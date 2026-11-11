<?php

namespace App\Http\Controllers\Api;

use App\Models\Employee;
use App\Models\Salon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Http\Controllers\Controller;

class EmployeeController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'salon_id' => 'required|exists:salons,id',
        ]);

        $salon = Salon::findOrFail($validated['salon_id']);
        $this->authorize('manageEmployees', $salon);

        $employees = Employee::where('salon_id', $salon->id)
            ->with('salon', 'services')
            ->orderBy('name')
            ->paginate(100);

        return response()->json($employees);
    }

    // Un membre de l'équipe n'a pas de compte : nom, téléphone et prestations
    // réalisées suffisent au gérant et au calcul des disponibilités.
    public function store(Request $request)
    {
        $validated = $request->validate([
            'salon_id'   => 'required|exists:salons,id',
            'name'       => 'required|string|max:255',
            'phone'      => 'required|string|max:30',
            'service_ids' => 'sometimes|array',
            'service_ids.*' => 'exists:services,id',
        ]);

        $salon = Salon::findOrFail($validated['salon_id']);
        $this->authorize('manageEmployees', $salon);
        $this->ensureServicesBelongToSalon($validated['service_ids'] ?? [], $salon);

        $employee = DB::transaction(function () use ($validated, $salon) {
            $employee = Employee::create([
                'salon_id' => $salon->id,
                'name'     => $validated['name'],
                'phone'    => $validated['phone'],
            ]);

            if (!empty($validated['service_ids'])) {
                $employee->services()->sync($validated['service_ids']);
            }

            return $employee;
        });

        return response()->json($employee->load('services'), 201);
    }

    public function show(Employee $employee)
    {
        $this->authorize('manageEmployees', $employee->salon);

        $employee->load('salon', 'services');
        return response()->json($employee);
    }

    public function update(Request $request, Employee $employee)
    {
        $this->authorize('manageEmployees', $employee->salon);

        $validated = $request->validate([
            'name'          => 'sometimes|string|max:255',
            'phone'         => 'sometimes|string',
            'service_ids'   => 'sometimes|array',
            'service_ids.*' => 'exists:services,id',
        ]);

        $this->ensureServicesBelongToSalon($validated['service_ids'] ?? [], $employee->salon);

        $employee->update(array_filter(
            $validated,
            fn ($key) => in_array($key, ['name', 'phone']),
            ARRAY_FILTER_USE_KEY
        ));

        if ($request->has('service_ids')) {
            $employee->services()->sync($validated['service_ids']);
        }

        return response()->json($employee->load('services'));
    }

    // Un employé n'est jamais supprimé : le désactiver le retire des créneaux
    // proposés et des nouvelles réservations, sans toucher à son historique
    // (rendez-vous passés, chiffre d'affaires, statistiques).
    public function deactivate(Employee $employee)
    {
        $this->authorize('manageEmployees', $employee->salon);

        $employee->forceFill(['is_active' => false])->save();

        $upcoming = $employee->appointments()
            ->whereIn('status', ['pending', 'confirmed'])
            ->where('scheduled_at', '>', now())
            ->count();

        return response()->json([
            'employee' => $employee->load('services'),
            // Rendez-vous à venir déjà attribués : le gérant doit les déplacer
            // ou prévenir les clients.
            'upcoming_appointments' => $upcoming,
        ]);
    }

    public function activate(Employee $employee)
    {
        $this->authorize('manageEmployees', $employee->salon);

        $employee->forceFill(['is_active' => true])->save();

        return response()->json(['employee' => $employee->load('services')]);
    }

    /**
     * @param array<int, int|string> $serviceIds
     */
    private function ensureServicesBelongToSalon(array $serviceIds, Salon $salon): void
    {
        if ($serviceIds === []) {
            return;
        }

        $matchingServices = \App\Models\Service::whereIn('id', $serviceIds)
            ->where('salon_id', $salon->id)
            ->count();

        if ($matchingServices !== count(array_unique($serviceIds))) {
            throw ValidationException::withMessages([
                'service_ids' => ['Tous les services doivent appartenir au salon de l’employé.'],
            ]);
        }
    }
}
