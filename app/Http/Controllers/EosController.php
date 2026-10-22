<?php

namespace App\Http\Controllers;

use App\Utils\BusinessUtil;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * EOS (Entrepreneurial Operating System) — the leadership team's one page for
 * running the business the EOS way (Sarah 2026-10-22):
 *
 *   Vision     the V/TO (core values, core focus, 10-year target, marketing
 *              strategy, 3-year picture, 1-year plan)
 *   Seats      the Accountability Chart (seat, who sits in it, 5 roles)
 *   Rocks      90-day priorities per owner, on/off track
 *   Scorecard  weekly numbers with a goal; store sales fill themselves in
 *   Issues     the Issues List (IDS: identify, discuss, solve)
 *   To-dos     7-day action items out of the weekly meeting
 *   L10        the weekly Level 10 meeting agenda, pulling all of the above,
 *              plus a place to record the meeting rating
 *
 * Access: admins (Jon, Sarah) and the store managers (Luis, Zakary).
 * No migration: storage/app/eos.json. Nothing is hard-deleted; Rocks get
 * marked done/dropped, Issues solved, To-dos done, so history stays.
 */
class EosController extends Controller
{
    const STORE_PATH = 'eos.json';

    const TABS = [
        'l10'       => 'Weekly L10',
        'scorecard' => 'Scorecard',
        'rocks'     => 'Rocks',
        'issues'    => 'Issues',
        'todos'     => 'To-dos',
        'seats'     => 'Accountability Chart',
        'vision'    => 'Vision (V/TO)',
    ];

    // V/TO questions, in EOS order. Key => [label, hint].
    const VTO = [
        'core_values'     => ['Core Values', '3 to 7 rules for who we hire, review and keep'],
        'purpose'         => ['Core Focus: Purpose / Cause / Passion', 'Why we exist, beyond making money'],
        'niche'           => ['Core Focus: Niche', 'What we do better than anyone'],
        'ten_year'        => ['10-Year Target', 'One big, long-range goal'],
        'target_market'   => ['Marketing Strategy: Target Market', 'Who our ideal customer is and where they are'],
        'uniques'         => ['Marketing Strategy: 3 Uniques', 'Three things that set us apart'],
        'proven_process'  => ['Marketing Strategy: Proven Process', 'How a customer experiences us, step by step'],
        'guarantee'       => ['Marketing Strategy: Guarantee', 'Our promise to the customer'],
        'three_year'      => ['3-Year Picture', 'Date, revenue, profit, measurables, and what it looks like'],
        'one_year'        => ['1-Year Plan', 'Date, revenue, profit, and 3 to 7 goals for the year'],
    ];

    // L10 agenda, in order. Key => [label, minutes].
    const AGENDA = [
        'segue'     => ['Segue: one personal and one business good news', 5],
        'scorecard' => ['Scorecard: on or off track, anything off goes to Issues', 5],
        'rocks'     => ['Rock review: on or off track', 5],
        'headlines' => ['Customer and employee headlines', 5],
        'todos'     => ['To-do list: done or not done', 5],
        'ids'       => ['IDS: identify, discuss, solve the top issues', 60],
        'conclude'  => ['Conclude: recap to-dos, messages to send, rate the meeting 1 to 10', 5],
    ];

    // Scorecard numbers the ERP can fill in itself.
    const AUTO = [
        'sales_hw'   => 'Hollywood store sales (auto)',
        'sales_pico' => 'Pico store sales (auto)',
    ];

    const WEEKS_SHOWN = 13;

    private function guard()
    {
        $u = auth()->user();
        $admin = $u && app(BusinessUtil::class)->is_admin($u);
        if (!$admin && !ManagerChecklistController::currentManagerKey()) {
            abort(403, 'EOS is for the leadership team (admins and store managers).');
        }
    }

    private static function blank()
    {
        return [
            'vto'          => array_fill_keys(array_keys(self::VTO), ''),
            // Starting chart from who does what today; edit freely.
            'seats'        => [
                ['id' => 's1', 'seat' => 'Visionary', 'person' => 'Jon', 'roles' => "Big ideas and long-range direction\nKey relationships (labels, distributors, artists)\nCulture\nNew locations\nBig buys"],
                ['id' => 's2', 'seat' => 'Integrator', 'person' => 'Sarah', 'roles' => "Runs the leadership team and the weekly L10\nHolds everyone to their Rocks and numbers\nProfit and loss results\nERP, website and systems\nRemoves obstacles between seats"],
                ['id' => 's3', 'seat' => 'Hollywood Store Manager', 'person' => 'Luis', 'roles' => "Hollywood sales\nStaff, schedule and weekly check-ins\nStore standards and openings/closings\nBuying from customers at the counter"],
                ['id' => 's4', 'seat' => 'Pico Store Manager', 'person' => 'Zakary', 'roles' => "Pico sales\nStaff, schedule and weekly check-ins\nStore standards and openings/closings\nBuying from customers at the counter"],
                ['id' => 's5', 'seat' => 'Purchasing and Receiving', 'person' => 'Clarissa', 'roles' => "Weekly reorder\nIncoming mail and distributor deliveries\nShelf counts"],
                ['id' => 's6', 'seat' => 'Fulfillment', 'person' => 'Nick', 'roles' => "Website orders picked, packed and shipped\nPickups ready on time"],
                ['id' => 's7', 'seat' => 'Customer Service and Bookings', 'person' => 'Fatteen', 'roles' => "Phones and texts answered\nOrder follow-up\nVenue bookings and collabs"],
                ['id' => 's8', 'seat' => 'Finance', 'person' => 'Sabina', 'roles' => "Books and monthly close review\nP&L review"],
            ],
            'rocks'        => [],
            'measurables'  => [
                ['id' => 'm1', 'name' => self::AUTO['sales_hw'], 'owner' => 'Luis', 'goal' => '', 'dir' => 'min', 'auto' => 'sales_hw', 'money' => 1],
                ['id' => 'm2', 'name' => self::AUTO['sales_pico'], 'owner' => 'Zakary', 'goal' => '', 'dir' => 'min', 'auto' => 'sales_pico', 'money' => 1],
            ],
            'values'       => [],
            'issues'       => [],
            'todos'        => [],
            'meetings'     => [],
        ];
    }

    private static function load()
    {
        $d = Storage::exists(self::STORE_PATH) ? json_decode(Storage::get(self::STORE_PATH), true) : null;
        $d = is_array($d) ? $d : [];
        return array_merge(self::blank(), $d);
    }

    private static function save(array $d)
    {
        Storage::put(self::STORE_PATH, json_encode($d, JSON_PRETTY_PRINT));
    }

    private static function newId($prefix)
    {
        return $prefix . substr(md5(uniqid('', true)), 0, 8);
    }

    private static function who()
    {
        $u = auth()->user();
        return trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? ''));
    }

    /** Monday of the week containing $date, Y-m-d. */
    public static function weekStart($date = null)
    {
        $t = $date ? strtotime($date) : time();
        return date('Y-m-d', strtotime('monday this week', $t));
    }

    /** The last WEEKS_SHOWN week starts, newest first. */
    private static function weeks()
    {
        $out = [];
        $start = strtotime(self::weekStart());
        for ($i = 0; $i < self::WEEKS_SHOWN; $i++) {
            $out[] = date('Y-m-d', strtotime("-{$i} week", $start));
        }
        return $out;
    }

    /** Quarter label like "Q4 2026". */
    public static function quarter($date = null)
    {
        $t = $date ? strtotime($date) : time();
        return 'Q' . (int) ceil(date('n', $t) / 3) . ' ' . date('Y', $t);
    }

    /** Final sales per week for one store, keyed by week start. */
    private function autoSales($businessId, $storeLike, array $weeks)
    {
        $from = end($weeks) . ' 00:00:00';
        $to = date('Y-m-d', strtotime(reset($weeks) . ' +6 days')) . ' 23:59:59';
        $rows = DB::table('transactions as t')
            ->join('business_locations as bl', 'bl.id', '=', 't.location_id')
            ->where('t.business_id', $businessId)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->where('bl.name', 'like', $storeLike)
            ->whereBetween('t.transaction_date', [$from, $to])
            ->select('t.transaction_date', 't.final_total')
            ->get();
        $out = array_fill_keys($weeks, 0.0);
        foreach ($rows as $r) {
            $w = self::weekStart($r->transaction_date);
            if (isset($out[$w])) {
                $out[$w] += (float) $r->final_total;
            }
        }
        return $out;
    }

    public function index(Request $request)
    {
        $this->guard();
        $tab = isset(self::TABS[$request->input('tab')]) ? $request->input('tab') : 'l10';
        $d = self::load();
        $weeks = self::weeks();
        $businessId = $request->session()->get('user.business_id');

        // Fill auto measurables live; manual ones come from saved values.
        $values = $d['values'];
        foreach ($d['measurables'] as $m) {
            if (!empty($m['auto'])) {
                $like = $m['auto'] === 'sales_pico' ? '%pico%' : '%hollywood%';
                foreach ($this->autoSales($businessId, $like, $weeks) as $w => $v) {
                    $values[$m['id']][$w] = round($v, 2);
                }
            }
        }

        $people = array_values(array_unique(array_filter(array_map(function ($s) {
            return trim($s['person']);
        }, $d['seats']))));

        return view('eos.index', [
            'tab'      => $tab,
            'd'        => $d,
            'values'   => $values,
            'weeks'    => $weeks,
            'people'   => $people,
            'quarter'  => self::quarter(),
            'thisWeek' => self::weekStart(),
        ]);
    }

    /** One POST for every edit on the page, picked by `do`. */
    public function update(Request $request)
    {
        $this->guard();
        $d = self::load();
        $do = (string) $request->input('do');
        $in = function ($k) use ($request) {
            return trim((string) $request->input($k, ''));
        };
        $tab = 'l10';
        $msg = 'Saved.';

        switch ($do) {
            case 'vto':
                foreach (array_keys(self::VTO) as $k) {
                    $d['vto'][$k] = $in('vto_' . $k);
                }
                $d['vto_updated'] = date('Y-m-d H:i') . ' by ' . self::who();
                $tab = 'vision';
                break;

            case 'seat_save':
                $id = $in('id') ?: self::newId('s');
                $row = ['id' => $id, 'seat' => $in('seat'), 'person' => $in('person'), 'roles' => $in('roles')];
                if ($row['seat'] === '') {
                    return back()->with('eos_warn', 'Give the seat a name.');
                }
                $found = false;
                foreach ($d['seats'] as &$s) {
                    if ($s['id'] === $id) {
                        $s = $row;
                        $found = true;
                    }
                }
                unset($s);
                if (!$found) {
                    $d['seats'][] = $row;
                }
                $tab = 'seats';
                break;

            case 'seat_remove':
                $d['seats'] = array_values(array_filter($d['seats'], function ($s) use ($in) {
                    return $s['id'] !== $in('id');
                }));
                $tab = 'seats';
                $msg = 'Seat removed.';
                break;

            case 'rock_add':
                if ($in('title') === '') {
                    return back()->with('eos_warn', 'Write the Rock first.');
                }
                $d['rocks'][] = [
                    'id' => self::newId('r'), 'title' => $in('title'), 'owner' => $in('owner'),
                    'quarter' => $in('quarter') ?: self::quarter(), 'company' => $request->boolean('company'),
                    'status' => 'on', 'created' => date('Y-m-d'),
                ];
                $tab = 'rocks';
                $msg = 'Rock added.';
                break;

            case 'rock_status':
                foreach ($d['rocks'] as &$r) {
                    if ($r['id'] === $in('id') && in_array($in('status'), ['on', 'off', 'done', 'dropped'], true)) {
                        $r['status'] = $in('status');
                        $r['status_at'] = date('Y-m-d');
                    }
                }
                unset($r);
                $tab = $in('back') ?: 'rocks';
                break;

            case 'measurable_save':
                $id = $in('id') ?: self::newId('m');
                $old = null;
                foreach ($d['measurables'] as $m) {
                    if ($m['id'] === $id) {
                        $old = $m;
                    }
                }
                $row = [
                    'id' => $id,
                    'name' => $old && !empty($old['auto']) ? $old['name'] : $in('name'),
                    'owner' => $in('owner'),
                    'goal' => $in('goal'),
                    'dir' => $in('dir') === 'max' ? 'max' : 'min',
                    'auto' => $old['auto'] ?? null,
                    'money' => $request->boolean('money') ? 1 : 0,
                ];
                if ($row['name'] === '') {
                    return back()->with('eos_warn', 'Name the number first.');
                }
                if ($old) {
                    foreach ($d['measurables'] as &$m) {
                        if ($m['id'] === $id) {
                            $m = $row;
                        }
                    }
                    unset($m);
                } else {
                    $d['measurables'][] = $row;
                }
                $tab = 'scorecard';
                break;

            case 'measurable_remove':
                $d['measurables'] = array_values(array_filter($d['measurables'], function ($m) use ($in) {
                    return $m['id'] !== $in('id');
                }));
                $tab = 'scorecard';
                $msg = 'Number removed from the Scorecard.';
                break;

            case 'values':
                foreach ((array) $request->input('v', []) as $mid => $byWeek) {
                    foreach ((array) $byWeek as $w => $val) {
                        $val = trim(str_replace([',', '$', '%'], '', (string) $val));
                        if ($val === '') {
                            unset($d['values'][$mid][$w]);
                        } elseif (is_numeric($val)) {
                            $d['values'][$mid][$w] = (float) $val;
                        }
                    }
                }
                $tab = $in('back') ?: 'scorecard';
                $msg = 'Scorecard saved.';
                break;

            case 'issue_add':
                if ($in('title') === '') {
                    return back()->with('eos_warn', 'Write the issue first.');
                }
                $d['issues'][] = [
                    'id' => self::newId('i'), 'title' => $in('title'), 'owner' => $in('owner'),
                    'notes' => $in('notes'), 'status' => 'open', 'created' => date('Y-m-d'), 'by' => self::who(),
                ];
                $tab = $in('back') ?: 'issues';
                $msg = 'Issue added.';
                break;

            case 'issue_solve':
                foreach ($d['issues'] as &$i) {
                    if ($i['id'] === $in('id')) {
                        $i['status'] = 'solved';
                        $i['solved_at'] = date('Y-m-d');
                        $i['solution'] = $in('solution');
                    }
                }
                unset($i);
                // A solve usually needs someone to do something.
                if ($in('todo') !== '') {
                    $d['todos'][] = [
                        'id' => self::newId('t'), 'title' => $in('todo'), 'owner' => $in('todo_owner'),
                        'due' => date('Y-m-d', strtotime('+7 days')), 'done' => false, 'created' => date('Y-m-d'),
                    ];
                }
                $tab = $in('back') ?: 'issues';
                $msg = 'Issue solved.';
                break;

            case 'issue_reopen':
                foreach ($d['issues'] as &$i) {
                    if ($i['id'] === $in('id')) {
                        $i['status'] = 'open';
                    }
                }
                unset($i);
                $tab = 'issues';
                break;

            case 'todo_add':
                if ($in('title') === '') {
                    return back()->with('eos_warn', 'Write the to-do first.');
                }
                $d['todos'][] = [
                    'id' => self::newId('t'), 'title' => $in('title'), 'owner' => $in('owner'),
                    'due' => $in('due') ?: date('Y-m-d', strtotime('+7 days')), 'done' => false, 'created' => date('Y-m-d'),
                ];
                $tab = $in('back') ?: 'todos';
                $msg = 'To-do added.';
                break;

            case 'todo_toggle':
                foreach ($d['todos'] as &$t) {
                    if ($t['id'] === $in('id')) {
                        $t['done'] = !$t['done'];
                        $t['done_at'] = $t['done'] ? date('Y-m-d') : null;
                    }
                }
                unset($t);
                $tab = $in('back') ?: 'todos';
                break;

            case 'meeting':
                $ratings = [];
                foreach ((array) $request->input('rating', []) as $name => $score) {
                    if ($score !== null && $score !== '' && is_numeric($score)) {
                        $ratings[$name] = max(1, min(10, (int) $score));
                    }
                }
                $d['meetings'][] = [
                    'date' => $in('date') ?: date('Y-m-d'), 'ratings' => $ratings,
                    'headlines' => $in('headlines'), 'notes' => $in('notes'), 'by' => self::who(),
                ];
                $tab = 'l10';
                $msg = 'Meeting saved.';
                break;

            default:
                return back()->with('eos_warn', 'Nothing to save.');
        }

        self::save($d);
        return redirect(url('/eos') . '?tab=' . urlencode($tab))->with('eos_ok', $msg);
    }
}
