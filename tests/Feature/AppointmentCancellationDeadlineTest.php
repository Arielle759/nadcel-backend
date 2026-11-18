<?php

namespace Tests\Feature;

use App\Mail\AppointmentCancelled;
use App\Models\Appointment;
use App\Models\Salon;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

// Règle métier : une cliente annule en ligne jusqu'à l'heure du rendez-vous ; moins de
// 24 h avant, l'annulation d'un rendez-vous confirmé est marquée « tardive »
// automatiquement et signalée au salon. Le salon peut toujours annuler, et enregistre
// une absence une fois l'heure passée.
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
        $this->assertFalse($appointment->fresh()->cancelled_late);
    }

    public function test_client_cancelling_within_24h_is_marked_late_and_salon_is_warned(): void
    {
        $appointment = $this->appointment('confirmed', 5);

        $this->actingAs($this->client, 'api')
            ->patchJson("/api/appointments/{$appointment->id}", ['status' => 'cancelled'])
            ->assertOk();

        $fresh = $appointment->fresh();
        $this->assertSame('cancelled', $fresh->status);
        $this->assertTrue($fresh->cancelled_late);
        Mail::assertQueued(AppointmentCancelled::class, fn ($mail) => $mail->hasTo($this->manager->email) && $mail->initiatedByClient);
    }

    public function test_client_cannot_cancel_an_appointment_that_has_started(): void
    {
        $appointment = $this->appointment('confirmed', -1);

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

        $this->assertFalse($appointment->fresh()->cancelled_late);
    }

    public function test_salon_can_cancel_confirmed_appointment_within_24h(): void
    {
        $appointment = $this->appointment('confirmed', 3);

        $this->actingAs($this->manager, 'api')
            ->patchJson("/api/appointments/{$appointment->id}", ['status' => 'cancelled'])
            ->assertOk();

        $this->assertSame('cancelled', $appointment->fresh()->status);
        $this->assertFalse($appointment->fresh()->cancelled_late);
    }

    public function test_salon_records_a_no_show_only_after_the_appointment_time(): void
    {
        $upcoming = $this->appointment('confirmed', 3);
        $this->actingAs($this->manager, 'api')
            ->patchJson("/api/appointments/{$upcoming->id}", ['status' => 'no_show'])
            ->assertStatus(422);

        $past = $this->appointment('confirmed', -2);
        $this->actingAs($this->manager, 'api')
            ->patchJson("/api/appointments/{$past->id}", ['status' => 'no_show'])
            ->assertOk();

        $this->assertSame('no_show', $past->fresh()->status);
        Mail::assertNothingQueued();
    }

    public function test_client_cannot_mark_herself_absent(): void
    {
        $appointment = $this->appointment('confirmed', -2);

        $this->actingAs($this->client, 'api')
            ->patchJson("/api/appointments/{$appointment->id}", ['status' => 'no_show'])
            ->assertForbidden();
    }

    public function test_client_file_counts_late_cancellations_and_no_shows(): void
    {
        $this->manager->assignRole(Role::firstOrCreate(['name' => 'gerant']));
        $this->appointment('cancelled', 2)->update(['cancelled_late' => true]);
        $this->appointment('cancelled', 72);
        $this->appointment('no_show', -24);
        $this->appointment('completed', -48);

        $row = $this->actingAs($this->manager, 'api')
            ->getJson('/api/manager/clients')
            ->assertOk()
            ->json('data.0');

        $this->assertSame(1, $row['late_cancellations']);
        $this->assertSame(1, $row['no_shows']);
        $this->assertSame(2, $row['appointments']);
        $this->assertSame(1, $row['visits']);
    }
}
