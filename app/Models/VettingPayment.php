<?php

namespace App\Models;

use App\Enums\VettingPaymentStatus;
use Database\Factories\VettingPaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'vetting_request_id',
    'submitter_id',
    'lawyer_id',
    'gateway',
    'kind',
    'status',
    'amount',
    'gateway_payment_intent_id',
    'gateway_payment_id',
    'gateway_payment_method_id',
    'gateway_refund_id',
    'receipt_ref',
    'captured_at',
    'refunded_at',
    'voided_at',
    'metadata',
])]
class VettingPayment extends Model
{
    /** @use HasFactory<VettingPaymentFactory> */
    use HasFactory;

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => VettingPaymentStatus::class,
            'amount' => 'integer',
            'captured_at' => 'datetime',
            'refunded_at' => 'datetime',
            'voided_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public const KIND_VETTING = 'vetting';

    public const KIND_NOTARIZATION = 'notarization';

    /**
     * The request this payment belongs to.
     */
    public function vettingRequest(): BelongsTo
    {
        return $this->belongsTo(VettingRequest::class);
    }

    /**
     * The user who paid.
     */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitter_id');
    }

    /**
     * The lawyer earning the fee, once assigned.
     */
    public function lawyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lawyer_id');
    }
}
