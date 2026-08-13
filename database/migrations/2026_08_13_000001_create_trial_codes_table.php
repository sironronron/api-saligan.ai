<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Codes that grant a free trial when redeemed.
     *
     * One table covers both shapes the product needs: an admin-issued invite
     * code (no owner, often multi-use, for a closed beta or a campaign) and a
     * personal referral code (owned by a user, so the referrer can be credited).
     * They differ only in whether `owner_user_id` is set.
     */
    public function up(): void
    {
        Schema::create('trial_codes', function (Blueprint $table) {
            $table->id();

            // Stored uppercase and compared uppercase, so redemption is not
            // case-sensitive for someone typing a code off a screenshot.
            $table->string('code')->unique();

            $table->foreignUuid('plan_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('trial_days');

            // Null means unlimited redemptions; the counter is still kept so
            // campaign performance is visible without scanning redemptions.
            $table->unsignedInteger('max_redemptions')->nullable();
            $table->unsignedInteger('redeemed_count')->default(0);

            $table->timestamp('expires_at')->nullable();

            // Set for a personal referral code, null for an admin-issued one.
            // `users` is bigint-keyed while `plans` is uuid — the column types
            // here follow each referenced table rather than one house rule.
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->boolean('is_active')->default(true);
            $table->string('note')->nullable();

            $table->timestamps();

            $table->index(['is_active', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trial_codes');
    }
};
