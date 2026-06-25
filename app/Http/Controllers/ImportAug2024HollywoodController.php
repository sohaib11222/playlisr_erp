<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

// Imports the one historical sheet the bulk importer skipped: "Hollywood Sales
// August 2024". That sheet's price-column header is a broken Excel formula
// (#REF!) instead of "Amount", so ImportNivessaHistoricalSales::findHeaderRow
// couldn't identify it and parked the whole sheet — leaving Hollywood at $0 for
// Aug 2024 (an n/a hole in the Like-for-Like report, between Jul $52k and
// Sep $51k).
//
// The 4,076 rows were parsed offline with the importer's own rules (price in
// col B, running date separators, fallback 2024-08-01) into
// app/Services/data/aug2024_hw_sales.json. This tool inserts them exactly the
// way the importer would: walk-in contact, "Legacy Historical Item" placeholder
// product, 9.75% tax added on top, import_source
// 'nivessa_backend_sales_hollywood_sales_august_2024'. Idempotent (dedup on
// import_source + import_external_id) and snapshotted for one-click undo at
// /admin/admin-action-history (action 'nivessa-sheet-import'). Live POS untouched.
class ImportAug2024HollywoodController extends Controller
{
    const IMPORT_SOURCE = 'nivessa_backend_sales_hollywood_sales_august_2024';
    const TAX_RATE = 0.0975;
    const DATA_FILE = 'aug2024_hw_sales.json';

    public function index(Request $request)
    {
        $businessId = $request->session()->get('user.business_id');
        $items = $this->loadItems();

        $locId = $this->hollywoodId($businessId);
        $preTax = 0.0; $final = 0.0;
        foreach ($items as $it) {
            $p = (float) $it['price'];
            $preTax += $p;
            $final  += round($p * (1 + self::TAX_RATE), 2);
        }

        $already = DB::table('transactions')
            ->where('business_id', $businessId)
            ->where('import_source', self::IMPORT_SOURCE)
            ->count();

        return view('admin.import_aug2024_hollywood', [
            'count'    => count($items),
            'preTax'   => $preTax,
            'final'    => $final,
            'already'  => $already,
            'locId'    => $locId,
            'hasLoc'   => (bool) $locId,
        ]);
    }

    public function run(Request $request)
    {
        @set_time_limit(0);
        @ini_set('memory_limit', '1024M');

        $businessId = $request->session()->get('user.business_id');
        $userId     = auth()->id();

        $locId = $this->hollywoodId($businessId);
        if (!$locId) {
            return back()->with('status', ['success' => 0, 'msg' => 'Could not resolve the Hollywood location.']);
        }

        $walkInContactId = $this->resolveWalkInContact($businessId);
        if (!$walkInContactId) {
            return back()->with('status', ['success' => 0, 'msg' => 'No walk-in/customer contact found to attach the sales to.']);
        }
        $productId   = $this->ensurePlaceholderProduct($businessId, $userId);
        $variationId = $this->ensurePlaceholderVariation($productId);

        $items = $this->loadItems();
        if (empty($items)) {
            return back()->with('status', ['success' => 0, 'msg' => 'Data file is empty or missing.']);
        }

        $insertedIds = [];
        $created = 0; $dup = 0;

        DB::beginTransaction();
        try {
            foreach ($items as $it) {
                $externalId = (string) $it['ext'];
                $price = (float) $it['price'];
                if ($price <= 0) { continue; }

                $exists = DB::table('transactions')
                    ->where('business_id', $businessId)
                    ->where('import_source', self::IMPORT_SOURCE)
                    ->where('import_external_id', $externalId)
                    ->exists();
                if ($exists) { $dup++; continue; }

                $totalBeforeTax = round($price, 4);
                $taxAmount = round($price * self::TAX_RATE, 4);
                $finalTotal = round($totalBeforeTax + $taxAmount, 4);

                $additional = trim(implode(' · ', array_filter([
                    !empty($it['artist'])    ? ('Artist: ' . $it['artist'])       : null,
                    !empty($it['title'])     ? ('Title: ' . $it['title'])         : null,
                    !empty($it['format'])    ? ('Format: ' . $it['format'])       : null,
                    !empty($it['genre'])     ? ('Genre: ' . $it['genre'])         : null,
                    !empty($it['condition']) ? ('Condition: ' . $it['condition']) : null,
                ])));

                $txId = DB::table('transactions')->insertGetId([
                    'business_id' => $businessId,
                    'type' => 'sell', 'status' => 'final', 'payment_status' => 'paid',
                    'contact_id' => $walkInContactId,
                    'location_id' => $locId,
                    'transaction_date' => $it['date'] . ' 12:00:00',
                    'total_before_tax' => $totalBeforeTax,
                    'tax_amount' => $taxAmount,
                    'final_total' => $finalTotal,
                    'discount_amount' => 0,
                    'additional_notes' => $additional ?: null,
                    'created_by' => $userId,
                    'import_source' => self::IMPORT_SOURCE,
                    'import_external_id' => $externalId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                DB::table('transaction_sell_lines')->insert([
                    'transaction_id' => $txId,
                    'product_id' => $productId,
                    'variation_id' => $variationId,
                    'quantity' => 1,
                    'unit_price' => $totalBeforeTax,
                    'unit_price_inc_tax' => $finalTotal,
                    'item_tax' => $taxAmount,
                    'import_source' => self::IMPORT_SOURCE,
                    'import_external_id' => $externalId,
                    'legacy_artist' => $it['artist'] ?: null,
                    'legacy_title' => $it['title'] ?: null,
                    'legacy_format' => $it['format'] ?: null,
                    'legacy_genre' => $it['genre'] ?: null,
                    'legacy_condition' => $it['condition'] ?: null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $insertedIds[] = $txId;
                $created++;
            }
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            \Log::emergency('aug2024-hw import failed: ' . $e->getMessage());
            return back()->with('status', ['success' => 0, 'msg' => 'Import failed, nothing written: ' . $e->getMessage()]);
        }

        if ($created === 0) {
            return redirect('/admin/import-aug2024-hollywood')
                ->with('status', ['success' => 1, 'msg' => "Nothing new to import — all {$dup} rows already present."]);
        }

        // Snapshot inserted ids so the whole batch is one-click deletable.
        $timestamp = now()->format('Y-m-d_His');
        $snapshotKey = "nivessa-sheet-import-{$timestamp}-aug2024-hw";
        Storage::disk('local')->put(
            "admin-snapshots/{$snapshotKey}.json",
            json_encode([
                'timestamp'     => $timestamp,
                'action'        => 'nivessa-sheet-import',
                'user_id'       => $userId,
                'business_id'   => $businessId,
                'import_source' => self::IMPORT_SOURCE,
                'rows'          => array_map(fn($id) => ['id' => $id], $insertedIds),
            ], JSON_PRETTY_PRINT)
        );

        return redirect('/admin/import-aug2024-hollywood')
            ->with('status', [
                'success' => 1,
                'msg' => "Imported {$created} Aug 2024 Hollywood sale(s)" . ($dup ? " ({$dup} already present, skipped)" : '') . ". Snapshot: {$snapshotKey} (undo at /admin/admin-action-history).",
            ]);
    }

    protected function loadItems(): array
    {
        $path = app_path('Services/data/' . self::DATA_FILE);
        if (!is_file($path)) { return []; }
        $data = json_decode((string) file_get_contents($path), true);
        return is_array($data) ? $data : [];
    }

    protected function hollywoodId($businessId)
    {
        $loc = DB::table('business_locations')
            ->where('business_id', $businessId)
            ->where('name', 'like', '%hollywood%')
            ->orderBy('id')
            ->first(['id']);
        return $loc ? (int) $loc->id : 0;
    }

    // --- mirrors ImportNivessaHistoricalSales bootstrap (same walk-in contact,
    //     placeholder product + dummy variation) so the rows match every other
    //     imported sheet exactly.
    protected function resolveWalkInContact($businessId)
    {
        $c = DB::table('contacts')->where('business_id', $businessId)
            ->where('is_default', 1)->whereNull('deleted_at')->orderBy('id')->first();
        if ($c) return $c->id;
        $c = DB::table('contacts')->where('business_id', $businessId)
            ->whereIn('type', ['customer', 'both'])->whereNull('deleted_at')->orderBy('id')->first();
        return $c ? $c->id : null;
    }

    protected function ensurePlaceholderProduct($businessId, $userId)
    {
        $name = 'Legacy Historical Item';
        $existing = DB::table('products')->where('business_id', $businessId)->where('name', $name)->first();
        if ($existing) return $existing->id;
        return DB::table('products')->insertGetId([
            'business_id' => $businessId, 'name' => $name, 'type' => 'single',
            'sku' => 'NIV-LEGACY-HIST', 'created_by' => $userId,
            'enable_stock' => 0, 'is_inactive' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function ensurePlaceholderVariation($productId)
    {
        $existing = DB::table('variations')->where('product_id', $productId)->orderBy('id')->first();
        if ($existing) return $existing->id;
        $pvId = DB::table('product_variations')->insertGetId([
            'product_id' => $productId, 'name' => 'DUMMY', 'is_dummy' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return DB::table('variations')->insertGetId([
            'product_id' => $productId, 'product_variation_id' => $pvId,
            'name' => 'DUMMY', 'sub_sku' => 'NIV-LEGACY-HIST-0',
            'default_purchase_price' => 0, 'dpp_inc_tax' => 0, 'profit_percent' => 0,
            'default_sell_price' => 0, 'sell_price_inc_tax' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
