<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Automatic distributor invoice intake (distributor-invoices:pull, every 3
 * hours, 7am to 7pm PT). Nobody has to upload invoices:
 *
 *   - AMS: logs into allmediasupply.com for each account we have a login for
 *     (Pico 131507 = the ICA "ams" credentials, Hollywood 131715 = the login
 *     saved on the audit page), lists invoices (/Account/Invoices_Read) and
 *     downloads each new one's PDF (/Account/GetDocument/{id}).
 *   - Alliance: reads the e-invoice emails (hdadmin@aent.com, PDF attached)
 *     over IMAP from the intake mailbox (sarah@nivessa.com by default; Jon's
 *     Gmail forwards them there).
 *
 * Each PDF is parsed on the server (DistributorInvoiceParser) and imported
 * through the same code as the manual importer: missing titles become new
 * products (SKU = UPC), one purchase at status Ordered, de-duped by invoice #
 * (the importer sidecar, an invoice registered as covered on the audit page,
 * or an existing purchase whose ref/notes carry the invoice #). Lines already
 * on another purchase are left off. Anything that can't be read or imported
 * cleanly is posted to Slack and shown on /reports/distributor-invoice-audit.
 */
class DistributorInvoiceIntake
{
    const SETTINGS = 'distributor-invoices/intake-settings.enc';
    const LOG = 'distributor-invoices/intake-log.json';
    const SEEN = 'distributor-invoices/intake-seen.json';
    const ALLIANCE_FROM = 'hdadmin@aent.com';

    private $business_id;
    private $user_id;
    private $dry = false;
    private $report = [];

    public function __construct()
    {
        $this->business_id = (int) optional(DB::table('business')->orderBy('id')->first())->id;
        $this->user_id = (int) optional(DB::table('users')->where('business_id', $this->business_id)->orderBy('id')->first())->id;
    }

    // ---- settings (Slack webhook, second AMS login, mailbox override) ----

    public static function settings()
    {
        if (!Storage::disk('local')->exists(self::SETTINGS)) return [];
        try {
            return json_decode(Crypt::decryptString(Storage::disk('local')->get(self::SETTINGS)), true) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    public static function saveSettings(array $changes)
    {
        $s = self::settings();
        foreach ($changes as $k => $v) {
            if ($v === null || $v === '') continue; // blank = keep what's saved
            $s[$k] = $v;
        }
        $s['_updated_at'] = now()->toDateTimeString();
        Storage::disk('local')->put(self::SETTINGS, Crypt::encryptString(json_encode($s)));
    }

    // What's set up, for the audit page (no secrets).
    public static function status()
    {
        $s = self::settings();
        try {
            $c = (new \App\Services\SupplierFetchers\AmsFetcher())->readCredentials();
            $amsMain = (string) $c['AMS_PORTAL_ACCOUNT'];
        } catch (\Throwable $e) {
            $amsMain = null;
        }
        $mailUser = !empty($s['mail_user']) ? $s['mail_user'] : config('inventory_check.email.username');
        $mailPass = !empty($s['mail_pass']) ? $s['mail_pass'] : config('inventory_check.email.password');
        return [
            'slack' => !empty($s['slack_webhook']),
            'ams_main' => $amsMain,
            'ams2' => !empty($s['ams2_user']) ? ($s['ams2_account'] ?? '') : null,
            'mailbox' => ($mailUser && $mailPass) ? $mailUser : null,
        ];
    }

    // ---- log ----

    public static function log()
    {
        if (!Storage::disk('local')->exists(self::LOG)) return [];
        $log = json_decode(Storage::disk('local')->get(self::LOG), true) ?: [];
        foreach ($log as &$e) if (isset($e['msg'])) $e['msg'] = preg_replace('/\bLOGIN\s+\S+\s+\S+/i', 'LOGIN [hidden]', (string) $e['msg']);
        unset($e);
        return $log;
    }

    // Never store or show a password: IMAP errors used to echo the LOGIN
    // command (10/20). Masks LOGIN arguments and any configured secret.
    public static function redact($text)
    {
        $text = preg_replace('/\bLOGIN\s+\S+\s+\S+/i', 'LOGIN [hidden]', (string) $text);
        $s = self::settings();
        foreach ([config('inventory_check.email.password'), $s['mail_pass'] ?? null, $s['ams2_pass'] ?? null] as $secret) {
            $secret = (string) $secret;
            if (strlen($secret) < 4) continue;
            foreach (array_unique([$secret, str_replace(' ', '', $secret)]) as $v) $text = str_replace($v, '[hidden]', $text);
        }
        return $text;
    }

    // Rewrite stored log entries through redact() (cleans up the 10/20 leak).
    public static function scrubLog()
    {
        $log = self::log();
        $changed = false;
        foreach ($log as &$e) {
            $clean = self::redact($e['msg'] ?? '');
            if ($clean !== ($e['msg'] ?? '')) { $e['msg'] = $clean; $changed = true; }
        }
        unset($e);
        if ($changed) Storage::disk('local')->put(self::LOG, json_encode($log, JSON_PRETTY_PRINT));
        return $changed;
    }

    private function addLog(array $entry)
    {
        if (isset($entry['msg'])) $entry['msg'] = self::redact($entry['msg']);
        $entry['at'] = now()->toDateTimeString();
        $this->report[] = $entry;
        if ($this->dry) return;
        $log = self::log();
        array_unshift($log, $entry);
        Storage::disk('local')->put(self::LOG, json_encode(array_slice($log, 0, 300), JSON_PRETTY_PRINT));
    }

    private function seen()
    {
        if (!Storage::disk('local')->exists(self::SEEN)) return [];
        return json_decode(Storage::disk('local')->get(self::SEEN), true) ?: [];
    }

    private function markSeen($key)
    {
        if ($this->dry) return;
        $s = $this->seen();
        $s[$key] = now()->toDateTimeString();
        Storage::disk('local')->put(self::SEEN, json_encode($s));
    }

    public static function slack($text)
    {
        $hook = trim((string) (self::settings()['slack_webhook'] ?? ''));
        if ($hook === '') return false;
        try {
            $ch = curl_init($hook);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_POSTFIELDS => json_encode(['text' => $text]),
                CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 5,
            ]);
            curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return $code >= 200 && $code < 300;
        } catch (\Throwable $e) {
            Log::warning('invoice intake slack post failed: ' . $e->getMessage());
            return false;
        }
    }

    private function alert($distributor, $invoice, $problem)
    {
        $label = DistributorInvoiceImport::config($distributor)['label'] ?? strtoupper($distributor);
        $problem = self::redact($problem);
        $text = "Invoice intake: {$label} invoice " . ($invoice ?: '(no number)') . " needs a look. {$problem}"
            . "\nAudit: https://playlist.nivessa.com/reports/distributor-invoice-audit";
        if ($this->dry) return;
        if (!self::slack($text)) Log::warning('invoice intake alert not posted (no Slack webhook): ' . $text);
    }

    // ---- run ----

    public function run($days = 60, $only = null, $dry = false)
    {
        $this->dry = (bool) $dry;
        $this->report = [];
        if (!$this->dry) {
            try { self::scrubLog(); } catch (\Throwable $e) {}
        }
        if (!$this->business_id || !$this->user_id) return ['error' => 'No business/user to import as.'];
        if ($only === null || $only === 'ams') {
            foreach ($this->amsLogins() as $login) {
                try {
                    $this->pullAms($login, $days);
                } catch (\Throwable $e) {
                    $this->addLog(['distributor' => 'ams', 'status' => 'error', 'msg' => 'AMS ' . $login['label'] . ': ' . $e->getMessage()]);
                    $this->alertOnce('ams-login-' . $login['account'], 'ams', null, 'Could not read the AMS portal for ' . $login['label'] . ': ' . $e->getMessage());
                }
            }
        }
        if ($only === null || $only === 'alliance') {
            try {
                $this->pullAllianceEmail($days);
            } catch (\Throwable $e) {
                $this->addLog(['distributor' => 'alliance', 'status' => 'error', 'msg' => 'Mailbox: ' . $e->getMessage()]);
                $this->alertOnce('alliance-mailbox', 'alliance', null, 'Could not read the invoice mailbox: ' . $e->getMessage());
            }
        }
        if (!$this->dry) {
            Storage::disk('local')->put('distributor-invoices/intake-last-run.json', json_encode(['at' => now()->toDateTimeString(), 'entries' => count($this->report)]));
        }
        return $this->report;
    }

    // Login/mailbox failures alert at most once a day, not every run.
    private function alertOnce($key, $distributor, $invoice, $problem)
    {
        $seenKey = 'alert:' . $key . ':' . date('Y-m-d');
        if (isset($this->seen()[$seenKey])) return;
        $this->alert($distributor, $invoice, $problem);
        $this->markSeen($seenKey);
    }

    private function amsLogins()
    {
        $out = [];
        // The login the price pull uses (Inventory Check Assistant > AMS > Credentials).
        try {
            $c = (new \App\Services\SupplierFetchers\AmsFetcher())->readCredentials();
            $out[] = ['account' => (string) $c['AMS_PORTAL_ACCOUNT'], 'user' => $c['AMS_PORTAL_USER'], 'pass' => $c['AMS_PORTAL_PASS'], 'label' => 'account ' . $c['AMS_PORTAL_ACCOUNT']];
        } catch (\Throwable $e) {
            // no main AMS login saved
        }
        // The other AMS account, saved on the audit page.
        $s = self::settings();
        if (!empty($s['ams2_user']) && !empty($s['ams2_pass'])) {
            $acct = $s['ams2_account'] ?? '';
            $out[] = ['account' => (string) $acct, 'user' => $s['ams2_user'], 'pass' => $s['ams2_pass'], 'label' => 'account ' . $acct];
        }
        return $out;
    }

    private function curl($jar, $method, $url, $body = null, array $headers = [])
    {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 60, CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
            CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
            CURLOPT_HTTPHEADER => $headers,
        ];
        if ($method === 'POST') { $opts[CURLOPT_POST] = true; $opts[CURLOPT_POSTFIELDS] = $body; }
        curl_setopt_array($ch, $opts);
        $res = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($res === false) throw new \RuntimeException("request failed ({$err})");
        return [$code, $res];
    }

    private function pullAms(array $login, $days)
    {
        $jar = tempnam(sys_get_temp_dir(), 'amsinv');
        try {
            $base = 'https://www.allmediasupply.com';
            $this->curl($jar, 'GET', $base . '/Account/LogOn');
            // Same login as AmsFetcher (the price pull), which works server-side.
            $this->curl($jar, 'POST', $base . '/Account/LogOn', http_build_query([
                'AccountNumber' => $login['account'], 'UserName' => $login['user'], 'Password' => $login['pass'],
                'RememberMe' => 'false', 'ReturnUrl' => '',
            ]), ['Content-Type: application/x-www-form-urlencoded']);
            list(, $home) = $this->curl($jar, 'GET', $base . '/');
            if (stripos($home, 'Sign out') === false && stripos($home, 'logout') === false) {
                throw new \RuntimeException('login failed (check the saved AMS login)');
            }
            list($code, $json) = $this->curl($jar, 'POST', $base . '/Account/Invoices_Read',
                'sort=InvoiceDate-desc&page=1&pageSize=60&group=&filter=',
                ['Content-Type: application/x-www-form-urlencoded; charset=UTF-8', 'X-Requested-With: XMLHttpRequest']);
            $data = json_decode($json, true);
            if ($code !== 200 || !is_array($data) || !isset($data['Data'])) throw new \RuntimeException('invoice list did not load (HTTP ' . $code . ')');
            $since = now()->subDays($days)->startOfDay()->getTimestamp();
            foreach ($data['Data'] as $row) {
                $ts = preg_match('/Date\((\d+)\)/', (string) ($row['InvoiceDate'] ?? ''), $m) ? (int) ($m[1] / 1000) : strtotime((string) ($row['InvoiceDate'] ?? ''));
                if ($ts && $ts < $since) continue;
                $inv = str_pad((string) ($row['InvoiceNumber'] ?? ''), 7, '0', STR_PAD_LEFT);
                if ($this->alreadyHandled('ams', $inv)) {
                    if ($this->needsResync('ams', $inv)) {
                        list($dc, $pdf) = $this->curl($jar, 'GET', $base . '/Account/GetDocument/' . (int) ($row['DocumentId'] ?? 0));
                        if ($dc === 200 && strncmp($pdf, '%PDF', 4) === 0) $this->resync('ams', $inv, $pdf, 'AMS portal ' . $login['label']);
                    }
                    if ($this->needsVinylCatchUp('ams', $inv)) {
                        list($dc, $pdf) = $this->curl($jar, 'GET', $base . '/Account/GetDocument/' . (int) ($row['DocumentId'] ?? 0));
                        if ($dc === 200 && strncmp($pdf, '%PDF', 4) === 0) $this->vinylCatchUp('ams', $inv, $pdf, 'AMS portal ' . $login['label']);
                    }
                    if ($this->needsCatchUp('ams', $inv)) {
                        list($dc, $pdf) = $this->curl($jar, 'GET', $base . '/Account/GetDocument/' . (int) ($row['DocumentId'] ?? 0));
                        if ($dc === 200 && strncmp($pdf, '%PDF', 4) === 0) $this->catchUp('ams', $inv, $pdf, 'AMS portal ' . $login['label']);
                    }
                    continue;
                }
                $seenKey = 'ams:' . $inv;
                if (isset($this->seen()[$seenKey])) continue; // failed before and was alerted; manual upload fixes it
                list($dc, $pdf) = $this->curl($jar, 'GET', $base . '/Account/GetDocument/' . (int) ($row['DocumentId'] ?? 0));
                if ($dc !== 200 || strncmp($pdf, '%PDF', 4) !== 0) {
                    $this->addLog(['distributor' => 'ams', 'invoice' => $inv, 'status' => 'error', 'msg' => 'PDF download failed (HTTP ' . $dc . ')']);
                    continue; // retried next run
                }
                $this->importPdf('ams', $pdf, 'AMS portal ' . $login['label'], $inv, $seenKey);
            }
        } finally {
            @unlink($jar);
        }
    }

    private function pullAllianceEmail($days)
    {
        $s = self::settings();
        $host = $s['mail_host'] ?? config('inventory_check.email.host', 'imap.gmail.com');
        $port = (int) ($s['mail_port'] ?? config('inventory_check.email.port', 993));
        $user = !empty($s['mail_user']) ? $s['mail_user'] : config('inventory_check.email.username');
        $pass = !empty($s['mail_pass']) ? $s['mail_pass'] : config('inventory_check.email.password');
        if (!$user || !$pass) {
            $this->addLog(['distributor' => 'alliance', 'status' => 'skipped', 'msg' => 'No invoice mailbox set up yet.']);
            return;
        }
        $imap = new RawImapClient();
        $imap->connect($host, $port);
        try {
            $imap->login($user, str_replace(' ', '', $pass));
            $imap->selectFolder('[Gmail]/All Mail');
            $uids = $imap->uidSearchSinceFrom(now()->subDays($days), self::ALLIANCE_FROM);
            $seen = $this->seen();
            // Re-open processed emails only while some imported invoice still
            // needs its one-time re-check.
            $pendingResync = false;
            foreach (Storage::disk('local')->files(DistributorInvoiceImport::config('alliance')['dir']) as $f) {
                if ($this->needsResync('alliance', basename($f, '.json'))) { $pendingResync = true; break; }
            }
            foreach ($uids as $uid) {
                $uidKey = 'mail:' . $user . ':' . $uid;
                if (isset($seen[$uidKey]) && !$pendingResync) continue;
                $raw = $imap->uidFetchRaw($uid);
                if (!$raw) continue;
                $subject = '';
                if (preg_match('/^Subject:\s*(.+)$/mi', $raw, $sm)) $subject = trim($sm[1]);
                $pdfs = self::pdfAttachments($raw);
                $invFromSubject = preg_match('/(PLS\d{6,})/', $subject, $im) ? $im[1] : null;
                if (!$pdfs) {
                    if ($invFromSubject && !$this->alreadyHandled('alliance', $invFromSubject)) {
                        $this->addLog(['distributor' => 'alliance', 'invoice' => $invFromSubject, 'status' => 'failed', 'msg' => 'Email has no PDF attached.']);
                        $this->alert('alliance', $invFromSubject, 'The e-invoice email had no PDF attached.');
                    }
                    $this->markSeen($uidKey);
                    continue;
                }
                foreach ($pdfs as $pdf) {
                    $inv = $invFromSubject;
                    if ($inv && $this->alreadyHandled('alliance', $inv)) {
                        if ($this->needsResync('alliance', $inv)) $this->resync('alliance', $inv, $pdf, 'Alliance e-invoice email');
                        continue;
                    }
                    $this->importPdf('alliance', $pdf, 'Alliance e-invoice email', $inv, null);
                }
                $this->markSeen($uidKey);
            }
        } finally {
            $imap->logout();
        }
    }

    // PDF parts of a raw RFC822 message (walks nested multiparts, so a
    // forwarded message's attachment is found too).
    public static function pdfAttachments($raw)
    {
        $out = [];
        $walk = function ($part) use (&$walk, &$out) {
            $split = preg_split("/\r?\n\r?\n/", $part, 2);
            $head = $split[0] ?? '';
            $body = $split[1] ?? '';
            $head = preg_replace("/\r?\n[ \t]+/", ' ', $head);
            if (preg_match('/^Content-Type:\s*multipart\/[^;]+;.*?boundary="?([^";\r\n]+)"?/mi', $head, $b)) {
                foreach (explode('--' . $b[1], $body) as $sub) {
                    $sub = ltrim($sub, "\r\n");
                    if ($sub === '' || strpos($sub, '--') === 0) continue;
                    $walk($sub);
                }
                return;
            }
            if (preg_match('/^Content-Type:\s*message\/rfc822/mi', $head)) { $walk($body); return; }
            $isPdf = preg_match('/^Content-Type:\s*application\/(pdf|octet-stream)/mi', $head)
                && (stripos($head, 'application/pdf') !== false || preg_match('/name="?[^"\r\n]*\.pdf/i', $head));
            if (!$isPdf) return;
            $enc = preg_match('/^Content-Transfer-Encoding:\s*(\S+)/mi', $head, $e) ? strtolower($e[1]) : '';
            $data = $enc === 'base64' ? base64_decode(preg_replace('/\s+/', '', $body)) : ($enc === 'quoted-printable' ? quoted_printable_decode($body) : $body);
            if ($data && strncmp($data, '%PDF', 4) === 0) $out[] = $data;
        };
        $walk($raw);
        return $out;
    }

    // Already imported, registered as covered by purchases, or written on a purchase.
    public function alreadyHandled($distributor, $invoice)
    {
        $key = DistributorInvoiceImport::invoiceKey($distributor, $invoice);
        if ($key === '') return false;
        if (Storage::disk('local')->exists(DistributorInvoiceImport::sidecarPath($distributor, $key))) return true;
        $reg = json_decode((string) (Storage::disk('local')->exists('distributor-invoices/registry.json') ? Storage::disk('local')->get('distributor-invoices/registry.json') : ''), true) ?: [];
        if (!empty($reg[$distributor . ':' . $key]['purchases'])) return true;
        return DB::table('transactions')->where('business_id', $this->business_id)->where('type', 'purchase')
            ->where(function ($q) use ($key) {
                $q->where('ref_no', 'like', '%' . $key . '%')->orWhere('additional_notes', 'like', '%' . $key . '%');
            })->exists();
    }

    // Invoices imported before 10/20/2026 dropped every UPC that wasn't in the
    // catalog yet. Those sidecars have no 'new_products' key; each one gets a
    // single catch-up pass.
    private function needsCatchUp($distributor, $invoice)
    {
        $path = DistributorInvoiceImport::sidecarPath($distributor, DistributorInvoiceImport::invoiceKey($distributor, $invoice));
        if (!Storage::disk('local')->exists($path)) return false;
        $sc = json_decode(Storage::disk('local')->get($path), true) ?: [];
        return !array_key_exists('new_products', $sc) && empty($sc['catch_up_at']) && !empty($sc['transaction_id']);
    }

    // Add the lines an earlier import dropped: titles still not in the
    // catalog, and catalog titles that are on neither that invoice's purchase
    // nor any other purchase from the supplier/store (e.g. the desk's same-day
    // manual purchase). They go on one extra purchase "<ref>-B".
    private function catchUp($distributor, $invoice, $pdf, $source)
    {
        $inv = DistributorInvoiceImport::invoiceKey($distributor, $invoice);
        $path = DistributorInvoiceImport::sidecarPath($distributor, $inv);
        $sc = json_decode(Storage::disk('local')->get($path), true) ?: [];
        $own = DB::table('transactions')->where('id', (int) $sc['transaction_id'])->first(['id', 'location_id', 'contact_id', 'transaction_date']);
        $p = DistributorInvoiceParser::parse($distributor, $pdf);
        if (!$own || !$p['items'] || $p['goods_matches'] === false) {
            $this->addLog(['distributor' => $distributor, 'invoice' => $inv, 'status' => 'skipped', 'source' => $source, 'msg' => 'Catch-up skipped: ' . (!$own ? 'its purchase is gone.' : 'the PDF could not be read cleanly.')]);
            $this->markCatchUp($path, $sc, null);
            return;
        }
        $ownVids = DB::table('purchase_lines')->where('transaction_id', $own->id)->pluck('variation_id')->map(function ($v) { return (int) $v; })->all();
        $ownVids = array_flip($ownVids);
        $lines = array_map(function ($it) {
            return ['upc' => $it['upc'], 'description' => $it['description'], 'format' => $it['prodType'] ?? '',
                    'qty' => $it['qty'], 'unit_price' => $it['unitPrice'], 'line_total' => $it['lineTotal']];
        }, $p['items']);
        $preview = DistributorInvoiceImport::preview($this->business_id, $lines, $own->contact_id, $own->location_id, substr($own->transaction_date, 0, 10), $inv, $distributor);
        $take = []; $noCategory = [];
        foreach ($preview as $l) {
            $onOwn = false;
            foreach ($l['upc_variation_ids'] as $v) if (isset($ownVids[$v])) { $onOwn = true; break; }
            if ($l['matched'] && ($onOwn || $l['existing'])) continue;
            if (!$l['matched'] && empty($l['new_product']['category_id'])) { $noCategory[] = $l['upc'] . ' ' . $l['description']; continue; }
            $take[] = ['variation_id' => $l['variation_id'], 'qty' => $l['qty'], 'unit_price' => $l['unit_price'], 'upc' => $l['upc'],
                       'description' => $l['description'], 'new_product' => $l['matched'] ? null : $l['new_product']];
        }
        if ($this->dry) {
            $this->addLog(['distributor' => $distributor, 'invoice' => $inv, 'status' => 'dry-run catch-up', 'source' => $source,
                'msg' => sprintf('Purchase #%d is missing %d line(s) (%d new products); %d need a category.', $own->id, count($take),
                    count(array_filter($take, function ($t) { return !empty($t['new_product']); })), count($noCategory))]);
            return;
        }
        $txId = null;
        if ($take) {
            $ownStatus = DB::table('transactions')->where('id', $own->id)->value('status');
            $notes = DistributorInvoiceImport::config($distributor)['label'] . ' invoice ' . $inv . ': lines left off purchase #' . $own->id . ' by the old importer, added automatically'
                . ($ownStatus === 'received' ? '. That box was already received with #' . $own->id . ', so count these before receiving.' : '');
            try {
                $res = DistributorInvoiceImport::apply($distributor, $this->business_id, $this->user_id, $own->location_id, $own->contact_id, $inv,
                    substr($own->transaction_date, 0, 10), $notes, $take, ['ref_suffix' => '-B', 'sidecar' => false]);
                $txId = $res['transaction']->id;
                $this->addLog(['distributor' => $distributor, 'invoice' => $inv, 'status' => 'imported', 'source' => $source, 'transaction_id' => $txId,
                    'msg' => sprintf('Catch-up purchase #%d: %d line(s) missing from #%d, %d new products.', $txId, $res['lines'], $own->id, count($res['created_products']))]);
            } catch (\Throwable $e) {
                $this->addLog(['distributor' => $distributor, 'invoice' => $inv, 'status' => 'failed', 'source' => $source, 'msg' => 'Catch-up failed: ' . $e->getMessage()]);
                $this->alertOnce('catchup-' . $inv, $distributor, $inv, 'Adding the lines missing from purchase #' . $own->id . ' failed: ' . $e->getMessage());
                return; // retried next run
            }
        }
        if ($noCategory) {
            $this->alert($distributor, $inv, 'These lines are still missing from purchase #' . $own->id . ' because their format has no category: ' . implode('; ', $noCategory) . '.');
        }
        $this->markCatchUp($path, $sc, $txId);
    }

    // Invoices the intake imported automatically before 10/21 left off any
    // title that was on an older purchase. One pass adds those lines to the
    // invoice's own purchase while it is still Ordered.
    private function needsResync($distributor, $invoice)
    {
        $path = DistributorInvoiceImport::sidecarPath($distributor, DistributorInvoiceImport::invoiceKey($distributor, $invoice));
        if (!Storage::disk('local')->exists($path)) return false;
        $sc = json_decode(Storage::disk('local')->get($path), true) ?: [];
        if (!array_key_exists('new_products', $sc) || !empty($sc['resynced_at']) || empty($sc['transaction_id'])) return false;
        $tx = DB::table('transactions')->where('id', (int) $sc['transaction_id'])->first(['status', 'additional_notes']);
        return $tx && $tx->status !== 'received' && strpos((string) $tx->additional_notes, 'imported automatically') !== false;
    }

    private function resync($distributor, $invoice, $pdf, $source)
    {
        $inv = DistributorInvoiceImport::invoiceKey($distributor, $invoice);
        $path = DistributorInvoiceImport::sidecarPath($distributor, $inv);
        $sc = json_decode(Storage::disk('local')->get($path), true) ?: [];
        $own = DB::table('transactions')->where('id', (int) $sc['transaction_id'])->first(['id', 'location_id', 'contact_id', 'transaction_date']);
        $p = DistributorInvoiceParser::parse($distributor, $pdf);
        if (!$own || !$p['items'] || $p['goods_matches'] === false) return;
        $ownVids = array_flip(DB::table('purchase_lines')->where('transaction_id', $own->id)->pluck('variation_id')->map(function ($v) { return (int) $v; })->all());
        $lines = array_map(function ($it) {
            return ['upc' => $it['upc'], 'description' => $it['description'], 'format' => $it['prodType'] ?? '',
                    'qty' => $it['qty'], 'unit_price' => $it['unitPrice'], 'line_total' => $it['lineTotal']];
        }, $p['items']);
        $preview = DistributorInvoiceImport::preview($this->business_id, $lines, $own->contact_id, $own->location_id, substr($own->transaction_date, 0, 10), $inv, $distributor);
        $add = []; $names = [];
        foreach ($preview as $l) {
            if (!$l['matched'] || $l['existing']) continue;
            $onOwn = false;
            foreach ($l['upc_variation_ids'] as $v) if (isset($ownVids[$v])) { $onOwn = true; break; }
            if ($onOwn) continue;
            $add[] = ['variation_id' => $l['variation_id'], 'qty' => $l['qty'], 'unit_price' => $l['unit_price']];
            $names[] = $l['description'] . ' x' . $l['qty'] . ($l['earlier'] ? ' (also on older #' . $l['earlier'][0]['transaction_id'] . ')' : '');
        }
        if ($this->dry) {
            $this->addLog(['distributor' => $distributor, 'invoice' => $inv, 'status' => 'dry-run re-check', 'msg' => count($add) . ' line(s) to add to #' . $own->id . ': ' . implode('; ', $names)]);
            return;
        }
        if ($add) {
            try {
                list($n, $total) = DistributorInvoiceImport::appendLines($this->business_id, $own->id, $add);
                $this->addLog(['distributor' => $distributor, 'invoice' => $inv, 'status' => 'imported', 'source' => $source, 'transaction_id' => $own->id,
                    'msg' => sprintf('Added %d line(s), $%.2f, left off by the old rule: %s.', $n, $total, implode('; ', $names))]);
            } catch (\Throwable $e) {
                $this->addLog(['distributor' => $distributor, 'invoice' => $inv, 'status' => 'failed', 'source' => $source, 'msg' => 'Re-check failed: ' . $e->getMessage()]);
                return;
            }
        }
        $sc['resynced_at'] = now()->toDateTimeString();
        Storage::disk('local')->put($path, json_encode($sc, JSON_PRETTY_PRINT));
    }

    // 10/21: AMS format codes TIN (12-inch) and TEN (10-inch) had no category,
    // so the first catch-up left those records off. One extra pass adds only
    // TIN/TEN lines whose barcode is not in the catalog at all (so they
    // cannot be on any purchase) as new products on "<ref>-C".
    private function needsVinylCatchUp($distributor, $invoice)
    {
        $path = DistributorInvoiceImport::sidecarPath($distributor, DistributorInvoiceImport::invoiceKey($distributor, $invoice));
        if (!Storage::disk('local')->exists($path)) return false;
        $sc = json_decode(Storage::disk('local')->get($path), true) ?: [];
        return !empty($sc['catch_up_at']) && empty($sc['vinyl_catch_up_at']);
    }

    private function vinylCatchUp($distributor, $invoice, $pdf, $source)
    {
        $inv = DistributorInvoiceImport::invoiceKey($distributor, $invoice);
        $path = DistributorInvoiceImport::sidecarPath($distributor, $inv);
        $sc = json_decode(Storage::disk('local')->get($path), true) ?: [];
        $own = DB::table('transactions')->where('id', (int) ($sc['transaction_id'] ?? 0))->first(['id', 'location_id', 'contact_id', 'transaction_date']);
        $p = DistributorInvoiceParser::parse($distributor, $pdf);
        if (!$own || !$p['items'] || $p['goods_matches'] === false) return;
        $lines = [];
        foreach ($p['items'] as $it) {
            if (!in_array(strtoupper($it['prodType'] ?? ''), ['TIN', 'TEN'], true)) continue;
            $lines[] = ['upc' => $it['upc'], 'description' => $it['description'], 'format' => $it['prodType'],
                        'qty' => $it['qty'], 'unit_price' => $it['unitPrice'], 'line_total' => $it['lineTotal']];
        }
        $take = [];
        if ($lines) {
            foreach (DistributorInvoiceImport::preview($this->business_id, $lines, $own->contact_id, $own->location_id, substr($own->transaction_date, 0, 10), $inv, $distributor) as $l) {
                if ($l['matched'] || $l['upc_variation_ids'] || empty($l['new_product']['category_id'])) continue; // already in the catalog
                $take[] = ['variation_id' => null, 'qty' => $l['qty'], 'unit_price' => $l['unit_price'], 'upc' => $l['upc'],
                           'description' => $l['description'], 'new_product' => $l['new_product']];
            }
        }
        if ($this->dry) {
            $this->addLog(['distributor' => $distributor, 'invoice' => $inv, 'status' => 'dry-run vinyl', 'msg' => count($take) . ' to add: ' . implode('; ', array_map(function ($t) { return $t['new_product']['name'] . ' x' . $t['qty'] . ' $' . $t['new_product']['sell_price']; }, $take))]);
            return;
        }
        $txId = null;
        if ($take) {
            $ownStatus = DB::table('transactions')->where('id', $own->id)->value('status');
            $notes = DistributorInvoiceImport::config($distributor)['label'] . ' invoice ' . $inv . ': 10-inch / 12-inch records left off purchase #' . $own->id . ', added automatically'
                . ($ownStatus === 'received' ? '. That box was already received with #' . $own->id . ', so count these before receiving.' : '');
            try {
                $res = DistributorInvoiceImport::apply($distributor, $this->business_id, $this->user_id, $own->location_id, $own->contact_id, $inv,
                    substr($own->transaction_date, 0, 10), $notes, $take, ['ref_suffix' => '-C', 'sidecar' => false]);
                $txId = $res['transaction']->id;
                $this->addLog(['distributor' => $distributor, 'invoice' => $inv, 'status' => 'imported', 'source' => $source, 'transaction_id' => $txId,
                    'msg' => sprintf('Purchase #%d: %d 10/12-inch record(s) missing from #%d, %d new products.', $txId, $res['lines'], $own->id, count($res['created_products']))]);
            } catch (\Throwable $e) {
                $this->addLog(['distributor' => $distributor, 'invoice' => $inv, 'status' => 'failed', 'source' => $source, 'msg' => '10/12-inch catch-up failed: ' . $e->getMessage()]);
                return;
            }
        }
        $sc['vinyl_catch_up_at'] = now()->toDateTimeString();
        $sc['vinyl_catch_up_tx'] = $txId;
        Storage::disk('local')->put($path, json_encode($sc, JSON_PRETTY_PRINT));
    }

    private function markCatchUp($path, array $sc, $txId)
    {
        if ($this->dry) return;
        $sc['catch_up_at'] = now()->toDateTimeString();
        $sc['catch_up_tx'] = $txId;
        Storage::disk('local')->put($path, json_encode($sc, JSON_PRETTY_PRINT));
    }

    /**
     * Redeye has no invoice feed the server can reach (their portal blocks
     * server logins), so the daily Chrome task reads the invoices on
     * b2b.redeyeworldwide.com/account-history and posts them here
     * (/admin/redeye-invoice-import/auto). Redeye bills one invoice per
     * order line group and ships them in one box, so invoices with the same
     * date and store become ONE purchase (Ordered) whose notes list every
     * invoice # and order #. An invoice is skipped if its invoice # or its
     * order # (the 10/12 order was logged by order #) is already on a purchase.
     * $invoices: [{invoice, so, date, ship_to, lines: [{upc, item, artist, title, qty, unit_price}]}]
     */
    public function importRedeye(array $invoices, $dry = false)
    {
        $this->dry = (bool) $dry;
        $supplier_id = DistributorInvoiceImport::defaultSupplierId($this->business_id, 'redeye');
        $groups = [];
        foreach ($invoices as $iv) {
            $inv = DistributorInvoiceImport::invoiceKey('redeye', $iv['invoice'] ?? '');
            $so = preg_replace('/\D/', '', (string) ($iv['so'] ?? ''));
            if ($inv === '') continue;
            if ($this->alreadyHandled('redeye', $inv) || ($so !== '' && $this->alreadyHandled('redeye', $so))) continue;
            $store = stripos((string) ($iv['ship_to'] ?? ''), 'pico') !== false ? 'pico' : 'hollywood';
            $date = substr(DistributorInvoiceImport::normalizeDate($iv['date'] ?? ''), 0, 10);
            $groups[$date . '|' . $store][] = ['invoice' => $inv, 'so' => $so, 'lines' => (array) ($iv['lines'] ?? [])];
        }

        foreach ($groups as $gk => $ivs) {
            [$date, $store] = explode('|', $gk);
            usort($ivs, function ($a, $b) { return strcmp($a['invoice'], $b['invoice']); });
            $key = $ivs[0]['invoice'];
            $invList = implode(', ', array_column($ivs, 'invoice'));
            $location_id = (int) DB::table('business_locations')->where('business_id', $this->business_id)->where('name', $store)->value('id');
            if (!$location_id || !$supplier_id) {
                $this->addLog(['distributor' => 'redeye', 'invoice' => $key, 'status' => 'failed', 'msg' => 'No ' . (!$supplier_id ? 'Redeye supplier contact' : $store . ' store') . ' found.']);
                continue;
            }
            $lines = [];
            foreach ($ivs as $iv) {
                foreach ($iv['lines'] as $l) {
                    $qty = (float) ($l['qty'] ?? 0);
                    $unit = (float) preg_replace('/[^0-9.]/', '', (string) ($l['unit_price'] ?? 0));
                    $lines[] = ['upc' => (string) ($l['upc'] ?? ''), 'format' => strtoupper(strtok((string) ($l['item'] ?? ''), '-')),
                                'description' => trim(($l['artist'] ?? '') . ' / ' . ($l['title'] ?? ''), ' /'),
                                'qty' => $qty, 'unit_price' => $unit, 'line_total' => round($qty * $unit, 2)];
                }
            }
            $preview = DistributorInvoiceImport::preview($this->business_id, $lines, $supplier_id, $location_id, $date, $key, 'redeye');
            $take = []; $noCategory = [];
            foreach ($preview as $l) {
                if (!$l['matched'] && empty($l['new_product']['category_id'])) { $noCategory[] = $l['upc'] . ' ' . $l['description']; continue; }
                $take[] = ['variation_id' => $l['variation_id'], 'qty' => $l['qty'], 'unit_price' => $l['unit_price'], 'upc' => $l['upc'],
                           'description' => $l['description'], 'new_product' => $l['matched'] ? null : $l['new_product']];
            }
            $newCount = count(array_filter($take, function ($t) { return !empty($t['new_product']); }));
            $sum = array_sum(array_map(function ($t) { return $t['qty'] * $t['unit_price']; }, $take));
            if ($this->dry) {
                $this->addLog(['distributor' => 'redeye', 'invoice' => $key, 'status' => 'dry-run',
                    'msg' => sprintf('%s %s: invoices %s; %d lines, %d units, $%.2f, %d new products, %d need a category.',
                        $date, $store, $invList, count($take), array_sum(array_column($take, 'qty')), $sum, $newCount, count($noCategory))]);
                continue;
            }
            if (!$take) continue;
            $sos = array_filter(array_column($ivs, 'so'));
            $notes = 'Redeye invoices ' . $invList . ($sos ? ' / orders ' . implode(', ', array_unique($sos)) : '')
                . ', one box to ' . ucfirst($store) . ', imported automatically from the Redeye portal';
            try {
                $res = DistributorInvoiceImport::apply('redeye', $this->business_id, $this->user_id, $location_id, $supplier_id, $key, $date, $notes, $take);
            } catch (\Throwable $e) {
                $this->addLog(['distributor' => 'redeye', 'invoice' => $key, 'status' => 'failed', 'msg' => 'Import failed, nothing was saved: ' . $e->getMessage()]);
                $this->alert('redeye', $key, 'Import failed, nothing was saved: ' . $e->getMessage());
                continue;
            }
            foreach (array_slice($ivs, 1) as $iv) {
                Storage::disk('local')->put(DistributorInvoiceImport::sidecarPath('redeye', $iv['invoice']), json_encode(['invoice' => $iv['invoice'], 'transaction_id' => $res['transaction']->id, 'applied_at' => now()->toDateTimeString()]));
            }
            $this->addLog(['distributor' => 'redeye', 'invoice' => $key, 'status' => 'imported', 'source' => 'Redeye portal',
                'transaction_id' => $res['transaction']->id,
                'msg' => sprintf('Purchase #%d (%s): invoices %s; %d lines, $%.2f, %d new products.', $res['transaction']->id, ucfirst($store), $invList, $res['lines'], $res['total'], count($res['created_products']))]);
            if ($noCategory) {
                $this->alert('redeye', $key, 'Imported as purchase #' . $res['transaction']->id . ', but these lines had no category and were left off: ' . implode('; ', $noCategory) . '. Add them on the purchase.');
            }
        }
        return $this->report;
    }

    private function importPdf($distributor, $pdf, $source, $expectedInvoice, $seenKey)
    {
        try {
            $p = DistributorInvoiceParser::parse($distributor, $pdf);
        } catch (\Throwable $e) {
            $p = ['header' => [], 'items' => [], 'totals' => [], 'sum' => 0, 'goods_matches' => null, 'error' => $e->getMessage()];
        }
        $inv = DistributorInvoiceImport::invoiceKey($distributor, $p['header']['invoice'] ?? $expectedInvoice);
        $fail = function ($why) use ($distributor, $inv, $source, $seenKey) {
            $this->addLog(['distributor' => $distributor, 'invoice' => $inv, 'status' => 'failed', 'source' => $source, 'msg' => $why]);
            $this->alert($distributor, $inv, $why . ' Upload the PDF at https://playlist.nivessa.com/admin/' . $distributor . '-invoice-import');
            if ($seenKey) $this->markSeen($seenKey);
        };

        if ($inv === '') return $fail('The invoice number could not be read from the PDF.');
        if ($this->alreadyHandled($distributor, $inv)) return;
        if (!$p['items']) return $fail('No line items could be read from the PDF.' . (!empty($p['error']) ? ' (' . $p['error'] . ')' : ''));
        if ($p['goods_matches'] === false) {
            return $fail(sprintf('The lines read ($%.2f) don\'t add up to the invoice goods total ($%.2f).', $p['sum'], $p['totals']['goods']));
        }
        $location_id = DistributorInvoiceImport::locationForAccount($this->business_id, $distributor, $p['header']['account'] ?? '');
        if (!$location_id) return $fail('Unknown account # "' . ($p['header']['account'] ?? '') . '" on the invoice, so the store is unknown.');
        $supplier_id = DistributorInvoiceImport::defaultSupplierId($this->business_id, $distributor);
        if (!$supplier_id) return $fail('No supplier contact for ' . $distributor . '.');
        $date = $p['header']['date'] ?? '';

        $lines = array_map(function ($it) {
            return ['upc' => $it['upc'], 'description' => $it['description'], 'format' => $it['prodType'] ?? '',
                    'qty' => $it['qty'], 'unit_price' => $it['unitPrice'], 'line_total' => $it['lineTotal']];
        }, $p['items']);
        $preview = DistributorInvoiceImport::preview($this->business_id, $lines, $supplier_id, $location_id, $date, $inv, $distributor);

        $take = []; $onOther = []; $noCategory = [];
        foreach ($preview as $l) {
            if ($l['existing']) { $onOther[] = $l['description'] . ' (this invoice is already on purchase #' . $l['existing'][0]['transaction_id'] . ')'; continue; }
            if (!$l['matched'] && empty($l['new_product']['category_id'])) { $noCategory[] = $l['upc'] . ' ' . $l['description']; continue; }
            $take[] = ['variation_id' => $l['variation_id'], 'qty' => $l['qty'], 'unit_price' => $l['unit_price'], 'upc' => $l['upc'],
                       'description' => $l['description'], 'new_product' => $l['matched'] ? null : $l['new_product']];
        }
        $newCount = count(array_filter($take, function ($t) { return !empty($t['new_product']); }));

        if ($this->dry) {
            $this->addLog(['distributor' => $distributor, 'invoice' => $inv, 'status' => 'dry-run', 'source' => $source,
                'msg' => sprintf('%d lines, $%.2f (goods match: %s); would import %d (%d new products), skip %d on other purchases, %d need a category. Store %d.',
                    count($p['items']), $p['sum'], var_export($p['goods_matches'], true), count($take), $newCount, count($onOther), count($noCategory), $location_id)]);
            return;
        }
        if (!$take) {
            $this->addLog(['distributor' => $distributor, 'invoice' => $inv, 'status' => 'nothing to add', 'source' => $source,
                'msg' => 'Every line is already logged for this invoice: ' . implode('; ', $onOther)]);
            if ($noCategory) $this->alert($distributor, $inv, 'Lines with no category were not imported: ' . implode('; ', $noCategory));
            if ($seenKey) $this->markSeen($seenKey);
            return;
        }
        $notes = (DistributorInvoiceImport::config($distributor)['label']) . ' invoice ' . $inv
            . (!empty($p['header']['amsRef']) ? ($distributor === 'ams' ? ' / AMS REF ' : ' / PO ') . $p['header']['amsRef'] : '')
            . ', imported automatically from ' . $source;
        try {
            $res = DistributorInvoiceImport::apply($distributor, $this->business_id, $this->user_id, $location_id, $supplier_id, $inv, $date, $notes, $take);
        } catch (\Throwable $e) {
            return $fail('Import failed, nothing was saved: ' . $e->getMessage());
        }
        $this->addLog(['distributor' => $distributor, 'invoice' => $inv, 'status' => 'imported', 'source' => $source,
            'transaction_id' => $res['transaction']->id,
            'msg' => sprintf('Purchase #%d: %d lines, $%.2f, %d new products.%s', $res['transaction']->id, $res['lines'], $res['total'], count($res['created_products']),
                $onOther ? ' Left off (this invoice already logged): ' . implode('; ', $onOther) . '.' : '')]);
        if ($noCategory) {
            $this->alert($distributor, $inv, 'Imported as purchase #' . $res['transaction']->id . ', but these lines had no category and were left off: ' . implode('; ', $noCategory) . '. Add them on the purchase.');
        }
    }
}
