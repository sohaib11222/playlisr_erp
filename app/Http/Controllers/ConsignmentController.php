<?php

namespace App\Http\Controllers;

use App\Product;
use App\Contact;
use App\TaxRate;
use App\BusinessLocation;
use App\Utils\ProductUtil;
use Illuminate\Http\Request;

/**
 * Consignment inventory.
 *
 * Records taken in "on consignment" from a local artist become normal,
 * sellable ERP products (so they ring through POS like anything else),
 * but the consignment terms — who the consignor is and their % split —
 * and the running payout owed live in a JSON sidecar in storage/, NOT a
 * DB column. Sarah doesn't run migrations on the ERP box (they've broken
 * prod before), so this mirrors the cloverManualMatch JSON pattern.
 *
 * Payout model: the artist gets a percentage of the PRE-TAX amount the
 * record actually sells for (computed from the sell line's unit_price,
 * which excludes tax). Tax goes to the state, not the artist.
 *
 * Money owed is tracked entirely inside this JSON ledger and is kept
 * deliberately OUT of contacts.balance — that field is store credit and
 * is synced to the nivessa.com backend; we don't want consignment
 * payables entangled with that sync.
 *
 * JSON shape (storage/app/consignment-{business_id}.json):
 *   {
 *     "items": {
 *       "<product_id>": {
 *         "consignor": "Local Artist", "consignor_contact": "venmo/phone",
 *         "pct": 60, "title": "...", "artist": "...", "sticker": 24.99,
 *         "qty": 1, "sold_qty": 0, "owed": 0, "paid": 0,
 *         "intake_date": "2026-06-21", "status": "in_stock",
 *         "sales":   [ {date, qty, amount, payout, txn} ],
 *         "payouts": [ {date, amount, note} ]
 *       }
 *     }
 *   }
 */
class ConsignmentController extends Controller
{
    protected $productUtil;

    public function __construct(ProductUtil $productUtil)
    {
        $this->productUtil = $productUtil;
    }

    protected static function path(int $business_id): string
    {
        return storage_path('app/consignment-' . $business_id . '.json');
    }

    /** Full ledger; missing/corrupt file returns the empty shape. */
    public static function load(int $business_id): array
    {
        $path = self::path($business_id);
        if (!is_file($path)) {
            return ['items' => []];
        }
        try {
            $json = json_decode((string) file_get_contents($path), true);
            if (!is_array($json) || !isset($json['items']) || !is_array($json['items'])) {
                return ['items' => []];
            }
            return $json;
        } catch (\Throwable $e) {
            return ['items' => []];
        }
    }

    public static function save(int $business_id, array $data): void
    {
        $path = self::path($business_id);
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $tmp = $path . '.tmp';
        file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        @rename($tmp, $path);
    }

    public function index(Request $request)
    {
        if (!auth()->user()->can('product.create')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = (int) $request->session()->get('user.business_id');
        $data = self::load($business_id);
        $items = $data['items'];

        // Group by consignor for the payables view.
        $consignors = [];
        foreach ($items as $product_id => $it) {
            $key = $it['consignor'];
            if (!isset($consignors[$key])) {
                $consignors[$key] = [
                    'name'    => $key,
                    'contact' => $it['consignor_contact'] ?? '',
                    'owed'    => 0.0,
                    'paid'    => 0.0,
                    'in_stock' => 0,
                    'sold'    => 0,
                    'items'   => [],
                ];
            }
            $consignors[$key]['owed'] += (float) ($it['owed'] ?? 0);
            $consignors[$key]['paid'] += (float) ($it['paid'] ?? 0);
            $remaining = (int) ($it['qty'] ?? 1) - (int) ($it['sold_qty'] ?? 0);
            $consignors[$key]['in_stock'] += max(0, $remaining);
            $consignors[$key]['sold'] += (int) ($it['sold_qty'] ?? 0);
            $it['product_id'] = $product_id;
            $consignors[$key]['items'][] = $it;
        }
        // Most owed first.
        uasort($consignors, function ($a, $b) {
            return $b['owed'] <=> $a['owed'];
        });

        $total_owed = array_sum(array_column($consignors, 'owed'));

        // Intake-form dropdowns.
        $categories = \App\Category::forDropdown($business_id, 'product');
        $locations  = BusinessLocation::forDropdown($business_id);
        $tax_rates  = TaxRate::where('business_id', $business_id)
            ->pluck('name', 'id');

        return view('consignment.index', compact(
            'consignors', 'total_owed', 'categories', 'locations', 'tax_rates'
        ));
    }

    /**
     * Intake: take in N records from one consignor. Each row becomes a
     * single, stock-tracked product priced at its sticker, plus a ledger
     * entry carrying the consignment terms.
     */
    public function store(Request $request)
    {
        if (!auth()->user()->can('product.create')) {
            abort(403, 'Unauthorized action.');
        }

        $request->validate([
            'consignor'   => 'required|string|max:191',
            'pct'         => 'required|numeric|min:0|max:100',
            'location_id' => 'required|integer',
            'items'                 => 'required|array|min:1',
            'items.*.title'         => 'nullable|string|max:191',
            'items.*.sticker'       => 'nullable|numeric|min:0',
        ], [
            'consignor.required' => 'Who consigned these? Enter the artist / seller name.',
            'pct.required'       => 'Enter the artist split % (their share of each sale).',
        ]);

        $business_id = (int) $request->session()->get('user.business_id');
        $consignor   = trim($request->input('consignor'));
        $contact     = trim((string) $request->input('consignor_contact', ''));
        $pct         = (float) $request->input('pct');
        $location_id = (int) $request->input('location_id');
        $category_id = $request->filled('category_id') ? (int) $request->input('category_id') : null;
        $tax_id      = $request->filled('tax_id') ? (int) $request->input('tax_id') : null;
        $tax_type    = $request->input('tax_type') === 'inclusive' ? 'inclusive' : 'exclusive';
        $tax_exempt  = filter_var($request->input('tax_exempt'), FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        $intake_date = date('Y-m-d');

        $unit_id = \App\Unit::where('business_id', $business_id)->value('id');

        $data = self::load($business_id);
        $created = 0;

        foreach ($request->input('items') as $row) {
            $title   = trim((string) ($row['title'] ?? ''));
            if ($title === '') {
                continue; // skip blank rows
            }
            $artist  = trim((string) ($row['artist'] ?? ''));
            $sticker = round((float) ($row['sticker'] ?? 0), 2);
            $qty     = max(1, (int) ($row['qty'] ?? 1));

            $product = Product::create([
                'name'           => $title,
                'artist'         => $artist ?: null,
                'business_id'    => $business_id,
                'type'           => 'single',
                'unit_id'        => $unit_id,
                'category_id'    => $category_id,
                'tax'            => $tax_exempt ? null : $tax_id,
                'tax_type'       => $tax_type,
                'tax_exempt'     => $tax_exempt,
                'enable_stock'   => 1,
                'alert_quantity' => 0,
                'sku'            => ' ',
                'barcode_type'   => 'C128',
                'created_by'     => auth()->user()->id,
                'added_via'      => 'consignment',
                'product_custom_field1' => 'Consignment: ' . $consignor,
            ]);

            $product->sku = $this->productUtil->generateProductSku($product->id);
            $product->save();

            // Mirror the sticker into both price columns (no exc/inc tax
            // math — same rule as resale-cert variation prices); cost is $0
            // because nothing is owed to the artist until it actually sells.
            $this->productUtil->createSingleProductVariation(
                $product->id, $product->sku, 0, 0, 0, $sticker, $sticker
            );

            // Opening stock at the intake location.
            $variation = \App\Variation::where('product_id', $product->id)->first();
            if ($variation) {
                $this->productUtil->updateProductQuantity(
                    $location_id, $product->id, $variation->id, $qty, 0, null, false
                );
            }

            $data['items'][(string) $product->id] = [
                'consignor'         => $consignor,
                'consignor_contact' => $contact,
                'pct'               => $pct,
                'title'             => $title,
                'artist'            => $artist,
                'sticker'           => $sticker,
                'qty'               => $qty,
                'sold_qty'          => 0,
                'owed'              => 0.0,
                'paid'              => 0.0,
                'intake_date'       => $intake_date,
                'status'            => 'in_stock',
                'sales'             => [],
                'payouts'           => [],
            ];
            $created++;
        }

        if ($created === 0) {
            return redirect()->route('consignment.index')
                ->with('error', 'No records added — every row was missing a title.');
        }

        self::save($business_id, $data);

        return redirect()->route('consignment.index')->with(
            'status',
            $created . ' consignment record' . ($created === 1 ? '' : 's') .
            ' added for ' . $consignor . ' at ' . rtrim(rtrim(number_format($pct, 2), '0'), '.') . '% split.'
        );
    }

    /**
     * Called from the POS finalize path when a sale goes 'final'. For each
     * sell line that is a consigned product, accrue the artist's cut of the
     * pre-tax amount into the ledger. Idempotent per transaction so a
     * re-fire can't double-pay. NEVER throws into the POS flow — the caller
     * wraps this, and we also swallow per-line errors here.
     */
    public static function settleSale(int $business_id, $transaction): void
    {
        try {
            $data = self::load($business_id);
            if (empty($data['items'])) {
                return;
            }

            $dirty = false;
            foreach ($transaction->sell_lines as $line) {
                $pid = (string) $line->product_id;
                if ($pid === '' || $pid === 'manual' || !isset($data['items'][$pid])) {
                    continue;
                }
                $it = &$data['items'][$pid];

                // Skip if this transaction was already settled for this item.
                foreach ($it['sales'] as $s) {
                    if ((int) ($s['txn'] ?? 0) === (int) $transaction->id) {
                        continue 2;
                    }
                }

                $qty    = (float) $line->quantity;
                $amount = round((float) $line->unit_price * $qty, 2); // pre-tax merchandise
                $payout = round($amount * ((float) $it['pct'] / 100), 2);

                $it['sales'][] = [
                    'date'   => date('Y-m-d H:i'),
                    'qty'    => $qty,
                    'amount' => $amount,
                    'payout' => $payout,
                    'txn'    => (int) $transaction->id,
                ];
                $it['sold_qty'] = (int) $it['sold_qty'] + (int) $qty;
                $it['owed']     = round((float) $it['owed'] + $payout, 2);
                $it['status']   = ($it['sold_qty'] >= (int) $it['qty']) ? 'sold' : 'partially_sold';
                $dirty = true;
                unset($it);
            }

            if ($dirty) {
                self::save($business_id, $data);
            }
        } catch (\Throwable $e) {
            \Log::error('Consignment settleSale failed (txn ' .
                (is_object($transaction) ? ($transaction->id ?? '?') : '?') . '): ' . $e->getMessage());
        }
    }

    /**
     * Pay out everything currently owed to one consignor. Snapshots the
     * ledger first (any money-mutating action does), then zeroes each of
     * that consignor's `owed` into `paid`.
     */
    public function markPaid(Request $request)
    {
        if (!auth()->user()->can('product.create')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = (int) $request->session()->get('user.business_id');
        $consignor   = trim((string) $request->input('consignor'));
        $note        = trim((string) $request->input('note', ''));
        if ($consignor === '') {
            return redirect()->route('consignment.index')->with('error', 'No consignor specified.');
        }

        $data = self::load($business_id);

        // Snapshot before mutating money.
        $snapDir = storage_path('app/admin-snapshots');
        if (!is_dir($snapDir)) {
            @mkdir($snapDir, 0775, true);
        }
        @file_put_contents(
            $snapDir . '/consignment-' . $business_id . '-' . date('Ymd-His') . '.json',
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        $paid_total = 0.0;
        foreach ($data['items'] as $pid => &$it) {
            if ($it['consignor'] !== $consignor) {
                continue;
            }
            $owed = round((float) ($it['owed'] ?? 0), 2);
            if ($owed <= 0) {
                continue;
            }
            $it['payouts'][] = [
                'date'   => date('Y-m-d H:i'),
                'amount' => $owed,
                'note'   => $note,
                'by'     => auth()->user()->id,
            ];
            $it['paid'] = round((float) ($it['paid'] ?? 0) + $owed, 2);
            $it['owed'] = 0.0;
            if ($it['status'] === 'sold') {
                $it['status'] = 'paid';
            }
            $paid_total += $owed;
        }
        unset($it);

        if ($paid_total <= 0) {
            return redirect()->route('consignment.index')
                ->with('error', 'Nothing currently owed to ' . $consignor . '.');
        }

        self::save($business_id, $data);

        return redirect()->route('consignment.index')->with(
            'status',
            'Recorded $' . number_format($paid_total, 2) . ' paid to ' . $consignor . '.'
        );
    }
}
