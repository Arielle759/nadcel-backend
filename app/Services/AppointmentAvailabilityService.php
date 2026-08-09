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

        $existing = Appointment::where('employee_id', $employee->id)
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
        bool $lockForUpdate = false
    ): ?Employee {
        $employees = $service->employees()
            ->where('salon_id', $salon->id)
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
     * Renvoie true si le jour contient au moins un créneau libre (9h-19h, pas 30 min).
     *
     * @param Collection<int, Employee>    $employees
     * @param Collection<int, Appointment> $appointments
     */
    public function isDayAvailable(
        Collection $employees,
        Carbon $day,
        int $duration,
        Collection $appointments
    ): bool {
        $slot   = $day->copy()->setTime(9, 0);
        $cutoff = $day->copy()->setTime(19, 0)->subMinutes($duration);

        while ($slot->lte($cutoff)) {
            if ($this->hasAvailableEmployee($employees, $slot, $duration, $appointments)) {
                return true;
            }
            $slot->addMinutes(30);
        }

        return false;
    }

    /**
     * Retourne la liste des heures disponibles ("H:i") pour un jour donné.
     *
     * @param  Collection<int, Employee>    $employees
     * @param  Collection<int, Appointment> $appointments
     * @return list<string>
     */
    public function availableSlotsForDay(
        Collection $employees,
        Carbon $day,
        int $duration,
        Collection $appointments
    ): array {
        $slot   = $day->copy()->setTime(9, 0);
        $cutoff = $day->copy()->setTime(19, 0)->subMinutes($duration);
        $slots  = [];

        while ($slot->lte($cutoff)) {
            if ($this->hasAvailableEmployee($employees, $slot, $duration, $appointments)) {
                $slots[] = $slot->format('H:i');
            }
            $slot->addMinutes(30);
        }

        return $slots;
    }
}
