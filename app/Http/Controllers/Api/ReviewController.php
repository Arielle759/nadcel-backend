<?php

namespace App\Http\Controllers\Api;

use App\Models\Appointment;
use App\Models\Review;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class ReviewController extends Controller
{
    public function index()
    {
        if (!auth()->user()->hasRole('admin')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $reviews = Review::with('client', 'salon', 'appointment')->paginate(10);
        return response()->json($reviews);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'appointment_id' => 'required|exists:appointments,id',
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:1000',
        ]);

        return \Illuminate\Support\Facades\DB::transaction(function () use ($validated) {
        $appointment = Appointment::lockForUpdate()->findOrFail($validated['appointment_id']);

        if ($appointment->client_id !== auth()->id()) {
            return response()->json(['error' => 'This appointment does not belong to you.'], 403);
        }

        if ($appointment->status !== 'completed') {
            return response()->json(['error' => 'You can only review a completed appointment.'], 422);
        }

        if (Review::where('appointment_id', $appointment->id)->exists()) {
            return response()->json(['error' => 'A review already exists for this appointment.'], 409);
        }

        $review = Review::create([
            'client_id' => auth()->id(),
            'appointment_id' => $appointment->id,
            'salon_id' => $appointment->salon_id,
            'rating' => $validated['rating'],
            'comment' => $validated['comment'] ?? null,
        ]);

        return response()->json($review, 201);
        }, attempts: 3);
    }

    public function show(Review $review)
    {
        $user = auth()->user();
        $isOwner = $user->id === $review->client_id;
        $isManager = $user->id === $review->salon?->manager_id;

        if (!$isOwner && !$isManager && !$user->hasRole('admin')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $review->load('client', 'salon', 'appointment');
        return response()->json($review);
    }

    public function update(Request $request, Review $review)
    {
        if (auth()->id() !== $review->client_id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'rating' => 'integer|min:1|max:5',
            'comment' => 'nullable|string|max:1000',
        ]);

        $review->update($validated);
        return response()->json($review);
    }

    public function destroy(Review $review)
    {
        if (auth()->id() !== $review->client_id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $review->delete();
        return response()->noContent();
    }
}
