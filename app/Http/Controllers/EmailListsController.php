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

    protected function brevoSend(string $key, string $method, string $path, array $body): array
    {
        $ch = curl_init('https://api.brevo.com/v3' . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_HTTPHEADER => ['accept: application/json', 'content-type: application/json', 'api-key: ' . $key]]);
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $json = json_decode((string) $res, true);
        if ($code >= 300) throw new \RuntimeException('Brevo said ' . $code . ': ' . substr((string) ($json['message'] ?? $res), 0, 200));
        return is_array($json) ? $json : [];
    }

    /**
     * Next batch for the free Brevo plan (300 emails/day): the newest Clover
     * in-store customers not sent to yet go into a new Brevo list.
     * Sidecar storage/app/brevo-batches-{biz}.json remembers who was used.
     */
    public function pushBatch(Request $request)
    {
        $biz = $this->guard();
        $key = $this->apiKey($biz);
        if ($key === '') return response()->json(['success' => false, 'msg' => 'No Brevo key saved']);
        $count = max(1, min(300, (int) $request->input('count', 299)));
        $path = storage_path('app/brevo-batches-' . $biz . '.json');
        $state = is_file($path) ? (json_decode((string) file_get_contents($path), true) ?: []) : [];
        $used = array_flip((array) ($state['used'] ?? []));
        try {
            // Everyone already in Brevo (e.g. LA Regulars #17) counts as used.
            for ($off = 0; $off < 20000; $off += 1000) {
                $page = $this->brevo($key, '/contacts?limit=1000&offset=' . $off);
                foreach ((array) ($page['contacts'] ?? []) as $c) $used[strtolower((string) ($c['email'] ?? ''))] = true;
                if (count((array) ($page['contacts'] ?? [])) < 1000) break;
            }
            // Anyone who ever unsubscribed in Brevo (saved here before the cleanup) is never picked.
            foreach (\DB::table('email_subscribers')->where('business_id', $biz)->where('unsubscribed', 1)->pluck('email') as $u) $used[strtolower($u)] = true;
            $staff = array_flip(array_filter(array_map(function ($e) { return strtolower(trim((string) $e)); },
                \DB::table('users')->where('business_id', $biz)->pluck('email')->toArray())));
            $pick = [];
            $rows = \DB::table('contacts')->where('business_id', $biz)->where('is_default', 0)->whereNotNull('clover_customer_id')
                ->whereNotNull('email')->where('email', 'like', '%@%.%')
                ->orderByRaw('COALESCE(last_purchase_date, clover_synced_at, created_at) DESC')
                ->select('email', 'zip_code')->get();
            foreach ($rows as $r) {
                $e = strtolower(trim($r->email));
                if (isset($used[$e]) || isset($staff[$e]) || isset($pick[$e]) || !filter_var($e, FILTER_VALIDATE_EMAIL)) continue;
                if (preg_match('/nivessa|hedvat|^test@|example\.|fahrul|zakscholefield|luis3pt/', $e) || !preg_match('/\.(com|net|org|edu|us|io|me|co)$/', $e)) continue;
                $zip = substr(preg_replace('/\D+/', '', (string) $r->zip_code), 0, 5);
                if ($zip !== '' && !preg_match('/^(90[0-9]|91[0-8]|935)/', $zip)) continue;
                $pick[$e] = true;
                if (count($pick) >= $count) break;
            }
            if (!$pick) return response()->json(['success' => false, 'msg' => 'No more customers left to send to']);
            $n = count((array) ($state['batches'] ?? [])) + 2;
            $list = $this->brevoSend($key, 'POST', '/contacts/lists', ['name' => 'In-store customers batch ' . $n . ' (' . now()->format('M j') . ')', 'folderId' => 1]);
            $listId = (int) ($list['id'] ?? 0);
            $this->brevoSend($key, 'POST', '/contacts/import', ['listIds' => [$listId], 'updateExistingContacts' => false,
                'jsonBody' => array_map(function ($e) { return ['email' => $e]; }, array_keys($pick))]);
            $state['used'] = array_values(array_unique(array_merge((array) ($state['used'] ?? []), array_keys($pick))));
            $state['batches'][] = ['list_id' => $listId, 'count' => count($pick), 'at' => now()->toDateTimeString()];
            file_put_contents($path, json_encode($state));
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'msg' => $e->getMessage()]);
        }
        return response()->json(['success' => true, 'list_id' => $listId, 'count' => count($pick), 'batch' => $n]);
    }

    /**
     * Safety check before a send: every batch list in Brevo is checked against
     * the unsubscribes saved in the ERP and against the other batch lists.
     * Unsubscribed people are blocklisted in Brevo; anyone on two batch
     * lists is removed from the later one. ?lists=17,18,19
     */
    public function checkBatches(Request $request)
    {
        $biz = $this->guard();
        $key = $this->apiKey($biz);
        $unsub = array_flip(array_map('strtolower', \DB::table('email_subscribers')->where('business_id', $biz)->where('unsubscribed', 1)->pluck('email')->toArray()));
        $ids = array_filter(array_map('intval', explode(',', (string) $request->input('lists'))));
        $seen = []; $report = [];
        try {
            foreach ($ids as $id) {
                $emails = [];
                for ($off = 0; $off < 5000; $off += 500) {
                    $page = $this->brevo($key, '/contacts/lists/' . $id . '/contacts?limit=500&offset=' . $off);
                    foreach ((array) ($page['contacts'] ?? []) as $c) if (empty($c['emailBlacklisted'])) $emails[] = strtolower((string) $c['email']);
                    if (count((array) ($page['contacts'] ?? [])) < 500) break;
                }
                $dupes = []; $bad = [];
                foreach ($emails as $e) {
                    if (isset($unsub[$e])) $bad[] = $e;
                    elseif (isset($seen[$e])) $dupes[] = $e;
                    else $seen[$e] = $id;
                }
                foreach ($bad as $e) $this->brevoSend($key, 'PUT', '/contacts/' . rawurlencode($e), ['emailBlacklisted' => true]);
                if ($dupes) $this->brevoSend($key, 'POST', '/contacts/lists/' . $id . '/contacts/remove', ['emails' => $dupes]);
                $report[] = ['list' => $id, 'sendable' => count($emails) - count($bad) - count($dupes), 'unsubscribed_blocked' => count($bad), 'duplicates_removed' => count($dupes)];
            }
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'msg' => $e->getMessage(), 'report' => $report]);
        }
        return response()->json(['success' => true, 'report' => $report]);
    }
}
