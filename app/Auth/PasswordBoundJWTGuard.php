<?php

namespace App\Auth;

use App\Models\User;
use Tymon\JWTAuth\JWTGuard;

class PasswordBoundJWTGuard extends JWTGuard
{
    public function user()
    {
        if ($this->user !== null) {
            return $this->user;
        }

        $user = parent::user();
        if (!$user instanceof User) {
            return null;
        }

        $claim = $this->getPayload()->get('credential');
        if (!is_string($claim) || !hash_equals($user->jwtCredential(), $claim)) {
            $this->forgetUser();
            return null;
        }

        return $user;
    }
}
