<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Salon;
use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Suggestions affichées pendant la saisie dans la barre de recherche
 * (route publique) : salons, services et lieux qui contiennent le texte tapé.
 *
 * La comparaison ignore la casse et les accents (« ceramique » trouve
 * « Céramique ») ; elle est faite en PHP sur les seuls salons visibles, ce qui
 * reste léger pour le volume de la plateforme. Au-delà de quelques milliers de
 * salons, un index plein texte prendrait le relais.
 */
class SearchSuggestionController extends Controller
{
    private const LIMIT = 5;

    public function __invoke(Request $request)
    {
        $validated = $request->validate([
            'q' => 'required|string|min:1|max:60',
        ]);

        $term = self::normalize($validated['q']);
        if ($term === '') {
            return response()->json(['salons' => [], 'services' => [], 'places' => []]);
        }

        $salons = Salon::public()->get(['id', 'name', 'address', 'city']);

        $salonHits = self::rank($salons, fn (Salon $s) => $s->name, $term)
            ->map(fn (Salon $s) => ['id' => $s->id, 'name' => $s->name, 'area' => self::area($s->address, $s->city)]);

        $serviceNames = Service::query()
            ->where('is_active', true)
            ->whereIn('salon_id', $salons->pluck('id'))
            ->get(['name', 'category'])
            ->flatMap(fn (Service $s) => [$s->name, $s->category])
            ->filter()
            ->map(fn ($v) => trim($v))
            ->unique(fn ($v) => self::normalize($v))
            ->values();

        $places = $salons
            ->flatMap(fn (Salon $s) => [self::district($s->address, $s->city), $s->city])
            ->filter()
            ->map(fn ($v) => trim($v))
            ->unique(fn ($v) => self::normalize($v))
            ->values();

        return response()->json([
            'salons'   => $salonHits->values(),
            'services' => self::rank($serviceNames, fn ($v) => $v, $term)->values(),
            'places'   => self::rank($places, fn ($v) => $v, $term)->values(),
        ]);
    }

    /**
     * Garde les éléments qui contiennent le terme ; ceux dont un mot commence
     * par le terme passent devant, puis ordre alphabétique.
     */
    private static function rank(Collection $items, callable $label, string $term): Collection
    {
        return $items
            ->map(fn ($item) => ['item' => $item, 'text' => self::normalize($label($item))])
            ->map(fn ($row) => $row + ['wordStart' => (bool) preg_match('/(^|[\s\-\'])'.preg_quote($term, '/').'/u', $row['text'])])
            // Une seule lettre : seuls les mots qui commencent par elle (sinon
            // « a » proposerait presque tout).
            ->filter(fn ($row) => mb_strlen($term) < 2 ? $row['wordStart'] : str_contains($row['text'], $term))
            ->sortBy(fn ($row) => [$row['wordStart'] ? 0 : 1, $row['text']])
            ->take(self::LIMIT)
            ->map(fn ($row) => $row['item']);
    }

    private static function normalize(?string $value): string
    {
        return trim(mb_strtolower(Str::ascii((string) $value)));
    }

    // « 23 rue X, Plateau » + « Dakar » -> « Plateau » (même règle que l'interface).
    private static function district(?string $address, string $city): ?string
    {
        $parts = array_values(array_filter(array_map('trim', explode(',', (string) $address))));
        if (count($parts) < 2) {
            return null;
        }
        $last = end($parts);

        return self::normalize($last) === self::normalize($city) ? null : $last;
    }

    private static function area(?string $address, string $city): string
    {
        $district = self::district($address, $city);

        return $district ? "{$district}, {$city}" : $city;
    }
}
