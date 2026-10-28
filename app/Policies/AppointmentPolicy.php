<?php

namespace App\Policies;

use App\Models\Appointment;
use App\Models\User;

class AppointmentPolicy
{
    public function view(User $user, Appointment $appointment): bool
    {
        return $user->hasRole('admin')
            || $appointment->client_id === $user->id
            || $appointment->salon?->manager_id === $user->id;
    }

    public function manage(User $user, Appointment $appointment): bool
    {
        return $user->hasRole('admin') || $appointment->salon?->manager_id === $user->id;
    }

    public function pay(User $user, Appointment $appointment): bool
    {
        return $appointment->salon?->manager_id === $user->id;
    }
}
