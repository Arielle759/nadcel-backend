<?php

namespace App\Http\Controllers\Api;

use App\Models\Employee;
use App\Models\Salon;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use App\Http\Controllers\Controller;

class EmployeeController extends Controller
{
    public function index(Request $request)
    {
        $salonId = $request->query('salon_id');

        $employees = Employee::when($salonId, fn ($q) => $q->where('salon_id', $salonId))
            ->with('salon', 'user', 'services')
            ->paginate(10);

        return response()->json($employees);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'salon_id'   => 'required|exists:salons,id',
            'name'       => 'required|string|max:255',
            'phone'      => 'required|string',
            'email'      => 'required|email|unique:users,email',
            'service_ids' => 'sometimes|array',
            'service_ids.*' => 'exists:services,id',
        ]);

        $salon = Salon::findOrFail($validated['salon_id']);
        $this->authorize('manageEmployees', $salon);

        $employee = DB::transaction(function () use ($validated, $salon) {
            $user = User::create([
                'name'     => $validated['name'],
                'email'    => $validated['email'],
                'password' => Hash::make(Str::random(16)),
            ]);
            $user->assignRole('employee');

            $employee = Employee::create([
                'user_id'  => $user->id,
                'salon_id' => $salon->id,
                'name'     => $validated['name'],
                'phone'    => $validated['phone'],
            ]);

            if (!empty($validated['service_ids'])) {
                $employee->services()->sync($validated['service_ids']);
            }

            return $employee;
        });

        return response()->json($employee->load('user', 'services'), 201);
    }

    public function show(Employee $employee)
    {
        $employee->load('salon', 'user', 'services', 'appointments');
        return response()->json($employee);
    }

    public function update(Request $request, Employee $employee)
    {
        $this->authorize('manageEmployees', $employee->salon);

        $validated = $request->validate([
            'name'          => 'sometimes|string|max:255',
            'phone'         => 'sometimes|string',
            'service_ids'   => 'sometimes|array',
            'service_ids.*' => 'exists:services,id',
        ]);

        $employee->update(array_filter(
            $validated,
            fn ($key) => in_array($key, ['name', 'phone']),
            ARRAY_FILTER_USE_KEY
        ));

        if ($request->has('service_ids')) {
            $employee->services()->sync($validated['service_ids']);
        }

        return response()->json($employee->load('user', 'services'));
    }

    public function destroy(Employee $employee)
    {
        $this->authorize('manageEmployees', $employee->salon);

        $employee->delete();
        return response()->noContent();
    }
}
