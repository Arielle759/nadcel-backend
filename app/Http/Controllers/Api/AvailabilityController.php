<?php

namespace App\Http\Controllers\Api;

use App\Models\Appointment;
use App\Models\Salon;
use App\Models\Service;
use App\Services\AppointmentAvailabilityService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Carbon;

class AvailabilityController extends Controller
{
    public function __construct(
        private readonly AppointmentAvailabilityService $availability
    ) {}

    // GET /salons/{salon}/availability?service_id={id}&month={YYYY-MM}
    public function month(Request $request, Salon $salon): JsonResponse
    {
        $validated = $request->validate([
            'service_id' => 'required|exists:services,id',
            'month'      => 'required|date_format:Y-m',
        ]);

        if (!$salon->isVisibleTo(Auth::user())) {
            abort(404);
        }

        $service    = Service::where('salon_id', $salon->id)->where('is_active', true)->findOrFail($validated['service_id']);
        $monthStart = Carbon::createFromFormat('!Y-m', $validated['month'])->startOfMonth();
        $monthEnd   = $monthStart->copy()->endOfMonth();
        $today      = Carbon::today();
        $now        = Carbon::now();

        // Une seule requête pour tous les RDV du mois
        $appointments = Appointment::where('salon_id', $salon->id)
            ->whereNotIn('status', ['cancelled'])
            ->whereBetween('scheduled_at', [
                $monthStart->copy()->startOfDay(),
                $monthEnd->copy()->endOfDay(),
            ])
            ->get(['employee_id', 'scheduled_at', 'duration']);

        // Une seule requête pour les employés compétents dans ce salon
        $employees = $service->employees()->where('salon_id', $salon->id)->get();

        // Une seule requête pour les fermetures exceptionnelles du mois
        $closedDates = $salon->closures()
            ->whereBetween('closed_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
            ->pluck('closed_date')
            ->map(fn ($date) => $date->toDateString())
            ->all();

        $result  = [];
        $current = $monthStart->copy();

        while ($current->lte($monthEnd)) {
            if ($current->lt($today)) {
                $result[$current->toDateString()] = false;
            } else {
                $hours = $salon->openingHoursFor($current, $closedDates);
                $result[$current->toDateString()] = $hours !== null && $this->availability->isDayAvailable(
                    $employees,
                    $hours['open'],
                    $hours['close'],
                    $service->duration,
                    $appointments,
                    $current->isToday() ? $now : null,
                );
            }

            $current->addDay();
        }

        return response()->json($result);
    }

    // GET /salons/{salon}/availability/{date}?service_id={id}
    public function day(Request $request, Salon $salon, string $date): JsonResponse
    {
        $validated = $request->validate([
            'service_id' => 'required|exists:services,id',
        ]);

        if (!$salon->isVisibleTo(Auth::user())) {
            abort(404);
        }

        validator(['date' => $date], ['date' => 'required|date_format:Y-m-d'])->validate();
        $day   = Carbon::createFromFormat('!Y-m-d', $date);
        $today = Carbon::today();

        if ($day->lt($today)) {
            return response()->json([]);
        }

        $service = Service::where('salon_id', $salon->id)->where('is_active', true)->findOrFail($validated['service_id']);

        $hours = $salon->openingHoursFor($day);
        if ($hours === null) {
            return response()->json([]);
        }

        $appointments = Appointment::where('salon_id', $salon->id)
            ->whereNotIn('status', ['cancelled'])
            ->whereDate('scheduled_at', $day->toDateString())
            ->get(['employee_id', 'scheduled_at', 'duration']);

        $employees = $service->employees()->where('salon_id', $salon->id)->get();

        return response()->json(
            $this->availability->availableSlotsForDay(
                $employees,
                $hours['open'],
                $hours['close'],
                $service->duration,
                $appointments,
                $day->isToday() ? Carbon::now() : null,
            )
        );
    }
}
