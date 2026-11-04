<?php

namespace Tests\Feature;

use App\Models\Salon;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

// Photos des prestations : formats courants acceptés jusqu'à 5 Mo, enregistrées
// sur le disque « public » et servies sous /storage/...
class ImageUploadTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;
    private Salon $salon;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Role::create(['name' => 'gerant']);
        $this->manager = User::factory()->create();
        $this->manager->assignRole('gerant');
        $this->salon = Salon::factory()->for($this->manager, 'manager')->verified()->create();
    }

    private function payload(UploadedFile $image): array
    {
        return [
            'salon_id' => $this->salon->id,
            'name'     => 'Tresses',
            'price'    => 10000,
            'duration' => 60,
            'category' => 'Coiffure',
            'image'    => $image,
        ];
    }

    public function test_a_four_megabyte_phone_photo_is_accepted_and_stored(): void
    {
        $response = $this->actingAs($this->manager, 'api')
            ->post('/api/services', $this->payload(UploadedFile::fake()->image('salon.jpg', 1600, 1200)->size(4000)), ['Accept' => 'application/json'])
            ->assertCreated();

        $path = $response->json('image');
        $this->assertStringStartsWith('/storage/services/', $path);
        Storage::disk('public')->assertExists(substr($path, strlen('/storage/')));
    }

    public function test_a_photo_over_five_megabytes_is_rejected(): void
    {
        $this->actingAs($this->manager, 'api')
            ->post('/api/services', $this->payload(UploadedFile::fake()->image('enorme.jpg')->size(6000)), ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('image');
    }

    public function test_formats_other_than_jpg_png_webp_are_rejected(): void
    {
        $this->actingAs($this->manager, 'api')
            ->post('/api/services', $this->payload(UploadedFile::fake()->image('anime.gif')), ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('image');
    }
}
