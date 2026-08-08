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

class AppointmentStatusTransitionTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;
    private User $client;
    private Salon $salon;
    private Service $service;
    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'client']);

        $this->manager  = User::factory()->create();
        $this->client   = User::factory()->create();
        $this->client->assignRole('client');

        $this->salon    = Salon::factory()->for($this->manager, 'manager')->create();
        $this->service  = Service::factory()->for($this->salon)->create();
        $this->employee = Employee::factory()->for($this->salon)->create();
    }

    private function makeAppointment(string $status): Appointment
    {
        return Appointment::factory()->state(['status' => $status])->create([
            'client_id'  => $this->client->id,
            'salon_id'   => $this->salon->id,
            'service_id' => $this->service->id,
        ]);
    }

    // --- Transitions valides (gérant du salon) ---

    public function test_pending_can_transition_to_confirmed(): void
    {
        $appt = $this->makeAppointment('pending');

        $response = $this->actingAs($this->manager, 'api')
                         ->patchJson("/api/appointments/{$appt->id}", [
                             'status' => 'confirmed',
                         ]);

        $response->assertStatus(200);
        $this->assertEquals('confirmed', $appt->fresh()->status);
    }

    public function test_confirmed_can_transition_to_completed(): void
    {
        $appt = $this->makeAppointment('confirmed');

        $response = $this->actingAs($this->manager, 'api')
                         ->patchJson("/api/appointments/{$appt->id}", [
                             'status' => 'completed',
                         ]);

        $response->assertStatus(200);
        $this->assertEquals('completed', $appt->fresh()->status);
    }

    public function test_confirmed_can_transition_to_cancelled(): void
    {
        $appt = $this->makeAppointment('confirmed');

        $response = $this->actingAs($this->manager, 'api')
                         ->patchJson("/api/appointments/{$appt->id}", [
                             'status' => 'cancelled',
                         ]);

        $response->assertStatus(200);
        $this->assertEquals('cancelled', $appt->fresh()->status);
    }

    // --- Annulation par le client (son propre RDV) ---

    public function test_pending_can_transition_to_cancelled(): void
    {
        $appt = $this->makeAppointment('pending');

        $response = $this->actingAs($this->client, 'api')
                         ->patchJson("/api/appointments/{$appt->id}", [
                             'status' => 'cancelled',
                         ]);

        $response->assertStatus(200);
        $this->assertEquals('cancelled', $appt->fresh()->status);
    }

    // --- Transitions invalides (gérant → 422 depuis la machine d'état) ---

    public function test_pending_cannot_skip_to_completed(): void
    {
        $appt = $this->makeAppointment('pending');

        $response = $this->actingAs($this->manager, 'api')
                         ->patchJson("/api/appointments/{$appt->id}", [
                             'status' => 'completed',
                         ]);

        $response->assertStatus(422);
    }

    public function test_completed_is_terminal(): void
    {
        $appt = $this->makeAppointment('completed');

        $response = $this->actingAs($this->manager, 'api')
                         ->patchJson("/api/appointments/{$appt->id}", [
                             'status' => 'cancelled',
                         ]);

        $response->assertStatus(422);
    }

    public function test_cancelled_is_terminal(): void
    {
        $appt = $this->makeAppointment('cancelled');

        $response = $this->actingAs($this->manager, 'api')
                         ->patchJson("/api/appointments/{$appt->id}", [
                             'status' => 'pending',
                         ]);

        $response->assertStatus(422);
    }

    // --- Accès non autorisé ---

    public function test_stranger_cannot_update_appointment(): void
    {
        $appt     = $this->makeAppointment('pending');
        $stranger = User::factory()->create();

        $response = $this->actingAs($stranger, 'api')
                         ->patchJson("/api/appointments/{$appt->id}", [
                             'status' => 'confirmed',
                         ]);

        $response->assertStatus(403);
    }
}
