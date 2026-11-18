<?php

namespace App\Console\Commands;

use App\Models\Appointment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Détection automatique des absences : un rendez-vous confirmé dont la cliente
 * n'a pas été signalée arrivée 30 minutes après l'heure prévue passe en
 * « Absente ». Le reste du créneau redevient libre pour d'autres réservations.
 * Le gérant peut corriger une erreur en clôturant le rendez-vous.
 *
 * Seuls les rendez-vous des dernières 24 heures sont examinés : les anciens
 * rendez-vous non clôturés (oubli du salon) ne sont pas requalifiés après coup.
 */
class MarkNoShows extends Command
{
    protected $signature   = 'appointments:mark-no-shows';
    protected $description = 'Marque « Absente » les rendez-vous confirmés sans arrivée 30 min après l\'heure prévue';

    public function handle(): int
    {
        $now = now();

        $count = Appointment::where('status', 'confirmed')
            ->where('scheduled_at', '<=', $now->copy()->subMinutes(Appointment::NO_SHOW_AFTER_MINUTES))
            ->where('scheduled_at', '>=', $now->copy()->subDay())
            ->update(['status' => 'no_show', 'no_show_detected_at' => $now]);

        Log::info("MarkNoShows: {$count} absence(s) détectée(s).");
        $this->info("{$count} absence(s) détectée(s).");

        return Command::SUCCESS;
    }
}
