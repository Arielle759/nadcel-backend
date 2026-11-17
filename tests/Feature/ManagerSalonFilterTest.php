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

    public function test_today_schedule_and_rating(): void
    {
        $this->appointment($this->first, '2026-11-18 09:00', 'confirmed');
        $this->appointment($this->first, '2026-11-18 15:00', 'pending', ['client_id' => null, 'client_name' => 'Fatou', 'source' => 'phone']);
        $this->appointment($this->first, '2026-11-18 16:00', 'cancelled');
        $this->appointment($this->first, '2026-11-17 11:00', 'completed');
        $done = $this->appointment($this->first, '2026-11-10 11:00', 'completed');
        \App\Models\Review::factory()->create(['appointment_id' => $done->id, 'salon_id' => $this->first->id, 'client_id' => $done->client_id, 'rating' => 4]);

        $this->actingAs($this->manager, 'api')->getJson('/api/manager/stats?salon_id='.$this->first->id)
            ->assertOk()
            ->assertJsonPath('today.count', 2)
            ->assertJsonPath('today.yesterday', 1)
            ->assertJsonPath('today.list.1.client_name', 'Fatou')
            ->assertJsonPath('rating.average', 4)
            ->assertJsonPath('rating.count', 1);
    }

    public function test_clients_list_groups_accounts_and_walk_ins_per_salon(): void
    {
        $client = User::factory()->create(['name' => 'Awa Diop']);
        $client->assignRole('client');
        $this->appointment($this->first, '2026-11-01 10:00', 'completed', ['client_id' => $client->id, 'payment_status' => 'paid', 'amount_paid' => 15000]);
        $this->appointment($this->first, '2026-11-10 10:00', 'completed', ['client_id' => $client->id, 'payment_status' => 'paid', 'amount_paid' => 5000]);
        $this->appointment($this->first, '2026-11-25 10:00', 'confirmed', ['client_id' => $client->id]);
        $this->appointment($this->first, '2026-11-05 10:00', 'completed', ['client_id' => null, 'client_name' => 'Fatou Sow', 'client_phone' => '77 123 45 67', 'source' => 'phone']);
        $this->appointment($this->first, '2026-11-12 10:00', 'cancelled', ['client_id' => null, 'client_name' => 'Annulée', 'client_phone' => '700000000', 'source' => 'phone']);
        $this->appointment($this->second, '2026-11-06 10:00', 'completed', ['client_id' => null, 'client_name' => 'Autre salon', 'source' => 'walk_in']);

        $res = $this->actingAs($this->manager, 'api')->getJson('/api/manager/clients?salon_id='.$this->first->id)->assertOk();
        $res->assertJsonPath('total', 2)
            ->assertJsonPath('data.0.name', 'Awa Diop')
            ->assertJsonPath('data.0.visits', 2)
            ->assertJsonPath('data.0.appointments', 3)
            ->assertJsonPath('data.0.spent', 20000)
            ->assertJsonPath('data.1.name', 'Fatou Sow')
            ->assertJsonPath('data.1.has_account', false);
        $this->assertNotNull($res->json('data.0.next_visit'));

        $this->actingAs($this->manager, 'api')->getJson('/api/manager/clients?search=771234')
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.name', 'Fatou Sow');
        $this->actingAs($this->manager, 'api')->getJson('/api/manager/clients')->assertOk()->assertJsonPath('total', 3);
    }
}
