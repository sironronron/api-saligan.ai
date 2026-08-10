<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Enable the pgvector extension for vector similarity search.
     */
    public function up(): void
    {
        Schema::ensureVectorExtensionExists();
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        DB::statement('DROP EXTENSION IF EXISTS vector');
    }
};
