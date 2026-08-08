<?php

namespace App\Http\Controllers\Api;

use App\Models\Appointment;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ManagerController extends Controller
{
    public function salons(Request $request): JsonResponse
    {
        $salons = $request->user()->salons()->with('services')->paginate(10);

        return response()->json($salons);
    }

    public function stats(Request $request)
    {
        $salonIds = $request->user()->salons()->pluck('id');

        $appointmentStats = Appointment::whereIn('salon_id', $salonIds)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $generated = (float) Appointment::whereIn('salon_id', $salonIds)
            ->where('status', 'completed')
            ->sum('price');
        $collected = (float) Appointment::whereIn('salon_id', $salonIds)
            ->where('status', 'completed')
            ->where('payment_status', 'paid')
            ->sum('price');

        return response()->json([
            'salons_count' => count($salonIds),
            'appointments' => $appointmentStats,
            'revenue'      => [
                'generated'   => $generated,
                'collected'   => $collected,
                'outstanding' => $generated - $collected,
            ],
        ]);
    }
}
