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
        Schema::table('legal_sources', function (Blueprint $table) {
            $table->string('knowledge_type', 20)->default('legal')->index();
        });

        Schema::table('crawled_pages', function (Blueprint $table) {
            $table->string('knowledge_type', 20)->default('legal')->index();
            $table->string('standard_code')->nullable();
            $table->string('standard_edition')->nullable();
            $table->string('standard_issuer')->nullable();
            $table->string('standard_status', 20)->nullable();
            $table->date('standard_publication_date')->nullable();
            $table->date('standard_review_date')->nullable();
            $table->string('rights_basis', 30)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('crawled_pages', function (Blueprint $table) {
            $table->dropIndex(['knowledge_type']);
            $table->dropColumn([
                'knowledge_type',
                'standard_code',
                'standard_edition',
                'standard_issuer',
                'standard_status',
                'standard_publication_date',
                'standard_review_date',
                'rights_basis',
            ]);
        });

        Schema::table('legal_sources', function (Blueprint $table) {
            $table->dropIndex(['knowledge_type']);
            $table->dropColumn('knowledge_type');
        });
    }
};
