<?php

namespace App\Http\Controllers\Api;

use App\Models\Appointment;
use App\Models\Review;
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
            'today'            => $this->today($salonIds),
            'rating'           => $this->rating($salonIds),
        ]);
    }

    // GET /manager/clients?salon_id=&search=&page=
    // Clients des salons du gérant, avec ou sans compte (rendez-vous saisis par
    // le salon) : nombre de rendez-vous, visites réalisées, dernière visite,
    // prochain rendez-vous et montant encaissé.
    public function clients(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => 'sometimes|nullable|string|max:100',
            'page'   => 'sometimes|integer|min:1',
        ]);
        $salonIds = $this->scopedSalonIds($request);
        $now = now();

        $rows = Appointment::with('client:id,name,email,phone')
            ->whereIn('salon_id', $salonIds)
            ->where(fn ($q) => $q->where('status', '!=', 'cancelled')->orWhere('cancelled_late', true))
            ->get(['id', 'client_id', 'client_name', 'client_phone', 'scheduled_at', 'status', 'cancelled_late', 'payment_status', 'amount_paid', 'price'])
            ->groupBy(fn (Appointment $a) => $a->client_id
                ? 'u'.$a->client_id
                : 'n'.mb_strtolower(trim((string) $a->client_name)).'|'.preg_replace('/\D+/', '', (string) $a->client_phone))
            ->map(function ($group) use ($now) {
                $first = $group->first();
                $lateCancellations = $group->where('status', 'cancelled')->count();
                $group = $group->where('status', '!=', 'cancelled');
                $first = $group->first() ?? $first;
                $done = $group->where('status', 'completed');
                $next = $group->whereIn('status', ['pending', 'confirmed'])->filter(fn ($a) => $a->scheduled_at >= $now)->sortBy('scheduled_at')->first();

                return [
                    'key'          => $first->client_id ? 'u'.$first->client_id : 'n'.$first->id,
                    'name'         => $first->client?->name ?? $first->client_name ?? 'Client',
                    'email'        => $first->client?->email,
                    'phone'        => $first->client?->phone ?? $group->pluck('client_phone')->filter()->first(),
                    'has_account'  => $first->client_id !== null,
                    'appointments' => $group->count(),
                    'visits'       => $done->count(),
                    'last_visit'   => $done->max('scheduled_at'),
                    'next_visit'   => $next?->scheduled_at,
                    'spent'        => round((float) $done->where('payment_status', 'paid')->sum(fn ($a) => $a->collectedAmount()), 2),
                    'late_cancellations' => $lateCancellations,
                    'no_shows'     => $group->where('status', 'no_show')->count(),
                ];
            })
            ->values();

        if ($search = mb_strtolower(trim($validated['search'] ?? ''))) {
            $rows = $rows->filter(fn ($c) => str_contains(mb_strtolower($c['name']), $search)
                || str_contains(mb_strtolower((string) $c['email']), $search)
                || str_contains(preg_replace('/\D+/', '', (string) $c['phone']), preg_replace('/\D+/', '', $search) ?: "\0"))->values();
        }

        $rows = $rows->sortByDesc(fn ($c) => [$c['visits'], (string) $c['last_visit']])->values();
        $perPage = 25;
        $page = (int) ($validated['page'] ?? 1);

        return response()->json([
            'data'         => $rows->forPage($page, $perPage)->values(),
            'current_page' => $page,
            'last_page'    => max(1, (int) ceil($rows->count() / $perPage)),
            'total'        => $rows->count(),
        ]);
    }

    // Planning du jour (hors annulés) et comparaison avec la veille.
    private function today(Collection $salonIds): array
    {
        $start = now()->startOfDay();
        $active = fn () => Appointment::whereIn('salon_id', $salonIds)->where('status', '!=', 'cancelled');

        $list = $active()
            ->with(['service:id,name', 'client:id,name', 'employee:id,name'])
            ->whereBetween('scheduled_at', [$start, $start->copy()->endOfDay()])
            ->orderBy('scheduled_at')
            ->limit(6)
            ->get()
            ->map(fn (Appointment $a) => [
                'id'           => $a->id,
                'scheduled_at' => $a->scheduled_at,
                'status'       => $a->status,
                'client_name'  => $a->client?->name ?? $a->client_name,
                'employee'     => $a->employee?->name,
                'service'      => $a->service?->name,
            ]);

        return [
            'count'     => $active()->whereBetween('scheduled_at', [$start, $start->copy()->endOfDay()])->count(),
            'yesterday' => $active()->whereBetween('scheduled_at', [$start->copy()->subDay(), $start->copy()->subSecond()])->count(),
            'list'      => $list,
        ];
    }

    // Note moyenne des avis des salons concernés.
    private function rating(Collection $salonIds): array
    {
        $reviews = Review::whereIn('salon_id', $salonIds);
        $count = (clone $reviews)->count();

        return [
            'average' => $count > 0 ? round((float) (clone $reviews)->avg('rating'), 1) : null,
            'count'   => $count,
        ];
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
