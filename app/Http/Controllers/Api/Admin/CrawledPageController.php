<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\CrawledPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CrawledPageController extends Controller
{
    /**
     * List crawled pages, optionally filtered by source, status, and kind.
     *
     * Uploaded legal documents share the crawled_pages table; `kind` keeps the
     * two origins separate in the admin UI (see the legal-documents tab).
     */
    public function index(Request $request): JsonResponse
    {
        $pages = CrawledPage::query()
            ->when($request->filled('legal_source_id'), fn ($query) => $query->where('legal_source_id', $request->string('legal_source_id')))
            ->when($request->filled('status'), fn ($query) => $query->where('crawl_status', $request->string('status')))
            ->when($request->filled('kind'), fn ($query) => $query->where('kind', $request->string('kind')))
            ->with('legalSource:id,name')
            ->latest('last_crawled_at')
            ->paginate($request->integer('per_page', 25));

        return response()->json($pages);
    }
}
