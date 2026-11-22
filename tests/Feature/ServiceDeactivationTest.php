<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Employee;
use App\Models\Salon;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

// Un service n'est jamais supprimé : il est désactivé puis, si besoin,
// réactivé, comme un employé. Son historique reste intact.
class ServiceDeactivationTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;
    private Salon $salon;
    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = User::factory()->create();
        $this->salon   = Salon::factory()->for($this->manager, 'manager')->verified()->create();
        $this->service = Service::factory()->for($this->salon)->create(['duration' => 60]);
        $employee      = Employee::factory()->for($this->salon)->create();
        $this->service->employees()->attach($employee->id);
    }

    private function publicServiceIds(): array
    {
        return array_column(
            $this->getJson("/api/services?salon_id={$this->salon->id}")->assertOk()->json('data'),
            'id'
        );
    }

    public function test_deactivated_service_is_hidden_and_not_bookable_then_can_be_reactivated(): void
    {
        $this->assertContains($this->service->id, $this->publicServiceIds());

        $this->actingAs($this->manager, 'api')
            ->patchJson("/api/services/{$this->service->id}/deactivate")
            ->assertOk()
            ->assertJsonPath('service.is_active', false);

        $this->app['auth']->forgetGuards();
        $this->assertNotContains($this->service->id, $this->publicServiceIds());
        $this->getJson("/api/services/{$this->service->id}")->assertNotFound();

        $client = User::factory()->create();
        $this->actingAs($client, 'api')->postJson('/api/appointments', [
            'salon_id'     => $this->salon->id,
            'service_id'   => $this->service->id,
            'scheduled_at' => Carbon::tomorrow()->setTime(10, 0)->toDateTimeString(),
        ])->assertNotFound();

        $this->actingAs($this->manager, 'api')
            ->patchJson("/api/services/{$this->service->id}/activate")
            ->assertOk()
            ->assertJsonPath('service.is_active', true);

        $this->app['auth']->forgetGuards();
        $this->assertContains($this->service->id, $this->publicServiceIds());
    }

    public function test_manager_lists_deactivated_services_but_others_cannot(): void
    {
        $this->service->forceFill(['is_active' => false])->save();
        $url = "/api/services?salon_id={$this->salon->id}&include_inactive=1";

        $ids = array_column($this->actingAs($this->manager, 'api')->getJson($url)->assertOk()->json('data'), 'id');
        $this->assertContains($this->service->id, $ids);

        $this->actingAs(User::factory()->create(), 'api')->getJson($url)->assertForbidden();
    }

    public function test_deactivation_keeps_history_and_reports_upcoming_appointments(): void
    {
        $past = Appointment::factory()->completed()->create([
            'salon_id' => $this->salon->id, 'service_id' => $this->service->id,
        ]);
        Appointment::factory()->create([
            'salon_id' => $this->salon->id, 'service_id' => $this->service->id,
            'status' => 'confirmed', 'scheduled_at' => now()->addDays(2),
        ]);

        $this->actingAs($this->manager, 'api')
            ->patchJson("/api/services/{$this->service->id}/deactivate")
            ->assertOk()
            ->assertJsonPath('upcoming_appointments', 1);

        $this->assertDatabaseHas('services', ['id' => $this->service->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('appointments', ['id' => $past->id, 'status' => 'completed']);
    }

    public function test_service_can_no_longer_be_deleted(): void
    {
        $this->actingAs($this->manager, 'api')
            ->deleteJson("/api/services/{$this->service->id}")
            ->assertStatus(405);

        $this->assertDatabaseHas('services', ['id' => $this->service->id, 'deleted_at' => null]);
    }
}
