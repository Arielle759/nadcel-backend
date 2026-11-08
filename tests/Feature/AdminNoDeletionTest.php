<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Review;
use App\Models\Salon;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

// L'administrateur ne supprime ni salon ni avis : un salon se suspend (son
// historique est conservé) et un avis authentique reste publié.
class AdminNoDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_neither_delete_a_salon_nor_a_review(): void
    {
        Role::create(['name' => 'admin']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $salon = Salon::factory()->for(User::factory()->create(), 'manager')->verified()->create();
        $client = User::factory()->create();
        $appointment = Appointment::factory()->state(['status' => 'completed'])->create([
            'client_id' => $client->id,
            'salon_id' => $salon->id,
            'service_id' => Service::factory()->for($salon)->create()->id,
        ]);
        $review = Review::create([
            'client_id' => $client->id,
            'appointment_id' => $appointment->id,
            'salon_id' => $salon->id,
            'rating' => 1,
            'comment' => 'Très déçue par la prestation.',
        ]);

        $this->actingAs($admin, 'api')->deleteJson("/api/admin/salons/{$salon->id}")->assertNotFound();
        $this->actingAs($admin, 'api')->deleteJson("/api/admin/reviews/{$review->id}")->assertNotFound();

        $this->assertNotSoftDeleted('salons', ['id' => $salon->id]);
        $this->assertDatabaseHas('reviews', ['id' => $review->id]);
    }
}
