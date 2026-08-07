<?php

namespace App\Models;

use Database\Factories\SubscriptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'plan_id',
    'interval',
    'gateway',
    'paymongo_subscription_id',
    'paymongo_customer_id',
    'lemonsqueezy_subscription_id',
    'lemonsqueezy_customer_id',
    'status',
    'current_period_start',
    'current_period_end',
    'trial_ends_at',
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
            'trial_ends_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
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
     * Whether the subscription is still within its trial window.
     */
    public function onTrial(): bool
    {
        return $this->trial_ends_at !== null && $this->trial_ends_at->isFuture();
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
