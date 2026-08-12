<?php

namespace App\Console\Commands;

use App\Models\Plan;
use App\Services\Billing\EarningsModel;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class CostingEarnings extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'costing:earnings
        {--exchange-rate=57 : PHP per USD}
        {--provider=claude-sonnet-5 : Chat model to cost against}
        {--cache-hit-rate=1.0 : Share of requests landing on a warm prompt cache (0-1)}
        {--no-cache : Cost without prompt caching at all}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Print per-plan monthly earnings, AI costs, and margins in pesos';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $rate = (float) $this->option('exchange-rate');
        $provider = (string) $this->option('provider');
        $cached = ! $this->option('no-cache');
        $hitRate = (float) $this->option('cache-hit-rate');

        $meta = EarningsModel::provider($provider);

        $this->line(sprintf(
            '%s at $%.2f/$%.2f per MTok · %s · ₱%.0f/USD · %s in / %s out per message',
            $meta['label'],
            $meta['input'],
            $meta['output'],
            $cached ? sprintf('cache on, %.0f%% hit rate', $hitRate * 100) : 'no caching',
            $rate,
            number_format(EarningsModel::INPUT_TOKENS_PER_MESSAGE),
            number_format(EarningsModel::OUTPUT_TOKENS_PER_MESSAGE),
        ));
        $this->newLine();

        $rows = $this->plans()->map(function (array $plan) use ($rate, $provider, $cached, $hitRate): array {
            $earnings = EarningsModel::earnings(
                priceCents: $plan['price'],
                messageCap: $plan['messages'],
                documentCap: $plan['documents'],
                providerMix: [$provider => 1.0],
                exchangeRate: $rate,
                cached: $cached,
                cacheHitRate: $hitRate,
            );

            return [
                'plan' => $plan['name'],
                'price' => '₱'.number_format($earnings['price_pesos'], 0),
                'messages' => $plan['messages'],
                'cost' => '₱'.number_format($earnings['message_cost_pesos'], 2),
                // Shown beside the marginal cost so an overage rate priced
                // below what the message costs to serve is visible at a glance.
                'overage' => $plan['overage'] === null
                    ? '—'
                    : '₱'.number_format($plan['overage'] / 100, 2),
                'ai_cogs' => '₱'.number_format($earnings['ai_cogs_pesos'], 2),
                'paymongo' => '₱'.number_format($earnings['paymongo_pesos'], 2),
                'net' => '₱'.number_format($earnings['net_pesos'], 2),
                'margin' => number_format($earnings['margin'] * 100, 1).'%',
            ];
        });

        $this->table(
            ['Plan', 'Price', 'Msgs', 'Cost ₱/msg', 'Overage ₱/msg', 'AI COGS', 'PayMongo', 'Net', 'Margin'],
            $rows->all(),
        );

        return self::SUCCESS;
    }

    /**
     * Active plans from the database, falling back to the default pricing
     * definitions when the database has not been seeded.
     *
     * @return Collection<int, array{name: string, price: int, messages: int, documents: ?int, overage: ?int}>
     */
    protected function plans(): Collection
    {
        $plans = Plan::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        if ($plans->isNotEmpty()) {
            return $plans->map(fn (Plan $plan): array => [
                'name' => $plan->name,
                'price' => $plan->price,
                'messages' => $plan->limits['messages_used'] ?? 0,
                'documents' => $plan->limits['documents_uploaded'] ?? null,
                'overage' => $plan->overage_price,
            ]);
        }

        return collect(EarningsModel::defaultPlans());
    }
}
