<?php

namespace App\Models;

use Database\Factories\MatterMemoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'organization_id',
    'case_id',
    'user_id',
    'type',
    'content',
    'metadata',
    'is_active',
])]
class MatterMemory extends Model
{
    /** @use HasFactory<MatterMemoryFactory> */
    use HasFactory;

    use HasUuids;

    /**
     * The table associated with the model.
     */
    protected $table = 'matter_memory';

    /**
     * Memory types that can be stored.
     */
    public const TYPES = ['fact', 'preference', 'deadline', 'strategy'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * The organization (firm) this memory belongs to.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * The case (matter) this memory is scoped to.
     */
    public function case(): BelongsTo
    {
        return $this->belongsTo(LegalCase::class, 'case_id');
    }

    /**
     * The user who created this memory.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope to active memories only.
     */
    public function scopeActive($query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Scope to a specific case.
     */
    public function scopeForCase($query, string $caseId): void
    {
        $query->where('case_id', $caseId);
    }

    /**
     * Scope to a specific organization.
     */
    public function scopeForOrganization($query, ?string $organizationId): void
    {
        if ($organizationId !== null) {
            $query->where('organization_id', $organizationId);
        }
    }

    /**
     * Scope to a specific memory type.
     */
    public function scopeOfType($query, string $type): void
    {
        $query->where('type', $type);
    }
}
