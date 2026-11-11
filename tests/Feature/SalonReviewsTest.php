<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Review;
use App\Models\Salon;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Avis affichés sur la page publique d'un salon : tous les avis du salon,
// quelle que soit la note, sans donnée personnelle de l'autrice.
class SalonReviewsTest extends TestCase
{
    use RefreshDatabase;

    private function review(Salon $salon, int $rating, ?string $comment, string $clientName = 'Awa Diop', string $serviceName = 'Tresses'): Review
    {
        $client = User::factory()->create(['name' => $clientName]);
        $service = Service::factory()->for($salon)->create(['name' => $serviceName]);
        $appointment = Appointment::factory()->state(['status' => 'completed'])->create([
            'client_id' => $client->id,
            'salon_id' => $salon->id,
            'service_id' => $service->id,
        ]);

        return Review::create([
            'client_id' => $client->id,
            'appointment_id' => $appointment->id,
            'salon_id' => $salon->id,
            'rating' => $rating,
            'comment' => $comment,
        ]);
    }

    public function test_visitor_reads_all_reviews_of_a_visible_salon(): void
    {
        $salon = Salon::factory()->for(User::factory()->create(), 'manager')->verified()->create();
        $other = Salon::factory()->for(User::factory()->create(), 'manager')->verified()->create();

        $this->review($salon, 5, 'Accueil chaleureux et tresses impeccables.', 'Awa Diop Ndiaye', 'Box braids');
        $this->review($salon, 2, 'Attente trop longue.');
        $this->review($salon, 4, '   ');
        $this->review($other, 5, 'Autre salon.');

        $response = $this->getJson("/api/salons/{$salon->id}/reviews")->assertOk();

        $response->assertJsonCount(3, 'data')->assertJsonPath('total', 3);
        $reviews = collect($response->json('data'));
        $this->assertEqualsCanonicalizing([5, 2, 4], $reviews->pluck('rating')->all());
        $first = $reviews->firstWhere('rating', 5);
        $this->assertSame('Awa D.', $first['author']);
        $this->assertSame('Box braids', $first['service']);
        $this->assertNull($reviews->firstWhere('rating', 4)['comment']);
        $this->assertArrayNotHasKey('client', $first);
        $this->assertStringNotContainsString('@', $response->getContent());
    }

    public function test_reviews_of_a_hidden_salon_are_not_public(): void
    {
        $pending = Salon::factory()->for(User::factory()->create(), 'manager')->create(['is_verified' => false]);
        $this->review($pending, 5, 'Très bon salon.');

        $this->getJson("/api/salons/{$pending->id}/reviews")->assertNotFound();
    }

    public function test_reviews_are_paginated_by_ten(): void
    {
        $salon = Salon::factory()->for(User::factory()->create(), 'manager')->verified()->create();
        foreach (range(1, 12) as $i) {
            $this->review($salon, 4, "Avis numéro $i.");
        }

        $this->getJson("/api/salons/{$salon->id}/reviews")->assertOk()
            ->assertJsonCount(10, 'data')->assertJsonPath('last_page', 2);
        $this->getJson("/api/salons/{$salon->id}/reviews?page=2")->assertOk()
            ->assertJsonCount(2, 'data');
    }
}
