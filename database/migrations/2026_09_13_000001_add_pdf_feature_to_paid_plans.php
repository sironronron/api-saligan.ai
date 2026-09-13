<?php

use App\Models\Plan;
use App\Support\PlanFeatures;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Ensure every paid tier carries the PDF document capability.
     *
     * `PlansSeeder` already writes the correct feature set, but existing
     * databases that were not re-seeded after the capability was introduced
     * otherwise leave paid subscribers looking like trial users.
     */
    public function up(): void
    {
        foreach ([Plan::SLUG_STANDARD, Plan::SLUG_PRO, Plan::SLUG_FIRM, Plan::SLUG_BUSINESS] as $slug) {
            $plan = Plan::query()->where('slug', $slug)->first();

            if ($plan === null) {
                continue;
            }

            $features = $plan->features ?? [];

            if (! in_array(PlanFeatures::PDF_DOCUMENTS, $features, true)) {
                $features[] = PlanFeatures::PDF_DOCUMENTS;

                // Keep the feature order stable for the pricing table.
                $plan->forceFill(['features' => array_values($features)])->save();
            }
        }
    }

    public function down(): void
    {
        foreach ([Plan::SLUG_STANDARD, Plan::SLUG_PRO, Plan::SLUG_FIRM, Plan::SLUG_BUSINESS] as $slug) {
            $plan = Plan::query()->where('slug', $slug)->first();

            if ($plan === null) {
                continue;
            }

            $features = array_values(array_filter(
                $plan->features ?? [],
                fn (string $feature): bool => $feature !== PlanFeatures::PDF_DOCUMENTS,
            ));

            $plan->forceFill(['features' => $features])->save();
        }
    }
};
