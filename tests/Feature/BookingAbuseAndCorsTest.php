<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Employee;
use App\Models\Salon;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

// Protections contre le blocage d'un agenda et contre les appels d'autres sites.
class BookingAbuseAndCorsTest extends TestCase
{
    use RefreshDatabase;

    private User $client;
    private Salon $salon;
    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Carbon::setTestNow('2026-11-04 08:00:00');
        Role::create(['name' => 'gerant']);
        Role::create(['name' => 'client']);
        $manager = User::factory()->create();
        $manager->assignRole('gerant');
        $this->salon = Salon::factory()->for($manager, 'manager')->verified()->create();
        $this->service = Service::factory()->for($this->salon)->create(['duration' => 60]);
        $this->service->employees()->attach(Employee::factory()->for($this->salon)->create());
        $this->client = User::factory()->create();
        $this->client->assignRole('client');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function book(string $at)
    {
        return $this->actingAs($this->client, 'api')->postJson('/api/appointments', [
            'salon_id' => $this->salon->id,
            'service_id' => $this->service->id,
            'scheduled_at' => $at,
        ]);
    }

    public function test_a_client_cannot_hold_more_than_three_pending_requests_in_a_salon(): void
    {
        $this->book('2026-11-05 10:00:00')->assertCreated();
        $this->book('2026-11-05 12:00:00')->assertCreated();
        $this->book('2026-11-05 14:00:00')->assertCreated();

        $this->book('2026-11-05 16:00:00')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('scheduled_at');
        $this->assertSame(3, Appointment::where('client_id', $this->client->id)->count());

        // Une demande confirmée par le salon libère une place.
        Appointment::where('client_id', $this->client->id)->first()->update(['status' => 'confirmed']);
        $this->book('2026-11-05 16:00:00')->assertCreated();
    }

    public function test_online_booking_is_rate_limited(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->book('2026-11-03 10:00:00')->assertUnprocessable(); // date passée : refusée, mais comptée
        }

        $this->book('2026-11-05 10:00:00')->assertStatus(429);
    }

    public function test_only_the_nadcel_site_may_call_the_api_from_a_browser(): void
    {
        config(['cors.allowed_origins' => ['https://nadcel.vercel.app']]);

        $this->withHeaders(['Origin' => 'https://nadcel.vercel.app'])->getJson('/api/health')
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', 'https://nadcel.vercel.app');

        // L'en-tête renvoyé ne correspond pas à l'autre site : son navigateur
        // bloque la lecture de la réponse.
        $other = $this->withHeaders(['Origin' => 'https://site-pirate.example'])->getJson('/api/health');
        $this->assertNotSame('https://site-pirate.example', $other->headers->get('Access-Control-Allow-Origin'));
        $this->assertNotSame('*', $other->headers->get('Access-Control-Allow-Origin'));
    }
}
