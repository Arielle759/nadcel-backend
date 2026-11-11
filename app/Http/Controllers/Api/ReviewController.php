<?php

namespace App\Http\Controllers\Api;

use App\Models\Appointment;
use App\Models\Review;
use App\Models\Salon;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class ReviewController extends Controller
{
    public function index(Request $request)
    {
        if (!auth()->user()->hasRole('admin')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'max_rating' => 'sometimes|integer|min:1|max:5',
            'per_page'   => 'sometimes|integer|min:1|max:100',
        ]);

        // Les plus récents d'abord : c'est l'ordre utile pour modérer.
        $reviews = Review::with('client:id,name', 'salon:id,name')
            ->when($validated['max_rating'] ?? null, fn ($q, $max) => $q->where('rating', '<=', $max))
            ->latest()
            ->paginate($validated['per_page'] ?? 30);

        return response()->json($reviews);
    }

    /**
     * Avis mis en avant sur la page d'accueil (route publique).
     * Uniquement de vrais avis, laissés après un rendez-vous terminé : 4 ou 5
     * étoiles, avec un commentaire, sur un salon actif et vérifié.
     * L'autrice est réduite au prénom et à l'initiale du nom (ni e-mail ni identifiant).
     */
    public function highlights()
    {
        $reviews = Review::with('client:id,name', 'salon:id,name')
            ->where('rating', '>=', 4)
            ->whereNotNull('comment')
            ->whereRaw('LENGTH(TRIM(comment)) >= ?', [20])
            ->whereHas('salon', fn ($q) => $q->public())
            ->latest()
            ->limit(8)
            ->get()
            ->map(fn (Review $review) => [
                'id'         => $review->id,
                'rating'     => $review->rating,
                'comment'    => trim((string) $review->comment),
                'author'     => self::shortName($review->client?->name),
                'salon'      => ['id' => $review->salon->id, 'name' => $review->salon->name],
                'created_at' => $review->created_at?->toIso8601String(),
            ]);

        return response()->json(['data' => $reviews]);
    }

    /**
     * Avis d'un salon, pour sa page publique (route publique).
     * Tous les avis sont montrés, quelle que soit la note : la plateforme ne
     * trie pas les avis à la place des clientes. Chaque avis vient d'un
     * rendez-vous terminé ; l'autrice est réduite au prénom et à l'initiale.
     */
    public function forSalon(Request $request, Salon $salon)
    {
        if (!$salon->isPublic()) {
            abort(404);
        }

        $validated = $request->validate([
            'page' => 'sometimes|integer|min:1',
        ]);

        $page = Review::with('client:id,name', 'appointment:id,service_id', 'appointment.service:id,name')
            ->where('salon_id', $salon->id)
            ->latest()
            ->latest('id')
            ->paginate(10, ['*'], 'page', $validated['page'] ?? 1);

        $page->getCollection()->transform(fn (Review $review) => [
            'id'         => $review->id,
            'rating'     => $review->rating,
            'comment'    => $review->comment !== null && trim($review->comment) !== '' ? trim($review->comment) : null,
            'author'     => self::shortName($review->client?->name),
            'service'    => $review->appointment?->service?->name,
            'created_at' => $review->created_at?->toIso8601String(),
        ]);

        return response()->json($page);
    }

    // « Awa Diop Ndiaye » devient « Awa D. »
    private static function shortName(?string $name): string
    {
        $parts = preg_split('/\s+/', trim((string) $name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($parts === []) {
            return 'Une cliente';
        }
        $first = $parts[0];
        $initial = isset($parts[1]) ? ' '.mb_strtoupper(mb_substr($parts[1], 0, 1)).'.' : '';

        return $first.$initial;
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
