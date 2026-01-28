## Playlist ERP POS – Client Testing Instructions

This document explains **exactly where to go and how to test** all the new features (Phases 1–3) so you can verify everything end‑to‑end.

URLs below assume the live domain: `https://playlist.nivessa.com`.

---

## 1. POS Basics, Tax, and Bag Fee

### 1.1 POS screen & basic sale
- **URL:** `/pos/create`
- **Steps:**
  1. Log in and go to `https://playlist.nivessa.com/pos/create`.
  2. Add any product to the cart using the search box.
  3. Confirm:
     - Cart rows render correctly.
     - Totals at the bottom update when you change quantity.

### 1.2 Automatic sales tax (non–tax‑exempt product) --> working 
- **Objective:** Default tax applies automatically to normal products.
- **Prerequisites:**
  - Business default sales tax configured (e.g. CA Sales Tax).
- **Steps:**
  1. Go to `https://playlist.nivessa.com/tax-rates` and ensure a rate exists (e.g. “CA Sales Tax”).
  2. Go to `Business Settings` → **Sales** tab and set this as default sales tax (if applicable).
  3. Create or edit a product at `https://playlist.nivessa.com/products`:
     - Make sure **Tax Exempt** is **unchecked**.
  4. Go to POS `/pos/create`, select any customer (or walk‑in), and add that product.
  5. Check:
     - **Order Tax** shows a non‑zero amount.
     - **Final Total** = Subtotal + Tax (no bag fee yet).

### 1.3 Tax‑exempt product --> not working
- **Objective:** Tax does **not** apply to tax‑exempt products.
- **Steps:**
  1. Edit a product at `https://playlist.nivessa.com/products` → Edit.
  2. Check the **Tax Exempt** checkbox and save.
  3. In POS, add that product to the cart.
  4. Check:
     - Line total for this product is **not** taxed.
     - Order Tax does **not** increase when you add only this product.

### 1.4 Bag Fee (no tax) -->< working>
- **Objective:** Bag fee is added but excluded from tax.
- **Prerequisites:** Bag fee enabled and price set.
- **Steps:**
  1. Go to `Business Settings` → **POS Settings** tab:
     - Find **Shopping Bag / Bag Fee Charge Settings**.
     - Enable bag fee.
     - Set a price (e.g. `$0.10`).
     - Save settings.
  2. Go to POS `/pos/create`.
  3. Add a **taxable** product to the cart.
  4. Check the **“Add Bag Fee”** checkbox under the product search area.
  5. Verify:
     - A **Bag Fee** line appears with the configured price.
     - Bag Fee line shows **no tax** (tax column/amount is zero).
     - **Order Tax** value is exactly what you’d get from the taxable items only (does not change when toggling bag fee).
     - **Final Total** = taxable items (with tax) + bag fee (no tax).

### 1.5 Layout after creating a new customer --> working fine
- **Objective:** After creating a new customer from POS, layout stays aligned.
- **Steps:**
  1. On `/pos/create`, click the **“+”** button next to the customer dropdown.
  2. Fill in required fields (Name, Mobile, etc.) and click **Save**.
  3. Verify:
     - The newly created customer is selected.
     - The **Customer Account Info** panel appears under the customer field.
     - The four summary columns (Credit, Gift Cards, Lifetime, Points) display in one clean row with no overlap.
     - Product search input and cart table remain aligned.

---

## 2. Customer Account Info & Loyalty in POS

> **⚠️ IMPORTANT PREREQUISITE:** Before testing loyalty points (section 2.3), you **must configure Reward Points** in `Business Settings` → **Reward Point Settings** tab. Without this configuration, loyalty points will not be earned. See section 2.3 for detailed configuration steps.

### 2.1 Customer account summary panel --> working
- **URL:** `/pos/create`
- **Steps:**
  1. Select an existing customer from the customer dropdown.
  2. Verify the **Customer Account Info** panel appears directly under the customer field:
     - Shows **customer name**.
     - Shows **Credit**, **Gift Cards**, **Lifetime**, **Points** values.
  3. Click **“View Details”**.
  4. A modal opens with full customer account details.

### 2.2 Customer account modal (detail view) --<> working
- **What to check inside the modal:**
  - **Account Summary**:
    - Lifetime purchases (sum of all completed sales).
    - Loyalty points.
    - Loyalty tier (e.g. Bronze/Silver/Gold).
    - Last purchase date.
  - **Gift Cards**:
    - Any active gift cards & balances (if set up).
  - **Preorders**:
    - List of pending preorders (product, quantity, dates).
  - **Purchase History**:
    - List of past invoices (date, total, status).

### 2.3 Loyalty points and tiers after a sale --> WORKING FINE
- **Objective:** Points and tier update automatically when you complete a sale.
- **Prerequisites:**
  - **Reward Points must be configured** (see configuration steps below).
  - **Loyalty Tiers must be set up** (optional but recommended for tier testing).
- **Steps:**
  1. **Configure Reward Points (if not already done):**
     - Go to `Business Settings` → **Reward Point Settings** tab.
     - Check **"Enable Reward Points"**.
     - Set **Reward Point Name** (e.g., "Points").
     - Configure **Earning Points Settings:**
       - **Amount per Unit Point:** `1.00` (1 point per $1 spent).
       - **Minimum Order Total for Points:** `0.00` (or your minimum).
       - **Maximum Points per Order:** Leave empty or set a limit.
     - Configure **Redeem Points Settings** (optional for testing):
       - **Redeem Amount per Unit Point:** `0.01` (1 point = $0.01).
       - **Minimum Order Total for Redeem:** `0.00` or your minimum.
       - **Minimum Redeem Point:** `100` (or your minimum).
     - Click **Update Settings**.
  2. **Configure Loyalty Tiers (optional but recommended):**
     - Go to `/loyalty-tiers`.
     - Create at least one tier (e.g., Bronze: $0 minimum, 1.0x multiplier).
     - For tier upgrade testing, create multiple tiers:
       - **Bronze:** $0 minimum, 1.0x multiplier
       - **Silver:** $200 minimum, 1.25x multiplier
       - **Gold:** $500 minimum, 1.5x multiplier
  3. **Test Points Earning:**
     - Pick a test customer in POS (`/pos/create`).
     - Add one or more products and complete a normal sale.
     - Note the sale total (e.g., $50.00).
  4. **Verify Points and Tier Updates:**
     - Reopen `/pos/create`, select the same customer, and click **View Details**.
     - Verify:
       - **Lifetime purchases** increased by the value of the new sale.
       - **Loyalty points** increased according to your reward point configuration.
         - Example: If sale was $50 and "Amount per Unit Point" is 1.00, customer should earn 50 points (or more if tier multiplier applies).
       - **Loyalty tier** upgrades automatically if the new lifetime total crosses a tier threshold.
       - **Last purchase date** is now the date/time of the new sale.
  5. **Test Tier Multiplier (if tiers configured):**
     - If customer is in a tier with multiplier (e.g., Gold = 1.5x):
       - Make another sale (e.g., $100).
       - Expected points: $100 × 1.5 = 150 points (if multiplier is 1.5x).
       - Verify points reflect the multiplier in the customer account.

---

## 3. Customer Listing (Customers Module)

### 3.1 Customer list columns --> working fine 
- **URL:** `/contacts?type=customer`
- **Steps:**
  1. Go to `Contacts` → `Customers` or directly open `https://playlist.nivessa.com/contacts?type=customer`.
  2. Verify table columns include:
     - Lifetime Purchases
     - Loyalty Points
     - Loyalty Tier
     - Preorders

### 3.2 View Profile from customers list
- **Steps:**
  1. In the Actions column for any customer, click **View Profile** (or equivalent).
  2. The same **Customer Account Modal** used in POS should open.
  3. Confirm data matches what you see from POS:
     - Lifetime purchases.
     - Loyalty points and tier.
     - Gift cards and preorders.
     - Purchase history.

---

## 4. Preorder Management

### 4.1 Create a preorder
- **URL:** `/preorders`
- **Steps:**
  1. Go to `https://playlist.nivessa.com/preorders`.
  2. Click **Add Preorder**.
  3. Fill in:
     - Customer.
     - Product (and variation if needed).
     - Quantity.
     - Order date.
     - Expected date (optional).
     - Notes (optional).
  4. Click **Save**.
  5. Confirm:
     - New preorder appears in the list.
     - Status is **Pending**.

### 4.2 See preorders in POS customer modal
- **URL:** `/pos/create`
- **Steps:**
  1. On POS, select the same customer used in the preorder.
  2. Click **View Details**.
  3. In the **Preorders** section:
     - Confirm the preorder you created is listed with correct product, quantity, and dates.

### 4.3 Fulfill a preorder
- **URL:** `/preorders`
- **Steps:**
  1. On `/preorders`, find the preorder and click **Fulfill**.
  2. Confirm the status changes to **Fulfilled**.
  3. Back in POS → **View Details** for that customer:
     - Confirm the fulfilled preorder is no longer shown under pending preorders.

---

## 5. Purchase Management

### 5.1 Default quantity in purchase form
- **URL:** `/purchases/create`
- **Objective:** Quantity field defaults to 1 when adding products to a purchase.
- **Steps:**
  1. Go to `https://playlist.nivessa.com/purchases/create`.
  2. Search for and add a product to the purchase.
  3. Verify:
     - The **Quantity** field for the newly added product is automatically set to **1**.
     - You don't need to manually enter the quantity before saving.
     - You can still change the quantity if needed.
  4. Add multiple products and confirm each one defaults to quantity 1.

### 5.2 Edit/Update an existing purchase
- **URL:** `/purchases` → `/purchases/{id}/edit`
- **Objective:** Verify that existing purchases can be edited and updated correctly.
- **Prerequisites:**
  - At least one purchase must exist in the system.
  - The purchase must be within the allowed edit period (configured in business settings).
- **Steps:**
  1. Go to `https://playlist.nivessa.com/purchases` to view the list of purchases.
  2. Find an existing purchase and click **Edit** (or the edit icon) in the Actions column.
  3. **Verify the edit form loads correctly:**
     - All purchase details are pre-populated (supplier, date, products, quantities, prices, etc.).
     - Product rows display correctly with all fields.
  4. **Test updating purchase details:**
     - Change the **Transaction Date** (if needed).
     - Modify **quantities** for existing products.
     - Add a new product to the purchase.
     - Remove a product from the purchase.
     - Update **prices** if needed.
     - Modify **discount** or **tax** amounts.
     - Update **shipping charges** or **additional notes**.
  5. **Verify totals update correctly:**
     - Subtotal updates when quantities or prices change.
     - Tax amount recalculates based on updated values.
     - Final total reflects all changes.
  6. Click **Update** (or **Save**) to save the changes.
  7. **Confirm the update:**
     - Success message appears.
     - You are redirected to the purchases list.
     - The updated purchase shows the new values.
     - Stock levels are adjusted correctly based on quantity changes.
  8. **Test status update:**
     - Edit a purchase and change its **status** (e.g., from "Ordered" to "Received").
     - Verify stock is updated appropriately when status changes to "Received".
- **Note:** Some purchases may not be editable if:
  - They are outside the allowed edit period (configured in business settings).
  - A return has been created for that purchase.
  - The purchase has been fully processed and locked.

---

## 6. Mass Add Products (Smart Textbox)

### 5.1 Smart bulk entry
- **URL:** `/product/mass-create`
- **Steps:**
  1. Go to `https://playlist.nivessa.com/product/mass-create`.
  2. Find the **Bulk Product Entry** textarea.
  3. Paste a few lines (for testing), such as:
     - `Artist Name - Album Title - 19.99`
     - `Another Artist - Another Title - 15.00`
  4. Click the **Parse / Add Rows** button.
  5. Confirm:
     - A row is created for each line.
     - Artist, title, and price are parsed into the correct columns.
     - You can manually adjust any row before saving.

### 5.2 Save new products
- **Steps:**
  1. After verifying the rows, click **Save**.
  2. Go to `/products` and confirm the new items appear.
  3. In POS `/pos/create`, search by artist or title:
     - Confirm these new products appear in autocomplete and can be added to a sale.

---

## 7. Uncategorized Products & Bulk Category Update

### 7.1 Filter to see uncategorized products
- **URL:** `/products`
- **Steps:**
  1. Go to `https://playlist.nivessa.com/products`.
  2. Use the **Uncategorized Only** filter (checkbox or dropdown).
  3. Confirm that only products with no category assigned are shown.

### 7.2 Bulk update categories
- **URL:** `/products` → `/products/bulk-update-categories`
- **Objective:** Update category and subcategory for multiple products at once.
- **Steps:**
  1. Go to `https://playlist.nivessa.com/products`.
  2. **Select products to update:**
     - Option A: Use checkboxes to select specific products in the table.
     - Option B: Leave checkboxes unchecked to update all visible products (based on current filters).
  3. Click the **Bulk Update Categories** button (top right, blue button with tag icon).
  4. **You will be redirected to a dedicated page** (`/products/bulk-update-categories`).
  5. On the bulk update page:
     - You'll see how many products will be updated (shown in an info box).
     - **Select Category:** Choose a category from the dropdown (required).
     - **Select Subcategory:** After selecting a category, the subcategory dropdown will automatically load with available subcategories (optional).
  6. Click **Update Categories** button.
  7. Confirm:
     - Success message appears showing how many products were updated.
     - You are automatically redirected back to the products list.
     - The updated products now show the selected category/subcategory.
     - They no longer appear when you use the "Uncategorized Only" filter.
- **Note:** If no products are selected, the system will update all visible products in the current view (respecting any active filters).

---

## 8. Import Sold Items as Products (50K Path)

### 8.1 Import from transaction history
- **URL:** `/products/import-sold-items`
- **Steps:**
  1. Go to `https://playlist.nivessa.com/products/import-sold-items`.
  2. In **Import Statistics**, note the counts for:
     - Total Sold Items.
     - Items with Products.
     - Existing Products.
  3. Under **Import Options**, stay on the **From Transaction History** tab.
  4. For a safe test:
     - Set `Maximum Items to Import` to a small number (e.g. 100–500).
     - Set `Minimum Sales Count` to 1 or 2.
     - Decide whether to check **Create products even if similar products already exist**.
  5. Click **Start Import**.
  6. Confirm:
     - Progress section appears and updates to 100%.
     - Results show how many products were **Created**, **Skipped**, and any **Errors**.
  7. Go to `/products`:
     - Verify new products created by import exist and can be searched in POS.

### 8.2 Import from CSV/Excel file
- **URL:** `/products/import-sold-items` (second tab)
- **Steps:**
  1. On the same screen, switch to **Upload CSV/Excel File** tab.
  2. Prepare a small test file with columns like:
     - `Name`, `SKU`, `Artist`, `Price`.
  3. Upload the file using **Select File**.
  4. (Optional) Set:
     - Minimum sales count.
     - Whether to **Create duplicates**.
  5. Click **Upload and Import**.
  6. Confirm:
     - Progress completes.
     - Success message with stats.
     - New products from the file appear in `/products` and POS search.

---

## 9. Clover Customer Import

### 9.1 Configure Clover credentials
- **URL:** `https://playlist.nivessa.com/business/settings` → **Integrations** tab.
- **Steps:**
  1. Under **Clover POS Integration**, enter:
     - Public Token.
     - Private Token.
     - Merchant ID(s).
     - Environment (Sandbox/Production).
  2. Click **Update Settings** to save.

### 9.2 Test connection
- **Steps:**
  1. In the same **Clover Customer Import** section, click **Test Connection**.
  2. Confirm:
     - You get a success message.
     - It shows how many customers Clover returned (if implemented).

### 9.3 Preview and import customers
- **Steps:**
  1. Click **Preview Clover Customers** (button label may vary slightly).
  2. In the modal:
     - Confirm a paginated list of Clover customers appears.
  3. Select a few customers and click **Import Selected** (or import all).
  4. After import:
     - Go to `/contacts?type=customer`.
     - Verify newly imported customers exist.
     - If duplicates exist (same email/phone), they should not be duplicated.

---

## 10. Gift Cards (ERP‑Side)

### 10.1 Create gift card
- **URL:** `/gift-cards`
- **Steps:**
  1. Go to `https://playlist.nivessa.com/gift-cards`.
  2. Click **Add Gift Card**.
  3. Enter:
     - Card number.
     - Linked customer.
     - Initial balance.
     - Expiry date (optional).
  4. Save and confirm the card appears in the listing.

### 10.2 Gift cards in customer modal
- **URL:** `/pos/create`
- **Steps:**
  1. In POS, select the linked customer.
  2. Click **View Details**.
  3. Confirm the **Gift Cards** section lists the card and its current balance.

---

## 11. StreetPulse FTP Integration (High‑Level Test)

> Note: If you don't have working FTP credentials yet, just verify that failure messages are clear and there are no "Something went wrong" generic errors.

### 11.1 Configure StreetPulse settings
- **URL:** `https://playlist.nivessa.com/business/settings` → **Integrations** tab.
- **Steps:**
  1. Find the **StreetPulse** section.
  2. Enter:
     - StreetPulse Acronym.
     - Check Digit option (as per StreetPulse instructions).
  3. Save settings.

### 11.2 Test connection / manual sync (if exposed)
- **Steps:**
  1. Click **Test StreetPulse Connection** (button in Integrations section).
  2. Confirm:
     - A clear success or error message is shown.
  3. If a **Sync Now** or similar button exists:
     - Run a manual sync for a small date range.
     - Confirm the app shows a clear success message (or a clear explanation if it fails).

---

## 12. Employee Discount Behavior (No Auto Discount)

### 12.1 Confirm no automatic 20% discount
- **URL:** `/pos/create`
- **Steps:**
  1. Log in as a normal cashier user.
  2. Select different types of customers (normal retail customers).
  3. Add products for each customer and verify:
     - No **automatic 20% discount** is applied.
     - Prices match the product’s configured selling prices unless you manually edit them.

### 12.2 Employee discount checkbox
- **(If configured for specific employee customers)**
- **Steps:**
  1. Select an employee‑type customer in POS.
  2. In the **Customer Account Info** panel, verify:
     - You see the **Apply Employee Discount (20%)** checkbox row only when appropriate.
  3. Check the box and add products:
     - Verify discounted prices (20% off) are clearly reflected.
  4. Uncheck the box and re‑add products:
     - Verify items are no longer discounted.

---

## 13. Quick Regression Checks

Before signing off testing, quickly verify:

1. **Login/Logout**
   - Can log in/out without issues.

2. **Products**
   - `/products/create` and `/products/{id}/edit` pages load.
   - **Tax Exempt** and **Listing Location** fields are visible and save correctly.

3. **Contacts**
   - `/contacts?type=customer` loads and allows add/edit.

4. **Reports**
   - Items report and sales reports load without errors (`/reports/items-report` etc.).

---

## Sign‑Off Checklist

Use this mini‑checklist when you finish:

- [ ] POS sale with tax (normal product) works.
- [ ] Tax‑exempt product sale has no tax.
- [ ] Bag fee adds correctly and is **not taxed**.
- [ ] Customer account info shows in POS and customers list.
- [ ] Loyalty points and tier update after a sale.
- [ ] Preorders can be created, viewed in POS, and fulfilled.
- [ ] Purchase quantity defaults to 1 when adding products.
- [ ] Purchase can be edited and updated correctly (quantities, products, prices, status).
- [ ] Mass Add smart textbox parses lines correctly.
- [ ] Uncategorized products can be bulk categorized using the dedicated bulk update page.
- [ ] Import from **Transactions** creates products.
- [ ] Import from **CSV/Excel file** creates products.
- [ ] Clover connection test works and customers can be imported.
- [ ] Gift cards appear on customer account modal.
- [ ] StreetPulse integration shows clear success/error messages.
- [ ] No automatic 20% employee discount is applied unless explicitly requested.

---

**Last Updated:** January 28, 2026

