<?php

namespace App\Http\Controllers\Api;

use App\Models\Appointment;
use App\Models\Review;
use App\Models\Salon;
use App\Models\User;
use App\Http\Controllers\Controller;
use Spatie\Permission\Models\Role;

class AdminController extends Controller
{
    // GET /admin/salons
    public function allSalons()
    {
        $salons = Salon::with('manager')->paginate(10);

        return response()->json($salons);
    }

    // GET /admin/salons/pending
    public function pendingSalons()
    {
        $salons = Salon::where('is_verified', false)
            ->where('is_active', true)
            ->with('manager')
            ->paginate(10);

        return response()->json($salons);
    }

    // PATCH /admin/salons/{salon}/verify
    public function verifySalon(Salon $salon)
    {
        $salon->verify();

        return response()->json($salon);
    }

    // PATCH /admin/salons/{salon}/reject
    public function rejectSalon(Salon $salon)
    {
        $salon->reject();

        return response()->json($salon);
    }

    // PATCH /admin/salons/{salon}/suspend
    public function suspendSalon(Salon $salon)
    {
        $salon->suspend();

        return response()->json($salon);
    }

    // PATCH /admin/salons/{salon}/reactivate
    public function reactivateSalon(Salon $salon)
    {
        $salon->reactivate();

        return response()->json($salon);
    }

    // DELETE /admin/salons/{salon}
    public function deleteSalon(Salon $salon)
    {
        $salon->delete();

        return response()->noContent();
    }

    // DELETE /admin/reviews/{review}
    public function deleteReview(Review $review)
    {
        $review->delete();

        return response()->noContent();
    }

    // GET /admin/stats
    public function stats()
    {
        $salonStats = [
            'total'    => Salon::count(),
            'verified' => Salon::where('is_verified', true)->count(),
            'pending'  => Salon::where('is_verified', false)->where('is_active', true)->count(),
            'rejected' => Salon::where('is_active', false)->count(),
        ];

        $appointmentStats = Appointment::selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $generated = (float) Appointment::where('status', 'completed')->sum('price');
        $collected = (float) Appointment::where('status', 'completed')
            ->where('payment_status', 'paid')
            ->sum('price');
        $revenue = [
            'generated'   => $generated,
            'collected'   => $collected,
            'outstanding' => $generated - $collected,
        ];

        $usersByRole = Role::withCount('users')
            ->get(['name', 'users_count'])
            ->map(fn (Role $role) => [
                'role'  => $role->name,
                'count' => $role->users_count,
            ]);

        $usersWithoutRole = User::doesntHave('roles')->count();

        return response()->json([
            'salons'       => $salonStats,
            'appointments' => $appointmentStats,
            'revenue'      => $revenue,
            'users'        => [
                'by_role'      => $usersByRole,
                'without_role' => $usersWithoutRole,
            ],
        ]);
    }
}
