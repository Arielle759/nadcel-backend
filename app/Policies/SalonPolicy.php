<?php

namespace App\Policies;

use App\Models\Salon;
use App\Models\User;

class SalonPolicy
{
    public function verify(User $user, Salon $salon): bool
    {
        return $user->hasRole('admin');
    }

    public function update(User $user, Salon $salon): bool
    {
        return $user->id === $salon->manager_id || $user->hasRole('admin');
    }

    public function delete(User $user, Salon $salon): bool
    {
        return $user->id === $salon->manager_id || $user->hasRole('admin');
    }

    public function manageServices(User $user, Salon $salon): bool
    {
        return $user->id === $salon->manager_id;
    }

    public function manageEmployees(User $user, Salon $salon): bool
    {
        return $user->id === $salon->manager_id || $user->hasRole('admin');
    }
}
