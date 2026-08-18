<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_activities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('todo_id')->index();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 50); // created, status_changed, priority_changed, subtask_added, etc.
            $table->text('description');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('todo_id')->references('id')->on('todos')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_activities');
    }
};
