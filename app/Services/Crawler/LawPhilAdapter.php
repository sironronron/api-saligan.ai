<?php

namespace App\Services\Crawler;

use DOMXPath;

class LawPhilAdapter extends AbstractCrawlAdapter
{
    /**
     * Patterns identifying the kind of legal text a LawPhil page contains.
     */
    private const LAW_PATTERN = '/(R\.?\s*A\.?\s*No\.?\s*\d+|Republic Act No\.?\s*\d+|P\.?\s*D\.?\s*No\.?\s*\d+|E\.?\s*O\.?\s*No\.?\s*\d+|Batas Pambansa Blg\.?\s*\d+|B\.?\s*P\.?\s*Blg\.?\s*\d+|C\.?\s*A\.?\s*No\.?\s*\d+|Commonwealth Act No\.?\s*\d+)/i';

    private const GR_PATTERN = '/G\.?\s*R\.?\s*No\.?\s*\d+(?:\s*[–-]\s*\d+)?/i';

    private const DATE_PATTERN = '/\b(?:January|February|March|April|May|June|July|August|September|October|November|December)\s+\d{1,2},\s+\d{4}\b/';

    public function parse(string $html, string $url): ParsedPage
    {
        $dom = $this->load($html);
        $text = '';

        if ($dom !== null) {
            $xpath = new DOMXPath($dom);
            $text = implode("\n", $this->nodeTexts($xpath, '//p[normalize-space()]'));
            $links = $this->links($xpath);
        } else {
            $links = [];
        }

        $title = $this->title($html, $text);

        $lawName = $this->firstMatch(self::LAW_PATTERN, $text) ?? $this->firstMatch(self::LAW_PATTERN, $title);
        $grNumber = $this->firstMatch(self::GR_PATTERN, $title) ?? $this->firstMatch(self::GR_PATTERN, $text);
        $promulgationDate = $this->firstMatch(self::DATE_PATTERN, $title) ?? $this->firstMatch(self::DATE_PATTERN, $text);

        return new ParsedPage(
            title: $title,
            lawName: $lawName,
            grNumber: $grNumber,
            promulgationDate: $promulgationDate,
            text: $text,
            links: $links,
        );
    }

    /**
     * @return string[]
     */
    private function links(DOMXPath $xpath): array
    {
        $nodes = $xpath->query('//a[@href]');

        if ($nodes === false) {
            return [];
        }

        $links = [];

        foreach ($nodes as $node) {
            $href = $node->getAttribute('href');

            if ($href !== '') {
                $links[] = $href;
            }
        }

        return $links;
    }

    private function title(string $html, string $text): string
    {
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $matches)) {
            $title = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($matches[1]))));

            if ($title !== '') {
                return $title;
            }
        }

        return mb_substr($text, 0, 200);
    }

    private function firstMatch(string $pattern, string $subject): ?string
    {
        if (preg_match($pattern, $subject, $matches)) {
            return trim($matches[0]);
        }

        return null;
    }
}
