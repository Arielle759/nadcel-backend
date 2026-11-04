<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;

// Crée le premier administrateur d'une base neuve (production), sans mot de
// passe connu d'avance : il est saisi de façon masquée dans le terminal.
class CreateAdmin extends Command
{
    protected $signature = 'admin:create {email} {name}';
    protected $description = 'Create an administrator account with a password typed interactively';

    public function handle(): int
    {
        $data = ['email' => $this->argument('email'), 'name' => $this->argument('name')];
        $identity = Validator::make($data, [
            'email' => 'required|email|unique:users,email',
            'name'  => 'required|string|max:255',
        ]);
        if ($identity->fails()) {
            foreach ($identity->errors()->all() as $error) {
                $this->error($error);
            }
            return self::FAILURE;
        }

        $password = $this->secret('Password (at least 16 characters)');
        $confirmation = $this->secret('Confirm password');
        $validator = Validator::make([
            'password' => $password,
            'password_confirmation' => $confirmation,
        ], [
            'password' => ['required', 'string', 'confirmed', Password::min(16)->mixedCase()->numbers()->symbols()],
        ]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }
            return self::FAILURE;
        }

        Role::findOrCreate('admin', 'api');
        $user = User::create([...$data, 'password' => $password]);
        $user->assignRole('admin');

        $this->info("Administrator {$user->email} created.");
        return self::SUCCESS;
    }
}
