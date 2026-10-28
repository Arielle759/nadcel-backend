<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Salon;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

// Règle métier : un client annule seul un rendez-vous confirmé jusqu'à 24 h avant ;
// une demande en attente reste annulable à tout moment ; le salon peut toujours annuler.
class AppointmentCancellationDeadlineTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;
    private User $client;
    private Salon $salon;
    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        Role::create(['name' => 'client']);

        $this->manager = User::factory()->create();
        $this->client = User::factory()->create();
        $this->client->assignRole('client');

        $this->salon = Salon::factory()->for($this->manager, 'manager')->create();
        $this->service = Service::factory()->for($this->salon)->create();
    }

    private function appointment(string $status, int $hoursAhead): Appointment
    {
        return Appointment::factory()->create([
            'client_id' => $this->client->id,
            'salon_id' => $this->salon->id,
            'service_id' => $this->service->id,
            'status' => $status,
            'scheduled_at' => now()->addHours($hoursAhead),
        ]);
    }

    public function test_client_can_cancel_confirmed_appointment_more_than_24h_ahead(): void
    {
        $appointment = $this->appointment('confirmed', 48);

        $this->actingAs($this->client, 'api')
            ->patchJson("/api/appointments/{$appointment->id}", ['status' => 'cancelled'])
            ->assertOk();

        $this->assertSame('cancelled', $appointment->fresh()->status);
    }

    public function test_client_cannot_cancel_confirmed_appointment_within_24h(): void
    {
        $appointment = $this->appointment('confirmed', 5);

        $this->actingAs($this->client, 'api')
            ->patchJson("/api/appointments/{$appointment->id}", ['status' => 'cancelled'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        $this->assertSame('confirmed', $appointment->fresh()->status);
    }

    public function test_client_can_cancel_pending_request_even_within_24h(): void
    {
        $appointment = $this->appointment('pending', 2);

        $this->actingAs($this->client, 'api')
            ->patchJson("/api/appointments/{$appointment->id}", ['status' => 'cancelled'])
            ->assertOk();
    }

    public function test_salon_can_cancel_confirmed_appointment_within_24h(): void
    {
        $appointment = $this->appointment('confirmed', 3);

        $this->actingAs($this->manager, 'api')
            ->patchJson("/api/appointments/{$appointment->id}", ['status' => 'cancelled'])
            ->assertOk();

        $this->assertSame('cancelled', $appointment->fresh()->status);
    }
}
