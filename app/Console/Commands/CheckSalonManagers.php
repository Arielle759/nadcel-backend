<?php

namespace App\Console\Commands;

use App\Models\Salon;
use App\Models\User;
use Illuminate\Console\Command;

class CheckSalonManagers extends Command
{
    protected $signature = 'salons:check-managers';
    protected $description = 'Affiche pour chaque salon le manager associé et ses rôles Spatie';

    public function handle(): void
    {
        $salons = Salon::with(['manager.roles'])->get();

        foreach ($salons as $salon) {
            /** @var User|null $manager */
            $manager = $salon->manager;

            $email = $manager ? $manager->email : 'AUCUN USER';
            $roles = $manager && $manager->roles->isNotEmpty()
                ? $manager->roles->pluck('name')->join(', ')
                : 'AUCUN ROLE';

            $this->line("[{$salon->id}] - {$salon->name} -> {$email} [{$roles}]");
        }
    }
}
