<?php

namespace App\Http\Controllers\Api;

use App\Mail\PartnerRequestApproved;
use App\Mail\PartnerRequestRejected;
use App\Models\Appointment;
use App\Models\Review;
use App\Models\Salon;
use App\Models\User;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Carbon;

class AdminController extends Controller
{
    // GET /admin/salons
    // Filtre facultatif par état : online, pending, suspended, rejected.
    public function allSalons(Request $request)
    {
        $validated = $request->validate([
            'status'   => 'sometimes|string|in:online,pending,suspended,rejected',
            'per_page' => 'sometimes|integer|min:1|max:100',
        ]);

        $salons = Salon::with('manager:id,name,email')
            ->withCount('reviews')
            ->withAvg('reviews', 'rating')
            ->when($validated['status'] ?? null, function ($query, string $status) {
                match ($status) {
                    'online'    => $query->public(),
                    'suspended' => $query->where('is_verified', true)->where('is_active', false),
                    'pending'   => $query->where('is_verified', false)->where('is_active', true),
                    'rejected'  => $query->where('is_verified', false)->where('is_active', false),
                };
            })
            ->orderByDesc('created_at')
            ->paginate($validated['per_page'] ?? 50);

        return response()->json($salons);
    }

    // GET /admin/salons/pending
    // Les plus anciennes demandes d'abord : premier arrivé, premier traité.
    public function pendingSalons()
    {
        $salons = Salon::where('is_verified', false)
            ->where('is_active', true)
            ->with('manager:id,name,email')
            ->oldest()
            ->paginate(50);

        return response()->json($salons);
    }

    // Chaque action n'a de sens que depuis un état précis. Sans ce contrôle,
    // suspendre une demande en attente la ferait passer pour « refusée ».

    // PATCH /admin/salons/{salon}/verify (demande en attente ou refusée)
    public function verifySalon(Salon $salon)
    {
        if ($salon->is_verified) {
            return $this->invalidTransition('Ce salon est déjà validé.');
        }

        $salon->verify();

        // La validation de la demande fait du demandeur un gérant.
        $manager = $salon->manager;
        if ($manager && !$manager->hasRole('gerant')) {
            $manager->assignRole('gerant');
        }
        if ($manager) {
            Mail::to($manager->email)->send(new PartnerRequestApproved($salon));
        }

        return response()->json($salon);
    }

    // PATCH /admin/salons/{salon}/reject (demande en attente uniquement)
    public function rejectSalon(Salon $salon)
    {
        if ($salon->is_verified || !$salon->is_active) {
            return $this->invalidTransition('Seule une demande en attente peut être refusée.');
        }

        $salon->reject();

        if ($salon->manager) {
            Mail::to($salon->manager->email)->send(new PartnerRequestRejected($salon));
        }

        return response()->json($salon);
    }

    // PATCH /admin/salons/{salon}/suspend (salon en ligne uniquement)
    public function suspendSalon(Salon $salon)
    {
        if (!$salon->is_verified || !$salon->is_active) {
            return $this->invalidTransition('Seul un salon en ligne peut être suspendu.');
        }

        $salon->suspend();

        return response()->json($salon);
    }

    // PATCH /admin/salons/{salon}/reactivate (salon suspendu uniquement)
    public function reactivateSalon(Salon $salon)
    {
        if (!$salon->is_verified || $salon->is_active) {
            return $this->invalidTransition('Seul un salon suspendu peut être remis en ligne.');
        }

        $salon->reactivate();

        return response()->json($salon);
    }

    private function invalidTransition(string $message)
    {
        return response()->json(['message' => $message], 422);
    }

    // GET /admin/stats
    // Santé de la plateforme. Aucun montant : les salons encaissent eux-mêmes,
    // sur place ; la plateforme n'a pas à centraliser leur chiffre d'affaires.
    public function stats()
    {
        $now = now();
        $monthStart = $now->copy()->startOfMonth();
        $previousStart = $monthStart->copy()->subMonth();
        $sameDayLastMonth = $now->copy()->subMonthNoOverflow();
        $last30 = $now->copy()->subDays(30);

        $salons = [
            'online'    => Salon::public()->count(),
            'pending'   => Salon::where('is_verified', false)->where('is_active', true)->count(),
            'suspended' => Salon::where('is_verified', true)->where('is_active', false)->count(),
            'rejected'  => Salon::where('is_verified', false)->where('is_active', false)->count(),
        ];

        $clients = $this->usersWithRole('client')->count();
        $newClients = $this->usersWithRole('client')->where('created_at', '>=', $monthStart)->count();

        // Comparaison à date égale : du 1er au jour J ce mois-ci, contre la même
        // période le mois dernier (sinon un mois en cours paraît toujours en baisse).
        $thisMonth = Appointment::whereBetween('scheduled_at', [$monthStart, $now])->count();
        $lastMonthSamePeriod = Appointment::whereBetween('scheduled_at', [$previousStart, $sameDayLastMonth])->count();

        $recent = Appointment::where('scheduled_at', '>=', $last30)->where('scheduled_at', '<=', $now);
        $recentTotal = (clone $recent)->count();
        $recentCancelled = (clone $recent)->where('status', 'cancelled')->count();
        $recentOnline = (clone $recent)->where('source', 'online')->count();

        // Détail par canal (en ligne, réseaux, téléphone, sur place), toujours dans le même ordre.
        $bySource = (clone $recent)->selectRaw('source, COUNT(*) as total')->groupBy('source')->pluck('total', 'source');
        $sources = array_map(fn (string $source) => [
            'source' => $source,
            'count'  => (int) ($bySource[$source] ?? 0),
            'share'  => $recentTotal > 0 ? round(($bySource[$source] ?? 0) / $recentTotal * 100, 1) : 0,
        ], Appointment::SOURCES);

        return response()->json([
            'salons' => $salons,
            'users'  => [
                'clients'      => $clients,
                'new_clients'  => $newClients,
                'managers'     => $this->usersWithRole('gerant')->count(),
            ],
            'appointments' => [
                'this_month'               => $thisMonth,
                'last_month_same_period'   => $lastMonthSamePeriod,
                'trend'                    => $lastMonthSamePeriod > 0
                    ? round(($thisMonth - $lastMonthSamePeriod) / $lastMonthSamePeriod * 100, 1)
                    : null,
                'cancellation_rate'        => $recentTotal > 0 ? round($recentCancelled / $recentTotal * 100, 1) : null,
                'online_share'             => $recentTotal > 0 ? round($recentOnline / $recentTotal * 100, 1) : null,
                'sources'                  => $sources,
                'recent_total'             => $recentTotal,
            ],
            'to_review' => [
                'pending_salons' => $salons['pending'],
                'low_reviews'    => Review::where('rating', '<=', 2)->where('created_at', '>=', $last30)->count(),
            ],
            'monthly' => $this->monthly($now),
        ]);
    }

    /** Rendez-vous (par date de passage) et nouvelles clientes, sur 12 mois complets + le mois en cours. */
    private function monthly(Carbon $now): array
    {
        $months = [];
        // Une requête COUNT par mois : portable (MySQL, SQLite) et indexable,
        // sans charger les lignes en mémoire.
        for ($offset = 12; $offset >= 0; $offset--) {
            $start = $now->copy()->startOfMonth()->subMonthsNoOverflow($offset);
            $end = $start->copy()->addMonth();
            $months[] = [
                'month'        => $start->format('Y-m'),
                'appointments' => Appointment::where('scheduled_at', '>=', $start)->where('scheduled_at', '<', $end)->count(),
                'new_clients'  => $this->usersWithRole('client')->where('created_at', '>=', $start)->where('created_at', '<', $end)->count(),
                'is_partial'   => $offset === 0,
            ];
        }

        return $months;
    }

    /** Équivalent de User::role(), sans exception si le rôle n'existe pas encore. */
    private function usersWithRole(string $role)
    {
        return User::whereHas('roles', fn ($q) => $q->where('name', $role));
    }
}
