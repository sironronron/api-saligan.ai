<?php

namespace App\Services\Crawler;

interface CrawlAdapter
{
    /**
     * Parse a crawled page's HTML into extractable legal metadata and text.
     */
    public function parse(string $html, string $url): ParsedPage;
}
