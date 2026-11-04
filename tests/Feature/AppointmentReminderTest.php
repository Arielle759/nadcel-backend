<?php

namespace Tests\Feature;

use App\Mail\AppointmentReminder;
use App\Models\Appointment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

// Rappel e-mail des rendez-vous en ligne confirmés : ~24 h avant, ou au passage
// suivant pour une réservation tardive, une seule fois, jamais sans e-mail.
class AppointmentReminderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Carbon::setTestNow('2026-11-04 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function appointment(string $at, array $attributes = []): Appointment
    {
        return Appointment::factory()->create(array_merge([
            'client_id'    => User::factory()->create()->id,
            'status'       => 'confirmed',
            'scheduled_at' => $at,
        ], $attributes));
    }

    public function test_reminds_confirmed_appointments_within_the_window_once(): void
    {
        $tomorrow = $this->appointment('2026-11-05 10:05:00');   // dans 24 h
        $lateBooking = $this->appointment('2026-11-04 16:00:00'); // réservé tard, dans 6 h
        $tooSoon = $this->appointment('2026-11-04 11:00:00');     // dans 1 h : inutile
        $tooFar = $this->appointment('2026-11-06 10:00:00');      // dans 48 h : plus tard
        $pending = $this->appointment('2026-11-05 09:00:00', ['status' => 'pending']);
        $offline = $this->appointment('2026-11-05 09:30:00', [
            'client_id' => null, 'client_name' => 'Awa', 'client_phone' => '771234567', 'source' => 'whatsapp',
        ]);

        $this->artisan('appointments:send-reminders')->assertSuccessful();

        Mail::assertQueuedCount(2);
        Mail::assertQueued(AppointmentReminder::class, fn ($m) => $m->appointment->is($tomorrow));
        Mail::assertQueued(AppointmentReminder::class, fn ($m) => $m->appointment->is($lateBooking));
        foreach ([$tooSoon, $tooFar, $pending, $offline] as $skipped) {
            $this->assertNull($skipped->fresh()->reminder_sent_at);
        }

        // Second passage : rien n'est renvoyé.
        $this->artisan('appointments:send-reminders')->assertSuccessful();
        Mail::assertQueuedCount(2);
    }
}
