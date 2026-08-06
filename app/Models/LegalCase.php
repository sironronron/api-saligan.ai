<?php

namespace App\Models;

use App\Enums\MessageRole;
use Database\Factories\LegalCaseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'user_id',
    'title',
    'case_type',
    'reference',
    'priority',
    'status',
    'description',
    'related_parties',
    'due_date',
    'tags',
    'default_template_id',
    'archived_at',
])]
class LegalCase extends Model
{
    /** @use HasFactory<LegalCaseFactory> */
    use HasFactory;

    use HasUuids;

    /**
     * The table associated with the model.
     */
    protected $table = 'cases';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'related_parties' => 'array',
            'tags' => 'array',
            'due_date' => 'date',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * The user who owns this case.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The conversation threads scoped to this case, one per purpose.
     */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class, 'case_id')->orderBy('created_at')->orderBy('id');
    }

    /**
     * The first (default) conversation thread scoped to this case.
     */
    public function conversation(): HasOne
    {
        return $this->hasOne(Conversation::class, 'case_id');
    }

    /**
     * The default template associated with this case, if any.
     */
    public function defaultTemplate(): BelongsTo
    {
        return $this->belongsTo(Template::class, 'default_template_id');
    }

    /**
     * The messages in this case (through its conversation).
     */
    public function messages(): HasManyThrough
    {
        return $this->hasManyThrough(Message::class, Conversation::class, 'case_id', 'conversation_id', 'id', 'id');
    }

    /**
     * The tasks belonging to this case (through its conversation).
     */
    public function tasks(): HasManyThrough
    {
        return $this->hasManyThrough(Todo::class, Conversation::class, 'case_id', 'conversation_id', 'id', 'id')
            ->orderBy('order')->orderBy('created_at');
    }

    /**
     * The AI-generated documents produced within this case (through its conversation).
     */
    public function generatedDocuments(): HasManyThrough
    {
        return $this->hasManyThrough(Message::class, Conversation::class, 'case_id', 'conversation_id', 'id', 'id')
            ->where('role', MessageRole::Assistant)
            ->where('content', 'like', '%/export/%');
    }

    /**
     * Scope to include only active (non-archived) cases.
     */
    public function scopeActive($query): void
    {
        $query->whereNull('archived_at');
    }

    /**
     * Scope to include only archived cases.
     */
    public function scopeArchived($query): void
    {
        $query->whereNotNull('archived_at');
    }

    /**
     * Scope to include only archived cases (explicit name for clarity in the controller).
     */
    public function scopeOnlyArchived($query): void
    {
        $query->whereNotNull('archived_at');
    }
}
