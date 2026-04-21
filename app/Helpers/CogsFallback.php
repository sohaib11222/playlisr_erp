<?php

namespace App\Helpers;

/**
 * Generates SQL expressions that apply Nivessa's purchase-price assumptions
 * to sold items that don't have a recorded cost. Config lives in
 * config/nivessa_cogs.php.
 *
 * Two primary uses in ReportController:
 *   1. Replace `SUM(pl.purchase_price_inc_tax * qty)` with a SUM that
 *      COALESCEs to the category-based assumption when pl has no value.
 *   2. Expose the per-line assumed cost so the UI can flag which COGS
 *      numbers came from a fallback vs. real purchase data.
 *
 * Design notes:
 *   - CASE expressions are generated off the PHP config so Sarah can edit
 *     prices in one place without touching queries.
 *   - Category column references are passed in by the caller (e.g.
 *     'sc.name' for sub-category, 'c.name' for parent category) — the
 *     helper doesn't assume a table alias.
 *   - Patterns run in declaration order; first match wins. This is why
 *     "damaged" sits before "vinyl" in the config.
 */
class CogsFallback
{
    /**
     * Return a SQL CASE expression that resolves to the assumed
     * purchase-price-per-unit for a given (sub)category name column.
     *
     * Example output (trimmed):
     *   CASE
     *     WHEN LOWER(TRIM(COALESCE(sc.name,''))) LIKE '%damaged%' THEN 0.00
     *     WHEN LOWER(TRIM(COALESCE(sc.name,''))) LIKE '%used vinyl%' THEN 0.10
     *     ...
     *     ELSE NULL
     *   END
     *
     * Pass the SAME column as $subCol and $parentCol if you only have one
     * to match against.
     */
    public static function priceExpression(string $subCol, ?string $parentCol = null): string
    {
        $config = config('nivessa_cogs.patterns', []);
        if (empty($config)) {
            return 'NULL';
        }

        $default = config('nivessa_cogs.default');
        $defaultSql = is_null($default) ? 'NULL' : number_format((float) $default, 4, '.', '');

        $pieces = [];
        foreach ($config as $row) {
            $match = $row['match'] ?? null;
            $price = $row['price'] ?? null;
            if ($match === null || $price === null) continue;

            // Escape single quotes in the pattern. We don't run this through
            // PDO bindings because the helper returns raw SQL fragments meant
            // to be dropped into selectRaw() — values come from trusted
            // config, not user input.
            $like = "'%" . str_replace("'", "''", strtolower($match)) . "%'";
            $priceSql = number_format((float) $price, 4, '.', '');

            // Check sub-category first, then (optionally) parent category
            $pieces[] = "WHEN LOWER(TRIM(COALESCE({$subCol},''))) LIKE {$like} THEN {$priceSql}";
            if ($parentCol && $parentCol !== $subCol) {
                $pieces[] = "WHEN LOWER(TRIM(COALESCE({$parentCol},''))) LIKE {$like} THEN {$priceSql}";
            }
        }

        return 'CASE ' . implode(' ', $pieces) . " ELSE {$defaultSql} END";
    }

    /**
     * Wrap an existing "real cost" SQL expression with the fallback so the
     * final expression reads: COALESCE(NULLIF(real_cost, 0), assumed_cost).
     *
     *   @param string $realCostExpr  e.g. 'pl.purchase_price_inc_tax'
     *   @param string $subCol
     *   @param string|null $parentCol
     */
    public static function costWithFallback(string $realCostExpr, string $subCol, ?string $parentCol = null): string
    {
        $fallback = self::priceExpression($subCol, $parentCol);
        return "COALESCE(NULLIF({$realCostExpr}, 0), {$fallback})";
    }

    /**
     * Enable check — returns false if the feature flag is off, letting callers
     * fall back to the old (real cost only) query.
     */
    public static function isEnabled(): bool
    {
        return (bool) config('nivessa_cogs.enabled', true);
    }
}
