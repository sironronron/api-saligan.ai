<?php

namespace App\Models;

use App\Enums\IntegrationProvider;
use App\Services\Integrations\TokenRefresher;
use Database\Factories\IntegrationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id',
    'organization_id',
    'provider',
    'status',
    'connection_scope',
    'provider_account_id',
    'account_email',
    'account_name',
    'access_token',
    'refresh_token',
    'token_expires_at',
    'granted_scopes',
    'capabilities',
    'paused_at',
    'paused_reason',
    'last_synced_at',
    'connected_at',
])]
// Tokens never leave the server: hidden from every serialization, encrypted at
// rest by the casts below.
#[Hidden(['access_token', 'refresh_token'])]
class Integration extends Model
{
    /** @use HasFactory<IntegrationFactory> */
    use HasFactory;

    use HasUuids;

    public const STATUS_CONNECTED = 'connected';

    /** The refresh token no longer works; the user must consent again. */
    public const STATUS_NEEDS_REAUTHORIZATION = 'needs_reauthorization';

    /** Plan dropped below the tiers that carry add-ons; settings are kept. */
    public const STATUS_PAUSED = 'paused';

    /** Each member connects their own account. */
    public const SCOPE_PERSONAL = 'personal';

    /** An admin connected once on behalf of the whole organization. */
    public const SCOPE_FIRM_WIDE = 'firm_wide';

    public const PAUSE_REASON_PLAN_DOWNGRADE = 'plan_downgrade';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => IntegrationProvider::class,
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'granted_scopes' => 'array',
            'capabilities' => 'array',
            'paused_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'connected_at' => 'datetime',
        ];
    }

    /**
     * The user who authorized this connection.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The organization a firm-wide connection belongs to.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * The audit trail recorded against this connection.
     */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(IntegrationAuditLog::class);
    }

    public function isConnected(): bool
    {
        return $this->status === self::STATUS_CONNECTED;
    }

    public function needsReauthorization(): bool
    {
        return $this->status === self::STATUS_NEEDS_REAUTHORIZATION;
    }

    public function isPaused(): bool
    {
        return $this->status === self::STATUS_PAUSED;
    }

    public function isFirmWide(): bool
    {
        return $this->connection_scope === self::SCOPE_FIRM_WIDE;
    }

    /**
     * The keys of the capabilities currently switched on.
     *
     * @return list<string>
     */
    public function enabledCapabilities(): array
    {
        $capabilities = $this->capabilities ?? [];

        return array_values(array_filter(
            array_keys($capabilities),
            fn (string $key): bool => ($capabilities[$key]['enabled'] ?? false) === true,
        ));
    }

    /**
     * Whether the given capability is switched on.
     */
    public function capabilityEnabled(string $capability): bool
    {
        return ($this->capabilities[$capability]['enabled'] ?? false) === true;
    }

    /**
     * The stored state of one capability, with defaults filled in. Extra keys
     * a gateway stores (a sync cursor, a webhook channel) ride along untouched.
     *
     * @return array<string, mixed>
     */
    public function capabilityState(string $capability): array
    {
        $state = $this->capabilities[$capability] ?? [];

        return $state + [
            'enabled' => false,
            'enabled_at' => null,
            'last_synced_at' => null,
            'sync_status' => 'idle',
            'last_error' => null,
        ];
    }

    /**
     * Write the state of one capability, merging over what is stored.
     *
     * @param  array<string, mixed>  $state
     */
    public function updateCapabilityState(string $capability, array $state): void
    {
        $capabilities = $this->capabilities ?? [];
        $capabilities[$capability] = array_merge($capabilities[$capability] ?? [], $state);

        $this->forceFill(['capabilities' => $capabilities])->save();
    }

    /**
     * The access token, refreshed first when it has expired or is about to.
     * Returns null when the connection has no usable token and needs
     * reauthorization.
     */
    public function freshAccessToken(TokenRefresher $refresher): ?string
    {
        if ($this->access_token === null) {
            return null;
        }

        // Refresh a minute early so a request that lands on the expiry second
        // does not fail its provider call.
        if ($this->token_expires_at !== null && $this->token_expires_at->subMinute()->isPast()) {
            if (! $refresher->refresh($this)) {
                return null;
            }
        }

        return $this->access_token;
    }
}
