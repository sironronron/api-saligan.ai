<?php

namespace App\Services\Export;

use App\Enums\MessageRole;
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
     * The intake values the user submitted for this draft, restricted to the
     * template's placeholders and keyed by their literal bracketed tokens so
     * the filler can replace them in place.
     *
     * @return array<string, string>
     */
    protected function intakeValuesFor(Message $message, Template $template): array
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
                $fill[$token] = $value;
            }
        }

        return $fill;
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
