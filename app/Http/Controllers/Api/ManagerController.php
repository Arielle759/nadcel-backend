<?php

namespace App\Http\Controllers\Api;

use App\Models\Appointment;
use App\Services\ManagerActivityStatsService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

class ManagerController extends Controller
{
    public function salons(Request $request): JsonResponse
    {
        $salons = $request->user()->salons()->with('services')->paginate(10);

        return response()->json($salons);
    }

    // Salons pris en compte : tous ceux du gérant, ou un seul (?salon_id=) s'il lui appartient.
    private function scopedSalonIds(Request $request): Collection
    {
        $validated = $request->validate(['salon_id' => 'sometimes|integer']);
        $ids = $request->user()->salons()->pluck('id');

        if (isset($validated['salon_id'])) {
            $id = (int) $validated['salon_id'];
            abort_unless($ids->contains($id), 403, 'Ce salon ne fait pas partie de vos salons.');

            return collect([$id]);
        }

        return $ids;
    }

    public function stats(Request $request)
    {
        $salonIds = $this->scopedSalonIds($request);

        $appointmentStats = Appointment::whereIn('salon_id', $salonIds)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        // Paiement sur place : le chiffre d'affaires est la somme des montants encaissés
        // notés à la clôture (le prix prévu sert de repli pour les anciens rendez-vous).
        $collected = Appointment::whereIn('salon_id', $salonIds)
            ->where('status', 'completed')
            ->where('payment_status', 'paid')
            ->get(['amount_paid', 'price'])
            ->sum(fn (Appointment $a) => $a->collectedAmount());

        $open = Appointment::whereIn('salon_id', $salonIds)->whereIn('status', ['pending', 'confirmed']);

        return response()->json([
            'salons_count' => count($salonIds),
            'appointments' => $appointmentStats,
            'revenue'      => [
                'collected' => round((float) $collected, 2),
            ],
            // Rendez-vous passés encore ouverts : un oubli de clôture, pas de l'argent dû.
            'to_close'     => (clone $open)->where('scheduled_at', '<', now())->count(),
            'upcoming'     => (clone $open)->where('scheduled_at', '>=', now())->count(),
            // Demandes en ligne qui attendent une réponse du salon (compteur de la cloche).
            'pending'      => Appointment::whereIn('salon_id', $salonIds)
                ->where('status', 'pending')
                ->where('scheduled_at', '>=', now())
                ->count(),
            'next_appointment' => $this->nextAppointment($salonIds),
        ]);
    }

    private function nextAppointment(Collection $salonIds): ?array
    {
        $next = Appointment::with(['service:id,name', 'salon:id,name', 'client:id,name'])
            ->whereIn('salon_id', $salonIds)
            ->whereIn('status', ['pending', 'confirmed'])
            ->where('scheduled_at', '>=', now())
            ->orderBy('scheduled_at')
            ->first();

        if (! $next) {
            return null;
        }

        return [
            'id'           => $next->id,
            'scheduled_at' => $next->scheduled_at,
            'status'       => $next->status,
            'client_name'  => $next->client?->name ?? $next->client_name,
            'service'      => $next->service?->name,
            'option_label' => $next->option_label,
            'salon'        => $next->salon?->name,
        ];
    }

    // GET /manager/stats/activity?period=month|quarter|year[&salon_id=]
    // Tous les salons du gérant connecté, ou un seul (?salon_id=) s'il lui appartient.
    public function activity(Request $request, ManagerActivityStatsService $stats): JsonResponse
    {
        $validated = $request->validate([
            'period' => 'sometimes|string|in:'.implode(',', array_keys(ManagerActivityStatsService::PERIODS)),
        ]);

        $salonIds = $this->scopedSalonIds($request);

        return response()->json($stats->build($salonIds, $validated['period'] ?? 'month'));
    }
}
