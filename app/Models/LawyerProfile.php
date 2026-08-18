<?php

namespace App\Models;

use App\Enums\LawyerVerificationStatus;
use App\Enums\VettingRequestStatus;
use Database\Factories\LawyerProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id',
    'full_name',
    'bar_number',
    'bar_jurisdiction',
    'ptr_number',
    'practice_areas',
    'region',
    'city',
    'phone',
    'is_notary',
    'notarial_commission_number',
    'notarial_commission_issuer',
    'notarial_commission_expires_at',
    'id_document_path',
    'bar_membership_document_path',
    'verification_status',
    'verification_reason',
    'verification_reviewed_at',
    'verified_at',
    'available',
    'max_concurrent_assignments',
    'notify_email',
    'notify_sms',
    'notify_push',
    'notify_in_app',
    'profile_changed_at',
])]
class LawyerProfile extends Model
{
    /** @use HasFactory<LawyerProfileFactory> */
    use HasFactory;

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'practice_areas' => 'array',
            'is_notary' => 'boolean',
            'notarial_commission_expires_at' => 'date',
            'verification_status' => LawyerVerificationStatus::class,
            'verification_reviewed_at' => 'datetime',
            'verified_at' => 'datetime',
            'available' => 'boolean',
            'max_concurrent_assignments' => 'integer',
            'notify_email' => 'boolean',
            'notify_sms' => 'boolean',
            'notify_push' => 'boolean',
            'notify_in_app' => 'boolean',
            'profile_changed_at' => 'datetime',
        ];
    }

    /**
     * The user this profile belongs to.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The vetting requests this lawyer has been offered.
     */
    public function matches(): HasMany
    {
        return $this->hasMany(VettingRequestMatch::class, 'lawyer_id');
    }

    /**
     * The requests this lawyer currently holds (accepted and not finished).
     */
    public function activeRequests(): HasMany
    {
        return $this->hasMany(VettingRequest::class, 'assigned_lawyer_id')
            ->whereIn('status', [
                VettingRequestStatus::Accepted,
                VettingRequestStatus::UnderReview,
                VettingRequestStatus::Vetted,
                VettingRequestStatus::Notarized,
            ]);
    }

    /**
     * Whether the profile's notarial commission is currently in force.
     */
    protected function hasActiveCommission(): Attribute
    {
        return Attribute::get(fn (): bool => $this->is_notary
            && $this->notarial_commission_expires_at !== null
            && $this->notarial_commission_expires_at->isFuture());
    }

    /**
     * Whether the lawyer may accept notarization requests.
     */
    public function canNotarize(): bool
    {
        return $this->has_active_commission === true;
    }
}
