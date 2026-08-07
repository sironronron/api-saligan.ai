<?php

namespace App\Models;

use Database\Factories\UsageCounterFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'period_key',
    'messages_used',
    'messages_overage',
    'documents_uploaded',
])]
class UsageCounter extends Model
{
    /** @use HasFactory<UsageCounterFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'messages_used' => 'integer',
            'messages_overage' => 'integer',
            'documents_uploaded' => 'integer',
        ];
    }

    /**
     * The user this usage counter belongs to.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The key of the current billing period.
     */
    public static function currentPeriodKey(): string
    {
        return now()->format('Y-m');
    }
}
