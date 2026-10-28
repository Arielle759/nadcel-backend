<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SalonSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class IncidentHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_change_revokes_real_bearer_token_and_new_login_works(): void
    {
        $this->seed(RoleSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('admin');
        $token = auth('api')->login($user);
        Auth::forgetGuards();
        $this->withToken($token)->getJson('/api/admin/stats')->assertOk();

        $user->password = 'Changed!Password12345';
        $user->save();
        Auth::forgetGuards();
        $this->withToken($token)->getJson('/api/admin/stats')->assertUnauthorized();

        Auth::forgetGuards();
        $login = $this->withHeaders(['Authorization' => ''])->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'Changed!Password12345',
        ])->assertOk();
        Auth::forgetGuards();
        $this->withToken($login->json('token'))->getJson('/api/admin/stats')->assertOk();
    }

    public function test_legacy_token_without_credential_claim_is_rejected(): void
    {
        $this->seed(RoleSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('admin');
        $legacy = new class($user->id) implements \Tymon\JWTAuth\Contracts\JWTSubject {
            public function __construct(private int $id) {}
            public function getJWTIdentifier() { return $this->id; }
            public function getJWTCustomClaims() { return []; }
        };
        $token = app('tymon.jwt')->lockSubject(false)->fromUser($legacy);
        Auth::forgetGuards();
        $this->withToken($token)->getJson('/api/admin/stats')->assertUnauthorized();
    }

    public function test_reset_command_changes_only_existing_admin_and_rejects_weak_password(): void
    {
        $this->seed(RoleSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('admin');
        $original = $user->password;
        $this->artisan('admin:reset-password', ['email' => $user->email])
            ->expectsQuestion('New password (at least 16 characters)', 'password')
            ->expectsQuestion('Confirm new password', 'password')
            ->assertFailed();
        $this->assertSame($original, $user->fresh()->password);

        $this->artisan('admin:reset-password', ['email' => $user->email])
            ->expectsQuestion('New password (at least 16 characters)', 'Unique!Password234567')
            ->expectsQuestion('Confirm new password', 'Unique!Password234567')
            ->assertSuccessful();
        $this->assertTrue(Hash::check('Unique!Password234567', $user->fresh()->password));
        $client = User::factory()->create();
        $this->artisan('admin:reset-password', ['email' => $client->email])->assertFailed();
    }

    public function test_production_seeding_creates_roles_but_no_demo_accounts(): void
    {
        $this->app->instance('env', 'production');
        $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])->assertSuccessful();
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseHas('roles', ['name' => 'admin', 'guard_name' => 'api']);
    }

    public function test_direct_demo_seeder_is_blocked_in_production(): void
    {
        $this->app->instance('env', 'production');
        $this->expectException(\RuntimeException::class);
        (new SalonSeeder)->run();
    }
}
