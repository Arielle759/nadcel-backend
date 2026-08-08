<?php

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api;

Route::get('/health', function () {
    return response()->json(['status' => 'ok']);
});

Route::post('/auth/login', function (Request $request) {
    $credentials = $request->validate([
        'email' => 'required|email',
        'password' => 'required',
    ]);

    $token = auth('api')->attempt($credentials);

    if (!$token) {
        return response()->json(['error' => 'Invalid credentials'], 401);
    }

    return response()->json([
        'token' => $token,
        'user' => auth('api')->user()->load('roles'),
    ]);
})->middleware('throttle:5,1');

Route::post('/auth/register', function (Request $request) {
    $validated = $request->validate([
        'name'     => 'required|string|max:255',
        'email'    => 'required|email|unique:users,email',
        'password' => 'required|string|min:8',
        'role'     => 'sometimes|string|in:client,gerant',
    ]);

    $user = User::create([
        'name'     => $validated['name'],
        'email'    => $validated['email'],
        'password' => Hash::make($validated['password']),
    ]);

    $user->assignRole($validated['role'] ?? 'client');

    $token = auth('api')->login($user);

    return response()->json([
        'token' => $token,
        'user'  => $user->load('roles'),
    ], 201);
})->middleware('throttle:10,1');

// Routes publiques (lecture seule uniquement)
Route::get('/salons', [Api\SalonController::class, 'index']);
Route::get('/salons/{salon}/availability', [Api\AvailabilityController::class, 'month']);
Route::get('/salons/{salon}/availability/{date}', [Api\AvailabilityController::class, 'day']);
Route::get('/salons/{salon}', [Api\SalonController::class, 'show']);
Route::get('/services', [Api\ServiceController::class, 'index']);
Route::get('/services/{service}', [Api\ServiceController::class, 'show']);

// Routes protégées
Route::middleware('auth:api')->group(function () {
    Route::apiResource('salons', Api\SalonController::class)->except(['index', 'show']);
    Route::apiResource('services', Api\ServiceController::class)->except(['index', 'show']);
    Route::apiResource('employees', Api\EmployeeController::class);
    Route::apiResource('appointments', Api\AppointmentController::class)->except(['destroy']);
    Route::patch('appointments/{appointment}/pay', [Api\AppointmentController::class, 'pay']);
    Route::apiResource('reviews', Api\ReviewController::class);

    Route::middleware('role:gerant')->prefix('manager')->group(function () {
        Route::get('salons', [Api\ManagerController::class, 'salons']);
        Route::get('stats', [Api\ManagerController::class, 'stats']);
    });

    Route::middleware('role:admin')->prefix('admin')->group(function () {
        // Routes statiques en premier (évite la capture par {salon})
        Route::get('salons', [Api\AdminController::class, 'allSalons']);
        Route::get('salons/pending', [Api\AdminController::class, 'pendingSalons']);
        Route::get('stats', [Api\AdminController::class, 'stats']);
        // Routes paramétriques
        Route::patch('salons/{salon}/verify', [Api\AdminController::class, 'verifySalon']);
        Route::patch('salons/{salon}/reject', [Api\AdminController::class, 'rejectSalon']);
        Route::patch('salons/{salon}/suspend', [Api\AdminController::class, 'suspendSalon']);
        Route::patch('salons/{salon}/reactivate', [Api\AdminController::class, 'reactivateSalon']);
        Route::delete('salons/{salon}', [Api\AdminController::class, 'deleteSalon']);
        Route::delete('reviews/{review}', [Api\AdminController::class, 'deleteReview']);
    });
});
