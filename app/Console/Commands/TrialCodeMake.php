<?php

namespace App\Console\Commands;

use App\Models\Plan;
use App\Models\TrialCode;
use App\Models\User;
use Illuminate\Console\Command;

class TrialCodeMake extends Command
{
    protected $signature = 'trial:code
        {--days=14 : Length of the trial in days}
        {--plan= : Plan slug to trial on (defaults to the cheapest active plan)}
        {--uses= : Maximum redemptions (omit for unlimited)}
        {--expires= : Days until the code itself stops working}
        {--code= : Use this exact code instead of generating one}
        {--owner= : Email of the user this is a referral code for}
        {--note= : Free-text label, e.g. the campaign name}
        {--count=1 : How many codes to mint}';

    protected $description = 'Mint trial invite or referral codes';

    public function handle(): int
    {
        $plan = null;

        if ($slug = $this->option('plan')) {
            $plan = Plan::query()->where('slug', $slug)->first();

            if ($plan === null) {
                $this->error("No plan with slug [{$slug}].");

                return self::FAILURE;
            }
        }

        $owner = null;

        if ($email = $this->option('owner')) {
            $owner = User::query()->where('email', strtolower($email))->first();

            if ($owner === null) {
                $this->error("No user with email [{$email}].");

                return self::FAILURE;
            }
        }

        $expires = $this->option('expires');
        $uses = $this->option('uses');
        $count = max(1, (int) $this->option('count'));

        if ($count > 1 && $this->option('code')) {
            $this->error('--code cannot be combined with --count; codes must be unique.');

            return self::FAILURE;
        }

        $rows = [];

        for ($i = 0; $i < $count; $i++) {
            $code = TrialCode::create([
                'code' => $this->option('code')
                    ?: TrialCode::generateCode(prefix: $owner ? TrialCode::referralPrefixFor($owner) : ''),
                'plan_id' => $plan?->id,
                'trial_days' => (int) $this->option('days'),
                'max_redemptions' => $uses === null ? null : (int) $uses,
                'expires_at' => $expires === null ? null : now()->addDays((int) $expires),
                'owner_user_id' => $owner?->id,
                'note' => $this->option('note'),
            ]);

            $rows[] = [
                $code->code,
                $code->trial_days.'d',
                $plan?->name ?? 'cheapest active',
                $code->max_redemptions ?? '∞',
                $code->expires_at?->toDateString() ?? 'never',
                $owner?->email ?? '—',
            ];
        }

        $this->table(['Code', 'Trial', 'Plan', 'Uses', 'Code expires', 'Referrer'], $rows);

        return self::SUCCESS;
    }
}
