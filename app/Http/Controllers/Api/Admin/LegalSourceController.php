<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\LegalSourceCategory;
use App\Http\Controllers\Controller;
use App\Jobs\CrawlLegalSourcePage;
use App\Models\LegalSource;
use App\Support\OutboundUrl;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
            'seed_urls.*' => [
                'required',
                'url:http,https',
                'max:2048',
                // `url` alone accepts http://127.0.0.1:6379 and
                // http://169.254.169.254 quite happily. The crawl job refuses
                // these too, but rejecting them here tells the admin why
                // instead of leaving a source that silently never crawls.
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! OutboundUrl::isFetchable((string) $value)) {
                        $fail('The :attribute must resolve to a public address.');
                    }
                },
            ],
            'is_active' => ['sometimes', 'boolean'],
            'category' => ['sometimes', Rule::enum(LegalSourceCategory::class)],
        ]);

        $source = LegalSource::create([
            'name' => $validated['name'],
            'base_domain' => $validated['base_domain'],
            'seed_urls' => array_values($validated['seed_urls']),
            'is_active' => $validated['is_active'] ?? true,
            'category' => $validated['category'] ?? LegalSourceCategory::General->value,
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
