<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'is_admin' => false,
        ];
    }

    /**
     * Indicate that the user is an administrator.
     *
     * is_admin is intentionally not fillable, so the flag is set on the
     * instance after it is made rather than through mass assignment.
     */
    public function admin(): static
    {
        return $this->afterMaking(fn (User $user) => $user->forceFill(['is_admin' => true]));
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Indicate that the user is an active member of the given organization.
     */
    public function memberOf(Organization $organization, string $role = User::ORG_ROLE_MEMBER, string $status = User::ORG_STATUS_ACTIVE): static
    {
        return $this->state(fn (array $attributes) => [
            'organization_id' => $organization->id,
            'org_role' => $role,
            'org_status' => $status,
        ]);
    }

    /**
     * Indicate that the user owns the given organization.
     */
    public function ownerOf(Organization $organization): static
    {
        return $this->memberOf($organization, User::ORG_ROLE_OWNER);
    }
}
