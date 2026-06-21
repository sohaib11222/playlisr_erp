<?php

namespace App\Http\Controllers;

use App\Barcode;
use App\Product;
use App\Utils\ProductUtil;
use App\Utils\TransactionUtil;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\SellingPriceGroup;
use App\Category;

class LabelsController extends Controller
{
    /**
     * All Utils instance.
     *
     */
    protected $transactionUtil;
    protected $productUtil;

    /**
     * Constructor
     *
     * @param TransactionUtil $TransactionUtil
     * @return void
     */
    public function __construct(TransactionUtil $transactionUtil, ProductUtil $productUtil)
    {
        $this->transactionUtil = $transactionUtil;
        $this->productUtil = $productUtil;
    }

    /**
     * Display labels
     *
     * @return \Illuminate\Http\Response
     */
    public function show(Request $request)
    {
        $business_id = $request->session()->get('user.business_id');
        $purchase_id = $request->get('purchase_id', false);
        $product_id = $request->get('product_id', false);
        $product_ids = $request->get('product_ids', false);

        //Get products for the business
        $products = [];
        $price_groups = [];
        if ($purchase_id) {
            $products = $this->transactionUtil->getPurchaseProducts($business_id, $purchase_id);
        } elseif ($product_id) {
            $products = $this->productUtil->getDetailsFromProduct($business_id, $product_id);
        } else if (!empty($product_ids)) {
            $productIdsArray = explode(",", $product_ids);
            $products = $this->productUtil->getDetailsFromProducts($business_id, $productIdsArray);
        }

        //get price groups
        $price_groups = [];
        if(!empty($purchase_id) || !empty($product_id)){
            $price_groups = SellingPriceGroup::where('business_id', $business_id)
                                    ->active()
                                    ->pluck('name', 'id');
        }

        $barcode_settings = Barcode::where('business_id', $business_id)
                                ->orWhereNull('business_id')
                                ->select(DB::raw('CONCAT(name, ", ", COALESCE(description, "")) as name, id, is_default'))
                                ->get();
        $default = $barcode_settings->where('is_default', 1)->first();
        $barcode_settings = $barcode_settings->pluck('name', 'id');

        return view('labels.show')
            ->with(compact('products', 'barcode_settings', 'default', 'price_groups'));
    }

    /**
     * Returns the html for product row
     *
     * @return \Illuminate\Http\Response
     */
    public function addProductRow(Request $request)
    {
        if ($request->ajax()) {
            $product_id = $request->input('product_id');
            $variation_id = $request->input('variation_id');
            $business_id = $request->session()->get('user.business_id');
            
            if (!empty($product_id)) {
                $index = $request->input('row_count');
                // $products = $this->productUtil->getDetailsFromProduct($business_id, $product_id, $variation_id);
                $products = Product::leftJoin('variations', 'products.id', '=', 'variations.product_id')
                    ->leftJoin('categories', 'products.category_id', '=', 'categories.id')
                    ->leftJoin('categories as sub_cat', 'products.sub_category_id', '=', 'sub_cat.id')
                    ->where('products.business_id', $business_id)
                    ->where('products.id', $product_id)
                    ->where('variations.id', $variation_id)
                    ->whereNull('variations.deleted_at')
                    ->select(
                        'products.id as product_id',
                        'products.name as product_name',
                        'products.type',
                        'variations.id as variation_id',
                        'variations.name as variation_name',
                        'variations.sell_price_inc_tax as price',
                        'categories.name as catname',
                        'sub_cat.name as subcatname',
                        'variations.sub_sku as sub_sku',
                        DB::raw("(SELECT DATE_FORMAT(t.transaction_date, '%m/%d/%Y') 
                            FROM purchase_lines pl 
                            INNER JOIN transactions t ON pl.transaction_id = t.id 
                            WHERE pl.variation_id = variations.id 
                                AND t.type IN ('purchase', 'opening_stock')
                            ORDER BY t.transaction_date DESC, pl.id DESC 
                            LIMIT 1) as purchase_date")
                    )
                    ->groupBy('variation_id')
                    ->get();
                // var_dump($products);die;
                $price_groups = SellingPriceGroup::where('business_id', $business_id)
                                            ->active()
                                            ->pluck('name', 'id');
                
                return view('labels.partials.show_table_rows')
                        ->with(compact('products', 'index', 'price_groups'));
            }
        }
    }

    /**
     * Returns the html for labels preview
     *
     * @return \Illuminate\Http\Response
     */
    public function preview(Request $request)
    {
        try {
            $products = $request->get('products');
            $print = $request->get('print');
            $barcode_setting = $request->get('barcode_setting');
            $business_id = $request->session()->get('user.business_id');

            $barcode_details = Barcode::find($barcode_setting);
            $barcode_details->stickers_in_one_sheet = $barcode_details->is_continuous ? $barcode_details->stickers_in_one_row : $barcode_details->stickers_in_one_sheet;
            $barcode_details->paper_height = $barcode_details->is_continuous ? $barcode_details->height : $barcode_details->paper_height;
            if($barcode_details->stickers_in_one_row == 1){
                $barcode_details->col_distance = 0;
                $barcode_details->row_distance = 0;
            }
            // if($barcode_details->is_continuous){
            //     $barcode_details->row_distance = 0;
            // }

            $business_name = $request->session()->get('business.name');

            $product_details_page_wise = [];
            $total_qty = 0;
            foreach ($products as $value) {
                $details = $this->productUtil->getDetailsFromVariation($value['variation_id'], $business_id, null, false);

                if (!empty($value['exp_date'])) {
                    $details->exp_date = $value['exp_date'];
                }
                if (!empty($value['packing_date'])) {
                    $details->packing_date = $value['packing_date'];
                }
                if (!empty($value['purchase_date'])) {
                    $details->purchase_date = $value['purchase_date'];
                }
                if (empty($details->purchase_date ?? null) && !empty($value['variation_id'])) {
                    $pd = DB::table('purchase_lines as pl')
                        ->join('transactions as t', 'pl.transaction_id', '=', 't.id')
                        ->where('pl.variation_id', $value['variation_id'])
                        ->whereIn('t.type', ['purchase', 'opening_stock'])
                        ->where('t.business_id', $business_id)
                        ->orderByDesc('t.transaction_date')
                        ->orderByDesc('pl.id')
                        ->selectRaw("DATE_FORMAT(t.transaction_date, '%m/%d/%Y') as pd")
                        ->value('pd');
                    if (!empty($pd)) {
                        $details->purchase_date = $pd;
                    }
                }
                if (!empty($value['lot_number'])) {
                    $details->lot_number = $value['lot_number'];
                }

                if (!empty($value['price_group_id'])) {
                    $tax_id = $print['price_type'] == 'inclusive' ? : $details->tax_id;

                    $group_prices = $this->productUtil->getVariationGroupPrice($value['variation_id'], $value['price_group_id'], $tax_id);

                    $details->sell_price_inc_tax = $group_prices['price_inc_tax'];
                    $details->default_sell_price = $group_prices['price_exc_tax'];
                    
                    
                }
                
                
                // print_r($details->sub_category);die;
                for ($i=0; $i < $value['quantity']; $i++) {

                    $page = intdiv($total_qty, $barcode_details->stickers_in_one_sheet);

                    if($total_qty % $barcode_details->stickers_in_one_sheet == 0){
                        $product_details_page_wise[$page] = [];
                    }
                    $sub_category_id = Product::where('id', $details->product_id)->value('sub_category_id');
                    $details->sub_category = Category::where('id', $sub_category_id)->value('name');
                    $details->category = Category::where('id', $details->category_id)->value('name');

                    $product_details_page_wise[$page][] = $details;
                    $total_qty++;
                }
            }

            $margin_top = $barcode_details->is_continuous ? 0: $barcode_details->top_margin*1;
            $margin_left = $barcode_details->is_continuous ? 0: $barcode_details->left_margin*1;
            $paper_width = $barcode_details->paper_width*1;
            $paper_height = $barcode_details->paper_height*1;

            // print_r($paper_height);
            // echo "==";
            // print_r($margin_left);exit;

            // $mpdf = new \Mpdf\Mpdf(['mode' => 'utf-8', 
            //             'format' => [$paper_width, $paper_height],
            //             'margin_top' => $margin_top,
            //             'margin_bottom' => $margin_top,
            //             'margin_left' => $margin_left,
            //             'margin_right' => $margin_left,
            //             'autoScriptToLang' => true,
            //             // 'disablePrintCSS' => true,
            // 'autoLangToFont' => true,
            // 'autoVietnamese' => true,
            // 'autoArabic' => true
            //             ]
            //         );
            //print_r($mpdf);exit;

            // Roll up value + category mix of everything printed this run so
            // the shift-notes summary can show "$X of products labeled" and
            // the category breakdown. Each printed sticker = one item put out.
            $label_value = 0.0;
            $label_categories = [];
            foreach ($product_details_page_wise as $page_products) {
                foreach ($page_products as $pd) {
                    $label_value += (float) ($pd->sell_price_inc_tax ?? 0);
                    $cat = trim((string) ($pd->category ?? '')) ?: 'Uncategorized';
                    $sub = trim((string) ($pd->sub_category ?? ''));
                    $key = $sub !== '' ? $cat . ' › ' . $sub : $cat;
                    $label_categories[$key] = ($label_categories[$key] ?? 0) + 1;
                }
            }

            $i = 0;
            $len = count($product_details_page_wise);
            $is_first = false;
            $is_last = false;

            //$original_aspect_ratio = 4;//(w/h)
            $factor = (($barcode_details->width / $barcode_details->height)) / ($barcode_details->is_continuous ? 2 : 4);
            $html = '';
            // print_r($product_details_page_wise);die;
            foreach ($product_details_page_wise as $page => $page_products) {

                if($i == 0){
                    $is_first = true;
                }

                if($i == $len-1){
                    $is_last = true;
                }

                $output = view('labels.partials.preview_2')
                            ->with(compact('print', 'page_products', 'business_name', 'barcode_details', 'margin_top', 'margin_left', 'paper_width', 'paper_height', 'is_first', 'is_last', 'factor'))->render();
                print_r($output);
                //$mpdf->WriteHTML($output);

                // if($i < $len - 1){
                //     // '', '', '', '', '', '', $margin_left, $margin_left, $margin_top, $margin_top, '', '', '', '', '', '', 0, 0, 0, 0, '', [$barcode_details->paper_width*1, $barcode_details->paper_height*1]
                //     $mpdf->AddPage();
                // }

                $i++;
            }

            // Log this print run so the Employee Productivity report can show
            // labels printed per user. Writes directly to the existing
            // activity_log table (no migration / no extra table needed).
            try {
                if ($total_qty > 0) {
                    // De-dup guard: the Preview button (the only thing that hits
                    // this endpoint) is frequently re-submitted — double-click,
                    // or re-click when the preview opened in a background tab —
                    // which double-logged the same run and inflated the
                    // "labeled" totals that feed commission. Skip the insert if
                    // the same run was already logged in the last 120s. The
                    // signature matches the /admin/label-duplicates scanner
                    // exactly — same user + qty + value + category mix, 120s
                    // window — so the guard catches every re-click the scanner
                    // would have flagged (incl. slower ~90s re-clicks), while
                    // requiring the category mix to match avoids suppressing a
                    // genuinely different batch that happens to share a total.
                    $catSig = $label_categories;
                    ksort($catSig);
                    $catSig = json_encode($catSig);

                    $recent = DB::table('activity_log')
                        ->where('description', 'labels_printed')
                        ->where('business_id', $business_id)
                        ->where('causer_id', auth()->id())
                        ->where('created_at', '>=', now()->subSeconds(120))
                        ->whereRaw("JSON_EXTRACT(properties, '$.qty') = ?", [(int) $total_qty])
                        ->whereRaw("CAST(JSON_EXTRACT(properties, '$.value') AS DECIMAL(12,2)) = ?", [round($label_value, 2)])
                        ->pluck('properties');

                    $dupExists = false;
                    foreach ($recent as $p) {
                        $pd = json_decode($p, true) ?: [];
                        $pc = $pd['categories'] ?? [];
                        ksort($pc);
                        if (json_encode($pc) === $catSig) {
                            $dupExists = true;
                            break;
                        }
                    }

                    if (!$dupExists) {
                        DB::table('activity_log')->insert([
                            'log_name' => 'default',
                            'description' => 'labels_printed',
                            'subject_id' => null,
                            'subject_type' => null,
                            'causer_id' => auth()->id(),
                            'causer_type' => auth()->id() ? 'App\\User' : null,
                            'business_id' => $business_id,
                            'properties' => json_encode([
                                'qty' => (int) $total_qty,
                                'value' => round($label_value, 2),
                                'categories' => $label_categories,
                            ]),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }
            } catch (\Throwable $logErr) {
                \Log::warning('labels_printed activity_log insert failed: ' . $logErr->getMessage());
            }

            print_r('<script>window.print()</script>');
            exit;
            //return $output;

            //$mpdf->Output();

            // $page_height = null;
            // if ($barcode_details->is_continuous) {
            //     $rows = ceil($total_qty/$barcode_details->stickers_in_one_row) + 0.4;
            //     $barcode_details->paper_height = $barcode_details->top_margin + ($rows*$barcode_details->height) + ($rows*$barcode_details->row_distance);
            // }

            // $output = view('labels.partials.preview')
            //     ->with(compact('print', 'product_details', 'business_name', 'barcode_details', 'product_details_page_wise'))->render();

            // $output = ['html' => $html,
            //                 'success' => true,
            //                 'msg' => ''
            //             ];
        } catch (\Exception $e) {
            \Log::emergency("File:" . $e->getFile(). "Line:" . $e->getLine(). "Message:" . $e->getMessage());

            $output = __('lang_v1.barcode_label_error');
        }

        //return $output;
    }
}
