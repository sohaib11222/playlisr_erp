<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BuyDemandService;
use App\Services\BuyMarginSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Public "what pays more / less right now" list per buying store, for the
 * nivessa.com /buy-sell-trade pages (Sarah 2026-10-25). Same per-genre levels
 * the Beta buy form uses (BuyDemandService::levelsForStore): hot / warm pay
 * more, overstocked (cool / cold) pays less. Only names and percentages go
 * out, never sold / on-hand counts.
 */
class NivessaBuyPremiumsController extends Controller
{
    // Formats the Beta form pays bonuses on; everything else is cuts-only.
    const MUSIC_FORMAT = '/vinyl|\bcds?\b|compact disc|cassette|tape|\b45s?\b|7"/i';

    public function publicFeed()
    {
        $data = Cache::remember('buypremiums1', 3600, function () {
            $businessId = (int) (config('services.nivessa_web.business_id') ?: 1);
            $s = BuyMarginSettings::get($businessId);
            $pct = [
                'hot' => (int) $s['bonus_hot'],
                'warm' => (int) $s['bonus_warm'],
                'cool' => -10,
                'cold' => -20,
            ];
            $cats = DB::table('categories')->where('business_id', $businessId)->whereNull('deleted_at')
                ->pluck('name', 'id')->all();
            $parents = DB::table('categories')->where('business_id', $businessId)->whereNull('deleted_at')
                ->pluck('parent_id', 'id')->all();
            $stores = DB::table('business_locations')->where('business_id', $businessId)
                ->where(function ($q) {
                    $q->where('name', 'like', '%pico%')->orWhere('name', 'like', '%hollywood%');
                })->select('id', 'name')->get();

            $svc = new BuyDemandService();
            $out = [];
            foreach ($stores as $store) {
                $key = stripos($store->name, 'pico') !== false ? 'pico' : 'hollywood';
                $more = [];
                $less = [];
                foreach ($svc->levelsForStore($businessId, $store->id) as $subId => $row) {
                    $level = $row['level'];
                    if (!isset($pct[$level]) || !isset($cats[$subId])) {
                        continue;
                    }
                    $format = (string) ($cats[(int) ($parents[$subId] ?? 0)] ?? '');
                    $genre = trim((string) $cats[$subId]);
                    if ($format === '' || $genre === '' || preg_match('/^(other|misc|general|n\/?a)\b/i', $genre)) {
                        continue;
                    }
                    $item = ['format' => $format, 'genre' => $genre, 'level' => $level, 'pct' => $pct[$level]];
                    if ($pct[$level] > 0) {
                        if (preg_match(self::MUSIC_FORMAT, $format)) {
                            $more[] = $item;
                        }
                    } else {
                        $less[] = $item;
                    }
                }
                $sort = function ($a, $b) {
                    return abs($b['pct']) <=> abs($a['pct']) ?: strcasecmp($a['format'] . $a['genre'], $b['format'] . $b['genre']);
                };
                usort($more, $sort);
                usort($less, $sort);
                $out[$key] = [
                    'name' => $key === 'pico' ? 'Pico' : 'Hollywood',
                    'more' => $more,
                    'less' => $less,
                ];
            }
            return ['updated' => now()->toDateTimeString(), 'stores' => $out];
        });

        return response()->json($data)
            ->header('Access-Control-Allow-Origin', '*')
            ->header('Cache-Control', 'public, max-age=900');
    }
}
