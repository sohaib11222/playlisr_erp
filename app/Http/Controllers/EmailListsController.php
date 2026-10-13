<?php

namespace App\Http\Controllers;

use App\Services\InventoryCheckService;
use Illuminate\Http\Request;

/**
 * Email Lists (Sarah 10/13): copies every Brevo contact and the lists they
 * belong to into the ERP (email_subscribers), so the ERP keeps the full list
 * and Brevo can be trimmed down to a cheaper plan. Read-only on Brevo: it
 * never deletes or changes anything there.
 */
class EmailListsController extends Controller
{
    protected function guard(): int
    {
        if (!auth()->user()->can('customer.view')) abort(403);
        return (int) request()->session()->get('user.business_id');
    }

    protected function apiKey(int $biz): string
    {
        $c = app(InventoryCheckService::class)->loadSupplierCredentials($biz, 'brevo');
        return trim((string) ($c['api_key'] ?? ''));
    }

    public function index(Request $request)
    {
        $biz = $this->guard();
        $hasTable = \Schema::hasTable('email_subscribers');
        $total = 0; $unsub = 0; $lists = []; $lastPull = null;
        if ($hasTable) {
            $q = \DB::table('email_subscribers')->where('business_id', $biz);
            $total = (clone $q)->count();
            $unsub = (clone $q)->where('unsubscribed', 1)->count();
            $lastPull = (clone $q)->max('updated_at');
            foreach ((clone $q)->whereNotNull('lists')->pluck('lists') as $l) {
                foreach (array_filter(explode('|', $l)) as $name) $lists[$name] = ($lists[$name] ?? 0) + 1;
            }
            arsort($lists);
        }
        return view('marketing.email_lists', [
            'hasTable' => $hasTable,
            'hasKey' => $this->apiKey($biz) !== '',
            'total' => $total,
            'unsub' => $unsub,
            'lists' => $lists,
            'lastPull' => $lastPull,
        ]);
    }

    public function saveKey(Request $request)
    {
        $biz = $this->guard();
        $key = trim((string) $request->input('api_key'));
        if ($key !== '') app(InventoryCheckService::class)->saveSupplierCredentials($biz, 'brevo', ['api_key' => $key]);
        return redirect()->action('EmailListsController@index')->with('status', ['success' => 1, 'msg' => 'Brevo key saved.']);
    }

    protected function brevo(string $key, string $path): array
    {
        $ch = curl_init('https://api.brevo.com/v3' . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => ['accept: application/json', 'api-key: ' . $key]]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $json = json_decode((string) $body, true);
        if ($code >= 300 || !is_array($json)) {
            throw new \RuntimeException('Brevo said ' . $code . ': ' . substr((string) ($json['message'] ?? $body), 0, 200));
        }
        return $json;
    }

    /** Pull every Brevo contact (1,000 per page) into email_subscribers. */
    public function pull(Request $request)
    {
        $biz = $this->guard();
        @set_time_limit(600);
        $key = $this->apiKey($biz);
        if ($key === '') {
            return redirect()->action('EmailListsController@index')->with('status', ['success' => 0, 'msg' => 'Save your Brevo API key first.']);
        }
        try {
            $names = [];
            for ($off = 0; $off < 5000; $off += 50) {
                $page = $this->brevo($key, '/contacts/lists?limit=50&offset=' . $off);
                foreach ((array) ($page['lists'] ?? []) as $l) $names[(int) $l['id']] = (string) $l['name'];
                if (count((array) ($page['lists'] ?? [])) < 50) break;
            }
            $start = max(0, (int) $request->input('offset', 0));
            $saved = (int) $request->input('saved', 0);
            $now = now();
            $done = false;
            // 3 pages per request so a web request never runs long; the
            // redirect below picks up where this one stopped.
            for ($off = $start; $off < $start + 3000; $off += 1000) {
                $page = $this->brevo($key, '/contacts?limit=1000&offset=' . $off . '&sort=asc');
                $contacts = (array) ($page['contacts'] ?? []);
                $rows = [];
                foreach ($contacts as $c) {
                    $email = strtolower(trim((string) ($c['email'] ?? '')));
                    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
                    $a = (array) ($c['attributes'] ?? []);
                    $ln = [];
                    foreach ((array) ($c['listIds'] ?? []) as $id) if (isset($names[(int) $id])) $ln[] = str_replace('|', '/', $names[(int) $id]);
                    $rows[] = ['business_id' => $biz, 'email' => substr($email, 0, 191),
                        'first_name' => isset($a['FIRSTNAME']) ? mb_substr((string) $a['FIRSTNAME'], 0, 120) : null,
                        'last_name' => isset($a['LASTNAME']) ? mb_substr((string) $a['LASTNAME'], 0, 120) : null,
                        'phone' => isset($a['SMS']) ? mb_substr((string) $a['SMS'], 0, 60) : null,
                        'lists' => $ln ? '|' . implode('|', $ln) . '|' : null,
                        'unsubscribed' => !empty($c['emailBlacklisted']) ? 1 : 0,
                        'source' => 'brevo', 'brevo_id' => (int) ($c['id'] ?? 0) ?: null,
                        'brevo_created_at' => !empty($c['createdAt']) ? date('Y-m-d H:i:s', strtotime($c['createdAt'])) : null,
                        'created_at' => $now, 'updated_at' => $now];
                }
                // Laravel 5.8 has no upsert(): INSERT ... ON DUPLICATE KEY UPDATE.
                $cols = ['business_id', 'email', 'first_name', 'last_name', 'phone', 'lists', 'unsubscribed', 'source', 'brevo_id', 'brevo_created_at', 'created_at', 'updated_at'];
                $upd = ['first_name', 'last_name', 'phone', 'lists', 'unsubscribed', 'brevo_id', 'brevo_created_at', 'updated_at'];
                foreach (array_chunk($rows, 500) as $chunk) {
                    $ph = implode(',', array_fill(0, count($chunk), '(' . implode(',', array_fill(0, count($cols), '?')) . ')'));
                    $bind = [];
                    foreach ($chunk as $r) foreach ($cols as $c) $bind[] = $r[$c];
                    \DB::statement('INSERT INTO email_subscribers (' . implode(',', $cols) . ') VALUES ' . $ph
                        . ' ON DUPLICATE KEY UPDATE ' . implode(',', array_map(function ($c) { return "$c = VALUES($c)"; }, $upd)), $bind);
                }
                $saved += count($rows);
                if (count($contacts) < 1000) { $done = true; break; }
            }
            if (!$done) {
                return redirect()->action('EmailListsController@pull', ['offset' => $start + 3000, 'saved' => $saved]);
            }
        } catch (\Throwable $e) {
            return redirect()->action('EmailListsController@index')->with('status', ['success' => 0, 'msg' => 'Pull stopped: ' . $e->getMessage()]);
        }
        return redirect()->action('EmailListsController@index')->with('status', ['success' => 1, 'msg' => number_format($saved) . ' Brevo contacts saved in the ERP.']);
    }

    /** CSV of one list (or everyone), ready to re-import into Brevo. */
    public function csv(Request $request)
    {
        $biz = $this->guard();
        $list = (string) $request->input('list', '');
        $q = \DB::table('email_subscribers')->where('business_id', $biz)->orderBy('email');
        if ($list !== '') $q->where('lists', 'like', '%|' . str_replace(['%', '_'], ['\%', '\_'], $list) . '|%');
        $name = 'email-list-' . ($list !== '' ? preg_replace('/[^a-z0-9]+/i', '-', $list) : 'all') . '-' . now()->format('Y-m-d') . '.csv';
        return response()->streamDownload(function () use ($q) {
            $f = fopen('php://output', 'w');
            fputcsv($f, ['EMAIL', 'FIRSTNAME', 'LASTNAME', 'SMS', 'LISTS', 'UNSUBSCRIBED']);
            $q->chunk(2000, function ($rows) use ($f) {
                foreach ($rows as $r) fputcsv($f, [$r->email, $r->first_name, $r->last_name, $r->phone, trim(str_replace('|', '; ', (string) $r->lists), '; '), $r->unsubscribed ? 'yes' : '']);
            });
            fclose($f);
        }, $name);
    }
}
