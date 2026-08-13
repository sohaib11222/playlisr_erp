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
        $articles = [
            [
                'slug' => 'getting-started',
                'title' => 'Getting Started with the ERP',
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
<p><a href="https://slack.com/app_redirect?channel=U07D33PMQLA" target="_blank" rel="noopener">Slack Sarah</a> with what you were looking for and we'll add it.</p>
HTML,
            ],
            [
                'slug' => 'pos-ringing-up-a-customer',
                'title' => 'How to Ring Up a Customer (POS)',
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

<div class="help-critical">
    <strong>Manager-only.</strong> Cashiers do not apply discounts. If a customer asks for one, get a manager.
</div>

<div class="help-tip">
    <strong>For reference:</strong> a manager may approve <strong>10% off purchases of $300+</strong>. There are no cash discounts.
</div>

<h3>Returns</h3>

<div class="help-critical">
    <strong>Manager-only.</strong> Only a manager can authorize a refund. If the manager is out, tell the customer it can be processed when they're back, or call Jon. Receipts are required. <strong>Used products: no returns.</strong>
</div>

<h3>Critical Don'ts</h3>

<div class="help-critical">
    <strong>Always ring the sale in the ERP.</strong> Never ring on Clover only and skip /pos/create — that's the gap that breaks inventory, reports, and reconciliation.
</div>

<div class="help-warn">
    <strong>Never zero the tax</strong> unless the entire transaction is store credit. Cash and card both pay tax.
</div>

<div class="help-warn">
    <strong>Kallax pulls.</strong> If a customer pulls a record from a Kallax under the bins, don't ring it without checking with a manager. If it does sell, immediately delete the listing from Discogs so it doesn't get double-sold.
</div>
HTML,
            ],
            [
                'slug' => 'receive-ams-shipment',
                'title' => 'How to Receive an AMS Shipment',
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

<div class="help-tip">
    <strong>Sealed buys from customers</strong> (e.g. Randy's weekly drop at Pico) follow the same flow: <strong>Purchases &rarr; Add Purchase</strong>, search the title, set supplier to the customer's name (use the "+" button to add a new supplier), pick the location, and mark received.
</div>
HTML,
            ],
            [
                'slug' => 'buy-collection-from-customer',
                'title' => 'How to Buy a Collection from a Customer',
                'section' => 'Buying',
                'sort' => 1,
                'summary' => 'The negotiation flow plus what to pay for what.',
                'page_keys' => ['buy_from_customer', 'purchase.create'],
                'body_html' => <<<'HTML'
<p>Buying collections fuels the business. The #1 priority when someone walks in with records:</p>

<div class="help-must-do">
    <strong>Get their phone or email before anything else.</strong> Even if you can't make the deal today, the contact info is the first asset.
</div>

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

<h3>Don't Buy</h3>

<div class="help-critical">
    <strong>No stolen goods.</strong> Sealed items with Target / B&amp;N / Walmart stickers from a seller who looks unhoused or evasive — kindly pass with "We aren't interested today." Get contact info and call Jon if you're unsure. Your safety comes first.
</div>

<div class="help-critical">
    <strong>No firearms or weapons.</strong> Ever.
</div>

<div class="help-warn">
    <strong>Avoid Poor / Fair / Good condition</strong> at any meaningful price — they sit and lose us money.
</div>

<h3>After you buy</h3>
<p>Price the collection immediately unless we have AMS orders waiting (AMS is top priority, collections are second). If something's better suited to Discogs, drop it in the Discogs bin instead of the floor.</p>
HTML,
            ],
            [
                'slug' => 'sales-training',
                'title' => 'Sales Training',
                'section' => 'Customer Experience',
                'sort' => 0,
                'summary' => 'How we sell at Nivessa — be present, approachable, and genuinely helpful.',
                'page_keys' => ['home', 'pos.create', 'contact.index'],
                'body_html' => <<<'HTML'
<p>Great customer service at Nivessa starts with being present, approachable, and genuinely interested in helping people discover music. This sheet is how we sell on the floor.</p>

<h3>Approach Customers Naturally</h3>
<p>When someone is digging through the bins, many are searching for something specific and simply don't know if we have it. A simple <em>"Looking for anything specific today?"</em> can start a great conversation and help us guide them to exactly what they want. A low-pressure <em>"I'm here if you need anything"</em> works just as well — it lets them know you're available without crowding them. And if you see them stop on something interesting, <em>"Did you ever hear of this one?"</em> is a natural way to open up a conversation about the record. If they'd rather be left alone, of course we respect that.</p>

<h3>Stay Visible &amp; Approachable</h3>
<ul>
    <li>Stand near customers without hovering, so it's easy for them to ask a question when they need help.</li>
    <li>Don't look distracted, buried in your phone, or too busy to assist. Customers should feel comfortable approaching you at any time.</li>
</ul>

<h3>Professional Appearance</h3>
<p>How you present yourself matters.</p>
<ul>
    <li>Dress professionally.</li>
    <li>Avoid hoodies or anything that blocks your face.</li>
    <li>Make eye contact and acknowledge customers when they enter.</li>
</ul>
<p>People are more likely to engage with someone who looks friendly and approachable.</p>

<h3>Build Connections</h3>
<p>Some of our strongest salespeople connect with customers through conversation. Henry was excellent at complimenting customers on their shirts, jackets, or other music-related clothing — Clyde and Luis do this well too. A genuine compliment creates an instant connection and makes customers feel welcome.</p>
<ul>
    <li>If someone is wearing a band shirt, talk music with them.</li>
    <li>If they're excited about an artist, share recommendations.</li>
</ul>
<p>These small interactions build relationships and create repeat customers.</p>

<h3>Share What's New</h3>
<p>Always look for opportunities to add value:</p>
<ul>
    <li>Tell customers about current sales and markdowns.</li>
    <li>Mention new releases related to artists they're buying.</li>
    <li>Recommend similar albums they may enjoy.</li>
    <li>Suggest accessories that improve their collecting experience.</li>
</ul>
<div class="help-tip">
    <strong>Example:</strong> Luis often recommends vinyl cleaning kits to customers buying records — a useful add-on that keeps their collection in great shape and improves their experience.
</div>

<h3>Who We Are</h3>
<p>Nivessa is not a prestigious record store. We are not record snobs. We are not here to make customers feel like they need a certain level of knowledge to shop with us.</p>
<p>We welcome everyone — from lifelong collectors to someone buying their first record. Our goal is to help people discover music they love: we may introduce someone to vinyl for the first time, or help them find their next favorite album.</p>
<div class="help-tip">
    <strong>Be knowledgeable. Be helpful. Be kind.</strong> Customers remember how you made them feel. When people feel welcomed, respected, and excited about music, they come back. That's the Nivessa experience.
</div>

<h3>What to Focus On</h3>
<p>Jon sets the focus each week — specific categories, genres, or items to promote to customers. Watch Slack for that callout at the start of the week. If you're not sure what's prioritized, check in with Jon.</p>

<div class="help-tip">
    <strong>How you're paid to sell:</strong> you earn <strong>2% listing commission</strong> on items that you sell in store, plus a <strong>sales goal bonus</strong> when you hit your goal for the period. See your own numbers anytime at <a href="/my-earnings" target="_blank">My Earnings</a>. (See <a href="/help/earning-at-nivessa" target="_blank">Earning at Nivessa</a> for the full breakdown.)
</div>

<hr>

<h3>Ringing Up a Sale</h3>
<ol>
    <li>Open the <strong><a href="/pos/create" target="_blank">POS</a></strong> and start a new sale.</li>
    <li>Ask the customer if they have an account with us. If not, ask if they'd like to join our rewards program — it's <strong>free</strong>.</li>
    <li>Scan or search each item. Confirm the title and price match the sticker.</li>
    <li><strong>Cashiers do not give discounts — only Jon.</strong> The only standing discount is <strong>10% off orders of $300 or more</strong>, approved by Jon. <strong>No cash discounts, ever.</strong></li>
    <li>Make sure the sale is rung up in the <strong>ERP</strong>, then type the total amount into <strong>Clover</strong>. Make sure the Clover transaction goes through — <strong>very important</strong>. Cash sales get recorded in Clover too.</li>
    <li>Hand over the receipt, done.</li>
</ol>
<div class="help-critical">
    <strong>Refunds need Jon's approval — he's the only one who can process one.</strong> Don't promise or process a refund yourself; check with Jon first.
</div>

<h3>Make SURE the Sale Hits the Clover Device</h3>
<div class="help-must-do">
    <strong>Every sale — cash AND card — has to be entered on the Clover device too.</strong>
</div>
<ul>
    <li><strong>Card sales:</strong> run the card on Clover. Wait for the <strong>approved</strong> screen before you hand anything back.</li>
    <li><strong>Cash sales:</strong> still ring it on Clover so the day balances. A cash sale that never touches Clover looks like missing money at close.</li>
    <li>After ringing, glance at the Clover screen and confirm the amount matches the POS total before moving on.</li>
</ul>
<div class="help-critical">
    <strong>Clover and the ERP must always match.</strong> If the amount or the sale itself doesn't line up between the two, please fix it before you move on. If you can't, <strong>ping Sarah</strong> — she will do it.
</div>

<h3>If Someone Wants to Sell Us a Collection</h3>
<ul>
    <li>Be friendly, take a look, and get a rough sense of size and condition.</li>
    <li>Anything large or that you're unsure about — check with Jon. <strong>Don't quote big numbers on your own.</strong></li>
    <li>Always use the <strong><a href="/buy-from-customer" target="_blank">buy calculator</a></strong> to work out a fair offer.</li>
</ul>

<h3>Using the Buy Calculator</h3>
<p>The buy form lives at <a href="/buy-from-customer" target="_blank"><code>playlist.nivessa.com/buy-from-customer</code></a>.</p>
<ol>
    <li>Open <strong><a href="/buy-from-customer" target="_blank">Buy from Customer</a></strong>.</li>
    <li>Add each item the customer is selling.</li>
    <li>The form figures the buy price per item — confirm condition is set right, since that moves the number.</li>
    <li>Review the total offer with the customer before you commit.</li>
    <li>Once they agree, complete the buy so it's recorded against inventory. Pay them out the way they chose (cash or store credit).</li>
</ol>

<hr>

<h3>How to List Products</h3>
<p>Listing is how product gets priced, located, and made sellable — and it's how you earn listing commission.</p>
<ul>
    <li><strong>Floor items:</strong> <strong>barcode every item — never hand-write the price.</strong> See <a href="/help/pricing-in-store" target="_blank">Pricing Items for the Store Floor</a>.</li>
</ul>

<h3>Sales &amp; Listing Commissions — How We Reward Both</h3>
<ul>
    <li><strong>Sales goal bonus:</strong> hit your <strong>daily</strong> sales goal on the floor and you earn a bonus on top of that — <strong>2% or 4%</strong> depending on the goal you hit. This rewards selling well in the moment, not just listing.</li>
    <li><strong>Listing commission:</strong> you earn <strong>2% of the sale price on items you listed that sell in store</strong>. The more great products you list, the more this grows over time as it sells.</li>
</ul>
<div class="help-tip">
    The "labeled / put-out" count is a stat we track. You're paid on listing commission + sales goal bonus.
</div>
<div class="help-tip">
    <strong>See your own numbers anytime at <a href="/my-earnings" target="_blank">My Earnings</a>.</strong>
</div>
HTML,
            ],
            [
                'slug' => 'customer-service-basics',
                'title' => 'Customer Service Basics',
                'section' => 'Customer Experience',
                'sort' => 1,
                'summary' => 'How to greet, help, and create a good experience on the floor.',
                'page_keys' => ['contact.index', 'home', 'pos.create'],
                'body_html' => <<<'HTML'
<p>Every customer interaction shapes whether they come back. Be friendly, attentive, and quick.</p>

<h3>The Basics</h3>
<ul>
    <li><strong>Greet everyone.</strong> A simple "Hi, welcome in" or a nod when they walk in is enough. If you're already helping someone else, acknowledge the new arrival so they know you saw them.</li>
    <li><strong>Pay attention to what they're asking for.</strong> Don't assume their taste. Ask a couple of questions to narrow it down. Know the layout so you can point them to the right bin without making them wait.</li>
    <li><strong>Keep the store inviting.</strong> Music at a reasonable volume, bins organized, walkways clear. No boxes or open stock in customer view.</li>
    <li><strong>Ring quickly and accurately.</strong> Confirm the price matches the sticker, and confirm the transaction went through on Clover before handing the bag over.</li>
</ul>

<h3>Specific Situations</h3>

<div class="help-warn">
    <strong>Don't let customers grab from the Kallaxes</strong> under the genre bins — those are pulled inventory headed to Discogs orders. If you do see one taken and they want to buy it, check with a manager first; if it sells, immediately delete it from Discogs so it doesn't double-sell.
</div>

<div class="help-warn">
    <strong>Restroom is employees only.</strong> Customers and outside visitors (including anyone filming or working a food truck) do not use it. If asked, point them to the nearest public restroom.
</div>

<div class="help-warn">
    <strong>"Jon said I can pick this up — it's already paid for."</strong> Always verify with Jon before handing anything over. Receipts are required for paid pickups. People do try this.
</div>

<h3>If a Customer Asks About Hiring</h3>
<p>Yes, we're hiring. Direct them to <code>nivessa.com/careers</code> — someone will get back to them if there's a fit.</p>
HTML,
            ],
            [
                'slug' => 'store-credit',
                'title' => 'Store Credit (Find, Use, Add)',
                'section' => 'Customer Experience',
                'sort' => 2,
                'summary' => 'Where store credit lives now and how to apply it at the register.',
                'page_keys' => ['contact.index', 'pos.create', 'customers'],
                'body_html' => <<<'HTML'
<div class="help-must-do">
    <strong>Store credit lives on the customer's record in the ERP.</strong> The old Google Sheet is no longer the source of truth — don't go searching there.
</div>

<h3>Use It at the Register</h3>
<ol>
    <li>Open <strong>POS &rarr; Create</strong>.</li>
    <li>Search the customer by name or phone. Their store-credit balance shows on their record.</li>
    <li>Add items to the sale as normal.</li>
    <li>At payment, choose <strong>Store Credit</strong> as the payment method (or split with cash/card if it doesn't cover the full total).</li>
</ol>

<div class="help-tip">
    <strong>Store-credit transactions don't pay sales tax.</strong> Cash and card sales always do.
</div>

<h3>Add Credit (Trade-In or Adjustment)</h3>
<ol>
    <li>Open the customer's record under <strong>Contacts &rarr; Customers</strong>. If they don't have a record yet, create one — name + phone is enough.</li>
    <li>Edit the credit balance to add the amount.</li>
    <li>If this came from a collection trade-in, you should have already logged the purchase under <strong>Buy from Customer</strong>; the credit added here is the customer-facing balance to spend.</li>
</ol>

<h3>Common Questions</h3>
<ul>
    <li><strong>How much credit do they have?</strong> Their record shows it.</li>
    <li><strong>They say they have credit but I can't find it?</strong> They may not have a customer record yet, or the credit may have been on the old Google Sheet and not migrated. Get Jon or Sarah.</li>
    <li><strong>Can I give credit instead of cash for a return?</strong> Manager-only — see Returns guidance.</li>
</ul>
HTML,
            ],
            [
                'slug' => 'consignment',
                'title' => 'Consignment Sales',
                'section' => 'Customer Experience',
                'sort' => 3,
                'summary' => 'Taking items on consignment and paying out the seller after sale.',
                'page_keys' => ['purchase.create', 'contact.index'],
                'body_html' => <<<'HTML'
<p>Sometimes a seller wants us to sell their items on consignment instead of buying them outright. We pay <strong>60% of the sale price</strong> to the seller after the item sells.</p>

<h3>When You Take the Item</h3>
<ol>
    <li>Get the seller's full info: name, phone, email.</li>
    <li>Create a customer record for them in <strong>Contacts &rarr; Customers</strong> if they don't have one.</li>
    <li>Log the items as a Purchase with the seller as the supplier and a note that it's consignment.</li>
    <li><strong>Put a "C" sticker on each item</strong> so the rest of the team knows it's consignment, not store-owned.</li>
</ol>

<div class="help-must-do">
    <strong>The "C" sticker is critical.</strong> Without it, we won't know to pay out the seller after the item sells. Stick it somewhere visible on the item.
</div>

<h3>After It Sells</h3>
<p>The 60% payout to the seller happens after the sale. Manager handles the payout — flag the sale to Jon if a "C"-stickered item rings up.</p>
HTML,
            ],
            [
                'slug' => 'ship-discogs-order',
                'title' => 'How to Ship a Discogs Order',
                'section' => 'Shipping',
                'sort' => 1,
                'summary' => 'Pull, label, pack, and mark a Discogs order shipped.',
                'page_keys' => ['shipping', 'discogs'],
                'body_html' => <<<'HTML'
<p>Most online orders come from Discogs, and Pico is the shipping HQ. Speed matters — orders left to sit produce unhappy customers and chargebacks.</p>

<h3>Step 1: Pull the Order</h3>
<ol>
    <li>Go to <strong>discogs.com</strong> &rarr; Orders.</li>
    <li>Filter to <strong>Payment Received</strong> (NOT "Invoice Received" — that means they haven't paid yet).</li>
    <li>Click an order. Note the location code (e.g. <code>A3</code>, <code>UZ1</code>, <code>BD3</code>) — this is the Kallax to find it in.</li>
    <li>If the location starts with <strong>HW</strong>, the item is at Hollywood. Post in <strong>#shipping</strong> on Slack to the HW puller. Example: <em>"Order #8037290-11888 needs Stevie Wonder – Songs in the Key of Life CD, HW25."</em></li>
    <li>Mark the order <strong>In Progress</strong> and leave an internal note about what's pulled vs. waiting.</li>
</ol>

<div class="help-tip">
    <strong>Can't find an item at the listed location?</strong> Check our Discogs storefront for another copy, then the bins. If still missing, ask the other store. Last resort: cancel and refund the order. Don't spend more than ~5 minutes searching for one item — move on and come back.
</div>

<h3>Step 2: Make the Label</h3>
<ol>
    <li><strong>Domestic order &rarr; ShipStation.</strong> <strong>International &rarr; PirateShip.</strong></li>
    <li>Use the <strong>Media Mail</strong> preset. Standard shipping only — never priority/first-class/UPS unless the customer paid for it.</li>
    <li>Domestic ≈ <strong>$4.47</strong>. International ≈ <strong>$13.99</strong>.</li>
    <li>For Brazil or Chile: include the recipient's <strong>CPF/RUT</strong> tax ID in PirateShip's "recipient tax identification" field. Without it, customs will block it.</li>
    <li>Check the message thread before printing — the customer may have requested a different address, no inner sleeve, or extra protection.</li>
</ol>

<h3>Step 3: Pack</h3>
<ul>
    <li>Default: <strong>Mighty Music Mailer</strong>, holds 1–6 records.</li>
    <li>CDs/cassettes: bubble mailer, holds 3–4.</li>
    <li>7-inch: modify the mailer so the record can't slide.</li>
    <li>Tape perpendicularly (so if one strip fails, the other holds).</li>
    <li>Apply the label — no creases, rips, or tape across the address or barcode.</li>
</ul>

<div class="help-warn">
    <strong>Items over $50:</strong> add extra cardboard slips and put the record in a plastic bag inside the mailer. Lost or damaged high-value orders are expensive to replace.
</div>

<div class="help-must-do">
    <strong>Always send tracking</strong> — mark the order Shipped on Discogs so the tracking number reaches the customer.
</div>

<h3>Refunds</h3>
<ul>
    <li>If you couldn't find an item: send a partial refund through the order page (<em>More &rarr; Send Refund &rarr; Send Partial Refund</em>) and message the customer.</li>
    <li><strong>Any refund over $20: ask Jon first.</strong></li>
</ul>
HTML,
            ],
            [
                'slug' => 'whatnot-orders',
                'title' => 'Whatnot Orders (After-Show Packing)',
                'section' => 'Shipping',
                'sort' => 2,
                'summary' => 'Pulling and shipping Whatnot show orders after Golden\'s daily auctions.',
                'page_keys' => ['shipping', 'whatnot'],
                'body_html' => <<<'HTML'
<p>Golden hosts Whatnot auctions daily at Pico. After each show, you'll get an email from Whatnot with a "Start Shipping" link to that show's order page.</p>

<h3>Step 1: Open the Order Page</h3>
<ol>
    <li>Open the Whatnot email subject "Summary of your Whatnot show – Diggin' with GOLDNBROWN."</li>
    <li>Click <strong>Start Shipping</strong>.</li>
    <li><strong>Check the date</strong> matches the show you're packing. Multiple shows in a row can confuse the page.</li>
</ol>

<h3>Step 2: Generate Labels in Bulk</h3>
<ol>
    <li>Check the box at the top of the recipient column to select all orders.</li>
    <li>Under bulk actions on the right, click <strong>Generate Labels</strong> (yellow). When it finishes, the button turns black and reads <strong>Export Shipping Labels / Slips</strong>.</li>
    <li>Click it, choose to include shipping labels, view, and print. Labels print in bulk in show order.</li>
</ol>

<h3>Step 3: Match and Pack</h3>
<p>During the show, the host stickers each record with the buyer's username. Match the username on each record to the bottom of the packing slip.</p>

<div class="help-warn">
    <strong>If a record has no username sticker</strong>, do not ship it. Ask the host (Golden) before sending anything that's unmarked. Wrong-customer ships on Whatnot are painful to unwind.
</div>

<ul>
    <li>Multi-item orders: pack all items together.</li>
    <li>Pack as you would any Discogs order — barcode and address must scan cleanly.</li>
</ul>

<h3>Whatnot Customer Service</h3>
<p>Refund requests come in as messages on the Whatnot homescreen. Most cases are handled by issuing a credit:</p>
<ul>
    <li><strong>For Pico:</strong> drop a sticky note near Golden's desk with the customer's name and credit amount. He'll handle it.</li>
    <li><strong>If the customer absolutely needs a refund</strong>, direct them to contact Whatnot — we approve or deny from there.</li>
    <li><strong>Unsure?</strong> Ask the host of the show.</li>
</ul>
HTML,
            ],
            [
                'slug' => 'opening-checklist',
                'title' => 'Opening Checklist',
                'section' => 'Opening & Closing',
                'sort' => 1,
                'summary' => 'What to do in the first 15 minutes of every shift.',
                'page_keys' => ['home'],
                'body_html' => <<<'HTML'
<div class="help-must-do">
    <strong>Arrive at least 15 minutes before opening.</strong> Setup takes time and customers will start walking up to the door at the dot.
</div>

<h3>Pico — Opening Steps</h3>
<ol>
    <li>Unlock the front door: turn the key <strong>left</strong> on the glass door, <strong>right</strong> on the metal door.</li>
    <li>Clock in on Clover.</li>
    <li>Turn on front lights, backroom lights, and flip the back-wall switch that powers the fans + record player.</li>
    <li>Turn on the computer. Put on good music.</li>
    <li>Set the A-frame out by the curb.</li>
    <li>Count the register cash and log the opening total.</li>
    <li>Check walls and bins are stocked. Fix any messy bins.</li>
    <li>Refill end caps with featured albums.</li>
    <li>Clear front-desk clutter.</li>
    <li>Sweep or vacuum the floor.</li>
    <li>Check the bathroom — tidy, trash out.</li>
    <li>Check Discogs for new orders + messages.</li>
</ol>

<h3>Hollywood — Opening Steps</h3>
<ol>
    <li>If you don't have a key, the lockbox key is at the front of the gate. The code is <code>1492</code>.</li>
    <li>Use the key to unlock the front door.</li>
    <li>Turn on all main-room lights and the computer. Music up loud — outside too.</li>
    <li>Plug in the neon signs:
        <ul>
            <li>"Welcome to Digger's Paradise" — plug behind the listening station.</li>
            <li>"Have you heard it on vinyl" — plug behind the rock bins.</li>
            <li>"Disco es la cultura" — plug into the wall on stage.</li>
        </ul>
    </li>
    <li>Check walls and bins. Fix any out-of-place sections.</li>
    <li>Refill end caps with featured albums.</li>
    <li>Clear front desk.</li>
    <li>Sweep or vacuum.</li>
    <li>Check bathroom.</li>
    <li>Open the doors to welcome customers.</li>
</ol>

<div class="help-tip">
    Drop a note in <strong>#shift-notes</strong> on Slack at the start of your shift if anything is off (low stock, broken equipment, missing signage). The next person picks up where you left off.
</div>
HTML,
            ],
            [
                'slug' => 'closing-checklist',
                'title' => 'Closing Checklist',
                'section' => 'Opening & Closing',
                'sort' => 2,
                'summary' => 'Lock up the right way at end of shift.',
                'page_keys' => ['home'],
                'body_html' => <<<'HTML'
<h3>Pico — Closing Steps</h3>
<ol>
    <li>Tidy the sales floor and restock end caps with featured albums.</li>
    <li>Clear the front desk.</li>
    <li>Sweep or vacuum the floor.</li>
    <li>Check the bathroom — tidy, lights off, trash emptied.</li>
    <li>Take the trash out.</li>
    <li>Bring the A-frame back inside.</li>
    <li>Turn off the computer, vinyl player, and front fan.</li>
    <li>Turn off all backroom lights, bathroom light, the "Diggers Paradise" neon, the front main light, and the lamp by the vinyl player.</li>
    <li><strong>Lock up:</strong> grab the lock from the hook behind the desk, join the metal gates together, and lock them. Pull the brown gate flush with the door and all the way to the right.</li>
    <li>Close the glass door and lock it (turn the key right until it clicks). <strong>Double-check it's locked.</strong></li>
</ol>

<h3>Hollywood — Closing Steps</h3>
<ol>
    <li>Tidy the floor, restock featured displays.</li>
    <li>Clear the front desk.</li>
    <li>Sweep or vacuum.</li>
    <li>Check bathroom — tidy, lights off, trash emptied.</li>
    <li>Empty all trash bins.</li>
    <li>Shut down the front-desk computer.</li>
    <li>Unplug the three neon signs (listening station, behind rock bins, on-stage).</li>
    <li>Turn off the vinyl player.</li>
    <li>Turn off all lights including bathroom.</li>
    <li>Bring the A-frame in if it's outside.</li>
    <li><strong>Lock the front door</strong> with the two bottom locks. Lower the gate using the buttons on the right wall.</li>
    <li>Exit through the back door — confirm it's locked behind you.</li>
    <li>Place the key back in the lockbox at the front of the gate. <strong>Scramble the code.</strong></li>
</ol>

<div class="help-must-do">
    <strong>Update <code>#shift-notes</code> on Slack at end of shift.</strong> What you did, what's left, in-store sales total, anything broken or low. The next person reads this first.
</div>
HTML,
            ],
            [
                'slug' => 'safety-and-suspicious-customers',
                'title' => 'Safety & Suspicious Customers',
                'section' => 'Safety',
                'sort' => 1,
                'summary' => 'Who to call, what to watch for, and how to handle uncomfortable situations.',
                'page_keys' => ['home'],
                'body_html' => <<<'HTML'
<div class="help-critical">
    <strong>Your safety comes first.</strong> If a situation feels dangerous, do not engage. Move to a safe area, call for help, and let a manager know. Money and merchandise can be replaced.
</div>

<h3>If Someone Acts Aggressive or Unstable</h3>
<ol>
    <li><strong>Do not engage.</strong> Don't argue, don't make eye contact, don't try to reason with them. Get to a safe area.</li>
    <li><strong>Call or text the Hollywood Partnership: <code>567-459-9663</code></strong> — they respond faster than the police. Save this number in your phone now.</li>
    <li>Tell <strong>Sarah or Jon</strong> immediately so they can call the police if needed.</li>
    <li>Make sure exits stay accessible. Stay calm.</li>
    <li>Once it's safe, write up what happened.</li>
</ol>

<h3>Signals to Watch For</h3>
<ul>
    <li>Someone hiding items or moving them under clothing.</li>
    <li>Avoiding eye contact while holding merchandise.</li>
    <li>Moving between sections quickly without browsing.</li>
    <li>Carrying large stacks of items without buying anything.</li>
</ul>

<div class="help-tip">
    Stay attentive but don't confront. Acknowledge them with a hello — knowing they've been seen often deters theft on its own. If something feels wrong, get a coworker or manager involved discreetly.
</div>

<h3>Suspicious Sellers</h3>

<div class="help-warn">
    <strong>Sealed items with Target / B&amp;N / Walmart stickers</strong> from a seller who looks unhoused or evasive — likely stolen. Kindly pass: "We aren't interested today" or "We're not purchasing today." Get their contact info if you can, and call Jon if unsure.
</div>

<p>Record-company employees occasionally sell us their surplus sealed inventory — that's legitimate. Use your judgment based on the seller, not the items.</p>

<h3>Bathroom & Carrying Help</h3>
<ul>
    <li><strong>Restroom is employees only.</strong> Customers and outside visitors do not use it.</li>
    <li><strong>Carrying collections:</strong> if a seller has a large collection and asks for help, you can offer if you feel comfortable — but you're not obligated. Your safety first.</li>
</ul>

<h3>Locking Up</h3>
<ul>
    <li>Basement / warehouse must stay locked when no one's in there.</li>
    <li>Front gate must be locked at end of shift.</li>
    <li>If anything in the store looks off when you arrive (broken lock, door not fully closed, missing inventory), don't enter — call Jon first.</li>
</ul>
HTML,
            ],
            [
                'slug' => 'code-of-conduct',
                'title' => 'Code of Conduct (What We\'re Serious About)',
                'section' => 'Conduct',
                'sort' => 1,
                'summary' => 'Behaviors that lead to disciplinary action up to termination — read this once.',
                'page_keys' => ['home'],
                'body_html' => <<<'HTML'
<p>Trust and integrity are the cornerstone of how Nivessa runs. Most of the rules below are obvious, but they're written down because they've come up before.</p>

<h3>Strictly Prohibited</h3>

<div class="help-critical">
    <strong>Theft</strong> — cash, merchandise, or self-pricing for personal gain. Grounds for immediate termination and possible legal action.
</div>

<div class="help-critical">
    <strong>Pocketing cash.</strong> Don't.
</div>

<div class="help-critical">
    <strong>Lying about purchased collections</strong> — what was bought, what was paid, what was traded.
</div>

<div class="help-critical">
    <strong>Wage theft</strong> — manipulating hours, skipping clock-out for breaks, not getting paid overtime. This is illegal and we won't allow it on either side.
</div>

<div class="help-warn">
    <strong>Buying from customers off-the-books.</strong> All collection purchases happen through the store's official channels — no private side deals with customers for inventory.
</div>

<div class="help-warn">
    <strong>Clocking in for someone else.</strong> Each person clocks themselves in and out. No exceptions.
</div>

<div class="help-warn">
    <strong>Failing to clock out for lunch breaks.</strong> Record your time accurately.
</div>

<div class="help-warn">
    <strong>Lying about pricing</strong> to benefit yourself or someone else.
</div>

<div class="help-warn">
    <strong>Bad-mouthing the company</strong> in person or online. If you have concerns, bring them directly to a manager — Jon will always make time, even if he's busy.
</div>

<h3>Reporting</h3>
<p>If you witness or suspect any of the above, tell a manager. All reports are confidential. Retaliation against anyone who reports in good faith won't be tolerated.</p>

<h3>Professional Conduct</h3>
<ul>
    <li>Respect customers, coworkers, and management. Zero tolerance for discrimination, harassment, or misconduct.</li>
    <li>No politics at work. We want a neutral, inclusive space.</li>
    <li>No gossip. If something's bothering you about a coworker or a process, take it directly to a manager.</li>
    <li>Confidentiality — customer data, business strategies, internal pricing, anything you see in the ERP — stays inside Nivessa.</li>
</ul>

<h3>Show Up</h3>
<p>If you're scheduled, show up on time and ready. Two no-call-no-shows lose hours. Emergencies happen — just give us a heads-up. We're flexible when we know in advance.</p>
HTML,
            ],
            [
                'slug' => 'computer-or-printer-issues',
                'title' => 'When the Computer or Printer Breaks',
                'section' => 'Operations',
                'sort' => 1,
                'summary' => 'Who to call when the front-desk computer or label printer stops working.',
                'page_keys' => ['home', 'labels'],
                'body_html' => <<<'HTML'
<p>If the computer or label printer at the front desk isn't working, get in touch with Muhammad — he's based in Bangladesh and handles tech support for the stores remotely via AnyDesk.</p>

<div class="help-must-do">
    <strong>Message Muhammad on WhatsApp at <a href="https://wa.me/8801723948653">+880 1723-948653</a></strong> for any computer or printer issue. He can take remote control and fix most problems on the spot.
</div>

<h3>Before You Reach Out</h3>
<ul>
    <li><strong>Check the sensor for anything sticky.</strong> Open the label printer and look at the sensor — remove any sticky residue or a label stuck inside. A blocked sensor is a common cause of the printer "not working."</li>
    <li><strong>Restart first.</strong> A fresh power cycle of the computer or printer fixes a surprising amount of stuff.</li>
    <li><strong>Check paper + ribbon</strong> on the Zebra label printer. "It's broken" is often "out of labels."</li>
    <li><strong>Check the cable.</strong> USB unplugged is the most common label-print issue.</li>
</ul>

<h3>Changing the Label Roll (Zebra printer)</h3>
<ol>
    <li><strong>Pull the two yellow tabs</strong> on the side of the printer outward. These two yellow dispensers hold the roll in place — widening them releases it.</li>
    <li><strong>Pull the old roll out</strong> and remove the leftover paper.</li>
    <li><strong>Drop the new roll in</strong>, then let the yellow tabs close back in to hold it.</li>
</ol>

<h3>How to Reach Muhammad</h3>
<ol>
    <li><strong>Open WhatsApp</strong> and message Muhammad at <strong>+880 1723-948653</strong>. Tell him what's broken, what you've already tried, and which device.</li>
    <li><strong>He'll ask for your AnyDesk code.</strong> On the front-desk computer, hit the Windows key and search for <strong>AnyDesk</strong> — the program is already installed. Open it.</li>
    <li><strong>Read him the 9-digit "Your Address" code</strong> shown in AnyDesk. Send it on WhatsApp.</li>
    <li><strong>Accept the incoming connection</strong> when his request pops up. He'll take over the computer remotely and work on the issue.</li>
</ol>

<p>Stay near the computer while he's connected in case he needs you to physically check a cable, swap a label roll, or power-cycle the printer.</p>

<h3>If the Whole System Is Down</h3>
<p>If you can't ring sales because the ERP itself is down, get a manager <strong>immediately</strong> — don't keep ringing on Clover only. Lost ERP transactions break inventory and reporting.</p>
HTML,
            ],
            [
                'slug' => 'pricing-in-store',
                'title' => 'Pricing Items for the Store Floor',
                'section' => 'Pricing',
                'sort' => 1,
                'summary' => 'How to price vinyl, CDs, and other media for in-store sale.',
                'page_keys' => ['product.create', 'home'],
                'body_html' => <<<'HTML'
<p>The goal is to price quickly and accurately so items move. Discogs is the main reference for vinyl/CD/cassette market prices. eBay sold listings cover everything else.</p>

<h3>Vinyl, CDs, Cassettes — the Discogs Method</h3>
<ol>
    <li>Use the barcode scanner on the Discogs search bar to pull up the release ID. Sometimes you'll get multiple matches — judge by label, jacket art, country, and notes to pick the right pressing.</li>
    <li><strong>Grade the item</strong> using the Goldmine standard: Mint (M), Near Mint (NM), Very Good Plus (VG+), Very Good (VG), Good Plus (G+), Good (G), Fair (F), Poor (P).</li>
    <li><strong>Price it as: Discogs market price + half the lowest available shipping rate.</strong>
        <ul>
            <li>If lowest shipping is ~$5, add $2.50 to the price.</li>
            <li>If lowest shipping is $25, add $12.50.</li>
        </ul>
    </li>
</ol>

<div class="help-tip">
    <strong>Cover worse than the vinyl?</strong> Knock the price down 5–10% from the matching vinyl-grade price. Don't weight the cover heavily — the media is what matters most.
</div>

<h3>No Competing Sellers on Discogs?</h3>
<ul>
    <li>If wantlist &lt; 25 wants: price at <strong>$25</strong>, or match recent sales history (whichever is greater).</li>
    <li>If never sold or wantlist &gt; 25 with no competition: <strong>$1 per want</strong>. (650 wants → $650.)</li>
</ul>

<h3>When to Send to Discogs Instead of the Floor</h3>
<ul>
    <li>If an item is over $70 AND not on our top-150-sold-artists list, list it on Discogs instead of putting it on the floor.</li>
    <li>If it's better-suited online (rare/collector), drop it in the Discogs bin during your shift.</li>
</ul>

<h3>Other Media (eBay-Based)</h3>
<p>For non-Discogs items, find comparable listings on eBay and switch to <strong>Sold</strong> filter for accurate prices. We're open to other marketplaces too — Depop for clothing, etc.</p>

<h3>Quick Reference for Used Items</h3>
<ul>
    <li><strong>CDs:</strong> Common titles priced low to move. Box sets, early pressings, out-of-print → check Discogs/eBay.</li>
    <li><strong>DVDs:</strong> Common titles low for quick turn. Box sets / collector editions → check eBay sold.</li>
    <li><strong>Magazines:</strong> Music or pop culture may have collector value. eBay sold listings if unsure.</li>
    <li><strong>Clothing:</strong> Vintage band shirts → check eBay. Basics → cheap to encourage impulse buys.</li>
</ul>

<h3>Sticker Format</h3>

<div class="help-must-do">
    <strong>Always write the genre AND the price on the sticker.</strong> Example: <code>$14 ROCK</code>. If something gets misplaced, the genre tells anyone where to put it back.
</div>
HTML,
            ],
            [
                'slug' => 'list-on-discogs',
                'title' => 'How to List an Item on Discogs',
                'section' => 'Listing',
                'sort' => 1,
                'summary' => 'Find the release, grade it, set location, price competitively.',
                'page_keys' => ['discogs', 'listing'],
                'body_html' => <<<'HTML'
<p>Listing on Discogs is straightforward — the only place to be careful is matching the exact edition. Wrong edition = either underselling a valuable record or selling something we have to refund.</p>

<h3>Step 1: Find the Right Release</h3>
<p>Scan the barcode if there is one. If not, type the catalog code from the back sleeve or the dead-wax matrix.</p>

<div class="help-warn">
    <strong>Edition matters.</strong> Different pressings of the same album can vary by hundreds of dollars. Confirm <em>all</em> of these match before listing:
    <ul>
        <li>Fonts on the cover</li>
        <li>Stereo vs. mono</li>
        <li>Record company / label</li>
        <li>How artists are credited (e.g. "Miles Davis" vs. "M. Davis" vs. "Miles. D")</li>
        <li>Cover art and the inner sleeve type</li>
        <li>Vinyl color</li>
    </ul>
</div>

<h3>Step 2: Grade It</h3>
<p>Use the Goldmine standard for both <strong>Media Condition</strong> and <strong>Sleeve Condition</strong>. If unsure of grading, refer to the in-store grading videos or ask a coworker.</p>

<h3>Step 3: Notes</h3>
<p>Use <strong>Item condition comments</strong> for anything noteworthy — especially on more expensive records. If sealed, note it here.</p>

<h3>Step 4: Location</h3>

<div class="help-must-do">
    <strong>Always set the location.</strong> We've lost hundreds of records due to wrong/missing locations. Pick the right Kallax for your store (Pico or Hollywood), match the folder number to the bin (FL 1 → location FL 1).
</div>

<h3>Step 5: Price</h3>
<ul>
    <li>Minimum listing price: <strong>$5</strong>.</li>
    <li>Above $5: price competitively against current listings of the same condition and pressing. Don't be the absolute cheapest unless inventory is slow-moving.</li>
    <li>If condition is borderline, price slightly under comparables to head off disputes.</li>
</ul>

<h3>Step 6: List + Place It</h3>
<p>Click <strong>List Item for Sale</strong> and put the record physically into the Kallax location you chose. The two must match.</p>

<div class="help-warn">
    <strong>Don't overfill cardboard boxes</strong> in the warehouse. Over time the cardboard breaks down, and overfilled boxes break sooner.
</div>
HTML,
            ],
            [
                'slug' => 'photo-upload-fl-bins',
                'title' => 'Photo Upload (FL Bins) — Warehouse Process',
                'section' => 'Listing',
                'sort' => 2,
                'summary' => 'How to photograph, fold, and shelve records for the listing team.',
                'page_keys' => ['listing'],
                'body_html' => <<<'HTML'
<p>Photo Upload is for the warehouse team — the records get listed by someone else later, so the photos need to communicate everything that person needs to know.</p>

<h3>How to Photograph</h3>
<ul>
    <li><strong>One photo per item.</strong></li>
    <li>Pull the record halfway out of the sleeve so both the front cover and the vinyl are visible.</li>
    <li><strong>Spine damage or split cover?</strong> Hold a written card in the photo that says <code>G+ cover</code> (or whatever grade applies).</li>
    <li>Anything else important about the cover or record? Write a note and include it in the photo.</li>
</ul>

<h3>FL Bin Numbering</h3>
<ol>
    <li>Take all photos from one bin, then put them in a folder named <strong>FL [number]</strong> using the next sequential number, with the grade in parentheses. Example: <code>FL 308 (VG)</code>.</li>
    <li>Mark the spreadsheet with the latest number used.</li>
    <li><strong>Maximum 50 vinyls per folder.</strong></li>
</ol>

<h3>Shelving</h3>
<ul>
    <li>Put the records into a partition labeled with the matching FL number on a 4×6 white sticker. Example: <code>FL 308 (VG)</code>.</li>
    <li>Place the new partition on the shelf <strong>sequentially after</strong> the previous one — never on the floor.</li>
    <li>If there's room on the shelf for more records after this partition, add a white divider and label it on the supporting structure below.</li>
</ul>

<div class="help-must-do">
    <strong>Sequential numbering is non-negotiable.</strong> When the listing team can't find FL 308 because it was filed between FL 290 and FL 295, hours get wasted.
</div>
HTML,
            ],
            [
                'slug' => 'discogs-messages-and-returns',
                'title' => 'Discogs Customer Messages & Returns',
                'section' => 'Shipping',
                'sort' => 3,
                'summary' => 'Responding fast, partial refunds, condition disputes, and return labels.',
                'page_keys' => ['shipping', 'discogs'],
                'body_html' => <<<'HTML'
<p>We get 30–50 messages a day on Discogs. Slow responses cost us sales and feedback. Most messages are about an existing order.</p>

<h3>Common Scenarios</h3>

<h4>"My order is missing an item."</h4>
<ol>
    <li>Check the order's message thread — did an employee already say it was missing?</li>
    <li>If yes, confirm a refund went out and message the customer.</li>
    <li>If no dialogue, check the bin / location. If still missing, send a refund for that item and message the customer.</li>
</ol>

<h4>"The condition / edition is wrong."</h4>
<ol>
    <li>Ask the customer to send pictures to <strong>orders@nivessa.com</strong>.</li>
    <li>When pictures arrive, decide on a <strong>partial refund</strong> — usually 30–60% of the item price depending on severity.</li>
    <li>If they refuse the partial, offer a full refund <strong>only if they return the item</strong>. Email them a domestic return label.</li>
    <li>International returns: we reimburse up to <strong>$13.99</strong> for shipping. They send it on their own.</li>
</ol>

<div class="help-warn">
    <strong>Refunds over $20 — ask Jon first.</strong> Always.
</div>

<h4>"Can I see photos of this record before I buy?"</h4>
<p>Our seller terms: we don't provide pre-purchase photos for items under <strong>$40</strong>. For items at or above $40, take photos and send to the email they provide.</p>

<h4>"Can you take less for it?"</h4>
<p>Contact Jon — ask how much he'd go down. Don't negotiate on your own.</p>

<h3>Discogs Returns</h3>
<ol>
    <li>For domestic returns: create a label with <strong>Pico's address as ship-to</strong> and the customer's address as ship-from.</li>
    <li>Email the label as a PDF to the customer's email.</li>
    <li>For international: customer creates their own label. We rebate up to $13.99 for shipping but not customs/tax fees.</li>
</ol>

<h3>Feedback</h3>
<ul>
    <li>If a customer had a good experience, ask for positive feedback.</li>
    <li>For Brazil/Chile orders, get the CPF/RUT before processing — required for customs.</li>
</ul>

<h3>Disputing Negative Feedback</h3>
<ol>
    <li>Go to the buyer rating tab on our profile.</li>
    <li>Find the feedback, click the dropdown to the right, dispute it. Be honest — Discogs reviews and usually removes within hours.</li>
    <li>You can also offer the customer a partial refund or future credit in exchange for them removing the negative feedback.</li>
</ol>
HTML,
            ],
            [
                'slug' => 'shift-notes',
                'title' => 'After-Shift Notes (#shift-notes on Slack)',
                'section' => 'Opening & Closing',
                'sort' => 3,
                'summary' => 'What to post in Slack at the end of every shift so the next person picks up smoothly.',
                'page_keys' => ['home'],
                'body_html' => <<<'HTML'
<p>Every shift ends with an update in <code>#shift-notes</code> on Slack. The goal is to leave the next person with a clear picture: what got done, what's left, and anything they should know.</p>

<div class="help-must-do">
    <strong>Always post a shift note before you leave.</strong> Even on a quiet day. The pattern is what makes it useful.
</div>

<h3>What to Include</h3>
<ul>
    <li><strong>What you shipped, listed, or rang up.</strong> Example: "Shipped 5 packages, 20 more remaining — need help."</li>
    <li><strong>What's still in progress.</strong> Half-pulled Discogs orders, collections waiting to be priced.</li>
    <li><strong>Supplies low or out.</strong> "Need plastic bags." "Almost out of mighty mailers."</li>
    <li><strong>Damaged signage, broken equipment, anything visibly off.</strong></li>
    <li><strong>In-store sales total for the day</strong> — helps us track busy times.</li>
    <li><strong>Highlights and lowlights.</strong> A great sale, a tricky customer, a process idea.</li>
</ul>

<h3>Why This Matters</h3>
<p>The next person reads this first when they walk in. A 60-second note saves 10 minutes of "what's going on?" the next morning.</p>
HTML,
            ],
            [
                'slug' => 'shifts-and-sling',
                'title' => 'Shifts &amp; Sling',
                'section' => 'Shifts & Schedule',
                'sort' => 1,
                'summary' => 'How to claim shifts, request time off, trade with coworkers, and the role list.',
                'page_keys' => ['home'],
                'body_html' => <<<'HTML'
<p>Scheduling lives in <strong>Sling</strong>. The ERP doesn't manage shifts — but the choices you make in Sling affect everything that happens here, so this is the rulebook.</p>

<h3>Claiming Shifts</h3>
<ul>
    <li>Open Sling and check available shifts. Claim as many as you want.</li>
    <li>The manager approves each request — don't show up unless you've been approved.</li>
</ul>

<h3>Time Off</h3>
<div class="help-must-do">
    <strong>Give 2 weeks' notice for time off.</strong> Mark your shift as <strong>"available"</strong> in Sling so someone else can claim it. Tell the manager directly too.
</div>
<ul>
    <li>We're flexible when we know in advance.</li>
    <li>Emergencies happen — call the manager as soon as you know.</li>
</ul>

<h3>Trading Shifts</h3>
<p>You can trade with another employee inside Sling. The manager has to approve the trade — initiate it in the app, don't just hand off informally.</p>

<h3>Schedule Changes</h3>
<p>Always give the manager a heads-up on anything that affects the schedule. Surprises break the day for everyone.</p>

<h3>Hours &amp; Overtime</h3>
<div class="help-warn">
    <strong>40 hours/week is the cap.</strong> Overtime requires manager approval in advance. Don't run yourself into OT without it being signed off.
</div>

<h3>Reliability</h3>
<div class="help-critical">
    <strong>Two no-call-no-shows = you lose hours.</strong> If you're scheduled, show up on time and ready. We depend on each other.
</div>

<h3>Roles at Nivessa</h3>
<ul>
    <li><strong>Front Desk</strong> — runs the register, helps customers, lists products in the ERP.</li>
    <li><strong>Shipping</strong> — packs and ships online orders.</li>
    <li><strong>Product &amp; Floor Lead</strong> — prices, organizes, restocks the floor.</li>
    <li><strong>Photo Upload</strong> — lists products on Discogs.</li>
    <li><strong>Moving Shift</strong> — moves products between stores or storage areas.</li>
    <li><strong>Inventory Check</strong> — counts and verifies stock levels.</li>
</ul>
HTML,
            ],
            [
                'slug' => 'floor-lead-checklist',
                'title' => 'Floor Lead Checklist',
                'section' => 'Operations',
                'sort' => 2,
                'summary' => 'Daily routine for keeping the sales floor organized and presentable.',
                'page_keys' => ['home'],
                'body_html' => <<<'HTML'
<p>The Floor Lead's job is the visible store — what every customer sees when they walk in. The list below is what gets done on every floor-lead shift.</p>

<h3>Trash &amp; Recycling</h3>
<ul>
    <li>Throw out trash from downstairs.
        <ul>
            <li><strong>Hollywood:</strong> parking lot behind the store.</li>
            <li><strong>Pico:</strong> behind our store — <strong>NOT behind the cafe</strong>.</li>
        </ul>
    </li>
    <li>Flatten cardboard and recycle in the blue bins.</li>
</ul>

<h3>Floor &amp; Bins</h3>
<ul>
    <li>Walk the floor and check for misplaced products.</li>
    <li>Records upright in bins — no leaning piles. Fix any messy bins.</li>
    <li>Refill end caps, walls, and the new-CD section near the front desk with featured albums.</li>
    <li>Refill window displays.</li>
</ul>

<h3>Clothing &amp; Merch</h3>
<ul>
    <li>Sort and display artist shirts properly.</li>
    <li>Anything cracked / damaged / unsellable: ask Jon before tossing — even old-looking items can have value.</li>
</ul>

<h3>Signage &amp; Display</h3>
<ul>
    <li>Signage check — section signs lined up, in the right place.</li>
    <li>Stage looks tidy.</li>
    <li>Window displays look good. Wipe with Windex if dirty.</li>
    <li><strong>No boxes or open stock in customer view.</strong></li>
</ul>

<h3>Sweep</h3>
<p>Sweep the floor before the next shift comes in.</p>

<h3>Report Back in #shift-notes</h3>
<div class="help-must-do">
    Note anything that needs attention next shift:
    <ul style="margin-bottom:0;">
        <li>Missing or damaged section signs that need to be reprinted.</li>
        <li>Any area low on product or missing filler.</li>
    </ul>
</div>
HTML,
            ],
            [
                'slug' => 'earning-at-nivessa',
                'title' => 'Earning at Nivessa (Hours, Raises, Bonuses)',
                'section' => 'Pay & Growth',
                'sort' => 1,
                'summary' => 'How to get more hours, take on responsibility, and earn extra.',
                'page_keys' => ['home'],
                'body_html' => <<<'HTML'
<p>Hard work doesn't go unnoticed. Productivity, efficiency, and a get-it-done attitude get rewarded with more hours, raises, and leadership opportunities.</p>

<h3>More Hours</h3>
<p>Sling shows what's available. Claim what you want — if you're efficient and reliable, more hours and a raise tend to follow.</p>

<h3>Take on Responsibility</h3>
<ul>
    <li><strong>Take initiative.</strong> If you see something that needs doing — organizing, helping with inventory, a new idea — jump in. The more initiative, the more responsibility (and rewards) you'll earn.</li>
    <li><strong>Leadership roles</strong> open up as Nivessa grows. They come with added responsibility and pay.</li>
</ul>

<h3>Bonuses</h3>
<p>If the store hits sales targets, the team shares in the success. The exact structure is being worked out — Jon will share it as it firms up.</p>

<h3>Host a Whatnot Show</h3>
<p>You can host a Whatnot show anytime if you like being on camera and selling. These are paid jobs and with 200,000+ products there's plenty to sell. Check out Golden's daily Whatnot from Pico for what good looks like.</p>

<h3>Expense Reimbursement</h3>
<div class="help-warn">
    <strong>Don't buy things for the store with your own money</strong> without prior approval from Jon. We can't reimburse all expenses — small business budgets are tight.
</div>

<div class="help-tip">
    <strong>Buying a collection?</strong> Jon can Zelle or Venmo the seller directly — just hold onto the merchandise until it's settled. Don't front cash unless he's confirmed.
</div>
HTML,
            ],
            [
                'slug' => 'sick-leave-and-benefits',
                'title' => 'Sick Leave (California Compliance)',
                'section' => 'Pay & Growth',
                'sort' => 2,
                'summary' => 'How sick leave accrues and when you can use it.',
                'page_keys' => ['home'],
                'body_html' => <<<'HTML'
<p>California law sets the rules; here's how it works at Nivessa.</p>

<h3>How It Accrues</h3>
<ul>
    <li><strong>1 hour of paid sick leave for every 30 hours worked</strong>, starting your first day.</li>
    <li>Accrual is automatic — you don't need to request anything.</li>
</ul>

<h3>When You Can Use It</h3>
<ul>
    <li>You can start using accrued sick leave <strong>after 90 days</strong> of employment.</li>
    <li><strong>Max 24 hours (3 days) usable per calendar year.</strong></li>
    <li>Can be used for personal illness, medical appointments, or to care for a family member.</li>
</ul>

<h3>The Cap</h3>
<ul>
    <li><strong>Max accruable:</strong> 48 hours at any one time. Once you hit 48, you stop accruing until you use some.</li>
    <li>Unused sick time carries over year to year, up to the 48-hour ceiling.</li>
</ul>

<div class="help-tip">
    Calling out sick? Let your manager know as soon as you know. Even more so if you're scheduled to open or close.
</div>
HTML,
            ],
            [
                'slug' => 'hosting-events',
                'title' => 'Hosting Events at the Store',
                'section' => 'Operations',
                'sort' => 3,
                'summary' => 'Setup, running the night, and keeping the storefront fresh.',
                'page_keys' => ['home'],
                'body_html' => <<<'HTML'
<p>Events bring new people in and remind regulars why they love the store. The bar is: clean setup, friendly hosting, safe environment.</p>

<h3>Before Doors</h3>
<ul>
    <li>Stage, equipment, and space prepared <strong>well before guests arrive</strong>.</li>
    <li>Sound check — don't troubleshoot a mic for the first time when 40 people are watching.</li>
    <li>Floor walked, no boxes or open stock in customer view.</li>
</ul>

<h3>During the Event</h3>
<ul>
    <li>Help guide guests as they come in.</li>
    <li>Maintain a safe and enjoyable environment — same standards as a regular shop day.</li>
    <li>Restroom stays employees only, even during events.</li>
</ul>

<h3>The Outside Frame</h3>
<p>The A-frame / outside display should feature <strong>interesting, eye-catching records</strong> that represent the store's personality. Change it periodically — anyone walking by who's seen the same record for two weeks stops noticing.</p>

<h3>After</h3>
<ul>
    <li>Reset the floor before closing.</li>
    <li>Trash out, sweep, lights off, lock up — same closing checklist as any night.</li>
    <li>Drop a note in <code>#shift-notes</code> with how it went, headcount, and anything that broke.</li>
</ul>
HTML,
            ],
            [
                'slug' => 'cataloging-older-inventory',
                'title' => 'Cataloging Older Inventory (Barcoding)',
                'section' => 'Listing',
                'sort' => 3,
                'summary' => 'Getting unbarcoded items into the ERP so checkout, reports, and stock counts work.',
                'page_keys' => ['product.create', 'labels'],
                'body_html' => <<<'HTML'
<p>The goal is <strong>every item in the store has an ERP record and a barcode</strong>. The further we get from that, the more guesswork at the register and the less reliable our reports.</p>

<h3>Why It Matters</h3>
<ul>
    <li>Smooth checkout — scan and ring, no manual entry.</li>
    <li>Accurate inventory counts.</li>
    <li>Real data on what's selling — so we can replicate the wins.</li>
</ul>

<h3>Where the Backlog Is</h3>
<p>Used CDs are the biggest gap — most are unpriced and unbarcoded. Vinyl is mostly priced but plenty don't have barcodes either. Anything with a hand-written sticker but no barcode is a candidate.</p>

<h3>How to Catalog an Item</h3>
<ol>
    <li>Pick up an unbarcoded item from the floor.</li>
    <li>Open <strong>Products &rarr; Add Product</strong> in the ERP. Search by title first — a record may already exist.</li>
    <li>If it doesn't exist: create it with title, artist, format, condition. Use Discogs to fill in the right pressing/release.</li>
    <li>Set the price using the in-store pricing rules. (See <a href="/help/pricing-in-store" target="_blank">Pricing for the Store Floor</a>.)</li>
    <li>Print a barcode label from <strong>Labels</strong>.</li>
    <li>Sticker the item top-right of the cover. Put it back in the right bin.</li>
</ol>

<div class="help-tip">
    <strong>Slow shift = catalog time.</strong> If the store is quiet, this is the work that pays off long after your shift ends.
</div>

<h3>What NOT to Throw Out</h3>

<div class="help-warn">
    Don't throw out items that look old, cracked, or "useless." They may still have collector value. If you're unsure, ask Jon before disposing.
</div>
HTML,
            ],
            [
                'slug' => 'floor-sales-lead',
                'title' => 'Floor Sales Lead (Peak Shifts)',
                'section' => 'Customer Experience',
                'sort' => 5,
                'summary' => 'The second-person floor selling role on busy Hollywood weekend shifts.',
                'page_keys' => ['pos.create', 'sell.create', 'pos'],
                'body_html' => <<<'HTML'
<p>On our busiest Hollywood shifts we put a <strong>second person on the floor</strong> whose job is to sell, not to run the register. Right now about <strong>83 out of every 100 people leave without buying</strong>. The Floor Sales Lead is here to change that. Every browser you turn into a buyer is more revenue for the store and more commission for you.</p>

<h3>When This Shift Runs</h3>
<p>Hollywood only, when the floor is fullest:</p>
<ul>
    <li><strong>Friday</strong> 5:00 - 10:00 PM</li>
    <li><strong>Saturday</strong> 2:00 - 8:00 PM</li>
    <li><strong>Sunday</strong> 1:00 - 6:30 PM</li>
</ul>
<p>These hours run 35 to 70 people on the floor at a time, which is more than one person can sell to. In slower months these shorten toward Saturday and Sunday only.</p>

<h3>What You Do</h3>
<ul>
    <li>Stay <strong>on the floor</strong>, never parked behind the desk. Greet everyone within a minute of them walking in.</li>
    <li>Ask what they are into, pull records for them, make recommendations, and get them to the listening station.</li>
    <li>Read the room. Get to the people who look ready to buy, or ready to walk, first.</li>
    <li>Close the sale.</li>
</ul>

<h3>During Lulls</h3>
<ul>
    <li>Face and restock the bins and tidy the listening station so the floor stays shoppable.</li>
    <li>Stay visible as a loss-prevention presence while it is crowded.</li>
</ul>

<div class="help-tip">
    <strong>Talk to customers, not coworkers.</strong> Save the catch-up with other staff for after your shift. On the clock, a full floor is money on the table, and every few minutes spent chatting is a sale, and your own commission, walking out the door.
</div>

<h3>Getting Credit for Your Sales</h3>
<p>The sales bonus goes to whoever <strong>rings</strong> the sale. So when you close someone, ring it up under <strong>your own login</strong> so the credit is yours. With two people on a shift, the daily target is split between you, so each of you has a fair, reachable goal.</p>

<div class="help-info">
    Take turns at the register: each person rings the sales they personally closed. If one person stays logged in and rings everything, that person gets all the bonus and the other gets none.
</div>

<h3>Do Not</h3>
<ul>
    <li>Do not camp at the register.</li>
    <li>Do not disappear to the back.</li>
    <li>Do not get stuck 20 minutes on one customer when the floor is full.</li>
</ul>
HTML,
            ],
            [
                'slug' => 'store-manager-handbook',
                'title' => 'Store Manager Handbook',
                'section' => 'Store Manager',
                'sort' => 1,
                'summary' => 'The complete manager guide - the role, and every training pulled together in one place.',
                'page_keys' => ['home', 'dashboard'],
                'body_html' => <<<'HTML'
<p>This is the complete guide to running a Nivessa store. It pulls every staff training into one place and organizes it around what the job actually is, so a new manager can read this one page and know how to run the floor, the team, the cash, and the building. You report to <strong>Jon</strong>. This role exists so one person owns each store day to day instead of Jon managing two at once. It is a hard job and an important one.</p>

<div class="help-must-do">
    <strong>Two things define this job.</strong>
    <ol style="margin-bottom:0;">
        <li><strong>You grow sales</strong> - your own and everyone else's. You are on the floor selling, and you show the team how to sell so the whole store gets better.</li>
        <li><strong>You keep the store tight</strong> - organized, stocked, clean, and staffed by people who know what they are doing because you trained them.</li>
    </ol>
</div>

<p>You are a <strong>seller and a teacher</strong>. The best thing you can do for the numbers is sell well yourself and turn everyone around you into a better seller. If you would rather do it all yourself than train someone, this is not the job.</p>

<h3>What you own</h3>
<ul>
    <li><strong>Your store's sales</strong> - day, week, and month.</li>
    <li><strong>The team</strong> - trained in, coached on every shift, held to the standard.</li>
    <li><strong>The floor</strong> - merchandised, stocked, clean, sections in order, new arrivals out fast.</li>
    <li><strong>Cash, keys, and access</strong> - clean register closes, accurate buys, and the keys to the building.</li>
    <li><strong>Loss prevention</strong> - a team that watches the floor and stops theft before it happens.</li>
</ul>

<h3>How your bonus works</h3>
<p>Hourly at a manager rate above cashier pay, plus a <strong>monthly store bonus</strong> tied to three things you directly control:</p>
<ul>
    <li><strong>Hitting sales</strong> - your store's number for the month.</li>
    <li><strong>Keeping shrink low</strong> - theft and loss stay down because the floor is watched and the team is trained.</li>
    <li><strong>Clean, accurate register closes</strong> - the drawer ties out and every sale is accounted for.</li>
</ul>

<hr>

<h2>1. Drive sales</h2>

<h3>Sell on the floor yourself</h3>
<p>Set the example. Greet people, read what they are into, put records in their hands, walk them to the listening station. On peak weekend shifts you lead from the floor closing sales, not parked at the register. Ring your own closes under <strong>your own login</strong> so the credit and commission are yours and the team sees you doing the work.</p>

<h3>The Nivessa selling method - train yourself and the team on all of this</h3>
<ul>
    <li><strong>Approach naturally.</strong> When someone is digging, most are looking for something specific and do not know if we have it. Open with "Looking for anything specific today?" or a low-pressure "I'm here if you need anything." If they stop on a record, "Did you ever hear of this one?" starts a real conversation. If they would rather be left alone, respect it.</li>
    <li><strong>Stay visible and approachable.</strong> Stand near customers without hovering. No phone, no looking buried or too busy to help. Customers should feel they can approach you at any time.</li>
    <li><strong>Look the part.</strong> Dress professionally. Avoid hoodies or anything that blocks your face. Make eye contact and acknowledge everyone who walks in.</li>
    <li><strong>Build connections.</strong> Compliment a band shirt or jacket, talk music, share a recommendation. A genuine compliment creates an instant connection and makes people feel welcome. Our strongest sellers do this constantly.</li>
    <li><strong>Share what's new.</strong> Tell people about current sales and markdowns, new releases tied to what they are buying, a similar album they might love, or an accessory like a cleaning kit that improves their collection.</li>
    <li><strong>Read the room.</strong> On a full floor, get to the people who look ready to buy - or ready to walk - first. Do not get stuck 20 minutes on one person when 40 others are browsing.</li>
    <li><strong>Who we are.</strong> Nivessa is not a snob shop. We are not record snobs and we do not make anyone feel they need a certain level of knowledge to shop here. We welcome everyone, from lifelong collectors to someone buying their first record. Be knowledgeable, be helpful, be kind. Customers remember how you made them feel.</li>
</ul>

<h3>Own your numbers</h3>
<div class="help-must-do">
    Know where your store stands for the <strong>day, the week, and the month</strong>. When you are behind, adjust that shift - do not wait for the month to end.
</div>
<ul>
    <li>Check your own numbers anytime at <a href="/my-earnings" target="_blank">My Earnings</a>.</li>
    <li>Jon will set you up on the store's daily and weekly sales figures and what "on track" looks like. If you are ever unsure of the target, ask him - do not guess.</li>
    <li>Follow the <strong>weekly focus</strong> Jon posts in Slack - the categories, genres, or items to push that week. If you are not sure what is prioritized, ask him.</li>
    <li>Reach out to Jon anytime for support or guidance. Owning the number does not mean carrying it alone.</li>
</ul>

<h3>Turn browsers into buyers, buyers into regulars</h3>
<p>Right now most people who walk in still leave without buying - that is the gap you close.</p>
<ul>
    <li>Know your regulars and what they collect. When something comes in for them, tell them.</li>
    <li>Recommend a second record without being pushy. Get people to the listening station.</li>
    <li>Turn a good first visit into a return visit - a compliment, a recommendation, remembering their name.</li>
</ul>

<h3>Peak weekend playbook</h3>
<p>Weekends are the busiest days for both stores and where the month is won. Hollywood runs a <strong>second person on the floor</strong> whose only job is to sell during the fullest hours (roughly Friday evening, Saturday afternoon, Sunday afternoon), when 35 to 70 people can be on the floor at once.</p>
<ul>
    <li>Both sellers stay on the floor, never both parked behind the desk. Greet everyone within a minute of walking in.</li>
    <li>Take turns at the register: each person rings the sales they personally closed, under their own login, so the credit and the daily bonus land with whoever did the selling.</li>
    <li>During lulls, face and restock the bins and tidy the listening station so the floor stays shoppable, and stay visible as a loss-prevention presence while it is crowded.</li>
</ul>

<h3>Report the floor to Jon</h3>
<p>You are Jon's eyes in the store. Tell him what is selling and what is not, what customers keep asking for that we do not carry, and where a section is dying. Real signals from the floor shape what we buy and how we merchandise.</p>

<hr>

<h2>2. Ring it right (the register)</h2>
<p>Everyone you train has to run the register the same way, every time. This is the single most common place things break.</p>

<div class="help-must-do">
    <strong>Every sale goes through the ERP at <a href="/pos/create" target="_blank">POS &rarr; Create</a>, and every sale - cash AND card - is also entered on Clover.</strong> A cash sale that never touches Clover looks like missing money at close.
</div>

<h3>The sale flow</h3>
<ol>
    <li><strong>Pick the customer.</strong> Search by name or phone. If new, add them on the spot - name and phone is enough. Offer the free rewards program.</li>
    <li><strong>Add items.</strong> Scan the barcode, or search by title, or use manual entry for an untagged used record.</li>
    <li><strong>Confirm price matches the sticker.</strong> The sticker price is the price. Cashiers do not adjust prices at the register. No sticker or a wrong sticker? A manager sorts it before it rings.</li>
    <li><strong>Tax applies automatically</strong> by store location. Cash and card both pay tax.</li>
    <li><strong>Take payment</strong> - cash, card, or store credit (store credit shows on the customer record, and store-credit sales do not pay tax).</li>
    <li><strong>Finalize</strong>, then type the total into <strong>Clover</strong> and confirm the Clover amount matches the POS total. For card, wait for the approved screen before handing anything back.</li>
    <li>Offer a receipt by phone or email.</li>
</ol>

<div class="help-critical">
    <strong>Discounts and refunds are not the cashier's call.</strong> The only standing discount is <strong>10% off orders of $300 or more</strong>, approved by Jon. No cash discounts, ever. <strong>Refunds go through Jon only</strong>, receipts required, and used products have no returns.
</div>

<div class="help-warn">
    <strong>Kallax pulls.</strong> If a customer pulls a record from a Kallax under the bins, it may be reserved for a Discogs order. Do not ring it without checking. If it does sell, immediately delete the listing from Discogs so it is not double-sold.
</div>

<div class="help-critical">
    <strong>Clover and the ERP must always match.</strong> If they do not, fix it before moving on. If you cannot, ping Sarah and she will.
</div>

<hr>

<h2>3. Buying collections</h2>
<p>Buying collections fuels the business. You oversee every buy your cashiers make and you handle the big or unusual ones yourself. Buy smart - rare, quality product only, no junk.</p>

<div class="help-must-do">
    <strong>Get the seller's phone or email before anything else.</strong> Even if the deal does not happen today, the contact info is the first asset.
</div>

<h3>Negotiate first, type second</h3>
<ol>
    <li><strong>Ask what they are hoping for.</strong> It is often less than you would have offered. Do not lead with a number.</li>
    <li><strong>Ask cash or store credit.</strong> Store credit pays more than cash.</li>
    <li><strong>Assess and grade</strong> with the Goldmine standard (M, NM, VG+, VG, G+, G, F, P). Watch for mold, deep scratches, missing items, cracked media, wrong record in wrong sleeve. We mostly want M to VG; G+ and below sells slowly, so pay very little.</li>
    <li><strong>Compute three offers</strong> on paper - high, middle, low. Open with the low and let them counter.</li>
    <li><strong>Close.</strong> Use the <a href="/buy-from-customer" target="_blank">buy calculator</a> and pay from the register. If short on cash, Jon can Zelle or Venmo the seller - hold the merchandise until it is settled. For store credit, add it to the customer's record in the ERP.</li>
</ol>

<h3>What to pay (rough guide)</h3>
<table class="table table-condensed table-bordered">
  <thead><tr><th>Format</th><th>Rate</th></tr></thead>
  <tbody>
    <tr><td>Sealed/new LP, popular artist (2020+)</td><td>$7-8 each</td></tr>
    <tr><td>Sealed/new LP, lesser-known</td><td>$2 each</td></tr>
    <tr><td>Used LP, sellable (Stevie Wonder, Sade, Sabbath, Dead)</td><td>~$2 each</td></tr>
    <tr><td>Slower LPs (Genesis, Billy Joel, Cat Stevens, Elton John)</td><td>$1 each</td></tr>
    <tr><td>Don't offer for: Johnny Mathis, Streisand, Jack Jones</td><td>$0</td></tr>
    <tr><td>CDs - Hip hop, Metal</td><td>$1 / $1.50 sealed</td></tr>
    <tr><td>CDs - Latin, Reggae</td><td>$0.50 / $1 sealed</td></tr>
    <tr><td>CDs - Blues, Rock, Electronic, New Wave</td><td>$0.35 / $0.70 sealed</td></tr>
    <tr><td>CDs - Jazz, R&amp;B, Soundtracks, Musicals</td><td>$0.15 / $0.35 sealed</td></tr>
    <tr><td>CDs - Classical</td><td>$0.10 / $0.25 sealed</td></tr>
    <tr><td>45s (7")</td><td>$0.15 each (Latin: $0.50)</td></tr>
    <tr><td>DVDs</td><td>$0.15 used / $0.35 sealed / $2 steelbook</td></tr>
    <tr><td>Blu-rays</td><td>$0.25 used / $0.50 sealed</td></tr>
  </tbody>
</table>

<div class="help-critical">
    <strong>Do not buy:</strong> stolen goods (sealed Target / B&amp;N / Walmart stock from an evasive or unhoused-looking seller - pass kindly with "We aren't interested today," get contact info, call Jon if unsure), firearms or weapons ever, or Poor / Fair / Good condition at any meaningful price. Your safety comes first.
</div>

<h3>Consignment</h3>
<p>Sometimes a seller wants us to sell their items instead of buying outright. We pay the seller <strong>60% of the sale price after the item sells</strong>. Log the items as a purchase with the seller as supplier and a consignment note, create their customer record, and <strong>put a "C" sticker on every item</strong> so the team knows it is not store-owned. Without the C sticker we will not know to pay them out. When a C item rings up, flag it to Jon for the payout.</p>

<h3>After a buy</h3>
<p>Price the collection promptly. <strong>AMS orders are top priority, collections are second.</strong> Anything better suited to Discogs goes in the Discogs bin instead of the floor.</p>

<hr>

<h2>4. Teach the team to sell</h2>
<p>A store is only as good as the people on the floor. Your job is to make every one of them a seller, not just a cashier who rings people up.</p>
<ul>
    <li><strong>Train the moves:</strong> how to greet, how to ask what someone is looking for, how to recommend, how to suggest a second record, and how to talk about a pressing without being pushy.</li>
    <li><strong>Coach in the moment.</strong> Watch how your team interacts with customers and give them real feedback that same shift, not a month later. Catch someone doing it right and say so; catch a missed opportunity and show them the better move right then.</li>
    <li><strong>Build their music knowledge.</strong> Genres, key artists, pressings, what is collectible, what is worth more and why. This is what lets a browser trust a recommendation.</li>
    <li><strong>Give clear goals and follow up.</strong> People should always know what "doing well" looks like this week, and whether they hit it. A goal with no follow-up is a wish.</li>
</ul>
<div class="help-tip">
    <strong>How the team is paid to sell:</strong> a <strong>daily sales-goal bonus</strong> (2% or 4% depending on the goal hit) goes to whoever rings the sale, plus <strong>2% listing commission</strong> on items they listed that later sell in store. Make sure everyone knows this - it is why ringing your own closes matters.
</div>

<hr>

<h2>5. Train everyone, all the way through</h2>
<div class="help-must-do">
    New hires get properly trained on the <strong>register, buys, theft awareness, and the floor</strong> before they run solo. Nobody is left alone on a shift until you have signed off.
</div>

<h3>Onboarding order</h3>
<ol>
    <li><strong>Register</strong> - the sale flow above. ERP + Clover, tax never zeroed, no cashier discounts, refunds Jon only.</li>
    <li><strong>Buys</strong> - contact info first, negotiate before typing, use the buy calculator, no off-the-books deals.</li>
    <li><strong>Theft awareness</strong> - what to watch for on the floor and with sellers, and that their safety comes first (see section 8).</li>
    <li><strong>The floor</strong> - how a section should look, and how items get priced and barcoded (sections 6 and 7).</li>
    <li><strong>Conduct</strong> - the things we are serious about (section 10). Have them read it once.</li>
</ol>

<h3>A simple first-week shape</h3>
<ul>
    <li><strong>Day 1-2:</strong> shadow you on the floor and at the register. They watch, then ring with you standing there.</li>
    <li><strong>Day 3-4:</strong> they run the register with you nearby; you introduce buys and pricing.</li>
    <li><strong>Day 5+:</strong> they handle the floor and register while you spot-check; you cover opening and closing.</li>
    <li>Cleared to work solo only once you have watched them do each core task correctly without help.</li>
</ul>

<h3>Keep a training checklist per person</h3>
<p>A simple checklist for each person so nothing gets skipped and you know exactly where everyone stands. At a glance you should be able to say who is cleared for: <strong>register, buys, opening, closing, shipping, and pricing.</strong> Keep it wherever is easiest - you can keep a shared page at <a href="/admin/help-knowledge" target="_blank">Store Knowledge</a>.</p>

<h3>Keep training going after week one</h3>
<ul>
    <li>Short refreshers and quick huddles at the start of busy shifts.</li>
    <li>Retrain when the same mistake repeats. A repeated mistake is a training gap, not just a bad day.</li>
</ul>

<h3>Theft-awareness training (everyone)</h3>
<p>Teach the team to watch the floor and stop loss before it happens:</p>
<ul>
    <li>Greet everyone - being seen deters theft on its own.</li>
    <li>Watch for items moving under clothing, avoided eye contact while holding merch, quick section-to-section movement, and large stacks with no intent to buy.</li>
    <li>Stay attentive but do not confront alone. If something feels wrong, get a coworker or manager involved discreetly.</li>
    <li>Shrink is part of your store bonus, so this is not optional coaching.</li>
</ul>

<hr>

<h2>6. Keep the store organized</h2>

<h3>The daily floor standard</h3>
<p>The store looks great every day: well merchandised, well stocked, sections in order, no gaps and no mess. Every section has a standard and you hold the team to it. On every floor shift:</p>
<ul>
    <li><strong>Trash and recycling out.</strong> Hollywood: parking lot behind the store. Pico: behind our store, not behind the cafe. Flatten cardboard into the blue bins.</li>
    <li><strong>Floor and bins:</strong> walk the floor for misplaced product, records upright with no leaning piles, fix messy bins.</li>
    <li><strong>Refill</strong> end caps, walls, the new-CD section near the front desk, and window displays with featured albums.</li>
    <li><strong>Signage:</strong> section signs lined up and in the right place; stage tidy; windows clean (Windex if dirty).</li>
    <li><strong>No boxes or open stock in customer view.</strong></li>
    <li><strong>Sweep or vacuum</strong> before the next shift comes in.</li>
    <li>Anything cracked, damaged, or old-looking: ask Jon before tossing - it may still have value.</li>
</ul>
<p>Need supplies or upgrades to keep the store right? Tell Jon or Sarah. Do not buy for the store with your own money without approval first.</p>

<h3>New arrivals out fast</h3>
<p>AMS ships sealed inventory roughly weekly in brown UPS boxes; the order is already in the ERP. Confirm it arrived, mark the purchase <strong>Received</strong> (this updates stock), print 2x1 barcode labels, sticker top-right of the cover, and shelve in the New Reissue bins. New product on the floor fast is money; a box in the back is not.</p>

<h3>Pricing and barcoding</h3>
<div class="help-must-do">
    Barcode every floor item - never hand-write the price. Every item should have an ERP record and a barcode so checkout, stock counts, and reports actually work. A slow shift is catalog time.
</div>
<p>Pricing method for vinyl / CDs / cassettes: scan into Discogs, pick the exact pressing (label, jacket, country, notes), grade it, and price at <strong>Discogs market price plus half the lowest shipping rate</strong> (lowest shipping ~$5 means add ~$2.50). Cover worse than the media? Knock 5 to 10% off. Non-Discogs items: price off eBay <strong>Sold</strong> listings. On the sticker, always write the <strong>genre and the price</strong> (e.g. "$14 ROCK") so a misplaced record can be put back.</p>

<h3>When it goes to Discogs instead of the floor</h3>
<p>If an item is over $70 and not on our top-150-sold-artists list, or it is rare / collector-grade, list it on Discogs and place it in the correct Kallax. <strong>Always set the location</strong> in the listing and physically file it there - we have lost hundreds of records to wrong or missing locations. Pico is shipping HQ.</p>

<h3>Shift notes</h3>
<p>Close every shift with a note in <strong>#shift-notes</strong> on Slack: what got shipped/listed/rung, what is still in progress, supplies low, anything broken, and the in-store sales total. The next person reads it first.</p>

<hr>

<h2>7. Opening and closing checklists</h2>
<div class="help-must-do">
    <strong>Arrive at least 15 minutes before opening.</strong> Setup takes time and customers walk up at the dot.
</div>

<h3>Opening - Pico</h3>
<ol>
    <li>Unlock the front door: turn the key <strong>left</strong> on the glass door, <strong>right</strong> on the metal door.</li>
    <li>Clock in on Clover.</li>
    <li>Turn on front lights, backroom lights, and the back-wall switch that powers the fans and record player.</li>
    <li>Turn on the computer. Put on good music.</li>
    <li>Set the A-frame out by the curb.</li>
    <li>Count the register cash and log the opening total.</li>
    <li>Check walls and bins are stocked; fix messy bins. Refill end caps with featured albums.</li>
    <li>Clear front-desk clutter. Sweep or vacuum. Tidy the bathroom, trash out.</li>
    <li>Check Discogs for new orders and messages.</li>
</ol>

<h3>Opening - Hollywood</h3>
<ol>
    <li>If you do not have a key, the lockbox is at the front of the gate; code <code>1492</code>. Unlock the front door.</li>
    <li>Turn on all main-room lights and the computer. Music up loud, outside too.</li>
    <li>Plug in the neon signs: "Welcome to Digger's Paradise" (behind the listening station), "Have you heard it on vinyl" (behind the rock bins), "Disco es la cultura" (wall on stage).</li>
    <li>Check walls and bins; fix out-of-place sections. Refill end caps with featured albums.</li>
    <li>Clear front desk. Sweep or vacuum. Check the bathroom.</li>
    <li>Open the doors to welcome customers.</li>
</ol>

<h3>Closing - Pico</h3>
<ol>
    <li>Tidy the floor and restock end caps. Clear the front desk. Sweep or vacuum.</li>
    <li>Tidy the bathroom - lights off, trash emptied. Take the trash out. Bring the A-frame inside.</li>
    <li>Turn off the computer, vinyl player, and front fan. Turn off backroom lights, bathroom light, the "Diggers Paradise" neon, the front main light, and the lamp by the vinyl player.</li>
    <li>Grab the lock from the hook behind the desk, join the metal gates, and lock them. Pull the brown gate flush with the door and all the way right.</li>
    <li>Close the glass door and lock it (turn the key right until it clicks). <strong>Double-check it is locked.</strong></li>
</ol>

<h3>Closing - Hollywood</h3>
<ol>
    <li>Tidy the floor and restock featured displays. Clear the front desk. Sweep or vacuum.</li>
    <li>Tidy the bathroom - lights off, trash emptied. Empty all trash bins.</li>
    <li>Shut down the front-desk computer. Unplug the three neon signs. Turn off the vinyl player and all lights including the bathroom.</li>
    <li>Bring the A-frame in if it is outside.</li>
    <li>Lock the front door with the two bottom locks. Lower the gate with the buttons on the right wall.</li>
    <li>Exit through the back door and confirm it is locked behind you.</li>
    <li>Put the key back in the lockbox and <strong>scramble the code.</strong></li>
</ol>

<div class="help-must-do">
    Update <code>#shift-notes</code> at close: what you did, what is left, in-store sales total, anything broken or low.
</div>

<hr>

<h2>8. Run the operation</h2>

<h3>Make sure the cashiers are doing their jobs</h3>
<p>You are accountable for how your cashiers perform - both when selling and when buying collections. Spot-check every shift:</p>
<div class="help-must-do">
    <ul style="margin-bottom:0;">
        <li><strong>Every sale is in the ERP and on Clover</strong>, tax handled correctly, amounts matching between the two.</li>
        <li><strong>No unauthorized discounts.</strong> Only 10% off $300+ with Jon's approval. No cash discounts.</li>
        <li><strong>Prices match stickers.</strong> Cashiers are not adjusting prices at the register.</li>
        <li><strong>Buys are accurate</strong> - contact info captured, buy calculator used, condition graded honestly, nothing bought off the books.</li>
        <li><strong>Register closes are clean</strong> and the drawer ties out. A close that does not balance gets found and explained the same day, not left for the morning.</li>
        <li><strong>Cash is handled straight</strong> - no pocketing, everything (cash included) rung on Clover so the day balances. Follow the safe-drop rules for large cash.</li>
        <li><strong>Sections stay tight</strong> during their shift, not just at open and close.</li>
    </ul>
</div>
<p>If a cashier keeps making the same mistake, that is a retraining job, not a one-off correction. If it is a cash or honesty issue, address it directly and loop in Jon and Sarah - see Conduct below.</p>

<h3>Oversee collection buys</h3>
<p>Make sure the store is buying smart: rare, quality product only, no junk. Back up cashiers on anything large or unusual, and loop in Jon before anyone quotes a big number. Rates and grading are in section 3.</p>

<h3>Oversee events</h3>
<p>In-store events bring new people in. The bar is clean setup, friendly hosting, safe environment. Stage and equipment prepared well before guests arrive, sound checked early, floor walked with no boxes in view. Reset the floor before closing and drop a note in #shift-notes with headcount and anything that broke. Keep the outside A-frame fresh with eye-catching records and change it periodically.</p>

<h3>Keys, access, and security</h3>
<ul>
    <li><strong>You own the keys and access.</strong> Basement and warehouse stay locked when no one is in there; the front gate is locked at end of shift.</li>
    <li>If anything looks off when you arrive - broken lock, door ajar, missing inventory - <strong>do not enter, call Jon first.</strong></li>
</ul>

<h3>When the computer or printer breaks</h3>
<p>Message <strong>Muhammad on WhatsApp at +880 1723-948653</strong> for any computer or label-printer issue; he fixes most things remotely via AnyDesk. First try the basics: check the printer sensor for stuck labels, power-cycle, check paper/ribbon and the USB cable. If the whole ERP is down and you cannot ring sales, get Jon immediately - do not keep ringing on Clover only.</p>

<h3>Coverage</h3>
<p>Fatteen handles the bulk of scheduling in Sling. On a last-minute emergency you may need to help him find coverage. Approve shift claims and trades in Sling; the 40-hour week is the cap and overtime needs approval in advance.</p>

<h3>Be the face of the store</h3>
<p>Friendly, approachable, even-tempered under pressure. The kind of person customers like and come back for, and the kind of lead the team wants to work for. Hold people to standards without drama, and bring issues to Jon and Sarah directly and early.</p>

<hr>

<h2>9. Cash, shrink, and loss prevention</h2>
<p>These three are your monthly bonus, so treat them as core to the job, not paperwork.</p>
<ul>
    <li><strong>Clean closes:</strong> the drawer balances and every sale is accounted for on both the ERP and Clover. Investigate a miss the same day.</li>
    <li><strong>Cash discipline:</strong> no pocketing, ever. All sales (cash and card) go on Clover. Follow the safe-drop procedure for large cash on hand.</li>
    <li><strong>Shrink stays low</strong> because the floor is watched, the team greets everyone, and suspicious situations get handled early. Train it, model it, and address theft head-on.</li>
</ul>

<hr>

<h2>10. Safety and suspicious situations</h2>
<div class="help-critical">
    <strong>Your safety and your team's safety come first.</strong> If a situation feels dangerous, do not engage. Move to a safe area, call for help, and let Jon or Sarah know. Money and merchandise can be replaced.
</div>
<ul>
    <li><strong>Aggressive or unstable person:</strong> do not argue or engage. Call or text the <strong>Hollywood Partnership at 567-459-9663</strong> (they respond faster than police), then tell Jon or Sarah so they can call police if needed. Save that number in your phone now.</li>
    <li><strong>Suspicious seller:</strong> sealed retail-stickered stock from an evasive seller is likely stolen - pass kindly and get contact info. Record-company employees selling surplus sealed stock are legitimate; judge the seller, not the items.</li>
    <li><strong>Arriving to something off</strong> (broken lock, door not fully closed, missing inventory): do not enter, call Jon first.</li>
</ul>

<hr>

<h2>11. Conduct and culture</h2>
<p>Trust and integrity are how Nivessa runs. You enforce this, and you model it.</p>
<div class="help-critical">
    <strong>Strictly prohibited:</strong> theft of cash or merchandise, self-pricing for personal gain, pocketing cash, lying about purchased collections, wage theft (manipulating hours, skipping clock-out, dodging overtime), off-the-books buys from customers, and clocking in for someone else. These are grounds for discipline up to termination.
</div>
<ul>
    <li>Respect customers, coworkers, and management. Zero tolerance for discrimination or harassment.</li>
    <li>No politics at work. No gossip - take concerns directly to a manager.</li>
    <li>Confidentiality: customer data, internal pricing, business strategy, anything in the ERP stays inside Nivessa.</li>
    <li>Show up on time and ready. Two no-call-no-shows lose hours. Emergencies happen - give a heads-up.</li>
</ul>
<p>If you witness or suspect any of the above, it comes to Jon and Sarah. Reports are confidential and retaliation is not tolerated.</p>

<hr>

<h2>12. People and schedule</h2>
<ul>
    <li><strong>Sling</strong> runs scheduling. Staff claim shifts and you (or Fatteen) approve. Time off needs 2 weeks' notice with the shift marked available; trades happen in-app with manager approval.</li>
    <li><strong>Sick leave (California):</strong> 1 hour per 30 worked, usable after 90 days, up to 24 hours per year, capped at 48 accrued.</li>
    <li><strong>Roles on the floor:</strong> Front Desk (register, customers, listing), Shipping (online orders), Product and Floor Lead (pricing, organizing, restocking), Photo Upload (Discogs listing), Moving Shift (between stores/storage), Inventory Check (counts).</li>
    <li><strong>Weekly manager report:</strong> every week, send Jon and Sarah a short report on how the team is doing, what the store needs, what you are handling, and what you need help with. Template: <a href="/help/weekly-manager-report" target="_blank">Weekly Manager Report</a>.</li>
</ul>

<hr>

<h2>13. Your schedule, on call, and pay</h2>
<ul>
    <li><strong>Weekends are mandatory</strong> - Saturday and Sunday are the busiest days for both stores, and you are on the floor leading and selling.</li>
    <li><strong>On call when you are not in.</strong> If the store is open and something comes up staff cannot handle, you are reachable and help sort it out. This is how Jon runs things now.</li>
    <li><strong>One full weekday off, guaranteed and protected.</strong> A backup manager covers it so you are truly off. Only a true emergency - break-in, alarm, fire, flood, or a shift left completely unstaffed - interrupts it, and that is rare.</li>
    <li><strong>Pay:</strong> hourly at a manager rate above cashier pay, plus a monthly store bonus tied to hitting sales, keeping shrink low, and clean, accurate register closes.</li>
</ul>

<hr>

<h2>14. Store policies you enforce</h2>
<div class="help-must-do">
    <strong>Filming:</strong> anyone filming must book and pay first through <strong>Giggster</strong> or <a href="https://nivessa.com/venues" target="_blank">nivessa.com/venues</a>. The store stays <strong>open for business</strong> - we never shut down for a shoot - and <strong>aisles are never blocked</strong>. That is exactly why our filming rates are lower. No booking, no filming.
</div>
<div class="help-critical">
    <strong>Food trucks</strong> are not allowed to park in front of the store. Ask them to move. If they refuse, call parking enforcement. They do not pay rent on Hollywood Blvd and they block our storefront and our walk-in traffic.
</div>
<div class="help-critical">
    <strong>Restroom is employees only.</strong> No customers, filmers, or food-truck workers. Point anyone who asks to the nearest public restroom.
</div>
<p>Full version: <a href="/help/filming-food-trucks-restroom" target="_blank">Filming, Food Trucks and Restroom</a>.</p>

<hr>

<h2>Key contacts</h2>
<ul>
    <li><strong>Jon</strong> - owner, your direct report. Sales targets, big buys, discounts, refunds, anything you are unsure about.</li>
    <li><strong>Sarah</strong> - website, ERP, and Clover/ERP mismatches.</li>
    <li><strong>Fatteen</strong> - scheduling in Sling.</li>
    <li><strong>Muhammad (tech support)</strong> - WhatsApp +880 1723-948653, computer and printer issues.</li>
    <li><strong>Hollywood Partnership (safety)</strong> - 567-459-9663, faster than police for a situation on the block.</li>
    <li><strong>Golden</strong> - hosts the daily Whatnot show at Pico.</li>
</ul>

<h2>Every training guide</h2>
<p>These are the individual guides behind this handbook, grouped by area - what you train the team on. Open any one for the full detail.</p>
<div class="help-guide-groups">
    <div class="help-guide-group">
        <h3>Selling &amp; customers</h3>
        <ul class="help-guide-links">
            <li><a href="/help/sales-training" target="_blank">Sales Training</a></li>
            <li><a href="/help/customer-service-basics" target="_blank">Customer Service Basics</a></li>
            <li><a href="/help/floor-sales-lead" target="_blank">Floor Sales Lead</a></li>
        </ul>
    </div>
    <div class="help-guide-group">
        <h3>Register &amp; payments</h3>
        <ul class="help-guide-links">
            <li><a href="/help/pos-ringing-up-a-customer" target="_blank">Ringing Up a Customer</a></li>
            <li><a href="/help/store-credit" target="_blank">Store Credit</a></li>
        </ul>
    </div>
    <div class="help-guide-group">
        <h3>Buying &amp; consignment</h3>
        <ul class="help-guide-links">
            <li><a href="/help/buy-collection-from-customer" target="_blank">Buying a Collection</a></li>
            <li><a href="/help/consignment" target="_blank">Consignment</a></li>
            <li><a href="/help/receive-ams-shipment" target="_blank">Receiving an AMS Shipment</a></li>
        </ul>
    </div>
    <div class="help-guide-group">
        <h3>Pricing &amp; listing</h3>
        <ul class="help-guide-links">
            <li><a href="/help/pricing-in-store" target="_blank">Pricing for the Floor</a></li>
            <li><a href="/help/list-on-discogs" target="_blank">Listing on Discogs</a></li>
            <li><a href="/help/photo-upload-fl-bins" target="_blank">Photo Upload (FL Bins)</a></li>
            <li><a href="/help/cataloging-older-inventory" target="_blank">Cataloging Older Inventory</a></li>
        </ul>
    </div>
    <div class="help-guide-group">
        <h3>Online orders &amp; shipping</h3>
        <ul class="help-guide-links">
            <li><a href="/help/ship-discogs-order" target="_blank">Shipping a Discogs Order</a></li>
            <li><a href="/help/whatnot-orders" target="_blank">Whatnot Orders</a></li>
            <li><a href="/help/discogs-messages-and-returns" target="_blank">Discogs Messages and Returns</a></li>
        </ul>
    </div>
    <div class="help-guide-group">
        <h3>Opening, closing &amp; floor</h3>
        <ul class="help-guide-links">
            <li><a href="/help/opening-checklist" target="_blank">Opening Checklist</a></li>
            <li><a href="/help/closing-checklist" target="_blank">Closing Checklist</a></li>
            <li><a href="/help/floor-lead-checklist" target="_blank">Floor Lead Checklist</a></li>
            <li><a href="/help/shift-notes" target="_blank">After-Shift Notes</a></li>
        </ul>
    </div>
    <div class="help-guide-group">
        <h3>People &amp; scheduling</h3>
        <ul class="help-guide-links">
            <li><a href="/help/weekly-manager-report" target="_blank">Weekly Manager Report</a></li>
            <li><a href="/help/shifts-and-sling" target="_blank">Shifts and Sling</a></li>
            <li><a href="/help/hosting-events" target="_blank">Hosting Events</a></li>
        </ul>
    </div>
    <div class="help-guide-group">
        <h3>Safety, conduct &amp; policy</h3>
        <ul class="help-guide-links">
            <li><a href="/help/safety-and-suspicious-customers" target="_blank">Safety and Suspicious Customers</a></li>
            <li><a href="/help/code-of-conduct" target="_blank">Code of Conduct</a></li>
            <li><a href="/help/earning-at-nivessa" target="_blank">Earning at Nivessa</a></li>
            <li><a href="/help/sick-leave-and-benefits" target="_blank">Sick Leave</a></li>
            <li><a href="/help/computer-or-printer-issues" target="_blank">When the Computer or Printer Breaks</a></li>
            <li><a href="/help/filming-food-trucks-restroom" target="_blank">Filming, Food Trucks and Restroom</a></li>
        </ul>
    </div>
</div>

<div class="help-tip">
    Something missing? Add it yourself at <a href="/admin/help-knowledge" target="_blank">Store Knowledge</a> or Slack Sarah. This handbook should grow as you learn what a new hire needs.
</div>
HTML,
            ],
            [
                'slug' => 'weekly-manager-report',
                'title' => 'Weekly Manager Report',
                'section' => 'Store Manager',
                'sort' => 2,
                'summary' => 'The weekly check-in you send Jon and Sarah: how the team is doing, what the store needs, what you are handling, and what you need help with.',
                'page_keys' => ['home', 'dashboard'],
                'body_html' => <<<'HTML'
<p>Every week, send Jon and Sarah a short report on how the store and team are doing. It keeps us in the loop, shows what you have already handled, and flags what you need from us early - before a small thing turns into a big one.</p>

<div class="help-must-do">
    <strong>One report a week, sent to Jon and Sarah.</strong> Send it at the end of your week - Sunday close works well. Keep it short: a few lines per section. If something is urgent, do not wait for the report - message us right away.
</div>

<h2>What it covers</h2>
<p>Four things, every week:</p>
<ul>
    <li><strong>How the team is doing</strong> - who is doing well, who needs a nudge or retraining.</li>
    <li><strong>What the store needs</strong> - stock, supplies, repairs, displays, staffing gaps.</li>
    <li><strong>What you are handling yourself</strong> - what you own this week, no action needed from us.</li>
    <li><strong>What you need help with</strong> - decisions, approvals, big buys, anything above your call.</li>
</ul>

<h2>Before you write it</h2>
<ul>
    <li>Base the team section on real check-ins during the week - talk to your people on shift, do not guess.</li>
    <li>Glance at the week's numbers in <a href="/my-earnings" target="_blank">My Earnings</a> and the daily totals so the sales line is accurate.</li>
    <li>Walk the floor before you write "what the store needs" so nothing gets missed.</li>
</ul>

<div class="help-critical">
    Anything about <strong>cash, honesty, safety, or harassment</strong> goes to Jon and Sarah the moment it comes up - never hold it for the weekly report. See Conduct in the <a href="/help/store-manager-handbook" target="_blank">Store Manager Handbook</a>.
</div>

<h2>The report you send</h2>
<p>Fill this in and send it to <strong>Jon and Sarah on Slack</strong> each week.</p>
<pre class="help-template">NIVESSA WEEKLY MANAGER REPORT

Store:        Hollywood / Pico
Manager:
Week of:

1. How the team is doing
   (one line per person - doing well, needs a nudge, needs retraining)
   -

2. What the store needs
   (stock, supplies, repairs, cleaning, displays, staffing)
   -

3. What I am handling myself
   (owned this week - no action needed from you)
   -

4. What I need help with from Jon and Sarah
   (decisions, approvals, big buys, anything above my call)
   -

Sales this week vs target:
Anything urgent:</pre>

<div class="help-tip">
    Send it the same day and time each week so it becomes a habit and nothing slips. Over a few weeks, these reports show us exactly where each store stands.
</div>
HTML,
            ],
            [
                'slug' => 'filming-food-trucks-restroom',
                'title' => 'Filming, Food Trucks and Restroom',
                'section' => 'Operations',
                'sort' => 4,
                'summary' => 'Store policy on paid filming, food trucks out front, and who may use the restroom.',
                'page_keys' => ['home'],
                'body_html' => <<<'HTML'
<p>Three store policies everyone needs to know and enforce. Managers are accountable for holding the line on all three.</p>

<h3>Filming in the store</h3>
<div class="help-must-do">
    Anyone filming in the store must pay for it first, through <strong>Giggster</strong> or <strong>nivessa.com/venues</strong>. No booking, no filming.
</div>
<ul>
    <li><strong>The store stays open for business.</strong> We never shut the store down for a shoot.</li>
    <li><strong>Aisles cannot be blocked.</strong> Customers always get a clear path through the store. A crew cannot take over a section or block the bins.</li>
    <li>This is exactly why our filming rates are lower than a normal location - the store keeps selling the whole time. If a crew wants the place to themselves, that is not what we offer.</li>
    <li>If someone starts filming without a booking, stop them politely and send them to <a href="https://nivessa.com/venues" target="_blank">nivessa.com/venues</a> or Giggster to book.</li>
</ul>

<h3>Food trucks out front</h3>
<div class="help-critical">
    Food trucks are <strong>not allowed</strong> to park in front of the store. If you see one, ask them to move.
</div>
<ul>
    <li>They must move. If they will not, call parking enforcement.</li>
    <li>They do not pay rent on Hollywood Blvd and they block our storefront - it is not fair to the store and it costs us walk-in traffic.</li>
    <li>They cannot use the restroom (see below).</li>
</ul>

<h3>Restroom</h3>
<div class="help-critical">
    The restroom is for <strong>employees only</strong>. No one else uses it - not customers, not filmers, not food-truck workers, no one.
</div>
<p>If someone asks, politely point them to the nearest public restroom. This is a change from the old policy that let customers use it, so expect to remind regulars.</p>
HTML,
            ],
        ];

        // Merge in manager-added entries from the self-serve knowledge editor
        // (/admin/help-knowledge). Read live from storage, so saves show up on
        // the /help pages and in the bot with no code change or deploy.
        if (class_exists(\App\Http\Controllers\HelpKnowledgeController::class)) {
            $articles = array_merge($articles, \App\Http\Controllers\HelpKnowledgeController::forCatalog());
        }

        return $articles;
    }

    /**
     * Tour steps for a given page key. Used by the floating "?" tour
     * launcher. Each step has selector / title / body — body is HTML.
     * Empty array = no tour available; the partial renders nothing.
     */
    public static function tour(string $key): array
    {
        $tours = [
            'pos.create' => [
                [
                    'selector' => '#customer_id',
                    'title' => 'Pick the Customer',
                    'body' => 'Search by name, phone, or email. If they are new, click <strong>Sign Up for a Nivessa Account</strong> to create their record.',
                ],
                [
                    'selector' => '#search_product',
                    'title' => 'Add Items',
                    'body' => 'Scan the barcode or type the title.',
                ],
                [
                    'selector' => null,
                    'title' => 'Quick Add Tiles (Right Side)',
                    'body' => 'For drinks, snacks, swag, and clearance vinyl, tap a tile on the right to add it instantly — no scan or search needed.',
                ],
                [
                    'selector' => null,
                    'title' => 'Cash or Card',
                    'body' => 'Tax is automatic — both cash and card pay it. <strong>Always ring on the ERP</strong>, even when the card swipes on Clover. Skipping the ERP is what breaks inventory and reports.',
                ],
            ],
            'purchase.index' => [
                [
                    'selector' => null,
                    'title' => 'Receiving an AMS Shipment',
                    'body' => 'When the brown UPS box arrives, find the matching AMS order in this list, open it, confirm the contents, then change status to <strong>Received</strong>. That updates stock counts.',
                ],
                [
                    'selector' => null,
                    'title' => 'Then Print Labels',
                    'body' => 'After marking received, go to <strong>Purchases &rarr; Print Labels</strong>. Swap the Zebra paper from 4×6 to 2×1 (Zebra Utilities &rarr; Configure Printer Settings &rarr; width 2 / height 1). Then scan each record into the print list.',
                ],
                [
                    'selector' => null,
                    'title' => 'Sealed Buys From Customers',
                    'body' => 'Use <strong>Add Purchase</strong> for sealed items bought from regulars (e.g. Randy at Pico). Set supplier to the customer\'s name, location to the store, mark received. <a href="/help/receive-ams-shipment" target="_blank">See full guide &rarr;</a>',
                ],
            ],
            'buy_from_customer' => [
                [
                    'selector' => null,
                    'title' => 'Get Phone or Email First',
                    'body' => 'Before anything else — even before looking at the records — get the seller\'s phone or email. Even if no deal happens today, the contact is the asset.',
                ],
                [
                    'selector' => null,
                    'title' => 'Negotiate Before You Type',
                    'body' => 'Ask <em>"how much are you hoping for?"</em> first. They often expect less than you would have offered. Open with your low offer, let them counter, only inch up if needed.',
                ],
                [
                    'selector' => null,
                    'title' => 'Cash or Store Credit?',
                    'body' => 'Ask early. <strong>Store credit pays more</strong> than cash. If they take credit, add it to their customer record in the ERP — not the old spreadsheet.',
                ],
                [
                    'selector' => null,
                    'title' => 'Don\'t Buy: Stolen, Weapons, Poor Condition',
                    'body' => 'Sealed items with Target/B&amp;N/Walmart stickers from a sketchy seller — pass kindly. No firearms, ever. Skip Poor/Fair/Good condition unless free. <strong>Your safety comes first.</strong> <a href="/help/buy-collection-from-customer" target="_blank">See full buying guide &rarr;</a>',
                ],
            ],
        ];
        return $tours[$key] ?? [];
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

    /**
     * Sections render in this order on the index. Anything not listed here
     * falls in alphabetically at the end.
     */
    private const SECTION_ORDER = [
        'Welcome',
        'Store Manager',
        'Conduct',
        'Shifts & Schedule',
        'POS',
        'Customer Experience',
        'Buying',
        'Purchases',
        'Pricing',
        'Operations',
        'Shipping',
        'Listing',
        'Opening & Closing',
        'Pay & Growth',
        'Safety',
    ];

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

        $ordered = [];
        foreach (self::SECTION_ORDER as $name) {
            if (isset($out[$name])) {
                $ordered[$name] = $out[$name];
                unset($out[$name]);
            }
        }
        ksort($out);
        foreach ($out as $name => $items) {
            $ordered[$name] = $items;
        }
        return $ordered;
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
