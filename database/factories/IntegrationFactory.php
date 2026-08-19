<?php

namespace Database\Factories;

use App\Enums\IntegrationProvider;
use App\Models\Integration;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Integration>
 */
class IntegrationFactory extends Factory
{
    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'organization_id' => null,
            'provider' => IntegrationProvider::GoogleWorkspace,
            'status' => Integration::STATUS_CONNECTED,
            'connection_scope' => Integration::SCOPE_PERSONAL,
            'provider_account_id' => (string) fake()->randomNumber(9),
            'account_email' => fake()->safeEmail(),
            'account_name' => fake()->name(),
            'access_token' => 'access-'.fake()->sha256(),
            'refresh_token' => 'refresh-'.fake()->sha256(),
            'token_expires_at' => now()->addHour(),
            'granted_scopes' => ['openid', 'email', 'profile'],
            'capabilities' => [],
            'paused_at' => null,
            'paused_reason' => null,
            'last_synced_at' => null,
            'connected_at' => now(),
        ];
    }

    /**
     * A SharePoint connection.
     */
    public function sharepoint(): static
    {
        return $this->state(fn (array $attributes) => [
            'provider' => IntegrationProvider::SharePoint,
        ]);
    }

    /**
     * A connection whose refresh token no longer works.
     */
    public function needsReauthorization(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Integration::STATUS_NEEDS_REAUTHORIZATION,
        ]);
    }

    /**
     * A connection paused by a plan downgrade.
     */
    public function paused(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Integration::STATUS_PAUSED,
            'paused_at' => now(),
            'paused_reason' => Integration::PAUSE_REASON_PLAN_DOWNGRADE,
        ]);
    }

    /**
     * A firm-wide connection made by an admin for the organization.
     */
    public function firmWide(): static
    {
        return $this->state(fn (array $attributes) => [
            'connection_scope' => Integration::SCOPE_FIRM_WIDE,
        ]);
    }

    /**
     * A connection with the given capabilities switched on.
     *
     * @param  list<string>  $capabilities
     */
    public function withCapabilities(array $capabilities): static
    {
        return $this->state(fn (array $attributes) => [
            'capabilities' => array_merge(
                $attributes['capabilities'] ?? [],
                collect($capabilities)->mapWithKeys(
                    fn (string $capability): array => [$capability => [
                        'enabled' => true,
                        'enabled_at' => now()->toIso8601String(),
                        'last_synced_at' => null,
                        'sync_status' => 'idle',
                        'last_error' => null,
                    ]],
                )->all(),
            ),
        ]);
    }
}
