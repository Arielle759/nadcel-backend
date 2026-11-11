<?php

namespace Tests\Feature;

use App\Models\Salon;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Suggestions de la barre de recherche : salons visibles uniquement,
// sans tenir compte des accents ni de la casse, au plus 5 par groupe.
class SearchSuggestionTest extends TestCase
{
    use RefreshDatabase;

    private function salon(array $attributes, bool $visible = true): Salon
    {
        $factory = Salon::factory()->for(User::factory()->create(), 'manager');

        return ($visible ? $factory->verified() : $factory)->create($attributes + ($visible ? [] : ['is_verified' => false]));
    }

    public function test_suggests_salons_services_and_places_ignoring_accents(): void
    {
        $ariel = $this->salon(['name' => 'Ariel Coiffure', 'address' => '12 rue 10, Sacré-Cœur', 'city' => 'Dakar']);
        Service::factory()->for($ariel)->create(['name' => 'Tresses box braids', 'category' => 'Coiffure', 'is_active' => true]);
        $this->salon(['name' => 'Céramique Spa', 'address' => 'Route des Almadies', 'city' => 'Dakar']);

        $this->getJson('/api/search/suggestions?q=ar')->assertOk()
            ->assertJsonPath('salons.0.name', 'Ariel Coiffure')
            ->assertJsonPath('salons.0.area', 'Sacré-Cœur, Dakar');

        $this->getJson('/api/search/suggestions?q=ceram')->assertOk()
            ->assertJsonPath('salons.0.name', 'Céramique Spa');

        $this->getJson('/api/search/suggestions?q=COIF')->assertOk()
            ->assertJsonPath('services', ['Coiffure'])
            ->assertJsonPath('salons.0.name', 'Ariel Coiffure');

        $this->getJson('/api/search/suggestions?q=sacre')->assertOk()
            ->assertJsonPath('places', ['Sacré-Cœur']);
    }

    public function test_hidden_salons_and_inactive_services_are_never_suggested(): void
    {
        $pending = $this->salon(['name' => 'Arôme Beauté'], visible: false);
        Service::factory()->for($pending)->create(['name' => 'Argan soin', 'is_active' => true]);
        $visible = $this->salon(['name' => 'Studio Nour']);
        Service::factory()->for($visible)->create(['name' => 'Ancien service', 'is_active' => false]);

        $this->getJson('/api/search/suggestions?q=ar')->assertOk()
            ->assertJsonPath('salons', [])
            ->assertJsonPath('services', []);
        $this->getJson('/api/search/suggestions?q=ancien')->assertOk()
            ->assertJsonPath('services', []);
    }

    public function test_word_start_matches_come_first_and_groups_are_limited_to_five(): void
    {
        foreach (['Bel Ami', 'Atelier Belle', 'Belle Peau', 'Ô Belles', 'Belinda', 'Bella Spa', 'Rebelle'] as $name) {
            $this->salon(['name' => $name]);
        }

        $names = collect($this->getJson('/api/search/suggestions?q=bel')->assertOk()->json('salons'))->pluck('name');

        $this->assertCount(5, $names);
        $this->assertNotContains('Rebelle', $names->all());
    }

    public function test_a_single_letter_only_matches_the_start_of_a_word(): void
    {
        $this->salon(['name' => 'Ariel Coiffure']);
        $this->salon(['name' => 'Studio Nour']);

        $names = collect($this->getJson('/api/search/suggestions?q=a')->assertOk()->json('salons'))->pluck('name');

        $this->assertSame(['Ariel Coiffure'], $names->all());
    }

    public function test_query_is_required(): void
    {
        $this->getJson('/api/search/suggestions')->assertStatus(422);
    }
}
