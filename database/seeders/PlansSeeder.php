<?php

namespace Database\Seeders;

use App\Models\Plan;
use App\Services\Billing\PaymongoClient;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

class PlansSeeder extends Seeder
{
    /**
     * Seed the billing plans used by the PayMongo integration.
     */
    public function run(): void
    {
        $plans = [
            [
                'slug' => Plan::SLUG_STARTER,
                'name' => 'Starter',
                'price' => 150000,
                'price_annual' => 1494000,
                'overage_price' => null,
                'sort_order' => 1,
                'limits' => [
                    'active_cases' => 10,
                    'documents_uploaded' => 10,
                    'messages_used' => 200,
                ],
                'features' => ['templates', 'exports', 'web_search'],
            ],
            [
                'slug' => Plan::SLUG_PRO,
                'name' => 'Pro',
                'price' => 200000,
                'price_annual' => 1990000,
                'overage_price' => 350,
                'sort_order' => 2,
                'limits' => [
                    'active_cases' => null,
                    'documents_uploaded' => 100,
                    'messages_used' => 500,
                ],
                'features' => ['templates', 'exports', 'web_search', 'unlimited_cases'],
            ],
            [
                'slug' => Plan::SLUG_FIRM,
                'name' => 'Firm',
                'price' => 890000,
                'price_annual' => 8860000,
                'overage_price' => 300,
                'sort_order' => 3,
                'limits' => [
                    'active_cases' => null,
                    'documents_uploaded' => null,
                    'messages_used' => 3000,
                ],
                'features' => ['templates', 'exports', 'web_search', 'unlimited_cases', 'unlimited_documents', 'priority_support'],
            ],
        ];

        foreach ($plans as $plan) {
            $record = Plan::query()->updateOrCreate(
                ['slug' => $plan['slug']],
                $plan + ['currency' => 'PHP', 'interval' => Plan::INTERVAL_MONTHLY, 'is_active' => true],
            );

            $this->syncPayMongoPlan($record);
        }
    }

    /**
     * Provision the matching PayMongo billing plans (monthly and annual) and
     * persist their ids so a subscription can be created with just a
     * customer_id and plan_id.
     */
    protected function syncPayMongoPlan(Plan $plan): void
    {
        $this->provisionPlan($plan, Plan::INTERVAL_MONTHLY);
        $this->provisionPlan($plan, Plan::INTERVAL_ANNUAL);
    }

    protected function provisionPlan(Plan $plan, string $interval): void
    {
        $column = $interval === Plan::INTERVAL_ANNUAL
            ? 'paymongo_plan_id_annual'
            : 'paymongo_plan_id';

        if ($plan->{$column} !== null) {
            return;
        }

        if (config('paymongo.secret_key') === '') {
            return;
        }

        try {
            $paymongoPlan = (new PaymongoClient)->createPlan(
                $plan->name,
                $plan->priceForInterval($interval),
                "{$plan->name} ".($interval === Plan::INTERVAL_ANNUAL ? 'annual' : 'monthly').' subscription',
                [
                    'slug' => $plan->slug,
                    'interval' => $interval,
                    'limits' => json_encode($plan->limits ?? [], JSON_THROW_ON_ERROR),
                    'features' => json_encode($plan->features ?? [], JSON_THROW_ON_ERROR),
                    'overage_price' => $plan->overage_price,
                ],
                interval: $interval === Plan::INTERVAL_ANNUAL ? 'yearly' : 'monthly',
            );

            $plan->update([$column => $paymongoPlan['id']]);
        } catch (\Throwable $e) {
            Log::warning("Could not provision PayMongo plan for {$plan->slug} ({$interval}): {$e->getMessage()}");
        }
    }
}
