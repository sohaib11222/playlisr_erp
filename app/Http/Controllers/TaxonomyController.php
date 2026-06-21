<?php

namespace App\Http\Controllers;

use App\Category;
use App\Utils\ModuleUtil;
use Illuminate\Http\Request;
use Yajra\DataTables\Facades\DataTables;

class TaxonomyController extends Controller
{
    /**
     * All Utils instance.
     *
     */
    protected $moduleUtil;

    /**
     * Constructor
     *
     * @param ProductUtils $product
     * @return void
     */
    public function __construct(ModuleUtil $moduleUtil)
    {
        $this->moduleUtil = $moduleUtil;
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $category_type = request()->get('type');
        if ($category_type == 'product' && !auth()->user()->can('category.view') && !auth()->user()->can('category.create')) {
            abort(403, 'Unauthorized action.');
        }

        if (request()->ajax()) {
            $can_edit = true;
            if($category_type == 'product' && !auth()->user()->can('category.update')) {
                $can_edit = false;
            }

            $can_delete = true;
            if($category_type == 'product' && !auth()->user()->can('category.update')) {
                $can_delete = false;
            }

            $business_id = request()->session()->get('user.business_id');

            $category = Category::where('business_id', $business_id)
                            ->where('category_type', $category_type)
                            ->select(['name', 'short_code', 'description', 'id', 'parent_id'])
                            ->selectRaw('(SELECT p.name FROM categories p WHERE p.id = categories.parent_id) as parent_name')
                            ->selectRaw(
                                "(SELECT COUNT(*) FROM products
                                  WHERE products.business_id = ?
                                    AND products.type != 'modifier'
                                    AND (products.category_id = categories.id OR products.sub_category_id = categories.id)
                                 ) as product_count",
                                [$business_id]
                            );

            return Datatables::of($category)
                ->addColumn(
                    'action', function ($row) use ($can_edit, $can_delete, $category_type)
                    {
                        $html = '';
                        if ($can_edit) {
                            $html .= '<button data-href="' . action('TaxonomyController@edit', [$row->id]) . '?type=' . $category_type . '" class="btn btn-xs btn-primary edit_category_button"><i class="glyphicon glyphicon-edit"></i>' . __("messages.edit") . '</button>';
                        }

                        if ($can_delete) {
                            $html .= '&nbsp;<button data-href="' . action('TaxonomyController@destroy', [$row->id]) . '" class="btn btn-xs btn-danger delete_category_button"><i class="glyphicon glyphicon-trash"></i> ' . __("messages.delete") . '</button>';
                        }

                        return $html;
                    }
                )
                ->editColumn('name', function ($row) {
                    if ($row->parent_id != 0) {
                        return '--' . $row->name;
                    } else {
                        return $row->name;
                    }
                })
                ->editColumn('parent_name', function ($row) {
                    return $row->parent_id != 0 ? $row->parent_name : '<span class="text-muted">—</span>';
                })
                ->editColumn('product_count', function ($row) {
                    $count = (int) $row->product_count;
                    if ($count === 0) {
                        return '<span class="text-muted">0</span>';
                    }
                    // Parent categories filter the product list by category_id;
                    // sub-categories filter by sub_category_id.
                    $param = $row->parent_id != 0 ? 'sub_category_id' : 'category_id';
                    $url = action('ProductController@index') . '?' . $param . '=' . $row->id;

                    return '<a href="' . $url . '" target="_blank">' . $count . '</a>';
                })
                ->removeColumn('id')
                ->removeColumn('parent_id')
                ->rawColumns(['action', 'product_count', 'parent_name'])
                ->make(true);
        }

        $module_category_data = $this->moduleUtil->getTaxonomyData($category_type);

        $business_id = request()->session()->get('user.business_id');

        $can_edit = true;
        $can_delete = true;
        if ($category_type == 'product' && !auth()->user()->can('category.update')) {
            $can_edit = false;
            $can_delete = false;
        }

        $all = Category::where('business_id', $business_id)
                    ->where('category_type', $category_type)
                    ->select('id', 'name', 'short_code', 'description', 'parent_id')
                    ->orderByRaw('LOWER(name)')
                    ->get();

        // Product counts. A product's category_id is always its parent category,
        // so category_id counts roll up the whole parent; sub_category_id counts
        // the individual child.
        $parentCounts = \DB::table('products')
                        ->where('business_id', $business_id)
                        ->where('type', '!=', 'modifier')
                        ->select('category_id', \DB::raw('COUNT(*) as c'))
                        ->groupBy('category_id')
                        ->pluck('c', 'category_id');

        $subCounts = \DB::table('products')
                        ->where('business_id', $business_id)
                        ->where('type', '!=', 'modifier')
                        ->select('sub_category_id', \DB::raw('COUNT(*) as c'))
                        ->groupBy('sub_category_id')
                        ->pluck('c', 'sub_category_id');

        $parents = $all->filter(function ($c) {
            return (int) $c->parent_id === 0;
        })->values();

        $children = $all->filter(function ($c) {
            return (int) $c->parent_id !== 0;
        });
        $childrenByParent = $children->groupBy('parent_id');

        // Sub-categories whose parent no longer exists -> "Ungrouped" bucket.
        $parentIds = $parents->pluck('id')->all();
        $ungrouped = $children->filter(function ($c) use ($parentIds) {
            return !in_array($c->parent_id, $parentIds);
        })->values();

        // Flat list (id + readable label) for the merge-target dropdown.
        $nameById = $all->pluck('name', 'id');
        $catOptions = $all->map(function ($c) use ($nameById) {
            $label = $c->name;
            if ((int) $c->parent_id !== 0) {
                $p = $nameById->get($c->parent_id);
                $label = ($p ? $p . ' / ' : '') . $c->name;
            }
            return ['id' => $c->id, 'label' => $label];
        })->sortBy('label', SORT_FLAG_CASE | SORT_STRING)->values();

        return view('taxonomy.index')->with(compact(
            'module_category_data',
            'category_type',
            'parents',
            'childrenByParent',
            'ungrouped',
            'parentCounts',
            'subCounts',
            'catOptions',
            'can_edit',
            'can_delete'
        ));
    }

    /**
     * Merge one category into another: move every product that references the
     * source (in category_id or sub_category_id) to the target, reparent the
     * source's sub-categories to the target, then soft-delete the source.
     * Snapshots BEFORE state to admin-snapshots so it can be undone at
     * /admin/admin-action-history.
     */
    public function merge(Request $request, $id)
    {
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        // Merge is owner-only (Jon). Match first name case-insensitively /
        // trimmed so "Jon", "jon", "Jon " all pass.
        $firstName = strtolower(trim((string) auth()->user()->first_name));
        if ($firstName !== 'jon'
            || !auth()->user()->can('category.update')
            || !auth()->user()->can('category.delete')) {
            return response()->json(['success' => false, 'msg' => 'Only Jon can merge categories.'], 403);
        }

        $business_id = $request->session()->get('user.business_id');
        $sourceId = (int) $id;
        $targetId = (int) $request->input('target_id');

        if ($targetId <= 0 || $sourceId === $targetId) {
            return response()->json(['success' => false, 'msg' => 'Pick a different category to merge into.']);
        }

        $source = Category::where('business_id', $business_id)->find($sourceId);
        $target = Category::where('business_id', $business_id)->find($targetId);

        if (!$source || !$target) {
            return response()->json(['success' => false, 'msg' => 'Category not found (already merged?).']);
        }
        if ($source->category_type !== $target->category_type) {
            return response()->json(['success' => false, 'msg' => 'Those categories are different types.']);
        }
        // Don't let a parent merge into one of its own sub-categories.
        if ((int) $target->parent_id === $sourceId) {
            return response()->json(['success' => false, 'msg' => 'Cannot merge a category into its own sub-category.']);
        }

        $affected = \DB::table('products')
            ->where('business_id', $business_id)
            ->where(function ($q) use ($sourceId) {
                $q->where('category_id', $sourceId)->orWhere('sub_category_id', $sourceId);
            })
            ->select('id', 'category_id', 'sub_category_id')
            ->get();

        $childRows = Category::where('business_id', $business_id)
            ->where('parent_id', $sourceId)
            ->select('id', 'parent_id')
            ->get();

        $timestamp = now()->format('Y-m-d_His');
        $snapshotKey = "merge-categories-{$timestamp}";
        \Storage::disk('local')->put(
            "admin-snapshots/{$snapshotKey}.json",
            json_encode([
                'timestamp'   => $timestamp,
                'action'      => 'merge-categories',
                'user_id'     => auth()->id(),
                'business_id' => $business_id,
                'source_id'   => $sourceId,
                'target_id'   => $targetId,
                'source_name' => $source->name,
                'target_name' => $target->name,
                'rows'        => $affected->map(function ($r) {
                    return ['id' => $r->id, 'category_id' => $r->category_id, 'sub_category_id' => $r->sub_category_id];
                })->all(),
                'children'    => $childRows->map(function ($r) {
                    return ['id' => $r->id, 'parent_id' => $r->parent_id];
                })->all(),
            ], JSON_PRETTY_PRINT)
        );

        \DB::beginTransaction();
        try {
            // Move product references. We deliberately don't touch products.updated_at
            // here — bumping it on a large category (e.g. tens of thousands of rows)
            // would kick off an unnecessary storefront/Clover re-sync wave.
            \DB::table('products')->where('business_id', $business_id)
                ->where('category_id', $sourceId)->update(['category_id' => $targetId]);
            \DB::table('products')->where('business_id', $business_id)
                ->where('sub_category_id', $sourceId)->update(['sub_category_id' => $targetId]);

            // Reparent the source's sub-categories under the target.
            Category::where('business_id', $business_id)
                ->where('parent_id', $sourceId)->update(['parent_id' => $targetId]);

            $source->delete(); // soft delete
            \DB::commit();
        } catch (\Exception $e) {
            \DB::rollBack();
            \Log::emergency("merge-categories failed: File:" . $e->getFile() . " Line:" . $e->getLine() . " Message:" . $e->getMessage());
            return response()->json(['success' => false, 'msg' => 'Merge failed — nothing was changed.']);
        }

        return response()->json([
            'success' => true,
            'msg' => 'Merged "' . $source->name . '" into "' . $target->name . '" — moved ' . count($affected) . ' product(s). Undo at /admin/admin-action-history.',
        ]);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        $category_type = request()->get('type');
        if ($category_type == 'product' && !auth()->user()->can('category.create')) {
            abort(403, 'Unauthorized action.');
        }
        $business_id = request()->session()->get('user.business_id');

        $module_category_data = $this->moduleUtil->getTaxonomyData($category_type);

        $categories = Category::where('business_id', $business_id)
                        ->where('parent_id', 0)
                        ->where('category_type', $category_type)
                        ->select(['name', 'short_code', 'id'])
                        ->get();

        $parent_categories = [];
        if (!empty($categories)) {
            foreach ($categories as $category) {
                $parent_categories[$category->id] = $category->name;
            }
        }

        return view('taxonomy.create')
                    ->with(compact('parent_categories', 'module_category_data', 'category_type'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
   public function store(Request $request)
{
    $category_type = $request->input('category_type');

    if ($category_type == 'product' && !auth()->user()->can('category.create')) {
        abort(403, 'Unauthorized action.');
    }

    try {
        if (!empty($request->input('add_as_sub_cat')) && $request->input('add_as_sub_cat') == 1) {
            // Process multiple subcategories
            $subcategories = $request->input('subcategories', []);

            foreach ($subcategories as $subcategory_name) {
                if (!empty($subcategory_name)) {
                    $subcategory_data = [
                        'name' => $subcategory_name,
                        'category_type' => $category_type,
                        'parent_id' => $request->input('parent_id', 0), // Add parent ID if selected
                        'business_id' => $request->session()->get('user.business_id'),
                        'created_by' => $request->session()->get('user.id'),
                    ];
                    if ($category_type === 'product' && $request->filled('ebay_category_ids')) {
                        $subcategory_data['ebay_category_ids'] = $request->input('ebay_category_ids');
                    }
                    Category::create($subcategory_data);
                }
            }
        } else {
            // Process single category
            $input = $request->only(['name', 'short_code', 'category_type', 'description']);
            $input['parent_id'] = 0; // No parent if not a subcategory
            $input['business_id'] = $request->session()->get('user.business_id');
            $input['created_by'] = $request->session()->get('user.id');
            if ($category_type === 'product' && $request->filled('ebay_category_ids')) {
                $input['ebay_category_ids'] = $request->input('ebay_category_ids');
            }

            Category::create($input);
        }

        $output = [
            'success' => true,
            'msg' => "Category added successfully"
        ];
    } catch (\Exception $e) {
        \Log::emergency("File:" . $e->getFile(). "Line:" . $e->getLine(). "Message:" . $e->getMessage());

        $output = [
            'success' => false,
            'msg' => "Something went wrong"
        ];
    }

    return $output;
}


    /**
     * Display the specified resource.
     *
     * @param  \App\Category  $category
     * @return \Illuminate\Http\Response
     */
    public function show(Category $category)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        $category_type = request()->get('type');
        if ($category_type == 'product' && !auth()->user()->can('category.update')) {
            abort(403, 'Unauthorized action.');
        }

        if (request()->ajax()) {
            $business_id = request()->session()->get('user.business_id');
            $category = Category::where('business_id', $business_id)->find($id);
            
            $module_category_data = $this->moduleUtil->getTaxonomyData($category_type);

            $parent_categories = Category::where('business_id', $business_id)
                                        ->where('parent_id', 0)
                                        ->where('category_type', $category_type)
                                        ->where('id', '!=', $id)
                                        ->pluck('name', 'id');
            $is_parent = false;
            
            if ($category->parent_id == 0) {
                $is_parent = true;
                $selected_parent = null;
            } else {
                $selected_parent = $category->parent_id ;
            }

            return view('taxonomy.edit')
                ->with(compact('category', 'parent_categories', 'is_parent', 'selected_parent', 'module_category_data'));
        }
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {

        if (request()->ajax()) {
            try {
                $input = $request->only(['name', 'description', 'ebay_category_ids']);
                $business_id = $request->session()->get('user.business_id');

                $category = Category::where('business_id', $business_id)->findOrFail($id);

                if ($category->category_type == 'product' && !auth()->user()->can('category.update')) {
                    abort(403, 'Unauthorized action.');
                }

                $category->name = $input['name'];
                $category->description = $input['description'];
                $category->short_code = $request->input('short_code');
                if ($category->category_type === 'product') {
                    $category->ebay_category_ids = $request->input('ebay_category_ids');
                }
                
                if (!empty($request->input('add_as_sub_cat')) &&  $request->input('add_as_sub_cat') == 1 && !empty($request->input('parent_id'))) {
                    $category->parent_id = $request->input('parent_id');
                } else {
                    $category->parent_id = 0;
                }
                $category->save();

                $output = ['success' => true,
                            'msg' => __("category.updated_success")
                            ];
            } catch (\Exception $e) {
                \Log::emergency("File:" . $e->getFile(). "Line:" . $e->getLine(). "Message:" . $e->getMessage());
            
                $output = ['success' => false,
                            'msg' => __("messages.something_went_wrong")
                        ];
            }

            return $output;
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {

        if (request()->ajax()) {
            try {
                $business_id = request()->session()->get('user.business_id');

                $category = Category::where('business_id', $business_id)->findOrFail($id);

                if ($category->category_type == 'product' && !auth()->user()->can('category.delete')) {
                    abort(403, 'Unauthorized action.');
                }

                $category->delete();

                $output = ['success' => true,
                            'msg' => __("category.deleted_success")
                            ];
            } catch (\Exception $e) {
                \Log::emergency("File:" . $e->getFile(). "Line:" . $e->getLine(). "Message:" . $e->getMessage());
            
                $output = ['success' => false,
                            'msg' => __("messages.something_went_wrong")
                        ];
            }

            return $output;
        }
    }

    public function getCategoriesApi()
    {
        try {
            $api_token = request()->header('API-TOKEN');

            $api_settings = $this->moduleUtil->getApiSettings($api_token);
            
            $categories = Category::catAndSubCategories($api_settings->business_id);
        } catch (\Exception $e) {
            \Log::emergency("File:" . $e->getFile(). "Line:" . $e->getLine(). "Message:" . $e->getMessage());
            
            return $this->respondWentWrong($e);
        }

        return $this->respond($categories);
    }

    /**
     * get taxonomy index page
     * through ajax
     * @return \Illuminate\Http\Response
     */
    public function getTaxonomyIndexPage(Request $request)
    {
        if (request()->ajax()) {
            $category_type = $request->get('category_type');
            $module_category_data = $this->moduleUtil->getTaxonomyData($category_type);

            return view('taxonomy.ajax_index')
                ->with(compact('module_category_data', 'category_type'));
        }
    }
}
