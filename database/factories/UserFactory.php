<?php

namespace Database\Factories;

use App\Models\ArtistProfile;
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
            // Spec S2: factory users know their password. Tests for the
            // first-time-setup flow pass ['password_set_at' => null].
            'password_set_at' => now(),
            'remember_token' => Str::random(10),
            /* @chisel-2fa */
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            /* @end-chisel-2fa */
        ];
    }

    /**
     * Spec S5 (profile gate): factory users come with a complete artist
     * profile so they pass EnsureArtistProfileComplete. Tests that need an
     * incomplete/missing profile delete it (ProfileGateTest, ArtistProfileTest).
     */
    public function configure()
    {
        return $this->afterCreating(function (User $user) {
            if ($user->artistProfile()->exists()) {
                return;
            }

            ArtistProfile::create([
                'user_id' => $user->id,
                'artist_name' => $user->name,
                'real_name' => $user->name,
                'role' => 'Artist',
                'country' => 'Argentina',
                'city' => 'Buenos Aires',
                'estate' => 'CABA',
                'photo' => 'https://drive.google.com/photo.jpg',
                'bio' => 'Test artist bio.',
                'status' => 'New Artist',
                'links' => [],
            ]);
        });
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

    /* @chisel-2fa */
    /**
     * Indicate that the model has two-factor authentication configured.
     */
    public function withTwoFactor(): static
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
            'two_factor_confirmed_at' => now(),
        ]);
    }
    /* @end-chisel-2fa */
}
