<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Review;
use App\Models\Salon;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Avis affichés sur l'accueil : uniquement de vrais avis positifs commentés,
// sur des salons visibles, sans donnée personnelle de l'autrice.
class ReviewHighlightsTest extends TestCase
{
    use RefreshDatabase;

    private function review(Salon $salon, int $rating, ?string $comment, string $clientName = 'Awa Diop'): Review
    {
        $client = User::factory()->create(['name' => $clientName]);
        $service = Service::factory()->for($salon)->create();
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

    public function test_only_positive_commented_reviews_of_visible_salons_are_returned(): void
    {
        $manager = User::factory()->create();
        $visible = Salon::factory()->for($manager, 'manager')->verified()->create();
        $pending = Salon::factory()->for($manager, 'manager')->create(['is_verified' => false]);

        $kept = $this->review($visible, 5, 'Accueil chaleureux et tresses impeccables.', 'Awa Diop Ndiaye');
        $this->review($visible, 2, 'Attente beaucoup trop longue ce jour-là.');
        $this->review($visible, 5, null);
        $this->review($visible, 4, 'Bien.');
        $this->review($pending, 5, 'Très bon salon, je recommande vivement.');

        $response = $this->getJson('/api/reviews/highlights')->assertOk();

        $response->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $kept->id)
            ->assertJsonPath('data.0.author', 'Awa D.')
            ->assertJsonPath('data.0.salon.id', $visible->id);

        $this->assertArrayNotHasKey('client', $response->json('data.0'));
        $this->assertStringNotContainsString('@', $response->getContent());
    }

    public function test_highlights_are_public_and_empty_without_reviews(): void
    {
        $this->getJson('/api/reviews/highlights')->assertOk()->assertExactJson(['data' => []]);
    }
}
