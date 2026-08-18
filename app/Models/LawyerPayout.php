<?php

namespace App\Models;

use Database\Factories\LawyerPayoutFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'lawyer_id',
    'period_start',
    'period_end',
    'gross_amount',
    'platform_fee',
    'lawyer_share',
    'notarization_count',
    'status',
    'payout_ref',
    'paid_at',
])]
class LawyerPayout extends Model
{
    /** @use HasFactory<LawyerPayoutFactory> */
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'gross_amount' => 'integer',
            'platform_fee' => 'integer',
            'lawyer_share' => 'integer',
            'notarization_count' => 'integer',
            'paid_at' => 'datetime',
        ];
    }

    /**
     * The notary-lawyer this payout pays out to.
     */
    public function lawyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lawyer_id');
    }
}
