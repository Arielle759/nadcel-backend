<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Employee;
use App\Models\Salon;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EmployeeSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::create(['name' => 'admin']);
        Role::create(['name' => 'gerant']);
        Role::create(['name' => 'client']);
    }

    public function test_client_cannot_view_employee_appointments_of_another_salon(): void
    {
        $client  = User::factory()->create();
        $client->assignRole('client');

        $manager  = User::factory()->create();
        $salon    = Salon::factory()->for($manager, 'manager')->verified()->create();
        $service  = Service::factory()->for($salon)->create();
        $empUser  = User::factory()->create();
        $employee = Employee::factory()->for($salon)->create(['user_id' => $empUser->id]);

        Appointment::factory()->create([
            'employee_id' => $employee->id,
            'salon_id'    => $salon->id,
            'service_id'  => $service->id,
        ]);

        $response = $this->actingAs($client, 'api')
                         ->getJson("/api/employees/{$employee->id}");

        $response->assertStatus(403);
    }

    public function test_manager_can_view_own_employee(): void
    {
        $manager  = User::factory()->create();
        $manager->assignRole('gerant');
        $salon    = Salon::factory()->for($manager, 'manager')->create();
        $employee = Employee::factory()->for($salon)->create();

        $response = $this->actingAs($manager, 'api')
                         ->getJson("/api/employees/{$employee->id}");

        $response->assertStatus(200);
    }

    public function test_index_requires_salon_id(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('gerant');

        $response = $this->actingAs($manager, 'api')
                         ->getJson('/api/employees');

        $response->assertStatus(422);
    }

    public function test_client_cannot_list_employees_of_a_salon_they_do_not_manage(): void
    {
        $client = User::factory()->create();
        $client->assignRole('client');
        $manager = User::factory()->create();
        $salon   = Salon::factory()->for($manager, 'manager')->verified()->create();

        $response = $this->actingAs($client, 'api')
                         ->getJson("/api/employees?salon_id={$salon->id}");

        $response->assertStatus(403);
    }
}
