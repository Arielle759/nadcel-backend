<?php

namespace Tests\Feature;

use App\Models\Salon;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

// Gestion des utilisateurs par l'administrateur : liste, recherche, suspension.
class AdminUsersTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $client;
    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->admin = User::factory()->create(['name' => 'Admin Nadcel']);
        $this->admin->assignRole('admin');
        $this->client = User::factory()->create(['name' => 'Awa Diop', 'email' => 'awa@example.test', 'password' => Hash::make('MotDePasse-Solide-1')]);
        $this->client->assignRole('client');
        $this->manager = User::factory()->create(['name' => 'Moussa Fall']);
        $this->manager->assignRole('gerant');
        Salon::factory()->for($this->manager, 'manager')->verified()->create();
    }

    public function test_admin_lists_searches_and_filters_users(): void
    {
        $this->actingAs($this->admin, 'api')->getJson('/api/admin/users')
            ->assertOk()->assertJsonPath('total', 3);

        $this->actingAs($this->admin, 'api')->getJson('/api/admin/users?search=AWA')
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.email', 'awa@example.test');

        $this->actingAs($this->admin, 'api')->getJson('/api/admin/users?role=gerant')
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.salons_count', 1);
    }

    public function test_suspended_user_can_no_longer_log_in_or_use_a_token(): void
    {
        $token = auth('api')->login($this->client);

        $this->actingAs($this->admin, 'api')->patchJson("/api/admin/users/{$this->client->id}/suspend")
            ->assertOk()->assertJsonPath('id', $this->client->id);
        $this->assertNotNull($this->client->fresh()->suspended_at);

        $this->postJson('/api/auth/login', ['email' => 'awa@example.test', 'password' => 'MotDePasse-Solide-1'])->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/appointments')->assertForbidden();

        $this->actingAs($this->admin, 'api')->patchJson("/api/admin/users/{$this->client->id}/reactivate")->assertOk();
        $this->assertNull($this->client->fresh()->suspended_at);
        $this->postJson('/api/auth/login', ['email' => 'awa@example.test', 'password' => 'MotDePasse-Solide-1'])->assertOk();
    }

    public function test_admin_cannot_suspend_himself_or_another_admin(): void
    {
        $other = User::factory()->create();
        $other->assignRole('admin');

        $this->actingAs($this->admin, 'api')->patchJson("/api/admin/users/{$this->admin->id}/suspend")->assertUnprocessable();
        $this->actingAs($this->admin, 'api')->patchJson("/api/admin/users/{$other->id}/suspend")->assertUnprocessable();
        $this->assertNull($other->fresh()->suspended_at);
    }

    public function test_only_admin_can_manage_users(): void
    {
        $this->actingAs($this->manager, 'api')->getJson('/api/admin/users')->assertForbidden();
        $this->actingAs($this->client, 'api')->patchJson("/api/admin/users/{$this->manager->id}/suspend")->assertForbidden();
    }

    public function test_dashboard_lists_recent_appointments_and_cities(): void
    {
        $this->actingAs($this->admin, 'api')->getJson('/api/admin/stats')
            ->assertOk()
            ->assertJsonStructure(['recent_appointments', 'cities' => [['city', 'count', 'share']]]);
    }
}
