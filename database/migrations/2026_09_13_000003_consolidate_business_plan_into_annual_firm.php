<?php

use App\Models\Plan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Make Firm the only team tier and retain existing Business subscribers.
     *
     * The old Business row is kept inactive as a historical record. Existing
     * subscriptions move to Firm so the public API and entitlement checks no
     * longer expose a tier that the product no longer sells.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('plans', 'annual_only')) {
            Schema::table('plans', function (Blueprint $table): void {
                $table->boolean('annual_only')->default(false)->after('contact_sales');
            });
        }

        $firm = DB::table('plans')->where('slug', Plan::SLUG_FIRM)->first();
        $business = DB::table('plans')->where('slug', Plan::SLUG_BUSINESS)->first();

        if ($firm === null && $business !== null) {
            DB::table('plans')->where('id', $business->id)->update([
                'slug' => Plan::SLUG_FIRM,
                'name' => 'Firm',
                'annual_only' => true,
                'contact_sales' => false,
            ]);

            return;
        }

        if ($firm === null) {
            return;
        }

        DB::table('plans')->where('id', $firm->id)->update([
            'annual_only' => true,
            'contact_sales' => false,
        ]);

        if ($business === null) {
            return;
        }

        $firmFeatures = json_decode((string) $firm->features, true) ?: [];
        $businessFeatures = json_decode((string) $business->features, true) ?: [];

        DB::table('plans')->where('id', $firm->id)->update([
            'features' => json_encode(array_values(array_unique([
                ...$firmFeatures,
                ...$businessFeatures,
            ])), JSON_THROW_ON_ERROR),
        ]);

        DB::table('subscriptions')->where('plan_id', $business->id)->update([
            'plan_id' => $firm->id,
        ]);

        if (Schema::hasColumn('subscriptions', 'pending_plan_id')) {
            DB::table('subscriptions')
                ->where('pending_plan_id', $business->id)
                ->update([
                    'pending_plan_id' => null,
                    'pending_plan_interval' => null,
                    'pending_plan_checkout_url' => null,
                ]);
        }

        DB::table('plans')->where('id', $business->id)->update([
            'is_active' => false,
            'sort_order' => 999,
        ]);
    }

    /**
     * The archived Business row is intentionally not restored. Removing the
     * flag is the safe reversible part; subscriber plan moves are not undone.
     */
    public function down(): void
    {
        if (Schema::hasColumn('plans', 'annual_only')) {
            Schema::table('plans', function (Blueprint $table): void {
                $table->dropColumn('annual_only');
            });
        }
    }
};
