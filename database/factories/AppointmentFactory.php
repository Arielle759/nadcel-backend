<?php

namespace Database\Factories;

use App\Models\Appointment;
use App\Models\Employee;
use App\Models\Salon;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class AppointmentFactory extends Factory
{
    protected $model = Appointment::class;

    public function definition(): array
    {
        $salon   = Salon::factory()->create();
        $service = Service::factory()->for($salon)->create();

        return [
            'client_id'      => User::factory(),
            'salon_id'       => $salon->id,
            'service_id'     => $service->id,
            'employee_id'    => null,
            'scheduled_at'   => now()->addDay(),
            'duration'       => $service->duration,
            'status'         => 'pending',
            'price'          => $service->price,
            'payment_status' => 'unpaid',
        ];
    }

    public function completed(): static
    {
        return $this->state(['status' => 'completed']);
    }

    public function cancelled(): static
    {
        return $this->state(['status' => 'cancelled']);
    }

    public function confirmed(): static
    {
        return $this->state(['status' => 'confirmed']);
    }
}
