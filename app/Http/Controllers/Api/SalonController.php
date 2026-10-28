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
    // GET /api/salons - Lister tous les salons (filtre optionnel : q = ville, quartier, nom ou service recherché)
    public function index(Request $request)
    {
        $validated = $request->validate([
            'q'        => 'sometimes|string|max:255',
            'per_page' => 'sometimes|integer|min:1|max:50',
        ]);

        $salons = Salon::where('is_active', true)
            ->where('is_verified', true)
            ->when($validated['q'] ?? null, function ($q, $term) {
                $term = mb_strtolower($term);
                $q->where(function ($inner) use ($term) {
                    $inner->whereRaw('LOWER(name) LIKE ?', ["%{$term}%"])
                        ->orWhereRaw('LOWER(city) LIKE ?', ["%{$term}%"])
                        ->orWhereRaw('LOWER(address) LIKE ?', ["%{$term}%"])
                        ->orWhereHas('services', function ($sq) use ($term) {
                            $sq->where('is_active', true)
                                ->where(function ($sq2) use ($term) {
                                    $sq2->whereRaw('LOWER(category) LIKE ?', ["%{$term}%"])
                                        ->orWhereRaw('LOWER(name) LIKE ?', ["%{$term}%"]);
                                });
                        });
                });
            })
            ->withCount('reviews')
            ->withAvg('reviews', 'rating')
            ->with(['manager:id,name', 'services' => fn ($q) => $q->where('is_active', true)->select('id', 'salon_id', 'category')])
            ->paginate($validated['per_page'] ?? 10);

        $salons->getCollection()->transform(function (Salon $salon) {
            $salon->setAttribute('service_categories', $salon->services
                ->pluck('category')
                ->unique()
                ->values());
            $salon->unsetRelation('services');
            return $salon;
        });

        return response()->json($salons);
    }

    // POST /api/salons - Créer un salon
    // POST /api/salons - Demande de partenariat.
    // Le salon est créé « en attente » (non vérifié, invisible du public). Le rôle
    // gérant n'est accordé qu'à la validation par l'administrateur (AdminController).
    public function store(Request $request)
    {
        $user = $request->user();
        $hasPendingRequest = Salon::where('manager_id', $user->id)
            ->where('is_verified', false)
            ->where('is_active', true)
            ->exists();
        if ($hasPendingRequest && !$user->hasRole('admin')) {
            return response()->json([
                'message' => 'Vous avez déjà une demande de partenariat en cours d\'examen.',
            ], 422);
        }

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
        while (Salon::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base . '-' . $counter++;
        }

        $salon = Salon::create([
            ...$validated,
            'manager_id' => Auth::id(),
            'slug'       => $slug,
        ]);

        return response()->json($salon, 201);
    }

    // GET /api/my-salons - Les salons de l'utilisateur et l'état de leur demande,
    // y compris pour un client dont la demande n'est pas encore validée.
    public function mine(Request $request)
    {
        $salons = Salon::where('manager_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->get(['id', 'name', 'city', 'is_verified', 'is_active', 'created_at'])
            ->map(fn (Salon $salon) => [
                'id'         => $salon->id,
                'name'       => $salon->name,
                'city'       => $salon->city,
                'status'     => match (true) {
                    $salon->is_verified && $salon->is_active => 'verified',
                    $salon->is_verified                      => 'suspended',
                    $salon->is_active                        => 'pending',
                    default                                  => 'rejected',
                },
                'created_at' => $salon->created_at,
            ]);

        return response()->json($salons);
    }

    // GET /api/salons/{id} - Détails d'un salon
    public function show(Salon $salon)
    {
        if (!$salon->isVisibleTo(Auth::user())) {
            abort(404);
        }

        $salon->loadCount('reviews')->loadAvg('reviews', 'rating');
        $salon->load(['manager:id,name', 'services' => fn ($q) => $q->where('is_active', true), 'employees:id,salon_id,name']);
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
            'opening_hours' => 'sometimes|nullable|array:mon,tue,wed,thu,fri,sat,sun',
            'opening_hours.*' => 'nullable|array:open,close',
            'opening_hours.*.open' => 'required_with:opening_hours.*.close|date_format:H:i',
            'opening_hours.*.close' => 'required_with:opening_hours.*.open|date_format:H:i|after:opening_hours.*.open',
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
