<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Employee;
use App\Models\Review;
use App\Models\Salon;
use App\Models\Service;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SecurityRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Mail::fake();
    }

    private function staffedSalon(): array
    {
        $salon = Salon::factory()->verified()->create();
        $service = Service::factory()->for($salon)->create(['duration' => 60]);
        $employee = Employee::factory()->for($salon)->create();
        $service->employees()->attach($employee);
        return [$salon, $service, $employee];
    }

    private function booking(Salon $salon, Service $service, array $extra = []): array
    {
        return array_merge([
            'salon_id' => $salon->id,
            'service_id' => $service->id,
            'scheduled_at' => now()->addDay()->setTime(10, 0)->toDateTimeString(),
        ], $extra);
    }

    public function test_clients_cannot_cancel_terminal_appointments(): void
    {
        $client = User::factory()->create();
        $client->assignRole('client');
        foreach (['completed', 'cancelled'] as $status) {
            $appointment = Appointment::factory()->create(['client_id' => $client->id, 'status' => $status]);
            $this->actingAs($client, 'api')->patchJson("/api/appointments/{$appointment->id}", [
                'status' => 'cancelled',
            ])->assertUnprocessable();
            $this->assertSame($status, $appointment->fresh()->status);
        }
        Mail::assertNothingOutgoing();
    }

    public function test_client_manager_can_manage_own_salon_and_see_personal_bookings(): void
    {
        [$salon, $service] = $this->staffedSalon();
        $manager = $salon->manager;
        $manager->assignRole(['client', 'gerant']);
        $appointment = Appointment::factory()->create(['salon_id' => $salon->id, 'service_id' => $service->id]);
        $this->actingAs($manager, 'api')->patchJson("/api/appointments/{$appointment->id}", [
            'status' => 'confirmed',
        ])->assertOk();
        $personal = Appointment::factory()->create(['client_id' => $manager->id]);
        $this->getJson("/api/appointments/{$personal->id}")->assertOk();
        $this->getJson('/api/appointments')->assertOk()->assertJsonFragment(['id' => $personal->id]);
        $this->patchJson("/api/appointments/{$personal->id}", ['status' => 'confirmed'])->assertForbidden();
        $this->patchJson("/api/appointments/{$personal->id}", ['status' => 'cancelled'])->assertOk();
    }

    public function test_unverified_suspended_and_inactive_services_cannot_be_booked(): void
    {
        [$salon, $service] = $this->staffedSalon();
        $this->actingAs(User::factory()->create(), 'api');
        $salon->is_verified = false;
        $salon->save();
        $this->postJson('/api/appointments', $this->booking($salon, $service))->assertNotFound();
        $salon->verify();
        $salon->suspend();
        $this->postJson('/api/appointments', $this->booking($salon, $service))->assertNotFound();
        $salon->reactivate();
        $service->update(['is_active' => false]);
        $this->postJson('/api/appointments', $this->booking($salon, $service))->assertNotFound();
        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_selected_employee_is_respected_and_busy_employee_is_not_substituted(): void
    {
        [$salon, $service, $first] = $this->staffedSalon();
        $chosen = Employee::factory()->for($salon)->create();
        $service->employees()->attach($chosen);
        $this->actingAs(User::factory()->create(), 'api');
        $payload = $this->booking($salon, $service, ['employee_id' => $chosen->id]);
        $this->postJson('/api/appointments', $payload)->assertCreated()->assertJsonPath('employee_id', $chosen->id);
        $this->postJson('/api/appointments', $payload)->assertConflict();
        unset($payload['employee_id']);
        $this->postJson('/api/appointments', $payload)->assertCreated()->assertJsonPath('employee_id', $first->id);
    }

    public function test_foreign_or_unqualified_employee_is_rejected(): void
    {
        [$salon, $service] = $this->staffedSalon();
        $this->actingAs(User::factory()->create(), 'api');
        foreach ([Employee::factory()->create(), Employee::factory()->for($salon)->create()] as $employee) {
            $this->postJson('/api/appointments', $this->booking($salon, $service, ['employee_id' => $employee->id]))
                ->assertUnprocessable()->assertJsonValidationErrors('employee_id');
        }
        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_timezone_offset_cannot_bypass_opening_hours(): void
    {
        [$salon, $service] = $this->staffedSalon();
        $this->actingAs(User::factory()->create(), 'api')->postJson('/api/appointments', $this->booking(
            $salon, $service, ['scheduled_at' => now()->addDays(2)->format('Y-m-d').'T10:00:00+12:00']
        ))->assertUnprocessable()->assertJsonValidationErrors('scheduled_at');
    }

    public function test_public_responses_exclude_private_contacts_and_inactive_services(): void
    {
        [$salon, $service] = $this->staffedSalon();
        $inactive = Service::factory()->for($salon)->create(['is_active' => false, 'category' => 'PrivateCategory']);
        $this->getJson('/api/salons')->assertOk()->assertJsonMissingPath('data.0.manager.email');
        $detail = $this->getJson("/api/salons/{$salon->id}")->assertOk();
        $detail->assertJsonMissingPath('manager.email')->assertJsonMissingPath('employees.0.phone')
            ->assertJsonMissingPath('employees.0.user_id')->assertJsonCount(1, 'services');
        $this->getJson("/api/services/{$service->id}")->assertOk()->assertJsonMissingPath('employees.0.phone');
        $this->getJson("/api/services/{$inactive->id}")->assertNotFound();
    }

    public function test_service_deletion_preserves_appointments_reviews_and_history(): void
    {
        [$salon, $service] = $this->staffedSalon();
        $appointment = Appointment::factory()->completed()->create(['salon_id' => $salon->id, 'service_id' => $service->id]);
        $review = Review::create([
            'client_id' => $appointment->client_id, 'salon_id' => $salon->id,
            'appointment_id' => $appointment->id, 'rating' => 5,
        ]);
        $this->actingAs($salon->manager, 'api')->deleteJson("/api/services/{$service->id}")->assertNoContent();
        $this->assertSoftDeleted('services', ['id' => $service->id]);
        $this->assertDatabaseHas('appointments', ['id' => $appointment->id, 'status' => 'completed']);
        $this->assertDatabaseHas('reviews', ['id' => $review->id]);
        $this->getJson("/api/appointments/{$appointment->id}")->assertOk()->assertJsonPath('service.id', $service->id);
        $this->getJson("/api/services/{$service->id}")->assertNotFound();
    }

    public function test_deleted_salon_history_remains_authorized_and_services_are_hidden(): void
    {
        [$salon, $service] = $this->staffedSalon();
        $appointment = Appointment::factory()->create(['salon_id' => $salon->id, 'service_id' => $service->id]);
        $manager = $salon->manager;
        $salon->delete();
        $this->actingAs($manager, 'api')->getJson("/api/appointments/{$appointment->id}")
            ->assertOk()->assertJsonPath('salon.id', $salon->id);
        $this->actingAs(User::factory()->create(), 'api')->getJson("/api/appointments/{$appointment->id}")->assertForbidden();
        $this->getJson("/api/services/{$service->id}")->assertNotFound();
    }

    public function test_invalid_availability_dates_and_mismatched_services_are_rejected(): void
    {
        [$salon, $service] = $this->staffedSalon();
        $this->getJson("/api/salons/{$salon->id}/availability/nonsense?service_id={$service->id}")
            ->assertUnprocessable()->assertJsonValidationErrors('date');
        $this->getJson("/api/salons/{$salon->id}/availability/2027-02-30?service_id={$service->id}")
            ->assertUnprocessable();
        $other = Service::factory()->create();
        foreach ([$other, $service] as $unavailable) {
            if ($unavailable->id === $service->id) {
                $service->update(['is_active' => false]);
            }
            $this->getJson("/api/salons/{$salon->id}/availability?month=2027-01&service_id={$unavailable->id}")->assertNotFound();
            $this->getJson("/api/salons/{$salon->id}/availability/2027-01-02?service_id={$unavailable->id}")->assertNotFound();
        }
    }

    public function test_database_rejects_duplicate_reviews(): void
    {
        $appointment = Appointment::factory()->completed()->create();
        $data = [
            'client_id' => $appointment->client_id, 'salon_id' => $appointment->salon_id,
            'appointment_id' => $appointment->id, 'rating' => 4,
        ];
        Review::create($data);
        $this->expectException(QueryException::class);
        Review::create($data);
    }

    public function test_registration_cannot_assign_admin_or_leave_account_when_role_is_missing(): void
    {
        $data = ['name' => 'Test', 'email' => 'security@example.test', 'password' => 'LongUniquePassword123!'];
        $this->postJson('/api/auth/register', $data + ['role' => 'admin'])->assertUnprocessable();
        $this->assertDatabaseMissing('users', ['email' => $data['email']]);
        \Spatie\Permission\Models\Role::where('name', 'client')->delete();
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->withoutExceptionHandling();
        try {
            $this->postJson('/api/auth/register', $data);
            $this->fail('Missing role should abort registration.');
        } catch (\Spatie\Permission\Exceptions\RoleDoesNotExist $exception) {
            $this->assertDatabaseMissing('users', ['email' => $data['email']]);
        }
    }

    public function test_real_client_token_cannot_access_admin_mutations(): void
    {
        [$salon] = $this->staffedSalon();
        $client = User::factory()->create();
        $client->assignRole('client');
        $token = auth('api')->login($client);
        Auth::forgetGuards();
        $this->withToken($token)->getJson('/api/admin/stats')->assertForbidden();
        foreach (['verify', 'reject', 'suspend', 'reactivate'] as $action) {
            Auth::forgetGuards();
            $this->withToken($token)->patchJson("/api/admin/salons/{$salon->id}/{$action}")->assertForbidden();
        }
        Auth::forgetGuards();
        $this->withToken($token)->deleteJson("/api/admin/salons/{$salon->id}")->assertForbidden();
        $this->assertDatabaseHas('salons', ['id' => $salon->id, 'deleted_at' => null, 'is_active' => true]);
    }

    public function test_opening_hours_must_use_known_days_and_close_after_open(): void
    {
        [$salon] = $this->staffedSalon();
        $this->actingAs($salon->manager, 'api');
        $this->putJson("/api/salons/{$salon->id}", ['opening_hours' => ['xyz' => ['open' => '09:00', 'close' => '17:00']]])
            ->assertUnprocessable();
        $this->putJson("/api/salons/{$salon->id}", ['opening_hours' => ['mon' => ['open' => '17:00', 'close' => '09:00']]])
            ->assertUnprocessable();
        $this->putJson("/api/salons/{$salon->id}", ['opening_hours' => ['mon' => ['open' => '09:00', 'close' => '17:00']]])
            ->assertOk();
    }
}
