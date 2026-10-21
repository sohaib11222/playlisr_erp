<?php

namespace App\Services;

use App\Contact;
use App\Product;
use App\PurchaseLine;
use App\Transaction;
use App\Utils\ProductUtil;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

// Shared logic for the distributor invoice importers (AMS, Alliance).
//
// The browser parses the invoice PDF with pdf.js and posts the lines. Here we
// match each UPC to a product, flag lines that are already on a purchase from
// the same supplier/store (Jon's pre-logged Alliance orders, floor-staff
// receipts) so they aren't doubled, and propose a new product for every UPC we
// don't carry yet: name "ARTIST - TITLE", SKU = the UPC, category from the
// invoice format, cost = invoice unit price, sell price by the sealed pricing
// convention. Apply creates those products and ONE purchase (status=ordered,
// no stock bump) holding every ticked line, snapshotted for one-click undo.
class DistributorInvoiceImport
{
    // key => settings. 'ref' prefixes the purchase ref_no, 'dir' holds the
    // already-imported sidecars, 'action' is the admin-snapshot action name.
    const DISTRIBUTORS = [
        'ams' => [
            'label' => 'AMS',
            'long' => 'AMS (All Media Supply)',
            'ref' => 'AMS-',
            'dir' => 'ams-imports',
            'action' => 'ams-invoice-import',
            'supplier_like' => ['AMS', 'All Media Supply'],
            // Account # on the invoice => store name.
            'accounts' => ['131507' => 'pico', '131715' => 'hollywood'],
        ],
        'alliance' => [
            'label' => 'Alliance',
            'long' => 'Alliance Entertainment',
            'ref' => 'ALLIANCE-',
            'dir' => 'alliance-imports',
            'action' => 'alliance-invoice-import',
            'supplier_like' => ['Alliance Entertainment'],
            'accounts' => ['048452' => 'hollywood', '065706' => 'pico'],
        ],
        // Redeye sends no PDF invoice we receive; orders are logged from the
        // order confirmation email (lines posted to the import endpoints).
        'redeye' => [
            'label' => 'Redeye',
            'long' => 'Redeye Worldwide',
            'ref' => 'REDEYE-',
            'dir' => 'redeye-imports',
            'action' => 'redeye-invoice-import',
            'supplier_like' => ['Redeye Worldwide'],
            'accounts' => ['54497' => 'hollywood'],
        ],
    ];

    // Category names new products can land in (top-level, product type).
    const CATEGORY_NAMES = ['CD - Sealed', 'Vinyl - Sealed', 'Cassettes - Sealed', 'Toys'];

    public static function config($distributor)
    {
        return self::DISTRIBUTORS[$distributor] ?? null;
    }

    // AMS invoice numbers are digits ("0625013"); Alliance are "PLS97956676".
    public static function invoiceKey($distributor, $invoice)
    {
        $invoice = strtoupper(trim((string) $invoice));
        if ($distributor === 'ams') {
            return preg_replace('/[^0-9]/', '', $invoice);
        }
        return preg_replace('/[^A-Z0-9]/', '', $invoice);
    }

    public static function sidecarPath($distributor, $invoiceKey)
    {
        return self::config($distributor)['dir'] . '/' . $invoiceKey . '.json';
    }

    public static function defaultSupplierId($business_id, $distributor)
    {
        $likes = self::config($distributor)['supplier_like'];
        $c = Contact::where('business_id', $business_id)
            ->where('type', '!=', 'customer')
            ->where(function ($q) use ($likes) {
                foreach ($likes as $like) {
                    $q->orWhere('name', $like)->orWhere('supplier_business_name', $like);
                }
            })
            ->orderBy('id')
            ->first();
        return $c ? $c->id : null;
    }

    // Store id for the account number printed on the invoice, if we know it.
    public static function locationForAccount($business_id, $distributor, $account)
    {
        $account = preg_replace('/\D/', '', (string) $account);
        $map = self::config($distributor)['accounts'];
        if ($account === '' || !isset($map[$account])) {
            return null;
        }
        $id = DB::table('business_locations')->where('business_id', $business_id)
            ->where('name', $map[$account])->value('id');
        return $id ? (int) $id : null;
    }

    public static function categoryIds($business_id)
    {
        return DB::table('categories')->where('business_id', $business_id)
            ->whereNull('deleted_at')->where('parent_id', 0)->where('category_type', 'product')
            ->whereIn('name', self::CATEGORY_NAMES)
            ->pluck('id', 'name')->all(); // name => id
    }

    // Candidate sub_sku forms for a printed UPC: as printed, without leading
    // zeros, 12-digit UPC-A from a 13-digit EAN with a leading zero, and the
    // 13-digit EAN form of a 12-digit UPC.
    public static function upcCandidates($upc)
    {
        $upc = preg_replace('/\D/', '', (string) $upc);
        if ($upc === '') return [];
        $c = [$upc];
        if (strlen($upc) === 13 && $upc[0] === '0') {
            $c[] = substr($upc, 1);
        }
        if (strlen($upc) === 12) {
            $c[] = '0' . $upc;
        }
        $c[] = ltrim($upc, '0');
        return array_values(array_unique(array_filter($c, function ($x) { return $x !== ''; })));
    }

    // The SKU a new product gets: the 12-digit UPC-A when the invoice prints a
    // 13-digit code with a leading zero (same as the sealed-barcode backfill),
    // otherwise the code as printed. Never a catalog number.
    public static function skuForUpc($upc)
    {
        $upc = preg_replace('/\D/', '', (string) $upc);
        if (strlen($upc) === 13 && $upc[0] === '0') {
            return substr($upc, 1);
        }
        return $upc;
    }

    // Category from the invoice's format code (AMS "Prod Type": LP / CD / CDS /
    // MC / SIN (7-inch) / TEN (10-inch) / TIN (12-inch);
    // Alliance "CF": 01 = LP, 02 = CD, TO = toys), falling back to words in
    // the description. Null = unknown, the reviewer picks.
    public static function guessCategory($format, $description)
    {
        $f = strtoupper(trim((string) $format));
        $d = strtoupper((string) $description);
        if ($f === '02' || preg_match('/^\d?CD|SACD/', $f)) return 'CD - Sealed';
        // AMS: TIN = 12-inch, TEN = 10-inch, SIN = 7-inch single (Fugazi "7 Songs" 12" came as TIN).
        if ($f === '01' || preg_match('/^(\d?LP|VIN|VINYL|7|10|12|7IN|10IN|12IN|EP|SIN|TIN|TEN|45)$/', $f)) return 'Vinyl - Sealed';
        if ($f === 'TO' || $f === 'TOY') return 'Toys';
        if (preg_match('/^(CS|CAS|CASS|MC)$/', $f)) return 'Cassettes - Sealed';
        if (preg_match('/\bCASSETTE\b/', $d)) return 'Cassettes - Sealed';
        if (preg_match('/\b(\d?LP|VINYL)\b/', $d)) return 'Vinyl - Sealed';
        if (preg_match('/\b\d?CD\b/', $d)) return 'CD - Sealed';
        return null;
    }

    // Sealed pricing convention, read off how the purchases desk prices new
    // sealed titles (median ~1.6-1.7x cost; CDs end in .49, LPs in .99):
    // 1.6x cost, rounded UP to the next .49 for CDs and .99 for everything else.
    public static function suggestPrice($cost, $categoryName)
    {
        $cost = (float) $cost;
        if ($cost <= 0) return null;
        $raw = $cost * 1.6;
        $ending = $categoryName === 'CD - Sealed' ? 0.49 : 0.99;
        $p = floor($raw) + $ending;
        if ($p < $raw - 0.0001) $p += 1;
        return round($p, 2);
    }

    // "ARTIST / TITLE" (both distributors) => ["Artist", "ARTIST - Title"].
    // Artist goes through the catalog's artist cleanup ("GUARALDI,VINCE" =>
    // "Vince Guaraldi"), title is Title Cased. No slash = no artist.
    public static function nameFromDescription($description)
    {
        $d = trim(preg_replace('/\s+/', ' ', (string) $description));
        $d = str_replace("\u{00AD}", '-', $d);
        // AMS cuts long titles mid-parenthesis ("(MA", "(RANDOM -"): drop the stub.
        $d = trim(preg_replace('/\s*\([^)]*$/', '', $d));
        // Alliance Hot Wheels lines: "HW PREM JAGUAR XJS".
        if (preg_match('/^HW (PREM )?(.+)$/', $d, $m)) {
            return ['artist' => 'Hot Wheels', 'name' => 'Hot Wheels' . ($m[1] ? ' Premium' : '') . ' - ' . ProductNameNormalizer::properTitle($m[2])];
        }
        $parts = preg_split('#\s+/\s+#', $d, 2);
        if (count($parts) === 2 && trim($parts[0]) !== '' && trim($parts[1]) !== '') {
            $artist = ProductNameNormalizer::cleanArtistValue($parts[0]);
            $title = ProductNameNormalizer::properTitle($parts[1]);
            return ['artist' => $artist, 'name' => $artist . ' - ' . $title];
        }
        return ['artist' => null, 'name' => ProductNameNormalizer::properTitle($d)];
    }

    /**
     * Match parsed lines. $lines: [{upc, description, format, qty, unit_price, line_total}].
     * Returns the lines with matched product info, existing-purchase flags and
     * a proposed new product for unmatched ones.
     */
    public static function preview($business_id, array $lines, $supplier_id, $location_id, $invoiceDate, $invoiceKey = '', $distributor = '')
    {
        $catIds = self::categoryIds($business_id);

        $all = [];
        foreach ($lines as $l) {
            foreach (self::upcCandidates($l['upc'] ?? '') as $c) $all[$c] = true;
        }
        $rowsBySku = [];
        foreach (array_chunk(array_keys($all), 500) as $chunk) {
            $rows = DB::table('variations as v')
                ->join('products as p', 'v.product_id', '=', 'p.id')
                ->where('p.business_id', $business_id)
                ->whereNull('v.deleted_at')
                ->whereIn('v.sub_sku', $chunk)
                ->get(['v.id as variation_id', 'v.product_id', 'v.sub_sku', 'v.default_purchase_price',
                       'v.sell_price_inc_tax', 'p.name as product_name', 'p.is_inactive', 'p.category_id']);
            foreach ($rows as $r) $rowsBySku[$r->sub_sku][] = $r;
        }

        $out = [];
        foreach ($lines as $l) {
            $upc = preg_replace('/\D/', '', (string) ($l['upc'] ?? ''));
            $desc = trim((string) ($l['description'] ?? ''));
            $format = trim((string) ($l['format'] ?? ''));
            $catName = self::guessCategory($format, $desc);
            $catId = $catName && isset($catIds[$catName]) ? (int) $catIds[$catName] : null;

            // Best product for this UPC: active over inactive, then the one in
            // the invoice's category, then the code as printed, then newest.
            $best = null; $bestScore = null; $allVids = [];
            foreach (self::upcCandidates($upc) as $rank => $cand) {
                foreach ($rowsBySku[$cand] ?? [] as $r) {
                    $allVids[] = (int) $r->variation_id;
                    $score = [(int) !$r->is_inactive, (int) ($catId && (int) $r->category_id === $catId), -$rank, (int) $r->product_id];
                    if ($bestScore === null || $score > $bestScore) { $best = $r; $bestScore = $score; }
                }
            }

            $unit = round((float) ($l['unit_price'] ?? 0), 2);
            $line = [
                'upc' => $upc,
                'description' => $desc,
                'format' => $format,
                'qty' => (int) ($l['qty'] ?? 0),
                'unit_price' => $unit,
                'line_total' => round((float) ($l['line_total'] ?? 0), 2),
                'matched' => (bool) $best,
                'variation_id' => $best ? (int) $best->variation_id : null,
                'product_id' => $best ? (int) $best->product_id : null,
                'product_name' => $best ? $best->product_name : null,
                'product_inactive' => $best ? (bool) $best->is_inactive : false,
                'current_cost' => $best && $best->default_purchase_price !== null ? round((float) $best->default_purchase_price, 2) : null,
                'existing' => [],   // same invoice already logged on another purchase: skip
                'earlier' => [],    // the title is on an older purchase: just FYI
                'new_product' => null,
                // Every product carrying this UPC (the catalog has duplicates),
                // so "already on a purchase" checks catch any of them.
                'upc_variation_ids' => array_values(array_unique($allVids)),
            ];
            if (!$best) {
                $nm = self::nameFromDescription($desc);
                $line['new_product'] = [
                    'name' => $nm['name'],
                    'artist' => $nm['artist'],
                    'sku' => self::skuForUpc($upc),
                    'category_id' => $catId,
                    'category_name' => $catName,
                    'sell_price' => self::suggestPrice($unit, $catName),
                ];
            }
            $out[] = $line;
        }

        // A line is only left off when THIS invoice is already logged on
        // another purchase (its number in the ref or notes, e.g. Jon's
        // "Alliance PLS97785739", or the purchases registered for it on the
        // audit page). The same title on an older purchase is a reorder or a
        // back-order fill, so it is imported and only noted (Sarah 10/21).
        $vids = [];
        foreach ($out as $l) foreach ($l['upc_variation_ids'] as $v) $vids[] = $v;
        $vids = array_values(array_unique($vids));
        if ($vids && $supplier_id && $location_id) {
            $d = self::normalizeDate($invoiceDate);
            $from = date('Y-m-d', strtotime($d . ' -90 days'));
            $to = date('Y-m-d 23:59:59', strtotime($d . ' +10 days'));
            $key = strtoupper(trim((string) $invoiceKey));
            $registered = [];
            if ($key !== '' && $distributor !== '' && Storage::disk('local')->exists('distributor-invoices/registry.json')) {
                $reg = json_decode(Storage::disk('local')->get('distributor-invoices/registry.json'), true) ?: [];
                foreach ($reg[$distributor . ':' . $key]['purchases'] ?? [] as $pid) $registered[(int) $pid] = true;
            }
            $hits = DB::table('purchase_lines as pl')
                ->join('transactions as t', 't.id', '=', 'pl.transaction_id')
                ->where('t.business_id', $business_id)
                ->where('t.type', 'purchase')
                ->where('t.contact_id', $supplier_id)
                ->where('t.location_id', $location_id)
                ->whereBetween('t.transaction_date', [$from, $to])
                ->whereIn('pl.variation_id', $vids)
                ->get(['pl.variation_id', 't.id', 't.ref_no', 't.additional_notes', 't.status', 'pl.quantity']);
            $same = []; $earlier = [];
            foreach ($hits as $h) {
                $row = ['transaction_id' => (int) $h->id, 'ref_no' => $h->ref_no, 'status' => $h->status, 'qty' => (float) $h->quantity];
                $isSame = isset($registered[(int) $h->id])
                    || ($key !== '' && (stripos((string) $h->ref_no, $key) !== false || stripos((string) $h->additional_notes, $key) !== false));
                if ($isSame) $same[(int) $h->variation_id][] = $row; else $earlier[(int) $h->variation_id][] = $row;
            }
            foreach ($out as &$line) {
                foreach ($line['upc_variation_ids'] as $v) {
                    if (isset($same[$v])) $line['existing'] = array_merge($line['existing'], $same[$v]);
                    if (isset($earlier[$v])) $line['earlier'] = array_merge($line['earlier'], $earlier[$v]);
                }
            }
            unset($line);
        }

        return $out;
    }

    /**
     * Create new products for "create" lines and one purchase with every line.
     * $lines: [{variation_id?, qty, unit_price, upc, description, new_product?{name, artist, sku, category_id, sell_price}}]
     * Returns ['transaction' => Transaction, 'created_products' => [...], 'lines' => n, 'total' => x].
     */
    public static function apply($distributor, $business_id, $user_id, $location_id, $contact_id, $invoiceKey, $invoiceDate, $notes, array $lines, array $opts = [])
    {
        $cfg = self::config($distributor);
        // A catch-up purchase for lines an earlier import dropped gets its own
        // ref ("AMS-0621577-B") and snapshot key, and leaves the invoice's
        // sidecar alone.
        $suffix = $opts['ref_suffix'] ?? '';
        $writeSidecar = $opts['sidecar'] ?? true;
        $productUtil = app(ProductUtil::class);
        $catIds = array_flip(self::categoryIds($business_id)); // id => name

        // Validate posted variation ids against this business.
        $postedVids = array_values(array_filter(array_map(function ($l) { return (int) ($l['variation_id'] ?? 0); }, $lines)));
        $valid = $postedVids ? DB::table('variations as v')
            ->join('products as p', 'v.product_id', '=', 'p.id')
            ->where('p.business_id', $business_id)
            ->whereIn('v.id', $postedVids)
            ->pluck('v.product_id', 'v.id')->all() : [];

        $created = [];
        $clean = [];
        DB::beginTransaction();
        try {
            foreach ($lines as $l) {
                $qty = (float) ($l['qty'] ?? 0);
                if ($qty <= 0) continue;
                $unit = round((float) ($l['unit_price'] ?? 0), 4);
                $vid = (int) ($l['variation_id'] ?? 0);

                if ($vid && isset($valid[$vid])) {
                    $clean[] = ['variation_id' => $vid, 'product_id' => (int) $valid[$vid], 'qty' => $qty, 'unit_price' => $unit];
                    continue;
                }

                $np = $l['new_product'] ?? null;
                if (!$np) continue;
                $sku = preg_replace('/\D/', '', (string) ($np['sku'] ?? ''));
                if (strlen($sku) < 11 || strlen($sku) > 14) {
                    throw new \RuntimeException('Line ' . ($l['description'] ?? '') . ': SKU must be the 11-14 digit UPC.');
                }
                $catId = (int) ($np['category_id'] ?? 0);
                if (!isset($catIds[$catId])) {
                    throw new \RuntimeException('Pick a category for ' . ($np['name'] ?? $sku) . '.');
                }
                $name = trim((string) ($np['name'] ?? ''));
                if ($name === '') {
                    throw new \RuntimeException('New product for UPC ' . $sku . ' needs a name.');
                }
                $sell = round((float) ($np['sell_price'] ?? 0), 2);
                if ($sell <= 0) {
                    throw new \RuntimeException('Set a selling price for ' . $name . '.');
                }

                // Same UPC already created earlier in this invoice (a title on
                // two lines) or by someone else a moment ago: reuse it.
                $existing = DB::table('variations as v')->join('products as p', 'v.product_id', '=', 'p.id')
                    ->where('p.business_id', $business_id)->whereNull('v.deleted_at')
                    ->whereIn('v.sub_sku', self::upcCandidates($sku))
                    ->orderBy('p.is_inactive')->orderByDesc('p.id')
                    ->first(['v.id', 'v.product_id']);
                if ($existing) {
                    $clean[] = ['variation_id' => (int) $existing->id, 'product_id' => (int) $existing->product_id, 'qty' => $qty, 'unit_price' => $unit];
                    continue;
                }

                $product = Product::create([
                    'name' => $name,
                    'artist' => !empty($np['artist']) ? $np['artist'] : null,
                    'sku' => $sku,
                    'barcode_type' => 'C128',
                    'brand_id' => null,
                    'category_id' => $catId,
                    'sub_category_id' => null,
                    'tax' => 1,
                    'tax_type' => 'exclusive',
                    'alert_quantity' => 0,
                    'business_id' => $business_id,
                    'created_by' => $user_id,
                    'added_via' => $distributor . '_invoice',
                    'enable_stock' => 1,
                    'product_description' => $cfg['label'] . ' invoice ' . $invoiceKey . ': ' . ($l['description'] ?? ''),
                    'unit_id' => 1,
                    'type' => 'single',
                    'not_for_selling' => 0,
                ]);
                $productUtil->createSingleProductVariation($product->id, $sku, $unit, $unit, 0, $sell, $sell);
                $product->product_locations()->sync([$location_id]);
                $variation = DB::table('variations')->where('product_id', $product->id)->first(['id']);

                $created[] = ['product_id' => $product->id, 'variation_id' => (int) $variation->id, 'sku' => $sku, 'name' => $name];
                $clean[] = ['variation_id' => (int) $variation->id, 'product_id' => $product->id, 'qty' => $qty, 'unit_price' => $unit];
            }

            if (count($clean) === 0) {
                throw new \RuntimeException('No lines to import.');
            }

            $total = 0.0;
            foreach ($clean as $l) { $total += $l['qty'] * $l['unit_price']; }
            $total = round($total, 2);

            $productUtil->setAndGetReferenceCount('purchase', $business_id);
            $transaction = Transaction::create([
                'business_id' => $business_id,
                'location_id' => $location_id,
                'type' => 'purchase',
                'status' => 'ordered',
                'payment_status' => 'due',
                'contact_id' => $contact_id,
                'ref_no' => $cfg['ref'] . $invoiceKey . $suffix,
                'transaction_date' => self::normalizeDate($invoiceDate),
                'total_before_tax' => $total,
                'tax_amount' => 0,
                'discount_amount' => 0,
                'discount_type' => 'fixed',
                'final_total' => $total,
                'additional_notes' => $notes,
                'created_by' => $user_id,
            ]);

            foreach ($clean as $l) {
                $pl = new PurchaseLine();
                $pl->transaction_id = $transaction->id;
                $pl->product_id = $l['product_id'];
                $pl->variation_id = $l['variation_id'];
                $pl->quantity = $l['qty'];
                $pl->purchase_price = $l['unit_price'];
                $pl->purchase_price_inc_tax = $l['unit_price'];
                $pl->pp_without_discount = $l['unit_price'];
                $pl->item_tax = 0;
                $pl->tax_id = null;
                $pl->save();
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        $stamp = now()->toDateTimeString();
        Storage::disk('local')->put('admin-snapshots/' . $cfg['action'] . '_' . $invoiceKey . $suffix . '_' . now()->format('Ymd_His') . '.json', json_encode([
            'action' => $cfg['action'],
            'timestamp' => $stamp,
            'direction' => $cfg['label'] . " invoice {$invoiceKey}{$suffix} → purchase #{$transaction->id} (" . count($clean) . ' lines, ' . count($created) . ' new products)',
            'business_id' => $business_id,
            'invoice' => $invoiceKey . $suffix,
            'transaction_id' => $transaction->id,
            'created_products' => $created,
        ], JSON_PRETTY_PRINT));

        if ($writeSidecar) Storage::disk('local')->put(self::sidecarPath($distributor, $invoiceKey), json_encode([
            'invoice' => $invoiceKey,
            'transaction_id' => $transaction->id,
            'line_count' => count($clean),
            'new_products' => count($created),
            'total' => $total,
            'invoice_date' => substr(self::normalizeDate($invoiceDate), 0, 10),
            'location_id' => (int) $location_id,
            'applied_at' => $stamp,
            'applied_by' => $user_id,
        ], JSON_PRETTY_PRINT));

        return ['transaction' => $transaction, 'created_products' => $created, 'lines' => count($clean), 'total' => $total];
    }

    // Add catalog lines to a purchase that is still Ordered (no stock moves):
    // used when an auto-imported invoice left lines off under the old rule.
    public static function appendLines($business_id, $transaction_id, array $lines)
    {
        $tx = DB::table('transactions')->where('business_id', $business_id)->where('id', $transaction_id)->where('type', 'purchase')->first();
        if (!$tx || $tx->status === 'received') throw new \RuntimeException('purchase #' . $transaction_id . ' is missing or already received');
        $added = 0; $total = 0.0;
        DB::beginTransaction();
        try {
            foreach ($lines as $l) {
                $vid = (int) ($l['variation_id'] ?? 0);
                $pid = $vid ? DB::table('variations')->where('id', $vid)->value('product_id') : null;
                if (!$pid || (float) $l['qty'] <= 0) continue;
                $pl = new PurchaseLine();
                $pl->transaction_id = $transaction_id;
                $pl->product_id = (int) $pid;
                $pl->variation_id = $vid;
                $pl->quantity = (float) $l['qty'];
                $pl->purchase_price = round((float) $l['unit_price'], 4);
                $pl->purchase_price_inc_tax = round((float) $l['unit_price'], 4);
                $pl->pp_without_discount = round((float) $l['unit_price'], 4);
                $pl->item_tax = 0;
                $pl->tax_id = null;
                $pl->save();
                $added++;
                $total += (float) $l['qty'] * (float) $l['unit_price'];
            }
            if ($added) {
                DB::table('transactions')->where('id', $transaction_id)->update([
                    'total_before_tax' => DB::raw('total_before_tax + ' . round($total, 2)),
                    'final_total' => DB::raw('final_total + ' . round($total, 2)),
                ]);
            }
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
        return [$added, round($total, 2)];
    }

    // Undo: delete the purchase (only while not received) and every product
    // the import created that nothing else has touched since (no other
    // purchase/sale line, no stock). Products in use are left as they are.
    public static function undo(array $data)
    {
        $txId = (int) ($data['transaction_id'] ?? 0);
        $purchase = $txId ? DB::table('transactions')->where('id', $txId)->where('type', 'purchase')->first() : null;
        if ($purchase && ($purchase->status ?? '') === 'received') {
            return [false, "Purchase #{$txId} was received (stock involved) — delete it from the Purchases page instead."];
        }
        $removedProducts = 0; $keptProducts = 0; $lines = 0;
        DB::beginTransaction();
        try {
            if ($purchase) {
                $lines = DB::table('purchase_lines')->where('transaction_id', $txId)->delete();
                DB::table('transactions')->where('id', $txId)->where('type', 'purchase')->delete();
            }
            foreach ($data['created_products'] ?? [] as $cp) {
                $pid = (int) ($cp['product_id'] ?? 0);
                if (!$pid) continue;
                $used = DB::table('purchase_lines')->where('product_id', $pid)->exists()
                    || DB::table('transaction_sell_lines')->where('product_id', $pid)->exists()
                    || DB::table('variation_location_details')->where('product_id', $pid)->where('qty_available', '!=', 0)->exists();
                if ($used) { $keptProducts++; continue; }
                DB::table('variation_location_details')->where('product_id', $pid)->delete();
                DB::table('variations')->where('product_id', $pid)->delete();
                DB::table('product_variations')->where('product_id', $pid)->delete();
                DB::table('product_locations')->where('product_id', $pid)->delete();
                DB::table('products')->where('id', $pid)->delete();
                $removedProducts++;
            }
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return [false, 'Undo failed, nothing changed: ' . $e->getMessage()];
        }
        $msg = $purchase ? "Deleted purchase #{$txId} + {$lines} line(s)" : "Purchase #{$txId} was already gone";
        if ($removedProducts || $keptProducts) {
            $msg .= "; removed {$removedProducts} product(s) the import created" . ($keptProducts ? ", kept {$keptProducts} that are in use" : '');
        }
        return [true, $msg . '.'];
    }

    public static function normalizeDate($d)
    {
        $d = trim((string) $d);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
            return $d . ' 00:00:00';
        }
        return now()->toDateTimeString();
    }
}
