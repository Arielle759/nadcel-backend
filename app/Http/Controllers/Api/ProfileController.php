<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

// « Mon profil » : chaque utilisateur modifie ses propres informations.
// L'e-mail n'est pas modifiable ici : c'est l'identifiant de connexion, son
// changement demanderait une vérification par e-mail.
class ProfileController extends Controller
{
    // GET /api/profile
    public function show(Request $request): JsonResponse
    {
        return response()->json($request->user()->load('roles'));
    }

    // PATCH /api/profile
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'  => 'required|string|max:255',
            // Chiffres, espaces, +, tirets et points : formats usuels au Sénégal.
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^\+?[0-9 .\-]{7,20}$/'],
        ], [
            'phone.regex' => 'Le numéro de téléphone n\'est pas valide.',
        ]);

        $user = $request->user();
        $user->update($validated);

        return response()->json($user->fresh()->load('roles'));
    }

    // PUT /api/profile/password
    // Les jetons sont liés au mot de passe (PasswordBoundJWTGuard) : le
    // changement déconnecte toutes les autres sessions. On renvoie donc un
    // nouveau jeton pour que la session en cours continue.
    public function updatePassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'current_password' => 'required|string|max:1024',
            'password'         => 'required|string|min:12|max:72|confirmed',
        ]);

        $user = $request->user();

        if (!Hash::check($validated['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'Le mot de passe actuel est incorrect.',
            ]);
        }

        if (Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'password' => 'Choisissez un mot de passe différent de l\'actuel.',
            ]);
        }

        $user->password = $validated['password']; // haché par le cast 'hashed'
        $user->save();

        return response()->json([
            'token' => auth('api')->login($user),
            'user'  => $user->fresh()->load('roles'),
        ]);
    }
}
