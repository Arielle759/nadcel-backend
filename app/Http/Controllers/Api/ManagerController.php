<?php

namespace App\Http\Controllers\Api;

use App\Models\Appointment;
use App\Services\ManagerActivityStatsService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ManagerController extends Controller
{
    public function salons(Request $request): JsonResponse
    {
        $salons = $request->user()->salons()->with('services')->paginate(10);

        return response()->json($salons);
    }

    public function stats(Request $request)
    {
        $salonIds = $request->user()->salons()->pluck('id');

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
        ]);
    }

    // GET /manager/stats/activity?period=month|quarter|year
    // Les salons sont toujours ceux du gérant connecté : aucun identifiant de salon n'est accepté.
    public function activity(Request $request, ManagerActivityStatsService $stats): JsonResponse
    {
        $validated = $request->validate([
            'period' => 'sometimes|string|in:'.implode(',', array_keys(ManagerActivityStatsService::PERIODS)),
        ]);

        $salonIds = $request->user()->salons()->pluck('id');

        return response()->json($stats->build($salonIds, $validated['period'] ?? 'month'));
    }
}
