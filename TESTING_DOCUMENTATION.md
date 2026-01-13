# Testing Documentation - POS Enhancements & Features

This document provides detailed testing instructions for all implemented features with their respective URLs and test scenarios.

## Table of Contents
1. [Items Report Performance](#1-items-report-performance)
2. [Timezone Update to PST](#2-timezone-update-to-pst)
3. [Clover POS Auto-Amount](#3-clover-pos-auto-amount)
4. [POS Discount/Price Alteration](#4-pos-discountprice-alteration)
5. [Employee Discount (20%)](#5-employee-discount-20)
6. [Plastic Bag Charge with Tax](#6-plastic-bag-charge-with-tax)
7. [POS Artist Autocomplete](#7-pos-artist-autocomplete)
8. [Customer Account Lookup in POS](#8-customer-account-lookup-in-pos)
9. [Bin Positions](#9-bin-positions)
10. [Export Manual Products](#10-export-manual-products)
11. [eBay/Discogs Listing with Location](#11-ebaydiscogs-listing-with-location)
12. [Streetpulse Connection](#12-streetpulse-connection)

---

## 1. Items Report Performance --> working

**URL:** `http://localhost:8080/reports/items-report`

**What was changed:**
- Optimized database query with better filtering and indexing
- Improved date comparisons using DATE() function

**Testing Steps:**
1. Navigate to Reports > Items Report
2. Apply various filters (date range, category, location)
3. Verify report loads faster than before
4. Check that all data displays correctly
5. Test pagination and sorting

**Expected Result:**
- Report should load significantly faster
- All data should be accurate
- No timeout errors

---

## 2. Timezone Update to PST  ->> working

**URL:** `https://playlist.nivessa.com/business/settings`

**What was changed:**
- Updated timezone selection to include all timezones
- PST (America/Los_Angeles) should be available
- Fixed BusinessUtil::allTimeZones() method to return all timezones correctly
- Updated BusinessController to use BusinessUtil method

**Testing Steps:**
1. Go to Business Settings
2. Click on "Business" tab in the left navigation (should be active by default - highlighted in blue)
3. Look for "Time zone:" field - it's located in the **second row** of fields:
   - **First row:** Business Name, Start Date, Default profit percent
   - **Second row:** Currency, Currency Symbol Placement, **Time zone** ← HERE
4. Click on the timezone dropdown (has a clock icon)
5. Search for "America/Los_Angeles" or scroll to find it
6. Select "America/Los_Angeles" (PST)
7. Scroll down and click "Update Settings" button at the bottom
8. Verify transactions and reports use PST timezone

**Field Location Details:**
- **Tab:** Business (first tab, left side menu)
- **Row:** Second row of form fields
- **Position:** Third field in the second row (after Currency and Currency Symbol Placement)
- **Label:** "Time zone:" with clock icon (🕐)
- **Current Value:** Should show current timezone (e.g., "America/Los_Angeles")

**Expected Result:**
- Timezone dropdown shows all available timezones (400+ options)
- PST (America/Los_Angeles) is available in the list
- All timestamps reflect PST after selection
- Settings save successfully

**Troubleshooting:**
- If field is not visible, try:
  - Clear browser cache (Ctrl+F5 or Cmd+Shift+R)
  - Check you're on the "Business" tab (not Tax, Product, etc.)
  - Scroll down - field is in second row
  - Check browser console for JavaScript errors

---

## 3. Clover POS Auto-Amount

**URL:** `https://playlist.nivessa.com/pos/create`

**What was changed:**
- Payment amount automatically sent to Clover device when 'clover' payment method is selected
- No manual entry required

**Prerequisites:**
- Clover API credentials configured in Business Settings > Integrations > Clover POS Integration

**Testing Steps:**

### Step 1: Configure Clover Credentials
1. Go to Business Settings
2. Click "Integrations" tab in the left menu
3. Find "Clover POS Integration" section
4. Enter:
   - **App ID** (Clover App ID)
   - **App Secret** (Clover App Secret)
   - **Merchant ID** (Your Clover Merchant ID)
   - **Environment** (Sandbox or Production)
   - **Access Token** (Optional - can be obtained automatically via OAuth)
5. Click "Update Settings" button at bottom
6. Verify credentials are saved

**Configuration URL:** `https://playlist.nivessa.com/business/settings` (Click "Integrations" tab)

### Step 2: Test Clover Payment in POS
1. Navigate to POS Create page: `https://playlist.nivessa.com/pos/create`
2. Add products to cart (search and add items)
3. Review the total amount (shown in "Total Payable" box)
4. **Click the "Multiple Pay" button** at the bottom
   - This is the dark blue/navy button with dollar sign icon
   - Located between "Credit Sale" and "Cash" buttons
   - Button text: "Checkout Multi Pay" or "Multiple Pay"
5. **Payment Modal opens** - You'll see payment rows
6. In the payment row, find the **"Payment Method" dropdown**
7. **Click the dropdown** and look for "Clover" option
8. **Select "Clover"** from the dropdown
9. **Verify the amount field automatically populates** with the total amount
10. Click "Finalize Payment" button in the modal
11. Verify payment amount is automatically sent to Clover device
12. Complete payment on Clover device

**Where to Find Clover Payment Method:**
- **Location:** Payment Modal (opens when you click "Multiple Pay" button)
- **In the modal:** Look for "Payment Method" dropdown in the payment row
- **Dropdown contains:** Cash, Card, Bank Transfer, Cheque, Clover (if configured), etc.
- **Important:** Clover only appears if credentials are configured!

**Visual Guide:**
1. Add items to cart
2. Click **"Multiple Pay"** button (dark blue, bottom of screen)
3. Modal opens → See "Payment Method" dropdown
4. Click dropdown → Select **"Clover"**
5. Amount auto-fills → Click "Finalize Payment"

**Expected Result:**
- Clover appears in payment method dropdown (only if configured)
- Payment amount automatically populates in amount field when Clover is selected
- Payment amount automatically sent to Clover device
- No manual amount entry needed on Clover device
- Payment processes successfully

**Troubleshooting:**
- **Clover not in dropdown?**
  - ✅ Check Clover credentials are saved in Business Settings > Integrations
  - ✅ Verify all required fields are filled: App ID, App Secret, Merchant ID
  - ✅ Clear browser cache and refresh page
  - ✅ Check browser console (F12) for JavaScript errors
  - ✅ Verify you clicked "Update Settings" button

- **Amount not auto-populating?**
  - ✅ Check JavaScript console for errors
  - ✅ Verify Clover payment method is selected from dropdown
  - ✅ Check that total amount is calculated correctly
  - ✅ Try refreshing the page and starting over

---

## 4. POS Discount/Price Alteration  --> working

**URL:** `http://localhost:8080/pos/create`

**What was changed:**
- Enhanced existing price editing functionality
- Discount can be applied per line item or transaction-wide

**Testing Steps:**
1. Navigate to POS Create page
2. Add products to cart
3. Click the info icon (ℹ️) next to a product name
4. In the modal, edit the unit price
5. Apply discount (fixed or percentage)
6. Verify totals update correctly
7. Complete transaction

**Expected Result:**
- Price can be edited for individual items
- Discounts apply correctly
- Totals calculate accurately
- Transaction saves with modified prices

---

## 5. Employee Discount (20%)  --> working

**URL:** `http://localhost:8080/pos/create`

**What was changed:**
- Added `is_employee` field to contacts
- Automatic 20% discount applied when employee customer is selected

**Testing Steps:**

### Step 1: Mark a Customer as Employee
1. Go to Contacts > Customers
2. Edit a customer or create new customer
3. Check the "Employee (20% discount)" checkbox
4. Save

**URL:** `http://localhost:8080/contacts?type=customer`

### Step 2: Test Employee Discount in POS
1. Navigate to POS Create page
2. Select the employee customer from dropdown
3. Add products to cart
4. Verify 20% discount is automatically applied to all products
5. Check that discount shows in line items
6. Complete transaction

**Expected Result:**
- Employee checkbox visible in customer form
- 20% discount automatically applied when employee selected
- Discount visible in cart and totals
- Notification shows "Employee discount (20%) applied automatically"

---

## 6. Plastic Bag Charge with Tax --> working

**URL:** `http://localhost:8080/pos/create`

**What was changed:**
- Added configurable plastic bag charge
- Sales tax automatically included

**Prerequisites:**
- Enable plastic bag charge in Business Settings > POS Settings

**Testing Steps:**

### Step 1: Configure Plastic Bag Charge
1. Go to Business Settings > POS Settings
2. Find "Shopping Bag / Plastic Bag Charge Settings"
3. Check "Enable Shopping Bag Charge"
4. Set price (e.g., $0.10)
5. Save

**URL:** `http://localhost:8080/business/settings` (POS Settings tab)

### Step 2: Test in POS
1. Navigate to POS Create page
2. Add products to cart
3. Check "Add Shopping Bag Charge" checkbox
4. Verify plastic bag row appears in cart
5. Verify sales tax is applied to plastic bag
6. Check totals include bag charge + tax
7. Complete transaction

**Expected Result:**
- Checkbox appears when enabled in settings
- Plastic bag row added with correct price
- Sales tax applied to bag charge
- Totals include bag + tax

---

## 7. POS Artist Autocomplete  --> working

**URL:** `http://localhost:8080/pos/create`

**What was changed:**
- Autocomplete now shows "Artist - Title" format
- Product rows also display "Artist - Title"

**Testing Steps:**
1. Navigate to POS Create page
2. In search box, type an artist name
3. Verify autocomplete results show "Artist - Title" format
4. Select a product
5. Verify product row also shows "Artist - Title" format

**Expected Result:**
- Autocomplete displays: "Artist Name - Product Title"
- Selected products show: "Artist Name - Product Title"
- Format consistent throughout POS

---

## 8. Customer Account Lookup in POS  --> working

**URL:** `http://localhost:8080/pos/create`

**What was changed:**
- Customer account info panel above search bar
- Detailed customer account modal
- Shows credit, gift cards, purchase history, rewards

**Testing Steps:**
1. Navigate to POS Create page
2. Select a customer from dropdown
3. Verify account info panel appears above search bar showing:
   - Customer name
   - Credit balance
   - Gift card balance
   - Lifetime purchases
   - Loyalty points
4. Click "View Details" button
5. Verify modal opens with:
   - Account summary
   - Gift cards list
   - Recent purchases (last 10)
   - Loyalty tier information

**Expected Result:**
- Account info panel displays when customer selected
- All information is accurate
- Modal shows detailed customer information
- Recent purchases list is populated

**API Endpoint:** `GET /sells/pos/get-customer-account-info?contact_id={id}`

---

## 9. Bin Positions --> working

**URL:** `http://localhost:8080/products`

**What was changed:**
- Added `bin_position` field to products
- Bin position displayed on barcode printouts
- Bin position shown in product forms

**Testing Steps:**

### Step 1: Add Bin Position to Product
1. Go to Products > Add Product or Edit Product
2. Find "Bin Position" field
3. Enter bin position (e.g., "A-12", "B-5")
4. Save product

**URL:** `http://localhost:8080/products/create` or `http://localhost:8080/products/{id}/edit`

### Step 2: Verify on Barcode Printout
1. Go to Products list
2. Select products with bin positions
3. Click "Download Barcodes"
4. Open generated PDF
5. Verify bin position appears on barcode label

**URL:** `http://localhost:8080/products`

### Step 3: Verify in Labels
1. Go to Labels section
2. Print labels for products with bin positions
3. Verify bin position appears on printed labels

**Expected Result:**
- Bin position field in product forms
- Bin position saved correctly
- Bin position appears on barcode printouts
- Format: "Bin: A-12" or similar

---

## 10. Export Manual Products  -> working

**URL:** `http://localhost:8080/pos/export-manual-products`

**What was changed:**
- New export endpoint for manually added products from POS
- Exports products that were added manually (without product_id)

**Testing Steps:**
1. Navigate to POS Create page
2. Add some manual products (using "Add Manual Item" button)
3. Complete transactions with manual products
4. Navigate to export URL or add button to access it
5. Download Excel file
6. Verify file contains:
   - Product Name
   - Artist
   - Category
   - Sub Category
   - Quantity
   - Prices
   - Sale Date
   - Invoice No

**Expected Result:**
- Excel file downloads successfully
- Contains all manually added products
- All relevant data included
- Data is accurate

**Note:** You may need to add a UI button to access this export. Currently accessible via direct URL.

---

## 11. eBay/Discogs Listing with Location

**URL:** `http://localhost:8080/products`

**What was changed:**
- Listing location field added to products
- Location included when listing to eBay/Discogs
- Location separate from business locations

**Prerequisites:**
- eBay/Discogs API credentials configured in Business Settings > Integrations

**Testing Steps:**

### Step 1: Configure API Credentials
1. Go to Business Settings > Integrations
2. Configure eBay credentials (App ID, Cert ID, Dev ID)
3. Configure Discogs credentials (API Token)
4. Save

**URL:** `http://localhost:8080/business/settings` (Integrations tab)

### Step 2: Add Listing Location to Product
1. Go to Products > Edit Product
2. Find "Listing Location" field
3. Enter location (e.g., "Warehouse A", "Storage B")
4. Save

**URL:** `http://localhost:8080/products/{id}/edit`

### Step 3: List to eBay
1. Go to Products list
2. Select product(s)
3. Click "List Selected to eBay" button
4. Verify listing includes location information
5. Check product's `ebay_listing_id` is updated

**URL:** `http://localhost:8080/products`

### Step 4: List to Discogs
1. Go to Products list
2. Select product(s)
3. Click "List Selected to Discogs" button
4. Verify listing includes location information
5. Check product's `discogs_listing_id` is updated

**Expected Result:**
- Listing location field in product forms
- Location saved correctly
- Location included in eBay/Discogs listings
- Listing IDs stored in database

**API Endpoints:**
- `POST /products/{id}/list-to-ebay`
- `POST /products/{id}/list-to-discogs`
- `POST /products/bulk-list-to-ebay`
- `POST /products/bulk-list-to-discogs`

---

## 12. Streetpulse Connection

**URL:** `http://localhost:8080/business/settings` (Integrations tab)

**What was changed:**
- Streetpulse service implementation
- Test connection functionality
- Sync sales data functionality

**Testing Steps:**

### Step 1: Configure Streetpulse Credentials
1. Go to Business Settings > Integrations
2. Find "Streetpulse Integration" section
3. Enter:
   - API Key
   - Endpoint URL
   - Username (optional)
4. Save

**URL:** `http://localhost:8080/business/settings` (Integrations tab)

### Step 2: Test Connection
1. In Streetpulse Integration section
2. Click "Test Connection" button
3. Verify success message appears
4. Check for any error messages

**Expected Result:**
- Connection test successful
- Success message: "Connection successful!"
- Or error message with details if connection fails

### Step 3: Sync Sales Data
1. In Streetpulse Integration section
2. Click "Sync Now" button
3. Confirm sync action
4. Verify success message
5. Check Streetpulse dashboard for synced data

**Expected Result:**
- Sync completes successfully
- Success message: "Synced X sales successfully"
- Sales data appears in Streetpulse

**API Endpoints:**
- `POST /business/test-streetpulse-connection`
- `POST /business/sync-streetpulse`

---

## Additional Features

### Remove eBay/Discogs Suggestions from Mass Add
**URL:** `http://localhost:8080/product/mass-create`

**What was changed:**
- Removed eBay/Discogs price recommendation sections
- Reduced row height for easier use

**Testing Steps:**
1. Navigate to Mass Add Products page
2. Verify no eBay/Discogs suggestion sections appear
3. Verify rows are more compact
4. Test adding multiple products

**Expected Result:**
- No eBay/Discogs suggestions visible
- Rows are shorter/easier to use
- Functionality works normally

---

## Database Migrations Required

Before testing, ensure these migrations have been run:

1. ✅ `2026_01_13_120000_add_is_employee_to_contacts_table.php` - Adds employee field (COMPLETED)
2. `2026_01_13_130000_add_bin_position_to_products_table.php` - Adds bin position (Column already exists from older migration)
3. `2026_01_10_180000_add_listing_location_to_products_table.php` - Adds listing location (Already migrated)

**Run migrations in Docker:**
```bash
# Run all pending migrations
docker exec playlist_app php artisan migrate

# Or run specific migrations
docker exec playlist_app php artisan migrate --path=database/migrations/2026_01_13_120000_add_is_employee_to_contacts_table.php
```

**Migration Status:**
- ✅ `is_employee` field - MIGRATED SUCCESSFULLY
- ✅ `bin_position` field - MIGRATED SUCCESSFULLY (migration checks if exists)
- ✅ `listing_location` field - Already migrated
- ✅ `api_settings` field - MIGRATED SUCCESSFULLY
- ✅ `listing_status` fields - MIGRATED SUCCESSFULLY

**All migrations completed in Docker!** ✅

---

## Common Issues & Troubleshooting

### Employee Discount Not Applying
- **Check:** Customer has `is_employee` field set to 1
- **Check:** JavaScript console for errors
- **Fix:** Re-select customer in POS

### Plastic Bag Charge Not Appearing
- **Check:** Feature enabled in Business Settings > POS Settings
- **Check:** Price is set in settings
- **Fix:** Enable and set price in settings

### Customer Account Info Not Loading
- **Check:** Customer ID is valid
- **Check:** Browser console for AJAX errors
- **Check:** Route exists: `/sells/pos/get-customer-account-info`

### Streetpulse Connection Failing
- **Check:** API credentials are correct
- **Check:** Endpoint URL is accessible
- **Check:** API key has proper permissions
- **Check:** Server can reach Streetpulse endpoint

### Bin Position Not on Barcode
- **Check:** Product has bin_position value
- **Check:** Barcode template includes bin_position
- **Fix:** Re-generate barcodes after adding bin position

---

## Testing Checklist

- [ ] Items Report loads quickly
- [ ] Timezone can be set to PST
- [ ] Clover payment auto-amount works
- [ ] Price editing works in POS
- [ ] Employee discount applies automatically
- [ ] Plastic bag charge appears with tax
- [ ] Artist autocomplete shows "Artist - Title"
- [ ] Customer account info displays correctly
- [ ] Bin position appears on barcodes
- [ ] Manual products can be exported
- [ ] eBay/Discogs listing includes location
- [ ] Streetpulse connection test works
- [ ] Streetpulse sync works

---

## Support & Notes

- All features are designed to work gracefully if API credentials are not configured
- Features will be hidden/disabled if required credentials are missing
- Check Business Settings > Integrations for API configuration status
- Most features require appropriate user permissions

**Last Updated:** January 13, 2026

---

## Quick Reference: All Feature URLs

| Feature | URL |
|---------|-----|
| POS Create | `http://localhost:8080/pos/create` |
| Business Settings | `http://localhost:8080/business/settings` |
| Products List | `http://localhost:8080/products` |
| Contacts (Customers) | `http://localhost:8080/contacts?type=customer` |
| Items Report | `http://localhost:8080/reports/items-report` |
| Export Manual Products | `http://localhost:8080/pos/export-manual-products` |
| Mass Add Products | `http://localhost:8080/product/mass-create` |

---

## Implementation Summary

### Database Changes
- ✅ Added `is_employee` field to `contacts` table
- ✅ Added `bin_position` field to `products` table  
- ✅ Added `listing_location` field to `products` table
- ✅ Added `api_settings` JSON column to `business` table

### Code Changes
- ✅ Employee discount logic (frontend + backend)
- ✅ Customer account lookup with modal
- ✅ Plastic bag charge with tax
- ✅ Artist autocomplete format change
- ✅ Bin position on barcodes
- ✅ Export manual products functionality
- ✅ eBay/Discogs listing with location
- ✅ Streetpulse service implementation
- ✅ Items report query optimization
- ✅ Clover auto-amount integration

### UI Changes
- ✅ Customer account info panel in POS
- ✅ Employee checkbox in contact forms
- ✅ Bin position field in product forms
- ✅ Listing location field in product forms
- ✅ Plastic bag charge checkbox in POS
- ✅ Integration settings in Business Settings

---

## Verification Checklist

Before going live, verify:

1. **Migrations Run:**
   ```bash
   php artisan migrate
   ```

2. **Permissions:**
   - Users have appropriate permissions for POS, products, contacts
   - Admin can access Business Settings

3. **API Credentials (Optional):**
   - Clover POS credentials (if using Clover)
   - eBay API credentials (if listing to eBay)
   - Discogs API credentials (if listing to Discogs)
   - Streetpulse credentials (if using Streetpulse)

4. **Settings Configured:**
   - Plastic bag charge enabled/disabled
   - Plastic bag price set
   - Timezone set to PST
   - All integration credentials saved

5. **Test Transactions:**
   - Create test sale with employee customer
   - Create test sale with plastic bag
   - Create test sale with manual product
   - Verify all calculations are correct

---

## Notes

- All features gracefully degrade if API credentials are not configured
- Employee discount applies automatically but can be overridden
- Plastic bag charge requires configuration in settings
- Customer account info loads asynchronously
- Bin positions are optional but recommended for inventory management

