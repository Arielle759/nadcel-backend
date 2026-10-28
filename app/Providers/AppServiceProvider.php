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
        \Illuminate\Support\Facades\Auth::extend('password-bound-jwt', function ($app, $name, array $config) {
            $guard = new \App\Auth\PasswordBoundJWTGuard(
                $app['tymon.jwt'],
                $app['auth']->createUserProvider($config['provider']),
                $app['request']
            );
            $app->refresh('request', $guard, 'setRequest');
            return $guard;
        });
        Gate::policy(Salon::class, SalonPolicy::class);
        Gate::policy(Appointment::class, AppointmentPolicy::class);
    }
}
