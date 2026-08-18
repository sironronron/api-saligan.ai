<?php

namespace App\Models;

use Database\Factories\NotarialJournalEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'lawyer_id',
    'vetting_request_id',
    'signer_name',
    'id_type',
    'id_number',
    'document_type',
    'verification_method',
    'certificate_number',
    'session_recording_ref',
    'notarized_at',
    'metadata',
])]
class NotarialJournalEntry extends Model
{
    /** @use HasFactory<NotarialJournalEntryFactory> */
    use HasFactory;

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'notarized_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /**
     * The notary-lawyer who performed the act.
     */
    public function lawyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lawyer_id');
    }

    /**
     * The request this entry belongs to.
     */
    public function vettingRequest(): BelongsTo
    {
        return $this->belongsTo(VettingRequest::class);
    }
}
