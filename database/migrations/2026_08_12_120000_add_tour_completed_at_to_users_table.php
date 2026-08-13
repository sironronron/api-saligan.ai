<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Records when the user finished (or skipped) the product tour.
     *
     * Kept on the account rather than in browser storage so the tour does not
     * reappear when the same person signs in on another device.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('tour_completed_at')->nullable()->after('kyc_completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('tour_completed_at');
        });
    }
};
