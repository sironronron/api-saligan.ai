<?php

namespace App\Services\Export;

use App\Enums\MessageRole;
use App\Models\LegalCase;
use App\Models\Message;
use App\Models\Template;
use App\Services\Templates\DocxTemplateFiller;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpWord\Element\AbstractContainer;
use PhpOffice\PhpWord\Element\Image as ImageElement;
use PhpOffice\PhpWord\Element\Text as TextElement;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;
use ReflectionProperty;
use RuntimeException;

/**
 * Exports a drafted message through the original uploaded .docx template: the
 * template's placeholders are filled with the intake values the user submitted
 * for that draft, so the exported Word file keeps the template's logo,
 * formatting, and layout. PDFs are rendered server-side from the filled file;
 * because PhpWord cannot render headers, the header content (typically the
 * letterhead logo) is stamped at the top of the first page before rendering.
 */
class TemplateDocumentExportService
{
    /**
     * The widest the header logo may render in the generated PDF, in pixels.
     * The template's natural logo size is used when it is smaller.
     */
    private const MAX_LOGO_WIDTH_PX = 240;

    public function __construct(
        private readonly DocxTemplateFiller $filler,
    ) {
        //
    }

    /**
     * A copy of the template with the intake values for the given drafted
     * message filled in. The caller owns the returned temp file.
     */
    public function fillForMessage(Message $message, Template $template): string
    {
        $values = $this->intakeValuesFor($message, $template);

        return $this->filler->fill(Storage::path($template->original_path), $values);
    }

    /**
     * Convert a filled .docx into a PDF. The caller owns the returned temp file.
     */
    public function toPdf(string $filledDocxPath): string
    {
        Settings::setPdfRendererName('DomPDF');
        Settings::setPdfRendererPath((string) base_path('vendor/dompdf/dompdf'));

        $phpWord = IOFactory::load($filledDocxPath, 'Word2007');
        $this->stampHeaderOntoFirstPage($phpWord);

        $tempFile = tempnam(sys_get_temp_dir(), 'saligan_template_pdf_');
        IOFactory::createWriter($phpWord, 'PDF')->save($tempFile);

        return $tempFile;
    }

    /**
     * PhpWord drops headers when writing PDFs, so the header content is
     * re-added at the top of the first section's body. The most important
     * header content is the letterhead logo; header text is carried over too.
     */
    protected function stampHeaderOntoFirstPage(PhpWord $phpWord): void
    {
        $sections = $phpWord->getSections();

        if ($sections === []) {
            return;
        }

        $section = $sections[0];
        $prepend = [];

        foreach ($section->getHeaders() as $header) {
            foreach ($header->getElements() as $element) {
                if ($element instanceof TextRun) {
                    $prepend[] = $this->reconstructTextRun($element);
                } elseif ($element instanceof TextElement) {
                    $run = new TextRun;
                    $run->addText($element->getText());
                    $prepend[] = $run;
                }
            }
        }

        if ($prepend === []) {
            return;
        }

        // Sections expose no public way to insert elements at the front, so
        // the body's element list is prepended to directly. This only moves
        // reconstructed header runs into the body for rendering; the original
        // .docx file on disk is never modified.
        $property = new ReflectionProperty(AbstractContainer::class, 'elements');
        $elements = $property->getValue($section);
        $property->setValue($section, array_merge($prepend, $elements));
    }

    /**
     * A standalone copy of the header run with its image materialized into a
     * temp file (PhpWord images loaded from a .docx reference a zip:// stream
     * that outlives the archive handle, so the bytes are copied out first).
     */
    protected function reconstructTextRun(TextRun $original): TextRun
    {
        $run = new TextRun;

        if ($original->getStyle()?->getAlignment() !== null) {
            $run->getStyle()->setAlignment($original->getStyle()->getAlignment());
        }

        foreach ($original->getElements() as $inner) {
            if ($inner instanceof ImageElement) {
                $run->addImage($this->materializeImage($inner), $this->imageStyle($inner));
            } elseif ($inner instanceof TextElement) {
                $run->addText($inner->getText());
            }
        }

        return $run;
    }

    /**
     * The logo size to use when rendering the header image: the template's own
     * size when the reader carried it over, otherwise the natural pixel size
     * capped to a reasonable letterhead width.
     *
     * @return array<string, int>
     */
    protected function imageStyle(ImageElement $image): array
    {
        $width = $image->getStyle()->getWidth();
        $height = $image->getStyle()->getHeight();

        if (is_numeric($width) && is_numeric($height)) {
            return ['width' => (int) $width, 'height' => (int) $height];
        }

        [$naturalWidth, $naturalHeight] = $this->naturalSize((string) $image->getSource());

        if ($naturalWidth === 0 || $naturalHeight === 0) {
            return [];
        }

        $scale = min(1.0, self::MAX_LOGO_WIDTH_PX / $naturalWidth);

        return [
            'width' => (int) round($naturalWidth * $scale),
            'height' => (int) round($naturalHeight * $scale),
        ];
    }

    /**
     * Copy the header image's bytes into a temp file the PDF renderer can read.
     */
    protected function materializeImage(ImageElement $image): string
    {
        $raw = file_get_contents((string) $image->getSource());

        if ($raw === false) {
            throw new RuntimeException('Could not read a header image from the template.');
        }

        $extension = $image->getImageExtension() ?: 'png';
        $tempFile = tempnam(sys_get_temp_dir(), 'saligan_header_').'.'.$extension;

        if (file_put_contents($tempFile, $raw) === false) {
            @unlink($tempFile);
            throw new RuntimeException('Could not stage a header image for the PDF.');
        }

        return $tempFile;
    }

    /**
     * The natural pixel dimensions of the image referenced by the given
     * zip:// source.
     *
     * @return array{0: int, 1: int}
     */
    protected function naturalSize(string $source): array
    {
        $raw = file_get_contents($source);

        if ($raw === false) {
            return [0, 0];
        }

        $info = @getimagesizefromstring($raw);

        return $info === false ? [0, 0] : [(int) $info[0], (int) $info[1]];
    }

    /**
     * Extract values from the AI-drafted document in the message content.
     * Parses the drafted letter to find common fields like date, recipient,
     * subject, sender, etc. and maps them to template placeholder tokens.
     *
     * @return array<string, string>
     */
    protected function documentValues(Message $message): array
    {
        $content = $message->content;

        if (blank($content)) {
            return [];
        }

        $values = [];

        // Extract date from "DATE: ..." or "Date: ..." lines
        if (preg_match('/^(?:DATE|Date)\s*:\s*(.+)$/m', $content, $m)) {
            $date = trim($m[1]);
            $values['date'] = $date;
            $values['Date'] = $date;
            $values['DATE'] = $date;
            $values['[Date]'] = $date;
            $values['date_of_letter'] = $date;
            $values['Date of Letter'] = $date;
        }

        // Extract subject/Re line from "RE: ..." or "Re: ..." or "Subject: ..." lines
        if (preg_match('/^(?:RE|Re|Subject|SUBJECT)\s*:\s*(.+)$/m', $content, $m)) {
            $subject = trim($m[1]);
            $values['subject'] = $subject;
            $values['Subject'] = $subject;
            $values['SUBJECT'] = $subject;
            $values['re_line'] = $subject;
            $values['Re:'] = $subject;
            $values['Re'] = $subject;
            $values['[Subject of the Letter]'] = $subject;
            $values['[Subject of the Letter — e.g., Demand for Payment, Application for Certification]'] = $subject;
        }

        // Extract recipient from "Dear ..." salutation line
        if (preg_match('/^Dear\s+(.+?),?\s*$/m', $content, $m)) {
            $recipient = trim($m[1]);
            $values['recipient_name'] = $recipient;
            $values['Recipient Name'] = $recipient;
            $values['recipient'] = $recipient;
            $values['Recipient'] = $recipient;
            $values['[Recipient Full Name]'] = $recipient;
            $values['[Sir/Madam / Atty. Recipient Surname / Recipient Position]'] = $recipient;
        }

        // Extract recipient address block (lines after salutation, before body)
        if (preg_match('/^Dear\s+.+?\n((?:.+\n)*?)(?=\n|$)/m', $content, $m)) {
            $addrBlock = trim($m[1]);
            if (filled($addrBlock)) {
                $values['recipient_address'] = $addrBlock;
                $values['Recipient Address'] = $addrBlock;
                $values['[Recipient Complete Address]'] = $addrBlock;
            }
        }

        // Extract client name from "our client, [Name]" or "client, [Name]" patterns
        if (preg_match('/(?:our\s+)?client,\s+(?:Mr\.|Ms\.|Mrs\.|Dr\.|Atty\.)?\s*(.+?)(?:,\s*(?:of|residing|located|with))\b/i', $content, $m)) {
            $client = trim($m[1]);
            $values['client_name'] = $client;
            $values['Client Name'] = $client;
            $values['[Client Full Name]'] = $client;
            $values['client'] = $client;
            $values['Client'] = $client;
        }

        // Extract client address from "of [Address]" after client name
        if (preg_match('/(?:our\s+)?client,.+?(?:,\s*of|residing\s+at|located\s+at)\s+(.+?)(?:,\s*in\s+connection|,\s+regarding|\.)/i', $content, $m)) {
            $clientAddr = trim($m[1]);
            $values['client_address'] = $clientAddr;
            $values['Client Address'] = $clientAddr;
            $values['[Client Complete Address]'] = $clientAddr;
        }

        // Extract number of days from "within [Number] days" or "within thirty (30) days"
        if (preg_match('/within\s+(?:(\d+)\s*\((\d+)\)|(\w+))\s+days/i', $content, $m)) {
            $days = $m[2] ?? $m[1] ?? $m[3];
            $values['number_of_days'] = $days;
            $values['Number of Days'] = $days;
            $values['[Number]'] = $days;
            $values['days'] = $days;
        }

        // Extract deadline from "on or before [Date]" or "before [Date]"
        if (preg_match('/(?:on\s+or\s+)?before\s+(\w+\s+\d{1,2},?\s+\d{4})/i', $content, $m)) {
            $deadline = trim($m[1]);
            $values['deadline_date'] = $deadline;
            $values['Deadline Date'] = $deadline;
            $values['[Deadline Date]'] = $deadline;
            $values['deadline'] = $deadline;
        }

        // Extract sender/signatory from closing block ("Very truly yours,\n[Name]")
        if (preg_match('/(?:Very truly yours|Respectfully yours|Sincerely),?\s*\n\s*\n?(?:.*\n)*?(?=\n\n|\n\[)/m', $content, $m)) {
            $closing = trim($m[0]);
            // Get lines after the closing phrase
            $lines = explode("\n", $closing);
            $signatory = '';
            $foundClosing = false;
            foreach ($lines as $line) {
                $trimmed = trim($line);
                if (preg_match('/^(?:Very truly yours|Respectfully yours|Sincerely),?\s*$/i', $trimmed)) {
                    $foundClosing = true;

                    continue;
                }
                if ($foundClosing && filled($trimmed) && ! preg_match('/^\[/', $trimmed)) {
                    $signatory .= ($signatory !== '' ? "\n" : '').$trimmed;
                }
            }
            if (filled($signatory)) {
                $values['signatory'] = $signatory;
                $values['Signatory'] = $signatory;
                $values['sender_name'] = $signatory;
                $values['Sender Name'] = $signatory;
                $values['lawyer_name'] = $signatory;
                $values['Lawyer Name'] = $signatory;
                $values['attorney_name'] = $signatory;
                $values['Attorney Name'] = $signatory;
                $values['[Sender Full Name]'] = $signatory;
            }
        }

        // Extract position/title from "Counsel for [Client]" or "Position / Counsel for [Client]"
        if (preg_match('/(?:Counsel|Attorney|Lawyer)\s+for\s+(.+?)$/m', $content, $m)) {
            $position = 'Counsel for '.trim($m[1]);
            $values['position'] = $position;
            $values['Position'] = $position;
            $values['[Position / Counsel for Client]'] = $position;
        }

        // Extract any line in "LABEL: VALUE" format that looks like a letterhead field
        if (preg_match_all('/^([A-Z][A-Z\s]{2,30}?)\s*:\s*(.+)$/m', $content, $matches)) {
            foreach ($matches[1] as $i => $label) {
                $key = strtolower(trim(str_replace(' ', '_', $label)));
                $val = trim($matches[2][$i]);
                if (filled($val) && ! in_array($key, ['re', 'date', 'subject'], true)) {
                    $values[$key] = $val;
                    $values[ucwords(str_replace('_', ' ', $key))] = $val;
                }
            }
        }

        return $values;
    }

    /**
     * The intake values the user submitted for this draft, restricted to the
     * template's placeholders and keyed by their literal bracketed tokens so
     * the filler can replace them in place. Falls back to case context facts
     * and document-extracted values when no intake form submission exists.
     *
     * @return array<string, string>
     */
    protected function intakeValuesFor(Message $message, Template $template): array
    {
        // 0. The values the model supplied through fill_template_fields, keyed
        // by the literal template token. These are the only source that knows
        // what THIS template's placeholders mean, so they outrank every
        // heuristic below.
        $modelValues = $this->modelFieldValues($message);

        // 1. Try to get values from intake form submission first
        $intakeValues = $this->intakeFormValues($message);

        // 2. Get values from the drafted document content
        $documentValues = $this->documentValues($message);

        // 3. Get values from case context as fallback
        $caseValues = $this->caseContextValues($message->conversation->case);

        // Merge: model fill values > intake form > document extraction > case context
        $values = array_merge($caseValues, $documentValues, $intakeValues, $modelValues);

        $normalizedValues = [];

        foreach ($values as $key => $value) {
            $normalizedValues[$this->normalizeKey((string) $key)] = $value;
        }

        $fill = [];

        foreach ($template->placeholder_fields ?? [] as $field) {
            $token = is_string($field) ? $field : ($field['key'] ?? null);

            if (! is_string($token) || $token === '') {
                continue;
            }

            $value = $values[$token] ?? $normalizedValues[$this->normalizeKey($token)] ?? null;

            if ($value !== null && $value !== '') {
                // This path also renders through DomPDF, which has no glyph
                // for the peso sign, so a filled amount carrying "₱" would
                // reach the PDF as "?".
                $fill[$token] = DocumentExportService::normalizeCurrency((string) $value);
            }
        }

        return $fill;
    }

    /**
     * The placeholder values the model supplied through fill_template_fields,
     * stored on the message when the draft was produced. Keyed by the literal
     * token as it appears in the template, e.g. "[Client Full Name]".
     *
     * @return array<string, string>
     */
    protected function modelFieldValues(Message $message): array
    {
        $fields = $message->metadata['template_fields'] ?? null;

        if (! is_array($fields)) {
            return [];
        }

        $values = [];

        foreach ($fields as $key => $value) {
            if (is_string($key) && is_scalar($value) && (string) $value !== '') {
                $values[$key] = (string) $value;
            }
        }

        return $values;
    }

    /**
     * Extract values from the most recent intake form submission in the
     * conversation, before the given message.
     *
     * @return array<string, string>
     */
    protected function intakeFormValues(Message $message): array
    {
        $content = $message->conversation->messages()
            ->where('role', MessageRole::User)
            ->where('content', 'like', '[Intake Form Submission]%')
            ->where('created_at', '<=', $message->created_at)
            ->orderByDesc('created_at')
            ->value('content');

        if ($content === null) {
            return [];
        }

        $values = [];

        foreach (array_slice(explode("\n", $content), 1) as $line) {
            $parts = explode(': ', $line, 2);

            if (count($parts) === 2) {
                $values[trim($parts[0])] = trim($parts[1]);
            }
        }

        return $values;
    }

    /**
     * Extract values from the case context that can be mapped to common
     * template placeholders. This allows templates to be filled with case
     * facts even when the user did not go through the intake form.
     *
     * @return array<string, string>
     */
    protected function caseContextValues(?LegalCase $case): array
    {
        if ($case === null) {
            return [];
        }

        $values = [];

        // Map case fields to common placeholder tokens
        if (filled($case->title)) {
            $values['case_title'] = $case->title;
            $values['Case Title'] = $case->title;
            $values['subject'] = $case->title;
            $values['Subject'] = $case->title;
        }

        if (filled($case->reference)) {
            $values['case_reference'] = $case->reference;
            $values['Case Reference'] = $case->reference;
            $values['reference_number'] = $case->reference;
            $values['Reference Number'] = $case->reference;
            $values['Case No.'] = $case->reference;
        }

        if (filled($case->description)) {
            $values['case_description'] = $case->description;
            $values['Case Description'] = $case->description;
            $values['facts'] = $case->description;
            $values['Facts'] = $case->description;
            $values['Statement of Facts'] = $case->description;
        }

        if (filled($case->related_parties) && is_array($case->related_parties)) {
            $parties = implode(', ', $case->related_parties);
            $values['related_parties'] = $parties;
            $values['Related Parties'] = $parties;
            $values['parties'] = $parties;
            $values['Parties'] = $parties;
        }

        if ($case->due_date !== null) {
            $dateStr = $case->due_date->format('F j, Y');
            $values['due_date'] = $dateStr;
            $values['Due Date'] = $dateStr;
            $values['deadline'] = $dateStr;
            $values['Deadline'] = $dateStr;
        }

        if (filled($case->case_type)) {
            $values['case_type'] = $case->case_type;
            $values['Case Type'] = $case->case_type;
        }

        return $values;
    }

    /**
     * A comparable key for a placeholder token or intake key: lowercased with
     * all non-alphanumeric characters removed, so "[Client Name]" matches both
     * itself and "client_name".
     */
    protected function normalizeKey(string $key): string
    {
        return mb_strtolower((string) preg_replace('/[^a-z0-9]+/i', '', $key));
    }
}
