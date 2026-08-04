<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Todo extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'conversation_id',
        'title',
        'status',
        'priority',
        'due_hint',
    ];

    /**
     * Cap the title to the column limit so long AI-generated steps never
     * truncate the insert.
     */
    public function setTitleAttribute(string $value): void
    {
        $this->attributes['title'] = mb_strimwidth($value, 0, 255, '…');
    }

    /**
     * Get the conversation that owns the todo.
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
