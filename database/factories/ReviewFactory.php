<?php

namespace Database\Factories;

use App\Models\Appointment;
use App\Models\Review;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ReviewFactory extends Factory
{
    protected $model = Review::class;

    public function definition(): array
    {
        $appointment = Appointment::factory()->completed()->create();

        return [
            'client_id'      => $appointment->client_id,
            'appointment_id' => $appointment->id,
            'salon_id'       => $appointment->salon_id,
            'rating'         => fake()->numberBetween(1, 5),
            'comment'        => fake()->sentence(),
        ];
    }
}
