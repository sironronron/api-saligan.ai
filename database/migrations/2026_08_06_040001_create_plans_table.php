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
        Schema::create('plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('slug', 40)->unique();
            $table->string('name');
            $table->unsignedBigInteger('price'); // PHP amount in centavos
            $table->string('currency', 3)->default('PHP');
            $table->string('interval', 20)->default('monthly');
            $table->json('limits')->nullable();
            $table->json('features')->nullable();
            $table->string('paymongo_plan_id')->nullable()->index();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
