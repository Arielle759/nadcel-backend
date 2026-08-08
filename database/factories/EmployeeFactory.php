<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\Salon;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class EmployeeFactory extends Factory
{
    protected $model = Employee::class;

    public function definition(): array
    {
        return [
            'user_id'  => User::factory(),
            'salon_id' => Salon::factory(),
            'name'     => fake()->name(),
            'phone'    => fake()->phoneNumber(),
        ];
    }
}
