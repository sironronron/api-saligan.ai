<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A comment pinned to a single block (paragraph, heading, list item, etc.) of
 * a drafted letter. Comments belong to the draft message, not to a position in
 * the text, so they survive edits and stay attached to the right section.
 *
 * A comment may be the root of a thread (no parent) or a reply (a parent that
 * is itself a root). Replies are loaded recursively through the resource so a
 * block shows one conversation, not a flat list.
 */
class LetterComment extends Model
{
    use HasFactory;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'message_id',
        'block_id',
        'parent_id',
        'user_id',
        'body',
    ];

    protected static function booted(): void
    {
        static::creating(function (LetterComment $model) {
            if (empty($model->id)) {
                $model->id = Str::uuid()->toString();
            }
        });
    }

    /**
     * The draft message this comment is attached to.
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /**
     * The author of the comment.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The root comment this one replies to, when it is a reply.
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * The replies beneath this root comment, oldest first.
     */
    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->oldest();
    }
}
