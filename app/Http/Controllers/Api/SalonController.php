<?php

namespace App\Http\Controllers\Api;

use App\Models\Salon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Http\Controllers\Controller;

class SalonController extends Controller
{
    // GET /api/salons - Lister tous les salons
    public function index()
    {
        $salons = Salon::where('is_active', true)
            ->where('is_verified', true)
            ->with(['manager', 'services:id,salon_id,category'])
            ->paginate(10);

        $salons->getCollection()->transform(function (Salon $salon) {
            $salon->service_categories = $salon->services
                ->pluck('category')
                ->unique()
                ->values();
            $salon->unsetRelation('services');
            return $salon;
        });

        return response()->json($salons);
    }

    // POST /api/salons - Créer un salon
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'        => 'required|string|max:255',
            'description' => 'nullable|string',
            'address'     => 'required|string',
            'city'        => 'required|string',
            'phone'       => 'required|string',
            'email'       => 'nullable|email',
        ]);

        $base    = Str::slug($validated['name']);
        $slug    = $base;
        $counter = 2;
        while (Salon::where('slug', $slug)->exists()) {
            $slug = $base . '-' . $counter++;
        }

        $salon = Salon::create([
            ...$validated,
            'manager_id' => Auth::id(),
            'slug'       => $slug,
        ]);

        $user = $request->user();
        if (!$user->hasRole('gerant')) {
            $user->assignRole('gerant');
        }

        return response()->json($salon, 201);
    }

    // GET /api/salons/{id} - Détails d'un salon
    public function show(Salon $salon)
    {
        /** @var \App\Models\User|null $user */
        $user = Auth::user();

        if (!$salon->is_verified) {
            $isOwnerOrAdmin = $user && ($user->id === $salon->manager_id || $user->hasRole('admin'));
            if (!$isOwnerOrAdmin) {
                abort(404);
            }
        }

        $salon->load('manager', 'services', 'employees');
        return response()->json($salon);
    }

    // PUT /api/salons/{id} - Modifier un salon
    public function update(Request $request, Salon $salon)
    {
        $this->authorize('update', $salon);

        $validated = $request->validate([
            'name'        => 'sometimes|string|max:255',
            'slug'        => 'sometimes|string|unique:salons,slug,' . $salon->id,
            'description' => 'nullable|string',
            'address'     => 'sometimes|string',
            'city'        => 'sometimes|string',
            'phone'       => 'sometimes|string',
            'email'       => 'nullable|email',
            'cover'       => 'nullable|image|max:2048',
        ]);

        if ($request->hasFile('cover')) {
            if ($salon->cover && str_starts_with($salon->cover, '/storage/salons/')) {
                Storage::disk('public')->delete(substr($salon->cover, strlen('/storage/')));
            }
            $validated['cover'] = '/storage/' . Storage::disk('public')->putFile('salons', $request->file('cover'));
        }

        $salon->update($validated);
        return response()->json($salon);
    }

    // DELETE /api/salons/{id} - Supprimer un salon
    public function destroy(Salon $salon)
    {
        $this->authorize('delete', $salon);

        $salon->delete();
        return response()->noContent();
    }

    // PATCH /api/salons/{id}/verify - Vérifier un salon (admin uniquement)
    public function verify(Salon $salon)
    {
        $this->authorize('verify', $salon);

        $salon->verify();
        return response()->json($salon);
    }
}
