<?php

namespace Tests\Feature;

use App\Models\Salon;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SalonPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::create(['name' => 'admin']);
    }

    public function test_manager_can_update_own_salon(): void
    {
        $manager = User::factory()->create();
        $salon   = Salon::factory()->for($manager, 'manager')->create();

        $response = $this->actingAs($manager, 'api')
                         ->putJson("/api/salons/{$salon->id}", [
                             'name' => 'Updated Name',
                         ]);

        $response->assertStatus(200);
    }

    public function test_stranger_cannot_update_salon(): void
    {
        $manager  = User::factory()->create();
        $stranger = User::factory()->create();
        $salon    = Salon::factory()->for($manager, 'manager')->create();

        $response = $this->actingAs($stranger, 'api')
                         ->putJson("/api/salons/{$salon->id}", [
                             'name' => 'Hacked',
                         ]);

        $response->assertStatus(403);
    }

    public function test_admin_can_update_any_salon(): void
    {
        $manager = User::factory()->create();
        $admin   = User::factory()->create();
        $admin->assignRole('admin');
        $salon = Salon::factory()->for($manager, 'manager')->create();

        $response = $this->actingAs($admin, 'api')
                         ->putJson("/api/salons/{$salon->id}", [
                             'name' => 'Admin Updated',
                         ]);

        $response->assertStatus(200);
    }

    public function test_manager_can_delete_own_salon(): void
    {
        $manager = User::factory()->create();
        $salon   = Salon::factory()->for($manager, 'manager')->create();

        $response = $this->actingAs($manager, 'api')
                         ->deleteJson("/api/salons/{$salon->id}");

        $response->assertStatus(204);
    }

    public function test_stranger_cannot_delete_salon(): void
    {
        $manager  = User::factory()->create();
        $stranger = User::factory()->create();
        $salon    = Salon::factory()->for($manager, 'manager')->create();

        $response = $this->actingAs($stranger, 'api')
                         ->deleteJson("/api/salons/{$salon->id}");

        $response->assertStatus(403);
    }

    public function test_admin_can_verify_salon(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $salon = Salon::factory()->create();

        $response = $this->actingAs($admin, 'api')
                         ->patchJson("/api/admin/salons/{$salon->id}/verify");

        $response->assertStatus(200);
        $this->assertTrue($salon->fresh()->is_verified);
    }

    public function test_non_admin_cannot_verify_salon(): void
    {
        $user  = User::factory()->create();
        $salon = Salon::factory()->create();

        $response = $this->actingAs($user, 'api')
                         ->patchJson("/api/admin/salons/{$salon->id}/verify");

        $response->assertStatus(403);
    }

    public function test_admin_can_reject_salon(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $salon = Salon::factory()->create();

        $response = $this->actingAs($admin, 'api')
                         ->patchJson("/api/admin/salons/{$salon->id}/reject");

        $response->assertStatus(200);
        $this->assertFalse($salon->fresh()->is_active);
    }

    public function test_non_admin_cannot_reject_salon(): void
    {
        $user  = User::factory()->create();
        $salon = Salon::factory()->create();

        $response = $this->actingAs($user, 'api')
                         ->patchJson("/api/admin/salons/{$salon->id}/reject");

        $response->assertStatus(403);
    }
}
