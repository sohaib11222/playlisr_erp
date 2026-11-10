<?php

namespace App\Http\Controllers;

use App\BuyCustomerOffer;
use App\Services\BuyBetaOffers;
use App\Services\BuyDemandService;
use App\Services\BuyOfferCalculatorService;
use App\Services\SignedValueLookupService;
use App\Services\DiscogsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Website "Get a Quote" (Sarah 2026-11-04, a seller asked for a CarMax-style
 * quote before bringing her records in). nivessa.com /sell-your-stuff/quote
 * calls the public /sell-quote/* endpoints here, so the quote is priced by the
 * same BuyOfferCalculatorService + Beta demand levels as the in-store buy
 * form. Only ONE number goes out (the form's final offer, firm for 3 days if
 * the condition matches); the starting / second offers never leave the ERP.
 *
 * Sizes (what the items actually come to, not what the seller picked):
 *  - small  (1-9):    every record priced on its own Discogs value.
 *  - medium (10-199): counts by type at bulk rates; listed records worth
 *                     CHERRY_MIN+ on Discogs are priced on their own, the rest
 *                     join their genre's bulk rate (the store's 10+ rule).
 *  - large  (200+):   same math, but shown as an estimate range + appraisal.
 *
 * Staff side: /sell-quotes lists them; "Start buy" turns one into a draft on
 * the buy form with the quoted demand levels pinned.
 */
class SellQuoteController extends StoreOrDiscogsController
{
    const MEDIUM_FROM = 10;
    const LARGE_FROM = 200;
    // A listed record in a 10+ lot is priced on its own from this Discogs value.
    const CHERRY_MIN = 20;
    // Sarah 11/4: 3 days (was 7) so sellers come in while it's fresh.
    const VALID_DAYS = 3;
    const MAX_RECORDS = 60;
    // A Discogs collection import can bring in far more records than anyone
    // would type (customer idea 11/9). Past MAX_RECORDS the bulk-rate ones are
    // grouped into one line per genre + condition.
    const MAX_IMPORT_RECORDS = 1000;
    const IMPORT_PAGES = 10; // x100 per page
    // Discogs value lookups (2 API calls each, shared ~60/min app limit) one
    // Get-my-quote press may make; the rest use cached values or the bulk rate.
    const LOOKUP_BUDGET = 20;
    const MAX_GROUPS = 40;
    const MAX_GROUP_QTY = 20000;
    // Large lots: the low end of the estimate range, as a share of the quote.
    const LARGE_LOW_SHARE = 0.80;
    // Bulk lots graded NM / Mint need a manager in store, so cap them here.
    const BULK_GRADE_CAP = 'VG+';

    const GRADE_HELP = [
        'Mint' => 'Still sealed, never played',
        'Near Mint' => 'Played a few times, looks new',
        'VG+' => 'Light marks or wear, plays clean',
        'VG' => 'Visible marks, some surface noise',
        'Good+' => 'Lots of wear, noisy but plays through',
        'Good' => 'Heavy wear, skips or loud noise',
        'Fair' => 'Damaged, cover torn or split',
        'Poor' => 'Badly damaged, barely playable',
    ];

    // Customer-facing groups for the bulk item types, matched on the type key.
    const TYPE_GROUPS = [
        ['Bulk Vinyl Used', '/^bulk_vinyl_(?!sealed)/'],
        // Bulk Vinyl Sealed removed 11/10 (front desk): sellers could claim a pile
        // was sealed and rack up a big quote sight unseen. Sealed records can
        // still be listed one by one (Discogs-priced); staff buy form unchanged.
        ['45s / 7-inch', '/^rpm45/'],
        ['CDs', '/^cd_(?!player)/'],
        ['Cassettes', '/^cassette_(?!player)/'],
        ['Movies (DVD / Blu-ray / VHS)', '/^(dvd_(?!player)|bluray|4k|vhs|laserdisc)/'],
        ['Video games', '/^videogame/'],
        ['Books & magazines', '/^(book|magazine|coffee_table)/'],
        ['Clothing', '/^(hat_|tshirt|tanktop|sweatshirt)/'],
        ['Turntables & audio gear', '/^(turntable|receiver|dvd_player|cd_player|cassette_player|camcorder|flatscreen)/'],
        ['Toys & collectibles', '/^(toy_|funko|furry|mask|board_game|pin$)/'],
        ['Cards', '/^(pokemon|sports_card)/'],
        ['Posters & art', '/^(poster_2x3|framed_art)/'],
        ['Sealed card packs', '/(?!)/'],
        ['Autographs & signed items', '/(?!)/'],
    ];

    // Sarah 11/8: priced off each item's real sold value, which a seller can't
    // look up, so the website lists them as "priced in store" ($0 on the
    // quote); the seller says what each is and staff price it on the buy form.
    /** eBay lookups for signed lines per price press. */
    const SIGNED_LOOKUP_BUDGET = 10;

    const IN_STORE_TYPES = [
        'signed_vinyl' => ['Autographs & signed items', 'Signed vinyl'],
        'signed_cd' => ['Autographs & signed items', 'Signed CD'],
        'signed_poster' => ['Autographs & signed items', 'Signed poster'],
        'signed_item' => ['Autographs & signed items', 'Other signed item'],
        'card_pack' => ['Sealed card packs', 'Sealed card packs (any brand)'],
    ];

    /** @var BuyOfferCalculatorService */
    protected $calculator;

    public function __construct(BuyOfferCalculatorService $calculator)
    {
        $this->calculator = $calculator;
    }

    protected function businessId()
    {
        return (int) (config('services.nivessa_web.business_id') ?: 1);
    }

    protected function cors($response)
    {
        return $response->header('Access-Control-Allow-Origin', '*')
            ->header('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
            ->header('Access-Control-Allow-Headers', 'Content-Type')
            ->header('Cache-Control', 'no-store');
    }

    protected function json($data, $status = 200)
    {
        return $this->cors(response()->json($data, $status));
    }

    public function preflight()
    {
        return $this->cors(response('', 204));
    }

    /** Pico + Hollywood, the two stores that buy. */
    protected function stores()
    {
        return DB::table('business_locations')->where('business_id', $this->businessId())
            ->where(function ($q) {
                $q->where('name', 'like', '%pico%')->orWhere('name', 'like', '%hollywood%');
            })->orderBy('name')->get(['id', 'name'])
            ->map(function ($s) {
                return ['id' => (int) $s->id, 'name' => stripos($s->name, 'pico') !== false ? 'Pico' : 'Hollywood'];
            })->values()->all();
    }

    /** Bulk (per-piece) types the website offers, grouped for the dropdown. */
    protected function publicTypes()
    {
        $rules = $this->calculator->getRules();
        $out = [];
        foreach ($this->calculator->getItemTypesForDropdown(true, false) as $key => $label) {
            $cfg = $rules['item_types'][$key] ?? [];
            if (($cfg['mode'] ?? '') !== 'bulk_fixed' || empty($cfg['unit_rate'])) {
                continue;
            }
            $group = null;
            foreach (self::TYPE_GROUPS as [$name, $re]) {
                if (preg_match($re, $key)) {
                    $group = $name;
                    break;
                }
            }
            if ($group === null) {
                continue;
            }
            $clean = preg_replace('/^10\+ Bulk (SEALED )?Vinyl: |^(CD|Cassette|45 RPM): |^DVD Used: /', '', $label);
            $sealed = strpos($key, '_sealed') !== false || stripos($label, 'sealed') !== false;
            $out[] = [
                'key' => $key,
                'group' => $group,
                'label' => trim($clean),
                'graded' => empty($cfg['no_grading']) && !$sealed,
            ];
        }
        foreach (self::IN_STORE_TYPES as $key => [$group, $label]) {
            if (isset($rules['item_types'][$key])) {
                $out[] = ['key' => $key, 'group' => $group, 'label' => $label, 'graded' => false, 'in_store' => true];
            }
        }
        $order = array_flip(array_column(self::TYPE_GROUPS, 0));
        usort($out, function ($a, $b) use ($order) {
            return ($order[$a['group']] <=> $order[$b['group']]) ?: strnatcasecmp($a['label'], $b['label']);
        });
        return $out;
    }

    /** GET /sell-quote/options */
    public function options()
    {
        $data = Cache::remember('sellquote:options6', 600, function () {
            $grades = [];
            foreach (array_reverse($this->calculator->getGradesForDropdown()) as $g) {
                $grades[] = ['key' => $g, 'help' => self::GRADE_HELP[$g] ?? ''];
            }
            return [
                'stores' => $this->stores(),
                'types' => $this->publicTypes(),
                'grades' => $grades,
                'bulk_grade_cap' => self::BULK_GRADE_CAP,
                'sizes' => ['medium_from' => self::MEDIUM_FROM, 'large_from' => self::LARGE_FROM, 'cherry_min' => self::CHERRY_MIN],
                'valid_days' => self::VALID_DAYS,
            ];
        });
        return $this->json($data);
    }

    /** POST /sell-quote/start: name + phone (+ email) first, so we keep the lead. */
    public function start(Request $request)
    {
        $name = trim(strip_tags((string) $request->input('name')));
        $phoneDigits = preg_replace('/\D+/', '', (string) $request->input('phone'));
        $email = trim((string) $request->input('email'));
        if (mb_strlen($name) < 2) {
            return $this->json(['error' => 'Please enter your name.'], 422);
        }
        if (strlen($phoneDigits) < 10 || strlen($phoneDigits) > 15) {
            return $this->json(['error' => 'Please enter a phone number we can reach you at.'], 422);
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->json(['error' => 'That email doesn\'t look right (or leave it blank).'], 422);
        }
        $storeIds = array_column($this->stores(), 'id');
        $locationId = (int) $request->input('location_id');
        $size = in_array($request->input('size'), ['small', 'medium', 'large'], true) ? $request->input('size') : null;

        $token = Str::random(32);
        $id = DB::table('sell_quotes')->insertGetId([
            'business_id' => $this->businessId(),
            'token' => $token,
            'name' => mb_substr($name, 0, 120),
            'phone' => strlen($phoneDigits) === 10 ? sprintf('(%s) %s-%s', substr($phoneDigits, 0, 3), substr($phoneDigits, 3, 3), substr($phoneDigits, 6)) : $phoneDigits,
            'email' => $email !== '' ? mb_substr($email, 0, 160) : null,
            'location_id' => in_array($locationId, $storeIds, true) ? $locationId : null,
            'size_picked' => $size,
            'status' => 'started',
            'ip' => mb_substr((string) $request->ip(), 0, 64),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return $this->json(['token' => $token, 'number' => self::number($id)]);
    }

    public static function number($id)
    {
        return 'Q' . (1000 + (int) $id);
    }

    public static function idFromNumber($input)
    {
        $digits = preg_replace('/\D+/', '', (string) $input);
        if ($digits === '') return null;
        $n = (int) $digits;
        return $n > 1000 ? $n - 1000 : $n;
    }

    /** GET /sell-quote/search?q=&format=Vinyl: Discogs releases to pick from. */
    public function search(Request $request)
    {
        $q = trim(mb_substr((string) $request->input('q'), 0, 120));
        if (mb_strlen($q) < 2) {
            return $this->json(['results' => []]);
        }
        $format = in_array($request->input('format'), ['Vinyl', 'CD', 'Cassette'], true) ? $request->input('format') : 'Vinyl';
        $key = 'sellquote:search1:' . md5(mb_strtolower($q) . '|' . $format);
        if ($hit = Cache::get($key)) {
            return $this->json($hit);
        }
        $discogs = new DiscogsService($this->businessId());
        if (!$discogs->isConfigured()) {
            return $this->json(['results' => [], 'error' => 'Lookups are offline right now. You can still list it and we\'ll price it in store.']);
        }
        $res = $discogs->callApi($discogs->getReleaseApiUrl(['query' => $q]) . '&per_page=40&format=' . urlencode($format));
        if (!empty($res['error'])) {
            $limited = strpos((string) $res['message'], '429') !== false;
            return $this->json(['results' => [], 'error' => $limited ? 'Lots of lookups right now. Try again in a minute.' : 'Lookup failed. Try again.'], $limited ? 429 : 200);
        }
        $results = (array) ($res['data']->results ?? []);
        usort($results, function ($a, $b) {
            return ($b->community->have ?? 0) <=> ($a->community->have ?? 0);
        });
        $out = ['results' => []];
        foreach (array_slice($results, 0, 12) as $r) {
            $meta = [
                'genre' => array_values((array) ($r->genre ?? [])),
                'style' => array_values((array) ($r->style ?? [])),
            ];
            // Kept so pricing can map the record to its genre's bulk rate.
            Cache::put('sellquote:rel:' . (int) $r->id, $meta, now()->addDays(30));
            $out['results'][] = [
                'id' => (int) $r->id,
                'title' => (string) ($r->title ?? ''),
                'year' => (string) ($r->year ?? ''),
                'country' => (string) ($r->country ?? ''),
                'label' => implode(', ', array_slice(array_unique((array) ($r->label ?? [])), 0, 1)),
                'catno' => (string) ($r->catno ?? ''),
                'format' => implode(', ', array_slice(array_unique((array) ($r->format ?? [])), 0, 4)),
                'thumb' => (string) ($r->thumb ?? ''),
            ];
        }
        Cache::put($key, $out, now()->addDay());
        return $this->json($out);
    }

    /** Discogs value for one release (cached a week, shared with Store or Online?). */
    protected function releaseValueFor($id)
    {
        $id = (int) $id;
        if ($id <= 0) return null;
        $key = 'sod-release-value:' . $id;
        if ($hit = Cache::get($key)) return $hit;
        $discogs = new DiscogsService($this->businessId());
        if (!$discogs->isConfigured()) return null;
        $out = $this->releasePrices($this->businessId(), $discogs, $id, 0, 0);
        if ($out === null) return ['rate_limited' => true];
        Cache::put($key, $out, now()->addDays(7));
        return $out;
    }

    protected function releaseMeta($id)
    {
        $meta = Cache::get('sellquote:rel:' . (int) $id);
        if ($meta) return $meta;
        $discogs = new DiscogsService($this->businessId());
        if (!$discogs->isConfigured()) return ['genre' => [], 'style' => []];
        $rel = $discogs->callApi('https://api.discogs.com/releases/' . (int) $id . '?token=' . urlencode($this->discogsToken($this->businessId())));
        $meta = [
            'genre' => array_values((array) ($rel['data']->genres ?? [])),
            'style' => array_values((array) ($rel['data']->styles ?? [])),
        ];
        if (empty($rel['error'])) {
            Cache::put('sellquote:rel:' . (int) $id, $meta, now()->addDays(30));
        }
        return $meta;
    }

    /** GET /sell-quote/release-value?id= : warms the cache as each record is picked. */
    public function releaseValue(Request $request)
    {
        $v = $this->releaseValueFor($request->input('id'));
        if (!empty($v['rate_limited'])) {
            return $this->json(['found' => false, 'busy' => true], 429);
        }
        return $this->json(['found' => !empty($v['found']) && !empty($v['price'])]);
    }

    const DISCOGS_GRADES = [
        'Mint (M)' => 'Mint',
        'Near Mint (NM or M-)' => 'Near Mint',
        'Very Good Plus (VG+)' => 'VG+',
        'Very Good (VG)' => 'VG',
        'Good Plus (G+)' => 'Good+',
        'Good (G)' => 'Good',
        'Fair (F)' => 'Fair',
        'Poor (P)' => 'Poor',
    ];

    /** "discogs.com/user/jdoe/collection", "@jdoe", "jdoe" -> "jdoe". */
    public static function discogsUsername($input)
    {
        $s = trim((string) $input);
        if (preg_match('~discogs\.com/(?:[a-z]{2}/)?(?:user|seller)/([^/?#\s]+)~i', $s, $m)) {
            $s = urldecode($m[1]);
        }
        $s = ltrim($s, '@');
        return preg_match('/^[A-Za-z0-9._-]{2,64}$/', $s) ? $s : null;
    }

    /**
     * GET /sell-quote/discogs-collection?user= (customer idea 11/9): paste a
     * Discogs profile / collection link and the quote fills itself. LPs come
     * back as records (priced like looked-up ones); CDs, tapes and 7-inches
     * come back as per-piece counts by genre. Collection must be public.
     */
    public function discogsCollection(Request $request)
    {
        $user = self::discogsUsername($request->input('user'));
        if (!$user) {
            return $this->json(['error' => 'Paste your Discogs collection link (like discogs.com/user/yourname/collection) or just your Discogs username.'], 422);
        }
        $key = 'sellquote:dcoll1:' . strtolower($user);
        if ($hit = Cache::get($key)) {
            return $this->json($hit);
        }
        $discogs = new DiscogsService($this->businessId());
        if (!$discogs->isConfigured()) {
            return $this->json(['error' => 'Discogs lookups are offline right now. You can still list your items below.'], 503);
        }
        $token = urlencode($this->discogsToken($this->businessId()));
        $items = [];
        $total = 0;
        for ($page = 1; $page <= self::IMPORT_PAGES; $page++) {
            $res = $discogs->callApi('https://api.discogs.com/users/' . rawurlencode($user) . '/collection/folders/0/releases?per_page=100&sort=artist&page=' . $page . '&token=' . $token);
            if (!empty($res['error'])) {
                $code = preg_match('/HTTP Error: (\d+)/', (string) ($res['message'] ?? ''), $cm) ? (int) $cm[1] : 0;
                if ($page > 1) break; // keep what we got
                if ($code === 429) {
                    return $this->json(['error' => 'Lots of Discogs lookups right now. Try again in a minute.'], 429);
                }
                if ($code === 404) {
                    return $this->json(['error' => 'We couldn\'t find a Discogs user called "' . $user . '". Check the link and try again.'], 404);
                }
                if ($code === 403 || $code === 401) {
                    return $this->json(['error' => 'That Discogs collection is private. In Discogs go to Settings → Privacy, turn on "Show my collection", then try again.'], 403);
                }
                return $this->json(['error' => 'We couldn\'t read that collection. Try again in a minute.'], 502);
            }
            $data = $res['data'] ?? null;
            $total = (int) ($data->pagination->items ?? 0);
            foreach ((array) ($data->releases ?? []) as $rel) {
                $items[] = $rel;
            }
            if ($page >= (int) ($data->pagination->pages ?? 1)) break;
        }
        if (!$items) {
            return $this->json(['error' => 'That Discogs collection is empty (or private). In Discogs go to Settings → Privacy and turn on "Show my collection".'], 404);
        }

        $publicTypes = array_column($this->publicTypes(), null, 'key');
        $records = [];
        $groups = [];
        $skipped = 0;
        foreach ($items as $it) {
            $b = $it->basic_information ?? null;
            if (!$b || empty($b->id)) continue;
            $id = (int) $b->id;
            $genres = array_values((array) ($b->genres ?? []));
            $styles = array_values((array) ($b->styles ?? []));
            Cache::put('sellquote:rel:' . $id, ['genre' => $genres, 'style' => $styles], now()->addDays(30));

            $fmtNames = [];
            $desc = [];
            $qty = 0;
            foreach ((array) ($b->formats ?? []) as $f) {
                $fmtNames[] = (string) ($f->name ?? '');
                $qty += (int) ($f->qty ?? 1);
                foreach ((array) ($f->descriptions ?? []) as $d) $desc[] = (string) $d;
            }
            $descS = implode(' ', $desc);
            $artists = implode(', ', array_map(function ($a) {
                return preg_replace('/\s*\(\d+\)$/', '', (string) ($a->name ?? ''));
            }, (array) ($b->artists ?? [])));
            $title = trim(($artists !== '' ? $artists . ' - ' : '') . (string) ($b->title ?? ''));
            $grade = null;
            foreach ((array) ($it->notes ?? []) as $n) {
                if ((int) ($n->field_id ?? 0) === 1 && isset(self::DISCOGS_GRADES[(string) ($n->value ?? '')])) {
                    $grade = self::DISCOGS_GRADES[(string) $n->value];
                }
            }

            $type = null;
            if (in_array('Vinyl', $fmtNames, true)) {
                if (preg_match('/\b7"/', $descS) && !preg_match('/\bLP\b|12"/', $descS)) {
                    $type = self::rpm45TypeFor($genres, $styles);
                } else {
                    $label = (array) ($b->labels ?? []);
                    $records[] = [
                        'release_id' => $id,
                        'title' => mb_substr($title, 0, 200),
                        'thumb' => (string) ($b->thumb ?? ''),
                        'sub' => implode(' · ', array_filter([(string) ($b->year ?: ''), (string) ($label[0]->name ?? '')])),
                        'grade' => $grade,
                    ];
                    continue;
                }
            } elseif (in_array('CD', $fmtNames, true)) {
                $type = ($qty >= 4 || stripos($descS, 'Box Set') !== false) ? 'cd_boxset_used' : self::cdTypeFor($genres, $styles);
            } elseif (in_array('Cassette', $fmtNames, true)) {
                $type = self::cassetteTypeFor($genres, $styles);
            }
            if (!$type || !isset($publicTypes[$type])) {
                $skipped++;
                continue;
            }
            $gk = $type . '|' . ($publicTypes[$type]['graded'] ? (string) $grade : '');
            if (!isset($groups[$gk])) {
                $groups[$gk] = [
                    'type' => $type,
                    'group' => $publicTypes[$type]['group'],
                    'label' => $publicTypes[$type]['label'],
                    'quantity' => 0,
                    'grade' => $publicTypes[$type]['graded'] && $grade ? $this->capBulkGrade($grade) : '',
                ];
            }
            $groups[$gk]['quantity']++;
        }
        $out = [
            'username' => $user,
            'total' => $total,
            'records' => array_slice($records, 0, self::MAX_IMPORT_RECORDS),
            'groups' => array_values($groups),
            'skipped' => $skipped,
            'capped' => $total > count($items) || count($records) > self::MAX_IMPORT_RECORDS,
        ];
        Cache::put($key, $out, now()->addHour());
        return $this->json($out);
    }

    public static function cdTypeFor(array $genres, array $styles)
    {
        $g = array_map('strtolower', $genres);
        $s = strtolower(implode(' | ', $styles));
        if (in_array('hip hop', $g, true)) return 'cd_hiphop_used';
        if (preg_match('/metal|grindcore/', $s)) return 'cd_metal_used';
        if (preg_match('/punk|hardcore/', $s)) return 'cd_punk_used';
        if (in_array('rock', $g, true)) return 'cd_rock_alt_newwave_used';
        if (in_array('electronic', $g, true)) return 'cd_electronic_used';
        if (in_array('pop', $g, true)) return 'cd_pop_used';
        if (array_intersect($g, ['jazz', 'funk / soul', 'blues'])) return 'cd_rb_jazz_used';
        if (in_array('stage & screen', $g, true)) return 'cd_soundtracks_used';
        if (in_array('classical', $g, true)) return 'cd_classical_opera_used';
        if (array_intersect($g, ['non-music', 'children\'s'])) return 'cd_nonmusic_used';
        return 'cd_general_used';
    }

    public static function cassetteTypeFor(array $genres, array $styles)
    {
        $g = array_map('strtolower', $genres);
        $s = strtolower(implode(' | ', $styles));
        if (in_array('hip hop', $g, true)) return 'cassette_hiphop_used';
        if (preg_match('/metal|grindcore/', $s)) return 'cassette_metal_used';
        if (preg_match('/punk|hardcore/', $s)) return 'cassette_punk_used';
        if (in_array('funk / soul', $g, true)) return 'cassette_rnb_used';
        if (in_array('jazz', $g, true)) return 'cassette_jazz_used';
        if (in_array('rock', $g, true)) return preg_match('/alternative|indie|grunge|shoegaze/', $s) ? 'cassette_alt' : 'cassette_rock_used';
        return 'cassette_everything_else';
    }

    public static function rpm45TypeFor(array $genres, array $styles)
    {
        $g = array_map('strtolower', $genres);
        $s = strtolower(implode(' | ', $styles));
        if (in_array('hip hop', $g, true) || preg_match('/punk|hardcore|metal/', $s)) return 'rpm45_hh_metal_punk';
        if (in_array('rock', $g, true) || strpos($s, 'new wave') !== false) return 'rpm45_newwave_rock_alt';
        if (in_array('funk / soul', $g, true)) return 'rpm45_rnb';
        return 'rpm45_anything_else';
    }

    /** A used LP's bulk type from its Discogs genres / styles. */
    public static function bulkVinylTypeFor(array $genres, array $styles)
    {
        $g = array_map('strtolower', $genres);
        $s = strtolower(implode(' | ', $styles));
        if (in_array('hip hop', $g, true) || preg_match('/punk|hardcore|metal|grindcore/', $s)) return 'bulk_vinyl_punk_metal_hiphop';
        if (array_intersect($g, ['rock', 'electronic', 'reggae'])) return 'bulk_vinyl_rock_alt_reggae_electronic';
        if (array_intersect($g, ['jazz', 'funk / soul'])) return 'bulk_vinyl_rb_jazz';
        if (in_array('blues', $g, true)) return 'bulk_vinyl_blues';
        if (in_array('latin', $g, true)) return 'bulk_vinyl_latin';
        if (in_array('classical', $g, true)) return 'bulk_vinyl_classical';
        if (in_array('stage & screen', $g, true)) return 'bulk_vinyl_soundtracks';
        if (in_array('pop', $g, true)) return 'bulk_vinyl_pop_old';
        if (in_array('folk, world, & country', $g, true)) {
            return strpos($s, 'country') !== false ? 'bulk_vinyl_country' : 'bulk_vinyl_folk';
        }
        return 'bulk_vinyl_anything_else';
    }

    protected function capBulkGrade($grade)
    {
        $order = array_keys($this->calculator->getRules()['grade_multipliers']); // worst -> best
        $pos = array_search($grade, $order, true);
        $cap = array_search(self::BULK_GRADE_CAP, $order, true);
        if ($pos === false) return 'VG';
        return $pos > $cap ? self::BULK_GRADE_CAP : $grade;
    }

    /** Value tier % (same breakpoints as computeStdMult() on the buy form). */
    public static function stdMult($price)
    {
        $p = (float) $price;
        if ($p <= 0 || $p < 5) return 0.10;
        if ($p < 10) return 0.20;
        if ($p < 15) return 0.22;
        if ($p < 20) return 0.25;
        if ($p < 30) return 0.26;
        if ($p < 375) return 0.27;
        return 0.31;
    }

    protected function findByToken($token)
    {
        if (!is_string($token) || strlen($token) !== 32) return null;
        return DB::table('sell_quotes')->where('token', $token)->first();
    }

    /** POST /sell-quote/{token}/price: price the list, save it, return the quote. */
    public function price(Request $request, $token)
    {
        $quote = $this->findByToken($token);
        if (!$quote) {
            return $this->json(['error' => 'Quote not found. Start again from step 1.'], 404);
        }
        if ($quote->status === 'in_buy') {
            return $this->json(['error' => 'This quote is already being used for a buy in store.'], 409);
        }
        // Sarah 11/9: the seller no longer picks a store up front. We price the
        // list for every buying store and show the offers side by side; they
        // pick on the quote screen (POST /sell-quote/{token}/store). A store
        // they already picked sticks across re-prices.
        $stores = $this->stores();
        $storeIds = array_column($stores, 'id');
        $prevCalc = json_decode((string) $quote->calculation_json, true) ?: [];
        $chosenId = (int) $request->input('location_id');
        if (!in_array($chosenId, $storeIds, true)) {
            $chosenId = (self::storeChosen($prevCalc) && in_array((int) $quote->location_id, $storeIds, true)) ? (int) $quote->location_id : 0;
        }
        if (!$storeIds) {
            return $this->json(['error' => 'Quotes are offline right now. Please bring your items in.'], 503);
        }
        $rules = $this->calculator->getRules();
        $grades = $rules['grade_multipliers'];
        $types = array_column($this->publicTypes(), null, 'key');

        $records = (array) $request->input('records', []);
        $imported = count(array_filter($records, function ($r) {
            return !empty($r['imported']);
        }));
        $records = array_slice($records, 0, $imported > 0 ? self::MAX_IMPORT_RECORDS + self::MAX_RECORDS : self::MAX_RECORDS);
        $groups = array_slice((array) $request->input('groups', []), 0, self::MAX_GROUPS);
        $notes = trim(mb_substr(strip_tags((string) $request->input('notes')), 0, 2000));

        // Clean the seller's input.
        $cleanRecords = [];
        foreach ($records as $r) {
            $title = trim(mb_substr(strip_tags((string) ($r['title'] ?? '')), 0, 200));
            $grade = (string) ($r['grade'] ?? '');
            if ($title === '') continue;
            if (!isset($grades[$grade])) {
                return $this->json(['error' => 'Pick a condition for "' . $title . '".'], 422);
            }
            $cleanRecords[] = ['title' => $title, 'release_id' => (int) ($r['release_id'] ?? 0) ?: null, 'grade' => $grade]
                + (!empty($r['imported']) ? ['imported' => true] : []);
        }
        $cleanGroups = [];
        foreach ($groups as $g) {
            $key = (string) ($g['type'] ?? '');
            $qty = (int) ($g['quantity'] ?? 0);
            if (!isset($types[$key]) || $qty <= 0) continue;
            $qty = min($qty, self::MAX_GROUP_QTY);
            $grade = null;
            if ($types[$key]['graded']) {
                $grade = (string) ($g['grade'] ?? '');
                if (!isset($grades[$grade])) {
                    return $this->json(['error' => 'Pick a condition for your ' . $types[$key]['group'] . ': ' . $types[$key]['label'] . '.'], 422);
                }
                $grade = $this->capBulkGrade($grade);
            }
            $clean = ['type' => $key, 'quantity' => $qty, 'grade' => $grade];
            if (!empty($types[$key]['in_store'])) {
                $clean['description'] = trim(mb_substr(strip_tags((string) ($g['description'] ?? '')), 0, 150));
            }
            $cleanGroups[] = $clean;
        }
        $count = count($cleanRecords) + array_sum(array_column($cleanGroups, 'quantity'));
        if ($count === 0) {
            return $this->json(['error' => 'Add at least one item.'], 422);
        }
        $tier = $count >= self::LARGE_FROM ? 'large' : ($count >= self::MEDIUM_FROM ? 'medium' : 'small');

        // Build buy-form lines, keeping a display row for each.
        $lines = [];
        $display = [];
        // Big lists (a Discogs import): bulk-rate records collapse into one
        // line per bulk type + grade instead of hundreds of single lines.
        $collapse = count($cleanRecords) > self::MAX_RECORDS;
        $bulkPiles = [];
        $lookups = 0;
        $unchecked = 0;
        foreach ($cleanRecords as $r) {
            $value = null;
            $notChecked = false;
            if ($r['release_id']) {
                if (Cache::has('sod-release-value:' . $r['release_id']) || $lookups < self::LOOKUP_BUDGET) {
                    $wasCached = Cache::has('sod-release-value:' . $r['release_id']);
                    $value = $this->releaseValueFor($r['release_id']);
                    if (!$wasCached) $lookups++;
                    if (!empty($value['rate_limited'])) {
                        if (count($cleanRecords) <= self::MAX_RECORDS) {
                            return $this->json(['error' => 'Our record lookups are busy. Wait a minute and press Get my quote again.'], 429);
                        }
                        // Big import: don't fail the whole quote, price the rest by the piece.
                        $lookups = self::LOOKUP_BUDGET;
                        $value = null;
                        $unchecked++;
                        $notChecked = true;
                    }
                } else {
                    $unchecked++;
                    $notChecked = true;
                }
            }
            $price = (!empty($value['found']) && !empty($value['price'])) ? (float) $value['price'] : 0.0;
            $individual = $price > 0 && ($tier === 'small' || $price >= self::CHERRY_MIN);
            if ($individual) {
                $lines[] = [
                    'item_type' => 'individual_vinyl',
                    'quantity' => 1,
                    'title' => $r['title'],
                    'discogs_link' => 'https://www.discogs.com/release/' . $r['release_id'],
                    'discogs_median_price' => $price,
                    'condition_grade' => $r['grade'],
                    'standard_multiplier' => self::stdMult($price),
                ];
                $display[] = ['label' => $r['title'], 'quantity' => 1, 'grade' => $r['grade'], 'how' => 'record'];
            } else {
                $meta = $r['release_id'] ? $this->releaseMeta($r['release_id']) : ['genre' => [], 'style' => []];
                $type = self::bulkVinylTypeFor($meta['genre'] ?? [], $meta['style'] ?? []);
                if ($collapse) {
                    $pk = $type . '|' . $this->capBulkGrade($r['grade']);
                    $bulkPiles[$pk] = ($bulkPiles[$pk] ?? 0) + 1;
                    continue;
                }
                $lines[] = [
                    'item_type' => $type,
                    'quantity' => 1,
                    'title' => $r['title'],
                    'condition_grade' => $this->capBulkGrade($r['grade']),
                    'genre' => '',
                ];
                $display[] = [
                    'label' => $r['title'],
                    'quantity' => 1,
                    'grade' => $this->capBulkGrade($r['grade']),
                    'how' => $price > 0 ? 'bulk_rate' : ($notChecked ? 'not_checked' : 'no_sales_data'),
                ];
            }
        }
        $typeLabels = $collapse ? $this->calculator->getItemTypesForDropdown(true, true) : [];
        foreach ($bulkPiles as $pk => $qty) {
            [$type, $grade] = explode('|', $pk, 2);
            $lines[] = [
                'item_type' => $type,
                'quantity' => $qty,
                'title' => null,
                'condition_grade' => $grade,
                'genre' => '',
            ];
            $display[] = [
                'label' => 'Records from your list: ' . trim(preg_replace('/^10\+ Bulk (SEALED )?Vinyl: /', '', $typeLabels[$type] ?? $type)),
                'quantity' => $qty,
                'grade' => $grade,
                'how' => 'per_piece',
            ];
        }
        $inStoreCount = 0;
        $signedLookups = 0;
        foreach ($cleanGroups as $g) {
            if (!empty($types[$g['type']]['in_store'])) {
                $desc = $g['description'] ?? '';
                // Jon 11/9: signed items with a "who signed it, what is it"
                // get an eBay estimate (same lookup as the buy form's
                // "Look up eBay value"); no data = still priced in store.
                $est = null;
                if ($desc !== '' && array_key_exists($g['type'], SignedValueLookupService::FORMAT_WORDS) && $signedLookups < self::SIGNED_LOOKUP_BUDGET) {
                    $signedLookups++;
                    $est = app(SignedValueLookupService::class)->lookup($this->businessId(), $desc, $g['type']);
                }
                if (!empty($est['ok']) && $est['suggested'] > 0) {
                    $lines[] = [
                        'item_type' => $g['type'],
                        'quantity' => $g['quantity'],
                        'title' => $desc,
                        'discogs_median_price' => (float) $est['suggested'],
                        'discogs_link' => $est['search_url'],
                        'genre' => '',
                    ];
                    $display[] = [
                        'label' => $types[$g['type']]['label'] . ': ' . $desc,
                        'quantity' => $g['quantity'],
                        'grade' => null,
                        'how' => 'signed_ebay',
                    ];
                    continue;
                }
                // No value on the quote: staff enter it on the buy form.
                $inStoreCount += $g['quantity'];
                $lines[] = [
                    'item_type' => $g['type'],
                    'quantity' => $g['quantity'],
                    'title' => $desc !== '' ? $desc : $types[$g['type']]['label'],
                    'discogs_median_price' => 0,
                    'genre' => '',
                ];
                $display[] = [
                    'label' => $types[$g['type']]['label'] . ($desc !== '' ? ': ' . $desc : ''),
                    'quantity' => $g['quantity'],
                    'grade' => null,
                    'how' => 'in_store',
                ];
                continue;
            }
            $lines[] = [
                'item_type' => $g['type'],
                'quantity' => $g['quantity'],
                'title' => null,
                'condition_grade' => $g['grade'] ?? $this->calculator->defaultGradeFor($g['type']),
                'genre' => '',
            ];
            $display[] = [
                'label' => $types[$g['type']]['group'] . ': ' . $types[$g['type']]['label'],
                'quantity' => $g['quantity'],
                'grade' => $g['grade'],
                'how' => 'per_piece',
            ];
        }

        // Price the same list once per store (each store's own demand levels).
        $byStore = [];
        foreach ($storeIds as $sid) {
            $calculation = $this->calculator->calculate($lines, [], $this->demandHook($sid, $lines));
            $final = (float) $calculation['final_offer_cash'];
            $cashTotal = (float) $calculation['calculated_cash_total'];
            $share = $cashTotal > 0 ? $final / $cashTotal : 0;
            // Map calculator rows back to display rows (it skips nothing we
            // built, but match on line_order to be safe).
            $outLines = [];
            foreach ($calculation['lines'] as $row) {
                $i = (int) $row['line_order'];
                $d = $display[$i] ?? ['label' => '', 'quantity' => $row['quantity'], 'grade' => null, 'how' => 'per_piece'];
                $mult = (float) ($row['demand_multiplier'] ?? 1);
                $d['cash'] = ($d['how'] ?? '') === 'in_store' ? null : round((float) $row['line_cash_total'] * $share, 2);
                $d['demand'] = $mult > 1.001 ? 'wanted' : ($mult < 0.999 ? 'overstocked' : null);
                $outLines[] = $d;
            }
            $byStore[$sid] = [
                'cash' => $final,
                'credit' => (float) $calculation['final_offer_credit'],
                'estimate_low_cash' => $tier === 'large' ? BuyOfferCalculatorService::roundOffer($final * self::LARGE_LOW_SHARE) : null,
                'lines' => $calculation['lines'],
                'display' => $outLines,
            ];
        }
        // Until the seller picks, the quote row carries the best-paying store.
        $bestId = $storeIds[0];
        foreach ($byStore as $sid => $o) {
            if ($o['cash'] > $byStore[$bestId]['cash'] + 0.004) $bestId = $sid;
        }
        $locationId = $chosenId ?: $bestId;
        $primary = $byStore[$locationId];

        $now = now();
        // Every quote, estimates too, is valid VALID_DAYS (Sarah 11/4).
        $expires = $now->copy()->addDays(self::VALID_DAYS)->endOfDay();
        DB::table('sell_quotes')->where('id', $quote->id)->update([
            'location_id' => $locationId,
            'size_tier' => $tier,
            'status' => 'quoted',
            'item_count' => $count,
            'input_json' => json_encode(['records' => $cleanRecords, 'groups' => $cleanGroups]),
            'calculation_json' => json_encode([
                'lines' => $primary['lines'],
                'display' => $primary['display'],
                'in_store_count' => $inStoreCount,
                'unchecked' => $unchecked,
                'by_store' => $byStore,
                'store_chosen' => $chosenId > 0,
            ]),
            'cash_total' => $primary['cash'],
            'credit_total' => $primary['credit'],
            'estimate_low_cash' => $primary['estimate_low_cash'],
            'notes' => $notes !== '' ? $notes : null,
            'quoted_at' => $now,
            'expires_at' => $expires,
            'updated_at' => $now,
        ]);
        $fresh = DB::table('sell_quotes')->find($quote->id);
        $sent = $this->notifySeller($fresh, $prevCalc['notified'] ?? null);
        // Markers kept across re-prices: when the seller was last sent this
        // number, and which stores' Slack channels already heard about it.
        $calcSaved = json_decode((string) $fresh->calculation_json, true) ?: [];
        if ($sent['sms'] || $sent['email']) {
            $calcSaved['notified'] = ['cash' => (float) $fresh->cash_total, 'at' => $now->timestamp];
        } elseif (!empty($prevCalc['notified'])) {
            $calcSaved['notified'] = $prevCalc['notified'];
        }
        $calcSaved['slacked'] = $this->slackedStores($prevCalc, $quote->location_id);
        if (!$calcSaved['slacked'] && $this->alertStore($fresh)) {
            $calcSaved['slacked'] = [(int) $locationId];
        }
        DB::table('sell_quotes')->where('id', $quote->id)->update(['calculation_json' => json_encode($calcSaved)]);
        $fresh = DB::table('sell_quotes')->find($quote->id);
        return $this->json($this->publicQuote($fresh) + ['sent' => $sent]);
    }

    /** Old quotes (one store, picked up front) count as already chosen. */
    protected static function storeChosen(array $calc)
    {
        return !isset($calc['by_store']) || !empty($calc['store_chosen']);
    }

    /** Store ids whose Slack channel already got this quote (was a bool before 11/9). */
    protected function slackedStores(array $calc, $locationId)
    {
        $s = $calc['slacked'] ?? [];
        if ($s === true) return $locationId ? [(int) $locationId] : [];
        return is_array($s) ? array_values(array_map('intval', $s)) : [];
    }

    /**
     * POST /sell-quote/{token}/store: the seller picks the store they'll bring
     * it to. Swaps that store's numbers onto the quote, texts / emails them the
     * single-store quote, and alerts that store's Slack if it hadn't heard yet.
     */
    public function chooseStore(Request $request, $token)
    {
        $quote = $this->findByToken($token);
        if (!$quote || $quote->status === 'started') {
            return $this->json(['error' => 'Quote not found.'], 404);
        }
        if ($quote->status === 'in_buy') {
            return $this->json(['error' => 'This quote is already being used for a buy in store.'], 409);
        }
        $calc = json_decode((string) $quote->calculation_json, true) ?: [];
        $sid = (int) $request->input('location_id');
        $byStore = $calc['by_store'] ?? [];
        if (!isset($byStore[$sid])) {
            return $this->json(['error' => 'Pick one of our stores.'], 422);
        }
        $o = $byStore[$sid];
        $wasChosen = !empty($calc['store_chosen']) && (int) $quote->location_id === $sid;
        $calc['lines'] = $o['lines'];
        $calc['display'] = $o['display'];
        $calc['store_chosen'] = true;
        DB::table('sell_quotes')->where('id', $quote->id)->update([
            'location_id' => $sid,
            'cash_total' => $o['cash'],
            'credit_total' => $o['credit'],
            'estimate_low_cash' => $o['estimate_low_cash'],
            'calculation_json' => json_encode($calc),
            'updated_at' => now(),
        ]);
        $fresh = DB::table('sell_quotes')->find($quote->id);
        $sent = ['sms' => false, 'email' => false];
        if (!$wasChosen) {
            $sent = $this->notifySeller($fresh, null);
            $slacked = $this->slackedStores($calc, $quote->location_id);
            if (!in_array($sid, $slacked, true) && $this->alertStore($fresh)) {
                $slacked[] = $sid;
            }
            $calc['slacked'] = $slacked;
            if ($sent['sms'] || $sent['email']) {
                $calc['notified'] = ['cash' => (float) $fresh->cash_total, 'at' => time()];
            }
            DB::table('sell_quotes')->where('id', $quote->id)->update(['calculation_json' => json_encode($calc)]);
            $fresh = DB::table('sell_quotes')->find($quote->id);
        }
        return $this->json($this->publicQuote($fresh) + ['sent' => $sent]);
    }

    /** Every store's offer for this quote, best first (one row for old quotes). */
    protected function offers($q, array $calc)
    {
        $names = collect($this->stores())->pluck('name', 'id')->all();
        $rows = [];
        if (!empty($calc['by_store'])) {
            foreach ($calc['by_store'] as $sid => $o) {
                if (!isset($names[(int) $sid])) continue;
                $rows[] = [
                    'location_id' => (int) $sid,
                    'store' => $names[(int) $sid],
                    'cash' => (float) $o['cash'],
                    'credit' => (float) $o['credit'],
                    'estimate_low_cash' => $o['estimate_low_cash'] !== null ? (float) $o['estimate_low_cash'] : null,
                    'estimate_low_credit' => $o['estimate_low_cash'] !== null ? round((float) $o['estimate_low_cash'] * 1.5, 2) : null,
                    'lines' => $o['display'] ?? [],
                ];
            }
            usort($rows, function ($a, $b) {
                return ($b['cash'] <=> $a['cash']) ?: strcmp($a['store'], $b['store']);
            });
        }
        return $rows;
    }

    /**
     * Sarah 11/4: post each NEW website quote to its store's Slack channel
     * (#pico / #hollywood) so staff know someone may be coming in. Once per
     * quote; later edits by the seller don't re-post. Never throws.
     */
    protected function alertStore($q)
    {
        try {
            $storeRow = collect($this->stores())->firstWhere('id', (int) $q->location_id);
            $storeKey = stripos((string) ($storeRow['name'] ?? ''), 'pico') !== false ? 'pico' : 'hollywood';
            $esc = function ($s) {
                return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], (string) $s);
            };
            $m = function ($n) {
                $n = (float) $n;
                return '$' . (fmod($n, 1) ? number_format($n, 2) : number_format($n, 0));
            };
            $items = (int) $q->item_count . ' item' . ((int) $q->item_count === 1 ? '' : 's');
            $who = $esc($q->name) . ', ' . $esc($q->phone) . ($q->email ? ', ' . $esc($q->email) : '');
            $inStore = (int) ((json_decode((string) $q->calculation_json, true) ?: [])['in_store_count'] ?? 0);
            if ($inStore > 0 && (float) $q->cash_total <= 0) {
                $money = 'autographs / sealed card packs to price in store.';
            } elseif ($q->size_tier === 'large') {
                $money = 'estimate ' . $m($q->estimate_low_cash) . '–' . $m($q->cash_total) . ' cash, valid through '
                    . \Carbon\Carbon::parse($q->expires_at)->format('D n/j') . '. Needs an appraisal: they were told to reply to the text or call.';
            } else {
                $money = $m($q->cash_total) . ' cash / ' . $m($q->credit_total) . ' credit, good through '
                    . \Carbon\Carbon::parse($q->expires_at)->format('D n/j') . '.';
            }
            if ($inStore > 0 && (float) $q->cash_total > 0) {
                $money .= ' Plus ' . $inStore . ' autograph / card pack item' . ($inStore === 1 ? '' : 's') . ' to price in store.';
            }
            $calcAll = json_decode((string) $q->calculation_json, true) ?: [];
            if (!self::storeChosen($calcAll)) {
                $money .= ' Hasn\'t picked a store yet (this one pays the most).';
            } elseif (isset($calcAll['by_store'])) {
                $money .= ' They picked this store.';
            }
            $text = ':moneybag: New website sell quote *' . self::number($q->id) . '*: ' . $who . ' · ' . $items . ' · ' . $money
                . "\n<" . url('/sell-quotes/' . $q->id) . '|Open the quote>';
            $resp = \App\Services\WebsiteBridge::erp('POST', '/slack-store-post', ['store' => $storeKey, 'text' => $text], 15);
            if (empty($resp['success'])) {
                \Log::warning('Sell quote Slack alert failed: ' . ($resp['error'] ?? $resp['message'] ?? 'unknown'));
                return false;
            }
            return true;
        } catch (\Throwable $e) {
            \Log::warning('Sell quote Slack alert failed: ' . $e->getMessage());
            return false;
        }
    }

    /** nivessa.com link that reopens a saved quote. */
    public static function quoteLink($token)
    {
        return 'https://nivessa.com/sell-your-stuff/quote?id=' . $token;
    }

    /**
     * Text the quote from the store's Quo line, and email it when the seller
     * gave an email (Sarah 11/4). Skipped when the same number already went
     * out in the last NOTIFY_REPEAT_MIN minutes (seller pressed Get my quote
     * twice). Never throws; a failed send never blocks the quote.
     */
    const NOTIFY_REPEAT_MIN = 30;

    protected function notifySeller($q, $prev)
    {
        $out = ['sms' => false, 'email' => false];
        if (is_array($prev) && abs((float) ($prev['cash'] ?? -1) - (float) $q->cash_total) < 0.005
            && (time() - (int) ($prev['at'] ?? 0)) < self::NOTIFY_REPEAT_MIN * 60) {
            return $out + ['repeat' => true];
        }
        $storeRow = collect($this->stores())->firstWhere('id', (int) $q->location_id);
        $storeName = $storeRow['name'] ?? '';
        $store = \App\Services\WantNotice::store($storeName);
        $first = trim(explode(' ', trim((string) $q->name))[0] ?? '');
        $number = self::number($q->id);
        $link = self::quoteLink($q->token);
        $items = (int) $q->item_count . ' item' . ((int) $q->item_count === 1 ? '' : 's');
        $m = function ($n) {
            $n = (float) $n;
            return '$' . (fmod($n, 1) ? number_format($n, 2) : number_format($n, 0));
        };
        $large = $q->size_tier === 'large';
        $inStore = (int) ((json_decode((string) $q->calculation_json, true) ?: [])['in_store_count'] ?? 0);
        $onlyInStore = $inStore > 0 && (float) $q->cash_total <= 0;
        $inStoreText = $inStore > 0 ? ' (' . $inStore . ' of them priced in store)' : '';
        $goodThrough = $q->expires_at ? \Carbon\Carbon::parse($q->expires_at)->format('l, F j') : '';
        $goodThroughShort = $q->expires_at ? \Carbon\Carbon::parse($q->expires_at)->format('D n/j') : '';
        $street = trim(explode(',', $store['address'])[0]);
        $calcAll = json_decode((string) $q->calculation_json, true) ?: [];
        $offers = self::storeChosen($calcAll) ? [] : $this->offers($q, $calcAll);

        if (count($offers) > 1) {
            // No store picked yet: one text with every store's offer, best
            // first, and the link to pick. The email waits for the pick.
            $hi = 'Hi' . ($first !== '' ? ' ' . $first : '') . ', Nivessa here. ';
            if ($onlyInStore) {
                $sms = $hi . 'Quote ' . $number . ': we price autographs and sealed card packs when we see them. Bring your ' . $items
                    . ' to either store and show this text at the counter. ' . $link;
            } else {
                $parts = [];
                foreach ($offers as $o) {
                    $parts[] = $o['store'] . ' ' . ($large ? $m($o['estimate_low_cash']) . '-' . $m($o['cash']) : $m($o['cash'])) . ' cash';
                }
                $sms = $hi . ($large ? 'Estimate ' : 'Quote ') . $number . ' for ' . $items . $inStoreText . ': ' . implode(', ', $parts)
                    . ' (1.5x that in store credit). Valid through ' . $goodThroughShort . ', final price confirmed on appraisal. Pick your store here: ' . $link;
            }
            try {
                $res = app(\App\Services\OpenPhoneService::class)->sendFromStore($storeName ?: $store['name'], (string) $q->phone, $sms);
                $out['sms'] = (bool) ($res['success'] ?? false);
                if (!$out['sms']) {
                    \Log::warning('Sell quote ' . $number . ' text failed: ' . ($res['msg'] ?? ''));
                }
            } catch (\Throwable $e) {
                \Log::warning('Sell quote ' . $number . ' text failed: ' . $e->getMessage());
            }
            return $out;
        }

        if ($onlyInStore) {
            $sms = 'Hi' . ($first !== '' ? ' ' . $first : '') . ', Nivessa here. Quote ' . $number . ': we price autographs and sealed card packs when we see them. Bring your '
                . $items . ' to ' . $store['name'] . ', ' . $street . ' and show this text at the counter. ' . $link;
        } elseif ($large) {
            $sms = 'Hi' . ($first !== '' ? ' ' . $first : '') . ', Nivessa here. Estimate ' . $number . ': '
                . $m($q->estimate_low_cash) . '-' . $m($q->cash_total) . ' cash or ' . $m($q->estimate_low_cash * 1.5) . '-' . $m($q->credit_total)
                . ' store credit for ' . $items . $inStoreText . '. Valid through ' . $goodThroughShort . ', final price confirmed on appraisal. Reply to set up a quick look in person. '
                . $link;
        } else {
            $sms = 'Hi' . ($first !== '' ? ' ' . $first : '') . ', Nivessa here. Quote ' . $number . ': '
                . $m($q->cash_total) . ' cash or ' . $m($q->credit_total) . ' store credit for ' . $items . $inStoreText
                . '. Valid through ' . $goodThroughShort . ' at ' . $store['name'] . ', ' . $street . '. Final price confirmed on appraisal. Show this text at the counter. '
                . $link;
        }
        try {
            $res = app(\App\Services\OpenPhoneService::class)->sendFromStore($storeName ?: $store['name'], (string) $q->phone, $sms);
            $out['sms'] = (bool) ($res['success'] ?? false);
            if (!$out['sms']) {
                \Log::warning('Sell quote ' . $number . ' text failed: ' . ($res['msg'] ?? ''));
            }
        } catch (\Throwable $e) {
            \Log::warning('Sell quote ' . $number . ' text failed: ' . $e->getMessage());
        }

        // The email template always shows a dollar amount, so skip it when there is none.
        if (!empty($q->email) && !$onlyInStore) {
            try {
                $resp = \App\Services\WebsiteBridge::erp('POST', '/sell-quote/email', [
                    'to' => $q->email,
                    'firstName' => $first,
                    'store' => $store['key'],
                    'number' => $number,
                    'tier' => $large ? 'large' : 'small',
                    'cash' => (float) $q->cash_total,
                    'credit' => (float) $q->credit_total,
                    'lowCash' => (float) $q->estimate_low_cash,
                    'lowCredit' => round((float) $q->estimate_low_cash * 1.5, 2),
                    'itemCount' => (int) $q->item_count,
                    'goodThrough' => $goodThrough,
                    'link' => $link,
                ], 15);
                $out['email'] = !empty($resp['success']);
                if (!$out['email']) {
                    \Log::warning('Sell quote ' . $number . ' email failed: ' . ($resp['message'] ?? 'unknown'));
                }
            } catch (\Throwable $e) {
                \Log::warning('Sell quote ' . $number . ' email failed: ' . $e->getMessage());
            }
        }
        return $out;
    }

    /** What the website shows for a saved quote (never the offer ladder). */
    protected function publicQuote($q)
    {
        $calc = json_decode((string) $q->calculation_json, true) ?: [];
        $store = collect($this->stores())->firstWhere('id', (int) $q->location_id);
        $credit = (float) $q->credit_total;
        return [
            'number' => self::number($q->id),
            'token' => $q->token,
            'name' => $q->name,
            'status' => $q->status,
            'tier' => $q->size_tier,
            'store' => $store['name'] ?? null,
            'location_id' => $q->location_id ? (int) $q->location_id : null,
            'store_chosen' => self::storeChosen($calc),
            'offers' => $this->offers($q, $calc),
            'item_count' => (int) $q->item_count,
            'cash' => $q->cash_total !== null ? (float) $q->cash_total : null,
            'credit' => $q->credit_total !== null ? $credit : null,
            'estimate_low_cash' => $q->estimate_low_cash !== null ? (float) $q->estimate_low_cash : null,
            'estimate_low_credit' => $q->estimate_low_cash !== null ? round((float) $q->estimate_low_cash * 1.5, 2) : null,
            'lines' => $calc['display'] ?? [],
            'in_store_count' => (int) ($calc['in_store_count'] ?? 0),
            'unchecked' => (int) ($calc['unchecked'] ?? 0),
            'quoted_at' => $q->quoted_at,
            'expires_at' => $q->expires_at,
            'expired' => $q->expires_at && now()->greaterThan($q->expires_at),
        ];
    }

    /** GET /sell-quote/{token}: reopen a saved quote from its link. */
    public function show($token)
    {
        $q = $this->findByToken($token);
        if (!$q || $q->status === 'started') {
            return $this->json(['error' => 'Quote not found.'], 404);
        }
        return $this->json($this->publicQuote($q));
    }

    /** Beta buy form demand levels for the chosen store (same as BFC demandHook). */
    protected function demandHook($locationId, array $allLines)
    {
        $incoming = [];
        foreach ($allLines as $al) {
            $ik = ($al['item_type'] ?? '') . '|' . ($al['genre'] ?? '');
            $incoming[$ik] = ($incoming[$ik] ?? 0) + max(0, (float) ($al['quantity'] ?? 0));
        }
        $service = app(BuyDemandService::class);
        $businessId = $this->businessId();
        return function ($line) use ($service, $businessId, $locationId, $incoming) {
            $ik = ($line['item_type'] ?? '') . '|' . ($line['genre'] ?? '');
            if (isset($incoming[$ik])) {
                $line['incoming_quantity'] = $incoming[$ik];
            }
            return $service->forLine($line, $businessId, $locationId);
        };
    }

    // ---------------------------------------------------------------- staff

    protected function staffAllowed()
    {
        return auth()->user() && auth()->user()->can('purchase.create');
    }

    /** GET /sell-quotes */
    public function index(Request $request)
    {
        if (!$this->staffAllowed()) abort(403);
        $business_id = (int) $request->session()->get('user.business_id');
        $q = trim((string) $request->input('q'));
        $query = DB::table('sell_quotes')->where('business_id', $business_id);
        if ($q !== '') {
            $digits = preg_replace('/\D+/', '', $q);
            $query->where(function ($w) use ($q, $digits) {
                $w->where('name', 'like', '%' . $q . '%')->orWhere('email', 'like', '%' . $q . '%');
                if ($digits !== '') {
                    $w->orWhere('id', self::idFromNumber($q));
                    if (strlen($digits) >= 4) {
                        $w->orWhereRaw("REPLACE(REPLACE(REPLACE(REPLACE(phone,'(',''),')',''),'-',''),' ','') like ?", ['%' . $digits . '%']);
                    }
                }
            });
        }
        if (!$request->input('all')) {
            $query->where('status', '!=', 'started');
        }
        $quotes = $query->orderByDesc('id')->paginate(50)->appends($request->only('q', 'all'));
        $stores = collect($this->stores())->pluck('name', 'id')->all();
        $startedCount = DB::table('sell_quotes')->where('business_id', $business_id)->where('status', 'started')->count();
        return view('sell_quotes.index', compact('quotes', 'q', 'stores', 'startedCount'));
    }

    /**
     * GET /sell-quotes/lookup?number=Q1003: the buy form's "Quote #" box. Staff
     * type the number the seller got from nivessa.com and see what was quoted.
     */
    public function lookup(Request $request)
    {
        if (!$this->staffAllowed()) {
            return response()->json(['error' => 'Not allowed.'], 403);
        }
        $business_id = (int) $request->session()->get('user.business_id');
        $id = self::idFromNumber($request->input('number'));
        $quote = $id ? DB::table('sell_quotes')->where('business_id', $business_id)->where('id', $id)->first() : null;
        if (!$quote || $quote->status === 'started') {
            return response()->json(['error' => 'No quote found with that number. Check it with the seller (it looks like Q1234).'], 404);
        }
        $calc = json_decode((string) $quote->calculation_json, true) ?: [];
        $stores = collect($this->stores())->pluck('name', 'id')->all();
        $labels = $this->calculator->getItemTypesForDropdown(true, true);
        $lines = [];
        foreach (($calc['lines'] ?? []) as $i => $l) {
            $d = $calc['display'][$i] ?? [];
            $lines[] = [
                'label' => $d['label'] ?? ($l['title'] ?? ($labels[$l['item_type']] ?? $l['item_type'])),
                'qty' => (float) ($l['quantity'] ?? 0),
                'grade' => $l['condition_grade'] ?? null,
                'cash' => isset($d['cash']) ? (float) $d['cash'] : null,
            ];
        }
        $expired = $quote->expires_at && now()->greaterThan($quote->expires_at);
        return response()->json([
            'id' => (int) $quote->id,
            'number' => self::number($quote->id),
            'name' => $quote->name,
            'phone' => $quote->phone,
            'email' => $quote->email,
            'store' => $stores[$quote->location_id] ?? null,
            'location_id' => $quote->location_id ? (int) $quote->location_id : null,
            'large' => $quote->size_tier === 'large',
            'cash' => $quote->cash_total !== null ? (float) $quote->cash_total : null,
            'estimate_low_cash' => $quote->estimate_low_cash !== null ? (float) $quote->estimate_low_cash : null,
            'credit' => $quote->credit_total !== null ? (float) $quote->credit_total : null,
            'item_count' => (int) $quote->item_count,
            'quoted_at' => $quote->quoted_at ? \Carbon\Carbon::parse($quote->quoted_at)->format('D n/j') : null,
            'expires_at' => $quote->expires_at ? \Carbon\Carbon::parse($quote->expires_at)->format('D n/j') : null,
            'expired' => (bool) $expired,
            'status' => $quote->status,
            'buy_offer_id' => $quote->buy_offer_id ? (int) $quote->buy_offer_id : null,
            'notes' => $quote->notes,
            'lines' => $lines,
            'view_url' => url('/sell-quotes/' . $quote->id),
            'start_buy_url' => url('/sell-quotes/' . $quote->id . '/start-buy'),
            'buy_url' => $quote->buy_offer_id ? route('buy-from-customer.edit', [$quote->buy_offer_id, 'beta' => 1]) : null,
        ]);
    }

    /** GET /sell-quotes/{id} */
    public function staffShow(Request $request, $id)
    {
        if (!$this->staffAllowed()) abort(403);
        $business_id = (int) $request->session()->get('user.business_id');
        $quote = DB::table('sell_quotes')->where('business_id', $business_id)->where('id', (int) $id)->first();
        if (!$quote) abort(404);
        $calc = json_decode((string) $quote->calculation_json, true) ?: [];
        $stores = collect($this->stores())->pluck('name', 'id')->all();
        $labels = $this->calculator->getItemTypesForDropdown(true, true);
        return view('sell_quotes.show', [
            'quote' => $quote,
            'number' => self::number($quote->id),
            'display' => $calc['display'] ?? [],
            'calcLines' => $calc['lines'] ?? [],
            'store' => $stores[$quote->location_id] ?? '—',
            'labels' => $labels,
        ]);
    }

    /**
     * POST /sell-quotes/{id}/start-buy: a buy-form draft with the quote's lines
     * and its demand levels pinned (Beta sidecar), so the cashier only checks
     * grades and counts. The draft re-runs the normal offer ladder.
     */
    public function startBuy(Request $request, $id)
    {
        if (!$this->staffAllowed()) abort(403);
        $business_id = (int) $request->session()->get('user.business_id');
        $quote = DB::table('sell_quotes')->where('business_id', $business_id)->where('id', (int) $id)->first();
        if (!$quote || $quote->status === 'started') abort(404);
        if ($quote->buy_offer_id && BuyCustomerOffer::where('business_id', $business_id)->where('id', $quote->buy_offer_id)->exists()) {
            return redirect()->route('buy-from-customer.edit', [$quote->buy_offer_id, 'beta' => 1]);
        }
        $calcLines = (json_decode((string) $quote->calculation_json, true) ?: [])['lines'] ?? [];
        // Re-run the calculator so the draft gets the full offer ladder (the
        // quote only kept the lines), pinned to the quoted demand levels.
        $lines = array_map(function ($l) {
            return [
                'item_type' => $l['item_type'],
                'title' => $l['title'] ?? null,
                'discogs_link' => $l['discogs_link'] ?? null,
                'condition_grade' => $l['condition_grade'] ?? null,
                'quantity' => $l['quantity'],
                'discogs_median_price' => $l['discogs_median_price'] ?? null,
                'standard_multiplier' => $l['standard_multiplier'] ?? null,
                'genre' => $l['demand_genre'] ?? '',
            ];
        }, $calcLines);
        $pinned = [];
        foreach ($calcLines as $l) {
            $pinned[(int) $l['line_order']] = [
                'multiplier' => (float) ($l['demand_multiplier'] ?? 1),
                'level' => $l['demand_level'] ?? 'normal',
                'reason' => $l['demand_reason'] ?? null,
            ];
        }
        $calculation = $this->calculator->calculate($lines, [], function ($line, $i) use ($pinned) {
            return $pinned[$i] ?? ['multiplier' => 1.0, 'level' => 'normal', 'reason' => null];
        });

        $parts = preg_split('/\s+/', trim($quote->name), 2);
        $offer = DB::transaction(function () use ($business_id, $quote, $calculation, $parts) {
            $offer = new BuyCustomerOffer();
            $offer->business_id = $business_id;
            $offer->location_id = $quote->location_id;
            $offer->created_by = request()->session()->get('user.id');
            $offer->seller_mode = 'phone';
            $offer->seller_name = $quote->name;
            $offer->seller_first_name = $parts[0] ?? null;
            $offer->seller_last_name = $parts[1] ?? null;
            $offer->seller_phone = $quote->phone;
            $offer->seller_email = $quote->email;
            $offer->status = 'draft';
            $offer->payout_type = 'cash';
            $offer->payment_method = 'cash_in_store';
            $offer->calculated_cash_total = $calculation['calculated_cash_total'];
            $offer->calculated_credit_total = $calculation['calculated_credit_total'];
            $offer->starting_offer_cash = $calculation['starting_offer_cash'];
            $offer->starting_offer_credit = $calculation['starting_offer_credit'];
            $offer->second_offer_cash = $calculation['second_offer_cash'];
            $offer->second_offer_credit = $calculation['second_offer_credit'];
            $offer->final_offer_cash = $calculation['final_offer_cash'];
            $offer->final_offer_credit = $calculation['final_offer_credit'];
            $offer->notes = trim('Website quote ' . self::number($quote->id) . ': quoted $' . number_format((float) $quote->cash_total, 2)
                . ' cash / $' . number_format((float) $quote->credit_total, 2) . ' credit'
                . ($quote->expires_at ? ', good through ' . \Carbon\Carbon::parse($quote->expires_at)->format('n/j') : '')
                . '. Check grades + counts.' . ($quote->notes ? "\nSeller notes: " . $quote->notes : ''));
            $offer->collection_summary_json = json_encode($calculation['collection_summary'] ?? []);
            $offer->calculation_snapshot = json_encode($calculation['lines']);
            $offer->save();
            foreach ($calculation['lines'] as $line) {
                foreach (array_keys($line) as $k) {
                    if (strpos($k, 'demand_') === 0) unset($line[$k]);
                }
                $offer->lines()->create($line);
            }
            DB::table('sell_quotes')->where('id', $quote->id)->update(['status' => 'in_buy', 'buy_offer_id' => $offer->id, 'updated_at' => now()]);
            return $offer;
        });
        try {
            BuyBetaOffers::put($business_id, (int) $offer->id, $calculation);
        } catch (\Throwable $e) {
            \Log::warning('Sell quote -> BFC sidecar failed', ['quote' => $quote->id, 'err' => $e->getMessage()]);
        }
        return redirect()->route('buy-from-customer.edit', [$offer->id, 'beta' => 1]);
    }
}
