<?php

namespace App\Http\Controllers\Api;

use App\Models\Review;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class ReviewController extends Controller
{
    public function index()
    {
        $reviews = Review::with('client', 'salon', 'appointment')->paginate(10);
        return response()->json($reviews);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'appointment_id' => 'required|exists:appointments,id',
            'salon_id' => 'required|exists:salons,id',
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:1000',
        ]);

        $review = Review::create([
            ...$validated,
            'client_id' => auth()->user()?->id,
        ]);

        return response()->json($review, 201);
    }

    public function show(Review $review)
    {
        $review->load('client', 'salon', 'appointment');
        return response()->json($review);
    }

    public function update(Request $request, Review $review)
    {
        if (auth()->user()?->id !== $review->client_id) {
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
        if (auth()->user()?->id !== $review->client_id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $review->delete();
        return response()->noContent();
    }
}
