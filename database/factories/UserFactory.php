<?php

namespace Database\Factories;

use App\Enums\PeranPengguna;
use App\Models\Media;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
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
            'peran' => PeranPengguna::Superadmin,
            'aktif' => true,
        ];
    }

    public function walikota(): static
    {
        return $this->state(fn () => ['peran' => PeranPengguna::Walikota]);
    }

    /** Peran media wajib punya media_id, dijaga constraint database. */
    public function media(Media|int $media): static
    {
        return $this->state(fn () => [
            'peran' => PeranPengguna::Media,
            'media_id' => $media instanceof Media ? $media->id : $media,
        ]);
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
}
