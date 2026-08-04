<?php

namespace App\Services\Crawler;

use Illuminate\Support\Str;

class CrawlerAdapterFactory
{
    public function resolve(string $baseDomain): CrawlAdapter
    {
        if (Str::contains($baseDomain, 'lawphil.net')) {
            return new LawPhilAdapter;
        }

        return new GenericAdapter;
    }
}
