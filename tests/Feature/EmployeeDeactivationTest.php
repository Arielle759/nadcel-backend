<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Employee;
use App\Models\Salon;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class EmployeeDeactivationTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;
    private Salon $salon;
    private Service $service;
    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager  = User::factory()->create();
        $this->salon    = Salon::factory()->for($this->manager, 'manager')->verified()->create();
        $this->service  = Service::factory()->for($this->salon)->create(['duration' => 60]);
        $this->employee = Employee::factory()->for($this->salon)->create();
        $this->service->employees()->attach($this->employee->id);
    }

    private function tomorrowSlots(): array
    {
        return $this->getJson("/api/salons/{$this->salon->id}/availability/"
            . Carbon::tomorrow()->toDateString() . "?service_id={$this->service->id}")
            ->assertOk()->json();
    }

    public function test_deactivated_employee_receives_no_more_appointments_and_can_be_reactivated(): void
    {
        $this->assertNotEmpty($this->tomorrowSlots());

        $this->actingAs($this->manager, 'api')
            ->patchJson("/api/employees/{$this->employee->id}/deactivate")
            ->assertOk()
            ->assertJsonPath('employee.is_active', false);

        $this->assertSame([], $this->tomorrowSlots());

        $client = User::factory()->create();
        $this->actingAs($client, 'api')->postJson('/api/appointments', [
            'salon_id'     => $this->salon->id,
            'service_id'   => $this->service->id,
            'scheduled_at' => Carbon::tomorrow()->setTime(10, 0)->toDateTimeString(),
        ])->assertStatus(409);

        $this->actingAs($this->manager, 'api')
            ->patchJson("/api/employees/{$this->employee->id}/activate")
            ->assertOk()
            ->assertJsonPath('employee.is_active', true);

        $this->assertNotEmpty($this->tomorrowSlots());
    }

    public function test_history_is_kept_and_upcoming_appointments_are_reported(): void
    {
        Appointment::factory()->create([
            'salon_id' => $this->salon->id, 'service_id' => $this->service->id,
            'employee_id' => $this->employee->id, 'status' => 'confirmed',
            'scheduled_at' => Carbon::tomorrow()->setTime(10, 0),
        ]);
        Appointment::factory()->create([
            'salon_id' => $this->salon->id, 'service_id' => $this->service->id,
            'employee_id' => $this->employee->id, 'status' => 'completed',
            'scheduled_at' => Carbon::yesterday()->setTime(10, 0),
        ]);

        $this->actingAs($this->manager, 'api')
            ->patchJson("/api/employees/{$this->employee->id}/deactivate")
            ->assertOk()
            ->assertJsonPath('upcoming_appointments', 1);

        $this->assertDatabaseHas('employees', ['id' => $this->employee->id, 'is_active' => false]);
        $this->assertSame(2, Appointment::where('employee_id', $this->employee->id)->count());
    }

    public function test_an_employee_can_no_longer_be_deleted(): void
    {
        $this->actingAs($this->manager, 'api')
            ->deleteJson("/api/employees/{$this->employee->id}")
            ->assertStatus(405);

        $this->assertDatabaseHas('employees', ['id' => $this->employee->id]);
    }

    public function test_another_manager_cannot_deactivate_the_employee(): void
    {
        $stranger = User::factory()->create();

        $this->actingAs($stranger, 'api')
            ->patchJson("/api/employees/{$this->employee->id}/deactivate")
            ->assertForbidden();

        $this->assertTrue($this->employee->fresh()->is_active);
    }
}
