<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('cases', function (Blueprint $table) {
            $table->timestamp('closed_at')->nullable()->after('status');
        });

        // Closed cases already in the system were closed at some unknown
        // moment; the last update is the closest record we have, so the
        // auto-archive countdown starts from there for pre-existing rows.
        DB::table('cases')
            ->where('status', 'closed')
            ->whereNull('closed_at')
            ->update(['closed_at' => DB::raw('updated_at')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cases', function (Blueprint $table) {
            $table->dropColumn('closed_at');
        });
    }
};
