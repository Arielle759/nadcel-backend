<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Review;
use App\Models\Salon;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminStatsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['admin', 'gerant', 'client'] as $role) {
            Role::create(['name' => $role, 'guard_name' => 'api']);
        }
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
        Carbon::setTestNow('2026-10-15 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function salonIn(string $state): Salon
    {
        return Salon::factory()->create(match ($state) {
            'online'    => ['is_verified' => true, 'is_active' => true],
            'pending'   => ['is_verified' => false, 'is_active' => true],
            'suspended' => ['is_verified' => true, 'is_active' => false],
            'rejected'  => ['is_verified' => false, 'is_active' => false],
        });
    }

    private function appointment(Salon $salon, string $scheduledAt, array $attributes = []): Appointment
    {
        return Appointment::factory()->create(array_merge([
            'salon_id'     => $salon->id,
            'scheduled_at' => $scheduledAt,
            'status'       => 'completed',
            'source'       => 'online',
        ], $attributes));
    }

    public function test_stats_expose_no_revenue(): void
    {
        $response = $this->actingAs($this->admin, 'api')->getJson('/api/admin/stats')->assertOk();

        $this->assertArrayNotHasKey('revenue', $response->json());
        $this->assertStringNotContainsString('amount', $response->getContent());
    }

    public function test_salon_states_are_distinct(): void
    {
        $this->salonIn('online');
        $this->salonIn('online');
        $this->salonIn('pending');
        $this->salonIn('suspended');
        $this->salonIn('rejected');

        $this->actingAs($this->admin, 'api')->getJson('/api/admin/stats')
            ->assertOk()
            ->assertJsonPath('salons', ['online' => 2, 'pending' => 1, 'suspended' => 1, 'rejected' => 1])
            ->assertJsonPath('to_review.pending_salons', 1);
    }

    public function test_trend_compares_the_same_period_of_last_month(): void
    {
        $salon = $this->salonIn('online');
        // Du 1er au 15 octobre : 3 rendez-vous. Du 1er au 15 septembre : 2.
        $this->appointment($salon, '2026-10-02 10:00:00');
        $this->appointment($salon, '2026-10-10 10:00:00');
        $this->appointment($salon, '2026-10-14 10:00:00');
        $this->appointment($salon, '2026-09-03 10:00:00');
        $this->appointment($salon, '2026-09-12 10:00:00');
        // Après le 15 septembre : hors de la période comparée.
        $this->appointment($salon, '2026-09-25 10:00:00');

        $this->actingAs($this->admin, 'api')->getJson('/api/admin/stats')
            ->assertOk()
            ->assertJsonPath('appointments.this_month', 3)
            ->assertJsonPath('appointments.last_month_same_period', 2)
            ->assertJsonPath('appointments.trend', 50);
    }

    public function test_cancellation_rate_and_online_share_cover_the_last_30_days(): void
    {
        $salon = $this->salonIn('online');
        $this->appointment($salon, '2026-10-01 10:00:00');
        $this->appointment($salon, '2026-10-05 10:00:00', ['source' => 'whatsapp', 'client_id' => null, 'client_name' => 'Awa', 'client_phone' => '771234567']);
        $this->appointment($salon, '2026-10-08 10:00:00', ['status' => 'cancelled']);
        $this->appointment($salon, '2026-10-12 10:00:00', ['source' => 'phone', 'client_id' => null, 'client_name' => 'Fatou', 'client_phone' => '781234567']);
        // Trop ancien : ignoré.
        $this->appointment($salon, '2026-08-01 10:00:00', ['status' => 'cancelled']);

        $this->actingAs($this->admin, 'api')->getJson('/api/admin/stats')
            ->assertOk()
            ->assertJsonPath('appointments.cancellation_rate', 25)
            ->assertJsonPath('appointments.online_share', 50)
            ->assertJsonPath('appointments.recent_total', 4)
            ->assertJsonPath('appointments.sources', [
                ['source' => 'online', 'count' => 2, 'share' => 50],
                ['source' => 'whatsapp', 'count' => 1, 'share' => 25],
                ['source' => 'instagram', 'count' => 0, 'share' => 0],
                ['source' => 'facebook', 'count' => 0, 'share' => 0],
                ['source' => 'tiktok', 'count' => 0, 'share' => 0],
                ['source' => 'phone', 'count' => 1, 'share' => 25],
                ['source' => 'walk_in', 'count' => 0, 'share' => 0],
            ]);
    }

    public function test_rates_are_null_without_activity(): void
    {
        $this->actingAs($this->admin, 'api')->getJson('/api/admin/stats')
            ->assertOk()
            ->assertJsonPath('appointments.trend', null)
            ->assertJsonPath('appointments.cancellation_rate', null);
    }

    public function test_monthly_series_covers_thirteen_months_with_new_clients(): void
    {
        $salon = $this->salonIn('online');
        $this->appointment($salon, '2026-03-10 10:00:00');
        $client = User::factory()->create(['created_at' => '2026-03-02 09:00:00']);
        $client->assignRole('client');
        User::factory()->create(['created_at' => '2026-03-03 09:00:00'])->assignRole('gerant');

        $monthly = $this->actingAs($this->admin, 'api')->getJson('/api/admin/stats')->assertOk()->json('monthly');

        $this->assertCount(13, $monthly);
        $this->assertSame('2025-10', $monthly[0]['month']);
        $this->assertSame('2026-10', $monthly[12]['month']);
        $this->assertTrue($monthly[12]['is_partial']);

        $march = collect($monthly)->firstWhere('month', '2026-03');
        $this->assertSame(1, $march['appointments']);
        $this->assertSame(1, $march['new_clients']);
    }

    public function test_recent_low_reviews_are_flagged(): void
    {
        $salon = $this->salonIn('online');
        foreach ([1, 2, 5] as $index => $rating) {
            $appointment = $this->appointment($salon, '2026-10-0'.($index + 1).' 10:00:00');
            Review::factory()->create([
                'appointment_id' => $appointment->id,
                'client_id'      => $appointment->client_id,
                'salon_id'       => $salon->id,
                'rating'         => $rating,
            ]);
        }

        $this->actingAs($this->admin, 'api')->getJson('/api/admin/stats')
            ->assertOk()
            ->assertJsonPath('to_review.low_reviews', 2);
    }

    public function test_salon_list_filters_by_state(): void
    {
        $this->salonIn('online');
        $suspended = $this->salonIn('suspended');

        $this->actingAs($this->admin, 'api')->getJson('/api/admin/salons?status=suspended')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $suspended->id);

        $this->actingAs($this->admin, 'api')->getJson('/api/admin/salons?status=unknown')
            ->assertUnprocessable();
    }

    public function test_actions_only_apply_from_the_expected_state(): void
    {
        $pending = $this->salonIn('pending');
        $online = $this->salonIn('online');

        // Suspendre une demande en attente la ferait passer pour refusée.
        $this->actingAs($this->admin, 'api')->patchJson("/api/admin/salons/{$pending->id}/suspend")->assertUnprocessable();
        // Un salon en ligne se suspend, il ne se « refuse » pas.
        $this->actingAs($this->admin, 'api')->patchJson("/api/admin/salons/{$online->id}/reject")->assertUnprocessable();
        $this->actingAs($this->admin, 'api')->patchJson("/api/admin/salons/{$online->id}/verify")->assertUnprocessable();
        $this->actingAs($this->admin, 'api')->patchJson("/api/admin/salons/{$online->id}/reactivate")->assertUnprocessable();

        $this->actingAs($this->admin, 'api')->patchJson("/api/admin/salons/{$online->id}/suspend")->assertOk();
        $this->actingAs($this->admin, 'api')->patchJson("/api/admin/salons/{$online->id}/reactivate")->assertOk();
        $this->assertTrue($online->fresh()->is_active);

        $this->assertTrue($pending->fresh()->is_active);
        $this->assertFalse($pending->fresh()->is_verified);
    }
}
