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
        Schema::create('templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('category', 20)->default('formal');
            $table->string('jurisdiction', 10)->default('PH');
            $table->string('legal_subtype', 60)->nullable();
            $table->json('structure')->nullable();
            $table->json('placeholder_fields')->nullable();
            $table->json('default_for_case_types')->nullable();
            $table->text('content')->nullable();
            $table->timestamps();

            $table->index(['category', 'jurisdiction']);
            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('templates');
    }
};
