<?php

namespace App\Models;

use App\Enums\UrgencyLevel;
use App\Enums\VettingMatchStatus;
use App\Enums\VettingPaymentStatus;
use App\Enums\VettingRequestStatus;
use App\Enums\VettingServiceType;
use Database\Factories\VettingRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;

#[Fillable([
    'submitter_id',
    'document_id',
    'document_type',
    'summary',
    'jurisdiction',
    'service_type',
    'urgency',
    'status',
    'assigned_lawyer_id',
    'vetting_fee',
    'notarization_fee',
    'property_value',
    'processing_fee',
    'payment_status',
    'gateway_payment_intent_id',
    'gateway_checkout_url',
    'deadline_at',
    'locked_at',
    'session_scheduled_at',
    'session_link',
    'session_provider',
    'certificate_number',
    'completed_at',
    'cancelled_at',
    'cancellation_reason',
    'metadata',
    'letter_draft_message_id',
])]
class VettingRequest extends Model
{
    /** @use HasFactory<VettingRequestFactory> */
    use HasFactory, HasUuids;

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'service_type' => VettingServiceType::class,
            'urgency' => UrgencyLevel::class,
            'status' => VettingRequestStatus::class,
            'payment_status' => VettingPaymentStatus::class,
            'vetting_fee' => 'integer',
            'notarization_fee' => 'integer',
            'property_value' => 'integer',
            'processing_fee' => 'integer',
            'deadline_at' => 'datetime',
            'locked_at' => 'datetime',
            'session_scheduled_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /**
     * The user who submitted the document.
     */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitter_id');
    }

    /**
     * The lawyer holding the request, once accepted.
     */
    public function assignedLawyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_lawyer_id');
    }

    /**
     * The document to be vetted/notarized.
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * The letter draft this request was started from, when the submitter sent a
     * Batayan-generated letter straight into vetting or notarization. Lets the
     * same people who can open the request also comment on that draft.
     */
    public function letterDraftMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'letter_draft_message_id');
    }

    /**
     * Every lawyer offered this request, with their response status.
     */
    public function matches(): HasMany
    {
        return $this->hasMany(VettingRequestMatch::class);
    }

    /**
     * The clarification thread between submitter and lawyer.
     */
    public function messages(): HasMany
    {
        return $this->hasMany(VettingMessage::class);
    }

    /**
     * The payment rows behind this request.
     */
    public function payments(): HasMany
    {
        return $this->hasMany(VettingPayment::class);
    }

    /**
     * The notarial journal entry once the document is notarized.
     */
    public function journalEntry(): HasOne
    {
        return $this->hasOne(NotarialJournalEntry::class);
    }

    /**
     * Whether the request includes a notarization leg.
     */
    public function includesNotarization(): bool
    {
        return $this->service_type->includesNotarization();
    }

    /**
     * The total fee the submitter owes, in centavos: the service fees plus the
     * PayMongo processing fee passed through to the buyer.
     */
    public function totalFee(): int
    {
        return (int) ($this->vetting_fee ?? 0)
            + (int) ($this->notarization_fee ?? 0)
            + (int) ($this->processing_fee ?? 0);
    }

    /**
     * Whether the request still needs a payment before lawyers are matched.
     */
    public function requiresPayment(): bool
    {
        return $this->totalFee() > 0;
    }

    /**
     * Whether the request is still being worked on.
     */
    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    /**
     * Whether a user can view the underlying document: the submitter, the
     * assigned lawyer, an admin, or (for an accepted request) anyone who was
     * offered it and is still eligible.
     */
    public function isAccessibleBy(User $user): bool
    {
        if ($user->is_admin) {
            return true;
        }

        if ($user->id === $this->submitter_id) {
            return true;
        }

        if ($user->id === $this->assigned_lawyer_id) {
            return true;
        }

        return $this->matches()
            ->where('lawyer_id', $user->id)
            ->where('status', VettingMatchStatus::Notified)
            ->exists();
    }

    /**
     * The users allowed to post in the request's clarification thread.
     */
    public function participants(): Collection
    {
        return collect([$this->submitter_id, $this->assigned_lawyer_id])
            ->filter(fn (?int $id) => $id !== null)
            ->unique()
            ->values();
    }
}
