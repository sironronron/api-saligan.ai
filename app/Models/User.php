<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'is_admin'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
        ];
    }

    /**
     * The documents uploaded by this user.
     */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    /**
     * The conversations started by this user.
     */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    /**
     * The cases owned by this user.
     */
    public function cases(): HasMany
    {
        return $this->hasMany(LegalCase::class);
    }

    /**
     * The custom letter templates saved by this user.
     */
    public function templates(): HasMany
    {
        return $this->hasMany(Template::class);
    }

    /**
     * The todos across all conversations.
     */
    public function todos(): HasManyThrough
    {
        return $this->hasManyThrough(Todo::class, Conversation::class);
    }

    /**
     * The user's billing subscription.
     */
    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class)->latestOfMany();
    }

    /**
     * All billing subscriptions for this user.
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * The current plan via the active subscription.
     */
    public function plan(): ?Plan
    {
        return $this->subscription?->plan;
    }

    /**
     * The usage counter for the current billing period, created on demand.
     */
    public function usageCounterForCurrentPeriod(): UsageCounter
    {
        return $this->usageCounters()->firstOrCreate(
            ['period_key' => UsageCounter::currentPeriodKey()],
            ['messages_used' => 0, 'messages_overage' => 0, 'documents_uploaded' => 0],
        );
    }

    /**
     * The monthly usage counters for this user.
     */
    public function usageCounters(): HasMany
    {
        return $this->hasMany(UsageCounter::class);
    }
}
