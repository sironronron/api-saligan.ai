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
        Schema::create('legal_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type', 32)->default('terms_privacy');
            $table->string('version', 20);
            $table->string('title');
            $table->longText('content');
            $table->string('hash', 64);
            $table->timestamp('effective_at');
            $table->timestamps();

            // One row per published version, so re-seeding cannot duplicate a version.
            $table->unique(['type', 'version']);

            // Supports the "latest effective document of this type" lookup.
            $table->index(['type', 'effective_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('legal_documents');
    }
};
