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
use Illuminate\Support\Carbon;
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
            // Fenêtre de dates facultative : l'agenda et les rappels de demain
            // ne dépendent plus des 100 derniers rendez-vous seulement.
            'from'     => 'sometimes|date',
            'to'       => 'sometimes|date|after_or_equal:from',
            'per_page' => 'sometimes|integer|min:1|max:500',
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

        $windowed = isset($validated['from']) || isset($validated['to']);
        if (isset($validated['from'])) {
            $query->where('scheduled_at', '>=', Carbon::parse($validated['from']));
        }
        if (isset($validated['to'])) {
            $query->where('scheduled_at', '<=', Carbon::parse($validated['to']));
        }

        return response()->json(
            $query->orderBy('scheduled_at', $windowed ? 'asc' : 'desc')->paginate($validated['per_page'] ?? 10)
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

        // Au plus MAX_PENDING_PER_SALON demandes en attente par cliente et par
        // salon : une personne ne peut pas bloquer l'agenda d'un salon avec des
        // réservations qu'elle n'honorera pas.
        $pending = Appointment::where('client_id', Auth::id())
            ->where('salon_id', $validated['salon_id'])
            ->where('status', 'pending')
            ->where('scheduled_at', '>', now())
            ->count();
        if ($pending >= Appointment::MAX_PENDING_PER_SALON) {
            throw ValidationException::withMessages(['scheduled_at' => [
                'Vous avez déjà '.Appointment::MAX_PENDING_PER_SALON.' demandes en attente dans ce salon. '
                .'Attendez sa réponse ou annulez-en une avant d\'en faire une nouvelle.',
            ]]);
        }

        $appointment = $this->bookSlot($validated, [
            'client_id' => Auth::id(),
            'status'    => 'pending',
            'source'    => 'online',
        ]);

        if (!$appointment) {
            return response()->json(['error' => 'Aucun employé disponible pour ce créneau.'], 409);
        }

        $appointment->load('client', 'salon', 'service');
        Mail::to($appointment->client->email)->send(new AppointmentCreated($appointment));

        return response()->json($appointment->load('employee'), 201);
    }

    // POST /manager/appointments
    // Rendez-vous pris hors de NADCEL (WhatsApp, Instagram, Facebook, TikTok, téléphone, sur place) et saisi par
    // le gérant : mêmes contrôles de créneau que la réservation en ligne, la cliente
    // n'a pas besoin de compte, le rendez-vous est confirmé d'emblée.
    public function storeOffline(Request $request)
    {
        $validated = $request->validate([
            'salon_id'     => 'required|exists:salons,id',
            'service_id'   => 'required|exists:services,id',
            'employee_id'  => 'nullable|integer|exists:employees,id',
            'scheduled_at' => 'required|date|after:now',
            'client_name'  => 'required|string|max:120',
            'client_phone' => ['required', 'string', 'max:30', 'regex:/^[0-9+ ().-]{7,30}$/'],
            'source'       => 'required|string|in:'.implode(',', Appointment::OFFLINE_SOURCES),
            'notes'        => 'nullable|string|max:500',
        ]);

        $this->authorize('manageServices', Salon::findOrFail($validated['salon_id']));

        $appointment = $this->bookSlot($validated, [
            'client_id'    => null,
            'client_name'  => trim($validated['client_name']),
            'client_phone' => trim($validated['client_phone']),
            'source'       => $validated['source'],
            'notes'        => $validated['notes'] ?? null,
            'status'       => 'confirmed',
        ]);

        if (!$appointment) {
            return response()->json(['error' => 'Aucun membre de l\'équipe n\'est libre sur ce créneau.'], 409);
        }

        return response()->json($appointment->load('salon', 'service', 'employee:id,salon_id,name'), 201);
    }

    // PATCH /manager/appointments/{id}/reminder
    // Le gérant a envoyé le rappel par WhatsApp depuis son téléphone : on le note
    // pour ne pas relancer la cliente deux fois. Idempotent.
    public function markReminderSent(Appointment $appointment)
    {
        $this->authorize('pay', $appointment);

        if (!in_array($appointment->status, ['pending', 'confirmed'], true)) {
            throw ValidationException::withMessages([
                'status' => ['Ce rendez-vous n\'est plus à venir.'],
            ]);
        }

        if ($appointment->reminder_sent_at === null) {
            $appointment->update(['reminder_sent_at' => now()]);
        }

        return response()->json($appointment->load('salon', 'service', 'employee:id,salon_id,name'));
    }

    /**
     * Réserve un créneau de façon sûre (verrous, horaires, employé compétent et libre)
     * et crée le rendez-vous avec les attributs propres au canal de réservation.
     * Retourne null si aucun employé n'est disponible.
     */
    private function bookSlot(array $validated, array $attributes): ?Appointment
    {
        $scheduledAt = \Illuminate\Support\Carbon::parse($validated['scheduled_at'])
            ->setTimezone(config('app.timezone'));

        return DB::transaction(function () use ($validated, $scheduledAt, $attributes) {
            $salon = Salon::lockForUpdate()->findOrFail($validated['salon_id']);
            $service = Service::lockForUpdate()->findOrFail($validated['service_id']);

            if ($service->salon_id !== $salon->id) {
                throw ValidationException::withMessages([
                    'service_id' => ['Le service sélectionné n’appartient pas à ce salon.'],
                ]);
            }

            abort_unless($salon->isPublic() && $service->is_active, 404);

            if (isset($validated['employee_id']) && !$service->employees()
                ->where('employees.salon_id', $salon->id)->where('employees.is_active', true)->where('employees.id', $validated['employee_id'])->exists()) {
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
                ...$attributes,
                'salon_id'     => $salon->id,
                'service_id'   => $service->id,
                'employee_id'  => $employee->id,
                'scheduled_at' => $scheduledAt,
                'duration'     => $service->duration,
                'price'        => $service->price,
            ]);
        }, attempts: 3);
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
            // « Terminé » passe uniquement par la clôture (PATCH /complete) : elle
            // exige que le rendez-vous ait commencé et enregistre le paiement.
            if ($nouveauStatut === 'completed') {
                throw ValidationException::withMessages(['status' => [
                    'Pour terminer un rendez-vous, utilisez la clôture avec le montant encaissé.',
                ]]);
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

        // Rendez-vous saisi par le salon sans compte client : pas d'e-mail possible.
        if (!$appointment->client) {
            return response()->json($appointment->load('employee'));
        }

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

            // Une demande restée « en attente » dont la cliente est bien venue se
            // clôture directement : inutile de la confirmer après coup.
            if (!in_array($locked->status, ['pending', 'confirmed'], true)) {
                throw ValidationException::withMessages([
                    'status' => ['Ce rendez-vous est déjà terminé ou annulé.'],
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
