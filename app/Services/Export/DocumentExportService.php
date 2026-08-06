<?php

namespace App\Services\Export;

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
            } elseif (str_starts_with($line, '- ')) {
                $text = $this->parseInlineFormatting(substr($line, 2));
                $section->addListItem($text, 0, $style, 'bulletedList');
            } elseif (preg_match('/^(\d+)\. (.+)$/', $line, $matches)) {
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
     * Extract only the marked document from a drafted reply. Chat-only content
     * before the opening marker and after the closing marker is dropped. When
     * no markers are present, the full content is used so legacy messages
     * still export in full.
     */
    public function extractDocument(string $content): string
    {
        if (preg_match('/\[\[DOCUMENT_START\]\]\s*(.*?)\s*\[\[DOCUMENT_END\]\]/s', $content, $matches) === 1) {
            return trim($matches[1]);
        }

        return $content;
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

        $html = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $html);
        $html = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $html);
        $html = preg_replace('/`(.+?)`/', '<code>$1</code>', $html);

        $html = preg_replace('/^- (.+)$/m', '<li>$1</li>', $html);
        $html = preg_replace('/(<li>.*<\/li>\n?)+/', '<ul>$0</ul>', $html);

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
