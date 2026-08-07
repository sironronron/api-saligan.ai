<?php

namespace App\Models;

use App\Enums\ChatProvider;
use App\Enums\MessageRole;
use Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'id',
    'conversation_id',
    'role',
    'content',
    'provider',
    'cited_chunk_ids',
    'cited_legal_chunk_ids',
    'metadata',
    'feedback',
    'feedback_at',
])]
class Message extends Model
{
    /** @use HasFactory<MessageFactory> */
    use HasFactory;

    use HasUuids;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => MessageRole::class,
            'provider' => ChatProvider::class,
            'cited_chunk_ids' => 'array',
            'cited_legal_chunk_ids' => 'array',
            'metadata' => 'array',
            'feedback_at' => 'datetime',
        ];
    }

    /**
     * The conversation this message belongs to.
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
