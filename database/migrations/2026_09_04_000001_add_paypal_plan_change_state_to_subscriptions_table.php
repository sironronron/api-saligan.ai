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
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->foreignUuid('pending_plan_id')->nullable()->constrained('plans')->nullOnDelete()->after('plan_id');
            $table->text('pending_plan_checkout_url')->nullable()->after('pending_plan_id');
            $table->timestamp('paypal_last_event_at')->nullable()->after('paypal_subscription_id');
            $table->string('paypal_last_event_id', 255)->nullable()->after('paypal_last_event_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropForeign(['pending_plan_id']);
            $table->dropColumn([
                'pending_plan_id',
                'pending_plan_checkout_url',
                'paypal_last_event_at',
                'paypal_last_event_id',
            ]);
        });
    }
};
