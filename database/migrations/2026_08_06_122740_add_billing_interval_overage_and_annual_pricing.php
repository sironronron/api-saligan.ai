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
        Schema::table('plans', function (Blueprint $table) {
            $table->unsignedBigInteger('price_annual')->nullable()->after('price');
            $table->unsignedInteger('overage_price')->nullable()->after('price_annual');
            $table->string('paymongo_plan_id_annual')->nullable()->after('paymongo_plan_id')->index();
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('interval', 20)->default('monthly')->after('plan_id');
        });

        Schema::table('usage_counters', function (Blueprint $table) {
            $table->unsignedBigInteger('messages_overage')->default(0)->after('messages_used');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn(['price_annual', 'overage_price', 'paymongo_plan_id_annual']);
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('interval');
        });

        Schema::table('usage_counters', function (Blueprint $table) {
            $table->dropColumn('messages_overage');
        });
    }
};
