<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Salon;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AppointmentCompletionTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;
    private Salon $salon;
    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-28 15:00:00'));

        Role::create(['name' => 'gerant']);
        Role::create(['name' => 'admin']);

        $this->manager = User::factory()->create();
        $this->manager->assignRole('gerant');
        $this->salon = Salon::factory()->for($this->manager, 'manager')->verified()->create();
        $this->service = Service::factory()->for($this->salon)->create(['price' => 15000]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function appointment(array $overrides = []): Appointment
    {
        return Appointment::factory()->create(array_merge([
            'salon_id'     => $this->salon->id,
            'service_id'   => $this->service->id,
            'scheduled_at' => Carbon::parse('2026-10-28 10:00'),
            'price'        => 15000,
            'status'       => 'confirmed',
        ], $overrides));
    }

    private function complete(Appointment $appointment, array $payload, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->manager, 'api')
            ->patchJson("/api/appointments/{$appointment->id}/complete", $payload);
    }

    public function test_manager_closes_appointment_and_records_on_site_payment(): void
    {
        $appointment = $this->appointment();

        // Prix indicatif 15 000 F, montant réel 18 000 F (mèches longues).
        $this->complete($appointment, ['amount_paid' => 18000, 'payment_method' => 'wave'])
            ->assertOk()
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('payment_status', 'paid')
            ->assertJsonPath('payment_method', 'wave');

        $fresh = $appointment->fresh();
        $this->assertEquals(18000, $fresh->amount_paid);
        $this->assertNotNull($fresh->paid_at);
    }

    public function test_future_appointment_cannot_be_closed(): void
    {
        $appointment = $this->appointment(['scheduled_at' => Carbon::parse('2026-10-29 10:00')]);

        $this->complete($appointment, ['amount_paid' => 15000, 'payment_method' => 'especes'])
            ->assertUnprocessable();

        $this->assertSame('confirmed', $appointment->fresh()->status);
    }

    public function test_cancelled_appointment_cannot_be_closed(): void
    {
        $appointment = $this->appointment(['status' => 'cancelled']);

        $this->complete($appointment, ['amount_paid' => 15000, 'payment_method' => 'especes'])
            ->assertUnprocessable();
    }

    public function test_unknown_payment_method_and_negative_amount_are_rejected(): void
    {
        $appointment = $this->appointment();

        $this->complete($appointment, ['amount_paid' => 15000, 'payment_method' => 'card'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payment_method');

        $this->complete($appointment, ['amount_paid' => -1, 'payment_method' => 'especes'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount_paid');
    }

    public function test_other_manager_and_admin_cannot_close(): void
    {
        $appointment = $this->appointment();

        $otherManager = User::factory()->create();
        $otherManager->assignRole('gerant');
        $this->complete($appointment, ['amount_paid' => 15000, 'payment_method' => 'especes'], $otherManager)
            ->assertForbidden();

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->complete($appointment, ['amount_paid' => 15000, 'payment_method' => 'especes'], $admin)
            ->assertForbidden();

        $this->assertSame('confirmed', $appointment->fresh()->status);
    }

    public function test_dashboard_revenue_uses_collected_amount_and_counts_appointments_to_close(): void
    {
        $closed = $this->appointment();
        $this->complete($closed, ['amount_paid' => 18000, 'payment_method' => 'especes'])->assertOk();

        $this->appointment(['scheduled_at' => Carbon::parse('2026-10-27 10:00')]); // passé, non clôturé
        $this->appointment(['scheduled_at' => Carbon::parse('2026-10-30 10:00')]); // à venir

        $this->actingAs($this->manager, 'api')->getJson('/api/manager/stats')
            ->assertOk()
            ->assertJsonPath('revenue.collected', 18000)
            ->assertJsonPath('to_close', 1)
            ->assertJsonPath('upcoming', 1);

        $activity = $this->actingAs($this->manager, 'api')->getJson('/api/manager/stats/activity?period=month')
            ->assertOk();

        $this->assertEquals(18000, $activity->json('totals.revenue'));
        $especes = collect($activity->json('payment_methods'))->firstWhere('method', 'especes');
        $this->assertSame(1, $especes['count']);
        $this->assertEquals(18000, $especes['amount']);
    }

    public function test_salon_scope_lists_only_the_managed_salon_appointments(): void
    {
        $salonAppointment = $this->appointment();

        // Le gérant a aussi réservé, comme client, dans un autre salon.
        $otherSalon = Salon::factory()->verified()->create();
        $otherService = Service::factory()->for($otherSalon)->create();
        $personal = Appointment::factory()->create([
            'client_id'  => $this->manager->id,
            'salon_id'   => $otherSalon->id,
            'service_id' => $otherService->id,
        ]);

        $ids = collect($this->actingAs($this->manager, 'api')
            ->getJson('/api/appointments?scope=salon&per_page=100')
            ->assertOk()
            ->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($salonAppointment->id));
        $this->assertFalse($ids->contains($personal->id));

        $this->actingAs($this->manager, 'api')->getJson('/api/appointments?per_page=500')->assertUnprocessable();
    }
}
