<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * Genre Fix (Sarah, 2026-10-21): fix wrong genres catalog-wide here instead of
 * Clarissa fixing them one at a time during counts. "Genre" is
 * products.sub_category_id, a child of the format category (Vinyl - Used etc).
 *
 * Finds four kinds of problems, proposes a fix for each, nothing changes until
 * Apply:
 *   other_format - the genre belongs to a different format's list (e.g. a sealed
 *                  LP pointing at the CD - Sealed "Hip-Hop"), moved to the same
 *                  genre in its own format.
 *   deleted      - the genre was deleted, refilled from the artist.
 *   blank        - no genre, filled from the artist's other records.
 *   outlier      - the artist's other records clearly agree on another genre
 *                  (Sade as Metal, The Clash as Rock when 60 are Punk).
 * Artists whose records are split with no clear winner go to "Needs a look",
 * where one pick sets all of that artist's records in that format.
 * Every apply is snapshotted as 'backfill-genre-from-discogs' rows (id/old/new),
 * so it's undoable at /admin/admin-action-history.
 */
class GenreFixController extends Controller
{
    const TYPES = [
        'other_format' => 'Genre from another format',
        'deleted' => 'Genre was deleted',
        'outlier' => "Doesn't match the artist's other records",
        'blank' => 'No genre',
    ];

    // Genres that are legitimately "not the artist's usual" (a rock band's
    // soundtrack), never auto-moved out of; they go to Needs a look instead.
    const KEEP = ['soundtracks', 'musicals', 'children s', 'holiday', 'christmas', 'comedy', 'gospel'];

    private function guard()
    {
        if (!auth()->user()->can('product.update')) abort(403, 'Unauthorized action.');
    }

    /** Same genre across formats: "Hip Hop LPs", "Hip-Hop", "hip hop" are one. */
    public static function canon($name)
    {
        $s = mb_strtolower((string) $name);
        $s = str_replace('&', ' and ', $s);
        $s = trim(preg_replace('/[^a-z0-9]+/', ' ', $s));
        $alias = [
            'hiphop' => 'hip hop', 'rap' => 'hip hop',
            'r and b soul and funk' => 'rnb', 'r and b soul funk' => 'rnb', 'r and b' => 'rnb', 'soul' => 'rnb',
            'soul r and b' => 'rnb', 'rnb' => 'rnb', 'funk' => 'rnb', 'funk soul' => 'rnb',
            'alt indie rock' => 'alt', 'alt rock indie' => 'alt', 'alt rock' => 'alt', 'alt indie' => 'alt',
            'alternative' => 'alt', 'indie' => 'alt', 'indie rock' => 'alt',
            'new wave post punk' => 'new wave', 'post punk' => 'new wave',
            'punk hardcore' => 'punk', 'hardcore' => 'punk',
            'electronic dance' => 'electronic', 'dance' => 'electronic',
            'kpop' => 'k pop', 'non music and comedy' => 'comedy',
            'bossa nova' => 'bossanova',
            // Discogs' own genre names
            'stage and screen' => 'soundtracks', 'non music' => 'comedy', 'folk world and country' => 'folk',
            'indie pop' => 'alt', 'synth pop' => 'new wave', 'thrash' => 'metal', 'heavy metal' => 'metal',
            'hard rock' => 'rock', 'classic rock' => 'rock', 'rock and roll' => 'rock', 'gangsta' => 'hip hop',
            'alternative rock' => 'alt', 'grunge' => 'alt', 'shoegaze' => 'alt', 'lo fi' => 'alt',
            'contemporary r and b' => 'rnb', 'rhythm and blues' => 'rnb', 'neo soul' => 'rnb',
            'boom bap' => 'hip hop', 'conscious' => 'hip hop', 'trap' => 'hip hop', 'pop rap' => 'hip hop', 'thug rap' => 'hip hop',
            'house' => 'electronic', 'techno' => 'electronic', 'ambient' => 'electronic', 'downtempo' => 'electronic', 'electro' => 'electronic',
            'rnb swing' => 'rnb', 'musical' => 'musicals', 'soundtrack' => 'soundtracks',
            'black metal' => 'metal', 'death metal' => 'metal', 'doom metal' => 'metal', 'nu metal' => 'nu metal',
        ];
        if (isset($alias[$s])) return $alias[$s];
        $s = trim(preg_replace('/ (lps?|new)$/', '', $s));
        return $alias[$s] ?? $s;
    }

    private static function normArtist($a)
    {
        $a = mb_strtolower(trim((string) $a));
        $a = preg_replace('/^the\s+/', '', $a);
        return trim(preg_replace('/[^a-z0-9]+/', ' ', $a));
    }

    private static function badArtist($a)
    {
        return $a === '' || mb_strlen($a) < 2 || preg_match('/^(n a|na|unknown|various|various artists|none|no artist|v a|va|soundtrack|ost|unknown artist|various artist|original cast|original soundtrack)$/', $a);
    }

    /** Format categories, their live genres, and canon -> best genre id per format. */
    private function taxonomy($biz)
    {
        $all = \DB::table('categories')->where('business_id', $biz)->whereNull('deleted_at')->select('id', 'name', 'parent_id')->get();
        $formats = [];
        foreach ($all->where('parent_id', 0) as $c) {
            if (ProductController::isMusicCategoryName($c->name)) $formats[(int) $c->id] = $c->name;
        }
        $subs = []; // id => [name, parent]
        foreach ($all->where('parent_id', '>', 0) as $c) $subs[(int) $c->id] = ['name' => $c->name, 'parent' => (int) $c->parent_id];
        $sizes = \DB::table('products')->where('business_id', $biz)->where('sub_category_id', '>', 0)
            ->select('sub_category_id', \DB::raw('count(*) n'))->groupBy('sub_category_id')->pluck('n', 'sub_category_id')->all();
        $byCanon = []; // format => canon => sub id (biggest wins, e.g. cassette Soul vs R&b)
        foreach ($subs as $id => $s) {
            if (!isset($formats[$s['parent']])) continue;
            $k = self::canon($s['name']);
            $cur = $byCanon[$s['parent']][$k] ?? null;
            if ($cur === null || ($sizes[$id] ?? 0) > ($sizes[$cur] ?? 0)) $byCanon[$s['parent']][$k] = $id;
        }
        return [$formats, $subs, $byCanon];
    }

    /** Genre id for a canon key in a format, with Folk/Country and Nu-Metal fallbacks. */
    public static function subFor($byCanon, $format, $canon)
    {
        $m = $byCanon[$format] ?? [];
        if (isset($m[$canon])) return $m[$canon];
        $fallback = ['folk' => 'folk country', 'country' => 'folk country', 'nu metal' => 'metal', 'synth' => 'electronic', 'disco' => 'rnb', 'latin soul' => 'latin', 'salsa' => 'latin', 'tropical' => 'latin', 'bossanova' => 'latin', 'neo modern soul' => 'rnb', 'emo' => 'punk'];
        if (isset($fallback[$canon], $m[$fallback[$canon]])) return $m[$fallback[$canon]];
        return null;
    }

    /** artist => canon => count, over every music product that has a genre. */
    private function artistVotes($biz, $formats, $subs)
    {
        $votes = [];
        foreach (\DB::table('products')->where('business_id', $biz)->whereIn('category_id', array_keys($formats))
            ->where('sub_category_id', '>', 0)->select('artist', 'category_id', 'sub_category_id')->cursor() as $r) {
            $s = $subs[(int) $r->sub_category_id] ?? null;
            if (!$s || (int) $s['parent'] !== (int) $r->category_id) continue; // don't learn from broken rows
            $a = self::normArtist($r->artist);
            if (self::badArtist($a)) continue;
            $k = self::canon($s['name']);
            $votes[$a][$k] = ($votes[$a][$k] ?? 0) + 1;
        }
        return $votes;
    }

    /** Artist candidates for a product: the artist column, else either side of "A - B" / "A / B". */
    private static function artistCandidates($p)
    {
        $c = [];
        $a = self::normArtist($p->artist);
        if (!self::badArtist($a)) $c[] = $a;
        foreach ([' / ', ' - ', ' – '] as $sep) {
            if (mb_strpos((string) $p->name, $sep) === false) continue;
            foreach (array_map('trim', explode($sep, (string) $p->name, 2)) as $x) {
                $x = self::normArtist($x);
                if (!self::badArtist($x)) $c[] = $x;
            }
            break;
        }
        return array_values(array_unique($c));
    }

    /** Clear winner for an artist: >= $min records and >= 75% of them. */
    private static function winner($v, $min)
    {
        if (empty($v)) return null;
        arsort($v);
        $top = array_key_first($v);
        $sum = array_sum($v);
        if ($v[$top] < $min || $v[$top] / $sum < 0.75) return null;
        return [$top, $v[$top], $sum];
    }

    /** Every proposed fix, plus the "needs a look" artist groups. */
    private function compute($biz)
    {
        @set_time_limit(300);
        @ini_set('memory_limit', '1500M');
        [$formats, $subs, $byCanon] = $this->taxonomy($biz);
        $votes = $this->artistVotes($biz, $formats, $subs);
        $fixes = [];
        $groups = []; // artist|format => [canon => n]
        $stock = \DB::table('variation_location_details')->where('qty_available', '>', 0)
            ->select('product_id', \DB::raw('sum(qty_available) q'))->groupBy('product_id')->pluck('q', 'product_id')->all();
        foreach (\DB::table('products')->where('business_id', $biz)->where('is_inactive', 0)->whereIn('category_id', array_keys($formats))
            ->select('id', 'name', 'artist', 'category_id', 'sub_category_id')->cursor() as $p) {
            $fmt = (int) $p->category_id;
            $sid = (int) $p->sub_category_id;
            $s = $sid ? ($subs[$sid] ?? null) : null;
            $row = ['id' => (int) $p->id, 'name' => $p->name, 'artist' => (string) $p->artist, 'format' => $formats[$fmt], 'cat' => $fmt, 'old' => $sid ?: null,
                'old_name' => $s['name'] ?? ($sid ? 'deleted genre' : ''), 'stock' => (float) ($stock[$p->id] ?? 0)];
            $cands = self::artistCandidates($p);
            $type = null; $new = null; $why = '';

            if ($s && $s['parent'] !== $fmt) {
                $new = self::subFor($byCanon, $fmt, self::canon($s['name']));
                if ($new) { $type = 'other_format'; $why = 'Was ' . (isset($formats[$s['parent']]) ? 'the ' . $formats[$s['parent']] . ' ' : 'an old ') . $s['name'] . ', moved to this format\'s own'; }
                else { $s = null; } // no twin genre in this format, treat like blank
            }
            if (!$type && !$s) {
                foreach ($cands as $a) {
                    $w = self::winner($votes[$a] ?? [], 3);
                    if (!$w) continue;
                    $new = self::subFor($byCanon, $fmt, $w[0]);
                    if ($new) { $type = $sid ? 'deleted' : 'blank'; $why = "{$w[1]} of the artist's {$w[2]} records"; break; }
                }
            } elseif (!$type && $s) {
                $a = self::normArtist($p->artist);
                $v = self::badArtist($a) ? [] : ($votes[$a] ?? []);
                $cur = self::canon($s['name']);
                $w = self::winner($v, 5);
                $curN = $v[$cur] ?? 0;
                if ($w && $w[0] !== $cur && !in_array($cur, self::KEEP, true) && $curN / $w[2] <= 0.15) {
                    $new = self::subFor($byCanon, $fmt, $w[0]);
                    if ($new && $new !== $sid) { $type = 'outlier'; $why = "{$w[1]} of the artist's {$w[2]} records"; }
                }
                if (!$type && count(array_diff(array_keys($v), self::KEEP)) > 1 && array_sum($v) >= 3) {
                    $g = $a . '|' . $fmt;
                    $groups[$g][$cur] = ($groups[$g][$cur] ?? 0) + 1;
                }
            }
            if ($type) $fixes[$type][] = $row + ['new' => $new, 'new_name' => $subs[$new]['name'] ?? '', 'why' => $why];
        }
        // Needs a look: the artist still has 2+ genres in one format after the fixes above.
        $look = [];
        foreach ($groups as $g => $split) {
            if (count(array_diff(array_keys($split), self::KEEP)) < 2 || array_sum($split) < 3) continue;
            [$a, $fmt] = explode('|', $g);
            arsort($split);
            $named = [];
            foreach ($split as $k => $n) {
                $id = self::subFor($byCanon, (int) $fmt, $k);
                $named[] = ['name' => $id ? $subs[$id]['name'] : ucwords($k), 'n' => $n, 'id' => $id];
            }
            $look[] = ['artist' => $a, 'cat' => (int) $fmt, 'format' => $formats[$fmt], 'split' => $named, 'n' => array_sum($split)];
        }
        usort($look, fn($x, $y) => $y['n'] <=> $x['n']);
        $genres = [];
        foreach ($subs as $id => $s) if (isset($formats[$s['parent']])) $genres[$s['parent']][$id] = $s['name'];
        foreach ($genres as &$g) asort($g);
        return compact('fixes', 'look', 'genres', 'formats');
    }

    public function index(Request $request)
    {
        $this->guard();
        $biz = (int) $request->session()->get('user.business_id');
        $data = $this->compute($biz);
        $data['types'] = self::TYPES;
        $data['artist'] = trim((string) $request->input('artist'));
        $data['records'] = [];
        if ($data['artist'] !== '') $data['records'] = $this->artistRecords($biz, $data['artist'], array_keys($data['formats']));
        return view('product.genre_fix', $data);
    }

    /** Active music records for an artist search (artist column or name). */
    private function artistRecords($biz, $q, $formatIds)
    {
        $key = self::normArtist($q);
        $words = array_filter(explode(' ', $key), fn($w) => mb_strlen($w) > 1);
        if (!$words) return [];
        usort($words, fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        $out = [];
        foreach (\DB::table('products as p')->leftJoin('categories as sc', 'sc.id', '=', 'p.sub_category_id')
            ->where('p.business_id', $biz)->where('p.is_inactive', 0)->whereIn('p.category_id', $formatIds)
            ->where(function ($w) use ($words) { $w->where('p.artist', 'like', '%' . $words[0] . '%')->orWhere('p.name', 'like', '%' . $words[0] . '%'); })
            ->select('p.id', 'p.name', 'p.artist', 'p.category_id', 'p.sub_category_id', 'sc.name as genre', 'sc.parent_id', 'sc.deleted_at')
            ->orderBy('p.category_id')->orderBy('p.name')->limit(2000)->get() as $p) {
            $hit = self::normArtist($p->artist) === $key || in_array($key, self::artistCandidates($p), true)
                || preg_match('/\b' . preg_quote($key, '/') . '\b/', self::normArtist($p->name));
            if (!$hit) continue;
            $ok = $p->sub_category_id && !$p->deleted_at && (int) $p->parent_id === (int) $p->category_id;
            $out[] = ['id' => (int) $p->id, 'name' => $p->name, 'cat' => (int) $p->category_id, 'sub' => $ok ? (int) $p->sub_category_id : 0];
        }
        return $out;
    }

    /** Write genre changes (id => new sub id), snapshot for undo, tell the website. */
    private function write($biz, array $changes, $label)
    {
        $formatOf = \DB::table('categories')->where('business_id', $biz)->whereNull('deleted_at')->where('parent_id', '>', 0)->pluck('parent_id', 'id')->all();
        $done = [];
        \DB::beginTransaction();
        try {
            foreach (array_chunk(array_keys($changes), 1000) as $ids) {
                foreach (\DB::table('products')->where('business_id', $biz)->whereIn('id', $ids)->select('id', 'category_id', 'sub_category_id')->get() as $p) {
                    $new = (int) $changes[$p->id];
                    // Only a genre from this product's own format, and only if it changes.
                    if (!$new || (int) ($formatOf[$new] ?? 0) !== (int) $p->category_id || (int) $p->sub_category_id === $new) continue;
                    \DB::table('products')->where('id', $p->id)->update(['sub_category_id' => $new]);
                    $done[] = ['id' => (int) $p->id, 'old' => $p->sub_category_id ? (int) $p->sub_category_id : null, 'new' => $new];
                }
            }
            if ($done) {
                $ts = now()->format('Y-m-d_His');
                \Storage::disk('local')->put("admin-snapshots/backfill-genre-from-discogs-{$ts}.json", json_encode([
                    'timestamp' => $ts, 'action' => 'backfill-genre-from-discogs', 'user_id' => auth()->id(), 'business_id' => $biz,
                    'source_name' => count($done) . ' genre fix(es): ' . $label, 'target_name' => 'products.sub_category_id', 'rows' => $done,
                ], JSON_PRETTY_PRINT));
            }
            \DB::commit();
        } catch (\Throwable $e) {
            \DB::rollBack();
            \Log::error('Genre fix failed: ' . $e->getMessage());
            return null;
        }
        // Website picks up the new genre; after the response so the page isn't slowed down.
        $ids = array_column($done, 'id');
        if ($ids) {
            app()->terminating(function () use ($ids) {
                foreach (array_chunk($ids, 300) as $chunk) {
                    try { (new \App\Services\NivessaStockNotifier())->pushProductChanged($chunk); } catch (\Throwable $e) { \Log::warning('Genre fix website push failed: ' . $e->getMessage()); }
                }
            });
        }
        return count($done);
    }

    private function back($msg, $ok = true, $artist = '')
    {
        return redirect('/products/genre-fix' . ($artist !== '' ? '?artist=' . urlencode($artist) . '#artist' : ''))
            ->with('status', ['success' => $ok ? 1 : 0, 'msg' => $msg]);
    }

    /** Apply one type's fixes: every row of that type except the ones unticked. */
    public function apply(Request $request)
    {
        $this->guard();
        $biz = (int) $request->session()->get('user.business_id');
        $type = (string) $request->input('type');
        if ($type !== 'all' && !isset(self::TYPES[$type])) return $this->back('Unknown fix type.', false);
        $skip = array_flip(array_map('intval', (array) $request->input('skip', [])));
        $changes = [];
        foreach ($this->compute($biz)['fixes'] as $t => $rows) {
            if ($type !== 'all' && $t !== $type) continue;
            foreach ($rows as $f) if (!isset($skip[$f['id']])) $changes[$f['id']] = $f['new'];
        }
        $n = $this->write($biz, $changes, $type === 'all' ? 'Fix all' : self::TYPES[$type]);
        return $n === null ? $this->back('Something went wrong, nothing changed.', false) : $this->back("Fixed {$n} genres. Undo any time in Admin Action History.");
    }

    /** One genre for all of an artist's records in one format (from Needs a look). */
    public function setArtist(Request $request)
    {
        $this->guard();
        $biz = (int) $request->session()->get('user.business_id');
        $artist = (string) $request->input('artist');
        $cat = (int) $request->input('cat');
        $sub = (int) $request->input('sub');
        if (!$cat || !$sub || $artist === '') return $this->back('Pick a genre first.', false);
        $changes = [];
        foreach ($this->artistRecords($biz, $artist, [$cat]) as $r) $changes[$r['id']] = $sub;
        $n = $this->write($biz, $changes, $artist . ' (all ' . \DB::table('categories')->where('id', $cat)->value('name') . ')');
        return $n === null ? $this->back('Something went wrong, nothing changed.', false) : $this->back("Set {$n} of {$artist}'s records.");
    }

    /** Per-record picks from the artist search. */
    public function saveRecords(Request $request)
    {
        $this->guard();
        $biz = (int) $request->session()->get('user.business_id');
        $changes = [];
        foreach ((array) $request->input('genre', []) as $id => $sub) if ((int) $sub) $changes[(int) $id] = (int) $sub;
        $artist = (string) $request->input('artist');
        $n = $this->write($biz, $changes, $artist !== '' ? $artist . ' (by hand)' : 'by hand');
        return $n === null ? $this->back('Something went wrong, nothing changed.', false, $artist) : $this->back("Saved {$n} genres.", true, $artist);
    }
}
