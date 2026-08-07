<?php

namespace App\Console\Commands;

use App\Models\Plan;
use App\Services\Billing\PaymongoClient;
use Illuminate\Console\Command;

class SyncPaymongoPlans extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'paymongo:sync-plans';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create or update PayMongo billing plans to match the local plans table';

    /**
     * Execute the console command.
     */
    public function handle(PaymongoClient $paymongo): int
    {
        if (config('paymongo.secret_key') === '') {
            $this->error('PAYMONGO_SECRET_KEY is not set. Aborting.');

            return self::FAILURE;
        }

        $plans = Plan::query()->where('is_active', true)->orderBy('sort_order')->get();

        if ($plans->isEmpty()) {
            $this->error('No active plans found. Run the PlansSeeder first.');

            return self::FAILURE;
        }

        foreach ($plans as $plan) {
            if ($plan->paymongo_plan_id === null) {
                $remote = $paymongo->createPlan($plan->name, $plan->price, $plan->name, ['slug' => $plan->slug]);
                $plan->update(['paymongo_plan_id' => $remote['id']]);
                $this->info("Created PayMongo plan for {$plan->name}: {$remote['id']}");
            } else {
                $this->line("PayMongo plan already exists for {$plan->name}: {$plan->paymongo_plan_id}");
            }
        }

        return self::SUCCESS;
    }
}
