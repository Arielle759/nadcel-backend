<?php

namespace App\Http\Controllers\Api;

use App\Models\Salon;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class SalonController extends Controller
{
    // GET /api/salons - Lister tous les salons
    public function index()
    {
        $salons = Salon::where('is_active', true)
            ->where('is_verified', true)
            ->with('manager')
            ->paginate(10);

        return response()->json($salons);
    }

    // POST /api/salons - Créer un salon
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'required|string|unique:salons',
            'description' => 'nullable|string',
            'address' => 'required|string',
            'city' => 'required|string',
            'phone' => 'required|string',
            'email' => 'nullable|email',
        ]);

        $salon = Salon::create([
            ...$validated,
            'manager_id' => auth()->user()?->id,
        ]);

        return response()->json($salon, 201);
    }

    // GET /api/salons/{id} - Détails d'un salon
    public function show(Salon $salon)
    {
        $salon->load('manager', 'services', 'employees');
        return response()->json($salon);
    }

    // PUT /api/salons/{id} - Modifier un salon
    public function update(Request $request, Salon $salon)
    {
        if (auth()->user()?->id !== $salon->manager_id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'name' => 'string|max:255',
            'slug' => 'string|unique:salons,slug,' . $salon->id,
            'description' => 'nullable|string',
            'address' => 'string',
            'city' => 'string',
            'phone' => 'string',
            'email' => 'nullable|email',
        ]);

        $salon->update($validated);
        return response()->json($salon);
    }

    // DELETE /api/salons/{id} - Supprimer un salon
    public function destroy(Salon $salon)
    {
        if (auth()->user()?->id !== $salon->manager_id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $salon->delete();
        return response()->noContent();
    }
}
