<?php

namespace App\Services;

use App\Category;
use App\ProductEntryRule;

class ProductEntryRuleService
{
    public function resolve($business_id, $title = '', $category_id = null, $sub_category_id = null)
    {
        $title = trim((string) $title);
        $title_l = mb_strtolower($title);
        $category_id = !empty($category_id) ? (int) $category_id : null;
        $sub_category_id = !empty($sub_category_id) ? (int) $sub_category_id : null;

        $rules = ProductEntryRule::where('business_id', $business_id)
            ->where('is_active', 1)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $best = null;
        $bestScore = -1;
        foreach ($rules as $rule) {
            $score = $this->scoreRule($rule, $title_l, $category_id, $sub_category_id);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $rule;
            }
        }

        if (empty($best) || $bestScore <= 0) {
            return null;
        }

        return [
            'id' => $best->id,
            'trigger_type' => $best->trigger_type,
            'trigger_value' => $best->trigger_value,
            'artist' => $best->artist,
            'purchase_price' => $best->purchase_price,
            'selling_price' => $best->selling_price,
            'category_id' => $best->category_id,
            'sub_category_id' => $best->sub_category_id,
            'score' => $bestScore,
        ];
    }

    protected function scoreRule($rule, $title_l, $category_id, $sub_category_id)
    {
        $trigger = trim((string) $rule->trigger_value);
        if ($trigger === '') {
            return 0;
        }
        $trigger_l = mb_strtolower($trigger);

        if ($rule->trigger_type === 'category_combo') {
            $parsed = Category::parseCategoryComboValue($rule->trigger_value);
            $tCat = (int) ($parsed['category_id'] ?? 0);
            $tSub = (int) ($parsed['sub_category_id'] ?? 0);
            if ($tCat <= 0) {
                return 0;
            }
            $rowCat = (int) ($category_id ?? 0);
            $rowSub = (int) ($sub_category_id ?? 0);
            if ($rowCat !== $tCat) {
                return 0;
            }
            if ($tSub > 0 && $rowSub !== $tSub) {
                return 0;
            }
            // Parent-only combo (sub 0): do not match when a subcategory is selected on the row
            if ($tSub === 0 && $rowSub !== 0) {
                return 0;
            }

            return 70 + ($tSub > 0 ? 10 : 0);
        }

        // title trigger
        if ($title_l === '') {
            return 0;
        }
        if ($title_l === $trigger_l) {
            return 100;
        }
        if (mb_strpos($title_l, $trigger_l) !== false || mb_strpos($trigger_l, $title_l) !== false) {
            return 80;
        }
        return 0;
    }
}

