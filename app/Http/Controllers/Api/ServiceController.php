<?php

namespace App\Http\Controllers\Api;

use App\Models\Service;
use App\Models\Salon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use App\Http\Controllers\Controller;

class ServiceController extends Controller
{
    // GET /api/services - Lister les services actifs (filtre optionnel ?salon_id=).
    // Le gérant du salon peut demander aussi ses services désactivés
    // (?include_inactive=1) pour les réactiver depuis son espace.
    public function index(Request $request)
    {
        $validated = $request->validate([
            'per_page'         => 'sometimes|integer|min:1|max:100',
            'salon_id'         => 'required_if_accepted:include_inactive',
            'include_inactive' => 'sometimes|boolean',
        ]);

        $includeInactive = $request->boolean('include_inactive');
        if ($includeInactive) {
            $this->authorize('manageServices', Salon::findOrFail($validated['salon_id']));
        }

        // employees_count : une prestation que personne ne réalise n'a aucun
        // créneau. L'espace gérant le signale, la réservation l'explique.
        $services = Service::query()
            ->when(!$includeInactive, fn ($q) => $q->where('is_active', true))
            ->when($request->query('salon_id'), fn ($q, $salonId) => $q->where('salon_id', $salonId))
            ->whereIn('salon_id', Salon::visibleTo(Auth::user())->select('id'))
            ->with('salon')
            ->withCount(['employees' => fn ($e) => $e->where('employees.is_active', true)])
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->paginate($validated['per_page'] ?? 10);

        return response()->json($services);
    }

    // POST /api/services - Créer un service
    public function store(Request $request)
    {
        $validated = $request->validate([
            'salon_id'    => 'required|exists:salons,id',
            'name'        => 'required|string|max:255',
            'description' => 'nullable|string',
            'price'       => 'required|numeric|min:0',
            'duration'    => 'required|integer|min:15|max:720',
            'category'    => 'required|string',
            'image'       => 'nullable|image|mimes:jpg,jpeg,png,webp|max:5120',
        ]);

        $salon = Salon::findOrFail($validated['salon_id']);
        $this->authorize('manageServices', $salon);

        if ($request->hasFile('image')) {
            $validated['image'] = '/storage/' . Storage::disk('public')->putFile('services', $request->file('image'));
        }

        $service = Service::create($validated);
        return response()->json($service, 201);
    }

    // GET /api/services/{id} - Détails d'un service
    public function show(Service $service)
    {
        if (!$service->is_active || !$service->salon?->isVisibleTo(Auth::user())) {
            abort(404);
        }

        $service->load('salon', 'employees:id,salon_id,name');
        return response()->json($service);
    }

    // PUT /api/services/{id} - Modifier un service
    public function update(Request $request, Service $service)
    {
        $this->authorize('manageServices', $service->salon);

        $validated = $request->validate([
            'name'        => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'price'       => 'sometimes|numeric|min:0',
            'duration'    => 'sometimes|integer|min:15|max:720',
            'category'    => 'sometimes|string',
            'image'       => 'nullable|image|mimes:jpg,jpeg,png,webp|max:5120',
        ]);

        if ($request->hasFile('image')) {
            // Supprime l'ancienne image uploadée (pas les placeholders du seeder /images/...)
            if ($service->image && str_starts_with($service->image, '/storage/services/')) {
                Storage::disk('public')->delete(substr($service->image, strlen('/storage/')));
            }
            $validated['image'] = '/storage/' . Storage::disk('public')->putFile('services', $request->file('image'));
        }

        $service->update($validated);
        return response()->json($service);
    }

    // Un service n'est jamais supprimé : le désactiver le retire de la page
    // du salon et des nouvelles réservations, sans toucher à son historique
    // (rendez-vous passés, avis, chiffre d'affaires, statistiques).
    public function deactivate(Service $service)
    {
        $this->authorize('manageServices', $service->salon);

        $service->forceFill(['is_active' => false])->save();

        $upcoming = $service->appointments()
            ->whereIn('status', ['pending', 'confirmed'])
            ->where('scheduled_at', '>', now())
            ->count();

        return response()->json([
            'service' => $service,
            // Rendez-vous à venir déjà pris pour ce service : ils sont
            // maintenus, le gérant décide de les honorer ou de prévenir.
            'upcoming_appointments' => $upcoming,
        ]);
    }

    public function activate(Service $service)
    {
        $this->authorize('manageServices', $service->salon);

        $service->forceFill(['is_active' => true])->save();

        return response()->json(['service' => $service]);
    }
}