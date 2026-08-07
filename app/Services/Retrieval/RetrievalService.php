<?php

namespace App\Services\Retrieval;

use App\Models\DocumentChunk;
use App\Models\LegalCase;
use App\Models\LegalChunk;
use App\Models\User;
use App\Services\Ai\EmbeddingService;

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
     * attached to that case.
     */
    public function retrieve(User $user, string $query, ?LegalCase $case = null): RetrievalResult
    {
        $embedding = $this->embeddings->embed($query);

        $legalChunks = LegalChunk::query()
            ->select(['id', 'crawled_page_id', 'chunk_index', 'content'])
            ->with(['crawledPage.legalSource:id,name,base_domain'])
            ->whereVectorSimilarTo(
                'embedding',
                $embedding,
                minSimilarity: config('saligan.retrieval.min_similarity'),
            )
            ->limit(config('saligan.retrieval.max_legal_chunks'))
            ->get();

        $documentChunks = DocumentChunk::query()
            ->select(['id', 'document_id', 'user_id', 'chunk_index', 'content'])
            ->where('user_id', $user->id)
            ->with('document:id,title,original_filename')
            ->when($case !== null, fn ($query) => $query->whereHas(
                'document',
                fn ($documentQuery) => $documentQuery->where('case_id', $case->id),
            ))
            ->whereVectorSimilarTo(
                'embedding',
                $embedding,
                minSimilarity: config('saligan.retrieval.min_similarity'),
            )
            ->limit(config('saligan.retrieval.max_document_chunks'))
            ->get();

        return new RetrievalResult($legalChunks, $documentChunks);
    }
}
