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
        Schema::table('plans', function (Blueprint $table) {
            // Relative to Standard's monthly AI allowance. Null is reserved
            // for trial/custom tiers that do not belong to the paid ladder.
            $table->unsignedInteger('ai_usage_multiplier')->nullable()->after('ai_budget_usd_cents');
        });

        DB::table('plans')->where('slug', 'standard')->update([
            'ai_budget_usd_cents' => 515,
            'ai_usage_multiplier' => 1,
        ]);
        DB::table('plans')->where('slug', 'pro')->update([
            'ai_budget_usd_cents' => 2575,
            'ai_usage_multiplier' => 5,
        ]);
        DB::table('plans')->where('slug', 'firm')->update([
            'ai_budget_usd_cents' => 10300,
            'ai_usage_multiplier' => 20,
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('ai_usage_multiplier');
        });
    }
};
