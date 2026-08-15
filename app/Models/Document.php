<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use App\Models\Concerns\HasLabels;
use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id',
    'case_id',
    'title',
    'original_filename',
    'storage_path',
    'mime_type',
    'status',
    'error_message',
])]
class Document extends Model
{
    /** @use HasFactory<DocumentFactory> */
    use HasFactory;

    use HasLabels;
    use HasUuids;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DocumentStatus::class,
            'digest_generated_at' => 'datetime',
        ];
    }

    /**
     * The user who uploaded this document.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The case this document is attached to, if any.
     */
    public function case(): BelongsTo
    {
        return $this->belongsTo(LegalCase::class, 'case_id');
    }

    /**
     * The chunks extracted from this document.
     */
    public function chunks(): HasMany
    {
        return $this->hasMany(DocumentChunk::class);
    }

    /**
     * Whether the user may read this document: they uploaded it, or it is
     * attached to a case they are on.
     *
     * A case's documents are its shared evidence — an assignee who cannot open
     * the file shelf cannot work the matter. A document with no case stays
     * private to whoever uploaded it.
     */
    public function isAccessibleBy(User $user): bool
    {
        if ($this->user_id === $user->id) {
            return true;
        }

        return $this->case !== null && $this->case->isAccessibleBy($user);
    }

    /**
     * Whether the user may delete this document. Narrower than reading it:
     * the uploader, or the owner of the case it was filed into. An assignee
     * cannot delete a colleague's evidence out from under them.
     */
    public function isDeletableBy(User $user): bool
    {
        if ($this->user_id === $user->id) {
            return true;
        }

        return $this->case !== null && $this->case->user_id === $user->id;
    }
}
