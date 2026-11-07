<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Api\NivessaGenreBibleController;
use App\Services\ArtistGenreLookup;

/**
 * Staff Genre Bible (Sarah 2026-11-06): every artist in the curated
 * artist->bin sheet, the genre we show for them (the sheet's, unless most of
 * their products are filed elsewhere), and how their products are actually
 * filed. "Only where it differs" lists the artists to re-check.
 * Same data as nivessa.com/genre-bible. Read-only.
 */
class GenreBibleController extends Controller
{
    /** Display order, same as the sheet / website. */
    const GENRES = [
        'Rock', 'Hip-Hop / Rap', 'Alternative / Indie', 'Metal', 'R&B/Funk/Soul',
        'Pop', 'Jazz', 'Electronic / Dance', 'Country', 'Classical', 'Latin',
        'Folk', 'Blues', 'Punk', 'Reggae', 'World', 'New Wave', 'Gospel', 'Soundtrack',
    ];

    public function index(ArtistGenreLookup $lookup)
    {
        if (!auth()->user()->can('product.view') && !auth()->user()->can('sell.create')) {
            abort(403);
        }

        $data = NivessaGenreBibleController::build($lookup);
        $overrides = (array) ($data['overrides'] ?? []);
        $counts = $data['counts'] ?? [];

        $rows = [];
        foreach ($lookup->allArtists() as $key => $sheetBin) {
            $rows[] = [
                'name' => $lookup->displayName($key),
                'sheet' => $sheetBin,
                'genre' => $overrides[$key]['genre'] ?? $sheetBin,
                'counts' => $counts[$key] ?? [],
            ];
            $rows[count($rows) - 1]['differs'] = $rows[count($rows) - 1]['genre'] !== $sheetBin;
        }
        usort($rows, function ($a, $b) {
            $k = function ($s) {
                $l = mb_strtolower($s);
                return strpos($l, 'the ') === 0 ? substr($l, 4) : $l;
            };
            return strcmp($k($a['name']), $k($b['name']));
        });

        return view('products.genre_bible', [
            'rows' => $rows,
            'genres' => self::GENRES,
            'differCount' => count($overrides),
            'updated' => $data['updated'] ?? null,
            'minProducts' => NivessaGenreBibleController::MIN_PRODUCTS,
        ]);
    }
}
