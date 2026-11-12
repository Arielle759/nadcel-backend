<?php

namespace App\Http\Controllers\Api;

use App\Models\Service;
use App\Models\Salon;
use App\Models\ServiceOption;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use App\Http\Controllers\Controller;

class ServiceController extends Controller
{
    // GET /api/services - Lister tous les services (filtre optionnel ?salon_id=)
    public function index(Request $request)
    {
        $validated = $request->validate([
            'per_page' => 'sometimes|integer|min:1|max:100',
        ]);

        // employees_count : une prestation que personne ne réalise n'a aucun
        // créneau. L'espace gérant le signale, la réservation l'explique.
        $services = Service::where('is_active', true)
            ->when($request->query('salon_id'), fn ($q, $salonId) => $q->where('salon_id', $salonId))
            ->whereIn('salon_id', Salon::visibleTo(Auth::user())->select('id'))
            ->with('salon')
            ->withCount(['employees' => fn ($e) => $e->where('employees.is_active', true)])
            ->paginate($validated['per_page'] ?? 10);

        return response()->json($services);
    }

    // POST /api/services - Créer un service
    public function store(Request $request)
    {
        self::decodeOptions($request);
        $validated = $request->validate([
            'salon_id'    => 'required|exists:salons,id',
            'name'        => 'required|string|max:255',
            'description' => 'nullable|string',
            'price'       => 'required|numeric|min:0',
            'duration'    => 'required|integer|min:15|max:720',
            'category'    => 'required|string',
            'image'       => 'nullable|image|mimes:jpg,jpeg,png,webp|max:5120',
            ...self::optionRules(),
        ]);

        $salon = Salon::findOrFail($validated['salon_id']);
        $this->authorize('manageServices', $salon);

        if ($request->hasFile('image')) {
            $validated['image'] = '/storage/' . Storage::disk('public')->putFile('services', $request->file('image'));
        }

        $options = $validated['options'] ?? null;
        unset($validated['options']);
        $service = DB::transaction(function () use ($validated, $options) {
            $service = Service::create($validated);
            $this->syncOptions($service, $options);
            return $service;
        });
        return response()->json($service->load('options'), 201);
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
        self::decodeOptions($request);

        $validated = $request->validate([
            'name'        => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'price'       => 'sometimes|numeric|min:0',
            'duration'    => 'sometimes|integer|min:15|max:720',
            'category'    => 'sometimes|string',
            'image'       => 'nullable|image|mimes:jpg,jpeg,png,webp|max:5120',
            ...self::optionRules(),
        ]);

        if ($request->hasFile('image')) {
            // Supprime l'ancienne image uploadée (pas les placeholders du seeder /images/...)
            if ($service->image && str_starts_with($service->image, '/storage/services/')) {
                Storage::disk('public')->delete(substr($service->image, strlen('/storage/')));
            }
            $validated['image'] = '/storage/' . Storage::disk('public')->putFile('services', $request->file('image'));
        }

        $options = array_key_exists('options', $validated) ? ($validated['options'] ?? []) : null;
        unset($validated['options']);
        DB::transaction(function () use ($service, $validated, $options) {
            $service->update($validated);
            $this->syncOptions($service, $options);
        });
        return response()->json($service->fresh()->load('options'));
    }

    // Le formulaire est envoyé en multipart (photo) : les options arrivent en JSON.
    private static function decodeOptions(Request $request): void
    {
        if (is_string($request->input('options'))) {
            $decoded = json_decode($request->input('options'), true);
            $request->merge(['options' => is_array($decoded) ? $decoded : []]);
        }
    }

    private static function optionRules(): array
    {
        return [
            'option_label'       => 'nullable|string|max:40',
            'options'            => 'sometimes|nullable|array|max:' . ServiceOption::MAX_PER_SERVICE,
            'options.*.label'    => 'required|string|max:60|distinct',
            'options.*.price'    => 'required|numeric|min:0',
            'options.*.duration' => 'required|integer|min:15|max:720',
        ];
    }

    /**
     * Remplace les options du service (null = ne rien changer). Le prix et la
     * durée du service deviennent ceux de l'option la moins chère et la plus
     * courte (affichés « à partir de »). Les rendez-vous déjà pris gardent leur
     * copie du libellé, du prix et de la durée.
     */
    private function syncOptions(Service $service, ?array $options): void
    {
        if ($options === null) {
            return;
        }

        $service->options()->delete();
        foreach (array_values($options) as $i => $option) {
            $service->options()->create([
                'label'    => trim($option['label']),
                'price'    => $option['price'],
                'duration' => $option['duration'],
                'position' => $i,
            ]);
        }

        if ($options) {
            $service->update([
                'price'        => min(array_column($options, 'price')),
                'duration'     => min(array_column($options, 'duration')),
                'option_label' => $service->option_label ?: 'Longueur',
            ]);
        } else {
            $service->update(['option_label' => null]);
        }
        $service->unsetRelation('options');
    }

    // DELETE /api/services/{id} - Supprimer un service
    public function destroy(Service $service)
    {
        $this->authorize('manageServices', $service->salon);

        $service->delete();
        return response()->noContent();
    }
}