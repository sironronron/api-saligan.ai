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
        Schema::create('usage_counters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('period_key', 7); // Y-m
            $table->unsignedBigInteger('messages_used')->default(0);
            $table->unsignedBigInteger('documents_uploaded')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'period_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('usage_counters');
    }
};
