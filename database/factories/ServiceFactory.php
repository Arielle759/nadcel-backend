<?php

namespace Database\Factories;

use App\Models\Salon;
use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

class ServiceFactory extends Factory
{
    protected $model = Service::class;

    public function definition(): array
    {
        return [
            'salon_id'    => Salon::factory(),
            'name'        => fake()->words(2, true),
            'description' => fake()->sentence(),
            'price'       => fake()->randomFloat(2, 10, 200),
            'duration'    => fake()->randomElement([15, 30, 45, 60, 90, 120]),
            'category'    => fake()->randomElement(['Coiffure', 'Coloration', 'Manucure', 'Soin', 'Massage']),
            'is_active'   => true,
        ];
    }
}
