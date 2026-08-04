<?php

namespace App\Services\Crawler;

class ParsedPage
{
    /**
     * @param  string[]  $links  Same-domain links discovered on the page.
     */
    public function __construct(
        public readonly string $title,
        public readonly ?string $lawName,
        public readonly ?string $grNumber,
        public readonly ?string $promulgationDate,
        public readonly string $text,
        public readonly array $links = [],
    ) {}
}
