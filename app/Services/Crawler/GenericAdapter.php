<?php

namespace App\Services\Crawler;

use DOMXPath;

class GenericAdapter extends AbstractCrawlAdapter
{
    private const LAW_PATTERN = '/(R\.?\s*A\.?\s*No\.?\s*\d+|Republic Act No\.?\s*\d+|P\.?\s*D\.?\s*No\.?\s*\d+|E\.?\s*O\.?\s*No\.?\s*\d+|Batas Pambansa Blg\.?\s*\d+|B\.?\s*P\.?\s*Blg\.?\s*\d+|C\.?\s*A\.?\s*No\.?\s*\d+|Commonwealth Act No\.?\s*\d+)/i';

    private const GR_PATTERN = '/G\.?\s*R\.?\s*No\.?\s*\d+\s*[–-]?\s*\d*|G\.?\s*R\.?\s*No\.?\s*\d+/i';

    private const DATE_PATTERN = '/\b(?:January|February|March|April|May|June|July|August|September|October|November|December)\s+\d{1,2},\s+\d{4}\b/';

    private const NOISE_SELECTORS = [
        '//nav', '//header', '//footer', '//script', '//style', '//noscript',
        '//form', '//iframe', '//aside', '//*[@role="navigation"]',
        '//*[@class[contains(., "menu")]]', '//*[@id[contains(., "nav")]]',
        '//*[@class[contains(., "footer")]]', '//*[@class[contains(., "header")]]',
        '//*[@class[contains(., "breadcrumb")]]', '//*[@class[contains(., "share")]]',
    ];

    public function parse(string $html, string $url): ParsedPage
    {
        $dom = $this->load($html);

        if ($dom === null) {
            return new ParsedPage(title: '', lawName: null, grNumber: null, promulgationDate: null, text: '', links: []);
        }

        $xpath = new DOMXPath($dom);

        foreach (self::NOISE_SELECTORS as $selector) {
            $nodes = $xpath->query($selector);

            if ($nodes !== false) {
                foreach ($nodes as $node) {
                    $node->parentNode?->removeChild($node);
                }
            }
        }

        $title = $this->title($html);
        $text = trim(implode("\n", $this->nodeTexts($xpath, '//p[normalize-space()]')));

        if ($text === '') {
            $text = trim(preg_replace('/\s+/u', ' ', $dom->documentElement?->textContent ?? ''));
        }

        $lawName = $this->firstMatch(self::LAW_PATTERN, $text) ?? $this->firstMatch(self::LAW_PATTERN, $title);
        $grNumber = $this->firstMatch(self::GR_PATTERN, $text) ?? $this->firstMatch(self::GR_PATTERN, $title);
        $promulgationDate = $this->firstMatch(self::DATE_PATTERN, $text) ?? $this->firstMatch(self::DATE_PATTERN, $title);

        $links = [];
        $nodes = $xpath->query('//a[@href]');

        if ($nodes !== false) {
            foreach ($nodes as $node) {
                $href = $node->getAttribute('href');

                if ($href !== '') {
                    $links[] = $href;
                }
            }
        }

        return new ParsedPage(
            title: $title,
            lawName: $lawName,
            grNumber: $grNumber,
            promulgationDate: $promulgationDate,
            text: $text,
            links: $links,
        );
    }

    private function title(string $html): string
    {
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $matches)) {
            return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($matches[1]))));
        }

        return '';
    }

    private function firstMatch(string $pattern, string $subject): ?string
    {
        if (preg_match($pattern, $subject, $matches)) {
            return trim($matches[0]);
        }

        return null;
    }
}
