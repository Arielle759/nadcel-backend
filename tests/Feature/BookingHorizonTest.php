<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Employee;
use App\Models\Salon;
use App\Models\Service;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

// Règle métier : une cliente réserve en ligne au plus 3 mois à l'avance ;
// le gérant voit toutes les demandes à venir, même lointaines.
class BookingHorizonTest extends TestCase
{
    use RefreshDatabase;

    private Salon $salon;
    private Service $service;
    private User $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Mail::fake();

        $this->salon = Salon::factory()->verified()->create();
        $this->service = Service::factory()->for($this->salon)->create(['duration' => 60]);
        $employee = Employee::factory()->for($this->salon)->create();
        $this->service->employees()->attach($employee);

        $this->client = User::factory()->create();
        $this->client->assignRole('client');
    }

    private function book(int $daysAhead)
    {
        return $this->actingAs($this->client, 'api')->postJson('/api/appointments', [
            'salon_id' => $this->salon->id,
            'service_id' => $this->service->id,
            'scheduled_at' => now()->addDays($daysAhead)->setTime(10, 0)->toDateTimeString(),
        ]);
    }

    public function test_client_can_book_up_to_three_months_ahead(): void
    {
        $this->book(Appointment::MAX_ADVANCE_DAYS)->assertCreated();
    }

    public function test_client_cannot_book_beyond_three_months(): void
    {
        $this->book(Appointment::MAX_ADVANCE_DAYS + 1)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('scheduled_at');

        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_manager_sees_far_future_requests_with_open_ended_window(): void
    {
        $far = Appointment::factory()->create([
            'client_id' => $this->client->id,
            'salon_id' => $this->salon->id,
            'service_id' => $this->service->id,
            'status' => 'pending',
            'scheduled_at' => now()->addMonths(8),
        ]);

        $manager = $this->salon->manager;
        $manager->assignRole('gerant');

        $this->actingAs($manager, 'api')
            ->getJson('/api/appointments?scope=salon&per_page=500&from='.urlencode(now()->subDays(30)->toIso8601String()))
            ->assertOk()
            ->assertJsonFragment(['id' => $far->id]);
    }
}
