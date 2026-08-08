<?php

namespace Database\Factories;

use App\Models\Salon;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class SalonFactory extends Factory
{
    protected $model = Salon::class;

    public function definition(): array
    {
        $name = fake()->company();

        return [
            'manager_id'  => User::factory(),
            'name'        => $name,
            'slug'        => Str::slug($name) . '-' . fake()->unique()->numberBetween(1, 9999),
            'description' => fake()->paragraph(),
            'address'     => fake()->streetAddress(),
            'city'        => fake()->city(),
            'phone'       => fake()->phoneNumber(),
            'email'       => fake()->email(),
            'rating'      => fake()->randomFloat(2, 1, 5),
        ];
    }

    public function verified(): static
    {
        return $this->afterCreating(fn (Salon $salon) => $salon->verify());
    }
}
