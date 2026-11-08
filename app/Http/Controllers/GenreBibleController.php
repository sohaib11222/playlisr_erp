<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Api\NivessaGenreBibleController;
use App\Services\ArtistGenreLookup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Staff Genre Bible (Sarah 2026-11-06): every artist in the curated
 * artist->bin sheet, the genre we show for them (the sheet's, unless most of
 * their products are filed elsewhere), and how their products are actually
 * filed. "Only where it differs" lists the artists to re-check.
 * Same data as nivessa.com/genre-bible.
 *
 * "Make all one genre" (Sarah 2026-11-06) moves every music product by an
 * artist into the chosen genre's subcategory under the same parent (format),
 * so an LP stays on the vinyl wall and a CD on the CD wall. Products in
 * subcategories that aren't a genre bin (Children's, Bossanova...) are left
 * alone. Every move writes an undo log.
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

        $extra = (array) ($data['extra'] ?? []);
        $added = $lookup->added();

        $rows = [];
        $all = $lookup->allArtists();
        foreach ($extra as $key => $e) {
            $all[$key] = null; // not on the sheet
        }
        foreach ($all as $key => $sheetBin) {
            $key = (string) $key;
            $genre = $sheetBin === null ? $extra[$key]['genre'] : ($overrides[$key]['genre'] ?? $sheetBin);
            $rows[] = [
                'name' => $sheetBin === null ? $extra[$key]['name'] : $lookup->displayName($key),
                'sheet' => $sheetBin,
                'genre' => $genre,
                'counts' => $counts[$key] ?? [],
            ];
            $last = count($rows) - 1;
            $rows[$last]['differs'] = $sheetBin !== null && $genre !== $sheetBin;
            $rows[$last]['added'] = isset($added[$key]);
            // Products not yet in the shown genre (what "Make all one genre" would move).
            $c = $rows[$last]['counts'];
            $rows[$last]['toMove'] = array_sum($c) - ($c[$rows[$last]['genre']] ?? 0);
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
            'extraCount' => count($extra),
            'updated' => $data['updated'] ?? null,
            'minProducts' => NivessaGenreBibleController::MIN_PRODUCTS,
            'canEdit' => auth()->user()->can('product.update'),
        ]);
    }

    /** "the carpenters" => ["the carpenters", "carpenters"] (and the reverse). */
    private static function spellings(string $key): array
    {
        return strpos($key, 'the ') === 0 ? [$key, substr($key, 4)] : [$key, 'the ' . $key];
    }

    /**
     * POST /genre-bible/artist {name, genre}: add an artist, or set the genre
     * for one already listed. Also steers Mass Add (ArtistGenreLookup).
     */
    public function addArtist(Request $request, ArtistGenreLookup $lookup)
    {
        if (!auth()->user()->can('product.update')) {
            abort(403);
        }
        $name = trim(preg_replace('/\s+/u', ' ', (string) $request->input('name')));
        $genre = (string) $request->input('genre');
        $key = $lookup->normalizeArtist($name);
        if ($key === '' || mb_strlen($name) > 191 || !in_array($genre, self::GENRES, true)) {
            return response()->json(['error' => 'Type an artist name and pick a genre.'], 422);
        }
        $existing = DB::table('genre_bible_artists')->where('artist_key', $key)->first();
        if ($existing) {
            DB::table('genre_bible_artists')->where('id', $existing->id)->update([
                'name' => $name, 'genre' => $genre, 'created_by' => auth()->id(), 'updated_at' => now(),
            ]);
        } else {
            DB::table('genre_bible_artists')->insert([
                'artist_key' => $key, 'name' => $name, 'genre' => $genre,
                'created_by' => auth()->id(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        ArtistGenreLookup::flush();
        Cache::forget(NivessaGenreBibleController::CACHE_KEY);
        return response()->json(['ok' => true, 'updated' => (bool) $existing]);
    }

    /** POST /genre-bible/unify  {artist, genre} */
    public function unify(Request $request, ArtistGenreLookup $lookup)
    {
        if (!auth()->user()->can('product.update')) {
            abort(403);
        }
        $artist = trim((string) $request->input('artist'));
        $genre = (string) $request->input('genre');
        if ($artist === '' || !in_array($genre, self::GENRES, true)) {
            return response()->json(['error' => 'Pick an artist and a genre.'], 422);
        }
        $businessId = (int) $request->session()->get('user.business_id');

        $products = DB::table('products as p')
            ->join('categories as c', 'c.id', '=', 'p.sub_category_id')
            ->join('categories as pc', 'pc.id', '=', 'p.category_id')
            ->where('p.business_id', $businessId)
            // Case-insensitive collation; "Carpenters" and "The Carpenters" are one artist.
            ->whereIn('p.artist', self::spellings($lookup->normalizeArtist($artist)))
            ->whereRaw('LOWER(pc.name) REGEXP ?', [NivessaGenreBibleController::MUSIC_PARENT_REGEXP])
            ->get(['p.id', 'p.category_id', 'p.sub_category_id', 'c.name as sub_name', 'pc.name as parent_name']);

        // Products to move: in a genre subcategory, but not the chosen one.
        $toMove = $products->filter(function ($p) use ($genre) {
            $bin = NivessaGenreBibleController::binForSubcategory((string) $p->sub_name);
            return $bin !== null && $bin !== $genre;
        });

        $moved = [];
        $skipped = [];
        $undo = [];
        foreach ($toMove->groupBy('category_id') as $parentId => $rows) {
            $target = $this->targetSubcategory($businessId, (int) $parentId, $genre);
            if ($target === null) {
                $skipped[] = $rows->first()->parent_name . ' (no ' . $genre . ' section, ' . $rows->count() . ')';
                continue;
            }
            foreach ($rows as $r) {
                $undo[] = ['id' => $r->id, 'category_id' => $r->category_id, 'sub_category_id' => $r->sub_category_id];
            }
            DB::table('products')->whereIn('id', $rows->pluck('id')->all())->update([
                'sub_category_id' => $target,
                'updated_at' => now(),
            ]);
            $moved[] = $rows->first()->parent_name . ': ' . $rows->count();
        }

        $undoId = null;
        if ($undo) {
            $undoId = date('Ymd-His') . '-' . substr(md5($artist . microtime()), 0, 8);
            $dir = storage_path('app/genre-bible-undo');
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            file_put_contents($dir . '/' . $undoId . '.json', json_encode([
                'artist' => $artist, 'genre' => $genre, 'user_id' => auth()->id(), 'products' => $undo,
            ]));
            Cache::forget(NivessaGenreBibleController::CACHE_KEY);
        }

        return response()->json([
            'moved' => count($undo),
            'moved_detail' => $moved,
            'skipped' => $skipped,
            'undo' => $undoId,
            'counts' => $this->artistCounts($products, $undo ? $genre : null, array_column($undo, 'id')),
        ]);
    }

    /** POST /genre-bible/undo  {undo} */
    public function undo(Request $request)
    {
        if (!auth()->user()->can('product.update')) {
            abort(403);
        }
        $id = (string) $request->input('undo');
        $path = storage_path('app/genre-bible-undo/' . $id . '.json');
        if (!preg_match('/^\d{8}-\d{6}-[a-f0-9]{8}$/', $id) || !is_file($path)) {
            return response()->json(['error' => 'Undo log not found.'], 404);
        }
        $log = json_decode((string) file_get_contents($path), true) ?: [];
        foreach ($log['products'] ?? [] as $r) {
            DB::table('products')->where('id', $r['id'])->update([
                'category_id' => $r['category_id'],
                'sub_category_id' => $r['sub_category_id'],
                'updated_at' => now(),
            ]);
        }
        rename($path, $path . '.undone');
        Cache::forget(NivessaGenreBibleController::CACHE_KEY);
        return response()->json(['restored' => count($log['products'] ?? [])]);
    }

    /**
     * The subcategory under $parentId for $genre: of the subcategories whose
     * name maps to that genre, the one with the most products.
     */
    private function targetSubcategory(int $businessId, int $parentId, string $genre): ?int
    {
        $subs = DB::table('categories')
            ->where('business_id', $businessId)
            ->where('parent_id', $parentId)
            ->whereNull('deleted_at')
            ->get(['id', 'name'])
            ->filter(function ($c) use ($genre) {
                return NivessaGenreBibleController::binForSubcategory((string) $c->name) === $genre;
            });
        if ($subs->isEmpty()) {
            return null;
        }
        if ($subs->count() === 1) {
            return (int) $subs->first()->id;
        }
        $used = DB::table('products')
            ->whereIn('sub_category_id', $subs->pluck('id')->all())
            ->groupBy('sub_category_id')
            ->selectRaw('sub_category_id, COUNT(*) as n')
            ->pluck('n', 'sub_category_id');
        return (int) $subs->sortByDesc(function ($c) use ($used) {
            return (int) ($used[$c->id] ?? 0);
        })->first()->id;
    }

    /** Genre => count for the artist's products after a move, most first. */
    private function artistCounts($products, ?string $genre, array $movedIds): array
    {
        $moved = array_flip($movedIds);
        $counts = [];
        foreach ($products as $p) {
            $bin = isset($moved[$p->id]) ? $genre : NivessaGenreBibleController::binForSubcategory((string) $p->sub_name);
            if ($bin !== null) {
                $counts[$bin] = ($counts[$bin] ?? 0) + 1;
            }
        }
        arsort($counts);
        return $counts;
    }
}
