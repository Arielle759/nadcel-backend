<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Employee;
use App\Models\Salon;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PaymentTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;
    private Salon $salon;
    private Service $service;
    private Employee $employee;
    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'admin']);

        $this->manager  = User::factory()->create();
        $this->salon    = Salon::factory()->for($this->manager, 'manager')->verified()->create();
        $this->service  = Service::factory()->for($this->salon)->create();
        $this->employee = Employee::factory()->for($this->salon)->create();
        $this->client   = User::factory()->create();
    }

    private function makeAppointment(array $overrides = []): Appointment
    {
        return Appointment::factory()->create(array_merge([
            'client_id'  => $this->client->id,
            'salon_id'   => $this->salon->id,
            'service_id' => $this->service->id,
            'status'     => 'confirmed',
        ], $overrides));
    }

    public function test_manager_can_mark_appointment_as_paid(): void
    {
        $appt = $this->makeAppointment();

        $response = $this->actingAs($this->manager, 'api')
                         ->patchJson("/api/appointments/{$appt->id}/pay");

        $response->assertStatus(200);
        $this->assertEquals('paid', $appt->fresh()->payment_status);
    }

    public function test_paying_is_idempotent(): void
    {
        $appt = $this->makeAppointment(['payment_status' => 'paid']);

        $response = $this->actingAs($this->manager, 'api')
                         ->patchJson("/api/appointments/{$appt->id}/pay");

        $response->assertStatus(200);
    }

    public function test_cannot_pay_cancelled_appointment(): void
    {
        $appt = $this->makeAppointment(['status' => 'cancelled']);

        $response = $this->actingAs($this->manager, 'api')
                         ->patchJson("/api/appointments/{$appt->id}/pay");

        $response->assertStatus(422);
    }

    public function test_stranger_cannot_pay_appointment(): void
    {
        $stranger = User::factory()->create();
        $appt     = $this->makeAppointment();

        $response = $this->actingAs($stranger, 'api')
                         ->patchJson("/api/appointments/{$appt->id}/pay");

        $response->assertStatus(403);
    }

    public function test_admin_cannot_pay_appointment(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $appt = $this->makeAppointment();

        $response = $this->actingAs($admin, 'api')
                         ->patchJson("/api/appointments/{$appt->id}/pay");

        $response->assertStatus(403);
    }
}
