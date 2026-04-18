<?php

namespace App;

use DB;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Category extends Model
{
    use SoftDeletes;
    /**
     * The attributes that should be mutated to dates.
     *
     * @var array
     */
    

    /**
     * The attributes that aren't mass assignable.
     *
     * @var array
     */
    protected $guarded = ['id'];

    /**
     * Combines Category and sub-category
     *
     * @param int $business_id
     * @return array
     */
    public static function catAndSubCategories($business_id)
    {
        $all_categories = Category::where('business_id', $business_id)
                                ->where('category_type', 'product')
                                ->orderBy('name', 'asc')
                                ->get()
                                ->toArray();
                        
        if (empty($all_categories)) {
            return [];
        }
        $categories = [];
        $sub_categories = [];

        foreach ($all_categories as $category) {
            if ($category['parent_id'] == 0) {
                $categories[] = $category;
            } else {
                $sub_categories[] = $category;
            }
        }

        $sub_cat_by_parent = [];
        if (!empty($sub_categories)) {
            foreach ($sub_categories as $sub_category) {
                if (empty($sub_cat_by_parent[$sub_category['parent_id']])) {
                    $sub_cat_by_parent[$sub_category['parent_id']] = [];
                }

                $sub_cat_by_parent[$sub_category['parent_id']][] = $sub_category;
            }
        }

        foreach ($categories as $key => $value) {
            if (!empty($sub_cat_by_parent[$value['id']])) {
                $categories[$key]['sub_categories'] = $sub_cat_by_parent[$value['id']];
            }
        }

        return $categories;
    }

    /**
     * Flatten Category + Subcategory into a single list of combinations
     * that can be used to power a merged dropdown.
     *
     * Each entry has:
     * - id: string identifier (categoryId_subCategoryId or categoryId_0)
     * - category_id: int
     * - sub_category_id: int|null
     * - label: human readable label, e.g. "Used vinyl > Rock" or "Accessories"
     *
     * @param int $business_id
     * @return array
     */
    public static function flattenedProductCategoryCombos($business_id)
    {
        $categories = self::catAndSubCategories($business_id);
        $combos = [];

        foreach ($categories as $category) {
            $hasSubcats = !empty($category['sub_categories']);

            if ($hasSubcats) {
                foreach ($category['sub_categories'] as $subCat) {
                    $combos[] = [
                        'id' => $category['id'] . '_' . (int)$subCat['id'],
                        'category_id' => (int)$category['id'],
                        'sub_category_id' => (int)$subCat['id'],
                        'label' => $category['name'] . ' > ' . $subCat['name'],
                    ];
                }
            } else {
                $combos[] = [
                    'id' => $category['id'] . '_0',
                    'category_id' => (int)$category['id'],
                    'sub_category_id' => 0,
                    'label' => $category['name'],
                ];
            }
        }

        return $combos;
    }

    /**
     * Parse a category combo value from flattenedProductCategoryCombos dropdown
     * (format "categoryId_subCategoryId") or legacy "categoryId|subCategoryId".
     *
     * @param string|null $value
     * @return array{category_id: int|null, sub_category_id: int|null}
     */
    public static function parseCategoryComboValue($value)
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return ['category_id' => null, 'sub_category_id' => null];
        }

        if (strpos($raw, '|') !== false) {
            $parts = explode('|', $raw, 2);
        } else {
            $parts = explode('_', $raw, 2);
        }

        $category_id = isset($parts[0]) ? (int) trim($parts[0]) : 0;
        $sub_category_id = isset($parts[1]) ? (int) trim((string) $parts[1]) : 0;

        return [
            'category_id' => $category_id > 0 ? $category_id : null,
            'sub_category_id' => $sub_category_id > 0 ? $sub_category_id : null,
        ];
    }

    /**
     * Value for option[value] matching flattenedProductCategoryCombos "id" keys.
     *
     * @param int|null $category_id
     * @param int|null $sub_category_id
     * @return string
     */
    public static function formatCategoryComboOptionValue($category_id, $sub_category_id)
    {
        if (empty($category_id)) {
            return '';
        }

        return (int) $category_id . '_' . (int) ($sub_category_id ?: 0);
    }

    /**
     * Category Dropdown
     *
     * @param int $business_id
     * @param string $type category type
     * @return array
     */
    public static function forDropdown($business_id, $type)
    {
        $categories = Category::where('business_id', $business_id)
                            ->where('parent_id', 0)
                            ->where('category_type', $type)
                            ->select(DB::raw('IF(short_code IS NOT NULL, CONCAT(name, "-", short_code), name) as name'), 'id')
                            ->orderBy('name', 'asc')
                            ->get();

        $dropdown =  $categories->pluck('name', 'id');

        return $dropdown;
    }

    public function sub_categories()
    {
        return $this->hasMany(\App\Category::class, 'parent_id');
    }

    /**
     * Scope a query to only include main categories.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOnlyParent($query)
    {
        return $query->where('parent_id', 0);
    }
}
