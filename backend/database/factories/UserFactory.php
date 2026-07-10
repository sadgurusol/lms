<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'status' => 'active',
            'kind' => User::KIND_LOCAL,
            'locale' => 'en',
            'remember_token' => Str::random(10),
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * A user provisioned by a B2B launch: no email, no password, no way in
     * except through the client's SIS.
     */
    public function clientProvisioned(): static
    {
        return $this->state(fn (array $attributes) => [
            'kind' => User::KIND_CLIENT_PROVISIONED,
            'email' => null,
            'email_verified_at' => null,
            'password' => null,
        ]);
    }

    public function withRole(string $role): static
    {
        return $this->afterCreating(fn (User $user) => $user->assignRole($role));
    }
}
