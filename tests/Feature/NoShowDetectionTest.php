<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Employee;
use App\Models\Salon;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

// Absences : détection automatique 30 min après l'heure prévue si la cliente
// n'a pas été signalée arrivée ; le créneau libéré redevient réservable ; le
// gérant peut corriger une erreur en clôturant le rendez-vous.
class NoShowDetectionTest extends TestCase
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

    private function appointment(string $status, int $minutesFromNow, array $extra = []): Appointment
    {
        return Appointment::factory()->create(array_merge([
            'client_id' => $this->client->id,
            'salon_id' => $this->salon->id,
            'service_id' => $this->service->id,
            'status' => $status,
            'scheduled_at' => now()->addMinutes($minutesFromNow),
        ], $extra));
    }

    public function test_confirmed_appointment_without_arrival_is_marked_absent_after_30_minutes(): void
    {
        $late = $this->appointment('confirmed', -35);
        $justStarted = $this->appointment('confirmed', -10);
        $arrived = $this->appointment('in_progress', -60);
        $old = $this->appointment('confirmed', -60 * 30);

        $this->artisan('appointments:mark-no-shows')->assertSuccessful();

        $this->assertSame('no_show', $late->fresh()->status);
        $this->assertNotNull($late->fresh()->no_show_detected_at);
        $this->assertSame('confirmed', $justStarted->fresh()->status);
        $this->assertSame('in_progress', $arrived->fresh()->status);
        $this->assertSame('confirmed', $old->fresh()->status, 'les rendez-vous de plus de 24 h ne sont pas requalifiés');
    }

    public function test_salon_signals_arrival_only_around_the_appointment_time(): void
    {
        $tooEarly = $this->appointment('confirmed', 120);
        $this->actingAs($this->manager, 'api')
            ->patchJson("/api/appointments/{$tooEarly->id}", ['status' => 'in_progress'])
            ->assertStatus(422);

        $now = $this->appointment('confirmed', 10);
        $this->actingAs($this->manager, 'api')
            ->patchJson("/api/appointments/{$now->id}", ['status' => 'in_progress'])
            ->assertOk();
        $this->assertSame('in_progress', $now->fresh()->status);

        $this->actingAs($this->client, 'api')
            ->patchJson("/api/appointments/{$tooEarly->id}", ['status' => 'in_progress'])
            ->assertForbidden();
    }

    public function test_a_wrongly_detected_absence_can_be_corrected_by_closing(): void
    {
        $appointment = $this->appointment('no_show', -90, ['no_show_detected_at' => now()]);

        $this->actingAs($this->manager, 'api')
            ->patchJson("/api/appointments/{$appointment->id}/complete", ['amount_paid' => 5000, 'payment_method' => 'wave'])
            ->assertOk();

        $this->assertSame('completed', $appointment->fresh()->status);
    }

    public function test_late_client_can_still_be_signalled_arrived_if_the_slot_is_still_free(): void
    {
        $employee = Employee::factory()->create(['salon_id' => $this->salon->id]);
        $appointment = $this->appointment('no_show', -50, ['employee_id' => $employee->id, 'duration' => 180]);

        $this->actingAs($this->manager, 'api')
            ->patchJson("/api/appointments/{$appointment->id}", ['status' => 'in_progress'])
            ->assertOk();

        $this->assertSame('in_progress', $appointment->fresh()->status);
    }

    public function test_late_client_cannot_take_back_a_slot_rebooked_in_the_meantime(): void
    {
        $employee = Employee::factory()->create(['salon_id' => $this->salon->id]);
        $appointment = $this->appointment('no_show', -50, ['employee_id' => $employee->id, 'duration' => 180]);
        $this->appointment('confirmed', 20, ['employee_id' => $employee->id, 'duration' => 60]);

        $this->actingAs($this->manager, 'api')
            ->patchJson("/api/appointments/{$appointment->id}", ['status' => 'in_progress'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        $this->assertSame('no_show', $appointment->fresh()->status);
    }

    public function test_absent_appointment_no_longer_blocks_the_employee(): void
    {
        $employee = Employee::factory()->create(['salon_id' => $this->salon->id]);
        $start = now()->addDay()->setTime(10, 0);
        $this->appointment('no_show', 0, ['employee_id' => $employee->id, 'scheduled_at' => $start, 'duration' => 180]);

        $free = app(\App\Services\AppointmentAvailabilityService::class)
            ->isEmployeeAvailable($employee, $start->copy()->addMinutes(30), 60);

        $this->assertTrue($free);
    }
}
