<?php

namespace App\Help;

/**
 * In-memory help article catalog. No DB, no migrations — articles live as
 * PHP arrays so the help system can't get blocked on schema changes.
 *
 * To add an article: append a new entry to articles(). To edit one: edit
 * the body_html string. To delete one: remove the entry. That's the entire
 * authoring surface.
 */
class Catalog
{
    public static function articles(): array
    {
        return [
            [
                'slug' => 'getting-started',
                'title' => 'Getting started with the ERP',
                'section' => 'Welcome',
                'sort' => 1,
                'summary' => 'A short tour of the ERP and where to find things.',
                'page_keys' => ['home', 'dashboard'],
                'body_html' => <<<'HTML'
<p>The ERP at <code>playlist.nivessa.com</code> is the system of record for sales, inventory, purchases, customers and labels. The Google Sheets backend is no longer where day-to-day work happens — everything below is what to use instead.</p>

<h3>Where to find common tasks</h3>
<ul>
  <li><strong>Ring up a customer</strong> &rarr; POS &rarr; Create</li>
  <li><strong>Receive an AMS shipment</strong> &rarr; Purchases &rarr; List Purchases &rarr; mark received</li>
  <li><strong>Buy a collection from a customer</strong> &rarr; Buy from Customer</li>
  <li><strong>Print barcode labels for new reissues</strong> &rarr; Labels (after marking purchase received)</li>
  <li><strong>Look up store credit</strong> &rarr; Customers &rarr; open the customer record</li>
  <li><strong>Add a new product</strong> &rarr; Products &rarr; Add Product</li>
</ul>

<h3>Found something missing or wrong?</h3>
<p>Email <a href="mailto:sarah@nivessa.com">sarah@nivessa.com</a> with what you were looking for and we'll add it.</p>
HTML,
            ],
            [
                'slug' => 'pos-ringing-up-a-customer',
                'title' => 'How to ring up a customer (POS)',
                'section' => 'POS',
                'sort' => 1,
                'summary' => 'The standard sale flow on /pos/create.',
                'page_keys' => ['pos.create', 'sell.create', 'pos'],
                'body_html' => <<<'HTML'
<p>Use POS &rarr; Create for every sale. Every item must go through the ERP, even if you also ring it on Clover for the card swipe.</p>

<h3>Steps</h3>
<ol>
  <li><strong>Pick the customer.</strong> Search by name or phone in the customer field. If they're new, click "+ Add" to make them on the spot — name + phone is enough.</li>
  <li><strong>Add items.</strong> Scan the barcode if the item has one. If it's a used record with our red/white price tag, search by title or use "Manual entry" and type artist + title + price.</li>
  <li><strong>Check that the price matches the sticker.</strong> The sticker price is the price — cashiers don't adjust prices at the register. If something has no sticker or the sticker is wrong, ask a manager before ringing it up.</li>
  <li><strong>Sales tax</strong> is applied automatically based on the store location. Cash <em>and</em> card both pay tax.</li>
  <li><strong>Pick payment.</strong> Cash, card, or store credit. If the customer has store credit, it shows on their customer record — don't go searching the old credit spreadsheet.</li>
  <li><strong>Finalize</strong> and send the receipt by phone or email if they want one.</li>
</ol>

<h3>Discounts</h3>
<div class="help-warn"><strong>Manager-only.</strong> Cashiers don't apply discounts. If a customer asks for one, get a manager — they're the only ones who can authorize it.</div>

<h3>Returns</h3>
<div class="help-warn"><strong>Manager-only.</strong> Only a manager can authorize refunds. If the manager is out, tell the customer it can be processed when they're back, or call Jon. Receipts are required. Used products: no returns — direct them to sell it back as a collection.</div>

<h3>Don't</h3>
<ul>
  <li>Don't ring items only on Clover and skip the ERP — that's the gap that makes inventory and reports go wrong.</li>
  <li>Don't apply tax-free unless it's a store-credit transaction.</li>
  <li>Don't ring up Kallax records the customer pulled from under the bins without confirming with a manager (and if it sells, delete it from Discogs so it doesn't double-sell).</li>
</ul>
HTML,
            ],
            [
                'slug' => 'receive-ams-shipment',
                'title' => 'How to receive an AMS shipment',
                'section' => 'Purchases',
                'sort' => 1,
                'summary' => 'Mark a purchase received and print labels for new reissues.',
                'page_keys' => ['purchase.index', 'purchase.create', 'purchases', 'labels'],
                'body_html' => <<<'HTML'
<p>AMS (All Media Supply) ships sealed inventory roughly weekly in brown UPS boxes. The order is already in the ERP — your job is to confirm it arrived, then print labels.</p>

<h3>Mark the purchase received</h3>
<ol>
  <li>Go to <strong>Purchases &rarr; List Purchases</strong>.</li>
  <li>Find the matching AMS order by date.</li>
  <li>Open the row and confirm the items match what's in the box. If something's missing or wrong, note it before changing status.</li>
  <li>Change status to <strong>Received</strong>. This updates stock counts.</li>
</ol>

<h3>Print barcode labels</h3>
<ol>
  <li>Go to <strong>Purchases &rarr; Print Labels</strong>.</li>
  <li>Scan each record into the print list. If a dropdown appears (multiple matches), cross-reference the AMS price and pick the right one.</li>
  <li>Before printing, swap the Zebra paper from 4×6 to 2×1: open Zebra Utilities &rarr; Configure Printer Settings &rarr; width 2, height 1 &rarr; Finish.</li>
  <li>Click <strong>Preview</strong>, then print the PDF.</li>
</ol>

<h3>Stickering and shelving</h3>
<ul>
  <li>Place the sticker top-right of the cover. Avoid hype stickers and important cover info.</li>
  <li>Sealed inventory goes in the New Reissue bins.</li>
  <li>If the genre on the sticker looks wrong, check Discogs and use your judgment.</li>
</ul>

<div class="help-tip"><strong>Sealed buys from customers</strong> (e.g. Randy's weekly drop at Pico) follow the same flow: <strong>Purchases &rarr; Add Purchase</strong>, search the title, set supplier to the customer's name (use the "+" button to add a new supplier), pick the location, and mark received.</div>
HTML,
            ],
            [
                'slug' => 'buy-collection-from-customer',
                'title' => 'How to buy a collection from a customer',
                'section' => 'Buying',
                'sort' => 1,
                'summary' => 'The negotiation flow plus what to pay for what.',
                'page_keys' => ['buy_from_customer', 'purchase.create'],
                'body_html' => <<<'HTML'
<p>Buying collections fuels the business. The #1 priority when someone walks in with records: <strong>get their phone or email before anything else</strong>.</p>

<h3>Negotiate first, type second</h3>
<ol>
  <li><strong>Ask what they're hoping for.</strong> Often it's much less than you would have offered. Don't lead with a number.</li>
  <li><strong>Ask cash or store credit.</strong> Store credit pays more than cash.</li>
  <li><strong>Assess the collection.</strong> Look for mold, deep scratches, missing items, cracked media, wrong record-in-wrong-sleeve. Grade with the Goldmine standard (M, NM, VG+, VG, G+, G, F, P). We mostly want M–VG; G+ and below sell slowly so pay very little.</li>
  <li><strong>Compute three offers</strong> on paper — high, middle, low. Open with the low. Let them counter. Only inch up if needed.</li>
  <li><strong>Close.</strong> Pay from the register. If short, text Jon to Zelle/Venmo. For store credit, add it to the customer's record in the ERP (not the old spreadsheet).</li>
</ol>

<h3>What to pay (rough guide)</h3>
<table class="table table-condensed table-bordered">
  <thead><tr><th>Format</th><th>Rate</th></tr></thead>
  <tbody>
    <tr><td>Sealed/new LP, popular artist (2020+)</td><td>$7–8 each</td></tr>
    <tr><td>Sealed/new LP, lesser-known</td><td>$2 each</td></tr>
    <tr><td>Used LP, sellable (Stevie Wonder, Sade, Sabbath, Dead)</td><td>~$2 each</td></tr>
    <tr><td>Slower LPs (Genesis, Billy Joel, Cat Stevens, Elton John)</td><td>$1 each</td></tr>
    <tr><td>Don't offer for: Johnny Mathis, Streisand, Jack Jones</td><td>$0</td></tr>
    <tr><td>CDs — Hip hop, Metal</td><td>$1 / $1.50 sealed</td></tr>
    <tr><td>CDs — Latin, Reggae</td><td>$0.50 / $1 sealed</td></tr>
    <tr><td>CDs — Blues, Rock, Electronic, New Wave</td><td>$0.35 / $0.70 sealed</td></tr>
    <tr><td>CDs — Jazz, R&amp;B, Soundtracks, Musicals</td><td>$0.15 / $0.35 sealed</td></tr>
    <tr><td>CDs — Classical</td><td>$0.10 / $0.25 sealed</td></tr>
    <tr><td>45s (7")</td><td>$0.15 each (Latin: $0.50)</td></tr>
    <tr><td>DVDs</td><td>$0.15 used / $0.35 sealed / $2 steelbook</td></tr>
    <tr><td>Blu-rays</td><td>$0.25 used / $0.50 sealed</td></tr>
  </tbody>
</table>

<h3>Don't buy</h3>
<div class="help-warn">
  <ul style="margin-bottom: 0;">
    <li><strong>Stolen goods.</strong> If something looks sealed with a Target/B&amp;N/Walmart sticker and the seller seems sketchy, kindly pass: "We aren't interested today." Get contact info and call Jon if unsure.</li>
    <li><strong>Firearms or weapons.</strong> No.</li>
    <li><strong>Items in Poor / Fair / Good condition</strong> at any meaningful price — they'll sit and lose money.</li>
  </ul>
</div>

<h3>After you buy</h3>
<p>Price the collection immediately unless we have AMS orders waiting (AMS is top priority, collections are second). If something's better suited to Discogs, drop it in the Discogs bin instead of the floor.</p>
HTML,
            ],
        ];
    }

    public static function find(string $slug): ?array
    {
        foreach (self::articles() as $a) {
            if ($a['slug'] === $slug) {
                return $a;
            }
        }
        return null;
    }

    public static function bySection(): array
    {
        $out = [];
        foreach (self::articles() as $a) {
            $out[$a['section'] ?? 'General'][] = $a;
        }
        foreach ($out as $section => $items) {
            usort($out[$section], function ($x, $y) {
                $cmp = ($x['sort'] ?? 0) <=> ($y['sort'] ?? 0);
                return $cmp !== 0 ? $cmp : strcmp($x['title'], $y['title']);
            });
        }
        ksort($out);
        return $out;
    }

    public static function search(string $term): array
    {
        $term = trim(mb_strtolower($term));
        if ($term === '') {
            return [];
        }
        $hits = [];
        foreach (self::articles() as $a) {
            $haystack = mb_strtolower(($a['title'] ?? '') . ' ' . ($a['section'] ?? '') . ' ' . ($a['summary'] ?? '') . ' ' . strip_tags($a['body_html'] ?? ''));
            if (mb_strpos($haystack, $term) !== false) {
                $hits[] = $a;
            }
        }
        usort($hits, function ($x, $y) use ($term) {
            $xTitle = mb_strpos(mb_strtolower($x['title']), $term) !== false ? 0 : 1;
            $yTitle = mb_strpos(mb_strtolower($y['title']), $term) !== false ? 0 : 1;
            if ($xTitle !== $yTitle) return $xTitle <=> $yTitle;
            return ($x['sort'] ?? 0) <=> ($y['sort'] ?? 0);
        });
        return $hits;
    }
}
