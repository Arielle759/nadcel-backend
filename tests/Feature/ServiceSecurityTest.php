<?php

namespace Tests\Feature;

use App\Models\Salon;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ServiceSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::create(['name' => 'admin']);
        Role::create(['name' => 'gerant']);
        Role::create(['name' => 'client']);
    }

    public function test_client_cannot_create_service_on_any_salon(): void
    {
        $client = User::factory()->create();
        $client->assignRole('client');
        $manager = User::factory()->create();
        $salon   = Salon::factory()->for($manager, 'manager')->create();

        $response = $this->actingAs($client, 'api')
                         ->postJson('/api/services', [
                             'salon_id' => $salon->id,
                             'name'     => 'Service Interdit',
                             'price'    => 5000,
                             'duration' => 30,
                             'category' => 'Coiffure',
                         ]);

        $response->assertStatus(403);
    }

    public function test_gerant_cannot_create_service_on_another_managers_salon(): void
    {
        $gerant  = User::factory()->create();
        $gerant->assignRole('gerant');
        $manager = User::factory()->create();
        $salon   = Salon::factory()->for($manager, 'manager')->create();

        $response = $this->actingAs($gerant, 'api')
                         ->postJson('/api/services', [
                             'salon_id' => $salon->id,
                             'name'     => 'Service Interdit',
                             'price'    => 5000,
                             'duration' => 30,
                             'category' => 'Coiffure',
                         ]);

        $response->assertStatus(403);
    }

    public function test_gerant_can_create_service_on_own_salon(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('gerant');
        $salon   = Salon::factory()->for($manager, 'manager')->create();

        $response = $this->actingAs($manager, 'api')
                         ->postJson('/api/services', [
                             'salon_id' => $salon->id,
                             'name'     => 'Coupe Femme',
                             'price'    => 6000,
                             'duration' => 45,
                             'category' => 'Coiffure',
                         ]);

        $response->assertStatus(201);
    }

    public function test_services_of_unverified_salon_are_hidden_from_public(): void
    {
        $manager = User::factory()->create();
        $salon   = Salon::factory()->for($manager, 'manager')->create(); // is_verified = false
        $service = \App\Models\Service::factory()->for($salon)->create();

        $this->getJson("/api/services/{$service->id}")->assertStatus(404);
        $this->getJson('/api/services?salon_id=' . $salon->id)
             ->assertJsonCount(0, 'data');
    }

    public function test_manager_can_still_see_own_unverified_salons_services(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('gerant');
        $salon   = Salon::factory()->for($manager, 'manager')->create(); // is_verified = false
        $service = \App\Models\Service::factory()->for($salon)->create();

        $response = $this->actingAs($manager, 'api')->getJson("/api/services/{$service->id}");

        $response->assertStatus(200);
    }

    public function test_manager_can_still_list_own_unverified_salons_services(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('gerant');
        $salon   = Salon::factory()->for($manager, 'manager')->create(); // is_verified = false
        \App\Models\Service::factory()->for($salon)->create();

        $response = $this->actingAs($manager, 'api')
                         ->getJson('/api/services?salon_id=' . $salon->id);

        $response->assertStatus(200)->assertJsonCount(1, 'data');
    }
}
