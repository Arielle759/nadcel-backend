<?php

namespace App\Http\Controllers\Api;

use App\Models\Appointment;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class AppointmentController extends Controller
{
    public function index()
    {
        $appointments = Appointment::where('client_id', auth()->user()?->id)
            ->with('salon', 'service', 'employee')
            ->orderBy('scheduled_at', 'desc')
            ->paginate(10);

        return response()->json($appointments);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'salon_id' => 'required|exists:salons,id',
            'service_id' => 'required|exists:services,id',
            'employee_id' => 'nullable|exists:employees,id',
            'scheduled_at' => 'required|date|after:now',
        ]);

        $appointment = Appointment::create([
            ...$validated,
            'client_id' => auth()->user()?->id,
            'status' => 'pending',
        ]);

        return response()->json($appointment, 201);
    }

    public function show(Appointment $appointment)
    {
        $appointment->load('salon', 'service', 'employee', 'review');
        return response()->json($appointment);
    }

    public function update(Request $request, Appointment $appointment)
    {
        if (auth()->user()?->id !== $appointment->client_id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'status' => 'string|in:pending,confirmed,completed,cancelled',
        ]);

        $appointment->update($validated);
        return response()->json($appointment);
    }

    public function destroy(Appointment $appointment)
    {
        if (auth()->user()?->id !== $appointment->client_id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $appointment->delete();
        return response()->noContent();
    }
}
