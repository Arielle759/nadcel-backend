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
    protected $description = 'Send reminder emails to clients with confirmed appointments in ~24h';

    public function handle(): int
    {
        $target = now()->addHours(24);
        $from   = $target->copy()->subMinutes(15);
        $to     = $target->copy()->addMinutes(15);

        $appointments = Appointment::where('status', 'confirmed')
            ->whereNull('reminder_sent_at')
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
