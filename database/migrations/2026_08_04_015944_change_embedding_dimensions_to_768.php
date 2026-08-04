<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (['document_chunks', 'legal_chunks'] as $table) {
            $this->changeDimensions($table, 768);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['document_chunks', 'legal_chunks'] as $table) {
            $this->changeDimensions($table, 4000);
        }
    }

    /**
     * Rebuild the embedding column and its HNSW index at the given dimension.
     */
    protected function changeDimensions(string $table, int $dimensions): void
    {
        $index = "{$table}_embedding_hnsw_index";

        DB::statement("DROP INDEX IF EXISTS {$index}");
        DB::statement("ALTER TABLE {$table} ALTER COLUMN embedding TYPE halfvec({$dimensions})");
        DB::statement("CREATE INDEX {$index} ON {$table} USING hnsw (embedding halfvec_cosine_ops)");
    }
};
