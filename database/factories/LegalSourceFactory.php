<?php

namespace Database\Factories;

use App\Enums\LegalSourceCategory;
use App\Models\LegalSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LegalSource>
 */
class LegalSourceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'LawPhil',
            'base_domain' => 'lawphil.net',
            'seed_urls' => ['https://lawphil.net/statutes/repacts/repacts.html'],
            'is_active' => true,
            'category' => LegalSourceCategory::Law,
        ];
    }
}
