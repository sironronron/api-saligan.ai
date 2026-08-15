<?php

namespace App\Models;

use Database\Factories\InvitationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Notifications\Notifiable;

#[Fillable([
    'organization_id',
    'case_id',
    'invited_by',
    'email',
    'token',
    'status',
    'expires_at',
])]
class Invitation extends Model
{
    /** @use HasFactory<InvitationFactory> */
    use HasFactory;

    use Notifiable;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REVOKED = 'revoked';

    public const STATUS_EXPIRED = 'expired';

    /**
     * How long an invite stays valid before it expires, in days.
     */
    public const DEFAULT_EXPIRES_DAYS = 7;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }

    /**
     * The organization this invitation grants access to.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * The case this invite was sent from, when it came from one. Acceptance
     * assigns the new member to it, so the invite lands them on the matter
     * they were actually invited to work rather than an empty case list.
     */
    public function case(): BelongsTo
    {
        return $this->belongsTo(LegalCase::class, 'case_id');
    }

    /**
     * The admin who sent this invitation, when still known.
     */
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /**
     * Whether the invitation is still valid for acceptance.
     */
    public function isActive(): bool
    {
        return $this->status === self::STATUS_PENDING
            && $this->expires_at !== null
            && $this->expires_at->isFuture();
    }

    /**
     * Scope to invitations that still count against the seat pool: pending
     * and not yet expired.
     *
     * @param  Builder<Invitation>  $query
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING)
            ->where('expires_at', '>', now());
    }

    /**
     * Scope to pending invitations, expired or not.
     *
     * @param  Builder<Invitation>  $query
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }
}
