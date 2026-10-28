<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Review;
use App\Models\Salon;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ReviewSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function makeReview(): Review
    {
        $manager = User::factory()->create();
        $client  = User::factory()->create();
        $salon   = Salon::factory()->for($manager, 'manager')->verified()->create();
        $service = Service::factory()->for($salon)->create();
        $appt    = Appointment::factory()->state(['status' => 'completed'])->create([
            'client_id'  => $client->id,
            'salon_id'   => $salon->id,
            'service_id' => $service->id,
        ]);

        return Review::create([
            'client_id'      => $client->id,
            'appointment_id' => $appt->id,
            'salon_id'       => $salon->id,
            'rating'         => 5,
            'comment'        => 'Top',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Role::create(['name' => 'admin']);
        Role::create(['name' => 'gerant']);
        Role::create(['name' => 'client']);
    }

    public function test_client_cannot_list_all_reviews(): void
    {
        $this->makeReview();
        $otherClient = User::factory()->create();
        $otherClient->assignRole('client');

        $response = $this->actingAs($otherClient, 'api')->getJson('/api/reviews');

        $response->assertStatus(403);
    }

    public function test_admin_can_list_all_reviews(): void
    {
        $this->makeReview();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $response = $this->actingAs($admin, 'api')->getJson('/api/reviews');

        $response->assertStatus(200);
    }

    public function test_unrelated_client_cannot_view_another_clients_review(): void
    {
        $review = $this->makeReview();
        $otherClient = User::factory()->create();
        $otherClient->assignRole('client');

        $response = $this->actingAs($otherClient, 'api')->getJson("/api/reviews/{$review->id}");

        $response->assertStatus(403);
    }

    public function test_review_owner_can_view_own_review(): void
    {
        $review = $this->makeReview();
        $owner = User::find($review->client_id);

        $response = $this->actingAs($owner, 'api')->getJson("/api/reviews/{$review->id}");

        $response->assertStatus(200);
    }
}
