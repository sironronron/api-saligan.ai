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
        Schema::create('document_chunks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('document_id');
            $table->foreign('document_id')->references('id')->on('documents')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('chunk_index');
            $table->text('content');
            $table->timestamps();

            $table->index('document_id');
        });

        // Embeddings are stored as halfvec: pgvector's HNSW index caps the
        // `vector` type at 2,000 dimensions and `halfvec` at 4,000. The
        // qwen3-embedding model emits 4,096 dimensions with Matryoshka
        // (MRL) support, so a 4,000-dimension prefix is near-lossless.
        DB::statement('ALTER TABLE document_chunks ADD COLUMN embedding halfvec(4000)');
        DB::statement('CREATE INDEX document_chunks_embedding_hnsw_index ON document_chunks USING hnsw (embedding halfvec_cosine_ops)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_chunks');
    }
};
