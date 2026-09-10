<?php

namespace App\Models;

use Database\Factories\AiUsageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'id',
    'organization_id',
    'user_id',
    'subscription_id',
    'subscription_window_id',
    'conversation_id',
    'document_id',
    'operation',
    'engine',
    'provider',
    'model',
    'input_tokens',
    'output_tokens',
    'cache_read_tokens',
    'cache_write_tokens',
    'image_pages',
    'grounding_queries',
    'attempts',
    'status',
    'cost_usd',
    'rate_version',
    'idempotency_key',
    'latency_ms',
])]
class AiUsage extends Model
{
    /** @use HasFactory<AiUsageFactory> */
    use HasFactory;

    use HasUuids;

    public const OPERATION_CHAT = 'chat';

    public const OPERATION_REWRITE = 'rewrite';

    public const OPERATION_RESEARCH = 'research';

    public const OPERATION_LETTER = 'letter';

    public const OPERATION_INGEST = 'ingest';

    public const OPERATION_DIGEST = 'digest';

    public const OPERATION_CLASSIFY = 'classify';

    public const STATUS_RESERVED = 'reserved';

    public const STATUS_SETTLED = 'settled';

    public const STATUS_RELEASED = 'released';

    public const STATUS_FAILED = 'failed';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cache_read_tokens' => 'integer',
            'cache_write_tokens' => 'integer',
            'image_pages' => 'integer',
            'grounding_queries' => 'integer',
            'attempts' => 'integer',
            'cost_usd' => 'float',
            'latency_ms' => 'integer',
        ];
    }

    /**
     * Whether this row counts against the window's spend: reservations still
     * open plus everything already settled. Released and failed rows bill
     * nothing — they exist so a turn's history stays auditable.
     */
    public function countsAgainstBudget(): bool
    {
        return in_array($this->status, [self::STATUS_RESERVED, self::STATUS_SETTLED], true);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function window(): BelongsTo
    {
        return $this->belongsTo(SubscriptionWindow::class, 'subscription_window_id');
    }
}
