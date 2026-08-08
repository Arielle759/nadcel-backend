<?php

namespace App\Providers;

use App\Models\Appointment;
use App\Models\Salon;
use App\Policies\AppointmentPolicy;
use App\Policies\SalonPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Gate::policy(Salon::class, SalonPolicy::class);
        Gate::policy(Appointment::class, AppointmentPolicy::class);
    }
}
