<?php

namespace App\Services;

use App\Models\Appointment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Statistiques d'activité du tableau de bord gérant.
 *
 * Seules les prestations réellement réalisées (statut "completed") sont comptées.
 * Les agrégations sont faites en PHP plutôt qu'en SQL pour rester identiques
 * sur PostgreSQL (production) et SQLite (tests) : le volume d'un salon reste faible.
 */
class ManagerActivityStatsService
{
    /** Fenêtres glissantes : comparer 30 jours complets à 30 jours complets reste équitable. */
    public const PERIODS = [
        'month'   => 30,
        'quarter' => 90,
        'year'    => 365,
    ];

    /** Nombre minimal de mois d'historique avant d'afficher une prévision. */
    public const FORECAST_MIN_MONTHS = 3;

    private const TOP_SERVICES_LIMIT = 5;

    /**
     * @param  Collection<int, int>  $salonIds  Salons du gérant connecté (jamais fournis par le client)
     * @return array<string, mixed>
     */
    public function build(Collection $salonIds, string $period, ?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $days = self::PERIODS[$period];

        $end = $now->copy();
        $start = $now->copy()->subDays($days);
        $previousStart = $start->copy()->subDays($days);

        // Douze mois complets + le mois en cours, pour le graphique et la prévision.
        $historyStart = $now->copy()->startOfMonth()->subMonths(12);
        $loadFrom = $previousStart->lt($historyStart) ? $previousStart : $historyStart;

        $appointments = Appointment::query()
            ->whereIn('salon_id', $salonIds)
            ->where('status', 'completed')
            ->whereBetween('scheduled_at', [$loadFrom, $end])
            ->with(['service:id,name', 'employee:id,name'])
            ->get(['id', 'client_id', 'service_id', 'employee_id', 'scheduled_at', 'price', 'amount_paid', 'payment_method']);

        $current = $appointments->filter(fn (Appointment $a) => $a->scheduled_at->between($start, $end));
        $previous = $appointments->filter(
            fn (Appointment $a) => $a->scheduled_at->gte($previousStart) && $a->scheduled_at->lt($start)
        );

        $topServices = $this->topServices($current, $previous);
        $monthly = $this->monthly($appointments, $historyStart, $now, array_column($topServices, 'service_id'));

        return [
            'period' => [
                'key'   => $period,
                'days'  => $days,
                'start' => $start->toIso8601String(),
                'end'   => $end->toIso8601String(),
            ],
            'totals' => [
                'completed'          => $current->count(),
                'revenue'            => round((float) $this->revenue($current), 2),
                'previous_completed' => $previous->count(),
                'previous_revenue'   => round((float) $this->revenue($previous), 2),
                'trend'              => $this->trend($current->count(), $previous->count()),
            ],
            'top_services' => $topServices,
            'monthly'      => $monthly,
            'busy_slots'   => $this->busySlots($current),
            'employees'    => $this->employees($current),
            'loyalty'      => $this->loyalty($current),
            'payment_methods' => $this->paymentMethods($current),
            'forecast'     => $this->forecast($monthly, $now),
        ];
    }

    /**
     * @param  Collection<int, Appointment>  $current
     * @param  Collection<int, Appointment>  $previous
     * @return list<array<string, mixed>>
     */
    private function topServices(Collection $current, Collection $previous): array
    {
        $total = $current->count();
        $previousCounts = $previous->countBy('service_id');

        return $current
            ->groupBy('service_id')
            ->map(function (Collection $group, $serviceId) use ($total, $previousCounts) {
                $count = $group->count();
                $previousCount = (int) ($previousCounts[$serviceId] ?? 0);

                return [
                    'service_id'     => (int) $serviceId,
                    'name'           => $group->first()->service?->name ?? 'Prestation supprimée',
                    'count'          => $count,
                    'share'          => $total > 0 ? round($count / $total * 100, 1) : 0.0,
                    'revenue'        => round((float) $this->revenue($group), 2),
                    'previous_count' => $previousCount,
                    'trend'          => $this->trend($count, $previousCount),
                ];
            })
            ->sortByDesc('count')
            ->take(self::TOP_SERVICES_LIMIT)
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Appointment>  $appointments
     * @param  list<int>  $topServiceIds
     * @return list<array<string, mixed>>
     */
    private function monthly(Collection $appointments, Carbon $historyStart, Carbon $now, array $topServiceIds): array
    {
        $byMonth = $appointments
            ->filter(fn (Appointment $a) => $a->scheduled_at->gte($historyStart))
            ->groupBy(fn (Appointment $a) => $a->scheduled_at->format('Y-m'));

        $months = [];
        $cursor = $historyStart->copy();

        while ($cursor->lte($now)) {
            $key = $cursor->format('Y-m');
            /** @var Collection<int, Appointment> $group */
            $group = $byMonth->get($key, collect());

            $byService = [];
            foreach ($topServiceIds as $serviceId) {
                $byService[(string) $serviceId] = $group->where('service_id', $serviceId)->count();
            }

            $months[] = [
                'month'      => $key,
                'total'      => $group->count(),
                'revenue'    => round((float) $this->revenue($group), 2),
                'by_service' => $byService,
                'is_partial' => $key === $now->format('Y-m'),
            ];

            $cursor->addMonth();
        }

        return $months;
    }

    /**
     * Affluence par jour de la semaine (1 = lundi … 7 = dimanche) et moment de la journée.
     *
     * @param  Collection<int, Appointment>  $current
     * @return list<array<string, mixed>>
     */
    private function busySlots(Collection $current): array
    {
        $counts = $current->countBy(
            fn (Appointment $a) => $a->scheduled_at->dayOfWeekIso.'|'.$this->dayPart($a->scheduled_at)
        );

        $slots = [];
        foreach (range(1, 7) as $day) {
            foreach (['matin', 'apres_midi', 'soir'] as $part) {
                $slots[] = [
                    'day'   => $day,
                    'part'  => $part,
                    'count' => (int) ($counts["{$day}|{$part}"] ?? 0),
                ];
            }
        }

        return $slots;
    }

    private function dayPart(Carbon $moment): string
    {
        return match (true) {
            $moment->hour < 12 => 'matin',
            $moment->hour < 17 => 'apres_midi',
            default            => 'soir',
        };
    }

    /**
     * @param  Collection<int, Appointment>  $current
     * @return list<array<string, mixed>>
     */
    private function employees(Collection $current): array
    {
        return $current
            ->whereNotNull('employee_id')
            ->groupBy('employee_id')
            ->map(fn (Collection $group, $employeeId) => [
                'employee_id' => (int) $employeeId,
                'name'        => $group->first()->employee?->name ?? 'Employé retiré',
                'count'       => $group->count(),
                'revenue'     => round((float) $this->revenue($group), 2),
            ])
            ->sortByDesc('count')
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Appointment>  $current
     * @return array<string, int|float>
     */
    private function loyalty(Collection $current): array
    {
        $visitsByClient = $current->whereNotNull('client_id')->countBy('client_id');
        $clients = $visitsByClient->count();
        $returning = $visitsByClient->filter(fn (int $visits) => $visits >= 2)->count();

        return [
            'clients'   => $clients,
            'returning' => $returning,
            'rate'      => $clients > 0 ? round($returning / $clients * 100, 1) : 0.0,
        ];
    }

    /**
     * Prévision du nombre de prestations du mois prochain, par une règle explicable :
     * - avec un historique de 12 mois : même mois l'an dernier × tendance des 3 derniers mois
     *   (3 derniers mois de cette année ÷ les 3 mêmes mois l'an dernier) ;
     * - sinon : moyenne des 3 derniers mois complets.
     * Aucune prévision sans au moins 3 mois complets ayant de l'activité.
     *
     * @param  list<array<string, mixed>>  $monthly
     * @return array<string, mixed>
     */
    private function forecast(array $monthly, Carbon $now): array
    {
        $complete = array_values(array_filter($monthly, fn (array $m) => ! $m['is_partial']));
        $active = array_filter($complete, fn (array $m) => $m['total'] > 0);
        $nextMonth = $now->copy()->startOfMonth()->addMonth()->format('Y-m');

        if (count($active) < self::FORECAST_MIN_MONTHS) {
            return [
                'month'    => $nextMonth,
                'expected' => null,
                'method'   => 'insufficient_data',
                'months_of_history' => count($active),
                'peak_month' => null,
            ];
        }

        $byKey = array_column($complete, 'total', 'month');
        $lastThree = array_slice($complete, -3);
        $recentSum = array_sum(array_column($lastThree, 'total'));

        $sameMonthLastYear = $byKey[$now->copy()->startOfMonth()->addMonth()->subYear()->format('Y-m')] ?? null;
        $lastYearSum = 0;
        foreach ($lastThree as $month) {
            $lastYearSum += $byKey[Carbon::createFromFormat('Y-m', $month['month'])->subYear()->format('Y-m')] ?? 0;
        }

        if ($sameMonthLastYear !== null && $sameMonthLastYear > 0 && $lastYearSum > 0) {
            $expected = (int) round($sameMonthLastYear * ($recentSum / $lastYearSum));
            $method = 'seasonal';
        } else {
            $expected = (int) round($recentSum / count($lastThree));
            $method = 'moving_average';
        }

        $peak = collect($complete)->sortByDesc('total')->first();

        return [
            'month'    => $nextMonth,
            'expected' => $expected,
            'method'   => $method,
            'months_of_history' => count($active),
            'peak_month' => $peak && $peak['total'] > 0 ? ['month' => $peak['month'], 'total' => $peak['total']] : null,
        ];
    }

    /**
     * @param  Collection<int, Appointment>  $appointments
     */
    private function revenue(Collection $appointments): float
    {
        return (float) $appointments->sum(fn (Appointment $a) => $a->collectedAmount());
    }

    /**
     * Répartition des encaissements par moyen de paiement (espèces, Wave, Orange Money).
     *
     * @param  Collection<int, Appointment>  $current
     * @return list<array<string, mixed>>
     */
    private function paymentMethods(Collection $current): array
    {
        $total = $this->revenue($current);

        return collect(Appointment::PAYMENT_METHODS)
            ->map(function (string $method) use ($current, $total) {
                $group = $current->where('payment_method', $method);
                $amount = $this->revenue($group);

                return [
                    'method' => $method,
                    'count'  => $group->count(),
                    'amount' => round($amount, 2),
                    'share'  => $total > 0 ? round($amount / $total * 100, 1) : 0.0,
                ];
            })
            ->all();
    }

    private function trend(int $current, int $previous): ?float
    {
        if ($previous === 0) {
            return null; // Pas de base de comparaison : on n'affiche pas de pourcentage trompeur.
        }

        return round(($current - $previous) / $previous * 100, 1);
    }
}
