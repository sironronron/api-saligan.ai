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
            $table->timestamp('deadline_reminded_at')->nullable()->after('due_date');
            $table->date('deadline_reminded_due_date')->nullable()->after('deadline_reminded_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('todos', function (Blueprint $table) {
            $table->dropColumn(['deadline_reminded_at', 'deadline_reminded_due_date']);
        });
    }
};
