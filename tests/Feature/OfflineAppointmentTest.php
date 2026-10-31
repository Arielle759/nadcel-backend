<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Employee;
use App\Models\Salon;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

// Rendez-vous pris par WhatsApp, téléphone ou sur place et saisi par le gérant.
class OfflineAppointmentTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;
    private Salon $salon;
    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-10-28 08:00:00'));

        Role::create(['name' => 'gerant']);
        Role::create(['name' => 'client']);

        $this->manager = User::factory()->create();
        $this->manager->assignRole('gerant');
        $this->salon = Salon::factory()->for($this->manager, 'manager')->verified()->create();
        $this->service = Service::factory()->for($this->salon)->create(['duration' => 60, 'price' => 10000]);
        $employee = Employee::factory()->for($this->salon)->create();
        $this->service->employees()->attach($employee);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'salon_id'     => $this->salon->id,
            'service_id'   => $this->service->id,
            'scheduled_at' => '2026-10-29 10:00:00',
            'client_name'  => 'Aminata Fall',
            'client_phone' => '77 123 45 67',
            'source'       => 'whatsapp',
        ], $overrides);
    }

    public function test_manager_records_a_whatsapp_booking_without_client_account(): void
    {
        $this->actingAs($this->manager, 'api')
            ->postJson('/api/manager/appointments', $this->payload())
            ->assertCreated()
            ->assertJsonPath('status', 'confirmed')
            ->assertJsonPath('source', 'whatsapp')
            ->assertJsonPath('client_id', null)
            ->assertJsonPath('client_name', 'Aminata Fall');

        $appointment = Appointment::firstOrFail();
        $this->assertNotNull($appointment->employee_id);
        $this->assertEquals(10000, $appointment->price);
        Mail::assertNothingSent();
    }

    public function test_offline_booking_blocks_the_slot_for_online_clients(): void
    {
        $this->actingAs($this->manager, 'api')->postJson('/api/manager/appointments', $this->payload())->assertCreated();

        $client = User::factory()->create();
        $client->assignRole('client');
        $this->actingAs($client, 'api')->postJson('/api/appointments', [
            'salon_id'     => $this->salon->id,
            'service_id'   => $this->service->id,
            'scheduled_at' => '2026-10-29 10:30:00',
        ])->assertStatus(409);
    }

    public function test_another_manager_cannot_record_for_this_salon(): void
    {
        $other = User::factory()->create();
        $other->assignRole('gerant');

        $this->actingAs($other, 'api')
            ->postJson('/api/manager/appointments', $this->payload())
            ->assertForbidden();
        $this->assertSame(0, Appointment::count());
    }

    public function test_a_client_cannot_use_the_manager_endpoint(): void
    {
        $client = User::factory()->create();
        $client->assignRole('client');

        $this->actingAs($client, 'api')
            ->postJson('/api/manager/appointments', $this->payload())
            ->assertForbidden();
    }

    public function test_source_must_be_an_offline_channel_and_phone_is_checked(): void
    {
        $this->actingAs($this->manager, 'api')
            ->postJson('/api/manager/appointments', $this->payload(['source' => 'online']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('source');

        $this->actingAs($this->manager, 'api')
            ->postJson('/api/manager/appointments', $this->payload(['client_phone' => 'pas un numero']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('client_phone');
    }

    public function test_offline_booking_can_be_cancelled_and_closed_without_email(): void
    {
        $this->actingAs($this->manager, 'api')->postJson('/api/manager/appointments', $this->payload())->assertCreated();
        $this->actingAs($this->manager, 'api')
            ->postJson('/api/manager/appointments', $this->payload(['scheduled_at' => '2026-10-29 14:00:00']))
            ->assertCreated();
        [$first, $second] = Appointment::orderBy('scheduled_at')->get();

        $this->patchJson("/api/appointments/{$first->id}", ['status' => 'cancelled'])->assertOk();

        Carbon::setTestNow(Carbon::parse('2026-10-29 16:00:00'));
        $this->patchJson("/api/appointments/{$second->id}/complete", ['amount_paid' => 10000, 'payment_method' => 'wave'])
            ->assertOk()
            ->assertJsonPath('status', 'completed');

        Mail::assertNothingSent();
    }

    public function test_activity_stats_count_channels_and_recognise_returning_clients_by_phone(): void
    {
        foreach (['2026-10-01 10:00', '2026-10-08 10:00'] as $date) {
            Appointment::factory()->create([
                'client_id' => null, 'client_name' => 'Aminata Fall', 'client_phone' => '771234567',
                'source' => 'phone', 'salon_id' => $this->salon->id, 'service_id' => $this->service->id,
                'scheduled_at' => $date, 'status' => 'completed', 'price' => 10000,
            ]);
        }
        Appointment::factory()->create([
            'salon_id' => $this->salon->id, 'service_id' => $this->service->id,
            'scheduled_at' => '2026-10-15 10:00', 'status' => 'completed', 'price' => 10000,
        ]);

        $response = $this->actingAs($this->manager, 'api')
            ->getJson('/api/manager/stats/activity?period=month')
            ->assertOk();

        $sources = collect($response->json('sources'))->pluck('count', 'source');
        $this->assertSame(2, $sources['phone']);
        $this->assertSame(1, $sources['online']);
        $response->assertJsonPath('loyalty.clients', 2)->assertJsonPath('loyalty.returning', 1);
    }

    public function test_manager_marks_the_whatsapp_reminder_as_sent_once(): void
    {
        $this->actingAs($this->manager, 'api')->postJson('/api/manager/appointments', $this->payload())->assertCreated();
        $appointment = Appointment::firstOrFail();

        $this->patchJson("/api/manager/appointments/{$appointment->id}/reminder")->assertOk();
        $firstSentAt = $appointment->fresh()->reminder_sent_at;
        $this->assertNotNull($firstSentAt);

        Carbon::setTestNow(Carbon::parse('2026-10-28 09:00:00'));
        $this->patchJson("/api/manager/appointments/{$appointment->id}/reminder")->assertOk();
        $this->assertTrue($appointment->fresh()->reminder_sent_at->equalTo($firstSentAt));
    }

    public function test_only_the_salon_manager_can_mark_a_reminder(): void
    {
        $this->actingAs($this->manager, 'api')->postJson('/api/manager/appointments', $this->payload())->assertCreated();
        $appointment = Appointment::firstOrFail();

        $other = User::factory()->create();
        $other->assignRole('gerant');
        $this->actingAs($other, 'api')
            ->patchJson("/api/manager/appointments/{$appointment->id}/reminder")
            ->assertForbidden();
        $this->assertNull($appointment->fresh()->reminder_sent_at);
    }

    public function test_reminder_cannot_be_marked_on_a_cancelled_appointment(): void
    {
        $this->actingAs($this->manager, 'api')->postJson('/api/manager/appointments', $this->payload())->assertCreated();
        $appointment = Appointment::firstOrFail();
        $this->patchJson("/api/appointments/{$appointment->id}", ['status' => 'cancelled'])->assertOk();

        $this->patchJson("/api/manager/appointments/{$appointment->id}/reminder")->assertUnprocessable();
    }
}
