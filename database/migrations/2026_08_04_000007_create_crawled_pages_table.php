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
        Schema::create('crawled_pages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('legal_source_id');
            $table->foreign('legal_source_id')->references('id')->on('legal_sources')->cascadeOnDelete();
            $table->string('url', 2048);
            $table->string('content_hash', 64)->nullable();
            $table->string('raw_html_path')->nullable();
            $table->string('law_name')->nullable();
            $table->string('gr_number')->nullable();
            $table->date('promulgation_date')->nullable();
            $table->string('crawl_status', 20)->default('pending');
            $table->text('last_error')->nullable();
            $table->timestamp('last_crawled_at')->nullable();
            $table->timestamps();

            $table->unique(['legal_source_id', 'url']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('crawled_pages');
    }
};
