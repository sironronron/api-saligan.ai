<?php

namespace App\Services\Export;

use App\Support\DraftingIntent;
use Barryvdh\DomPDF\Facade\Pdf;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\SimpleType\NumberFormat;

class DocumentExportService
{
    /**
     * Generate a Word document from markdown content.
     */
    public function toWord(string $markdown, string $title): string
    {
        $markdown = $this->extractDocument($markdown);

        $phpWord = new PhpWord;

        $phpWord->addNumberingStyle('bulletedList', [
            'type' => 'hybridMultilevel',
            'levels' => [
                ['format' => NumberFormat::BULLET, 'text' => '•', 'alignment' => 'left', 'tabPos' => 720, 'left' => 720, 'hanging' => 360],
            ],
        ]);
        $phpWord->addNumberingStyle('numberedList', [
            'type' => 'multilevel',
            'levels' => [
                ['format' => NumberFormat::DECIMAL, 'text' => '%1.', 'alignment' => 'left', 'tabPos' => 720, 'left' => 720, 'hanging' => 360],
            ],
        ]);

        $section = $phpWord->addSection();
        $style = [
            'name' => 'Calibri',
            'size' => 11,
        ];

        $lines = preg_split('/\R/', $markdown);

        foreach ($lines as $line) {
            if (str_starts_with($line, '### ')) {
                $section->addTitle(substr($line, 4), 3);
            } elseif (str_starts_with($line, '## ')) {
                $section->addTitle(substr($line, 3), 2);
            } elseif (str_starts_with($line, '# ')) {
                $section->addTitle(substr($line, 2), 1);
            } elseif ($this->isBulletLine($line)) {
                $text = $this->parseInlineFormatting($this->bulletText($line));
                $section->addListItem($text, 0, $style, 'bulletedList');
            } elseif (preg_match('/^(\d+)[.)] (.+)$/', $line, $matches)) {
                $text = $this->parseInlineFormatting($matches[2]);
                $section->addListItem($text, 0, $style, 'numberedList');
            } elseif (trim($line) === '') {
                $section->addTextBreak();
            } else {
                $text = $this->parseInlineFormatting($line);
                $section->addText($text, $style);
            }
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'saligan_word_');
        $writer = IOFactory::createWriter($phpWord, 'Word2007');
        $writer->save($tempFile);

        return $tempFile;
    }

    /**
     * Generate a PDF from markdown content.
     */
    public function toPdf(string $markdown, string $title): string
    {
        $markdown = $this->extractDocument($markdown);

        $html = $this->markdownToHtml($markdown);

        $fullHtml = <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
    body { font-family: sans-serif; font-size: 11pt; line-height: 1.6; color: #1a1a1a; }
    h1 { font-size: 20pt; margin-top: 24pt; margin-bottom: 8pt; }
    h2 { font-size: 16pt; margin-top: 18pt; margin-bottom: 6pt; }
    h3 { font-size: 13pt; margin-top: 14pt; margin-bottom: 4pt; }
    p { margin-bottom: 8pt; }
    ul, ol { margin-left: 20pt; margin-bottom: 8pt; }
    li { margin-bottom: 4pt; }
    code { background: #f3f4f6; padding: 1px 4px; border-radius: 3px; font-size: 10pt; }
    strong { font-weight: bold; }
    em { font-style: italic; }
</style>
</head>
<body>
<h1>{$this->escapeHtml($title)}</h1>
{$html}
</body>
</html>
HTML;

        $pdf = Pdf::loadHTML($fullHtml);
        $pdf->setPaper('a4');

        $tempFile = tempnam(sys_get_temp_dir(), 'saligan_pdf_');
        $pdf->save($tempFile);

        return $tempFile;
    }

    /**
     * Extract only the marked letter from a drafted reply. The document is
     * bounded by its defined start and end markers; chat-only content before
     * [[DOCUMENT_START]] and after [[DOCUMENT_END]] is dropped, and the
     * chat-only "Next Steps" checklist ([[TODO_START]] … [[TODO_END]]) is
     * removed from the body so exported files contain only the letter itself.
     * When no markers are present, the full content is used so legacy
     * messages still export in full. Drafts missing the closing marker
     * (common: the model reliably opens with [[DOCUMENT_START]] but
     * occasionally omits the end marker) are exported from the opening marker
     * onward, cut off at the start of the todo checklist when one follows, so
     * no chat-only content leaks into the exported file.
     */
    public function extractDocument(string $content): string
    {
        if (preg_match('/\[\[DOCUMENT_START\]\]\s*(.*?)\s*\[\[DOCUMENT_END\]\]/s', $content, $matches) === 1) {
            return $this->cleanBody((string) $matches[1]);
        }

        if (str_contains($content, '[[DOCUMENT_START]]')) {
            $body = substr($content, strpos($content, '[[DOCUMENT_START]]') + strlen('[[DOCUMENT_START]]'));

            // The letter's end is the start of the todo checklist when one is
            // present: the checklist closes the draft and is chat-only, so it
            // (and everything after it) never belongs in the exported file.
            $todoStart = strpos($body, '[[TODO_START]]');

            if ($todoStart !== false) {
                $body = substr($body, 0, $todoStart);
            }

            return $this->cleanBody((string) $body);
        }

        return $this->cleanBody($content);
    }

    /**
     * Remove the hidden todo markers, the chat-only "Next Steps" checklist the
     * model wrote after the letter, and any meta commentary around it so
     * exported files only contain the letter itself. Also drops the leading
     * preamble the model sometimes opens the draft with, normalizes date
     * placeholders to today's date, and removes dangling bracketed
     * placeholders for facts the user never provided.
     */
    protected function cleanBody(string $body): string
    {
        // The next-steps checklist is chat-only and must never be exported,
        // whether it was wrapped in [[TODO_START]]/[[TODO_END]] (handled
        // below) or written bare after a "Next Steps" heading.
        $cleaned = $this->stripNextStepsSection($body);

        // Drop the marked checklist block along with any heading (e.g.
        // "Next Steps:") that introduces it.
        $cleaned = preg_replace(
            '/\s*(?:(?:#{1,6}\s+)?(?:\*\*)?(?:next steps?|steps? to take|recommended steps?|action items?|checklist|immediate steps?|next actions?|steps? to follow|what to do(?: next)?)\s*(?:\*\*)?\s*:?\s*\R+\s*)?\[\[TODO_START\]\].*?\[\[TODO_END\]\]/is',
            '',
            $cleaned,
        );

        // Remove any step-heading line left dangling by the truncation above
        // (e.g. a draft cut at [[TODO_START]] still carries its "Next Steps:"
        // heading).
        $cleaned = preg_replace(
            '/^\s*(?:#{1,6}\s+)?(?:\*\*)?(?:next steps?|steps? to take|recommended steps?|action items?|checklist|immediate steps?|next actions?|steps? to follow|what to do(?: next)?)\s*(?:\*\*)?\s*:?\s*$/mi',
            '',
            (string) $cleaned,
        );

        $cleaned = preg_replace('/^\s*\[\[(TODO|DOCUMENT)_(START|END)\]\]\s*$/m', '', (string) $cleaned);
        $cleaned = preg_replace('/^\s*Next Steps Checklist Created Below Using create_todo Tool:\s*$/im', '', (string) $cleaned);

        $cleaned = $this->stripPreamble((string) $cleaned);
        $cleaned = $this->normalizeDatePlaceholders((string) $cleaned);
        $cleaned = $this->stripBracketPlaceholderLines((string) $cleaned);
        $cleaned = preg_replace('/\n{3,}/', "\n\n", (string) $cleaned);

        return trim(DraftingIntent::stripExportLinks((string) $cleaned));
    }

    /**
     * Remove the chat-only "Next Steps" checklist section even when the model
     * wrote it without the hidden TODO markers: the heading and the checklist
     * items that follow it are dropped, so no checklist content leaks into the
     * exported file.
     */
    protected function stripNextStepsSection(string $body): string
    {
        $lines = preg_split('/\R/', $body) ?? [];

        $out = [];
        $inSection = false;

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if (self::isNextStepsHeading($trimmed)) {
                $inSection = true;

                continue;
            }

            if (! $inSection) {
                $out[] = $line;

                continue;
            }

            if ($trimmed === '') {
                continue;
            }

            // A line that is not a checklist item (e.g. a signature block or a
            // new section) closes the checklist section.
            if (! $this->looksLikeNextStepItem($trimmed)) {
                $inSection = false;
                $out[] = $line;

                continue;
            }
        }

        return implode("\n", $out);
    }

    /**
     * Whether the line reads like a next-steps / checklist heading, either on
     * its own line or as an inline label ("Next Steps: …").
     */
    protected static function isNextStepsHeading(string $line): bool
    {
        if (preg_match('/^(?:#{1,6}\s+)?\*{0,2}(?:next steps?|steps? to take|recommended steps?|action items?|checklist|immediate steps?|next actions?|steps? to follow|what to do(?: next)?)\*{0,2}\s*:?\s*$/i', $line) === 1) {
            return true;
        }

        return preg_match('/^(?:#{1,6}\s+)?\*{0,2}(?:next steps?|checklist|action items?|what to do(?: next)?)\s*:\s*.+/i', $line) === 1;
    }

    /**
     * Whether the line looks like a checklist item: bulleted, numbered,
     * bold-led, a "Label: detail" line, or a sentence-length action.
     */
    protected function looksLikeNextStepItem(string $line): bool
    {
        if (preg_match('/^\d+[.)]\s+(?!\*)(.+)$/', $line) === 1) {
            return true;
        }

        if (preg_match('/^[-*+]\s+(?!\*)(.+)$/', $line) === 1) {
            return true;
        }

        if (preg_match('/^\*\*(.+?)\*\*:?\s*(.*)$/', $line) === 1) {
            return true;
        }

        if (preg_match('/^[A-Z0-9][^\n]{0,90}?:.+/', $line) === 1 && ! str_ends_with($line, ':')) {
            return true;
        }

        return mb_strlen($line) > 12 && str_contains($line, ' ');
    }

    /**
     * Drop the meta-introduction paragraph the model sometimes writes before
     * the letter (e.g. "Based on the documents provided …, here is a formal
     * Demand Letter …"). Only the leading paragraph is considered, so letter
     * content is never touched.
     */
    protected function stripPreamble(string $body): string
    {
        $lines = preg_split('/\R/', $body) ?? [];
        $count = count($lines);
        $start = 0;

        while ($start < $count && trim($lines[$start]) === '') {
            $start++;
        }

        if ($start >= $count || ! $this->isPreambleStart(trim($lines[$start]))) {
            return $body;
        }

        $end = $start;

        while ($end < $count && trim($lines[$end]) !== '') {
            $end++;
        }

        return implode("\n", array_slice($lines, $end));
    }

    /**
     * Whether a line opens a meta-introduction to the letter rather than the
     * letter itself.
     */
    protected function isPreambleStart(string $line): bool
    {
        $needle = mb_strtolower($line);

        $phrases = [
            'based on the documents provided',
            'based on your specific requirements',
            'based on your request',
            'based on the provided',
            'based on the above',
            'based on the information you provided',
            'here is your',
            'here is the',
            'here is a',
            'here is an',
            'below is',
            'as requested',
            'per your request',
            'attached herewith',
            'please find',
            'the following is',
            'we have prepared',
            'i have prepared',
            'i have drafted',
            'we have drafted',
            'in response to your request',
            'this letter serves',
            'this document serves',
        ];

        foreach ($phrases as $phrase) {
            if (str_contains($needle, $phrase)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Replace date placeholders and example dates in the letter's date line
     * with today's date, so exported files always carry the actual current
     * date instead of a token like "[Date]" or "(or current date)".
     */
    protected function normalizeDatePlaceholders(string $body): string
    {
        $today = now()->format('F j, Y');

        $lines = preg_split('/\R/', $body) ?? [];
        $out = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if (preg_match('/^\[(?:date|today\'s date|current date)\]$/i', $trimmed) === 1) {
                $out[] = $today;

                continue;
            }

            if (preg_match('/^(date|dated)\s*:\s*(.+)$/i', $line, $matches) === 1
                && $this->isDatePlaceholder(trim($matches[2]))) {
                $out[] = $matches[1].': '.$today;

                continue;
            }

            $out[] = $line;
        }

        return implode("\n", $out);
    }

    /**
     * Whether a date value is a placeholder or an example to be replaced,
     * e.g. "[Date]", "[Current Date]", or "October 24, 2024 (or current
     * date)".
     */
    protected function isDatePlaceholder(string $value): bool
    {
        if (preg_match('/^\[[^\]]+\]$/', $value) === 1) {
            return true;
        }

        return preg_match('/\(?\s*(?:or\s+)?(?:today\'s|today|current|insert|enter|use|the)\s+date\s*\)?/i', $value) === 1;
    }

    /**
     * Drop lines that are only bracketed placeholders for facts the user never
     * provided (e.g. "[Contact Number]", "[Email Address]") and dangling
     * "Label: [Placeholder]" lines. Citation tags such as [Web 1], [SRC K3F9],
     * and [DOC X1Y2] are untouched because they carry a digit.
     */
    protected function stripBracketPlaceholderLines(string $body): string
    {
        $lines = preg_split('/\R/', $body) ?? [];
        $out = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if (preg_match('/^\[[A-Z][A-Za-z \']+\]\s*(?:\[\s*[A-Z][A-Za-z \']*\])*$/', $trimmed) === 1) {
                continue;
            }

            if (preg_match('/^[^:\n]{1,80}:\s*\[[A-Z][A-Za-z \']+\]\s*$/', $trimmed) === 1) {
                continue;
            }

            $out[] = $line;
        }

        return implode("\n", $out);
    }

    /**
     * Whether a line is a markdown bullet: "- item", "* item", "+ item", or an
     * asterisk attached to its text ("*(words)") when the line is not italic
     * emphasis or bold.
     */
    protected function isBulletLine(string $line): bool
    {
        if (preg_match('/^[-*+]\s/', $line) === 1) {
            return true;
        }

        return preg_match('/^\*(?!\*)/', $line) === 1 && substr_count($line, '*') === 1;
    }

    /**
     * Strip the bullet marker from a bulleted line, returning its text.
     */
    protected function bulletText(string $line): string
    {
        if (preg_match('/^[-*+]\s+(.*)$/', $line, $matches) === 1) {
            return $matches[1];
        }

        if (preg_match('/^\*(?!\*)(.*)$/', $line, $matches) === 1) {
            return $matches[1];
        }

        return $line;
    }

    /**
     * Convert markdown to basic HTML.
     */
    protected function markdownToHtml(string $markdown): string
    {
        $html = $this->escapeHtml($markdown);

        $html = preg_replace('/^### (.+)$/m', '<h3>$1</h3>', $html);
        $html = preg_replace('/^## (.+)$/m', '<h2>$1</h2>', $html);
        $html = preg_replace('/^# (.+)$/m', '<h1>$1</h1>', $html);

        // Convert list markers to <li> runs first, so leading "*" bullets are
        // never mistaken for italic emphasis by the inline pass below.
        $lines = preg_split('/\R/', $html) ?? [];
        $out = [];
        $list = null;

        foreach ($lines as $line) {
            $trimmed = rtrim($line);

            if ($this->isBulletLine($trimmed)) {
                if ($list !== 'ul') {
                    if ($list !== null) {
                        $out[] = "</{$list}>";
                    }
                    $out[] = '<ul>';
                    $list = 'ul';
                }

                $out[] = '<li>'.$this->bulletText($trimmed).'</li>';

                continue;
            }

            if (preg_match('/^(\d+)[.)]\s+(.*)$/', $trimmed, $matches)) {
                if ($list !== 'ol') {
                    if ($list !== null) {
                        $out[] = "</{$list}>";
                    }
                    $out[] = '<ol>';
                    $list = 'ol';
                }

                $out[] = '<li>'.$matches[2].'</li>';

                continue;
            }

            if ($list !== null) {
                $out[] = "</{$list}>";
                $list = null;
            }

            $out[] = $trimmed;
        }

        if ($list !== null) {
            $out[] = "</{$list}>";
        }

        $html = implode("\n", $out);

        $html = preg_replace('/(<(?:ul|ol|li)>)[\r\n]+/', '$1', $html);
        $html = preg_replace('/[\r\n]+(<\/(?:ul|ol|li)>)/', '$1', $html);

        $html = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $html);
        $html = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $html);
        $html = preg_replace('/`(.+?)`/', '<code>$1</code>', $html);

        $html = preg_replace('/\n{2,}/', '</p><p>', $html);
        $html = '<p>'.$html.'</p>';
        $html = str_replace("\n", '<br>', $html);

        return $html;
    }

    /**
     * Parse inline markdown formatting (bold, italic, code).
     */
    protected function parseInlineFormatting(string $text): string
    {
        $text = str_replace('**', '', $text);
        $text = str_replace('*', '', $text);
        $text = str_replace('`', '', $text);

        return $text;
    }

    /**
     * Escape HTML entities.
     */
    protected function escapeHtml(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
