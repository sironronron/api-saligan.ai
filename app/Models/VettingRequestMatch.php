<?php

namespace App\Models;

use App\Enums\VettingMatchStatus;
use Database\Factories\VettingRequestMatchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'vetting_request_id',
    'lawyer_id',
    'status',
    'notified_at',
    'responded_at',
    'expires_at',
])]
class VettingRequestMatch extends Model
{
    /** @use HasFactory<VettingRequestMatchFactory> */
    use HasFactory;

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => VettingMatchStatus::class,
            'notified_at' => 'datetime',
            'responded_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * The request this match belongs to.
     */
    public function vettingRequest(): BelongsTo
    {
        return $this->belongsTo(VettingRequest::class);
    }

    /**
     * The lawyer who was offered the request.
     */
    public function lawyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lawyer_id');
    }
}
