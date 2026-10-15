<?php

namespace App\Http\Controllers\WebsiteAdmin;

use App\Http\Controllers\Controller;
use App\Services\WebsiteBridge;
use Illuminate\Http\Request;

/**
 * Weekly newsletter builder, moved from nivessa.com/admin/newsletter
 * (Sarah, 2026-10-15). Drafts, settings and the Brevo send all run on the
 * website's own /admin/newsletter handlers through the admin proxy.
 */
class NewsletterController extends Controller
{
    use WaContentHelpers;

    public function index()
    {
        $this->guardStaff();
        $list = WebsiteBridge::admin('GET', '/admin/newsletter');
        $settings = WebsiteBridge::admin('GET', '/admin/newsletter/settings');
        $lists = WebsiteBridge::admin('GET', '/admin/newsletter/brevo/lists');

        return view('website_admin.content.newsletter_index', [
            'bridgeError' => empty($list['success']) ? ($list['message'] ?? 'Could not load newsletters.') : null,
            'items' => $this->pick($list, 'items', []) ?: [],
            'settings' => $this->pick($settings, 'settings', null),
            'settingsError' => empty($settings['success']) ? ($settings['message'] ?? 'Could not load settings.') : null,
            'brevoLists' => $this->pick($lists, 'lists', []) ?: [],
            'brevoError' => empty($lists['success']) ? ($lists['message'] ?? 'Could not load Brevo lists.') : null,
        ]);
    }

    public function saveSettings(Request $request)
    {
        $this->guardStaff();
        $body = [
            'brevoListIds' => array_map('intval', (array) $request->input('brevoListIds', [])),
            'senderName' => (string) $request->input('senderName', ''),
            'senderEmail' => (string) $request->input('senderEmail', ''),
            'replyToEmail' => (string) $request->input('replyToEmail', ''),
            'productsPerStore' => (int) $request->input('productsPerStore', 6),
            'eventsLookaheadDays' => (int) $request->input('eventsLookaheadDays', 21),
            'sealedReleasesExcludeGenres' => $this->csvList($request->input('sealedReleasesExcludeGenres', '')),
            'sealedReleasesExcludeArtists' => $this->csvList($request->input('sealedReleasesExcludeArtists', '')),
            'sealedReleasesRequireSales' => $request->has('sealedReleasesRequireSales'),
            'featuredSku' => trim((string) $request->input('featuredSku', '')),
            'featuredBlurb' => (string) $request->input('featuredBlurb', ''),
            'newReleasesSkus' => trim((string) $request->input('newReleasesSkus', '')),
            'bargainBinEnabled' => $request->has('bargainBinEnabled'),
            'bargainBinCount' => (int) $request->input('bargainBinCount', 6),
            'introTemplate' => (string) $request->input('introTemplate', ''),
            'notifyEmail' => (string) $request->input('notifyEmail', ''),
            'autoBuildEnabled' => $request->has('autoBuildEnabled'),
        ];
        $resp = WebsiteBridge::admin('PUT', '/admin/newsletter/settings', $body);
        return $this->bridgeBack($resp, 'Newsletter settings saved.', 'Save failed.');
    }

    public function build()
    {
        $this->guardStaff();
        // Building pulls products and events, so give it longer than a read.
        $resp = WebsiteBridge::admin('POST', '/admin/newsletter/build-now', [], 90);
        if (empty($resp['success'])) {
            return back()->with('error', $resp['message'] ?? 'Build failed.');
        }
        $id = $this->pick($resp, 'item', [])['_id'] ?? null;
        if ($id) {
            return redirect()->route('wa.content.newsletterShow', $id)->with('status', 'Draft built.');
        }
        return back()->with('status', 'Draft built.');
    }

    public function destroy($id)
    {
        $this->guardStaff();
        $resp = WebsiteBridge::admin('DELETE', '/admin/newsletter/' . rawurlencode($id));
        return $this->bridgeBack($resp, 'Draft deleted.', 'Delete failed.');
    }

    public function show($id)
    {
        $this->guardStaff();
        $resp = WebsiteBridge::admin('GET', '/admin/newsletter/' . rawurlencode($id));
        $settings = WebsiteBridge::admin('GET', '/admin/newsletter/settings');
        $item = $this->pick($resp, 'item', null);
        if (empty($resp['success']) || !$item) {
            return redirect()->route('wa.content.newsletter')->with('error', $resp['message'] ?? 'Newsletter not found.');
        }
        $s = $this->pick($settings, 'settings', []) ?: [];
        return view('website_admin.content.newsletter_show', [
            'item' => $item,
            'notifyEmail' => $s['notifyEmail'] ?? '',
            'recipientCount' => count($s['brevoListIds'] ?? []),
        ]);
    }

    /** JSON save from the draft editor; returns the re-rendered draft. */
    public function update(Request $request, $id)
    {
        $this->guardStaff();
        $body = [];
        foreach (['subject', 'intro'] as $k) {
            if ($request->has($k)) {
                $body[$k] = (string) $request->input($k);
            }
        }
        foreach (['sealedNewReleases', 'hollywoodPicks', 'picoPicks', 'upcomingEvents'] as $k) {
            if ($request->has($k)) {
                $body[$k] = array_values((array) $request->input($k, []));
            }
        }
        $resp = WebsiteBridge::admin('PUT', '/admin/newsletter/' . rawurlencode($id), $body, 60);
        return $this->jsonFrom($resp);
    }

    public function test(Request $request, $id)
    {
        $this->guardStaff();
        $email = trim((string) $request->input('email', ''));
        if ($email === '') {
            return response()->json(['success' => false, 'message' => 'Enter a test email address.'], 422);
        }
        $resp = WebsiteBridge::admin('POST', '/admin/newsletter/' . rawurlencode($id) . '/test', ['email' => $email], 60);
        return $this->jsonFrom($resp);
    }

    /** Approve and send to the Brevo list(s). Same handler as the website's button. */
    public function send($id)
    {
        $this->guardStaff();
        $resp = WebsiteBridge::admin('POST', '/admin/newsletter/' . rawurlencode($id) . '/send', [], 90);
        return $this->jsonFrom($resp);
    }

    public function skip($id)
    {
        $this->guardStaff();
        $resp = WebsiteBridge::admin('POST', '/admin/newsletter/' . rawurlencode($id) . '/skip');
        return $this->jsonFrom($resp);
    }

    public function candidates(Request $request)
    {
        $this->guardStaff();
        $store = (string) $request->input('store', 'Hollywood');
        $exclude = preg_replace('/[^A-Za-z0-9_,-]/', '', (string) $request->input('exclude', ''));
        $path = '/admin/newsletter/candidate-pool?store=' . rawurlencode($store) . ($exclude !== '' ? '&exclude=' . $exclude : '');
        $resp = WebsiteBridge::admin('GET', $path, null, 45);
        return $this->jsonFrom($resp);
    }
}
