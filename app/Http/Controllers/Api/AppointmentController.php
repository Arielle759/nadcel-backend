<?php

namespace App\Http\Controllers\Api;

use App\Mail\AppointmentCancelled;
use App\Mail\AppointmentConfirmed;
use App\Mail\AppointmentCreated;
use App\Models\Appointment;
use App\Models\Service;
use App\Services\AppointmentAvailabilityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use App\Http\Controllers\Controller;

class AppointmentController extends Controller
{
    public function __construct(
        private readonly AppointmentAvailabilityService $availability
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $query = Appointment::with('salon', 'service', 'employee.user', 'client', 'review');

        if ($user->hasRole('admin')) {
            // pas de filtre, voit tout
        } elseif ($user->hasRole('gerant')) {
            $query->whereHas('salon', fn ($q) => $q->where('manager_id', $user->id));
        } else {
            $query->where('client_id', $user->id);
        }

        return response()->json($query->orderBy('scheduled_at', 'desc')->paginate(10));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'salon_id' => 'required|exists:salons,id',
            'service_id' => 'required|exists:services,id',
            'employee_id' => 'nullable|exists:employees,id',
            'scheduled_at' => 'required|date|after:now',
        ]);

        $service = Service::findOrFail($validated['service_id']);
        $salon = \App\Models\Salon::findOrFail($validated['salon_id']);
        $scheduledAt = \Illuminate\Support\Carbon::parse($validated['scheduled_at']);

        if ($service->salon_id !== $salon->id) {
            throw ValidationException::withMessages([
                'service_id' => ['Le service sélectionné n’appartient pas à ce salon.'],
            ]);
        }

        $appointment = DB::transaction(function () use ($salon, $service, $scheduledAt) {
            // Sérialise le choix d'un employé pour éviter une double réservation
            // lorsque deux requêtes arrivent exactement au même moment.
            $employee = $this->availability->findAvailableEmployee(
                $salon,
                $service,
                $scheduledAt,
                $service->duration,
                lockForUpdate: true,
            );

            if (!$employee) {
                return null;
            }

            return Appointment::create([
                'client_id' => Auth::id(),
                'salon_id' => $salon->id,
                'service_id' => $service->id,
                'employee_id' => $employee->id,
                'scheduled_at' => $scheduledAt,
                'duration' => $service->duration,
                'price' => $service->price,
                'status' => 'pending',
            ]);
        }, attempts: 3);

        if (!$appointment) {
            return response()->json(['error' => 'Aucun employé disponible pour ce créneau.'], 409);
        }

        $appointment->load('client', 'salon', 'service');
        Mail::to($appointment->client->email)->send(new AppointmentCreated($appointment));

        return response()->json($appointment->load('employee'), 201);
    }

    public function show(Request $request, Appointment $appointment)
    {
        $this->authorize('view', $appointment);
        $appointment->load('salon', 'service', 'employee', 'review');
        return response()->json($appointment);
    }

    public function update(Request $request, Appointment $appointment)
    {
        $validated = $request->validate([
            'status' => 'required|string|in:pending,confirmed,in_progress,completed,cancelled',
        ]);

        $user = $request->user();
        $nouveauStatut = $validated['status'];

        if ($user->hasRole('client')) {
            if ($appointment->client_id !== $user->id || $nouveauStatut !== 'cancelled') {
                return response()->json(['error' => 'Action non autorisée'], 403);
            }
        } else {
            $this->authorize('manage', $appointment);
            if (!$appointment->canTransitionTo($nouveauStatut)) {
                return response()->json(['error' => 'Transition invalide'], 422);
            }
        }

        $appointment->update(['status' => $nouveauStatut]);
        $appointment->load('client', 'salon.manager', 'service');

        if ($nouveauStatut === 'confirmed') {
            Mail::to($appointment->client->email)->send(new AppointmentConfirmed($appointment));
        } elseif ($nouveauStatut === 'cancelled') {
            $initiatedByClient = $user->id === $appointment->client_id;
            if ($initiatedByClient) {
                Mail::to($appointment->salon->manager->email)->send(new AppointmentCancelled($appointment, true));
            } else {
                Mail::to($appointment->client->email)->send(new AppointmentCancelled($appointment, false));
            }
        }

        return response()->json($appointment->load('employee'));
    }

    public function pay(Request $request, Appointment $appointment)
    {
        $this->authorize('pay', $appointment);

        if ($appointment->status === 'cancelled') {
            return response()->json(['error' => 'Cannot pay a cancelled appointment.'], 422);
        }
        if ($appointment->payment_status === 'paid') {
            return response()->json($appointment->load('salon', 'service', 'employee', 'client'));
        }

        $appointment->update(['payment_status' => 'paid']);
        return response()->json($appointment->load('salon', 'service', 'employee', 'client'));
    }
}
