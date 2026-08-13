<?php

namespace App\Services\Crawler;

use App\Services\Documents\TextExtractor;
use Throwable;

/**
 * Parses a downloaded PDF into the same ParsedPage shape as the HTML adapters.
 *
 * Legal authorities are routinely published as PDFs, and jurisprudence sites
 * (LawPhil, the Gazette) link to them heavily. Without this adapter a crawled
 * PDF would be fed to the HTML parser as binary, producing garbage text or a
 * failed crawl instead of indexable authority.
 *
 * Text is extracted with the shared document pipeline (the same parser used
 * for uploaded documents), then legal metadata — law name, G.R. number, and
 * promulgation date — is recovered with the same patterns the HTML adapters
 * use, so PDF pages read identically to crawled HTML pages downstream.
 */
class PdfAdapter implements CrawlAdapter
{
    private const LAW_PATTERN = '/(R\.?\s*A\.?\s*No\.?\s*\d+|Republic Act No\.?\s*\d+|P\.?\s*D\.?\s*No\.?\s*\d+|E\.?\s*O\.?\s*No\.?\s*\d+|Batas Pambansa Blg\.?\s*\d+|B\.?\s*P\.?\s*Blg\.?\s*\d+|C\.?\s*A\.?\s*No\.?\s*\d+|Commonwealth Act No\.?\s*\d+)/i';

    private const GR_PATTERN = '/G\.?\s*R\.?\s*No\.?\s*\d+(?:\s*[–-]\s*\d+)?/i';

    private const DATE_PATTERN = '/\b(?:January|February|March|April|May|June|July|August|September|October|November|December)\s+\d{1,2},\s+\d{4}\b/';

    public function parse(string $pdfBytes, string $url): ParsedPage
    {
        $text = $this->extractText($pdfBytes);

        $title = $this->title($text, $url);

        $lawName = $this->firstMatch(self::LAW_PATTERN, $text);
        $grNumber = $this->firstMatch(self::GR_PATTERN, $text);
        $promulgationDate = $this->firstMatch(self::DATE_PATTERN, $text);

        // A PDF has no hyperlinks to follow, so link discovery stops here.
        return new ParsedPage(
            title: $title,
            lawName: $lawName,
            grNumber: $grNumber,
            promulgationDate: $promulgationDate,
            text: $text,
            links: [],
        );
    }

    /**
     * Extract plain text from the PDF bytes. The parser reads from a file, so
     * the bytes are written to a temporary file first and removed again once
     * extraction completes. A scan with no text layer yields an empty string;
     * the caller records the page as fragmentary instead of failing.
     */
    protected function extractText(string $pdfBytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'saligan-pdf-');

        if ($path === false) {
            return '';
        }

        try {
            file_put_contents($path, $pdfBytes);

            return (new TextExtractor)->extract($path, 'application/pdf');
        } catch (Throwable) {
            return '';
        } finally {
            @unlink($path);
        }
    }

    /**
     * The document title: the first non-empty line of extracted text (legal
     * documents lead with their caption), falling back to the URL's file name.
     */
    protected function title(string $text, string $url): string
    {
        foreach (preg_split('/\r?\n/', trim($text)) ?: [] as $line) {
            $line = trim($line);

            if ($line !== '') {
                return mb_substr($line, 0, 200);
            }
        }

        $path = parse_url($url, PHP_URL_PATH) ?? '';
        $filename = trim(basename($path), './');

        return $filename !== '' && $filename !== 'pdf'
            ? mb_substr($filename, 0, 200)
            : '';
    }

    private function firstMatch(string $pattern, string $subject): ?string
    {
        if (preg_match($pattern, $subject, $matches)) {
            return trim($matches[0]);
        }

        return null;
    }
}
