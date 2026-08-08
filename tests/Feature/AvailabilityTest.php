<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Employee;
use App\Models\Salon;
use App\Models\Service;
use App\Models\User;
use App\Services\AppointmentAvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private AppointmentAvailabilityService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new AppointmentAvailabilityService();
    }

    public function test_employee_is_available_with_no_appointments(): void
    {
        $employee = Employee::factory()->create();
        $time     = Carbon::now()->addDay();

        $this->assertTrue($this->svc->isEmployeeAvailable($employee, $time, 60));
    }

    public function test_exact_overlap_is_blocked(): void
    {
        $employee = Employee::factory()->create();
        $time     = Carbon::now()->addDay();

        Appointment::factory()->create([
            'employee_id'  => $employee->id,
            'scheduled_at' => $time,
            'duration'     => 60,
            'status'       => 'confirmed',
        ]);

        $this->assertFalse($this->svc->isEmployeeAvailable($employee, $time, 60));
    }

    public function test_adjacent_slots_do_not_overlap(): void
    {
        $employee = Employee::factory()->create();
        $start    = Carbon::now()->addDay()->setTime(10, 0);

        Appointment::factory()->create([
            'employee_id'  => $employee->id,
            'scheduled_at' => $start,
            'duration'     => 60,
            'status'       => 'confirmed',
        ]);

        $nextSlot = $start->copy()->addHour(); // 11:00

        $this->assertTrue($this->svc->isEmployeeAvailable($employee, $nextSlot, 60));
    }

    public function test_partial_overlap_at_start_is_blocked(): void
    {
        $employee = Employee::factory()->create();
        $start    = Carbon::now()->addDay()->setTime(10, 0);

        Appointment::factory()->create([
            'employee_id'  => $employee->id,
            'scheduled_at' => $start,
            'duration'     => 60,
            'status'       => 'confirmed',
        ]);

        $overlapping = $start->copy()->addMinutes(30); // 10:30

        $this->assertFalse($this->svc->isEmployeeAvailable($employee, $overlapping, 60));
    }

    public function test_cancelled_appointment_does_not_block_slot(): void
    {
        $employee = Employee::factory()->create();
        $time     = Carbon::now()->addDay();

        Appointment::factory()->create([
            'employee_id'  => $employee->id,
            'scheduled_at' => $time,
            'duration'     => 60,
            'status'       => 'cancelled',
        ]);

        $this->assertTrue($this->svc->isEmployeeAvailable($employee, $time, 60));
    }

    public function test_find_available_employee_returns_competent_employee(): void
    {
        $manager  = User::factory()->create();
        $salon    = Salon::factory()->for($manager, 'manager')->create();
        $service  = Service::factory()->for($salon)->create();
        $empUser  = User::factory()->create();
        $employee = Employee::factory()->for($salon)->create(['user_id' => $empUser->id]);

        $service->employees()->attach($employee->id);

        $time      = Carbon::now()->addDay();
        $available = $this->svc->findAvailableEmployee($salon, $service, $time, 60);

        $this->assertNotNull($available);
        $this->assertEquals($employee->id, $available->id);
    }

    public function test_find_available_employee_returns_null_when_no_competent_employee(): void
    {
        $manager  = User::factory()->create();
        $salon    = Salon::factory()->for($manager, 'manager')->create();
        $service  = Service::factory()->for($salon)->create();
        // Employee exists but not attached to the service
        Employee::factory()->for($salon)->create();

        $time   = Carbon::now()->addDay();
        $result = $this->svc->findAvailableEmployee($salon, $service, $time, 60);

        $this->assertNull($result);
    }

    public function test_find_available_employee_returns_null_when_all_employees_busy(): void
    {
        $manager  = User::factory()->create();
        $salon    = Salon::factory()->for($manager, 'manager')->create();
        $service  = Service::factory()->for($salon)->create();
        $empUser  = User::factory()->create();
        $employee = Employee::factory()->for($salon)->create(['user_id' => $empUser->id]);

        $service->employees()->attach($employee->id);

        $time = Carbon::now()->addDay()->setTime(10, 0);

        // Block the employee's slot
        Appointment::factory()->create([
            'employee_id'  => $employee->id,
            'scheduled_at' => $time,
            'duration'     => 60,
            'status'       => 'confirmed',
        ]);

        $result = $this->svc->findAvailableEmployee($salon, $service, $time, 60);

        $this->assertNull($result);
    }
}
