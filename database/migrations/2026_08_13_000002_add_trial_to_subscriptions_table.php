<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reinstates trials, this time gated behind a redeemable code.
     *
     * `trial_ends_at` was dropped in 2026_08_09_000000 when the open trial was
     * removed. A trial is modelled as an ordinary subscription row with status
     * `trialing` rather than as a parallel concept, so plan limits, usage
     * counters, and every `$user->subscription->plan` lookup keep working
     * untouched — the only thing that changes is what counts as active.
     */
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->timestamp('trial_ends_at')->nullable()->after('current_period_end');

            // Kept on the subscription so a trial can always be traced to the
            // code that granted it, for attribution and for abuse review.
            $table->foreignId('trial_code_id')->nullable()->after('trial_ends_at')
                ->constrained('trial_codes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('trial_code_id');
            $table->dropColumn('trial_ends_at');
        });
    }
};
