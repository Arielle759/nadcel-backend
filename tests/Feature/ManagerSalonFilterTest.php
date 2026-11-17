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

// Tableau de bord gérant : choix d'un salon, prochain rendez-vous et demandes en attente.
class ManagerSalonFilterTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;
    private Salon $first;
    private Salon $second;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-18 10:00:00'));
        Role::create(['name' => 'gerant']);
        Role::create(['name' => 'client']);

        $this->manager = User::factory()->create();
        $this->manager->assignRole('gerant');
        $this->first = Salon::factory()->for($this->manager, 'manager')->verified()->create(['name' => 'Premier']);
        $this->second = Salon::factory()->for($this->manager, 'manager')->verified()->create(['name' => 'Second']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function appointment(Salon $salon, string $at, string $status, array $extra = []): Appointment
    {
        $service = Service::factory()->for($salon)->create(['name' => 'Soin '.$salon->name]);

        return Appointment::factory()->create(array_merge([
            'salon_id'     => $salon->id,
            'service_id'   => $service->id,
            'scheduled_at' => Carbon::parse($at),
            'status'       => $status,
            'price'        => 10000,
        ], $extra));
    }

    public function test_stats_cover_all_salons_by_default_and_one_salon_on_request(): void
    {
        $this->appointment($this->first, '2026-11-20 10:00', 'pending');
        $this->appointment($this->first, '2026-11-21 10:00', 'confirmed');
        $this->appointment($this->second, '2026-11-19 15:00', 'pending');

        $this->actingAs($this->manager, 'api')->getJson('/api/manager/stats')
            ->assertOk()
            ->assertJsonPath('upcoming', 3)
            ->assertJsonPath('pending', 2)
            ->assertJsonPath('next_appointment.salon', 'Second');

        $this->actingAs($this->manager, 'api')->getJson('/api/manager/stats?salon_id='.$this->first->id)
            ->assertOk()
            ->assertJsonPath('upcoming', 2)
            ->assertJsonPath('pending', 1)
            ->assertJsonPath('next_appointment.salon', 'Premier')
            ->assertJsonPath('next_appointment.service', 'Soin Premier');
    }

    public function test_next_appointment_shows_offline_client_name_and_ignores_past_or_cancelled(): void
    {
        $this->appointment($this->first, '2026-11-17 10:00', 'confirmed');
        $this->appointment($this->first, '2026-11-18 11:00', 'cancelled');
        $this->appointment($this->first, '2026-11-18 14:00', 'confirmed', ['client_id' => null, 'client_name' => 'Awa Ndiaye', 'source' => 'whatsapp']);

        $this->actingAs($this->manager, 'api')->getJson('/api/manager/stats?salon_id='.$this->first->id)
            ->assertOk()
            ->assertJsonPath('next_appointment.client_name', 'Awa Ndiaye')
            ->assertJsonPath('next_appointment.status', 'confirmed');
    }

    public function test_no_next_appointment_gives_null(): void
    {
        $this->actingAs($this->manager, 'api')->getJson('/api/manager/stats')
            ->assertOk()
            ->assertJsonPath('next_appointment', null)
            ->assertJsonPath('pending', 0);
    }

    public function test_activity_can_be_limited_to_one_salon(): void
    {
        $this->appointment($this->first, '2026-11-10 10:00', 'completed', ['payment_status' => 'paid', 'amount_paid' => 10000]);
        $this->appointment($this->second, '2026-11-11 10:00', 'completed', ['payment_status' => 'paid', 'amount_paid' => 10000]);

        $this->actingAs($this->manager, 'api')->getJson('/api/manager/stats/activity?period=month')
            ->assertOk()->assertJsonPath('totals.completed', 2);
        $this->actingAs($this->manager, 'api')->getJson('/api/manager/stats/activity?period=month&salon_id='.$this->second->id)
            ->assertOk()->assertJsonPath('totals.completed', 1);
    }

    public function test_manager_cannot_read_stats_of_another_managers_salon(): void
    {
        $other = User::factory()->create();
        $other->assignRole('gerant');
        $foreign = Salon::factory()->for($other, 'manager')->verified()->create();

        $this->actingAs($this->manager, 'api')->getJson('/api/manager/stats?salon_id='.$foreign->id)->assertForbidden();
        $this->actingAs($this->manager, 'api')->getJson('/api/manager/stats/activity?salon_id='.$foreign->id)->assertForbidden();
    }
}
