<?php

use Illuminate\Database\Migrations\Migration;
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
        Schema::connection()->getPdo()->exec('DROP EXTENSION IF EXISTS vector');
    }
};
