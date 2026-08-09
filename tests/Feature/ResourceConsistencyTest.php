<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Salon;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ResourceConsistencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['client', 'gerant', 'employee'] as $role) {
            Role::create(['name' => $role]);
        }
    }

    public function test_appointment_service_must_belong_to_selected_salon(): void
    {
        $client = User::factory()->create();
        $client->assignRole('client');

        $selectedSalon = Salon::factory()->create();
        $otherSalon = Salon::factory()->create();
        $otherService = Service::factory()->for($otherSalon)->create();

        $response = $this->actingAs($client, 'api')->postJson('/api/appointments', [
            'salon_id' => $selectedSalon->id,
            'service_id' => $otherService->id,
            'scheduled_at' => now()->addDay(),
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('service_id');
        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_employee_cannot_receive_service_from_another_salon(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('gerant');
        $salon = Salon::factory()->for($manager, 'manager')->create();
        $otherService = Service::factory()->create();

        $response = $this->actingAs($manager, 'api')->postJson('/api/employees', [
            'salon_id' => $salon->id,
            'name' => 'Employé test',
            'phone' => '0102030405',
            'email' => 'employee@example.test',
            'service_ids' => [$otherService->id],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('service_ids');
        $this->assertDatabaseMissing('users', ['email' => 'employee@example.test']);
    }

    public function test_employee_services_cannot_be_updated_with_another_salons_service(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('gerant');
        $salon = Salon::factory()->for($manager, 'manager')->create();
        $employee = Employee::factory()->for($salon)->create();
        $otherService = Service::factory()->create();

        $response = $this->actingAs($manager, 'api')->putJson("/api/employees/{$employee->id}", [
            'service_ids' => [$otherService->id],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('service_ids');
        $this->assertDatabaseMissing('employee_service', [
            'employee_id' => $employee->id,
            'service_id' => $otherService->id,
        ]);
    }
}
