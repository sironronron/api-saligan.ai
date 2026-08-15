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
        Schema::table('organizations', function (Blueprint $table) {
            $table->text('description')->nullable()->after('name');
            $table->string('website')->nullable()->after('description');

            // The path on the private disk, not a URL: the file is served
            // through a signed route so it never needs a public bucket.
            $table->string('logo_path')->nullable()->after('website');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn(['description', 'website', 'logo_path']);
        });
    }
};
