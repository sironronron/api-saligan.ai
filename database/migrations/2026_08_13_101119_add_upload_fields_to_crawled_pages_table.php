<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('crawled_pages', function (Blueprint $table) {
            // Uploaded legal documents reuse the crawled_page pipeline:
            // they are indexed, digested, retrieved, and cited exactly like a
            // crawled authority, but carry no crawl source. `kind` keeps the
            // two origins distinguishable in the admin UI and queries.
            $table->string('kind', 20)->default('crawled');
            $table->string('category', 30)->nullable();
            $table->string('storage_path')->nullable();
            $table->string('original_filename')->nullable();
            $table->string('mime_type')->nullable();

            // The upload pipeline dispatches no crawl job, so no URL or source
            // is ever recorded for it. Relaxing these columns (and dropping the
            // composite uniqueness that assumed a URL) keeps uploaded pages
            // free of synthetic placeholders.
            $table->uuid('legal_source_id')->nullable()->change();
            $table->string('url', 2048)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('crawled_pages', function (Blueprint $table) {
            $table->dropColumn(['kind', 'category', 'storage_path', 'original_filename', 'mime_type']);

            $table->uuid('legal_source_id')->nullable(false)->change();
            $table->string('url', 2048)->nullable(false)->change();
        });
    }
};
