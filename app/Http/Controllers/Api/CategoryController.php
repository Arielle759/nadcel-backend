<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Salon;
use App\Models\Service;
use Illuminate\Http\JsonResponse;

// Catégories de services réellement proposées sur la plateforme, pour le menu
// « Services » du site : seulement les prestations actives de salons en ligne,
// avec le nombre de salons qui les proposent (les plus répandues d'abord).
class CategoryController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $categories = Service::query()
            ->where('is_active', true)
            ->whereIn('salon_id', Salon::public()->select('id'))
            ->selectRaw('category, COUNT(DISTINCT salon_id) as salons_count')
            ->groupBy('category')
            ->orderByDesc('salons_count')
            ->orderBy('category')
            ->get()
            ->map(fn ($row) => ['name' => $row->category, 'salons_count' => (int) $row->salons_count]);

        return response()->json($categories);
    }
}
