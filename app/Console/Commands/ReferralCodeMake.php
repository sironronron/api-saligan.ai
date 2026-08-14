<?php

namespace App\Console\Commands;

use App\Models\Plan;
use App\Models\TrialCode;
use App\Models\User;
use Illuminate\Console\Command;

class ReferralCodeMake extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'referral:code
        {owner : User id or email the referral code belongs to}
        {--days=14 : Length of the trial in days}
        {--plan= : Plan slug to trial on (defaults to the free trial plan)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Mint a personal referral code for a user';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $user = $this->resolveUser($this->argument('owner'));

        if ($user === null) {
            $this->error("No user matching [{$this->argument('owner')}].");

            return self::FAILURE;
        }

        $plan = null;

        if ($slug = $this->option('plan')) {
            $plan = Plan::query()->where('slug', $slug)->first();

            if ($plan === null) {
                $this->error("No plan with slug [{$slug}].");

                return self::FAILURE;
            }
        }

        $code = TrialCode::create([
            'code' => TrialCode::generateCode(prefix: TrialCode::referralPrefixFor($user)),
            'plan_id' => $plan?->id,
            'trial_days' => (int) $this->option('days'),
            'max_redemptions' => null,
            'owner_user_id' => $user->id,
            'note' => 'Personal referral code',
        ]);

        $this->table(
            ['Code', 'Trial', 'Plan', 'Uses', 'Referrer'],
            [[
                $code->code,
                $code->trial_days.'d',
                $plan?->name ?? 'cheapest active',
                '∞',
                $user->email,
            ]],
        );

        return self::SUCCESS;
    }

    /**
     * Look up the user by id or email.
     */
    protected function resolveUser(string $identifier): ?User
    {
        return ctype_digit($identifier)
            ? User::query()->find((int) $identifier)
            : User::query()->where('email', strtolower($identifier))->first();
    }
}
