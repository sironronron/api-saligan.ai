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
        Schema::create('messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('conversation_id');
            $table->foreign('conversation_id')->references('id')->on('conversations')->cascadeOnDelete();
            $table->string('role', 20);
            $table->text('content');
            $table->string('provider', 20)->nullable();
            $table->jsonb('cited_chunk_ids')->nullable();
            $table->jsonb('cited_legal_chunk_ids')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index('conversation_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
