<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class ResetAdminPassword extends Command
{
    protected $signature = 'admin:reset-password {email}';
    protected $description = 'Reset an existing administrator password and revoke their previous JWTs';

    public function handle(): int
    {
        $user = User::role('admin', 'api')->where('email', $this->argument('email'))->first();
        if (!$user) {
            $this->error('Administrator not found. No account was changed.');
            return self::FAILURE;
        }

        $this->info('Environment: '.app()->environment());
        $password = $this->secret('New password (at least 16 characters)');
        $confirmation = $this->secret('Confirm new password');
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

        if (Hash::check($password, $user->password)) {
            $this->error('Choose a different password.');
            return self::FAILURE;
        }

        $user->password = $password;
        $user->save();
        $this->info('Password changed. Previous JWTs will be rejected by this application.');
        return self::SUCCESS;
    }
}
