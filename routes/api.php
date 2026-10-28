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
        'password' => 'required|string|max:1024',
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
        'password' => 'required|string|min:12|max:72',
        // Toute inscription crée un compte client. Le rôle gérant s'obtient
        // uniquement quand l'administrateur valide une demande de partenariat.
        'role'     => 'sometimes|string|in:client',
    ]);

    return \Illuminate\Support\Facades\DB::transaction(function () use ($validated) {
    $user = User::create([
        'name'     => $validated['name'],
        'email'    => $validated['email'],
        'password' => Hash::make($validated['password']),
    ]);

    $user->assignRole('client');

    $token = auth('api')->login($user);

    return response()->json([
        'token' => $token,
        'user'  => $user->load('roles'),
    ], 201);
    });
})->middleware('throttle:10,1');

// Routes publiques (lecture seule uniquement)
Route::get('/salons', [Api\SalonController::class, 'index']);
Route::get('/salons/{salon}/availability', [Api\AvailabilityController::class, 'month'])->middleware('throttle:60,1');
Route::get('/salons/{salon}/availability/{date}', [Api\AvailabilityController::class, 'day'])->middleware('throttle:60,1');
Route::get('/salons/{salon}', [Api\SalonController::class, 'show']);
Route::get('/services', [Api\ServiceController::class, 'index']);
Route::get('/services/{service}', [Api\ServiceController::class, 'show']);

// Routes protégées
Route::middleware('auth:api')->group(function () {
    // Demande de partenariat : limitée pour éviter les créations de salons en masse.
    Route::post('salons', [Api\SalonController::class, 'store'])->middleware('throttle:10,60');
    Route::get('my-salons', [Api\SalonController::class, 'mine']);
    Route::apiResource('salons', Api\SalonController::class)->except(['index', 'show', 'store']);
    Route::apiResource('services', Api\ServiceController::class)->except(['index', 'show']);
    Route::apiResource('employees', Api\EmployeeController::class);
    Route::apiResource('appointments', Api\AppointmentController::class)->except(['destroy']);
    Route::patch('appointments/{appointment}/pay', [Api\AppointmentController::class, 'pay']);
    Route::patch('appointments/{appointment}/complete', [Api\AppointmentController::class, 'complete']);
    Route::apiResource('reviews', Api\ReviewController::class);

    Route::middleware('role:gerant')->prefix('manager')->group(function () {
        Route::get('salons', [Api\ManagerController::class, 'salons']);
        Route::get('stats', [Api\ManagerController::class, 'stats']);
        Route::get('stats/activity', [Api\ManagerController::class, 'activity'])->middleware('throttle:30,1');
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
