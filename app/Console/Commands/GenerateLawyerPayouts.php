<?php

namespace App\Console\Commands;

use App\Enums\VettingPaymentStatus;
use App\Models\LawyerPayout;
use App\Models\User;
use App\Models\VettingPayment;
use App\Notifications\LawyerPayoutAvailable;
use App\Services\Vetting\VettingSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Aggregates captured notarization payments into weekly per-lawyer payouts.
 * Idempotent: a lawyer whose period already has a payout row is skipped, so
 * re-running the command never double-pays.
 */
class GenerateLawyerPayouts extends Command
{
    protected $signature = 'vetting:payouts-generate {--period-end=}';

    protected $description = 'Generate weekly notarization payouts for lawyers';

    public function handle(VettingSettings $settings): int
    {
        $reference = Carbon::parse($this->option('period-end') ?: now());

        // The previous complete ISO week (Monday–Sunday) before the reference's
        // own week, so a Monday morning run settles the week that just ended.
        $periodStart = $reference->copy()
            ->startOfWeek(Carbon::MONDAY)
            ->startOfDay()
            ->subWeek();
        $periodEnd = $periodStart->copy()->addWeek()->subSecond();

        $payments = VettingPayment::query()
            ->where('kind', VettingPayment::KIND_NOTARIZATION)
            ->where('status', VettingPaymentStatus::Captured)
            ->whereNotNull('lawyer_id')
            ->whereBetween('captured_at', [$periodStart, $periodEnd])
            ->get();

        if ($payments->isEmpty()) {
            $this->info('No captured notarizations in the period.');

            return self::SUCCESS;
        }

        $commissionPercent = $settings->commissionPercent();

        $grouped = $payments->groupBy('lawyer_id');

        $generated = 0;

        foreach ($grouped as $lawyerId => $lawyerPayments) {
            $alreadyGenerated = LawyerPayout::query()
                ->where('lawyer_id', $lawyerId)
                ->where('period_start', $periodStart->toDateString())
                ->exists();

            if ($alreadyGenerated) {
                continue;
            }

            $gross = (int) $lawyerPayments->sum('amount');
            $platformFee = (int) round($gross * $commissionPercent / 100);
            $lawyerShare = $gross - $platformFee;

            $payout = DB::transaction(function () use ($lawyerId, $periodStart, $periodEnd, $gross, $platformFee, $lawyerShare, $lawyerPayments): LawyerPayout {
                return LawyerPayout::create([
                    'lawyer_id' => (int) $lawyerId,
                    'period_start' => $periodStart,
                    'period_end' => $periodEnd,
                    'gross_amount' => $gross,
                    'platform_fee' => $platformFee,
                    'lawyer_share' => $lawyerShare,
                    'notarization_count' => $lawyerPayments->count(),
                    'status' => LawyerPayout::STATUS_PENDING,
                ]);
            });

            $lawyer = User::find($lawyerId);
            $lawyer?->notify(new LawyerPayoutAvailable($payout));

            $generated++;
        }

        $this->info("Generated {$generated} payout(s).");

        return self::SUCCESS;
    }
}
