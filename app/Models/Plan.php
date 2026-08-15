<?php

namespace App\Models;

use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'slug',
    'name',
    'price',
    'price_annual',
    'overage_price',
    'included_seats',
    'seat_price',
    'currency',
    'interval',
    'limits',
    'features',
    'paymongo_plan_id',
    'paymongo_plan_id_annual',
    'lemonsqueezy_variant_id',
    'lemonsqueezy_variant_id_annual',
    'is_active',
    'contact_sales',
    'sort_order',
])]
class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use HasFactory;

    use HasUuids;

    /**
     * The plan a code-granted free trial runs on. Never sold: it is seeded
     * inactive so it stays out of the pricing page and out of checkout, and
     * exists only to give a trial its own, smaller allowance.
     */
    public const SLUG_TRIAL = 'trial';

    public const SLUG_STANDARD = 'standard';

    public const SLUG_PRO = 'pro';

    public const SLUG_FIRM = 'firm';

    /**
     * The contract-priced tier. Listed on the pricing page so it can be asked
     * for, but never sold through checkout: its price is agreed with sales and
     * the subscription is granted by the `plan:business` command.
     */
    public const SLUG_BUSINESS = 'business';

    public const INTERVAL_MONTHLY = 'monthly';

    public const INTERVAL_ANNUAL = 'annual';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'price_annual' => 'integer',
            'overage_price' => 'integer',
            'included_seats' => 'integer',
            'seat_price' => 'integer',
            'limits' => 'array',
            'features' => 'array',
            'is_active' => 'boolean',
            'contact_sales' => 'boolean',
            'sort_order' => 'integer',
            'lemonsqueezy_variant_id' => 'integer',
            'lemonsqueezy_variant_id_annual' => 'integer',
        ];
    }

    /**
     * The subscriptions using this plan.
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * Whether the plan can be bought without talking to anyone. A
     * contact-sales plan carries no list price, so quoting one — in checkout,
     * on the pricing page, or in an email — would be quoting a number nobody
     * agreed to.
     */
    public function isSelfServe(): bool
    {
        return ! $this->contact_sales;
    }

    /**
     * The price formatted as a PHP peso string, e.g. ₱1,500.
     */
    public function priceLabel(): string
    {
        if ($this->contact_sales) {
            return 'Custom';
        }

        return '₱'.number_format($this->price / 100);
    }

    /**
     * The annual price formatted as a PHP peso string, e.g. ₱14,940.
     */
    public function priceAnnualLabel(): string
    {
        if ($this->contact_sales) {
            return 'Custom';
        }

        return '₱'.number_format(($this->price_annual ?? $this->price * 12) / 100);
    }

    /**
     * The price for the given billing interval, in centavos.
     */
    public function priceForInterval(string $interval): int
    {
        if ($interval === self::INTERVAL_ANNUAL) {
            return $this->price_annual ?? $this->price * 12;
        }

        return $this->price;
    }

    /**
     * The stored PayMongo plan id for the given billing interval.
     */
    public function paymongoPlanIdForInterval(string $interval): ?string
    {
        return $interval === self::INTERVAL_ANNUAL
            ? $this->paymongo_plan_id_annual
            : $this->paymongo_plan_id;
    }

    /**
     * The LemonSqueezy variant id for the given billing interval.
     */
    public function lemonSqueezyVariantIdForInterval(string $interval): ?int
    {
        return $interval === self::INTERVAL_ANNUAL
            ? $this->lemonsqueezy_variant_id_annual
            : $this->lemonsqueezy_variant_id;
    }

    /**
     * The per-message overage price formatted as a peso string, e.g. ₱3.50.
     */
    public function overageLabel(): string
    {
        $amount = ($this->overage_price ?? 0) / 100;

        return '₱'.number_format($amount, $amount == round($amount) ? 0 : 2);
    }

    /**
     * Whether the plan sells seats beyond the ones its list price covers.
     */
    public function sellsSeats(): bool
    {
        return $this->seat_price !== null;
    }

    /**
     * The per-seat price formatted as a peso string, or null when the plan
     * does not sell seats — the distinction the pricing page needs to choose
     * between quoting an add-on and saying there is none.
     */
    public function seatPriceLabel(): ?string
    {
        if (! $this->sellsSeats()) {
            return null;
        }

        return '₱'.number_format($this->seat_price / 100);
    }
}
