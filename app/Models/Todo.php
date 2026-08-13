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
        'description',
        'status',
        'priority',
        'due_hint',
        'due_date',
        'deadline_reminded_at',
        'deadline_reminded_due_date',
        'assignee',
        'order',
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
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'deadline_reminded_at' => 'datetime',
            'deadline_reminded_due_date' => 'date',
        ];
    }

    /**
     * Get the conversation that owns the todo.
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
