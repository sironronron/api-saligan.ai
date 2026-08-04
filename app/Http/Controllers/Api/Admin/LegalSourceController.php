<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\CrawlLegalSourcePage;
use App\Models\LegalSource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LegalSourceController extends Controller
{
    /**
     * List legal sources with their crawl stats.
     */
    public function index(): JsonResponse
    {
        $sources = LegalSource::query()
            ->withCount('crawledPages')
            ->withCount('legalChunks')
            ->latest()
            ->get();

        return response()->json($sources);
    }

    /**
     * Create a new legal source on the crawl allowlist.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'base_domain' => ['required', 'string', 'max:255', 'unique:legal_sources,base_domain'],
            'seed_urls' => ['required', 'array', 'min:1'],
            'seed_urls.*' => ['required', 'url', 'max:2048'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $source = LegalSource::create([
            'name' => $validated['name'],
            'base_domain' => $validated['base_domain'],
            'seed_urls' => array_values($validated['seed_urls']),
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return response()->json($source, 201);
    }

    /**
     * Dispatch crawl jobs for the source's seed URLs.
     */
    public function crawlNow(Request $request, LegalSource $legalSource): JsonResponse
    {
        if (! config('saligan.crawler.enabled')) {
            return response()->json(['message' => 'Crawler is disabled.'], 422);
        }

        foreach ($legalSource->seed_urls as $url) {
            CrawlLegalSourcePage::dispatch($legalSource, $url);
        }

        return response()->json(['message' => 'Crawl jobs dispatched.']);
    }

    /**
     * Delete a legal source and its crawled data.
     */
    public function destroy(Request $request, LegalSource $legalSource): JsonResponse
    {
        $legalSource->delete();

        return response()->json(null, 204);
    }
}
