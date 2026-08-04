<?php

namespace App\Services\Crawler;

use DOMDocument;
use DOMXPath;
use Throwable;

abstract class AbstractCrawlAdapter implements CrawlAdapter
{
    /**
     * Load an HTML document with DOMDocument, tolerating malformed markup.
     */
    protected function load(string $html): ?DOMDocument
    {
        if (trim($html) === '') {
            return null;
        }

        try {
            $dom = new DOMDocument;
            $previous = libxml_use_internal_errors(true);

            $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NOBLANKS);

            libxml_clear_errors();
            libxml_use_internal_errors($previous);

            return $dom;
        } catch (Throwable) {
            libxml_clear_errors();

            return null;
        }
    }

    /**
     * Extract only the text of a given DOMXPath query, trimmed and normalized.
     *
     * @return string[]
     */
    protected function nodeTexts(DOMXPath $xpath, string $query): array
    {
        $nodes = $xpath->query($query);

        if ($nodes === false) {
            return [];
        }

        $texts = [];

        foreach ($nodes as $node) {
            $text = trim(preg_replace('/\s+/u', ' ', $node->textContent ?? ''));

            if ($text !== '') {
                $texts[] = $text;
            }
        }

        return $texts;
    }
}
