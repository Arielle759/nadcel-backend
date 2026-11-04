<?php

namespace Tests\Feature;

use App\Models\Salon;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

// Un membre de l'équipe n'a pas de compte : aucun utilisateur n'est créé.
class EmployeeWithoutAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_adding_a_team_member_creates_no_user_account(): void
    {
        Role::create(['name' => 'gerant']);
        $manager = User::factory()->create();
        $manager->assignRole('gerant');
        $salon = Salon::factory()->for($manager, 'manager')->verified()->create();
        $service = Service::factory()->for($salon)->create();
        $usersBefore = User::count();

        $this->actingAs($manager, 'api')->postJson('/api/employees', [
            'salon_id' => $salon->id,
            'name' => 'Fatou Sy',
            'phone' => '77 000 00 00',
            'service_ids' => [$service->id],
        ])->assertCreated()->assertJsonPath('user_id', null)->assertJsonPath('services.0.id', $service->id);

        $this->assertSame($usersBefore, User::count());
    }
}
