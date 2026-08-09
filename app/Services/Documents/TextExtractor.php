<?php

namespace App\Services\Documents;

use PhpOffice\PhpWord\Element\AbstractContainer;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\IOFactory;
use Smalot\PdfParser\Parser as PdfParser;

class TextExtractor
{
    /**
     * Extract plain text from a local file based on its MIME type.
     */
    public function extract(string $fullPath, string $mimeType): string
    {
        return match (true) {
            $mimeType === 'application/pdf' => $this->extractPdf($fullPath),
            $mimeType === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => $this->extractDocx($fullPath),
            default => $this->extractPlainText($fullPath),
        };
    }

    /**
     * Extract text from a PDF using the local parser.
     */
    protected function extractPdf(string $path): string
    {
        $parser = new PdfParser;

        return $parser->parseFile($path)->getText();
    }

    /**
     * Extract text from a DOCX using PhpWord.
     */
    protected function extractDocx(string $path): string
    {
        $document = IOFactory::load($path, 'Word2007');

        $parts = [];

        foreach ($document->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                $this->collectElementText($element, $parts);
            }
        }

        return implode("\n", $parts);
    }

    /**
     * Recursively collect text from a PhpWord element tree.
     *
     * @param  array<int, string>  $parts
     */
    protected function collectElementText(object $element, array &$parts): void
    {
        if ($element instanceof TextRun) {
            $text = trim($element->getText());

            if ($text !== '') {
                $parts[] = $text;
            }

            return;
        }

        if ($element instanceof AbstractContainer) {
            foreach ($element->getElements() as $child) {
                $this->collectElementText($child, $parts);
            }

            return;
        }

        if (method_exists($element, 'getText')) {
            $text = trim((string) $element->getText());

            if ($text !== '') {
                $parts[] = $text;
            }
        }
    }

    /**
     * Read a plain-text file (TXT / MD / etc.).
     */
    protected function extractPlainText(string $path): string
    {
        $contents = file_get_contents($path);

        return $contents === false ? '' : $contents;
    }
}
