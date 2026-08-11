<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Both `cases.organization_id` and `users.organization_id` are nullable —
     * a solo user has no firm — but `matter_memory.organization_id` was
     * created NOT NULL. Any memory written against a case with no
     * organization therefore failed on insert. Align the column with the
     * tables it is scoped by.
     */
    public function up(): void
    {
        Schema::table('matter_memory', function (Blueprint $table) {
            $table->foreignId('organization_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('matter_memory', function (Blueprint $table) {
            $table->foreignId('organization_id')->nullable(false)->change();
        });
    }
};
