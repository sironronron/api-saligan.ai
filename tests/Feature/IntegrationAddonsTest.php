<?php

use App\Enums\IntegrationProvider;
use App\Models\Integration;
use App\Models\IntegrationAuditLog;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\IntegrationReauthorizationRequired;
use App\Services\Integrations\IntegrationEligibility;
use App\Services\Integrations\IntegrationManager;
use App\Services\Integrations\IntegrationSyncService;
use App\Services\Integrations\OAuthStateStore;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

function googleTokenFake(array $overrides = []): void
{
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response($overrides + [
            'access_token' => 'new-access-token',
            'refresh_token' => 'new-refresh-token',
            'expires_in' => 3600,
            'scope' => 'openid https://www.googleapis.com/auth/userinfo.email https://www.googleapis.com/auth/userinfo.profile',
        ]),
        'openidconnect.googleapis.com/*' => Http::response([
            'sub' => 'google-account-123',
            'email' => 'lawyer@gmail.com',
            'name' => 'Juan Lawyer',
        ]),
        'oauth2.googleapis.com/revoke' => Http::response([], 200),
    ]);
}

function stateFrom(string $authorizeUrl): string
{
    parse_str((string) parse_url($authorizeUrl, PHP_URL_QUERY), $query);

    return (string) $query['state'];
}

beforeEach(function () {
    Notification::fake();

    $this->organization = Organization::factory()->create();
    $this->owner = User::factory()->ownerOf($this->organization)->create();

    $this->pro = Plan::factory()->pro()->create();

    Subscription::factory()->for($this->organization)->for($this->owner)->create([
        'plan_id' => $this->pro->id,
    ]);
});

// ---------------------------------------------------------------------------
// Catalogue visibility
// ---------------------------------------------------------------------------

it('shows the full add-on catalogue to a user on any plan', function () {
    $standard = Plan::factory()->standard()->create();
    $organization = Organization::factory()->create();
    $user = User::factory()->ownerOf($organization)->create();

    Subscription::factory()->for($organization)->for($user)->create(['plan_id' => $standard->id]);

    $response = $this->signInAs($user)
        ->getJson('/api/integrations')
        ->assertOk();

    expect($response->json('data.eligible'))->toBeFalse()
        ->and($response->json('data.upgrade_message'))->toContain('Pro')
        ->and(collect($response->json('data.providers'))->pluck('provider')->all())
        ->toBe(['google_workspace', 'sharepoint']);

    // The locked card still lists every capability so the user sees what
    // they are missing.
    $google = collect($response->json('data.providers'))->firstWhere('provider', 'google_workspace');

    expect(collect($google['capabilities'])->pluck('key')->all())->toBe([
        'drive_import', 'drive_export', 'calendar_sync', 'gmail', 'docs_import',
    ]);
});

it('marks the catalogue eligible for a Pro user', function () {
    $response = $this->signInAs($this->owner)
        ->getJson('/api/integrations')
        ->assertOk();

    expect($response->json('data.eligible'))->toBeTrue()
        ->and($response->json('data.upgrade_message'))->toBeNull();
});

// ---------------------------------------------------------------------------
// Plan gating
// ---------------------------------------------------------------------------

it('refuses to start a connection below the add-on tiers', function () {
    $standard = Plan::factory()->standard()->create();
    $organization = Organization::factory()->create();
    $user = User::factory()->ownerOf($organization)->create();

    Subscription::factory()->for($organization)->for($user)->create(['plan_id' => $standard->id]);

    $this->signInAs($user)
        ->postJson('/api/integrations/google_workspace/connect')
        ->assertStatus(402)
        ->assertJsonPath('upgrade_required', true);
});

it('refuses toggles and syncs below the add-on tiers', function () {
    $standard = Plan::factory()->standard()->create();
    $organization = Organization::factory()->create();
    $user = User::factory()->ownerOf($organization)->create();

    Subscription::factory()->for($organization)->for($user)->create(['plan_id' => $standard->id]);

    Integration::factory()->for($user)->create();

    $this->signInAs($user)
        ->postJson('/api/integrations/google_workspace/capabilities/drive_import', ['enabled' => true])
        ->assertStatus(402);

    $this->signInAs($user)
        ->postJson('/api/integrations/google_workspace/sync')
        ->assertStatus(402);
});

it('lets Pro, Firm, and Business plans start a connection', function () {
    config()->set('integrations.google.client_id', 'google-client-id');

    Http::fake();

    $response = $this->signInAs($this->owner)
        ->postJson('/api/integrations/google_workspace/connect')
        ->assertOk();

    $authorizeUrl = $response->json('data.authorize_url');

    expect($authorizeUrl)->toContain('accounts.google.com')
        ->and($authorizeUrl)->toContain('client_id=google-client-id')
        ->and($authorizeUrl)->toContain('openid')
        // A bare connection asks for identity only — no data scopes yet.
        ->and($authorizeUrl)->not->toContain('drive.readonly')
        ->and($response->json('data.privacy_summary'))->not->toBeEmpty();
});

// ---------------------------------------------------------------------------
// Connection flow
// ---------------------------------------------------------------------------

it('completes the OAuth round-trip and stores encrypted tokens', function () {
    config()->set('integrations.google.client_id', 'google-client-id');
    googleTokenFake();

    $connect = $this->signInAs($this->owner)
        ->postJson('/api/integrations/google_workspace/connect')
        ->assertOk();

    $state = stateFrom($connect->json('data.authorize_url'));

    // The callback carries no bearer token — the state is the proof.
    $this->get('/api/integrations/callback?code=consent-code&state='.urlencode($state))
        ->assertRedirectContains('/settings/addons?integration_status=success');

    $integration = Integration::query()->where('user_id', $this->owner->id)->firstOrFail();

    expect($integration->provider)->toBe(IntegrationProvider::GoogleWorkspace)
        ->and($integration->status)->toBe(Integration::STATUS_CONNECTED)
        ->and($integration->account_email)->toBe('lawyer@gmail.com')
        ->and($integration->access_token)->toBe('new-access-token')
        ->and($integration->refresh_token)->toBe('new-refresh-token');

    // Encrypted at rest: the raw column must not hold the plain token.
    $raw = Integration::query()->whereKey($integration->id)
        ->toBase()->first();

    expect($raw->access_token)->not->toContain('new-access-token');

    expect(IntegrationAuditLog::query()
        ->where('integration_id', $integration->id)
        ->where('action', IntegrationAuditLog::ACTION_CONNECTED)
        ->exists())->toBeTrue();
});

it('rejects a callback with a tampered state', function () {
    googleTokenFake();

    $this->get('/api/integrations/callback?code=consent-code&state=forged-state')
        ->assertRedirectContains('integration_status=error');

    expect(Integration::query()->count())->toBe(0);
});

it('rejects an expired state', function () {
    googleTokenFake();

    $store = app(OAuthStateStore::class);
    $state = $store->issue($this->owner, IntegrationProvider::GoogleWorkspace, OAuthStateStore::PURPOSE_CONNECT);

    $this->travel(11)->minutes();

    $this->get('/api/integrations/callback?code=consent-code&state='.urlencode($state))
        ->assertRedirectContains('integration_status=error');
});

it('surfaces a denied consent as a denied return', function () {
    $this->get('/api/integrations/callback?error=access_denied')
        ->assertRedirectContains('integration_status=denied');
});

// ---------------------------------------------------------------------------
// Capability toggles
// ---------------------------------------------------------------------------

it('enables a capability whose scopes are already granted', function () {
    $integration = Integration::factory()->for($this->owner)->create([
        'granted_scopes' => ['openid', 'https://www.googleapis.com/auth/userinfo.email', 'https://www.googleapis.com/auth/userinfo.profile', 'https://www.googleapis.com/auth/drive.readonly'],
    ]);

    $this->signInAs($this->owner)
        ->postJson('/api/integrations/google_workspace/capabilities/drive_import', ['enabled' => true])
        ->assertOk()
        ->assertJsonPath('data.enabled', true)
        ->assertJsonPath('data.authorization_required', false);

    expect($integration->fresh()->capabilityEnabled('drive_import'))->toBeTrue();
});

it('asks for incremental consent when a capability needs new scopes', function () {
    config()->set('integrations.google.client_id', 'google-client-id');

    Integration::factory()->for($this->owner)->create([
        'granted_scopes' => ['openid', 'https://www.googleapis.com/auth/userinfo.email', 'https://www.googleapis.com/auth/userinfo.profile'],
    ]);

    $response = $this->signInAs($this->owner)
        ->postJson('/api/integrations/google_workspace/capabilities/drive_import', ['enabled' => true])
        ->assertOk();

    expect($response->json('data.authorization_required'))->toBeTrue()
        ->and($response->json('data.enabled'))->toBeFalse()
        ->and($response->json('data.authorize_url'))->toContain('drive.readonly');
});

it('enables the capability when the incremental consent round-trip completes', function () {
    googleTokenFake([
        'scope' => 'openid https://www.googleapis.com/auth/userinfo.email https://www.googleapis.com/auth/userinfo.profile https://www.googleapis.com/auth/drive.readonly',
    ]);

    Integration::factory()->for($this->owner)->create([
        'granted_scopes' => ['openid', 'https://www.googleapis.com/auth/userinfo.email', 'https://www.googleapis.com/auth/userinfo.profile'],
    ]);

    $toggle = $this->signInAs($this->owner)
        ->postJson('/api/integrations/google_workspace/capabilities/drive_import', ['enabled' => true])
        ->assertOk();

    $state = stateFrom($toggle->json('data.authorize_url'));

    $this->get('/api/integrations/callback?code=consent-code&state='.urlencode($state))
        ->assertRedirectContains('integration_status=success');

    $integration = Integration::query()->where('user_id', $this->owner->id)->firstOrFail();

    expect($integration->capabilityEnabled('drive_import'))->toBeTrue()
        ->and($integration->granted_scopes)
        ->toContain('https://www.googleapis.com/auth/drive.readonly');
});

it('narrows the grant when a capability is switched off', function () {
    Integration::factory()->for($this->owner)->create([
        'granted_scopes' => [
            'openid',
            'https://www.googleapis.com/auth/userinfo.email',
            'https://www.googleapis.com/auth/userinfo.profile',
            'https://www.googleapis.com/auth/drive.readonly',
        ],
    ])->updateCapabilityState('drive_import', ['enabled' => true]);

    $response = $this->signInAs($this->owner)
        ->postJson('/api/integrations/google_workspace/capabilities/drive_import', ['enabled' => false])
        ->assertOk();

    $integration = Integration::query()->where('user_id', $this->owner->id)->firstOrFail();

    expect($integration->capabilityEnabled('drive_import'))->toBeFalse()
        // The orphaned scope prompts a narrowing consent.
        ->and($response->json('data.authorization_required'))->toBeTrue()
        ->and($response->json('data.authorize_url'))->not->toContain('drive.readonly');
});

it('refuses a toggle for an unknown capability', function () {
    Integration::factory()->for($this->owner)->create();

    $this->signInAs($this->owner)
        ->postJson('/api/integrations/google_workspace/capabilities/teleportation', ['enabled' => true])
        ->assertStatus(422);
});

// ---------------------------------------------------------------------------
// Sync
// ---------------------------------------------------------------------------

it('syncs now and records the outcome per capability', function () {
    Http::fake([
        'www.googleapis.com/drive/v3/changes/startPageToken' => Http::response(['startPageToken' => 'page-1']),
        'www.googleapis.com/drive/v3/changes*' => Http::response(['changes' => [], 'newStartPageToken' => 'page-2']),
        'www.googleapis.com/calendar/v3/*' => Http::response(['items' => [['id' => 'evt-1']]]),
        'gmail.googleapis.com/*' => Http::response(['messages' => []]),
        'openidconnect.googleapis.com/*' => Http::response(['sub' => 'x']),
        'oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'fresh-token', 'refresh_token' => 'new-refresh-token', 'expires_in' => 3600,
        ]),
    ]);

    $integration = Integration::factory()->for($this->owner)
        ->withCapabilities(['drive_import', 'calendar_sync'])
        ->create(['token_expires_at' => now()->addHour()]);

    $response = $this->signInAs($this->owner)
        ->postJson('/api/integrations/google_workspace/sync')
        ->assertOk();

    expect($response->json('data.results.drive_import.ok'))->toBeTrue()
        ->and($response->json('data.results.calendar_sync.ok'))->toBeTrue();

    $fresh = $integration->fresh();

    expect($fresh->capabilityState('drive_import')['sync_status'])->toBe('idle')
        ->and($fresh->capabilityState('drive_import')['last_synced_at'])->not->toBeNull()
        ->and($fresh->last_synced_at)->not->toBeNull();
});

it('surfaces a revoked grant as an actionable sync error', function () {
    Http::fake([
        'www.googleapis.com/drive/v3/*' => Http::response(['error' => 'invalid_grant'], 401),
        'oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400),
    ]);

    $integration = Integration::factory()->for($this->owner)
        ->withCapabilities(['drive_import'])
        ->create(['token_expires_at' => now()->addHour()]);

    $this->signInAs($this->owner)
        ->postJson('/api/integrations/google_workspace/sync')
        ->assertOk()
        ->assertJsonPath('data.results.drive_import.ok', false);

    $fresh = $integration->fresh();

    expect($fresh->capabilityState('drive_import')['sync_status'])->toBe('error')
        ->and($fresh->capabilityState('drive_import')['last_error'])->toContain('reauthorize')
        ->and($fresh->status)->toBe(Integration::STATUS_NEEDS_REAUTHORIZATION);

    Notification::assertSentTo($this->owner, IntegrationReauthorizationRequired::class);
});

// ---------------------------------------------------------------------------
// Disconnect
// ---------------------------------------------------------------------------

it('disconnects, revokes with the provider, and keeps the audit row', function () {
    googleTokenFake();

    $integration = Integration::factory()->for($this->owner)->create();

    $this->signInAs($this->owner)
        ->deleteJson('/api/integrations/google_workspace')
        ->assertOk();

    expect(Integration::query()->count())->toBe(0)
        ->and(IntegrationAuditLog::query()
            ->where('action', IntegrationAuditLog::ACTION_DISCONNECTED)
            ->where('integration_id', $integration->id)
            ->exists())->toBeTrue();

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'oauth2.googleapis.com/revoke');
    });
});

it('lets a downgraded user disconnect even without an eligible plan', function () {
    googleTokenFake();

    $integration = Integration::factory()->for($this->owner)->paused()->create();

    // Cancel the subscription so the plan check would fail for anything else.
    $this->organization->subscription->update(['status' => 'cancelled']);

    $this->signInAs($this->owner)
        ->deleteJson('/api/integrations/google_workspace')
        ->assertOk();

    expect(Integration::query()->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Downgrade / upgrade
// ---------------------------------------------------------------------------

it('pauses integrations when the subscription is cancelled and resumes on re-upgrade', function () {
    $integration = Integration::factory()->for($this->owner)
        ->withCapabilities(['drive_import'])
        ->create();

    $this->signInAs($this->owner)
        ->postJson('/api/subscription/cancel')
        ->assertOk();

    $fresh = $integration->fresh();

    expect($fresh->status)->toBe(Integration::STATUS_PAUSED)
        ->and($fresh->paused_reason)->toBe(Integration::PAUSE_REASON_PLAN_DOWNGRADE)
        // Settings survive the pause.
        ->and($fresh->capabilityEnabled('drive_import'))->toBeTrue();

    // A paused integration does not sync.
    $results = app(IntegrationSyncService::class)
        ->syncAll($fresh);

    expect($results)->toBe([]);

    // Upgrade again: the sweep wakes the connection back up.
    Subscription::factory()->for($this->organization)->for($this->owner)->create([
        'plan_id' => $this->pro->id,
    ]);

    app(IntegrationEligibility::class)->sweep();

    expect($integration->fresh()->status)->toBe(Integration::STATUS_CONNECTED);
});

it('sweeps pause and resume across accounts', function () {
    $integration = Integration::factory()->for($this->owner)->create();

    // Drop the plan underneath the user.
    $standard = Plan::factory()->standard()->create();
    $this->organization->subscription->update(['plan_id' => $standard->id]);

    $result = app(IntegrationEligibility::class)->sweep();

    expect($result['paused'])->toBe(1)
        ->and($integration->fresh()->status)->toBe(Integration::STATUS_PAUSED);

    // Back on Pro.
    $this->organization->subscription->update(['plan_id' => $this->pro->id]);

    $result = app(IntegrationEligibility::class)->sweep();

    expect($result['resumed'])->toBe(1)
        ->and($integration->fresh()->status)->toBe(Integration::STATUS_CONNECTED);
});

// ---------------------------------------------------------------------------
// Firm admin controls
// ---------------------------------------------------------------------------

it('refuses the firm management view to non-managers', function () {
    $member = User::factory()->memberOf($this->organization)->create();

    $this->signInAs($member)
        ->getJson('/api/organizations/integrations')
        ->assertStatus(403);
});

it('shows the firm management view to an admin', function () {
    Integration::factory()->for($this->owner)
        ->withCapabilities(['drive_import'])
        ->create();

    $response = $this->signInAs($this->owner)
        ->getJson('/api/organizations/integrations')
        ->assertOk();

    expect($response->json('data.connection_mode'))->toBe('per_seat')
        ->and($response->json('data.connections.members'))->toHaveCount(1)
        ->and($response->json('data.connections.members.0.connections.0.provider'))->toBe('google_workspace');
});

it('enforces org-wide capability policies on toggles', function () {
    $this->organization->forceFill([
        'integration_capability_policies' => ['gmail' => 'forced_off', 'drive_import' => 'forced_on'],
    ])->save();

    Integration::factory()->for($this->owner)->create([
        'granted_scopes' => ['openid', 'https://www.googleapis.com/auth/userinfo.email', 'https://www.googleapis.com/auth/userinfo.profile'],
    ]);

    $this->signInAs($this->owner)
        ->postJson('/api/integrations/google_workspace/capabilities/gmail', ['enabled' => true])
        ->assertStatus(422);

    $this->signInAs($this->owner)
        ->postJson('/api/integrations/google_workspace/capabilities/drive_import', ['enabled' => false])
        ->assertStatus(422);
});

it('runs forced-on capabilities even when the member never enabled them', function () {
    $this->organization->forceFill([
        'integration_capability_policies' => ['drive_import' => 'forced_on'],
    ])->save();

    $integration = Integration::factory()->for($this->owner)->create();

    expect(app(IntegrationManager::class)
        ->isEffectivelyEnabled($integration, 'drive_import'))->toBeTrue();
});

it('updates policies and connection mode and audits both', function () {
    $this->signInAs($this->owner)
        ->putJson('/api/organizations/integrations/policies', [
            'policies' => ['gmail' => 'forced_off'],
        ])
        ->assertOk()
        ->assertJsonPath('data.policies.gmail', 'forced_off');

    $this->signInAs($this->owner)
        ->putJson('/api/organizations/integrations/connection-mode', ['mode' => 'firm_wide'])
        ->assertOk()
        ->assertJsonPath('data.connection_mode', 'firm_wide');

    expect(IntegrationAuditLog::query()->where('action', IntegrationAuditLog::ACTION_POLICY_UPDATED)->exists())->toBeTrue()
        ->and(IntegrationAuditLog::query()->where('action', IntegrationAuditLog::ACTION_CONNECTION_MODE_CHANGED)->exists())->toBeTrue();

    $this->signInAs($this->owner)
        ->getJson('/api/organizations/integrations/audit-logs')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

// ---------------------------------------------------------------------------
// Firm-wide connections
// ---------------------------------------------------------------------------

it('lets only managers connect when the firm runs firm-wide', function () {
    $this->organization->forceFill(['integrations_connection_mode' => 'firm_wide'])->save();

    $member = User::factory()->memberOf($this->organization)->create();

    $this->signInAs($member)
        ->postJson('/api/integrations/google_workspace/connect')
        ->assertStatus(403);

    config()->set('integrations.google.client_id', 'google-client-id');

    $this->signInAs($this->owner)
        ->postJson('/api/integrations/google_workspace/connect')
        ->assertOk();
});

it('makes a firm-wide connection visible to members but manageable only by admins', function () {
    googleTokenFake();

    $this->organization->forceFill(['integrations_connection_mode' => 'firm_wide'])->save();

    $connect = $this->signInAs($this->owner)
        ->postJson('/api/integrations/google_workspace/connect')
        ->assertOk();

    $state = stateFrom($connect->json('data.authorize_url'));

    $this->get('/api/integrations/callback?code=consent-code&state='.urlencode($state))
        ->assertRedirectContains('integration_status=success');

    $integration = Integration::query()->firstOrFail();

    expect($integration->connection_scope)->toBe(Integration::SCOPE_FIRM_WIDE)
        ->and($integration->organization_id)->toBe($this->organization->id);

    // A member sees the firm connection on the add-ons page.
    $member = User::factory()->memberOf($this->organization)->create();

    $page = $this->signInAs($member)
        ->getJson('/api/integrations')
        ->assertOk();

    $google = collect($page->json('data.providers'))->firstWhere('provider', 'google_workspace');

    expect($google['connection'])->not->toBeNull()
        ->and($google['connection']['connection_scope'])->toBe('firm_wide')
        ->and($google['connection']['can_manage'])->toBeFalse();

    // But cannot toggle it.
    $this->signInAs($member)
        ->postJson('/api/integrations/google_workspace/capabilities/drive_import', ['enabled' => true])
        ->assertStatus(403);
});

// ---------------------------------------------------------------------------
// Reauthorization
// ---------------------------------------------------------------------------

it('offers a reauthorization url that re-grants only the enabled capabilities', function () {
    config()->set('integrations.google.client_id', 'google-client-id');

    $integration = Integration::factory()->for($this->owner)
        ->needsReauthorization()
        ->withCapabilities(['calendar_sync'])
        ->create();

    $response = $this->signInAs($this->owner)
        ->postJson('/api/integrations/google_workspace/reauthorize')
        ->assertOk();

    expect($response->json('data.authorize_url'))->toContain('calendar.readonly')
        ->and($response->json('data.authorize_url'))->not->toContain('drive.readonly');
});

it('heals a needs-reauthorization connection through the consent round-trip', function () {
    googleTokenFake();

    $integration = Integration::factory()->for($this->owner)
        ->needsReauthorization()
        ->withCapabilities(['drive_import'])
        ->create();

    $reauth = $this->signInAs($this->owner)
        ->postJson('/api/integrations/google_workspace/reauthorize')
        ->assertOk();

    $state = stateFrom($reauth->json('data.authorize_url'));

    $this->get('/api/integrations/callback?code=consent-code&state='.urlencode($state))
        ->assertRedirectContains('integration_status=success');

    expect($integration->fresh()->status)->toBe(Integration::STATUS_CONNECTED);
});
