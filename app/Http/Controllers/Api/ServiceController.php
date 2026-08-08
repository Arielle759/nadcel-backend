<?php

namespace App\Http\Controllers\Api;

use App\Models\Service;
use App\Models\Salon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use App\Http\Controllers\Controller;

class ServiceController extends Controller
{
    // GET /api/services - Lister tous les services (filtre optionnel ?salon_id=)
    public function index(Request $request)
    {
        $services = Service::where('is_active', true)
            ->when($request->query('salon_id'), fn ($q, $salonId) => $q->where('salon_id', $salonId))
            ->with('salon')
            ->paginate(10);

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
            'duration'    => 'required|integer|min:15',
            'category'    => 'required|string',
            'image'       => 'nullable|image|max:2048',
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
        $service->load('salon', 'employees');
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
            'duration'    => 'sometimes|integer|min:15',
            'category'    => 'sometimes|string',
            'image'       => 'nullable|image|max:2048',
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

    // DELETE /api/services/{id} - Supprimer un service
    public function destroy(Service $service)
    {
        $this->authorize('manageServices', $service->salon);

        $service->delete();
        return response()->noContent();
    }
}