<?php

namespace App\Models;

use Database\Factories\BillingEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'subscription_id',
    'event_type',
    'seats_before',
    'seats_after',
    'price_per_seat',
    'metadata',
    'occurred_at',
])]
class BillingEvent extends Model
{
    /** @use HasFactory<BillingEventFactory> */
    use HasFactory;

    public const EVENT_SEAT_ADDED = 'seat_added';

    public const EVENT_SEAT_REMOVED = 'seat_removed';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * The subscription this billing event belongs to.
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
