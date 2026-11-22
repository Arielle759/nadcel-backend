<?php

namespace Tests\Feature;

use App\Models\Salon;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// L'état d'un salon se déduit de trois attributs : une seule méthode le calcule.
class SalonStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_combination_gives_the_expected_state(): void
    {
        $manager = User::factory()->create();
        $cas = [
            'pending'     => [false, true, null],
            'verified'    => [true, true, null],
            'rejected'    => [false, false, null],
            'suspended'   => [true, false, null],
            'deactivated' => [true, true, now()],
        ];

        foreach ($cas as $attendu => [$verifie, $actif, $desactivation]) {
            $salon = Salon::factory()->for($manager, 'manager')->create();
            $salon->forceFill([
                'is_verified' => $verifie,
                'is_active' => $actif,
                'deactivated_at' => $desactivation,
            ])->save();

            $this->assertSame($attendu, $salon->fresh()->status(), $attendu);
            $this->assertSame($attendu === 'verified', $salon->fresh()->isPublic());
        }
    }
}
