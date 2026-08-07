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
            $table->unsignedBigInteger('lemonsqueezy_variant_id')->nullable()->after('paymongo_plan_id_annual');
            $table->unsignedBigInteger('lemonsqueezy_variant_id_annual')->nullable()->after('lemonsqueezy_variant_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn(['lemonsqueezy_variant_id', 'lemonsqueezy_variant_id_annual']);
        });
    }
};
