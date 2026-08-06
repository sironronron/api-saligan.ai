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
        Schema::table('todos', function (Blueprint $table) {
            $table->text('description')->nullable()->after('title');
            $table->string('assignee')->nullable()->after('description');
            $table->unsignedInteger('order')->default(0)->after('assignee');
            $table->date('due_date')->nullable()->after('due_hint');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('todos', function (Blueprint $table) {
            $table->dropColumn(['description', 'assignee', 'order', 'due_date']);
        });
    }
};
