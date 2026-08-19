<?php

namespace Database\Factories;

use App\Enums\IntegrationProvider;
use App\Models\IntegrationAuditLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IntegrationAuditLog>
 */
class IntegrationAuditLogFactory extends Factory
{
    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'user_id' => null,
            'organization_id' => null,
            'integration_id' => null,
            'provider' => IntegrationProvider::GoogleWorkspace,
            'action' => IntegrationAuditLog::ACTION_CONNECTED,
            'details' => [],
            'ip_address' => fake()->ipv4(),
        ];
    }
}
