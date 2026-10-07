<?php

namespace App\Utils;

use Illuminate\Support\Facades\Cache;

// Sidebar "most used first" ordering (Sarah 2026-10-07). Every full page load
// records which section the user opened (one entry per user per section per
// day, so one person refreshing all day doesn't outweigh the team). Once a few
// days of data exist, top-level menu items between End Shift and Admin Tools
// are sorted by how many distinct user-days hit them over the last 30 days.
// Stored as one small JSON file per day under storage/app/nav-usage/ (no
// migration). Home/End Shift stay pinned at the top, Admin Tools and other
// order>=40 items stay at the bottom.
class NavUsage
{
    const MIN_DAYS = 3;
    const WINDOW_DAYS = 30;

    private static function dir()
    {
        return storage_path('app/nav-usage');
    }

    public static function keyFor($path)
    {
        $segs = array_values(array_filter(explode('/', strtolower(trim((string) $path, '/'))), function ($s) {
            return $s !== '' && !ctype_digit($s);
        }));
        return implode('/', array_slice($segs, 0, 2));
    }

    public static function record($request)
    {
        try {
            $uid = auth()->id();
            if (!$uid || !$request->isMethod('get')) {
                return;
            }
            $key = self::keyFor($request->path());
            if ($key === '') {
                return;
            }
            $dir = self::dir();
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            $fh = fopen($dir . '/' . date('Y-m-d') . '.json', 'c+');
            if (!$fh) {
                return;
            }
            flock($fh, LOCK_EX);
            $data = json_decode(stream_get_contents($fh), true) ?: [];
            $users = $data[$key] ?? [];
            if (!in_array($uid, $users, true)) {
                $users[] = $uid;
                $data[$key] = $users;
                ftruncate($fh, 0);
                rewind($fh);
                fwrite($fh, json_encode($data));
            }
            flock($fh, LOCK_UN);
            fclose($fh);
        } catch (\Throwable $e) {
            // Tracking must never break a page.
        }
    }

    // [day => [key => [user ids]]] for the window, cached 10 minutes.
    private static function window()
    {
        return Cache::remember('nav-usage-window', 600, function () {
            $out = [];
            for ($i = 0; $i < self::WINDOW_DAYS; $i++) {
                $day = date('Y-m-d', strtotime("-$i days"));
                $file = self::dir() . '/' . $day . '.json';
                if (is_file($file)) {
                    $d = json_decode((string) @file_get_contents($file), true);
                    if (is_array($d)) {
                        $out[$day] = $d;
                    }
                }
            }
            return $out;
        });
    }

    private static function score(array $keys, array $window)
    {
        $seen = [];
        foreach ($window as $day => $hits) {
            foreach ($hits as $hk => $users) {
                foreach ($keys as $k) {
                    if ($hk === $k || strpos($hk, $k . '/') === 0) {
                        foreach ((array) $users as $u) {
                            $seen[$day . ':' . $u] = true;
                        }
                        break;
                    }
                }
            }
        }
        return count($seen);
    }

    public static function applyOrder($menu)
    {
        try {
            if (!$menu) {
                return;
            }
            $window = self::window();
            if (count($window) < self::MIN_DAYS) {
                return;
            }
            $movable = [];
            foreach ($menu->getItems() as $item) {
                $o = (int) $item->order;
                if ($o <= 6 || $o >= 40) {
                    continue;
                }
                $urls = [$item->getUrl()];
                foreach ((array) $item->getChilds() as $c) {
                    $urls[] = $c->getUrl();
                }
                $keys = [];
                foreach ($urls as $u) {
                    $k = self::keyFor(parse_url($u, PHP_URL_PATH));
                    if ($k !== '') {
                        $keys[$k] = true;
                    }
                }
                $movable[] = [$item, self::score(array_keys($keys), $window), $o];
            }
            usort($movable, function ($a, $b) {
                return $b[1] <=> $a[1] ?: $a[2] <=> $b[2];
            });
            foreach ($movable as $i => $m) {
                $m[0]->order(7 + $i * 0.01);
            }
        } catch (\Throwable $e) {
            // Fall back to the static order.
        }
    }

    // Raw scores for checking the ranking.
    public static function report($menu)
    {
        $window = self::window();
        $rows = [];
        foreach ($menu ? $menu->getItems() : [] as $item) {
            $urls = [$item->getUrl()];
            foreach ((array) $item->getChilds() as $c) {
                $urls[] = $c->getUrl();
            }
            $keys = [];
            foreach ($urls as $u) {
                $k = self::keyFor(parse_url($u, PHP_URL_PATH));
                if ($k !== '') {
                    $keys[$k] = true;
                }
            }
            $rows[] = ['title' => strip_tags((string) $item->title), 'order' => $item->order, 'score' => self::score(array_keys($keys), $window)];
        }
        return ['days' => array_keys($window), 'items' => $rows];
    }
}
