<?php

use App\Models\Plan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Rename Starter to Standard, and clear the gateway plan ids of every
     * repriced plan so the seeder provisions new ones.
     *
     * The rename itself is cosmetic and safe: subscriptions reference a plan
     * by `plan_id` (a UUID), never by slug, so no subscriber moves.
     *
     * Clearing the gateway ids is the part that matters. PayMongo plans and
     * LemonSqueezy variants are immutable price objects — a price cannot be
     * edited on one, only replaced. PlansSeeder::provisionPlan() early-returns
     * whenever an id is already set, so re-seeding at a new price against the
     * old ids would leave every new checkout quietly charging the OLD amount.
     * Nulling them forces fresh objects at the new prices.
     *
     * Existing subscriptions are grandfathered by this rather than broken by
     * it: each one holds its own gateway subscription id and keeps renewing on
     * the plan it was sold, at the price it was sold at, whatever the plan row
     * now points to.
     */
    public function up(): void
    {
        DB::table('plans')->where('slug', 'starter')->update([
            'slug' => Plan::SLUG_STANDARD,
            'name' => 'Standard',
        ]);

        DB::table('plans')
            ->whereIn('slug', [Plan::SLUG_STANDARD, Plan::SLUG_PRO, Plan::SLUG_FIRM])
            ->update([
                'paymongo_plan_id' => null,
                'paymongo_plan_id_annual' => null,
                'lemonsqueezy_variant_id' => null,
                'lemonsqueezy_variant_id_annual' => null,
            ]);
    }

    /**
     * The gateway ids are not restored: they were cleared precisely because
     * they pointed at objects priced for the old ladder, and re-seeding on the
     * way down provisions whatever the seeder then describes.
     */
    public function down(): void
    {
        DB::table('plans')->where('slug', Plan::SLUG_STANDARD)->update([
            'slug' => 'starter',
            'name' => 'Starter',
        ]);
    }
};
