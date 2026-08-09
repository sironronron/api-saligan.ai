<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'organization_id', 'org_role', 'org_status'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public const ORG_ROLE_OWNER = 'owner';

    public const ORG_ROLE_ADMIN = 'admin';

    public const ORG_ROLE_MEMBER = 'member';

    public const ORG_STATUS_ACTIVE = 'active';

    public const ORG_STATUS_INVITED = 'invited';

    public const ORG_STATUS_SUSPENDED = 'suspended';

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
     * The organization this user belongs to, if any.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Whether the user belongs to an organization.
     */
    public function hasOrganization(): bool
    {
        return $this->organization_id !== null;
    }

    /**
     * Whether the user owns their organization.
     */
    public function isOrganizationOwner(): bool
    {
        return $this->org_role === self::ORG_ROLE_OWNER;
    }

    /**
     * Whether the user can manage the organization's members, invites, and seats.
     */
    public function canManageOrganization(): bool
    {
        return in_array($this->org_role, [self::ORG_ROLE_OWNER, self::ORG_ROLE_ADMIN], true);
    }

    /**
     * Whether the user's organization membership is currently active.
     */
    public function hasActiveMembership(): bool
    {
        return $this->hasOrganization() && $this->org_status === self::ORG_STATUS_ACTIVE;
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
     * The billing subscription that governs this user's access. Subscriptions
     * belong to the organization, not the individual, so this resolves through
     * the user's organization; accounts without an organization fall back to
     * their own latest subscription row (admins and legacy rows).
     */
    protected function subscription(): Attribute
    {
        return Attribute::get(function (): ?Subscription {
            return $this->organization?->subscription
                ?? $this->subscriptions()->latest('id')->first();
        });
    }

    /**
     * All billing subscriptions created by this user as the purchaser.
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * The current plan via the organization's active subscription.
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
