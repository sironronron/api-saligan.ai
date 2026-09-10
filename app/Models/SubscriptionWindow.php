<?php

namespace App\Models;

use Database\Factories\SubscriptionWindowFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'subscription_id',
    'window_start',
    'window_end',
    'budget_usd',
    'used_usd',
    'warned_80_at',
])]
class SubscriptionWindow extends Model
{
    /** @use HasFactory<SubscriptionWindowFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'window_start' => 'datetime',
            'window_end' => 'datetime',
            'budget_usd' => 'float',
            'used_usd' => 'float',
            'warned_80_at' => 'datetime',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function usages(): HasMany
    {
        return $this->hasMany(AiUsage::class, 'subscription_window_id');
    }

    /**
     * Share of the budget spent, uncapped so overshoot stays visible instead
     * of rendering as exactly full.
     */
    public function percentUsed(): float
    {
        if ($this->budget_usd <= 0) {
            return 0.0;
        }

        return $this->used_usd / $this->budget_usd * 100;
    }

    public function isExhausted(): bool
    {
        return $this->budget_usd > 0 && $this->used_usd >= $this->budget_usd;
    }

    public function isWarning(): bool
    {
        return ! $this->isExhausted() && $this->percentUsed() >= 80.0;
    }
}
