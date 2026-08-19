<?php

namespace App\Models;

use App\Enums\IntegrationProvider;
use Database\Factories\IntegrationAuditLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'organization_id',
    'integration_id',
    'provider',
    'action',
    'details',
    'ip_address',
])]
class IntegrationAuditLog extends Model
{
    /** @use HasFactory<IntegrationAuditLogFactory> */
    use HasFactory;

    use HasUuids;

    public const UPDATED_AT = null;

    public const ACTION_CONNECTED = 'connected';

    public const ACTION_REAUTHORIZED = 'reauthorized';

    public const ACTION_DISCONNECTED = 'disconnected';

    public const ACTION_CAPABILITY_ENABLED = 'capability_enabled';

    public const ACTION_CAPABILITY_DISABLED = 'capability_disabled';

    public const ACTION_SCOPES_GRANTED = 'scopes_granted';

    public const ACTION_SCOPES_REVOKED = 'scopes_revoked';

    public const ACTION_PAUSED_PLAN_DOWNGRADE = 'paused_plan_downgrade';

    public const ACTION_RESUMED_PLAN_UPGRADE = 'resumed_plan_upgrade';

    public const ACTION_TOKEN_REFRESH_FAILED = 'token_refresh_failed';

    public const ACTION_SYNC_FAILED = 'sync_failed';

    public const ACTION_POLICY_UPDATED = 'policy_updated';

    public const ACTION_CONNECTION_MODE_CHANGED = 'connection_mode_changed';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => IntegrationProvider::class,
            'details' => 'array',
        ];
    }

    /**
     * The connection this entry describes, if it still exists.
     */
    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    /**
     * The user who performed the action, if the account still exists. The
     * foreign key is deliberately unenforced so the trail survives deletion.
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
