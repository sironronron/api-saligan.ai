<?php

namespace Database\Factories;

use App\Models\Template;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Template>
 */
class TemplateFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     */
    protected $model = Template::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true),
            'category' => 'formal',
            'jurisdiction' => 'PH',
            'structure' => ['Header', 'Date', 'Recipient', 'Subject', 'Body', 'Closing', 'Signature'],
            'placeholder_fields' => ['recipient_name', 'date', 'case_reference'],
            'content' => null,
        ];
    }

    /**
     * A system (shared) template with no owning user.
     */
    public function system(): static
    {
        return $this->state(fn () => ['user_id' => null]);
    }

    /**
     * A Philippine legal correspondence template.
     */
    public function legal(): static
    {
        return $this->state(fn () => [
            'name' => 'Demand Letter',
            'category' => 'legal',
            'jurisdiction' => 'PH',
            'legal_subtype' => 'demand_letter',
        ]);
    }
}
