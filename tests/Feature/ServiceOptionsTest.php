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

// Options d'un service (ex. longueur des tresses) : chacune a son prix et sa
// durée, la cliente en choisit une, le rendez-vous en garde une copie.
class ServiceOptionsTest extends TestCase
{
    use RefreshDatabase;

    private Salon $salon;
    private User $manager;
    private User $client;
    private Employee $employee;

    private const OPTIONS = [
        ['label' => 'Épaules', 'price' => 15000, 'duration' => 180],
        ['label' => 'Mi-dos', 'price' => 20000, 'duration' => 270],
        ['label' => 'Fesses', 'price' => 25000, 'duration' => 360],
        ['label' => 'Après les fesses', 'price' => 30000, 'duration' => 420],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Mail::fake();

        $this->salon = Salon::factory()->verified()->create(); // 9 h – 19 h
        $this->manager = $this->salon->manager;
        $this->manager->assignRole('gerant');
        $this->employee = Employee::factory()->for($this->salon)->create();
        $this->client = User::factory()->create();
        $this->client->assignRole('client');
    }

    private function braids(): Service
    {
        $res = $this->actingAs($this->manager, 'api')->post('/api/services', [
            'salon_id' => $this->salon->id,
            'name' => 'Box braids',
            'category' => 'Tresses et coiffures africaines',
            'price' => 0,
            'duration' => 15,
            'option_label' => 'Longueur',
            // envoyé comme le formulaire (multipart) : les options en JSON
            'options' => json_encode(self::OPTIONS),
        ], ['Accept' => 'application/json']);
        $res->assertCreated();
        $service = Service::findOrFail($res->json('id'));
        $service->employees()->attach($this->employee);
        return $service->fresh();
    }

    private function book(Service $service, ?int $optionId, string $time = '09:00', int $daysAhead = 2)
    {
        return $this->actingAs($this->client, 'api')->postJson('/api/appointments', array_filter([
            'salon_id' => $this->salon->id,
            'service_id' => $service->id,
            'service_option_id' => $optionId,
            'scheduled_at' => now()->addDays($daysAhead)->setTimeFromTimeString($time)->toDateTimeString(),
        ], fn ($v) => $v !== null));
    }

    public function test_manager_creates_service_with_options_and_price_starts_at_cheapest(): void
    {
        $service = $this->braids();

        $this->assertCount(4, $service->options);
        $this->assertSame(['Épaules', 'Mi-dos', 'Fesses', 'Après les fesses'], $service->options->pluck('label')->all());
        $this->assertEquals(15000, $service->price);
        $this->assertSame(180, $service->duration);
        $this->assertSame('Longueur', $service->option_label);

        $this->getJson('/api/services/' . $service->id)
            ->assertOk()
            ->assertJsonPath('options.1.label', 'Mi-dos')
            ->assertJsonPath('options.1.duration', 270);
    }

    public function test_booking_requires_an_option_when_the_service_has_some(): void
    {
        $service = $this->braids();

        $this->book($service, null)->assertUnprocessable()->assertJsonValidationErrors('service_option_id');
        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_booking_uses_price_duration_and_label_of_chosen_option(): void
    {
        $service = $this->braids();
        $midos = $service->options->firstWhere('label', 'Mi-dos');

        $this->book($service, $midos->id)->assertCreated();

        $appointment = Appointment::sole();
        $this->assertSame($midos->id, $appointment->service_option_id);
        $this->assertSame('Mi-dos', $appointment->option_label);
        $this->assertEquals(20000, $appointment->price);
        $this->assertSame(270, $appointment->duration);
    }

    public function test_long_option_must_end_before_closing_time(): void
    {
        $service = $this->braids();
        $long = $service->options->firstWhere('label', 'Après les fesses'); // 7 h

        // 13 h + 7 h = 20 h > fermeture à 19 h
        $this->book($service, $long->id, '13:00')->assertUnprocessable()->assertJsonValidationErrors('scheduled_at');
        // l'option la plus courte (3 h) passe au même horaire
        $short = $service->options->firstWhere('label', 'Épaules');
        $this->book($service, $short->id, '13:00')->assertCreated();
    }

    public function test_option_of_another_service_is_refused(): void
    {
        $service = $this->braids();
        $other = Service::factory()->for($this->salon)->create();
        $otherOption = $other->options()->create(['label' => 'X', 'price' => 1, 'duration' => 30]);

        $this->book($service, $otherOption->id)->assertUnprocessable()->assertJsonValidationErrors('service_option_id');
    }

    public function test_day_availability_follows_option_duration(): void
    {
        $service = $this->braids();
        $date = now()->addDays(2)->toDateString();
        $short = $service->options->firstWhere('label', 'Épaules');
        $long = $service->options->firstWhere('label', 'Après les fesses');

        $shortSlots = $this->getJson("/api/salons/{$this->salon->id}/availability/{$date}?service_id={$service->id}&option_id={$short->id}")->assertOk()->json();
        $longSlots = $this->getJson("/api/salons/{$this->salon->id}/availability/{$date}?service_id={$service->id}&option_id={$long->id}")->assertOk()->json();

        $this->assertGreaterThan(count($longSlots), count($shortSlots));
    }

    public function test_salon_can_enter_offline_booking_with_option(): void
    {
        $service = $this->braids();
        $fesses = $service->options->firstWhere('label', 'Fesses');

        $this->actingAs($this->manager, 'api')->postJson('/api/manager/appointments', [
            'salon_id' => $this->salon->id,
            'service_id' => $service->id,
            'service_option_id' => $fesses->id,
            'scheduled_at' => now()->addDays(3)->setTime(9, 0)->toDateTimeString(),
            'client_name' => 'Awa',
            'client_phone' => '771234567',
            'source' => 'whatsapp',
        ])->assertCreated()->assertJsonPath('option_label', 'Fesses');

        $this->assertEquals(25000, Appointment::sole()->price);
    }

    public function test_removing_options_keeps_existing_appointments_copy(): void
    {
        $service = $this->braids();
        $midos = $service->options->firstWhere('label', 'Mi-dos');
        $this->book($service, $midos->id)->assertCreated();

        $this->actingAs($this->manager, 'api')->post('/api/services/' . $service->id, [
            '_method' => 'PUT',
            'price' => 18000,
            'duration' => 240,
            'options' => '[]',
        ], ['Accept' => 'application/json'])->assertOk();

        $service->refresh();
        $this->assertCount(0, $service->options);
        $this->assertNull($service->option_label);
        $appointment = Appointment::sole();
        $this->assertNull($appointment->service_option_id);
        $this->assertSame('Mi-dos', $appointment->option_label);
        $this->assertEquals(20000, $appointment->price);
    }
}
