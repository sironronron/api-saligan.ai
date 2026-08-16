<?php

namespace App\Models;

use Database\Factories\SubscriptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'organization_id',
    'user_id',
    'plan_id',
    'interval',
    'gateway',
    'paymongo_subscription_id',
    'paymongo_customer_id',
    'lemonsqueezy_subscription_id',
    'lemonsqueezy_customer_id',
    'status',
    'seats_purchased',
    'price_per_seat',
    'current_period_start',
    'current_period_end',
    'trial_ends_at',
    'trial_code_id',
    'trial_warned_at',
    'cancelled_at',
])]
class Subscription extends Model
{
    /** @use HasFactory<SubscriptionFactory> */
    use HasFactory;

    public const GATEWAY_PAYMONGO = 'paymongo';

    public const GATEWAY_LEMONSQUEEZY = 'lemonsqueezy';

    public const STATUS_INCOMPLETE = 'incomplete';

    public const STATUS_INCOMPLETE_CANCELLED = 'incomplete_cancelled';

    public const STATUS_ACTIVE = 'active';

    /** A code-granted free trial. Grants access until `trial_ends_at`. */
    public const STATUS_TRIALING = 'trialing';

    public const STATUS_PAST_DUE = 'past_due';

    public const STATUS_UNPAID = 'unpaid';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_PAUSED = 'paused';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'current_period_start' => 'date',
            'current_period_end' => 'date',
            'trial_ends_at' => 'datetime',
            'trial_warned_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'seats_purchased' => 'integer',
            'price_per_seat' => 'integer',
        ];
    }

    /**
     * The organization this subscription belongs to.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * The user this subscription belongs to.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The plan this subscription is on.
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * Whether the subscription currently grants full access.
     *
     * A trial counts, but only until it lapses — the row is left in place once
     * it does, so the user keeps their organization and history and simply
     * loses access until they subscribe.
     */
    public function isActive(): bool
    {
        if ($this->status === self::STATUS_ACTIVE) {
            return true;
        }

        return $this->onTrial();
    }

    /**
     * Whether this is a trial that has not yet lapsed.
     */
    public function onTrial(): bool
    {
        return $this->status === self::STATUS_TRIALING
            && $this->trial_ends_at !== null
            && $this->trial_ends_at->isFuture();
    }

    /**
     * Whole days left on the trial, floored at zero. Null when not a trial.
     */
    public function trialDaysRemaining(): ?int
    {
        if ($this->status !== self::STATUS_TRIALING || $this->trial_ends_at === null) {
            return null;
        }

        return max(0, (int) ceil(now()->diffInDays($this->trial_ends_at, false)));
    }

    /**
     * The code-granted trial this subscription came from, if any.
     */
    public function trialCode(): BelongsTo
    {
        return $this->belongsTo(TrialCode::class);
    }

    /**
     * The billing events recorded against this subscription.
     */
    public function billingEvents(): HasMany
    {
        return $this->hasMany(BillingEvent::class);
    }

    /**
     * The amount the next invoice should bill for, in centavos:
     * seats purchased times the per-seat price.
     *
     * Subscriptions written before the seat price was stamped onto the row
     * carry no `price_per_seat`. The plan's own seat price is what a seat
     * costs there, so it answers before the plan's full list price — falling
     * straight through to that would bill every seat at the whole plan.
     * The list price stays the last resort, for plans that sell no seats at
     * all and so bill their one bundled seat at the plan rate.
     */
    public function nextInvoiceAmount(): int
    {
        $pricePerSeat = $this->price_per_seat
            ?? $this->plan?->seat_price
            ?? $this->plan?->priceForInterval($this->interval ?? 'monthly')
            ?? 0;

        return $this->seats_purchased * $pricePerSeat;
    }

    /**
     * The provider-side subscription id for the subscription's gateway.
     */
    public function gatewaySubscriptionId(): ?string
    {
        return $this->gateway === self::GATEWAY_LEMONSQUEEZY
            ? $this->lemonsqueezy_subscription_id
            : $this->paymongo_subscription_id;
    }
}
