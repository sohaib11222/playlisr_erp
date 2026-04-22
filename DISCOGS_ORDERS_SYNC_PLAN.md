# Discogs Orders Sync — Implementation Plan

**For:** Sohaib
**Requested by:** Sarah (2026-04-22)
**Status:** ⏳ Ready for implementation

## Goal

Pull Discogs Marketplace orders into `transactions` so nivessa's Discogs sales
show up in the admin home dashboard (All-Stores MTD/YTD + "What's hot right
now" Discogs tab) and sales reports — same shape as Whatnot is wired today via
`is_whatnot`.

## Context

- Discogs inventory is **not** cataloged as ERP products — records live only on
  Discogs. Follow the `ImportNivessaHistoricalSales` pattern: one placeholder
  product ("Discogs Listing"), artist/title/format/genre preserved on the sell
  line via the existing `legacy_*` columns.
- Volume: ~10–20 orders/day. Backfill target: **1 year** (Sarah's call).
- Seller handle: `nivessa` (confirmed from
  https://www.discogs.com/seller/nivessa/profile).
- An `App\Services\DiscogsService` already exists and uses
  `business.api_settings.discogs.token` for price suggestions / listings —
  reuse that credential path, no new env var needed on your side. (Sarah also
  added `DISCOGS_PERSONAL_TOKEN` + `DISCOGS_SELLER_USERNAME` as GitHub repo
  secrets on 2026-04-22 in case you prefer env-based; either works.)

## Deliverables

### 1. Migration — `add_discogs_fields_to_transactions`

```php
$table->boolean('is_discogs')->default(0)->after('is_whatnot');
$table->unsignedBigInteger('discogs_order_id')->nullable()->unique();
$table->index('is_discogs');
```

`discogs_order_id` is the idempotency key — `INSERT ... ON DUPLICATE KEY UPDATE`
or `firstOrCreate()` so re-runs are safe.

### 2. Service — `App\Services\DiscogsOrdersService`

New class (or extend `DiscogsService`) with:

- `fetchOrders(string $since, int $page = 1)` — calls
  `GET /marketplace/orders?status=Shipped,Merged&sort=created&sort_order=asc&per_page=100&created_after={$since}&page={$page}`.
  Returns `{ orders: [...], pagination: { page, pages, per_page, items } }`.
- `fetchRelease(int $releaseId)` — calls `GET /releases/{id}` for genre / style
  / format. **Memoize by release_id for the duration of the run** — the same
  title sells multiple times, no point re-fetching.
- Respect `429 Too Many Requests`: read `Retry-After` header, sleep, retry
  (3 attempts max with exponential backoff).
- Discogs auth rate limit is 60 req/min — a `usleep(1_100_000)` between calls
  keeps us safely under.

### 3. Console command — `php artisan discogs:sync-orders`

```
discogs:sync-orders
  {--business=1 : business_id}
  {--user=1 : created_by}
  {--since= : YYYY-MM-DD; default = max(transactions.transaction_date where is_discogs=1) or 1 year ago}
  {--dry-run : don't write}
  {--max=0 : cap orders per run (0 = all)}
```

Per order:

1. Skip if `discogs_order_id` already exists in `transactions` (idempotent).
2. Resolve or create the "Discogs Listing" placeholder product (mirror
   `ensureLegacyProduct()` in `ImportNivessaHistoricalSales`).
3. Build the transaction:
   - `type='sell'`, `status='final'`, `payment_status='paid'`
   - `is_discogs=1`, `discogs_order_id={order.id}`, `source='discogs'`
   - `ref_no={order.id}`, `transaction_date={order.created}`
   - `final_total={order.total.value}`
   - `contact_id` = business's default walk-in
   - `location_id` — **your call**: null, or create a virtual `business_location`
     named "Discogs" on first run. I'd lean virtual-location so the existing
     tab wiring in `HomeController` just works via `location_id`.
4. Build one `transaction_sell_lines` row per `order.items[]`:
   - `product_id` = placeholder
   - `quantity=1`, `unit_price_inc_tax={item.price.value}`
   - `legacy_artist` / `legacy_title` from the release title + artist
   - `legacy_format` / `legacy_genre` from the cached `fetchRelease()` result

### 4. Scheduler entry — `app/Console/Kernel.php`

```php
$schedule->command('discogs:sync-orders')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();
```

Confirm the server actually runs `php artisan schedule:run` via cron — I (Claude)
didn't verify how the scheduler fires in production.

### 5. Initial backfill

```
php artisan discogs:sync-orders --since=2025-04-22 --dry-run   # sanity check
php artisan discogs:sync-orders --since=2025-04-22             # commit
```

~10–20 orders/day × 365 days ≈ 4–8K orders. With release-detail memoization
most will hit cache — figure 5–10K total API calls, ~2–3 hours at the
rate-limited pace. Run in a `tmux` / `nohup` on the server.

### 6. Dashboard wiring (one commit, ~5 lines)

In `app/Http/Controllers/HomeController.php`:

- Flip the Discogs store entry from the `__placeholder__` filter to either:
  - virtual location id (if you went that way in step 3), OR
  - a new filter token `'discogs'` and branch in `$tsRollup` alongside the
    existing `'online'` / whatnot branch, filtering on `t.is_discogs=1`.
- Also update the pico/hollywood branches to add
  `->where('t.is_discogs', 0)` so Discogs sales don't double-count in the
  store tabs. (This branch was added by Sarah+Claude 2026-04-22 for the
  toggle; easy to miss.)

In `resources/views/home/index.blade.php`, drop the "isn't wired into the
ERP yet" placeholder block for Discogs — keep it for nivessa.com until that
one's wired too.

### 7. Cleanup

If you go the virtual-location route, audit the "All-Stores MTD/YTD" sum in
`HomeController` — the new `$sumSells` closure Sarah+Claude added on
2026-04-22 uses `location_id` to scope Hollywood/Pico. You'll want the
combined ("all") mode to exclude the Discogs virtual location so the number
still means "in-store combined".

## Open decisions for you

1. **Virtual location row for "Discogs"?** Clean, but adds rows to
   `business_locations`. Alternative: keep `location_id=null`, filter everywhere
   on `is_discogs`. Either works — pick the one that matches your taste.
2. **Tax handling.** Discogs' `order.total.value` — is that inc-tax or
   ex-tax? The historical importer assumes a 9.75% CA rate and backs out tax.
   Check how Discogs actually reports it for us and mirror that.
3. **Order status filter.** I suggested `status=Shipped,Merged`. Consider
   also pulling `Paid` orders so the dashboard catches in-flight revenue.
   But then refunds/cancels need a reverse-sync — worth thinking about.
4. **Whatnot parity.** While you're in here, want to rename `is_whatnot` /
   `is_discogs` to a single `channel` enum? Optional; existing dashboard
   code still works either way.

## References

- Placeholder-product pattern:
  `app/Console/Commands/ImportNivessaHistoricalSales.php`
- Existing Discogs credential path:
  `app/Services/DiscogsService.php:41-44`
- Dashboard rollup to rewire:
  `app/Http/Controllers/HomeController.php` (the `$ts_stores` array + the
  `$tsRollup` closure)
- Discogs Marketplace API: https://www.discogs.com/developers/#page:marketplace

## What Sarah & Claude already did

Commit `657c129` on 2026-04-22 added a UI scaffold Sarah needed now:

1. **All Stores MTD/YTD toggle** — 3-way pill (Hollywood + Pico /
   Hollywood / Pico) above the two cards on the home dashboard. Server
   renders all three scope variants, JS flips which block is visible. No
   deploy risk, pure cosmetic.
2. **Unwired-channel empty state** — the Discogs + nivessa.com tabs in
   "What's hot right now" now say "isn't wired into the ERP yet" instead
   of the misleading "No sales in this window yet." When you ship step 6
   above, drop that block for Discogs.
