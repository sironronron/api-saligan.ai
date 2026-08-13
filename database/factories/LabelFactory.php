<?php

namespace Database\Factories;

use App\Enums\LabelKind;
use App\Models\Label;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Label>
 */
class LabelFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'kind' => LabelKind::DocumentCategory,
            'organization_id' => null,
            'user_id' => null,
            'slug' => Str::slug($name),
            'name' => Str::title($name),
            'description' => fake()->sentence(),
            'group' => null,
            'color' => null,
            'position' => 0,
        ];
    }

    /**
     * A thread tag rather than a document category.
     */
    public function threadTag(): static
    {
        return $this->state(fn (array $attributes) => [
            'kind' => LabelKind::ThreadTag,
        ]);
    }

    /**
     * A custom label shared across an organization.
     */
    public function forOrganization(Organization $organization, ?User $creator = null): static
    {
        return $this->state(fn (array $attributes) => [
            'organization_id' => $organization->id,
            'user_id' => $creator?->id,
        ]);
    }

    /**
     * A personal custom label, for a user who belongs to no organization.
     */
    public function forUser(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'organization_id' => null,
            'user_id' => $user->id,
        ]);
    }
}
