<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Review;
use App\Models\Salon;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalonSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_filters_by_city_via_q(): void
    {
        Salon::factory()->verified()->create(['city' => 'Dakar']);
        Salon::factory()->verified()->create(['city' => 'Thiès']);

        $response = $this->getJson('/api/salons?q=Dakar');

        $response->assertStatus(200)->assertJsonCount(1, 'data');
        $this->assertSame('Dakar', $response->json('data.0.city'));
    }

    public function test_search_filters_by_text_query_case_insensitively(): void
    {
        Salon::factory()->verified()->create(['name' => 'Salon Excellence']);
        Salon::factory()->verified()->create(['name' => 'Autre Salon']);

        $response = $this->getJson('/api/salons?q=excellence');

        $response->assertStatus(200)->assertJsonCount(1, 'data');
        $this->assertSame('Salon Excellence', $response->json('data.0.name'));
    }

    public function test_search_filters_by_service_category_or_name_via_q(): void
    {
        $salonA = Salon::factory()->verified()->create();
        Service::factory()->for($salonA)->create(['category' => 'Massage']);
        $salonB = Salon::factory()->verified()->create();
        Service::factory()->for($salonB)->create(['category' => 'Coiffure']);

        $response = $this->getJson('/api/salons?q=massage');

        $response->assertStatus(200)->assertJsonCount(1, 'data');
        $this->assertSame($salonA->id, $response->json('data.0.id'));
    }

    public function test_unverified_salon_never_appears_in_public_search(): void
    {
        $manager = User::factory()->create();
        Salon::factory()->for($manager, 'manager')->create(['city' => 'Dakar']); // unverified

        $response = $this->getJson('/api/salons?q=Dakar');

        $response->assertStatus(200)->assertJsonCount(0, 'data');
    }

    public function test_per_page_can_be_raised_above_default(): void
    {
        Salon::factory()->count(15)->verified()->create();

        $response = $this->getJson('/api/salons?per_page=20');

        $response->assertStatus(200)->assertJsonCount(15, 'data');
    }

    public function test_average_rating_and_review_count_are_exposed_on_salon_detail(): void
    {
        $manager = User::factory()->create();
        $client  = User::factory()->create();
        $salon   = Salon::factory()->for($manager, 'manager')->verified()->create();
        $service = Service::factory()->for($salon)->create();

        foreach ([5, 3] as $rating) {
            $appt = Appointment::factory()->state(['status' => 'completed'])->create([
                'client_id'  => User::factory()->create()->id,
                'salon_id'   => $salon->id,
                'service_id' => $service->id,
            ]);
            Review::create([
                'client_id'      => $appt->client_id,
                'appointment_id' => $appt->id,
                'salon_id'       => $salon->id,
                'rating'         => $rating,
            ]);
        }

        $response = $this->getJson("/api/salons/{$salon->id}");

        $response->assertStatus(200);
        $this->assertSame(2, $response->json('reviews_count'));
        $this->assertEquals(4, $response->json('reviews_avg_rating'));
    }
}
