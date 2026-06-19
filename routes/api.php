<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api;

Route::get('/health', function () {
    return response()->json(['status' => 'ok']);
});

// Routes API (sans auth pour tester)
Route::apiResource('salons', Api\SalonController::class);
Route::apiResource('services', Api\ServiceController::class);
Route::apiResource('employees', Api\EmployeeController::class);
Route::apiResource('appointments', Api\AppointmentController::class);
Route::apiResource('reviews', Api\ReviewController::class);
Route::post('/login', function (Request $request) {
    $credentials = $request->validate([
        'email' => 'required|email',
        'password' => 'required',
    ]);

    if (!Auth::attempt($credentials)) {
        return response()->json(['error' => 'Invalid credentials'], 401);
    }

    $token = auth()->guard('api')->attempt($credentials);
    
    return response()->json([
        'access_token' => $token,
        'token_type' => 'Bearer',
        'user' => auth()->user(),
    ]);
});