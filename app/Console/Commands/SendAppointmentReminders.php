<?php

namespace App\Console\Commands;

use App\Mail\AppointmentReminder;
use App\Models\Appointment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendAppointmentReminders extends Command
{
    protected $signature   = 'appointments:send-reminders';
    protected $description = 'Send reminder emails for confirmed appointments starting within 2h to 24h';

    public function handle(): int
    {
        // Rappel environ 24 h avant. Un rendez-vous réservé ou confirmé moins de
        // 24 h à l'avance reçoit son rappel au passage suivant (toutes les 15 min),
        // s'il reste au moins 2 h : sinon il serait inutile.
        $from = now()->addHours(2);
        $to   = now()->addHours(24)->addMinutes(15);

        $appointments = Appointment::where('status', 'confirmed')
            ->whereNull('reminder_sent_at')
            // Les rendez-vous saisis par le salon sans compte n'ont pas d'e-mail.
            ->whereNotNull('client_id')
            ->whereBetween('scheduled_at', [$from, $to])
            ->with('client', 'salon', 'service')
            ->get();

        foreach ($appointments as $appointment) {
            Mail::to($appointment->client->email)->send(new AppointmentReminder($appointment));
            $appointment->update(['reminder_sent_at' => now()]);
        }

        $count = $appointments->count();
        Log::info("AppointmentReminders: {$count} reminder(s) sent.");
        $this->info("{$count} reminder(s) sent.");

        return Command::SUCCESS;
    }
}
