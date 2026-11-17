<?php

namespace Tests\Feature;

use App\Models\Salon;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryListTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_categories_of_online_salons_with_salon_counts(): void
    {
        $a = Salon::factory()->create(['is_verified' => true, 'is_active' => true]);
        $b = Salon::factory()->create(['is_verified' => true, 'is_active' => true]);
        $pending = Salon::factory()->create(['is_verified' => false, 'is_active' => true]);

        Service::factory()->create(['salon_id' => $a->id, 'category' => 'Coiffure']);
        Service::factory()->create(['salon_id' => $a->id, 'category' => 'Coiffure']);
        Service::factory()->create(['salon_id' => $b->id, 'category' => 'Coiffure']);
        Service::factory()->create(['salon_id' => $b->id, 'category' => 'Massage']);
        // Prestation désactivée et salon pas encore validé : ignorés.
        Service::factory()->create(['salon_id' => $a->id, 'category' => 'Maquillage', 'is_active' => false]);
        Service::factory()->create(['salon_id' => $pending->id, 'category' => 'Onglerie']);

        $this->getJson('/api/categories')
            ->assertOk()
            ->assertExactJson([
                ['name' => 'Coiffure', 'salons_count' => 2],
                ['name' => 'Massage', 'salons_count' => 1],
            ]);
    }
}
