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
            $table->string('gateway', 30)->default('paymongo')->index()->after('plan_id');
            $table->string('lemonsqueezy_subscription_id')->nullable()->index()->after('paymongo_customer_id');
            $table->string('lemonsqueezy_customer_id')->nullable()->index()->after('lemonsqueezy_subscription_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['gateway', 'lemonsqueezy_subscription_id', 'lemonsqueezy_customer_id']);
        });
    }
};
