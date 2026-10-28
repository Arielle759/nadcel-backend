<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Employee;
use App\Models\Salon;
use App\Models\Service;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class AppointmentAvailabilityService
{
    public function isEmployeeAvailable(
        Employee $employee,
        Carbon $scheduledAt,
        int $duration,
        ?int $excludeId = null
    ): bool {
        $newEnd = $scheduledAt->copy()->addMinutes($duration);

        $query = Appointment::where('employee_id', $employee->id)
            ->where('scheduled_at', '<', $newEnd);
        $endExpression = match ($query->getConnection()->getDriverName()) {
            'pgsql' => "scheduled_at + duration * interval '1 minute'",
            'sqlite' => "datetime(scheduled_at, '+' || duration || ' minutes')",
            'mysql', 'mariadb' => 'DATE_ADD(scheduled_at, INTERVAL duration MINUTE)',
            default => null,
        };
        if ($endExpression !== null) {
            $query->whereRaw($endExpression.' > ?', [$scheduledAt->toDateTimeString()]);
        }
        $existing = $query
            ->whereNotIn('status', ['cancelled'])
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
            ->get(['scheduled_at', 'duration']);

        foreach ($existing as $appt) {
            $start = Carbon::parse($appt->scheduled_at);
            $end   = $start->copy()->addMinutes($appt->duration);

            if ($start->lt($newEnd) && $end->gt($scheduledAt)) {
                return false;
            }
        }

        return true;
    }

    public function findAvailableEmployee(
        Salon $salon,
        Service $service,
        Carbon $scheduledAt,
        int $duration,
        bool $lockForUpdate = false,
        ?int $employeeId = null
    ): ?Employee {
        $employees = $service->employees()
            ->where('employees.salon_id', $salon->id)
            ->when($employeeId !== null, fn ($q) => $q->where('employees.id', $employeeId))
            ->orderBy('employees.id');

        if ($lockForUpdate) {
            $employees->lockForUpdate();
        }

        foreach ($employees->get() as $employee) {
            if ($this->isEmployeeAvailable($employee, $scheduledAt, $duration)) {
                return $employee;
            }
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Méthodes "cache-based" : reçoivent les RDV déjà chargés en mémoire
    // pour éviter une requête SQL par créneau testé.
    // -------------------------------------------------------------------------

    /**
     * @param Collection<int, Appointment> $appointments RDV pré-chargés pour la période
     */
    private function isEmployeeAvailableInCache(
        Employee $employee,
        Carbon $scheduledAt,
        int $duration,
        Collection $appointments
    ): bool {
        $newEnd = $scheduledAt->copy()->addMinutes($duration);

        foreach ($appointments->where('employee_id', $employee->id) as $appt) {
            $start = Carbon::parse($appt->scheduled_at);
            $end   = $start->copy()->addMinutes($appt->duration);
            if ($start->lt($newEnd) && $end->gt($scheduledAt)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param Collection<int, Employee>    $employees
     * @param Collection<int, Appointment> $appointments
     */
    public function hasAvailableEmployee(
        Collection $employees,
        Carbon $scheduledAt,
        int $duration,
        Collection $appointments
    ): bool {
        foreach ($employees as $employee) {
            if ($this->isEmployeeAvailableInCache($employee, $scheduledAt, $duration, $appointments)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Renvoie true si le jour contient au moins un créneau libre (pas 30 min)
     * entre $open et $close (horaires d'ouverture du salon ce jour-là).
     *
     * @param Collection<int, Employee>    $employees
     * @param Collection<int, Appointment> $appointments
     * @param Carbon|null                  $notBefore  ignore les créneaux avant cette heure (aujourd'hui déjà entamé)
     */
    public function isDayAvailable(
        Collection $employees,
        Carbon $open,
        Carbon $close,
        int $duration,
        Collection $appointments,
        ?Carbon $notBefore = null
    ): bool {
        $slot   = $open->copy();
        $cutoff = $close->copy()->subMinutes($duration);

        while ($slot->lte($cutoff)) {
            $isPast = $notBefore && $slot->lt($notBefore);
            if (!$isPast && $this->hasAvailableEmployee($employees, $slot, $duration, $appointments)) {
                return true;
            }
            $slot->addMinutes(30);
        }

        return false;
    }

    /**
     * Retourne la liste des heures disponibles ("H:i") entre $open et $close
     * (horaires d'ouverture du salon ce jour-là).
     *
     * @param  Collection<int, Employee>    $employees
     * @param  Collection<int, Appointment> $appointments
     * @param  Carbon|null                  $notBefore  ignore les créneaux avant cette heure (aujourd'hui déjà entamé)
     * @return list<string>
     */
    public function availableSlotsForDay(
        Collection $employees,
        Carbon $open,
        Carbon $close,
        int $duration,
        Collection $appointments,
        ?Carbon $notBefore = null
    ): array {
        $slot   = $open->copy();
        $cutoff = $close->copy()->subMinutes($duration);
        $slots  = [];

        while ($slot->lte($cutoff)) {
            $isPast = $notBefore && $slot->lt($notBefore);
            if (!$isPast && $this->hasAvailableEmployee($employees, $slot, $duration, $appointments)) {
                $slots[] = $slot->format('H:i');
            }
            $slot->addMinutes(30);
        }

        return $slots;
    }
}
