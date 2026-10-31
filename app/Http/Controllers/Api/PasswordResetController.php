<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PasswordResetController extends Controller
{
    // POST /auth/forgot-password
    // Même réponse que l'adresse existe ou non : on ne révèle pas qui a un compte.
    public function forgot(Request $request)
    {
        $validated = $request->validate(['email' => 'required|email|max:255']);

        Password::sendResetLink(['email' => $validated['email']]);

        return response()->json([
            'message' => 'Si un compte existe avec cette adresse, un lien de réinitialisation vient d\'être envoyé.',
        ]);
    }

    // POST /auth/reset-password
    // Le nouveau mot de passe invalide aussi les sessions ouvertes : les jetons JWT
    // sont liés au mot de passe (PasswordBoundJWTGuard).
    public function reset(Request $request)
    {
        $validated = $request->validate([
            'token'    => 'required|string|max:255',
            'email'    => 'required|email|max:255',
            'password' => 'required|string|min:12|max:72',
        ]);

        $status = Password::reset($validated, function (User $user, string $password) {
            $user->forceFill([
                'password'       => $password, // haché par le cast 'hashed' du modèle
                'remember_token' => Str::random(60),
            ])->save();
        });

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'token' => ['Ce lien n\'est plus valable. Demandez-en un nouveau.'],
            ]);
        }

        return response()->json(['message' => 'Votre mot de passe a été modifié. Vous pouvez vous connecter.']);
    }
}
