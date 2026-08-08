<?php

namespace App\Http\Controllers\Api;

use App\Models\Appointment;
use App\Models\Salon;
use App\Models\Service;
use App\Services\AppointmentAvailabilityService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

        $service    = Service::findOrFail($validated['service_id']);
        $monthStart = Carbon::createFromFormat('Y-m', $validated['month'])->startOfMonth();
        $monthEnd   = $monthStart->copy()->endOfMonth();
        $today      = Carbon::today();

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

        $result  = [];
        $current = $monthStart->copy();

        while ($current->lte($monthEnd)) {
            $result[$current->toDateString()] = $current->lt($today)
                ? false
                : $this->availability->isDayAvailable(
                    $employees,
                    $current,
                    $service->duration,
                    $appointments,
                );

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

        $day   = Carbon::parse($date)->startOfDay();
        $today = Carbon::today();

        if ($day->lt($today)) {
            return response()->json([]);
        }

        $service = Service::findOrFail($validated['service_id']);

        $appointments = Appointment::where('salon_id', $salon->id)
            ->whereNotIn('status', ['cancelled'])
            ->whereDate('scheduled_at', $day->toDateString())
            ->get(['employee_id', 'scheduled_at', 'duration']);

        $employees = $service->employees()->where('salon_id', $salon->id)->get();

        return response()->json(
            $this->availability->availableSlotsForDay($employees, $day, $service->duration, $appointments)
        );
    }
}
