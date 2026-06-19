<?php

namespace App\Http\Controllers\Api;

use App\Models\Service;
use App\Models\Salon;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class ServiceController extends Controller
{
    // GET /api/services - Lister tous les services
    public function index()
    {
        $services = Service::where('is_active', true)
            ->with('salon')
            ->paginate(10);

        return response()->json($services);
    }

    // POST /api/services - Créer un service
    public function store(Request $request)
    {
        $validated = $request->validate([
            'salon_id' => 'required|exists:salons,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'price' => 'required|numeric|min:0',
            'duration' => 'required|integer|min:15',
            'category' => 'required|string',
        ]);

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
        $validated = $request->validate([
            'name' => 'string|max:255',
            'description' => 'nullable|string',
            'price' => 'numeric|min:0',
            'duration' => 'integer|min:15',
            'category' => 'string',
        ]);

        $service->update($validated);
        return response()->json($service);
    }

    // DELETE /api/services/{id} - Supprimer un service
    public function destroy(Service $service)
    {
        $service->delete();
        return response()->noContent();
    }
}