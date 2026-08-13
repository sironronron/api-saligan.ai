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
            // The broad category of legal material a source collects — law,
            // jurisprudence, issuance, treaty — used to organize the
            // allowlist and label sources in the admin UI.
            $table->string('category', 30)->default('general');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('legal_sources', function (Blueprint $table) {
            $table->dropColumn('category');
        });
    }
};
