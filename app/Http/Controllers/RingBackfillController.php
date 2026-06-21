<?php

namespace App\Http\Controllers;

use App\Contact;
use App\Utils\BusinessUtil;
use App\Utils\ProductUtil;
use App\Utils\TransactionUtil;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

// One-off, guarded back-fill for Alec's 103-item / "$705" cash sale at Pico on
// 2026-06-20 7:23pm that failed to ring (max_input_vars 500 before the fix), so
// no transaction was written and no stock decremented. Re-creates the sale via
// the app's own logic. Preview at /admin/ring-backfill; Apply snapshots +
// creates a final sale; Undo via /admin/admin-action-history (deleteSale).
class RingBackfillController extends Controller
{
    const TAG = 'RING-BACKFILL:alec-pico-2026-06-20';
    const EXPECTED_TOTAL = 705.00;
    const SALE_DATETIME = '2026-06-20T19:23';

    protected $transactionUtil;
    protected $productUtil;
    protected $businessUtil;

    public function __construct(TransactionUtil $transactionUtil, ProductUtil $productUtil, BusinessUtil $businessUtil)
    {
        $this->transactionUtil = $transactionUtil;
        $this->productUtil = $productUtil;
        $this->businessUtil = $businessUtil;
    }

    // The order, parsed from Alec's text doc. Barcoded lines carry a `sku`
    // (matched against variations.sub_sku / products.sku); hand-typed lines set
    // `manual => true`. `qty` = line qty; `price` = per-unit (tax-inclusive).
    private function order(): array
    {
        return [
            ['sku' => '124247', 'name' => 'Sérgio Mendes - Sergio Mendes', 'qty' => 1, 'price' => 8],
            ['sku' => 'AFL1-4383', 'name' => 'Daryl Hall & John Oates - H₂O', 'qty' => 1, 'price' => 5],
            ['sku' => '21591', 'name' => "Laid Back - One Life / It's The Way You Do It", 'qty' => 1, 'price' => 20],
            ['sku' => '124342', 'name' => 'Supertramp - Paris', 'qty' => 1, 'price' => 11],
            ['sku' => '49937', 'name' => 'ABC - BE NEAR ME', 'qty' => 1, 'price' => 11],
            ['sku' => '49704', 'name' => 'CURIOSITY KILLED THE CAT - MISFIT', 'qty' => 1, 'price' => 5],
            ['sku' => 'AD1-9837', 'name' => "Exposé - What You Don't Know", 'qty' => 1, 'price' => 5],
            ['sku' => '126699', 'name' => 'Heart - Heart', 'qty' => 1, 'price' => 5],
            ['sku' => '126878', 'name' => 'Snap! - World Power', 'qty' => 1, 'price' => 5],
            ['sku' => '126694', 'name' => "Snap! - The Madman's Return", 'qty' => 1, 'price' => 5],
            ['sku' => '126590', 'name' => 'KISS - Ikons', 'qty' => 1, 'price' => 20],
            ['sku' => '126576', 'name' => 'The Cranberries - No Need To Argue', 'qty' => 1, 'price' => 5],
            ['sku' => '126884', 'name' => 'Culture Beat - Serenity', 'qty' => 1, 'price' => 5],
            ['sku' => '126586', 'name' => 'The Police - Ghost In The Machine', 'qty' => 1, 'price' => 5],
            ['sku' => '126596', 'name' => 'War - The Best Of War… And More', 'qty' => 1, 'price' => 5],
            ['sku' => '57541', 'name' => 'U2 - ONE', 'qty' => 2, 'price' => 30],
            ['sku' => '126907', 'name' => "Yazoo - Upstairs At Eric's", 'qty' => 1, 'price' => 7],
            ['sku' => '58172', 'name' => 'SCORPIONS - THE BEST OF', 'qty' => 1, 'price' => 6],
            ['sku' => '56248', 'name' => 'ROLLING STONES - FORTY LICKS', 'qty' => 1, 'price' => 5],
            ['sku' => '56792', 'name' => 'SILVERCHAIR - FREAK SHOW', 'qty' => 1, 'price' => 5],
            ['sku' => '56375', 'name' => 'RICK SPRINGFIELD - GREATEST HITS', 'qty' => 1, 'price' => 4],
            ['sku' => '58168', 'name' => 'CAT STEVENS - GOLD', 'qty' => 1, 'price' => 6],
            ['sku' => '56910', 'name' => 'STING & THE POLICE - THE VERY BEST OF', 'qty' => 1, 'price' => 5],
            ['sku' => '56881', 'name' => 'STARSHIP - KNEE DEEP IN HOOPLA', 'qty' => 1, 'price' => 5],
            ['sku' => '57211', 'name' => 'STRAY CATS - RUNAWAY BOYS', 'qty' => 1, 'price' => 5],
            ['sku' => '58128', 'name' => 'THE WHO - REGGATTA DE BLANC', 'qty' => 1, 'price' => 6],
            ['sku' => '56442', 'name' => 'JOE SATRIANI - SURFING WITH THE ALIEN', 'qty' => 1, 'price' => 6],
            ['sku' => '56440', 'name' => 'JOE SATRIANI - TIME MACHINE', 'qty' => 1, 'price' => 5],
            ['sku' => '56445', 'name' => 'JOE SATRIANI - FLYING IN A BLUE', 'qty' => 1, 'price' => 4],
            ['sku' => '126718', 'name' => 'Haddaway - Haddaway', 'qty' => 1, 'price' => 5],
            ['sku' => '54275', 'name' => "GO GO'S - GREATEST", 'qty' => 1, 'price' => 5],
            ['sku' => '126880', 'name' => "Dr. Alban - It's My Life", 'qty' => 1, 'price' => 5],
            ['sku' => '58054', 'name' => 'PEARL JAM - VITALOGY', 'qty' => 1, 'price' => 5],
            ['sku' => '58051', 'name' => 'GEORGE MICHAEL - LADIES & GENTLEMEN', 'qty' => 1, 'price' => 7],
            ['sku' => '124865', 'name' => 'Air Supply - The Very Best Of', 'qty' => 1, 'price' => 6],
            ['sku' => '51854', 'name' => 'BEE GEES - GREATEST HITS', 'qty' => 1, 'price' => 5],
            ['sku' => '51848', 'name' => 'THE BEATLES - LOVE', 'qty' => 1, 'price' => 7],
            ['sku' => '51844', 'name' => 'THE BEATLES - LIVE AT THE BBC', 'qty' => 1, 'price' => 6],
            ['sku' => '124689', 'name' => 'Antrax - kings among scotland', 'qty' => 1, 'price' => 12],
            ['sku' => '124624', 'name' => 'Pixies - tromp le monde', 'qty' => 1, 'price' => 7],
            ['sku' => '55108', 'name' => 'KEANE - HOPE AND FEARS', 'qty' => 1, 'price' => 4],
            ['sku' => '55195', 'name' => 'KISS - HOTTER THAN HELL', 'qty' => 1, 'price' => 6],
            ['sku' => '55192', 'name' => 'THE KNACK - GET THE KNACK', 'qty' => 1, 'price' => 5],
            ['manual' => true, 'name' => 'dummy', 'artist' => 'various', 'category' => 'Cassettes', 'sub_category' => 'Rock', 'qty' => 1, 'price' => 5],
            ['sku' => '127626', 'name' => 'Earth, Wind & Fire - The Essential', 'qty' => 1, 'price' => 7],
            ['sku' => '56866', 'name' => 'CAT STEVENS - GREATEST HITS', 'qty' => 1, 'price' => 5],
            ['sku' => '55198', 'name' => 'KISS - LICK IT UP', 'qty' => 1, 'price' => 6],
            ['sku' => '55451', 'name' => 'RICHARD MARX - STORIES TO TELL', 'qty' => 1, 'price' => 8],
            ['sku' => '56049', 'name' => 'MOTLEY CRUE - THE BEST OF', 'qty' => 1, 'price' => 5],
            ['sku' => '56018', 'name' => 'MORRISSEY - KILL UNCLE', 'qty' => 1, 'price' => 5],
            ['sku' => '56020', 'name' => 'MORRISSEY - MALADJUSTED', 'qty' => 1, 'price' => 5],
            ['sku' => '55984', 'name' => 'MEN AT WORK - THE WORKS', 'qty' => 1, 'price' => 5],
            ['sku' => '55935', 'name' => 'METALLICA - HARDWIRED...TO SELF-DESTRUCT', 'qty' => 1, 'price' => 10],
            ['sku' => '55959', 'name' => 'GEORGE MICHAEL AND QUEEN - FIVE LIVE', 'qty' => 1, 'price' => 5],
            ['sku' => '55033', 'name' => 'GRAND FUNK - LIVE ALBUM', 'qty' => 1, 'price' => 5],
            ['sku' => '9362-49887-9', 'name' => 'Disturbed - Indestructible', 'qty' => 1, 'price' => 5],
            ['sku' => '52044', 'name' => 'THE BLACK CROWS - SHAKE YOUR MONEY MAKER', 'qty' => 1, 'price' => 5],
            ['sku' => '52060', 'name' => 'BLINK 182 - DUDE RANCH', 'qty' => 1, 'price' => 5],
            ['sku' => '52159', 'name' => 'CARPENTERS - GOLD', 'qty' => 1, 'price' => 7],
            ['sku' => '124863', 'name' => 'Cinderella (3) - Long Cold Winter', 'qty' => 1, 'price' => 6],
            ['sku' => '52343', 'name' => 'CIRCLE JERKS - ODDITIES ABNORMALITIES AND CURIOSITIES', 'qty' => 1, 'price' => 5],
            ['sku' => '58038', 'name' => 'THE CURE - STARING AT THE SEA', 'qty' => 1, 'price' => 5],
            ['sku' => '54989', 'name' => "MADONNA - DON'T TELL ME", 'qty' => 1, 'price' => 5],
            ['sku' => '126030', 'name' => "Janis Joplin - Janis Joplin's Greatest Hits", 'qty' => 1, 'price' => 3],
            ['sku' => '55087', 'name' => 'JOURNEY - GREATEST HITS', 'qty' => 1, 'price' => 2],
            ['manual' => true, 'name' => 'get a grip', 'artist' => 'areaosmith', 'category' => 'Used CD', 'sub_category' => 'Rock', 'qty' => 1, 'price' => 4],
            ['manual' => true, 'name' => 'master of reaility', 'artist' => 'black sabath', 'category' => 'Used CD', 'sub_category' => 'Rock', 'qty' => 1, 'price' => 5],
            ['manual' => true, 'name' => 'this that', 'artist' => 'michael penn', 'category' => 'Used CD', 'sub_category' => 'Rock', 'qty' => 1, 'price' => 5],
            ['manual' => true, 'name' => 'rejoice', 'artist' => 'emotion', 'category' => 'Used CD', 'sub_category' => 'Rock', 'qty' => 1, 'price' => 5],
            ['manual' => true, 'name' => 'how me love', 'artist' => 'robin', 'category' => 'Used CD', 'sub_category' => 'Rock', 'qty' => 1, 'price' => 4],
            ['manual' => true, 'name' => 'storyteller', 'artist' => 'cry', 'category' => 'Used CD', 'sub_category' => 'Rock', 'qty' => 1, 'price' => 3],
            ['manual' => true, 'name' => 'cheryll lynn', 'artist' => '', 'category' => 'Used CD', 'sub_category' => 'Rock', 'qty' => 1, 'price' => 6],
            ['manual' => true, 'name' => 'song by aimee mann', 'artist' => '', 'category' => 'Used CD', 'sub_category' => 'Rock', 'qty' => 1, 'price' => 3],
            ['manual' => true, 'name' => 'beck', 'artist' => '', 'category' => 'Used CD', 'sub_category' => 'Rock', 'qty' => 1, 'price' => 2],
            ['manual' => true, 'name' => 'every breath you take', 'artist' => 'the police', 'category' => 'Used CD', 'sub_category' => 'Rock', 'qty' => 1, 'price' => 2],
            ['manual' => true, 'name' => 'greatest hits', 'artist' => 'poison', 'category' => 'Used CD', 'sub_category' => 'Rock', 'qty' => 1, 'price' => 2],
            ['manual' => true, 'name' => 'greatet hits', 'artist' => 'queen', 'category' => 'Used CD', 'sub_category' => 'Rock', 'qty' => 1, 'price' => 4],
            ['manual' => true, 'name' => 'at the bbc', 'artist' => 'qeen', 'category' => 'Used CD', 'sub_category' => 'Rock', 'qty' => 1, 'price' => 3],
            ['manual' => true, 'name' => 'methods of silence', 'artist' => 'change', 'category' => 'Used CD', 'sub_category' => 'Rock', 'qty' => 1, 'price' => 3],
            ['manual' => true, 'name' => 'voices & images', 'artist' => 'camouflage', 'category' => 'Used CD', 'sub_category' => 'Rock', 'qty' => 1, 'price' => 2],
            ['manual' => true, 'name' => 'cause & effect', 'artist' => 'another minute', 'category' => 'Used CD', 'sub_category' => 'Rock', 'qty' => 1, 'price' => 2],
            ['manual' => true, 'name' => 'the definitive collection', 'artist' => 'stevie wonder', 'category' => 'Used CD', 'sub_category' => 'R&B', 'qty' => 1, 'price' => 3],
            ['manual' => true, 'name' => 'no parking on the dance floor', 'artist' => 'midnight strar', 'category' => 'Used CD', 'sub_category' => 'R&B', 'qty' => 1, 'price' => 8],
            ['manual' => true, 'name' => 'we will rock you', 'artist' => 'queen', 'category' => 'Used CD', 'sub_category' => 'Rock', 'qty' => 1, 'price' => 3],
            ['manual' => true, 'name' => 'tempermantal', 'artist' => 'everything but the girl', 'category' => 'Used CD', 'sub_category' => 'Electronic/Dance', 'qty' => 1, 'price' => 3],
            ['manual' => true, 'name' => 'finest hour', 'artist' => "antonio carlos jobim's", 'category' => 'Used CD', 'sub_category' => 'Latin', 'qty' => 1, 'price' => 4],
            ['manual' => true, 'name' => 'immortal', 'artist' => 'michael jackson', 'category' => 'Used CD', 'sub_category' => 'Pop', 'qty' => 1, 'price' => 8],
            ['sku' => '56032', 'name' => 'VARIOUS - DISCOUNT BIN ($3)', 'qty' => 3, 'price' => 3],
            ['manual' => true, 'name' => 'we built this city', 'artist' => 'starhsip', 'category' => 'Used Vinyl', 'sub_category' => 'new wave/post punk', 'qty' => 1, 'price' => 14],
            ['manual' => true, 'name' => 'loverboy', 'artist' => 'billy ocean', 'category' => 'Used Vinyl', 'sub_category' => 'R&B, Soul & Funk', 'qty' => 1, 'price' => 11],
            ['manual' => true, 'name' => 'when will i be famous', 'artist' => 'bros', 'category' => 'Used Vinyl', 'sub_category' => 'Rock', 'qty' => 1, 'price' => 10],
            ['manual' => true, 'name' => 'prove your love', 'artist' => 'taylor dayne', 'category' => 'Used Vinyl', 'sub_category' => 'Rock', 'qty' => 1, 'price' => 10],
            ['sku' => '56031', 'name' => 'VARIOUS - DISCOUNT BIN ($2)', 'qty' => 1, 'price' => 2],
            ['manual' => true, 'name' => 'jose feliciano', 'artist' => 'jose feliciano', 'category' => 'Used Vinyl', 'sub_category' => 'Latin', 'qty' => 1, 'price' => 13],
            ['manual' => true, 'name' => 'prove your love', 'artist' => 'taylor dayne', 'category' => 'Used Vinyl', 'sub_category' => 'Rock', 'qty' => 1, 'price' => 5],
            ['manual' => true, 'name' => "don't stop the dance", 'artist' => 'bryan ferry', 'category' => 'Used Vinyl', 'sub_category' => 'Rock', 'qty' => 1, 'price' => 12],
            ['manual' => true, 'name' => 'greatest hits', 'artist' => 'expose', 'category' => 'Used CD', 'sub_category' => 'R&B', 'qty' => 1, 'price' => 3],
            ['manual' => true, 'name' => 'dancer in the dark', 'artist' => 'bjork', 'category' => 'Used CD', 'sub_category' => 'Pop', 'qty' => 1, 'price' => 8],
            ['manual' => true, 'name' => 'dreamland', 'artist' => 'black box', 'category' => 'Used CD', 'sub_category' => 'R&B', 'qty' => 1, 'price' => 4],
            ['manual' => true, 'name' => 'best of', 'artist' => 't connection', 'category' => 'Used CD', 'sub_category' => 'R&B', 'qty' => 1, 'price' => 5],

        ];
    }

    // Single (non-group) sales-tax rates for the business, for the order-level
    // tax dropdown. Nivessa rings LA sales tax on top of the sticker price.
    private function taxRates($business_id)
    {
        return DB::table('tax_rates')
            ->where('business_id', $business_id)
            ->where('is_tax_group', 0)
            ->whereNull('deleted_at')
            ->orderBy('amount')
            ->get(['id', 'name', 'amount']);
    }

    // Pick the rate that lands the taxed total closest to the register's $705
    // (≈9.64% on the $643 base → whatever the store actually has, ~9.5%).
    private function defaultTaxRateId($rates, $base)
    {
        if ($rates->isEmpty() || $base <= 0) {
            return null;
        }
        $target = (self::EXPECTED_TOTAL / $base - 1) * 100;
        $best = null;
        $bestDiff = null;
        foreach ($rates as $r) {
            $diff = abs((float) $r->amount - $target);
            if ($bestDiff === null || $diff < $bestDiff) {
                $bestDiff = $diff;
                $best = $r->id;
            }
        }
        return $best;
    }

    public function index(Request $request)
    {
        $business_id = $request->session()->get('user.business_id');

        $locations = DB::table('business_locations')
            ->where('business_id', $business_id)
            ->orderBy('name')
            ->get(['id', 'name']);

        $users = DB::table('users')
            ->where('business_id', $business_id)
            ->orderBy('first_name')
            ->get(['id', DB::raw("TRIM(CONCAT(COALESCE(first_name,''),' ',COALESCE(surname,''))) as full_name")]);

        // Sensible defaults: Pico location, Alec as cashier.
        $location_id = (int) $request->input('location_id', optional($locations->first(function ($l) {
            return stripos($l->name, 'pico') !== false;
        }))->id ?? optional($locations->first())->id);

        $user_id = (int) $request->input('user_id', optional($users->first(function ($u) {
            return stripos($u->full_name, 'alec') !== false;
        }))->id ?? 0);

        $resolved = $this->resolveLines($business_id, $location_id);

        $tax_rates = $this->taxRates($business_id);
        $base = $resolved['computed_total'];
        $tax_rate_id = (int) $request->input('tax_rate_id', $this->defaultTaxRateId($tax_rates, $base));
        $tax_rate = $tax_rates->firstWhere('id', $tax_rate_id);
        $tax_amount = $tax_rate ? round($base * (float) $tax_rate->amount / 100, 2) : 0;

        return view('admin.ring_backfill', [
            'locations'      => $locations,
            'users'          => $users,
            'location_id'    => $location_id,
            'user_id'        => $user_id,
            'sale_datetime'  => self::SALE_DATETIME,
            'lines'          => $resolved['lines'],
            'computed_total' => $base,
            'tax_rates'      => $tax_rates,
            'tax_rate_id'    => $tax_rate_id,
            'tax_amount'     => $tax_amount,
            'total_with_tax' => round($base + $tax_amount, 2),
            'unit_count'     => $resolved['unit_count'],
            'line_count'     => count($resolved['lines']),
            'unmatched'      => $resolved['unmatched'],
            'expected_total' => self::EXPECTED_TOTAL,
            'already'        => $this->existingBackfill($business_id),
        ]);
    }

    public function apply(Request $request)
    {
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        $business_id = $request->session()->get('user.business_id');

        // Idempotency: if a sale tagged with this back-fill already exists,
        // don't ring it again. Undo (deleteSale) removes the row, re-arming us.
        if ($this->existingBackfill($business_id)) {
            return redirect('/admin/ring-backfill')
                ->with('status', ['success' => 0, 'msg' => 'Already rung up (a sale tagged ' . self::TAG . ' exists). Undo it at /admin/admin-action-history first if you need to redo it.']);
        }

        $location_id = (int) $request->input('location_id');
        $user_id = (int) $request->input('user_id');
        if (!$location_id || !$user_id) {
            return redirect('/admin/ring-backfill')
                ->with('status', ['success' => 0, 'msg' => 'Pick a location and a cashier first.']);
        }

        $transaction_date = $request->input('transaction_date');
        $transaction_date = $transaction_date ? date('Y-m-d H:i:s', strtotime($transaction_date)) : date('Y-m-d H:i:s', strtotime(self::SALE_DATETIME));

        $walk_in = Contact::where('business_id', $business_id)->where('is_default', 1)->first();
        if (!$walk_in) {
            $walk_in = Contact::where('business_id', $business_id)->where('type', 'customer')->first();
        }
        if (!$walk_in) {
            return redirect('/admin/ring-backfill')
                ->with('status', ['success' => 0, 'msg' => 'No walk-in / default customer found for this business.']);
        }

        $resolved = $this->resolveLines($business_id, $location_id);
        $products = $resolved['products'];
        if (empty($products)) {
            return redirect('/admin/ring-backfill')
                ->with('status', ['success' => 0, 'msg' => 'Nothing to ring — no lines resolved.']);
        }

        // Order-level sales tax (Nivessa rings LA tax on top of sticker). The
        // chosen rate is applied to the whole basket so final_total = base+tax.
        $tax_rate_id = (int) $request->input('tax_rate_id') ?: null;
        $invoice_total = $this->productUtil->calculateInvoiceTotal($products, $tax_rate_id, null, false);
        $final_total = round($invoice_total['final_total'], 2);

        // Snapshot BEFORE stock for every matched barcoded line (auditable;
        // the actual Undo uses deleteSale to restore).
        $snapshot_rows = [];
        foreach ($resolved['lines'] as $ln) {
            if (empty($ln['matched'])) { continue; }
            $snapshot_rows[] = [
                'variation_id' => $ln['variation_id'],
                'product_id' => $ln['product_id'],
                'location_id' => $location_id,
                'sku' => $ln['sku'],
                'name' => $ln['name'],
                'qty_available_before' => $ln['stock'],
                'qty_sold' => $ln['qty'],
            ];
        }

        $timestamp = now()->format('Y-m-d_His');
        $snapshotKey = "ring-backfill-{$timestamp}";

        DB::beginTransaction();
        try {
            $input = [
                'status' => 'final',
                'is_quotation' => 0,
                'location_id' => $location_id,
                'contact_id' => $walk_in->id,
                'transaction_date' => $transaction_date,
                'final_total' => $final_total,
                'discount_type' => 'fixed',
                'discount_amount' => 0,
                'tax_rate_id' => $tax_rate_id,
                'channel' => 'in_store',
                'commission_agent' => $user_id,
                'staff_note' => self::TAG . " (snapshot {$snapshotKey})",
                'sale_note' => 'Backfilled register sale — original 103-item ring failed on max_input_vars before the fix.',
                'exchange_rate' => 1,
            ];

            $transaction = $this->transactionUtil->createSellTransaction($business_id, $input, $invoice_total, $user_id, false);
            $this->transactionUtil->createOrUpdateSellLines($transaction, $products, $location_id, false, null, [], false);

            $payments = [[
                'amount' => $final_total,
                'method' => 'cash',
                'paid_on' => $transaction_date,
                'is_return' => 0,
            ]];
            $this->transactionUtil->createOrUpdatePaymentLines($transaction, $payments, $business_id, $user_id, false);

            // Decrement stock — mirrors SellPosController@store's final block.
            foreach ($products as $product) {
                if ($product['product_id'] === 'manual' || empty($product['enable_stock'])) { continue; }
                $decrease_qty = $product['quantity'];
                if (!empty($product['base_unit_multiplier'])) {
                    $decrease_qty = $decrease_qty * $product['base_unit_multiplier'];
                }
                $this->productUtil->decreaseProductQuantity(
                    $product['product_id'], $product['variation_id'], $location_id, $decrease_qty
                );
            }

            $this->transactionUtil->updatePaymentStatus($transaction->id, $transaction->final_total);

            // COGS mapping is non-fatal: stock + revenue are already correct.
            try {
                $business = [
                    'id' => $business_id,
                    'accounting_method' => $request->session()->get('business.accounting_method'),
                    'location_id' => $location_id,
                ];
                $this->transactionUtil->mapPurchaseSell($business, $transaction->sell_lines, 'purchase');
            } catch (\Throwable $e) {
                \Log::warning('ring-backfill mapPurchaseSell skipped: ' . $e->getMessage());
            }

            $this->transactionUtil->activityLog($transaction, 'added');

            Storage::disk('local')->put(
                "admin-snapshots/{$snapshotKey}.json",
                json_encode([
                    'timestamp' => $timestamp,
                    'action' => 'ring-backfill',
                    'user_id' => auth()->id(),
                    'business_id' => $business_id,
                    'transaction_id' => $transaction->id,
                    'invoice_no' => $transaction->invoice_no,
                    'final_total' => $final_total,
                    'location_id' => $location_id,
                    'rows' => $snapshot_rows,
                ], JSON_PRETTY_PRINT)
            );

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            \Log::emergency('ring-backfill failed: File:' . $e->getFile() . ' Line:' . $e->getLine() . ' Message:' . $e->getMessage());
            return redirect('/admin/ring-backfill')
                ->with('status', ['success' => 0, 'msg' => 'Aborted, nothing written: ' . $e->getMessage()]);
        }

        $decremented = count($snapshot_rows);
        $msg = "Rung up sale #{$transaction->invoice_no} (id {$transaction->id}) for \$" . number_format($final_total, 2)
            . " — decremented stock on {$decremented} barcoded item(s).";
        if (abs($final_total - self::EXPECTED_TOTAL) >= 0.01) {
            $msg .= " NOTE: total \$" . number_format($final_total, 2) . " differs from the register's \$" . number_format(self::EXPECTED_TOTAL, 2) . " by \$" . number_format(self::EXPECTED_TOTAL - $final_total, 2) . ".";
        }
        $msg .= " Undo at /admin/admin-action-history (snapshot {$snapshotKey}).";

        return redirect('/admin/ring-backfill')->with('status', ['success' => 1, 'msg' => $msg]);
    }

    // Resolve every order line against the catalogue at $location_id. Returns
    // display `lines` (match status + current stock) AND the `products` array
    // shaped for createOrUpdateSellLines / the decrement loop. Unmatched
    // barcodes ring as revenue-only manual lines so the $ total is preserved.
    private function resolveLines($business_id, $location_id): array
    {
        $lines = [];
        $products = [];
        $computed_total = 0.0;
        $unit_count = 0;
        $unmatched = 0;

        foreach ($this->order() as $row) {
            $qty = (int) $row['qty'];
            $price = (float) $row['price'];
            $computed_total += $qty * $price;
            $unit_count += $qty;

            if (!empty($row['manual'])) {
                [$catId, $subCatId] = $this->resolveCategory($business_id, $row['category'] ?? null, $row['sub_category'] ?? null);
                $lines[] = [
                    'manual' => true, 'matched' => false, 'sku' => null,
                    'name' => $row['name'], 'artist' => $row['artist'] ?? '',
                    'qty' => $qty, 'price' => $price, 'stock' => null,
                    'note' => 'Hand-typed line (no barcode) — revenue only, no stock change.',
                ];
                $products[] = $this->manualProduct($row['name'], $row['artist'] ?? '', $catId, $subCatId, $qty, $price);
                continue;
            }

            $sku = trim($row['sku']);
            $variation = DB::table('variations')
                ->join('products', 'products.id', '=', 'variations.product_id')
                ->where('products.business_id', $business_id)
                ->where(function ($q) use ($sku) {
                    $q->where('variations.sub_sku', $sku)->orWhere('products.sku', $sku);
                })
                ->select('variations.id as variation_id', 'variations.product_id', 'products.name as product_name', 'products.enable_stock', 'products.type as product_type')
                ->first();

            if (!$variation) {
                $unmatched++;
                $lines[] = [
                    'manual' => false, 'matched' => false, 'sku' => $sku,
                    'name' => $row['name'], 'qty' => $qty, 'price' => $price, 'stock' => null,
                    'note' => 'NOT FOUND — will ring as a revenue-only line; stock NOT decremented.',
                ];
                $products[] = $this->manualProduct($row['name'], '', null, null, $qty, $price);
                continue;
            }

            $stock = DB::table('variation_location_details')
                ->where('variation_id', $variation->variation_id)
                ->where('product_id', $variation->product_id)
                ->where('location_id', $location_id)
                ->value('qty_available');

            $lines[] = [
                'manual' => false, 'matched' => true, 'sku' => $sku,
                'name' => $variation->product_name,
                'variation_id' => $variation->variation_id,
                'product_id' => $variation->product_id,
                'enable_stock' => (int) $variation->enable_stock,
                'qty' => $qty, 'price' => $price,
                'stock' => $stock === null ? null : (float) $stock,
                'note' => $variation->enable_stock ? '' : 'Stock tracking off — no decrement.',
            ];

            $products[] = [
                'product_id' => $variation->product_id,
                'variation_id' => $variation->variation_id,
                'product_type' => $variation->product_type ?: 'single',
                'enable_stock' => (int) $variation->enable_stock,
                'quantity' => $qty,
                'unit_price' => $price,
                'unit_price_inc_tax' => $price,
                'item_tax' => 0,
                'tax_id' => null,
                'base_unit_multiplier' => 1,
            ];
        }

        return [
            'lines' => $lines,
            'products' => $products,
            'computed_total' => round($computed_total, 2),
            'unit_count' => $unit_count,
            'unmatched' => $unmatched,
        ];
    }

    private function manualProduct($name, $artist, $catId, $subCatId, $qty, $price): array
    {
        return [
            'product_id' => 'manual',
            'product_name' => $name,
            'product_artist' => $artist,
            'category_id' => $catId,
            'sub_category_id' => $subCatId,
            'variation_id' => null,
            'product_type' => 'single',
            'enable_stock' => 0,
            'quantity' => $qty,
            'unit_price' => $price,
            'unit_price_inc_tax' => $price,
            'item_tax' => 0,
            'tax_id' => null,
            'base_unit_multiplier' => 1,
        ];
    }

    // Best-effort name -> id for a manual line's category/sub-category. Null is
    // fine (matches the bag-fee manual line) and never blocks.
    private function resolveCategory($business_id, $category, $subCategory): array
    {
        $catId = !empty($category) ? DB::table('categories')->where('business_id', $business_id)->whereRaw('LOWER(name) = ?', [strtolower(trim($category))])->value('id') : null;
        $subCatId = !empty($subCategory) ? DB::table('categories')->where('business_id', $business_id)->whereRaw('LOWER(name) = ?', [strtolower(trim($subCategory))])->value('id') : null;
        return [$catId, $subCatId];
    }

    // True if a sale tagged with this back-fill already exists (final or draft).
    private function existingBackfill($business_id): bool
    {
        return DB::table('transactions')
            ->where('business_id', $business_id)
            ->where('type', 'sell')
            ->where('staff_note', 'like', self::TAG . '%')
            ->whereIn('status', ['final', 'draft'])
            ->exists();
    }
}
