<?php

namespace App\Http\Controllers\Api;

use App\Mail\AppointmentCancelled;
use App\Mail\AppointmentConfirmed;
use App\Mail\AppointmentCreated;
use App\Models\Appointment;
use App\Models\Service;
use App\Models\Salon;
use Illuminate\Support\Facades\Gate;
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
        $validated = $request->validate([
            // scope=salon : l'agenda du gérant, sans ses réservations personnelles de client.
            'scope'    => 'sometimes|string|in:salon',
            'per_page' => 'sometimes|integer|min:1|max:100',
        ]);

        $user = $request->user();
        $query = Appointment::with('salon', 'service', 'employee:id,salon_id,name', 'client', 'review');

        if ($user->hasRole('admin')) {
            // pas de filtre, voit tout
        } elseif ($user->hasRole('gerant')) {
            if (($validated['scope'] ?? null) === 'salon') {
                $query->whereHas('salon', fn ($sq) => $sq->where('manager_id', $user->id));
            } else {
                $query->where(fn ($q) => $q->where('client_id', $user->id)
                    ->orWhereHas('salon', fn ($sq) => $sq->where('manager_id', $user->id)));
            }
        } else {
            $query->where('client_id', $user->id);
        }

        return response()->json(
            $query->orderBy('scheduled_at', 'desc')->paginate($validated['per_page'] ?? 10)
        );
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'salon_id' => 'required|exists:salons,id',
            'service_id' => 'required|exists:services,id',
            'employee_id' => 'nullable|integer|exists:employees,id',
            'scheduled_at' => 'required|date|after:now',
        ]);

        $scheduledAt = \Illuminate\Support\Carbon::parse($validated['scheduled_at'])
            ->setTimezone(config('app.timezone'));
        $appointment = DB::transaction(function () use ($validated, $scheduledAt) {
        $salon = Salon::lockForUpdate()->findOrFail($validated['salon_id']);
        $service = Service::lockForUpdate()->findOrFail($validated['service_id']);

        if ($service->salon_id !== $salon->id) {
            throw ValidationException::withMessages([
                'service_id' => ['Le service sélectionné n’appartient pas à ce salon.'],
            ]);
        }

        abort_unless($salon->is_active && $salon->is_verified && $service->is_active, 404);

        if (isset($validated['employee_id']) && !$service->employees()
            ->where('employees.salon_id', $salon->id)->where('employees.id', $validated['employee_id'])->exists()) {
            throw ValidationException::withMessages([
                'employee_id' => ['Cet employé ne réalise pas ce service dans ce salon.'],
            ]);
        }

        $hours = $salon->openingHoursFor($scheduledAt);
        $appointmentEnd = $scheduledAt->copy()->addMinutes($service->duration);
        if (!$hours || $scheduledAt->lt($hours['open']) || $appointmentEnd->gt($hours['close'])) {
            throw ValidationException::withMessages([
                'scheduled_at' => ['Ce créneau est en dehors des horaires d’ouverture du salon.'],
            ]);
        }

            // Sérialise le choix d'un employé pour éviter une double réservation
            // lorsque deux requêtes arrivent exactement au même moment.
            $employee = $this->availability->findAvailableEmployee(
                $salon,
                $service,
                $scheduledAt,
                $service->duration,
                lockForUpdate: true,
                employeeId: $validated['employee_id'] ?? null,
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
            'status' => 'required|string|in:pending,confirmed,completed,cancelled',
        ]);

        $user = $request->user();
        $nouveauStatut = $validated['status'];

        $appointment = DB::transaction(function () use ($appointment, $user, $nouveauStatut) {
            $locked = Appointment::lockForUpdate()->findOrFail($appointment->id);
            $isSalonSide = Gate::forUser($user)->allows('manage', $locked);
            if (!$isSalonSide) {
                abort_unless($locked->client_id === $user->id && $nouveauStatut === 'cancelled', 403);
            }
            if (!$locked->canTransitionTo($nouveauStatut)) {
                throw ValidationException::withMessages(['status' => ['Transition invalide.']]);
            }
            if (!$isSalonSide && !$locked->clientCanCancel()) {
                throw ValidationException::withMessages(['status' => [
                    'Ce rendez-vous commence dans moins de '.Appointment::CLIENT_CANCELLATION_HOURS
                    .' h : contactez directement le salon pour l\'annuler.',
                ]]);
            }
            $locked->update(['status' => $nouveauStatut]);
            return $locked;
        }, attempts: 3);
        $appointment->load('client', 'salon.manager', 'service');

        if ($nouveauStatut === 'confirmed') {
            Mail::to($appointment->client->email)->send(new AppointmentConfirmed($appointment));
        } elseif ($nouveauStatut === 'cancelled') {
            $initiatedByClient = $user->id === $appointment->client_id;
            if ($initiatedByClient && $appointment->salon?->manager) {
                Mail::to($appointment->salon->manager->email)->send(new AppointmentCancelled($appointment, true));
            } else {
                Mail::to($appointment->client->email)->send(new AppointmentCancelled($appointment, false));
            }
        }

        return response()->json($appointment->load('employee'));
    }

    public function pay(Request $request, Appointment $appointment)
    {
        $appointment = DB::transaction(function () use ($appointment) {
            $locked = Appointment::lockForUpdate()->findOrFail($appointment->id);
            $this->authorize('pay', $locked);
            if ($locked->status === 'cancelled') {
                throw ValidationException::withMessages(['status' => ['Cannot pay a cancelled appointment.']]);
            }
            if ($locked->payment_status !== 'paid') {
                $locked->update(['payment_status' => 'paid']);
            }
            return $locked;
        }, attempts: 3);
        return response()->json($appointment->load('salon', 'service', 'employee', 'client'));
    }

    // PATCH /appointments/{id}/complete
    // Clôture d'un rendez-vous réalisé : le client est venu, le gérant note en une seule
    // action le montant réellement encaissé sur place et le moyen de paiement utilisé.
    public function complete(Request $request, Appointment $appointment)
    {
        $validated = $request->validate([
            'amount_paid'    => 'required|numeric|min:0|max:10000000',
            'payment_method' => 'required|string|in:'.implode(',', Appointment::PAYMENT_METHODS),
        ]);

        $appointment = DB::transaction(function () use ($appointment, $validated) {
            $locked = Appointment::lockForUpdate()->findOrFail($appointment->id);
            $this->authorize('pay', $locked);

            if (!$locked->canTransitionTo('completed')) {
                throw ValidationException::withMessages([
                    'status' => ['Seul un rendez-vous confirmé peut être clôturé.'],
                ]);
            }
            if ($locked->scheduled_at->isFuture()) {
                throw ValidationException::withMessages([
                    'status' => ['Un rendez-vous ne peut être clôturé qu’après son heure de début.'],
                ]);
            }

            $locked->update([
                'status'         => 'completed',
                'payment_status' => 'paid',
                'amount_paid'    => $validated['amount_paid'],
                'payment_method' => $validated['payment_method'],
                'paid_at'        => now(),
            ]);

            return $locked;
        }, attempts: 3);

        return response()->json($appointment->load('salon', 'service', 'employee', 'client'));
    }
}
