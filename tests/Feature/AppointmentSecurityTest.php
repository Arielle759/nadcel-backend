<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AppointmentSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::create(['name' => 'admin']);
        Role::create(['name' => 'gerant']);
        Role::create(['name' => 'client']);
    }

    public function test_client_cannot_cancel_another_clients_appointment(): void
    {
        $clientA = User::factory()->create();
        $clientA->assignRole('client');

        $clientB = User::factory()->create();
        $clientB->assignRole('client');

        // AppointmentFactory crée son propre salon/service — on surcharge juste le client
        $appointment = Appointment::factory()
            ->for($clientB, 'client')
            ->create(['status' => 'pending']);

        $response = $this->actingAs($clientA, 'api')
                         ->putJson("/api/appointments/{$appointment->id}", [
                             'status' => 'cancelled',
                         ]);

        $response->assertStatus(403);
    }
}
