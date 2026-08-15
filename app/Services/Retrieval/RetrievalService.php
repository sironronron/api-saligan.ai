<?php

namespace App\Services\Retrieval;

use App\Models\DocumentChunk;
use App\Models\LegalCase;
use App\Models\LegalChunk;
use App\Models\User;
use App\Services\Ai\EmbeddingService;
use App\Support\PlanFeatures;

class RetrievalService
{
    public function __construct(
        private readonly EmbeddingService $embeddings,
    ) {
        //
    }

    /**
     * Embed the query and retrieve context from both the shared legal
     * knowledge base (priority 1) and the user's own documents (priority 2).
     * When a case is given, document retrieval is scoped to the documents
     * attached to that case. When no case is given (general chat), only
     * general documents (not attached to any case) are retrieved —
     * case-specific documents stay scoped to their case.
     */
    public function retrieve(User $user, string $query, ?LegalCase $case = null): RetrievalResult
    {
        $embedding = $this->embeddings->embed($query);

        // How wide to cast the net is a plan feature: see the note in
        // config/saligan.php on why this is the lever worth selling.
        $deep = PlanFeatures::has($user, PlanFeatures::DEEP_RESEARCH);

        $legalLimit = config($deep ? 'saligan.retrieval.max_legal_chunks' : 'saligan.retrieval.base_max_legal_chunks');
        $documentLimit = config($deep ? 'saligan.retrieval.max_document_chunks' : 'saligan.retrieval.base_max_document_chunks');

        $legalChunks = LegalChunk::query()
            ->select(['id', 'crawled_page_id', 'chunk_index', 'content'])
            ->with(['crawledPage.legalSource:id,name,base_domain'])
            ->whereVectorSimilarTo(
                'embedding',
                $embedding,
                minSimilarity: config('saligan.retrieval.min_similarity'),
            )
            ->limit($legalLimit)
            ->get();

        $documentChunks = DocumentChunk::query()
            ->select(['id', 'document_id', 'user_id', 'chunk_index', 'content'])
            ->where('user_id', $user->id)
            ->with('document:id,title,original_filename,case_id')
            ->when(
                $case !== null,
                fn ($query) => $query->whereHas(
                    'document',
                    fn ($documentQuery) => $documentQuery->where('case_id', $case->id),
                ),
                fn ($query) => $query->whereHas(
                    'document',
                    fn ($documentQuery) => $documentQuery->whereNull('case_id'),
                ),
            )
            ->whereVectorSimilarTo(
                'embedding',
                $embedding,
                minSimilarity: config('saligan.retrieval.min_similarity'),
            )
            ->limit($documentLimit)
            ->get();

        return new RetrievalResult($legalChunks, $documentChunks);
    }
}
