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
    <li>If you don't have a key, the lockbox key is at the front of the gate. (You should already know the code — if not, ask a manager. We don't put it in writing.)</li>
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

    /**
     * Sections render in this order on the index. Anything not listed here
     * falls in alphabetically at the end.
     */
    private const SECTION_ORDER = [
        'Welcome',
        'POS',
        'Customer Experience',
        'Buying',
        'Purchases',
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
