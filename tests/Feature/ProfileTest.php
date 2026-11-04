<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Role::create(['name' => 'client']);
        $this->user = User::factory()->create(['name' => 'Awa Diop', 'password' => 'ancien-mot-de-passe']);
        $this->user->assignRole('client');
    }

    public function test_a_user_updates_name_and_phone(): void
    {
        $this->actingAs($this->user, 'api')
            ->patchJson('/api/profile', ['name' => 'Awa Ndiaye', 'phone' => '+221 77 123 45 67'])
            ->assertOk()
            ->assertJsonPath('name', 'Awa Ndiaye')
            ->assertJsonPath('phone', '+221 77 123 45 67');

        $this->assertDatabaseHas('users', ['id' => $this->user->id, 'name' => 'Awa Ndiaye']);
    }

    public function test_email_and_role_cannot_be_changed_through_the_profile(): void
    {
        $email = $this->user->email;

        $this->actingAs($this->user, 'api')
            ->patchJson('/api/profile', ['name' => 'Awa', 'email' => 'pirate@test.sn', 'roles' => ['admin']])
            ->assertOk();

        $this->assertSame($email, $this->user->fresh()->email);
        $this->assertFalse($this->user->fresh()->hasRole('admin'));
    }

    public function test_invalid_phone_is_rejected_in_french(): void
    {
        $this->actingAs($this->user, 'api')
            ->patchJson('/api/profile', ['name' => 'Awa', 'phone' => 'pas un numéro'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.phone.0', 'Le numéro de téléphone n\'est pas valide.');
    }

    public function test_password_change_requires_the_current_password(): void
    {
        $this->actingAs($this->user, 'api')
            ->putJson('/api/profile/password', [
                'current_password' => 'mauvais',
                'password' => 'nouveau-mot-de-passe',
                'password_confirmation' => 'nouveau-mot-de-passe',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('current_password');

        $this->assertTrue(Hash::check('ancien-mot-de-passe', $this->user->fresh()->password));
    }

    public function test_password_change_returns_a_new_token_and_revokes_the_old_one(): void
    {
        $oldToken = auth('api')->login($this->user);
        Auth::forgetGuards();

        $response = $this->withToken($oldToken)->putJson('/api/profile/password', [
            'current_password' => 'ancien-mot-de-passe',
            'password' => 'nouveau-mot-de-passe',
            'password_confirmation' => 'nouveau-mot-de-passe',
        ])->assertOk();

        $newToken = $response->json('token');
        $this->assertNotEmpty($newToken);
        $this->assertTrue(Hash::check('nouveau-mot-de-passe', $this->user->fresh()->password));

        // L'ancienne session (autre appareil) est déconnectée, la nouvelle fonctionne.
        $this->freshRequestState();
        $this->withToken($oldToken)->getJson('/api/profile')->assertUnauthorized();
        $this->freshRequestState();
        $this->withToken($newToken)->getJson('/api/profile')->assertOk();
    }

    // En production, chaque requête repart de zéro. Dans un test, le service JWT
    // garde le dernier jeton en mémoire : on le vide pour simuler une requête neuve.
    private function freshRequestState(): void
    {
        Auth::forgetGuards();
        app('tymon.jwt')->unsetToken();
    }

    public function test_short_or_unconfirmed_password_is_rejected(): void
    {
        $this->actingAs($this->user, 'api')
            ->putJson('/api/profile/password', [
                'current_password' => 'ancien-mot-de-passe',
                'password' => 'court',
                'password_confirmation' => 'autre',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');
    }

    public function test_guests_cannot_reach_the_profile(): void
    {
        $this->getJson('/api/profile')->assertUnauthorized();
        $this->patchJson('/api/profile', ['name' => 'X'])->assertUnauthorized();
    }
}
