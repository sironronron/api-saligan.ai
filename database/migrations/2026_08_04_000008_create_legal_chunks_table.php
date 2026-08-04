<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('legal_chunks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('crawled_page_id');
            $table->foreign('crawled_page_id')->references('id')->on('crawled_pages')->cascadeOnDelete();
            $table->unsignedInteger('chunk_index');
            $table->text('content');
            $table->timestamps();

            $table->index('crawled_page_id');
        });

        // See document_chunks migration for why embeddings use halfvec(4000).
        DB::statement('ALTER TABLE legal_chunks ADD COLUMN embedding halfvec(4000)');
        DB::statement('CREATE INDEX legal_chunks_embedding_hnsw_index ON legal_chunks USING hnsw (embedding halfvec_cosine_ops)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('legal_chunks');
    }
};
