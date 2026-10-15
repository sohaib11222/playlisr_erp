<?php

namespace App\Http\Controllers\WebsiteAdmin;

use App\Http\Controllers\Controller;
use App\Services\WebsiteBridge;
use Illuminate\Http\Request;

/**
 * Website email subscribers, moved from nivessa.com/admin/email-subscribers
 * and /admin/subscriber-list (Sarah, 2026-10-15). Brevo lists pulled into
 * the ERP itself stay on /marketing/email-lists; this is the website's own
 * subscriber collection (signup form, events, Brevo sync).
 */
class SubscribersController extends Controller
{
    use WaContentHelpers;

    public function subscriberList(Request $request)
    {
        $this->guardStaff();
        $page = max(1, (int) $request->input('page', 1));
        $search = trim((string) $request->input('search', ''));
        $limit = 10;
        $resp = WebsiteBridge::admin('GET', '/customer/subscribers?page=' . $page . '&limit=' . $limit . '&search=' . rawurlencode($search));
        $total = (int) ($this->pick($resp, 'total') ?? ($this->pick($resp, 'pagination', [])['total'] ?? 0));
        return view('website_admin.content.subscriber_list', [
            'bridgeError' => empty($resp['success']) ? ($resp['message'] ?? 'Could not load subscribers.') : null,
            'rows' => $this->listFrom($resp),
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'totalPages' => max(1, (int) ceil($total / $limit)),
            'search' => $search,
        ]);
    }

    public function index(Request $request)
    {
        $this->guardStaff();
        $page = max(1, (int) $request->input('page', 1));
        $limit = (int) $request->input('limit', 20);
        if (!in_array($limit, [10, 20, 50, 100], true)) {
            $limit = 20;
        }
        $status = (string) $request->input('status', '');
        if (!in_array($status, ['', 'active', 'inactive'], true)) {
            $status = '';
        }
        $search = trim((string) $request->input('search', ''));
        $qs = 'page=' . $page . '&limit=' . $limit . ($status !== '' ? '&status=' . $status : '') . ($search !== '' ? '&search=' . rawurlencode($search) : '');
        $resp = WebsiteBridge::admin('GET', '/subscribers?' . $qs);
        $stats = WebsiteBridge::admin('GET', '/subscribers/stats');
        $total = (int) ($this->pick($resp, 'pagination', [])['total'] ?? 0);
        $st = $this->pick($stats, 'data', []) ?: [];

        return view('website_admin.content.email_subscribers', [
            'bridgeError' => empty($resp['success']) ? ($resp['message'] ?? 'Could not load subscribers.') : null,
            'rows' => $this->listFrom($resp),
            'stats' => ['total' => $st['total'] ?? 0, 'active' => $st['active'] ?? 0, 'inactive' => $st['inactive'] ?? 0],
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'totalPages' => max(1, (int) ceil($total / $limit)),
            'status' => $status,
            'search' => $search,
            'importResult' => session('wc_import_result'),
        ]);
    }

    public function sync()
    {
        $this->guardStaff();
        $resp = WebsiteBridge::admin('POST', '/subscribers/sync-brevo', [], 120);
        if (empty($resp['success'])) {
            return back()->with('error', $resp['message'] ?? 'Sync failed.');
        }
        $d = $resp['data'] ?? [];
        return back()->with('status', 'Sync complete. Added ' . ($d['added'] ?? 0) . ', activated ' . ($d['activated'] ?? 0) . ', deactivated ' . ($d['deactivated'] ?? 0) . '.');
    }

    public function import(Request $request)
    {
        $this->guardStaff();
        $file = $request->file('file');
        if (!$file || !$file->isValid() || strtolower($file->getClientOriginalExtension()) !== 'csv') {
            return back()->with('error', 'Pick a CSV file.');
        }
        if ($file->getSize() > 50 * 1024 * 1024) {
            return back()->with('error', 'That file is too large. The limit is 50 MB.');
        }
        $resp = WebsiteBridge::adminUpload('POST', '/subscribers/import', [], ['file' => $file], 180);
        if (empty($resp['success'])) {
            return back()->with('error', $resp['message'] ?? 'Import failed.');
        }
        $d = $resp['data'] ?? [];
        return back()
            ->with('status', 'Imported ' . ($d['imported'] ?? 0) . ' subscribers.')
            ->with('wc_import_result', $d);
    }

    /**
     * Brevo-format CSV. The website's export route answers with a file,
     * which the JSON bridge can't carry, so the same CSV is built here from
     * the subscriber list.
     */
    public function export(Request $request)
    {
        $this->guardStaff();
        $all = $request->input('all') === '1';
        $resp = WebsiteBridge::admin('GET', '/subscribers?page=1&limit=200000' . ($all ? '' : '&status=active'), null, 120);
        if (empty($resp['success'])) {
            return back()->with('error', $resp['message'] ?? 'Export failed.');
        }
        $rows = $this->listFrom($resp);
        $q = function ($v) {
            return '"' . str_replace('"', '""', (string) $v) . '"';
        };
        $lines = ['EMAIL,FIRSTNAME,SMS'];
        foreach ($rows as $s) {
            $lines[] = $q($s['email'] ?? '') . ',' . $q($s['name'] ?? '') . ',""';
        }
        $csv = "\xEF\xBB\xBF" . implode("\r\n", $lines);
        $name = 'subscribers-brevo-export-' . now()->setTimezone('America/Los_Angeles')->format('Y-m-d') . '.csv';
        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $name . '"',
        ]);
    }

    public function preview(Request $request)
    {
        $this->guardStaff();
        $resp = WebsiteBridge::admin('POST', '/email/preview', [
            'subject' => (string) $request->input('subject', ''),
            'htmlContent' => (string) $request->input('htmlContent', ''),
        ]);
        return $this->jsonFrom($resp);
    }

    /** Sends to the website subscriber list (website's /email/send). */
    public function send(Request $request)
    {
        $this->guardStaff();
        $subject = trim((string) $request->input('subject', ''));
        $html = trim((string) $request->input('htmlContent', ''));
        if ($subject === '' || $html === '') {
            return back()->withInput()->with('error', 'Enter a subject and the email content.');
        }
        $resp = WebsiteBridge::admin('POST', '/email/send', ['subject' => $subject, 'htmlContent' => $html, 'subscriberIds' => null], 180);
        if (empty($resp['success'])) {
            return back()->withInput()->with('error', $resp['message'] ?? 'Failed to send the email.');
        }
        return back()->with('status', 'Email sent to ' . ($resp['data']['recipientCount'] ?? 0) . ' subscribers.');
    }
}
