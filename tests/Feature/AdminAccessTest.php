<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::create(['name' => 'admin']);
        Role::create(['name' => 'gerant']);
        Role::create(['name' => 'client']);
    }

    public function test_client_cannot_access_admin_stats(): void
    {
        $client = User::factory()->create();
        $client->assignRole('client');

        $response = $this->actingAs($client, 'api')
                         ->getJson('/api/admin/stats');

        $response->assertStatus(403);
    }

    public function test_gerant_cannot_access_admin_stats(): void
    {
        $gerant = User::factory()->create();
        $gerant->assignRole('gerant');

        $response = $this->actingAs($gerant, 'api')
                         ->getJson('/api/admin/stats');

        $response->assertStatus(403);
    }

    public function test_admin_can_access_admin_stats(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $response = $this->actingAs($admin, 'api')
                         ->getJson('/api/admin/stats');

        $response->assertStatus(200);
    }
}
