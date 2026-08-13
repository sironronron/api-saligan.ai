<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Records that the "your trial is nearly up" warning has gone out.
     *
     * One column rather than one per trigger: a trial ends on whichever of days
     * or messages runs out first, and both warnings would land within the same
     * few days. Sending one, whichever threshold is crossed first, is the
     * difference between a useful nudge and being the app that emails twice
     * about the same thing.
     */
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->timestamp('trial_warned_at')->nullable()->after('trial_code_id');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('trial_warned_at');
        });
    }
};
