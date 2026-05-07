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
<p>Email <a href="mailto:sarah@nivessa.com">sarah@nivessa.com</a> with what you were looking for and we'll add it.</p>
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

<div class="help-tip">
    <strong>Bathroom:</strong> customers can use it. One person at a time. Use your judgment if anyone seems off.
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
    <li><strong>Bathroom:</strong> customers may use it. Only one at a time. Use judgment if anyone seems off.</li>
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
<p>If the computer or label printer at the front desk isn't working, get in touch with Muhammad — he handles tech support for the stores via AnyDesk.</p>

<div class="help-must-do">
    <strong>Reach Muhammad on AnyDesk</strong> for any computer or printer issue. He can take remote control and fix most problems on the spot.
</div>

<h3>Before You Reach Out</h3>
<ul>
    <li><strong>Restart first.</strong> A fresh power cycle of the computer or printer fixes a surprising amount of stuff.</li>
    <li><strong>Check paper + ribbon</strong> on the Zebra label printer. "It's broken" is often "out of labels."</li>
    <li><strong>Check the cable.</strong> USB unplugged is the most common label-print issue.</li>
</ul>

<h3>If You're Still Stuck</h3>
<p>Reach Muhammad with what you've already tried, what you saw on screen, and which device. The more specific you are, the faster he can fix it.</p>

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
        ];
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
                    'body' => 'Scan the barcode or type the title. The price comes from the sticker — <strong>cashiers do not adjust prices at the register</strong>. If a sticker is missing or wrong, ask a manager before ringing it up.',
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
                [
                    'selector' => null,
                    'title' => 'Returns &amp; Discounts: Manager-Only',
                    'body' => 'If a customer wants a refund or asks for a discount, get a manager. Cashiers never authorize either. Used products: no returns. <a href="/help/pos-ringing-up-a-customer" target="_blank">See full POS guide &rarr;</a>',
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
        'Conduct',
        'POS',
        'Customer Experience',
        'Buying',
        'Purchases',
        'Pricing',
        'Operations',
        'Shipping',
        'Listing',
        'Opening & Closing',
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
