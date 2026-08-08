<?php

namespace Tests\Feature;

use App\Models\Salon;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ServicePolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::create(['name' => 'admin']);
    }

    public function test_manager_can_create_service_for_own_salon(): void
    {
        $manager = User::factory()->create();
        $salon   = Salon::factory()->for($manager, 'manager')->create();

        $response = $this->actingAs($manager, 'api')
                         ->postJson('/api/services', [
                             'salon_id'    => $salon->id,
                             'name'        => 'Coupe Homme',
                             'price'       => 20.00,
                             'duration'    => 30,
                             'category'    => 'Coiffure',
                         ]);

        $response->assertStatus(201);
    }

    public function test_stranger_cannot_create_service(): void
    {
        $manager  = User::factory()->create();
        $stranger = User::factory()->create();
        $salon    = Salon::factory()->for($manager, 'manager')->create();

        $response = $this->actingAs($stranger, 'api')
                         ->postJson('/api/services', [
                             'salon_id'    => $salon->id,
                             'name'        => 'Hacked Service',
                             'price'       => 10.00,
                             'duration'    => 30,
                             'category'    => 'Coiffure',
                         ]);

        $response->assertStatus(403);
    }

    public function test_admin_cannot_create_service(): void
    {
        $manager = User::factory()->create();
        $admin   = User::factory()->create();
        $admin->assignRole('admin');
        $salon = Salon::factory()->for($manager, 'manager')->create();

        $response = $this->actingAs($admin, 'api')
                         ->postJson('/api/services', [
                             'salon_id'    => $salon->id,
                             'name'        => 'Admin Service',
                             'price'       => 30.00,
                             'duration'    => 45,
                             'category'    => 'Soin',
                         ]);

        $response->assertStatus(403);
    }

    public function test_manager_can_update_own_service(): void
    {
        $manager = User::factory()->create();
        $salon   = Salon::factory()->for($manager, 'manager')->create();
        $service = Service::factory()->for($salon)->create();

        $response = $this->actingAs($manager, 'api')
                         ->putJson("/api/services/{$service->id}", [
                             'name' => 'Updated Service',
                         ]);

        $response->assertStatus(200);
    }

    public function test_stranger_cannot_update_service(): void
    {
        $manager  = User::factory()->create();
        $stranger = User::factory()->create();
        $salon    = Salon::factory()->for($manager, 'manager')->create();
        $service  = Service::factory()->for($salon)->create();

        $response = $this->actingAs($stranger, 'api')
                         ->putJson("/api/services/{$service->id}", [
                             'name' => 'Hacked',
                         ]);

        $response->assertStatus(403);
    }

    public function test_admin_cannot_update_service(): void
    {
        $manager = User::factory()->create();
        $admin   = User::factory()->create();
        $admin->assignRole('admin');
        $salon   = Salon::factory()->for($manager, 'manager')->create();
        $service = Service::factory()->for($salon)->create();

        $response = $this->actingAs($admin, 'api')
                         ->putJson("/api/services/{$service->id}", [
                             'name' => 'Admin Updated',
                         ]);

        $response->assertStatus(403);
    }

    public function test_manager_can_delete_own_service(): void
    {
        $manager = User::factory()->create();
        $salon   = Salon::factory()->for($manager, 'manager')->create();
        $service = Service::factory()->for($salon)->create();

        $response = $this->actingAs($manager, 'api')
                         ->deleteJson("/api/services/{$service->id}");

        $response->assertStatus(204);
    }

    public function test_stranger_cannot_delete_service(): void
    {
        $manager  = User::factory()->create();
        $stranger = User::factory()->create();
        $salon    = Salon::factory()->for($manager, 'manager')->create();
        $service  = Service::factory()->for($salon)->create();

        $response = $this->actingAs($stranger, 'api')
                         ->deleteJson("/api/services/{$service->id}");

        $response->assertStatus(403);
    }

    public function test_admin_cannot_delete_service(): void
    {
        $manager = User::factory()->create();
        $admin   = User::factory()->create();
        $admin->assignRole('admin');
        $salon   = Salon::factory()->for($manager, 'manager')->create();
        $service = Service::factory()->for($salon)->create();

        $response = $this->actingAs($admin, 'api')
                         ->deleteJson("/api/services/{$service->id}");

        $response->assertStatus(403);
    }
}
