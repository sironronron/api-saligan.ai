<?php

namespace App\Models;

use App\Enums\ChatProvider;
use App\Models\Concerns\HasLabels;
use Database\Factories\ConversationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id',
    'case_id',
    'title',
    'purpose',
    'provider',
])]
class Conversation extends Model
{
    /** @use HasFactory<ConversationFactory> */
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
            'provider' => ChatProvider::class,
        ];
    }

    /**
     * The user who owns this conversation.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The case this conversation is scoped to, when it belongs to one.
     */
    public function case(): BelongsTo
    {
        return $this->belongsTo(LegalCase::class, 'case_id');
    }

    /**
     * The messages in this conversation.
     */
    /**
     * Whether the user may open this thread: they started it, or it hangs off
     * a case they are on.
     *
     * A case conversation is created by whoever opened the thread, so an
     * assignee working someone else's matter fails an owner comparison on the
     * thread while passing one on the case. The case is the unit of access;
     * threads inherit it.
     */
    public function isAccessibleBy(User $user): bool
    {
        if ($this->user_id === $user->id) {
            return true;
        }

        return $this->case !== null && $this->case->isAccessibleBy($user);
    }

    /**
     * Scope to the threads a user may open: the ones they started, plus every
     * thread hanging off a case they are on.
     *
     * The listing mirror of `isAccessibleBy`, for the same reason the document
     * one exists: a draft lives on the thread that produced it, so scoping a
     * draft listing to the caller's own threads hides the case's drafts from
     * everyone but whoever happened to generate them.
     *
     * @param  Builder<Conversation>  $query
     */
    public function scopeVisibleTo($query, User $user): void
    {
        $query->where(function ($scoped) use ($user) {
            $scoped->where('conversations.user_id', $user->id)
                ->orWhereHas('case', fn ($case) => $case->visibleTo($user));
        });
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class)->orderBy('created_at');
    }

    /**
     * The tasks in this conversation.
     */
    public function todos(): HasMany
    {
        return $this->hasMany(Todo::class)->orderBy('order')->orderBy('created_at');
    }
}
