<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserAcceptance extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'user_id',
        'document_type',
        'accepted_at',
        'ip_address',
        'user_agent',
        'document_version',
        'document_hash',
        'marketing_opt_in',
    ];

    protected $casts = [
        'accepted_at' => 'datetime',
        'marketing_opt_in' => 'boolean',
    ];

    /**
     * Get the user who made this acceptance.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope to filter by document type.
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('document_type', $type);
    }

    /**
     * Check if this acceptance is for the current document version.
     */
    public function isCurrentVersion(): bool
    {
        return $this->document_version === LegalDocument::currentVersion($this->document_type);
    }
}
