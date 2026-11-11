<?php

namespace App\Http\Controllers;

use App\Utils\BusinessUtil;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Subscription Watch (Sarah 2026-11-11): the accounts payable bot's report.
 * Every month it answers two questions across the BUSINESS cards (personal
 * cards are never stored here): which subscriptions did we sign up for, and which ones went up.
 *
 * Charges come in two ways:
 *   - the monthly bot (a scheduled Claude task) posts JSON here from Chrome
 *     after reading each card's statement / transaction feed
 *   - a CSV download from any bank, uploaded on this page
 * An import for a card replaces that card's charges inside the imported date
 * range, so re-importing the same statement never doubles anything.
 *
 * Access: Jon and Sarah only.
 * No migration: storage/app/card-charges.json.
 */
class SubscriptionWatchController extends Controller
{
    const STORE_PATH = 'card-charges.json';

    // Known cards. New cards can be added from the upload form.
    // Business cards only. Personal cards never go in the ERP (Sarah 2026-11-11);
    // they live in her private money tracker.
    const CARDS = [
        '1866' => ['label' => 'Chase Ink 1866 (Nivessa Two)', 'owner' => 'Business'],
    ];

    // Merchants that are subscriptions even on their first charge.
    const KNOWN = [
        'NETFLIX', 'SPOTIFY', 'HULU', 'DISNEY', 'MAX.COM', 'HBO', 'PARAMOUNT', 'PEACOCK', 'APPLE.COM/BILL',
        'APPLE.COM', 'YOUTUBE', 'GOOGLE *', 'GOOGLE GSUITE', 'GOOGLE WORKSPACE', 'GOOGLE STORAGE', 'PRIME VIDEO',
        'AMAZON PRIME', 'AUDIBLE', 'KINDLE UNLTD', 'ADOBE', 'DROPBOX', 'MICROSOFT', 'MSFT', 'ANTHROPIC', 'CLAUDE.AI',
        'OPENAI', 'CHATGPT', 'INTUIT', 'QBOOKS', 'QUICKBOOKS', 'MONGODB', 'GITHUB', 'HEROKU', 'DIGITALOCEAN',
        'HOSTINGER', 'GODADDY', 'SQUARESPACE', 'WIX', 'SHOPIFY', 'CANVA', 'CALENDLY', 'ZOOM', 'SLACK', 'NOTION',
        'OPENPHONE', 'QUO', 'UBER ONE', 'UBER *ONE', 'DASHPASS', 'DOORDASH DASHPASS', 'INSTACART+', 'WALMART+', 'COSTCO',
        'SIRIUSXM', 'NYTIMES', 'WSJ', 'PATREON', 'LINKEDIN', 'UPWORK MEMBERSHIP', 'ZIPRECRUITER', 'INDEED',
        'SLING', 'CLOCKIFY', 'BREVO', 'SENDINBLUE', 'MAILCHIMP', 'KLAVIYO', 'TWILIO', 'CLOUDFLARE', 'VERCEL',
        'NETLIFY', 'SUPABASE', 'DOCUSIGN', 'GUSTO', 'SYNDER', 'DISCOGS', 'MARBLISM', 'CAPCUT', 'LIGHTROOM',
        '24 HOUR FITNESS', 'EQUINOX', 'PLANET FITNESS', 'CLASSPASS', 'VISIBLE', 'VZWRLSS', 'VERIZON', 'SPECTRUM',
        'AT&T', 'T-MOBILE', 'STATE FARM', 'FARMERS', 'GEICO', 'PROGRESSIVE', 'AAA ', 'LA CARE', 'SUPER+',
        'WANNA DANCE', 'ROBINHOOD', 'EXPRESSVPN', 'NORDVPN', '1PASSWORD', 'LASTPASS', 'ICLOUD',
    ];

    // Never subscriptions: payments, fees, interest, transfers.
    const IGNORE = ['PAYMENT THANK YOU', 'AUTOMATIC PAYMENT', 'PAYMENT RECEIVED', 'INTEREST CHARGE', 'LATE FEE',
        'ANNUAL MEMBERSHIP FEE', 'FOREIGN TRANSACTION FEE', 'BALANCE TRANSFER', 'CASH ADVANCE', 'ONLINE PAYMENT',
        'CREDIT INTEREST', 'INTEREST CHARGE REVERSAL', 'PURCHASE INTEREST'];

    private function guard()
    {
        $u = auth()->user();
        $first = strtolower(trim((string) ($u->first_name ?? '')));
        $last = strtolower(trim((string) ($u->last_name ?? '')));
        $owner = $last === 'hedvat' && in_array($first, ['jon', 'jonathan', 'sarah'], true);
        if (!$u || !$owner || !app(BusinessUtil::class)->is_admin($u)) {
            abort(403, 'Subscription Watch is for Jon and Sarah only.');
        }
    }

    public static function load()
    {
        $d = Storage::exists(self::STORE_PATH) ? json_decode(Storage::get(self::STORE_PATH), true) : null;
        if (!is_array($d)) {
            $d = [];
        }
        $d += ['cards' => [], 'charges' => [], 'imports' => [], 'ignored' => []];
        foreach (self::CARDS as $k => $c) {
            if (!isset($d['cards'][$k])) {
                $d['cards'][$k] = $c;
            }
        }
        // Purge anything personal that was ever loaded.
        $personal = array_keys(array_filter($d['cards'], function ($c) { return ($c['owner'] ?? '') !== 'Business'; }));
        if ($personal) {
            $d['cards'] = array_diff_key($d['cards'], array_flip($personal));
            $d['charges'] = array_values(array_filter($d['charges'], function ($c) use ($personal) { return !in_array($c['card'], $personal, true); }));
            $d['imports'] = array_values(array_filter($d['imports'], function ($i) use ($personal) { return !in_array($i['card'], $personal, true); }));
            self::save($d);
        }
        return $d;
    }

    private static function save(array $d)
    {
        Storage::put(self::STORE_PATH, json_encode($d));
    }

    /** "SQ *PASTA AMORE 555-1234 CA" -> "PASTA AMORE". Stable key per merchant. */
    public static function merchantKey($desc)
    {
        $s = strtoupper(trim((string) $desc));
        $s = preg_replace('/^(SQ|TST|SP|PY|DD|BT|IN|PP|UEP|FS|CKE|PAR|SPO|DNH|EB|NYX)\s?\*\s*/', '', $s);
        $s = preg_replace('/^SP\s+/', '', $s);
        $s = str_replace('*', ' ', $s);
        // Drop any token with a digit in it: order ids, store numbers, phones.
        $s = implode(' ', array_filter(preg_split('/\s+/', $s), function ($t) { return !preg_match('/\d/', $t); }));
        $s = preg_replace('#\b(HTTPS?://)?WWW\.#', '', $s);
        $s = preg_replace('/\b\d{3}[-.\s]?\d{3}[-.\s]?\d{4}\b/', ' ', $s); // phone
        $s = preg_replace('/[#*]?\d[\d\-\/]*/', ' ', $s);                     // numbers, store #s, order ids
        $s = preg_replace('/\s+[A-Z]{2}$/', '', trim(preg_replace('/\s+/', ' ', $s))); // trailing state
        $s = trim(preg_replace('/[^A-Z0-9&+.\/\' ]/', ' ', $s));
        $s = preg_replace('/\s+/', ' ', $s);
        $words = array_slice(explode(' ', $s), 0, 3);
        return trim(implode(' ', $words)) ?: strtoupper(trim((string) $desc));
    }

    private static function isKnown($desc)
    {
        $u = ' ' . strtoupper($desc) . ' ';
        foreach (self::KNOWN as $k) {
            if (strpos($u, $k) !== false) {
                return true;
            }
        }
        return false;
    }

    private static function isIgnored($desc)
    {
        $u = strtoupper(preg_replace('/\s+/', ' ', $desc));
        foreach (self::IGNORE as $k) {
            if (strpos($u, $k) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * The analysis. Returns per-merchant monthly totals and the month's
     * new / jumped / active subscriptions.
     */
    public static function analyze(array $d, $month)
    {
        $m = [];       // key => ['months' => [ym => total], 'counts' => [ym => n], 'card' => [..], 'desc' => last desc, 'known' => bool]
        $firstYm = null;
        foreach ($d['charges'] as $c) {
            $amt = (float) $c['amount'];
            if ($amt <= 0 || self::isIgnored($c['desc'])) {
                continue;
            }
            $ym = substr($c['date'], 0, 7);
            if ($firstYm === null || $ym < $firstYm) {
                $firstYm = $ym;
            }
            $k = self::merchantKey($c['desc']);
            if (in_array($k, $d['ignored'], true)) {
                continue;
            }
            if (!isset($m[$k])) {
                $m[$k] = ['months' => [], 'counts' => [], 'amounts' => [], 'cards' => [], 'desc' => $c['desc'], 'known' => false, 'last_date' => ''];
            }
            $m[$k]['months'][$ym] = ($m[$k]['months'][$ym] ?? 0) + $amt;
            $m[$k]['counts'][$ym] = ($m[$k]['counts'][$ym] ?? 0) + 1;
            $m[$k]['amounts'][] = $amt;
            $m[$k]['cards'][$c['card']] = true;
            $m[$k]['known'] = $m[$k]['known'] || self::isKnown($c['desc']);
            if ($c['date'] >= $m[$k]['last_date']) {
                $m[$k]['last_date'] = $c['date'];
                $m[$k]['desc'] = $c['desc'];
            }
        }

        $prev = date('Y-m', strtotime($month . '-01 -1 month'));
        $sixAgo = date('Y-m', strtotime($month . '-01 -6 months'));
        $new = $jumped = $active = $stopped = [];

        foreach ($m as $k => $x) {
            ksort($x['months']);
            $months = array_keys($x['months']);
            $first = $months[0];
            $upTo = array_filter($x['months'], function ($v, $ym) use ($month) { return $ym <= $month; }, ARRAY_FILTER_USE_BOTH);
            $recent = array_filter($upTo, function ($v, $ym) use ($sixAgo) { return $ym > $sixAgo; }, ARRAY_FILTER_USE_BOTH);
            if (!$recent) {
                continue;
            }
            // Looks like a subscription: shows up in 2+ months, at most 2 charges a
            // month, and the monthly totals stay in a narrow band. Grocery stores
            // and Amazon fail the band test; Netflix passes it.
            $counts = array_intersect_key($x['counts'], $upTo);
            $maxPerMonth = $counts ? max($counts) : 0;
            $vals = array_values($recent);
            // Most charges land on the same price (within 5%).
            $amts = $x['amounts'];
            sort($amts);
            $mid = $amts[intdiv(count($amts), 2)];
            $near = count(array_filter($amts, function ($a) use ($mid) { return abs($a - $mid) <= 0.05 * $mid + 0.01; }));
            $samePrice = $near >= 0.6 * count($amts);
            $steady = count($vals) >= 3 && $maxPerMonth <= 2 && min($vals) > 0 && (max($vals) / min($vals)) <= 1.6 && $samePrice;
            // Brand new, unknown merchant: last month and this month, one charge each, same price.
            $twoInARow = count($x['months']) === 2 && $first === $prev && isset($x['months'][$month])
                && $maxPerMonth === 1 && abs($x['months'][$month] - $x['months'][$prev]) <= 0.05 * $x['months'][$prev] + 0.01;
            $isSub = $steady || $twoInARow || ($x['known'] && $maxPerMonth <= 3);
            if (!$isSub) {
                continue;
            }

            $thisMonth = $x['months'][$month] ?? null;
            $priorMonths = array_filter($x['months'], function ($v, $ym) use ($month) { return $ym < $month; }, ARRAY_FILTER_USE_BOTH);
            $row = [
                'key'   => $k,
                'desc'  => $x['desc'],
                'cards' => array_keys($x['cards']),
                'this'  => $thisMonth,
                'first' => $first,
                'last_date' => $x['last_date'],
                'history' => $x['months'],
            ];

            if ($thisMonth !== null) {
                $active[] = $row;
            } elseif (isset($x['months'][$prev]) && count($priorMonths) >= 2) {
                $row['last'] = $x['months'][$prev];
                $stopped[] = $row;
            }

            // New: first charge ever was this month (known merchants) or last month
            // and it charged again this month, and our data goes back far enough
            // to know it is really new.
            $haveHistory = $firstYm !== null && $firstYm < $first;
            if ($haveHistory && $thisMonth !== null && ($first === $month || ($first === $prev && !$x['known']))) {
                $new[] = $row;
                continue;
            }

            // Jumped: this month vs the usual (median of the last 3 months it charged).
            if ($thisMonth !== null && $priorMonths) {
                $last3 = array_slice(array_values($priorMonths), -3);
                sort($last3);
                $base = $last3[intdiv(count($last3), 2)];
                $diff = $thisMonth - $base;
                if ($diff >= max(3, 0.10 * $base)) {
                    $row['before'] = $base;
                    $row['diff'] = $diff;
                    $row['pct'] = $base > 0 ? round(100 * $diff / $base) : null;
                    $jumped[] = $row;
                }
            }
        }

        $byAmt = function ($a, $b) { return ($b['this'] ?? $b['last'] ?? 0) <=> ($a['this'] ?? $a['last'] ?? 0); };
        usort($new, $byAmt);
        usort($active, $byAmt);
        usort($stopped, $byAmt);
        usort($jumped, function ($a, $b) { return $b['diff'] <=> $a['diff']; });

        return [
            'new' => $new, 'jumped' => $jumped, 'active' => $active, 'stopped' => $stopped,
            'total' => array_sum(array_column($active, 'this')),
            'first_month' => $firstYm,
        ];
    }

    /** Months that have any data, newest first, and per-card coverage. */
    private static function coverage(array $d)
    {
        $cov = [];
        foreach ($d['charges'] as $c) {
            $ym = substr($c['date'], 0, 7);
            $cov[$c['card']][$ym] = ($cov[$c['card']][$ym] ?? 0) + 1;
        }
        foreach ($cov as &$x) {
            ksort($x);
        }
        return $cov;
    }

    public function index(Request $request)
    {
        $this->guard();
        $d = self::load();
        $cov = self::coverage($d);
        $allMonths = [];
        foreach ($cov as $x) {
            $allMonths += array_flip(array_keys($x));
        }
        krsort($allMonths);
        $allMonths = array_keys($allMonths);
        // Default: last full calendar month.
        $month = $request->input('month');
        if (!preg_match('/^\d{4}-\d{2}$/', (string) $month)) {
            $month = date('Y-m', strtotime('first day of last month'));
        }
        $r = self::analyze($d, $month);

        if ($request->input('format') === 'json') {
            return response()->json(['month' => $month, 'coverage' => $cov] + $r);
        }

        return view('report.subscription_watch', [
            'month' => $month,
            'r' => $r,
            'cards' => $d['cards'],
            'cov' => $cov,
            'months' => $allMonths,
            'imports' => array_slice(array_reverse($d['imports']), 0, 15),
            'ignored' => $d['ignored'],
        ]);
    }

    /**
     * Import charges. JSON body from the bot:
     *   {card, label?, owner?, from, to, source, charges: [{date, desc, amount}]}
     * amount > 0 = a charge, < 0 = a credit. Or a CSV file upload from the page.
     */
    public function import(Request $request)
    {
        $this->guard();
        $d = self::load();

        $card = preg_replace('/\D/', '', (string) $request->input('card'));
        if (strlen($card) !== 4) {
            return $this->back($request, 0, 'Card must be the last 4 digits.');
        }
        if ($request->input('label')) {
            $d['cards'][$card] = ['label' => mb_substr(strip_tags($request->input('label')), 0, 80), 'owner' => 'Business'];
        } elseif (!isset($d['cards'][$card])) {
            return $this->back($request, 0, 'Unknown card. Business cards only; add it with a name first.');
        }

        if ($request->hasFile('csv')) {
            $rows = self::parseCsv(file_get_contents($request->file('csv')->getRealPath()));
            $source = 'CSV ' . mb_substr($request->file('csv')->getClientOriginalName(), 0, 60);
        } else {
            $rows = [];
            foreach ((array) $request->input('charges', []) as $c) {
                $date = date('Y-m-d', strtotime((string) ($c['date'] ?? '')));
                if (!isset($c['desc'], $c['amount']) || $date < '2000-01-01') {
                    continue;
                }
                $rows[] = ['date' => $date, 'desc' => mb_substr(trim($c['desc']), 0, 120), 'amount' => round((float) $c['amount'], 2)];
            }
            $source = mb_substr((string) $request->input('source', 'bot'), 0, 80);
        }
        if (!$rows) {
            return $this->back($request, 0, 'No charges found in that import.');
        }

        $dates = array_column($rows, 'date');
        $from = $request->input('from') ?: min($dates);
        $to = $request->input('to') ?: max($dates);
        $from = date('Y-m-d', strtotime($from));
        $to = date('Y-m-d', strtotime($to));

        // Replace this card's charges inside the range, keep everything else.
        $d['charges'] = array_values(array_filter($d['charges'], function ($c) use ($card, $from, $to) {
            return !($c['card'] === $card && $c['date'] >= $from && $c['date'] <= $to);
        }));
        foreach ($rows as $r) {
            if ($r['date'] >= $from && $r['date'] <= $to) {
                $d['charges'][] = ['card' => $card] + $r;
            }
        }
        usort($d['charges'], function ($a, $b) { return strcmp($a['date'], $b['date']); });
        $d['imports'][] = ['at' => date('Y-m-d H:i'), 'card' => $card, 'from' => $from, 'to' => $to,
            'rows' => count($rows), 'source' => $source, 'by' => auth()->user()->first_name];
        $d['imports'] = array_slice($d['imports'], -200);
        self::save($d);

        return $this->back($request, 1, count($rows) . ' charges imported for ' . $d['cards'][$card]['label'] . ' (' . date('m/d/y', strtotime($from)) . ' to ' . date('m/d/y', strtotime($to)) . ').');
    }

    /** Hide a merchant that is not really a subscription (or bring it back). */
    public function ignore(Request $request)
    {
        $this->guard();
        $d = self::load();
        $k = (string) $request->input('key');
        if ($request->input('undo')) {
            $d['ignored'] = array_values(array_diff($d['ignored'], [$k]));
        } elseif ($k !== '' && !in_array($k, $d['ignored'], true)) {
            $d['ignored'][] = $k;
        }
        self::save($d);
        return redirect()->back();
    }

    private function back(Request $request, $ok, $msg)
    {
        if ($request->expectsJson() || $request->isJson()) {
            return response()->json(['success' => $ok, 'msg' => $msg], $ok ? 200 : 422);
        }
        return redirect()->back()->with('status', ['success' => $ok, 'msg' => $msg]);
    }

    /**
     * Any bank's CSV. Finds the date, description and amount columns by header.
     * Chase: charges negative. Citi: Debit / Credit columns. Barclays: charges
     * negative. Returned amounts: charge positive.
     */
    public static function parseCsv($text)
    {
        $lines = preg_split('/\r\n|\n|\r/', trim($text));
        $hdrIdx = null;
        foreach ($lines as $i => $l) {
            if (preg_match('/date/i', $l) && preg_match('/desc|merchant|payee/i', $l)) {
                $hdrIdx = $i;
                break;
            }
        }
        if ($hdrIdx === null) {
            return [];
        }
        $h = array_map(function ($x) { return strtolower(trim($x, " \"\t")); }, str_getcsv($lines[$hdrIdx]));
        $find = function ($pats) use ($h) {
            foreach ($pats as $p) {
                foreach ($h as $i => $name) {
                    if (preg_match($p, $name)) {
                        return $i;
                    }
                }
            }
            return null;
        };
        $iDate = $find(['/^transaction date/', '/^trans\.? date/', '/^date/', '/date/']);
        $iDesc = $find(['/^description/', '/merchant/', '/desc/', '/payee/']);
        $iAmt = $find(['/^amount/', '/amount/']);
        $iDebit = $find(['/^debit/']);
        $iCredit = $find(['/^credit/']);
        $iType = $find(['/^type$/']);

        $raw = [];
        foreach (array_slice($lines, $hdrIdx + 1) as $l) {
            $f = str_getcsv($l);
            if (count($f) < 2 || $iDate === null || $iDesc === null || !isset($f[$iDate], $f[$iDesc])) {
                continue;
            }
            $ts = strtotime(trim($f[$iDate]));
            if (!$ts) {
                continue;
            }
            $num = function ($v) { return (float) str_replace([',', '$', ' '], '', (string) $v); };
            if ($iDebit !== null) {
                $amt = abs($num($f[$iDebit] ?? 0)) > 0 ? abs($num($f[$iDebit])) : -abs($num($f[$iCredit] ?? 0));
            } elseif ($iAmt !== null) {
                $amt = $num($f[$iAmt]);
            } else {
                continue;
            }
            $raw[] = ['date' => date('Y-m-d', $ts), 'desc' => trim($f[$iDesc]), 'amount' => $amt,
                'type' => $iType !== null ? strtolower($f[$iType] ?? '') : ''];
        }
        if ($iDebit === null) {
            // Which sign is a charge? Chase marks Type=Sale; otherwise the majority sign.
            $neg = count(array_filter($raw, function ($r) { return $r['amount'] < 0; }));
            $flip = $neg > count($raw) / 2;
            foreach ($raw as &$r) {
                if ($r['type'] === 'sale') {
                    $r['amount'] = abs($r['amount']);
                } elseif ($flip) {
                    $r['amount'] = -$r['amount'];
                }
            }
            unset($r);
        }
        return array_map(function ($r) {
            return ['date' => $r['date'], 'desc' => mb_substr($r['desc'], 0, 120), 'amount' => round($r['amount'], 2)];
        }, $raw);
    }
}
