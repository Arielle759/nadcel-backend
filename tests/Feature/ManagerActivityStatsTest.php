<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Employee;
use App\Models\Salon;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ManagerActivityStatsTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;
    private Salon $salon;
    private Service $braids;
    private Service $nails;
    private Employee $awa;

    protected function setUp(): void
    {
        parent::setUp();

        // Mercredi 28 octobre 2026, 15 h (UTC = heure de Dakar).
        Carbon::setTestNow(Carbon::parse('2026-10-28 15:00:00'));

        Role::create(['name' => 'gerant']);
        Role::create(['name' => 'client']);

        $this->manager = User::factory()->create();
        $this->manager->assignRole('gerant');
        $this->salon = Salon::factory()->for($this->manager, 'manager')->verified()->create();
        $this->braids = Service::factory()->for($this->salon)->create(['name' => 'Tresses longues', 'price' => 20000]);
        $this->nails = Service::factory()->for($this->salon)->create(['name' => 'Manucure', 'price' => 5000]);
        $this->awa = Employee::factory()->for($this->salon)->create(['name' => 'Awa']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function done(Service $service, string $at, array $overrides = []): Appointment
    {
        return Appointment::factory()->create(array_merge([
            'salon_id'     => $this->salon->id,
            'service_id'   => $service->id,
            'employee_id'  => $this->awa->id,
            'scheduled_at' => Carbon::parse($at),
            'price'        => $service->price,
            'status'       => 'completed',
        ], $overrides));
    }

    private function activity(string $period = 'month')
    {
        return $this->actingAs($this->manager, 'api')->getJson("/api/manager/stats/activity?period={$period}");
    }

    public function test_top_services_count_share_revenue_and_trend(): void
    {
        // Période courante (30 derniers jours) : 3 tresses, 1 manucure.
        $this->done($this->braids, '2026-10-05 10:00');
        $this->done($this->braids, '2026-10-10 10:00');
        $this->done($this->braids, '2026-10-20 14:00');
        $this->done($this->nails, '2026-10-21 18:00');
        // Période précédente : 2 tresses.
        $this->done($this->braids, '2026-09-10 10:00');
        $this->done($this->braids, '2026-09-15 10:00');

        $response = $this->activity()->assertOk();

        $response->assertJsonPath('totals.completed', 4)
            ->assertJsonPath('totals.previous_completed', 2)
            ->assertJsonPath('top_services.0.name', 'Tresses longues')
            ->assertJsonPath('top_services.0.count', 3)
            ->assertJsonPath('top_services.0.share', 75)
            ->assertJsonPath('top_services.0.previous_count', 2)
            ->assertJsonPath('top_services.0.trend', 50)
            ->assertJsonPath('top_services.1.name', 'Manucure')
            ->assertJsonPath('top_services.1.trend', null);

        $this->assertEquals(60000, $response->json('top_services.0.revenue'));
    }

    public function test_only_completed_appointments_are_counted(): void
    {
        $this->done($this->braids, '2026-10-10 10:00');
        $this->done($this->braids, '2026-10-11 10:00', ['status' => 'cancelled']);
        $this->done($this->braids, '2026-10-12 10:00', ['status' => 'confirmed']);

        $this->activity()->assertOk()->assertJsonPath('totals.completed', 1);
    }

    public function test_busy_slots_group_by_weekday_and_day_part(): void
    {
        $this->done($this->braids, '2026-10-24 09:00'); // samedi matin
        $this->done($this->nails, '2026-10-24 10:30'); // samedi matin
        $this->done($this->nails, '2026-10-24 18:00'); // samedi soir

        $slots = collect($this->activity()->assertOk()->json('busy_slots'));

        $this->assertCount(21, $slots);
        $this->assertSame(2, $slots->firstWhere(fn ($s) => $s['day'] === 6 && $s['part'] === 'matin')['count']);
        $this->assertSame(1, $slots->firstWhere(fn ($s) => $s['day'] === 6 && $s['part'] === 'soir')['count']);
    }

    public function test_employee_activity_and_loyalty(): void
    {
        $fidele = User::factory()->create();
        $nouvelle = User::factory()->create();

        $this->done($this->braids, '2026-10-05 10:00', ['client_id' => $fidele->id]);
        $this->done($this->nails, '2026-10-20 10:00', ['client_id' => $fidele->id]);
        $this->done($this->nails, '2026-10-21 10:00', ['client_id' => $nouvelle->id]);

        $response = $this->activity()->assertOk();

        $response->assertJsonPath('employees.0.name', 'Awa')
            ->assertJsonPath('employees.0.count', 3)
            ->assertJsonPath('loyalty.clients', 2)
            ->assertJsonPath('loyalty.returning', 1)
            ->assertJsonPath('loyalty.rate', 50);
    }

    public function test_monthly_history_covers_thirteen_months(): void
    {
        $this->done($this->braids, '2026-03-15 10:00');

        $monthly = $this->activity('year')->assertOk()->json('monthly');

        $this->assertCount(13, $monthly);
        $this->assertSame('2025-10', $monthly[0]['month']);
        $this->assertSame('2026-10', $monthly[12]['month']);
        $this->assertTrue($monthly[12]['is_partial']);
        $this->assertSame(1, collect($monthly)->firstWhere('month', '2026-03')['total']);
    }

    public function test_no_forecast_without_enough_history(): void
    {
        $this->done($this->braids, '2026-09-15 10:00');
        $this->done($this->braids, '2026-10-05 10:00');

        $this->activity()->assertOk()
            ->assertJsonPath('forecast.expected', null)
            ->assertJsonPath('forecast.method', 'insufficient_data');
    }

    public function test_forecast_uses_moving_average_of_last_three_months(): void
    {
        // Juillet : 2, août : 4, septembre : 6 → moyenne 4 pour novembre.
        foreach (['2026-07-10', '2026-07-11'] as $day) {
            $this->done($this->braids, "{$day} 10:00");
        }
        foreach (range(1, 4) as $i) {
            $this->done($this->braids, "2026-08-0{$i} 10:00");
        }
        foreach (range(1, 6) as $i) {
            $this->done($this->braids, "2026-09-0{$i} 10:00");
        }

        $this->activity()->assertOk()
            ->assertJsonPath('forecast.month', '2026-11')
            ->assertJsonPath('forecast.expected', 4)
            ->assertJsonPath('forecast.method', 'moving_average')
            ->assertJsonPath('forecast.peak_month.month', '2026-09');
    }

    public function test_manager_never_sees_another_salons_activity(): void
    {
        $otherSalon = Salon::factory()->verified()->create();
        $otherService = Service::factory()->for($otherSalon)->create();
        Appointment::factory()->create([
            'salon_id'     => $otherSalon->id,
            'service_id'   => $otherService->id,
            'scheduled_at' => Carbon::parse('2026-10-20 10:00'),
            'status'       => 'completed',
        ]);

        // Même en tentant de forcer un salon dans l'URL, seul son propre salon est pris en compte.
        $this->actingAs($this->manager, 'api')
            ->getJson("/api/manager/stats/activity?period=month&salon_id={$otherSalon->id}")
            ->assertOk()
            ->assertJsonPath('totals.completed', 0);
    }

    public function test_client_cannot_access_activity_stats(): void
    {
        $client = User::factory()->create();
        $client->assignRole('client');

        $this->actingAs($client, 'api')->getJson('/api/manager/stats/activity')->assertForbidden();
    }

    public function test_invalid_period_is_rejected(): void
    {
        $this->activity('decade')->assertUnprocessable();
    }
}
