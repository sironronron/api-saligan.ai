<?php

namespace App\Console\Commands;

use App\Services\Billing\EarningsModel;
use Illuminate\Console\Command;

/**
 * Per-seat pricing model with a pooled message allowance, for comparing seat
 * ladders against the per-plan message caps that `costing:earnings` reports.
 */
class CostingSeats extends Command
{
    protected $signature = 'costing:seats
        {--exchange-rate=57 : PHP per USD}
        {--provider=claude-sonnet-5 : Chat model to cost against}
        {--allowance=250 : Pooled messages granted per seat}
        {--utilisation=1.0 : Share of the pooled allowance actually consumed (0-1)}
        {--cache-hit-rate=0.7 : Share of requests landing on a warm prompt cache (0-1)}
        {--cost-per-message= : Observed PHP cost per message, overriding the token model}
        {--seats=1,3,5,10,25 : Seat counts to report}';

    protected $description = 'Model per-seat pricing with a pooled message allowance';

    /**
     * Seat price in centavos by tier, and the minimum seat count that unlocks it.
     *
     * @var array<int, array{name: string, price: int, min_seats: int}>
     */
    protected const TIERS = [
        ['name' => 'Solo', 'price' => 290_000, 'min_seats' => 1],
        ['name' => 'Practice', 'price' => 260_000, 'min_seats' => 3],
        ['name' => 'Firm', 'price' => 230_000, 'min_seats' => 10],
    ];

    public function handle(): int
    {
        $rate = (float) $this->option('exchange-rate');
        $provider = (string) $this->option('provider');
        $allowance = (int) $this->option('allowance');
        $utilisation = (float) $this->option('utilisation');
        $hitRate = (float) $this->option('cache-hit-rate');
        $observed = $this->option('cost-per-message');
        $observed = $observed === null ? null : (float) $observed;

        $meta = EarningsModel::provider($provider);

        $this->line(sprintf(
            '%s · %s · %d msgs/seat pooled · %.0f%% consumed · ₱%.0f/USD',
            $meta['label'],
            $observed !== null
                ? sprintf('observed ₱%.2f/msg', $observed)
                : sprintf('modelled, %.0f%% cache hit', $hitRate * 100),
            $allowance,
            $utilisation * 100,
            $rate,
        ));
        $this->newLine();

        $rows = [];

        foreach ($this->seatCounts() as $seats) {
            $tier = $this->tierFor($seats);

            $e = EarningsModel::seatEarnings(
                pricePerSeatCents: $tier['price'],
                seats: $seats,
                pooledMessagesPerSeat: $allowance,
                providerMix: [$provider => 1.0],
                exchangeRate: $rate,
                utilisation: $utilisation,
                cached: true,
                cacheHitRate: $hitRate,
                costPerMessagePesos: $observed,
            );

            $rows[] = [
                'tier' => $tier['name'],
                'seats' => $seats,
                'per_seat' => '₱'.number_format($tier['price'] / 100),
                'mrr' => '₱'.number_format($e['price_pesos']),
                'pool' => number_format($e['allowance']),
                'used' => number_format($e['messages_consumed']),
                'cogs' => '₱'.number_format($e['ai_cogs_pesos']),
                'net' => '₱'.number_format($e['net_pesos']),
                'margin' => number_format($e['margin'] * 100, 1).'%',
            ];
        }

        $this->table(
            ['Tier', 'Seats', '₱/seat', 'MRR', 'Pool', 'Used', 'AI COGS', 'Net', 'Margin'],
            $rows,
        );

        return self::SUCCESS;
    }

    /**
     * @return array<int, int>
     */
    protected function seatCounts(): array
    {
        return collect(explode(',', (string) $this->option('seats')))
            ->map(fn (string $value): int => (int) trim($value))
            ->filter(fn (int $value): bool => $value > 0)
            ->values()
            ->all();
    }

    /**
     * The best tier a given seat count qualifies for.
     *
     * @return array{name: string, price: int, min_seats: int}
     */
    protected function tierFor(int $seats): array
    {
        $match = self::TIERS[0];

        foreach (self::TIERS as $tier) {
            if ($seats >= $tier['min_seats']) {
                $match = $tier;
            }
        }

        return $match;
    }
}
