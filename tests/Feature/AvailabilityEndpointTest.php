<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Salon;
use App\Models\SalonClosure;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AvailabilityEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeStaffedSalon(array $salonAttributes = [], int $duration = 60): array
    {
        $manager  = User::factory()->create();
        $salon    = Salon::factory()->for($manager, 'manager')->verified()->create($salonAttributes);
        $service  = Service::factory()->for($salon)->create(['duration' => $duration]);
        $empUser  = User::factory()->create();
        $employee = Employee::factory()->for($salon)->create(['user_id' => $empUser->id]);
        $service->employees()->attach($employee->id);

        return [$salon, $service];
    }

    public function test_availability_is_hidden_for_unverified_salon(): void
    {
        $manager = User::factory()->create();
        $salon   = Salon::factory()->for($manager, 'manager')->create(); // is_verified = false
        $service = Service::factory()->for($salon)->create();

        $this->getJson("/api/salons/{$salon->id}/availability?service_id={$service->id}&month=" . Carbon::now()->format('Y-m'))
             ->assertStatus(404);

        $this->getJson("/api/salons/{$salon->id}/availability/" . Carbon::tomorrow()->toDateString() . "?service_id={$service->id}")
             ->assertStatus(404);
    }

    public function test_todays_already_passed_hours_are_excluded(): void
    {
        [$salon, $service] = $this->makeStaffedSalon();

        Carbon::setTestNow(Carbon::today()->setTime(14, 0));

        $response = $this->getJson(
            "/api/salons/{$salon->id}/availability/" . Carbon::today()->toDateString() . "?service_id={$service->id}"
        );

        $response->assertStatus(200);
        $slots = $response->json();

        $this->assertNotContains('09:00', $slots);
        $this->assertContains('14:00', $slots);
    }

    public function test_custom_opening_hours_limit_available_slots(): void
    {
        $tomorrow = Carbon::tomorrow();
        $dayKey   = strtolower($tomorrow->format('D'));

        [$salon, $service] = $this->makeStaffedSalon([
            'opening_hours' => [$dayKey => ['open' => '09:00', 'close' => '11:00']],
        ]);

        $response = $this->getJson(
            "/api/salons/{$salon->id}/availability/{$tomorrow->toDateString()}?service_id={$service->id}"
        );

        $slots = $response->json();

        $this->assertContains('09:00', $slots);
        $this->assertNotContains('18:00', $slots); // le créneau légacy 9h-19h ne doit plus s'appliquer
    }

    public function test_salon_closure_blocks_the_whole_day(): void
    {
        [$salon, $service] = $this->makeStaffedSalon();
        $tomorrow = Carbon::tomorrow();

        SalonClosure::create([
            'salon_id'    => $salon->id,
            'closed_date' => $tomorrow->toDateString(),
            'reason'      => 'Jour férié',
        ]);

        $response = $this->getJson(
            "/api/salons/{$salon->id}/availability/{$tomorrow->toDateString()}?service_id={$service->id}"
        );

        $response->assertStatus(200)->assertJson([]);
    }

    public function test_booking_outside_opening_hours_is_rejected(): void
    {
        $client   = User::factory()->create();
        $tomorrow = Carbon::tomorrow();
        $dayKey   = strtolower($tomorrow->format('D'));

        [$salon, $service] = $this->makeStaffedSalon([
            'opening_hours' => [$dayKey => ['open' => '09:00', 'close' => '11:00']],
        ]);

        $response = $this->actingAs($client, 'api')->postJson('/api/appointments', [
            'salon_id'     => $salon->id,
            'service_id'   => $service->id,
            'scheduled_at' => $tomorrow->copy()->setTime(15, 0)->toDateTimeString(),
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('scheduled_at');
    }
}
