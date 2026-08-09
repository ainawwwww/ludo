<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'username' => fake()->unique()->userName(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'country' => 'PK',
            'level' => 1,
            'xp' => 0,
            'is_guest' => false,
            'is_active' => true,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (User $user) {
            if (!$user->wallet) {
                Wallet::create([
                    'user_id' => $user->id,
                    'coins_balance' => 10000,
                    'diamonds_balance' => 100,
                ]);
            }
        });
    }
}
