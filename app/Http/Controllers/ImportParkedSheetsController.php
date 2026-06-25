<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ImportParkedSheetsController extends Controller
{
    const TAX_RATE = 0.0975;
    const DATA_DIR = 'Services/data/parked';

    public function index(Request $request)
    {
        $businessId = $request->session()->get('user.business_id');
        $batches = [];
        foreach ($this->manifest() as $m) {
            $locId = $this->locationId($businessId, $m['location']);
            $already = DB::table('transactions')->where('business_id', $businessId)
                ->where('import_source', $m['import_source'])->count();
            $current = 0.0;
            if ($locId) {
                $current = (float) DB::table('transactions')
                    ->where('business_id', $businessId)->where('location_id', $locId)
                    ->where('type', 'sell')->where('status', 'final')
                    ->where(function ($q) { $q->where('is_whatnot', 0)->orWhereNull('is_whatnot'); })
                    ->whereRaw("DATE_FORMAT(transaction_date, '%Y-%m') = ?", [$m['month']])
                    ->sum('final_total');
            }
            $remainingFinal = $already > 0 ? 0.0 : (float) $m['final'];
            $batches[] = array_merge($m, [
                'locId' => $locId, 'already' => $already, 'current' => $current,
                'projected' => $current + $remainingFinal,
                'done' => $already >= (int) $m['rows'] && (int) $m['rows'] > 0,
            ]);
        }

        // Method validation: for sheets the bulk importer ALREADY loaded, compare
        // the offline parser's total against the live DB total for that exact
        // import_source. If they match, the same parser is trustworthy for the
        // parked sheets. This is ground-truth proof, not my say-so.
        $validation = [];
        foreach ($this->validationData() as $v) {
            $db = DB::table('transactions')
                ->where('business_id', $businessId)
                ->where('import_source', $v['import_source'])
                ->where('type', 'sell')->where('status', 'final')
                ->selectRaw('COALESCE(SUM(final_total),0) as total, COUNT(*) as cnt')
                ->first();
            $dbTotal = (float) ($db->total ?? 0);
            $dbCnt   = (int) ($db->cnt ?? 0);
            $mine    = (float) $v['my_final'];
            $delta   = $dbTotal > 0 ? abs($mine - $dbTotal) / $dbTotal * 100 : ($mine > 0 ? 100 : 0);
            $validation[] = [
                'label' => $v['label'], 'import_source' => $v['import_source'],
                'my_final' => $mine, 'my_rows' => (int) $v['my_rows'],
                'db_total' => $dbTotal, 'db_cnt' => $dbCnt, 'delta' => $delta,
                'match' => $dbTotal > 0 && $delta <= 0.5,
                'present' => $dbCnt > 0,
            ];
        }
        $matched = count(array_filter($validation, fn($r) => $r['match']));
        $checkable = count(array_filter($validation, fn($r) => $r['present']));

        return view('admin.import_parked_sheets', [
            'batches' => $batches,
            'validation' => $validation,
            'matched' => $matched,
            'checkable' => $checkable,
        ]);
    }

    protected function validationData(): array
    {
        $path = app_path(self::DATA_DIR . '/validation.json');
        if (!is_file($path)) { return []; }
        $data = json_decode((string) file_get_contents($path), true);
        return is_array($data) ? $data : [];
    }

    public function run(Request $request)
    {
        @set_time_limit(0);
        @ini_set('memory_limit', '1024M');
        $businessId = $request->session()->get('user.business_id');
        $userId = auth()->id();
        $source = (string) $request->input('import_source', '');
        $m = collect($this->manifest())->firstWhere('import_source', $source);
        if (!$m) { return back()->with('status', ['success' => 0, 'msg' => 'Unknown batch.']); }
        $locId = $this->locationId($businessId, $m['location']);
        if (!$locId) { return back()->with('status', ['success' => 0, 'msg' => "Could not resolve the {$m['location']} location."]); }
        $walkInContactId = $this->resolveWalkInContact($businessId);
        if (!$walkInContactId) { return back()->with('status', ['success' => 0, 'msg' => 'No walk-in/customer contact found.']); }
        $productId = $this->ensurePlaceholderProduct($businessId, $userId);
        $variationId = $this->ensurePlaceholderVariation($productId);
        $items = $this->loadItems($m['file']);
        if (empty($items)) { return back()->with('status', ['success' => 0, 'msg' => 'Data file missing or empty for ' . $source]); }
        $insertedIds = []; $created = 0; $dup = 0;
        DB::beginTransaction();
        try {
            foreach ($items as $it) {
                $externalId = (string) $it['ext'];
                $price = (float) $it['price'];
                if ($price <= 0) { continue; }
                $exists = DB::table('transactions')->where('business_id', $businessId)
                    ->where('import_source', $source)->where('import_external_id', $externalId)->exists();
                if ($exists) { $dup++; continue; }
                $totalBeforeTax = round($price, 4);
                $taxAmount = round($price * self::TAX_RATE, 4);
                $finalTotal = round($totalBeforeTax + $taxAmount, 4);
                $additional = trim(implode(' · ', array_filter([
                    !empty($it['artist']) ? ('Artist: ' . $it['artist']) : null,
                    !empty($it['title']) ? ('Title: ' . $it['title']) : null,
                    !empty($it['format']) ? ('Format: ' . $it['format']) : null,
                    !empty($it['genre']) ? ('Genre: ' . $it['genre']) : null,
                    !empty($it['condition']) ? ('Condition: ' . $it['condition']) : null,
                ])));
                $txId = DB::table('transactions')->insertGetId([
                    'business_id' => $businessId, 'type' => 'sell', 'status' => 'final', 'payment_status' => 'paid',
                    'contact_id' => $walkInContactId, 'location_id' => $locId,
                    'transaction_date' => $it['date'] . ' 12:00:00',
                    'total_before_tax' => $totalBeforeTax, 'tax_amount' => $taxAmount, 'final_total' => $finalTotal,
                    'discount_amount' => 0, 'additional_notes' => $additional ?: null, 'created_by' => $userId,
                    'import_source' => $source, 'import_external_id' => $externalId,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('transaction_sell_lines')->insert([
                    'transaction_id' => $txId, 'product_id' => $productId, 'variation_id' => $variationId,
                    'quantity' => 1, 'unit_price' => $totalBeforeTax, 'unit_price_inc_tax' => $finalTotal, 'item_tax' => $taxAmount,
                    'import_source' => $source, 'import_external_id' => $externalId,
                    'legacy_artist' => $it['artist'] ?: null, 'legacy_title' => $it['title'] ?: null,
                    'legacy_format' => $it['format'] ?: null, 'legacy_genre' => $it['genre'] ?: null,
                    'legacy_condition' => $it['condition'] ?: null, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $insertedIds[] = $txId; $created++;
            }
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            \Log::emergency("parked-sheet import {$source} failed: " . $e->getMessage());
            return back()->with('status', ['success' => 0, 'msg' => 'Import failed, nothing written: ' . $e->getMessage()]);
        }
        if ($created === 0) {
            return redirect('/admin/import-parked-sheets')
                ->with('status', ['success' => 1, 'msg' => "{$m['label']}: nothing new — all {$dup} rows already present."]);
        }
        $timestamp = now()->format('Y-m-d_His');
        $snapshotKey = "nivessa-sheet-import-{$timestamp}-" . preg_replace('/[^a-z0-9]+/', '-', strtolower($source));
        Storage::disk('local')->put("admin-snapshots/{$snapshotKey}.json", json_encode([
            'timestamp' => $timestamp, 'action' => 'nivessa-sheet-import', 'user_id' => $userId,
            'business_id' => $businessId, 'import_source' => $source,
            'rows' => array_map(fn($id) => ['id' => $id], $insertedIds),
        ], JSON_PRETTY_PRINT));
        return redirect('/admin/import-parked-sheets')->with('status', [
            'success' => 1,
            'msg' => "{$m['label']}: imported {$created} sale(s)" . ($dup ? " ({$dup} already present)" : '') . ". Snapshot: {$snapshotKey} (undo at /admin/admin-action-history).",
        ]);
    }

    protected function manifest(): array
    {
        $path = app_path(self::DATA_DIR . '/manifest.json');
        if (!is_file($path)) { return []; }
        $data = json_decode((string) file_get_contents($path), true);
        return is_array($data) ? $data : [];
    }

    protected function loadItems($file): array
    {
        $path = app_path(self::DATA_DIR . '/' . basename($file));
        if (!is_file($path)) { return []; }
        $data = json_decode((string) file_get_contents($path), true);
        return is_array($data) ? $data : [];
    }

    protected function locationId($businessId, $needle)
    {
        $loc = DB::table('business_locations')->where('business_id', $businessId)
            ->where('name', 'like', '%' . $needle . '%')->orderBy('id')->first(['id']);
        return $loc ? (int) $loc->id : 0;
    }

    protected function resolveWalkInContact($businessId)
    {
        $c = DB::table('contacts')->where('business_id', $businessId)->where('is_default', 1)
            ->whereNull('deleted_at')->orderBy('id')->first();
        if ($c) return $c->id;
        $c = DB::table('contacts')->where('business_id', $businessId)->whereIn('type', ['customer', 'both'])
            ->whereNull('deleted_at')->orderBy('id')->first();
        return $c ? $c->id : null;
    }

    protected function ensurePlaceholderProduct($businessId, $userId)
    {
        $name = 'Legacy Historical Item';
        $existing = DB::table('products')->where('business_id', $businessId)->where('name', $name)->first();
        if ($existing) return $existing->id;
        return DB::table('products')->insertGetId([
            'business_id' => $businessId, 'name' => $name, 'type' => 'single', 'sku' => 'NIV-LEGACY-HIST',
            'created_by' => $userId, 'enable_stock' => 0, 'is_inactive' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function ensurePlaceholderVariation($productId)
    {
        $existing = DB::table('variations')->where('product_id', $productId)->orderBy('id')->first();
        if ($existing) return $existing->id;
        $pvId = DB::table('product_variations')->insertGetId([
            'product_id' => $productId, 'name' => 'DUMMY', 'is_dummy' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        return DB::table('variations')->insertGetId([
            'product_id' => $productId, 'product_variation_id' => $pvId, 'name' => 'DUMMY', 'sub_sku' => 'NIV-LEGACY-HIST-0',
            'default_purchase_price' => 0, 'dpp_inc_tax' => 0, 'profit_percent' => 0,
            'default_sell_price' => 0, 'sell_price_inc_tax' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
