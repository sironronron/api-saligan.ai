<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('letter_comments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('message_id')->index();
            $table->string('block_id')->index();
            $table->uuid('parent_id')->nullable();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();

            $table->foreign('message_id')->references('id')->on('messages')->cascadeOnDelete();
        });

        // Add the self-reference in a second step so Postgres sees the primary
        // key on `id` before the foreign key that points back at it.
        Schema::table('letter_comments', function (Blueprint $table) {
            $table->foreign('parent_id')->references('id')->on('letter_comments')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('letter_comments');
    }
};
