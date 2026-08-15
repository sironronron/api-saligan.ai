<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A caveat, gap, assumption, or deadline the assistant flagged on a turn —
 * the "things you might have missed" the user can answer one by one.
 */
class Advisory extends Model
{
    use HasFactory, HasUuids;

    /** The dispositions a user can give an advisory. */
    public const STATUSES = ['open', 'tracked', 'not_a_problem', 'will_check', 'mitigated'];

    public const KINDS = ['caveat', 'gap', 'risk', 'assumption', 'deadline'];

    public const SEVERITIES = ['low', 'medium', 'high'];

    protected $fillable = [
        'conversation_id',
        'message_id',
        'kind',
        'title',
        'detail',
        'severity',
        'status',
        'note',
        'todo_id',
        'responded_at',
        'order',
    ];

    /**
     * Cap the title to the column limit so a long flag never truncates the
     * insert. Mirrors Todo::setTitleAttribute.
     */
    public function setTitleAttribute(string $value): void
    {
        $this->attributes['title'] = mb_strimwidth($value, 0, 255, '…');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'responded_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function todo(): BelongsTo
    {
        return $this->belongsTo(Todo::class);
    }
}
