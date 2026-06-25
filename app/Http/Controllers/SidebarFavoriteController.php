<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

// Per-employee "starred" sidebar links. Each user can pin any left-menu link
// (or a whole page, via the "Pin to my sidebar" button on a page) to a personal
// Favorites group at the top of the sidebar. Stored as a per-user JSON sidecar
// (no migration) under storage/app/sidebar-favorites/ — one file per user, so
// two people pinning at once never clobber each other. Nobody else sees your
// stars; this is purely a personal shortcut list, on your own account.
class SidebarFavoriteController extends Controller
{
    private static function path($business_id, $user_id)
    {
        return storage_path('app/sidebar-favorites/' . (int) $business_id . '-' . (int) $user_id . '.json');
    }

    // Favorites for one user: array of ['url' => ..., 'label' => ...]. Public so
    // the sidebar blade + page "Pin" buttons can render their state server-side.
    public static function forUser($business_id, $user_id)
    {
        $path = self::path($business_id, $user_id);
        if (is_file($path)) {
            $decoded = json_decode((string) file_get_contents($path), true);
            if (is_array($decoded)) {
                return array_values(array_filter($decoded, function ($r) {
                    return is_array($r) && !empty($r['url']);
                }));
            }
        }
        return [];
    }

    // Is this exact url already pinned by the user? Lets a page render its Pin
    // button in the right state on load.
    public static function isPinned($business_id, $user_id, $url)
    {
        foreach (self::forUser($business_id, $user_id) as $row) {
            if (isset($row['url']) && $row['url'] === $url) {
                return true;
            }
        }
        return false;
    }

    // Star / unstar a single link. Toggles based on url and writes the user's
    // own file. Returns the new starred state + the full updated list so the JS
    // can re-render the Favorites group without a page reload.
    public function toggle(Request $request)
    {
        $business_id = $request->session()->get('user.business_id');
        $user_id     = $request->session()->get('user.id');

        if (!$business_id || !$user_id) {
            return response()->json(['ok' => false], 403);
        }

        $url   = trim((string) $request->input('url'));
        $label = trim((string) $request->input('label'));
        if ($label === '') {
            $label = $url;
        }
        // Don't trust the client blindly — keep urls + labels to sane lengths.
        if ($url === '' || strlen($url) > 500 || strlen($label) > 120) {
            return response()->json(['ok' => false], 422);
        }

        $list  = self::forUser($business_id, $user_id);
        $found = false;
        $next  = [];
        foreach ($list as $row) {
            if (isset($row['url']) && $row['url'] === $url) {
                $found = true; // present -> dropping it = unstar
                continue;
            }
            $next[] = ['url' => $row['url'], 'label' => $row['label'] ?? $row['url']];
        }
        if (!$found) {
            $next[] = ['url' => $url, 'label' => $label];
        }
        // Keep Favorites a shortlist, not a second full menu.
        if (count($next) > 20) {
            $next = array_slice($next, -20);
        }

        $dir = storage_path('app/sidebar-favorites');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents(
            self::path($business_id, $user_id),
            json_encode(array_values($next), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );

        return response()->json([
            'ok'        => true,
            'starred'   => !$found,
            'favorites' => array_values($next),
        ]);
    }
}
