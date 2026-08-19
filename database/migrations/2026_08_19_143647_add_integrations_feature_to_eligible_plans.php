<?php

use App\Models\Plan;
use App\Support\PlanFeatures;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Ensure Pro, Firm and Business plans carry the integrations capability.
     *
     * The feature was introduced after these rows were seeded. `PlansSeeder`
     * already writes the correct shape, but an existing database that never
     * re-runs the seeder would still carry the old feature set — so the
     * migration patches the rows in place and is idempotent.
     */
    public function up(): void
    {
        foreach ([Plan::SLUG_PRO, Plan::SLUG_FIRM, Plan::SLUG_BUSINESS] as $slug) {
            $plan = Plan::query()->where('slug', $slug)->first();

            if ($plan === null) {
                continue;
            }

            $features = $plan->features ?? [];

            if (! in_array(PlanFeatures::INTEGRATIONS, $features, true)) {
                $features[] = PlanFeatures::INTEGRATIONS;

                // Keep the feature order stable for the pricing table.
                $plan->forceFill(['features' => array_values($features)])->save();
            }
        }
    }

    public function down(): void
    {
        foreach ([Plan::SLUG_PRO, Plan::SLUG_FIRM, Plan::SLUG_BUSINESS] as $slug) {
            $plan = Plan::query()->where('slug', $slug)->first();

            if ($plan === null) {
                continue;
            }

            $features = array_values(array_filter(
                $plan->features ?? [],
                fn (string $feature): bool => $feature !== PlanFeatures::INTEGRATIONS,
            ));

            $plan->forceFill(['features' => $features])->save();
        }
    }
};
