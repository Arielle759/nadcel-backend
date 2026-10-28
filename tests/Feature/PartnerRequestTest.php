<?php

namespace Tests\Feature;

use App\Models\Salon;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

// Devenir partenaire : l'inscription crée toujours un client, la demande de salon
// reste en attente, et seul l'administrateur fait d'un demandeur un gérant.
class PartnerRequestTest extends TestCase
{
    use RefreshDatabase;

    private array $salonData = [
        'name' => 'Salon Teranga',
        'address' => '12 rue Carnot, Plateau',
        'city' => 'Dakar',
        'phone' => '771234567',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Role::create(['name' => 'admin']);
        Role::create(['name' => 'gerant']);
        Role::create(['name' => 'client']);
    }

    private function client(): User
    {
        $user = User::factory()->create();
        $user->assignRole('client');
        return $user;
    }

    public function test_registration_refuses_the_manager_role(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'Test',
            'email' => 'gerant@example.test',
            'password' => 'UnMotDePasseLong123',
            'role' => 'gerant',
        ])->assertUnprocessable();

        $this->assertDatabaseMissing('users', ['email' => 'gerant@example.test']);
    }

    public function test_salon_request_stays_pending_and_does_not_grant_manager_role(): void
    {
        $user = $this->client();

        $this->actingAs($user, 'api')->postJson('/api/salons', $this->salonData)->assertCreated();

        $salon = Salon::where('manager_id', $user->id)->firstOrFail();
        $this->assertFalse((bool) $salon->is_verified);
        $this->assertFalse($user->fresh()->hasRole('gerant'));
        $this->getJson("/api/salons/{$salon->id}")->assertOk(); // visible par son propriétaire
        $this->actingAs($user, 'api')->getJson('/api/manager/stats')->assertForbidden();
    }

    public function test_second_request_is_refused_while_first_is_pending(): void
    {
        $user = $this->client();
        $this->actingAs($user, 'api')->postJson('/api/salons', $this->salonData)->assertCreated();

        $this->actingAs($user, 'api')
            ->postJson('/api/salons', ['name' => 'Autre salon'] + $this->salonData)
            ->assertStatus(422);

        $this->assertSame(1, Salon::where('manager_id', $user->id)->count());
    }

    public function test_admin_validation_grants_manager_role(): void
    {
        $user = $this->client();
        $this->actingAs($user, 'api')->postJson('/api/salons', $this->salonData)->assertCreated();
        $salon = Salon::where('manager_id', $user->id)->firstOrFail();

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin, 'api')->patchJson("/api/admin/salons/{$salon->id}/verify")->assertOk();

        $this->assertTrue($user->fresh()->hasRole('gerant'));
    }

    public function test_my_salons_reports_request_status(): void
    {
        $user = $this->client();
        $pending = Salon::factory()->for($user, 'manager')->create(['is_verified' => false, 'is_active' => true]);
        $rejected = Salon::factory()->for($user, 'manager')->create(['is_verified' => false, 'is_active' => false]);
        Salon::factory()->create(); // salon d'un autre utilisateur

        $response = $this->actingAs($user, 'api')->getJson('/api/my-salons')->assertOk();

        $statuses = collect($response->json())->pluck('status', 'id');
        $this->assertCount(2, $statuses);
        $this->assertSame('pending', $statuses[$pending->id]);
        $this->assertSame('rejected', $statuses[$rejected->id]);
    }
}
