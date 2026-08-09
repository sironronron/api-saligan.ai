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
     */
    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
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
     */
    public function nextInvoiceAmount(): int
    {
        $pricePerSeat = $this->price_per_seat ?? $this->plan?->priceForInterval($this->interval ?? 'monthly') ?? 0;

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
