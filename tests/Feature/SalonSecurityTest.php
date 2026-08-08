<?php

namespace Tests\Feature;

use App\Models\Salon;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SalonSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::create(['name' => 'admin']);
        Role::create(['name' => 'gerant']);
        Role::create(['name' => 'client']);
    }

    public function test_unauthenticated_visitor_gets_404_for_unverified_salon(): void
    {
        $manager = User::factory()->create();
        $salon   = Salon::factory()->for($manager, 'manager')->create();
        // is_verified = false par défaut (verify() non appelé)

        $response = $this->getJson("/api/salons/{$salon->id}");

        $response->assertStatus(404);
    }
}
