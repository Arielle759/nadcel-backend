<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Review;
use App\Models\Salon;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $client;
    private Salon $salon;
    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        $manager       = User::factory()->create();
        $this->client  = User::factory()->create();
        $this->salon   = Salon::factory()->for($manager, 'manager')->verified()->create();
        $this->service = Service::factory()->for($this->salon)->create();
    }

    private function makeAppointment(string $status, ?User $client = null): Appointment
    {
        return Appointment::factory()->state(['status' => $status])->create([
            'client_id'  => ($client ?? $this->client)->id,
            'salon_id'   => $this->salon->id,
            'service_id' => $this->service->id,
        ]);
    }

    public function test_client_can_review_own_completed_appointment(): void
    {
        $appt = $this->makeAppointment('completed');

        $response = $this->actingAs($this->client, 'api')
                         ->postJson('/api/reviews', [
                             'appointment_id' => $appt->id,
                             'rating'         => 5,
                             'comment'        => 'Excellent service!',
                         ]);

        $response->assertStatus(201);
    }

    public function test_client_cannot_review_incomplete_appointment(): void
    {
        $appt = $this->makeAppointment('confirmed');

        $response = $this->actingAs($this->client, 'api')
                         ->postJson('/api/reviews', [
                             'appointment_id' => $appt->id,
                             'rating'         => 4,
                         ]);

        $response->assertStatus(422);
    }

    public function test_client_cannot_review_another_clients_appointment(): void
    {
        $otherClient = User::factory()->create();
        $appt        = $this->makeAppointment('completed', $this->client);

        $response = $this->actingAs($otherClient, 'api')
                         ->postJson('/api/reviews', [
                             'appointment_id' => $appt->id,
                             'rating'         => 3,
                         ]);

        $response->assertStatus(403);
    }

    public function test_duplicate_review_is_rejected(): void
    {
        $appt = $this->makeAppointment('completed');

        Review::create([
            'client_id'      => $this->client->id,
            'appointment_id' => $appt->id,
            'salon_id'       => $this->salon->id,
            'rating'         => 4,
            'comment'        => 'First review',
        ]);

        $response = $this->actingAs($this->client, 'api')
                         ->postJson('/api/reviews', [
                             'appointment_id' => $appt->id,
                             'rating'         => 5,
                             'comment'        => 'Duplicate review',
                         ]);

        $response->assertStatus(409);
    }
}
