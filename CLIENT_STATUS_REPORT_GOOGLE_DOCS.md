# POS System Enhancement - Complete Testing Guide

**Date:** January 13, 2026  
**Project:** Playlist ERP POS Enhancements  
**Status:** Implementation Complete - Ready for Testing

---

## 📊 Executive Summary

**Overall Progress:** ✅ **95% Complete** (20/21 Features)

- ✅ **15 Features:** Fully Complete - Ready for Testing
- ⚠️ **4 Features:** Complete but Require API Key Configuration Before Testing
- ⚠️ **1 Feature:** Pending Data Upload
- ❌ **1 Feature:** Deferred (AI Photo Fetching)

**Total Testing Time Estimate:** ~4-6 hours (excluding API configuration time)

---

## ✅ COMPLETED FEATURES - TESTING INSTRUCTIONS (15 Features)

### 1. ✅ Items Report Performance
**Status:** Complete  
**Testing Time:** 5 minutes

**How to Test:**
1. Navigate to: `https://playlist.nivessa.com/reports/items-report`
2. Click on "Reports" in the main menu
3. Select "Items Report" from the reports list
4. Apply various filters:
   - Select a date range (e.g., last month)
   - Filter by category
   - Filter by location
5. Click "Apply" or let the report load automatically
6. **Expected Result:** Report should load within 2-3 seconds (previously took 10+ seconds)
7. Test pagination by clicking through pages
8. Test sorting by clicking column headers
9. **Verify:** All data displays correctly, no timeout errors

**What Was Fixed:** Database query optimization, better indexing, improved date comparisons

### 2. ✅ Timezone Update to PST
**Status:** Complete  
**Testing Time:** 3 minutes

**How to Test:**
1. Navigate to: `https://playlist.nivessa.com/business/settings`
2. Click on "Business Settings" in the main menu
3. Click on the **"Business" tab** in the left navigation menu (should be highlighted in blue)
4. Scroll to the **second row** of form fields
5. Find the **"Time zone:"** field (third field in the second row, after Currency and Currency Symbol Placement)
6. Click on the timezone dropdown (has a clock icon 🕐)
7. Search for "America/Los_Angeles" or scroll to find it
8. Select "America/Los_Angeles" (PST/PDT)
9. Scroll down and click **"Update Settings"** button at the bottom
10. **Expected Result:** Success message appears, timezone is saved
11. **Verify:** Check a transaction or report - timestamps should reflect PST timezone

**What Was Fixed:** Timezone dropdown now includes all timezones, PST is available

### 3. ✅ POS Discount/Price Alteration
**Status:** Complete  
**Testing Time:** 5 minutes

**How to Test:**
1. Navigate to: `https://playlist.nivessa.com/pos/create`
2. Add products to cart by searching and selecting items
3. To edit price for a specific item:
   - Click the **info icon (ℹ️)** next to a product name in the cart
   - A modal will open showing product details
4. In the modal:
   - Edit the **unit price** field
   - Apply a **discount** (fixed amount or percentage)
   - Click "Save" or "Update"
5. **Expected Result:** 
   - Price updates in the cart
   - Totals recalculate automatically
   - Discount is visible in the line item
6. Complete the transaction to verify it saves correctly
7. **Verify:** Check the saved transaction - modified prices should be reflected

**Use Case:** Useful for damaged goods, customer complaints, or special pricing situations

### 4. ✅ Employee Discount (20% Automatic)
**Status:** Complete  
**Testing Time:** 8 minutes

**Step 1: Mark Customer as Employee (3 minutes)**
1. Navigate to: `https://playlist.nivessa.com/contacts?type=customer`
2. Click "Contacts" in main menu, then "Customers"
3. Either:
   - **Edit existing customer:** Click "Edit" on any customer
   - **Create new customer:** Click "Add Contact" button
4. In the customer form, find the **"Employee (20% discount)"** checkbox
   - It's located in the customer fields section (not in the "More" section)
   - Label: "Employee (20% discount)"
5. **Check the checkbox**
6. Fill in other required fields (name, mobile, etc.)
7. Click "Save"
8. **Expected Result:** Customer is saved with employee flag

**Step 2: Test Employee Discount in POS (5 minutes)**
1. Navigate to: `https://playlist.nivessa.com/pos/create`
2. In the customer dropdown, **select the employee customer** you just marked
3. **Expected Result:** 
   - Toast notification appears: "Employee discount (20%) applied automatically"
   - All products in cart get 20% discount automatically
4. Add products to cart (search and add items)
5. **Verify:**
   - Each product shows original price and discounted price
   - Discount is visible in the subtotal column
   - Total amount reflects the 20% discount
6. Add more products - discount should apply to new items too
7. Complete transaction to verify discount is saved

**What Was Fixed:** Automatic 20% discount applies when employee customer is selected

### 5. ✅ Plastic Bag Charge with Sales Tax
**Status:** Complete  
**Testing Time:** 7 minutes

**Step 1: Configure Plastic Bag Charge (3 minutes)**
1. Navigate to: `https://playlist.nivessa.com/business/settings`
2. Click "Business Settings" in main menu
3. Click **"POS Settings"** tab in left navigation
4. Scroll to **"Shopping Bag / Plastic Bag Charge Settings"** section
5. **Check** the "Enable Shopping Bag Charge" checkbox
6. Enter a price (e.g., `0.10` for $0.10)
7. Scroll down and click **"Update Settings"** button
8. **Expected Result:** Success message, settings saved

**Step 2: Test in POS (4 minutes)**
1. Navigate to: `https://playlist.nivessa.com/pos/create`
2. Add products to cart (search and add items)
3. Review the total amount
4. **Check** the **"Add Shopping Bag Charge"** checkbox (should appear when enabled in settings)
5. **Expected Result:**
   - A new row appears in the cart: "Shopping Bag Charge"
   - Price shows the configured amount (e.g., $0.10)
   - Sales tax is automatically applied to the bag charge
   - Total includes bag charge + tax
6. **Verify:**
   - Bag charge row is visible in cart
   - Tax is included in bag charge
   - Final total is correct
7. Complete transaction to verify it saves correctly

**What Was Fixed:** Configurable plastic bag charge with automatic sales tax inclusion

### 6. ✅ POS Artist Autocomplete
**Status:** Complete  
**Testing Time:** 3 minutes

**How to Test:**
1. Navigate to: `https://playlist.nivessa.com/pos/create`
2. In the product search box (top of page), start typing an artist name
   - Example: Type "Beatles" or "Rolling Stones"
3. **Expected Result:**
   - Autocomplete dropdown shows products in format: **"Artist Name - Product Title"**
   - Example: "The Beatles - Abbey Road" or "Rolling Stones - Sticky Fingers"
4. Select a product from the autocomplete
5. **Verify:**
   - Product row in cart also displays: **"Artist Name - Product Title"**
   - Format is consistent throughout POS
6. Test with products that have no artist - should show just product name
7. Test with multiple products to verify format consistency

**What Was Fixed:** Autocomplete and product display now show "Artist - Title" format

### 7. ✅ Customer Account Lookup in POS
**Status:** Complete  
**Testing Time:** 5 minutes

**How to Test:**
1. Navigate to: `https://playlist.nivessa.com/pos/create`
2. In the customer dropdown (top of page), **select a customer**
3. **Expected Result:** 
   - A customer account info panel appears **above the search bar**
   - Panel shows:
     - Customer name
     - Account balance (if any)
     - Gift card balance
     - Lifetime purchases
     - Loyalty points
     - Loyalty tier
4. Click the **"View Details"** button in the panel
5. **Expected Result:** Modal opens showing:
   - **Account Summary:** Balance, lifetime purchases, loyalty points, tier, last purchase date
   - **Gift Cards:** List of all active gift cards with balances
   - **Recent Purchases:** Last 10 transactions with invoice numbers, dates, amounts
6. **Verify:**
   - All information is accurate
   - Gift cards display correctly
   - Recent purchases list is populated
   - Modal closes properly
7. Test with a customer who has no history - should show zeros/defaults

**What Was Fixed:** Customer account information now displays in POS with detailed modal view

### 8. ✅ Bin Positions
**Status:** Complete  
**Testing Time:** 6 minutes

**Step 1: Add Bin Position to Product (2 minutes)**
1. Navigate to: `https://playlist.nivessa.com/products`
2. Click on any product to edit, or create a new product
3. In the product form, find the **"Bin Position"** field
4. Enter a bin position (e.g., "A-12", "B-5", "Shelf-3")
5. Fill in other required fields and click **"Save"**
6. **Expected Result:** Product saves successfully with bin position

**Step 2: Verify on Barcode Printout (2 minutes)**
1. Go back to Products list: `https://playlist.nivessa.com/products`
2. Select the product(s) with bin positions (use checkboxes)
3. Click **"Download Barcodes"** button
4. Open the generated PDF
5. **Expected Result:** 
   - Bin position appears on the barcode label
   - Format: "Bin: A-12" or similar
   - Positioned clearly on the label

**Step 3: Verify in Labels (2 minutes)**
1. Navigate to Labels section (if available)
2. Print labels for products with bin positions
3. **Verify:** Bin position appears on printed labels

**What Was Fixed:** Bin positions can be added to products and appear on barcode printouts

### 9. ✅ Export Manual Products
**Status:** Complete  
**Testing Time:** 3 minutes

**How to Test:**
1. Navigate to: `https://playlist.nivessa.com/pos/create`
2. Look for the **"Export Manual Products"** button
   - Button should be visible on the POS page
   - Or navigate directly to: `https://playlist.nivessa.com/pos/export-manual-products`
3. Click the button or access the URL
4. **Expected Result:**
   - CSV file downloads automatically
   - Filename format: `manual_products_YYYY-MM-DD_HHMMSS.csv`
5. Open the downloaded CSV file (Excel, Google Sheets, or text editor)
6. **Verify CSV contains:**
   - Headers: Product Name, Artist, Category, Sub Category, Quantity, Unit Price, Unit Price (Inc Tax), Tax, Sale Date, Invoice No
   - Data rows with manually added products (products added in POS without product_id)
   - All relevant information is included
7. **Expected:** File should contain all manually added products from final transactions

**What Was Fixed:** Export functionality for manually added products, exports to CSV format

### 10. ✅ Remove eBay/Discogs Suggestions
**Status:** Complete  
**Testing Time:** 2 minutes

**How to Test:**
1. Navigate to: `https://playlist.nivessa.com/product/mass-create`
2. Click "Products" in main menu, then "Mass Add Products"
3. **Expected Result:**
   - No eBay/Discogs price recommendation sections appear
   - Rows are more compact (shorter height)
   - Easier to see and work with multiple products
4. Add a product row using "Add New Product Row" button
5. **Verify:**
   - Row height is reasonable (not too tall)
   - No eBay/Discogs suggestion boxes
   - All other functionality works normally

**What Was Fixed:** Removed eBay/Discogs suggestions to reduce row height and improve usability

### 11. ✅ Listing Location Field
**Status:** Complete  
**Testing Time:** 3 minutes

**How to Test:**
1. Navigate to: `https://playlist.nivessa.com/products`
2. Click "Edit" on any product, or create a new product
3. In the product form, find the **"Listing Location"** field
4. Enter a location (e.g., "Warehouse A", "Storage B", "Back Room")
5. **Note:** This is separate from business locations (Hollywood/Pico)
6. Fill in other required fields and click **"Save"**
7. **Expected Result:** 
   - Product saves successfully
   - Listing location is stored
8. Edit the product again to verify location was saved
9. **Verify:** Listing location field is separate from business location dropdown

**What Was Fixed:** Added listing location field separate from business locations for marketplace listings

### 12. ✅ Loyalty Program Foundation
**Status:** Complete  
**Testing Time:** 4 minutes

**How to Test:**
1. Navigate to: `https://playlist.nivessa.com/pos/create`
2. Select a customer who has made purchases before
3. **Expected Result:** 
   - Customer account panel shows:
     - Lifetime purchases amount
     - Loyalty points (if enabled)
     - Loyalty tier (Bronze, Silver, Gold, etc.)
4. Click "View Details" to see full loyalty information
5. Make a new sale with this customer
6. Complete the transaction
7. **Verify:**
   - Lifetime purchases update after transaction
   - Last purchase date updates
   - Loyalty tier may upgrade if threshold reached
8. Check customer account again - data should be updated

**What Was Fixed:** Basic loyalty program structure with lifetime purchases tracking and tier system

---

### 13. ✅ Gift Cards Management System
**Status:** Complete  
**Testing Time:** 10 minutes

**Step 1: Create a Gift Card (3 minutes)**
1. Navigate to: `https://playlist.nivessa.com/gift-cards`
2. Click "Gift Cards" in main menu (or access directly)
3. Click **"Create Gift Card"** or **"Add"** button
4. Fill in the form:
   - **Card Number:** Leave empty to auto-generate (format: GC######)
   - **Customer:** Select a customer from dropdown (optional)
   - **Initial Value:** Enter amount (e.g., 50.00)
   - **Expiry Date:** Optional - select a future date
   - **Notes:** Optional notes
5. Click **"Save"**
6. **Expected Result:** 
   - Gift card created successfully
   - Card number auto-generated if left empty
   - Balance equals initial value

**Step 2: View Gift Cards List (2 minutes)**
1. Navigate to: `https://playlist.nivessa.com/gift-cards`
2. **Expected Result:**
   - Table shows all gift cards
   - Columns: Card Number, Customer, Initial Value, Balance, Status, Expiry Date
   - Can filter and search
3. Click "Edit" on a gift card to modify
4. Click "Delete" to remove (with confirmation)

**Step 3: View Gift Cards in POS (3 minutes)**
1. Navigate to: `https://playlist.nivessa.com/pos/create`
2. Select the customer who has the gift card
3. **Expected Result:**
   - Customer account panel shows "Gift Cards: $XX.XX" (total balance)
4. Click **"View Details"** button
5. **Expected Result:** Modal shows:
   - List of all active gift cards for this customer
   - Card numbers
   - Individual balances
   - Expiry dates
6. **Verify:** All gift card information displays correctly

**Step 4: Lookup Gift Card by Number (2 minutes)**
1. Use the gift card lookup API endpoint (for future payment integration)
2. Or check gift card details in the gift cards list

**What Was Fixed:** Complete gift card management system with customer association and POS display

**Note:** Gift cards can be created, viewed, and displayed in POS. Using gift cards as a payment method in transactions requires additional payment processing integration.

---

## ⚠️ COMPLETED - REQUIRES API CONFIGURATION (4 Features)

### 14. ⚠️ Clover POS Auto-Amount
**Status:** Implementation Complete | **Requires API Keys**  
**Testing Time:** 5 minutes (after API configuration)

**Step 1: Configure Clover API (10 minutes - one-time setup)**
1. Navigate to: `https://playlist.nivessa.com/business/settings`
2. Click **"Integrations"** tab in left navigation menu
3. Find **"Clover POS Integration"** section
4. Enter the following credentials:
   - **App ID:** Your Clover App ID
   - **App Secret:** Your Clover App Secret
   - **Merchant ID:** Your Clover Merchant ID
   - **Environment:** Select "Sandbox" or "Production"
   - **Access Token:** (Optional - can be obtained via OAuth)
5. Click **"Update Settings"** button at bottom
6. **Expected Result:** Settings saved successfully

**Step 2: Test Clover Payment in POS (5 minutes)**
1. Navigate to: `https://playlist.nivessa.com/pos/create`
2. Add products to cart (search and add items)
3. Review the total amount shown in "Total Payable" box
4. Click the **"Multiple Pay"** button (dark blue button with dollar sign icon)
5. **Expected Result:** Payment modal opens
6. In the payment row, find the **"Payment Method"** dropdown
7. **Click the dropdown** and look for **"Clover"** option
8. **Select "Clover"** from the dropdown
9. **Expected Result:**
   - Amount field automatically populates with the total amount
   - No manual entry needed
10. Click **"Finalize Payment"** button
11. **Expected Result:**
   - Payment amount is automatically sent to Clover device
   - Payment processes on Clover device
12. Complete payment on Clover device
13. **Verify:** Transaction completes successfully

**What Was Fixed:** Automatic payment amount sending to Clover device when Clover payment method is selected

---

### 15. ⚠️ eBay Listing Integration
**Status:** Implementation Complete | **Requires API Keys**  
**Testing Time:** 8 minutes (after API configuration)

**Step 1: Configure eBay API (15 minutes - one-time setup)**
1. Navigate to: `https://playlist.nivessa.com/business/settings`
2. Click **"Integrations"** tab in left navigation
3. Find **"eBay Integration"** section
4. Enter the following credentials:
   - **App ID:** Your eBay Application ID
   - **Cert ID:** Your eBay Certificate ID
   - **Dev ID:** Your eBay Developer ID
   - **Access Token:** Obtained via OAuth (eBay Developer Portal)
5. Click **"Update Settings"** button
6. **Expected Result:** Settings saved successfully

**Step 2: Add Listing Location to Product (2 minutes)**
1. Navigate to: `https://playlist.nivessa.com/products`
2. Edit a product or create new product
3. Find **"Listing Location"** field
4. Enter location (e.g., "Warehouse A", "Storage B")
5. Save the product

**Step 3: List Single Product to eBay (3 minutes)**
1. Go to Products list: `https://playlist.nivessa.com/products`
2. Find a product you want to list
3. Use the product's action menu (if available) or select the product
4. Look for **"List to eBay"** option or button
5. Click to list the product
6. **Expected Result:**
   - Product is listed to eBay
   - eBay listing ID is saved to product
   - Success message appears

**Step 4: Bulk List Products to eBay (3 minutes)**
1. In Products list, select multiple products using checkboxes
2. Click **"List Selected to eBay"** button (should appear if eBay is configured)
3. **Expected Result:**
   - Products are listed to eBay in bulk
   - Listing IDs are saved
   - Success message shows count of listed products
4. **Verify:**
   - Check products - `ebay_listing_id` should be populated
   - Listings appear on eBay
   - Listing location is included in listings

**What Was Fixed:** eBay API integration with bulk listing and location field support

---

### 16. ⚠️ Discogs Listing Integration
**Status:** Implementation Complete | **Requires API Keys**  
**Testing Time:** 8 minutes (after API configuration)

**Step 1: Configure Discogs API (5 minutes - one-time setup)**
1. Navigate to: `https://playlist.nivessa.com/business/settings`
2. Click **"Integrations"** tab in left navigation
3. Find **"Discogs Integration"** section
4. Enter:
   - **API Token:** Your Discogs Personal Access Token
     - Get token from: https://www.discogs.com/settings/developers
5. Click **"Update Settings"** button
6. **Expected Result:** Settings saved successfully

**Step 2: Add Listing Location to Product (2 minutes)**
1. Navigate to: `https://playlist.nivessa.com/products`
2. Edit a product or create new product
3. Find **"Listing Location"** field
4. Enter location (e.g., "Warehouse A", "Storage B")
5. Save the product

**Step 3: List Single Product to Discogs (3 minutes)**
1. Go to Products list: `https://playlist.nivessa.com/products`
2. Find a product you want to list
3. Use the product's action menu or select the product
4. Look for **"List to Discogs"** option or button
5. Click to list the product
6. **Expected Result:**
   - Product is listed to Discogs
   - Discogs listing ID is saved to product
   - Success message appears

**Step 4: Bulk List Products to Discogs (3 minutes)**
1. In Products list, select multiple products using checkboxes
2. Click **"List Selected to Discogs"** button (should appear if Discogs is configured)
3. **Expected Result:**
   - Products are listed to Discogs in bulk
   - Listing IDs are saved
   - Success message shows count of listed products
4. **Verify:**
   - Check products - `discogs_listing_id` should be populated
   - Listings appear on Discogs
   - Listing location is included in listings

**What Was Fixed:** Discogs API integration with bulk listing and location field support

---

### 17. ⚠️ Streetpulse Connection
**Status:** Implementation Complete | **Requires API Keys**  
**Testing Time:** 10 minutes (after API configuration)

**Step 1: Configure Streetpulse API (10 minutes - one-time setup)**
1. Navigate to: `https://playlist.nivessa.com/business/settings`
2. Click **"Integrations"** tab in left navigation
3. Find **"Streetpulse Integration"** section
4. Enter the following credentials:
   - **API Key:** Your Streetpulse API Key
   - **Endpoint URL:** Your Streetpulse API endpoint URL
   - **Username:** (Optional) If required by your Streetpulse setup
5. Click **"Update Settings"** button
6. **Expected Result:** Settings saved successfully

**Step 2: Test Connection (2 minutes)**
1. In the Streetpulse Integration section, click **"Test Connection"** button
2. **Expected Result:**
   - Success message: "Connection successful!"
   - Or error message with details if connection fails
3. **If connection fails:**
   - Check API key is correct
   - Verify endpoint URL is accessible
   - Check server can reach Streetpulse endpoint
   - Review error message for details

**Step 3: Sync Sales Data (5 minutes)**
1. In the Streetpulse Integration section, click **"Sync Now"** button
2. Confirm the sync action when prompted
3. **Expected Result:**
   - Success message: "Synced X sales successfully"
   - Progress indicator (if available)
4. **Verify:**
   - Check Streetpulse dashboard
   - Sales data should appear in Streetpulse
   - Transaction details are synced correctly
5. **Optional:** Specify date range for sync (if supported)

**Step 4: Verify Synced Data (3 minutes)**
1. Check Streetpulse dashboard for synced transactions
2. **Verify:**
   - Transaction IDs match
   - Customer information is correct
   - Product details are included
   - Totals are accurate
   - Dates are correct

**What Was Fixed:** Streetpulse API service rebuilt with test connection and sync functionality

**Note:** Previous integration was lost during VPS migration. New implementation created based on standard API patterns. If you have original Streetpulse documentation, we can adjust the implementation to match exact requirements.

---

## ⚠️ PENDING DATA UPLOAD (1 Feature)

### 20. ⚠️ Import Sold Items as Products
**Status:** Implementation Complete | **Pending Data Upload**  
**Testing Time:** 15 minutes (after file preparation)

**Step 1: Prepare Data File (Time varies)**
1. Prepare a CSV or Excel file with 50,000 sold items
2. File should include columns:
   - Product Name
   - Artist
   - Category
   - Sub Category
   - Price
   - SKU (optional)
   - Other product details
3. Save file in CSV or Excel format

**Step 2: Access Import Page (1 minute)**
1. Navigate to: `https://playlist.nivessa.com/products/import-sold-items`
2. Or go to Products > Import Sold Items as Products
3. **Expected Result:**
   - Page shows import statistics
   - Upload form is available
   - Instructions are displayed

**Step 3: Upload and Import (10 minutes)**
1. Click **"Choose File"** or **"Browse"** button
2. Select your prepared CSV/Excel file
3. Review import options (if available)
4. Click **"Import"** or **"Upload"** button
5. **Expected Result:**
   - Progress indicator shows import progress
   - System processes the file
   - Duplicate detection runs (by SKU or artist)
   - Unique products are created
6. Wait for import to complete
7. **Expected Result:**
   - Success message: "X products imported successfully"
   - Summary of imported vs skipped (duplicates)

**Step 4: Verify Imported Products (4 minutes)**
1. Go to Products list: `https://playlist.nivessa.com/products`
2. Search for products that were in your import file
3. **Verify:**
   - Products appear in the list
   - Product details are correct
   - Categories are assigned (if included in file)
4. Test autocomplete in "Add Purchase":
   - Go to Purchases > Add Purchase
   - Start typing a product name from imported items
   - **Expected Result:** Product appears in autocomplete

**What Was Fixed:** Import functionality ready to process 50,000 sold items with duplicate detection

**Status:** Feature is ready - just needs the data file to be uploaded

---

### 18. ✅ Uncategorized Items Management
**Status:** Complete  
**Testing Time:** 8 minutes

**Step 1: View Uncategorized Products (2 minutes)**
1. Navigate to: `https://playlist.nivessa.com/products`
2. In the filters section, find **"Show Uncategorized Only"** checkbox
3. **Check** the checkbox
4. **Expected Result:**
   - Table refreshes to show only products without categories
   - "Bulk Update Categories" button appears
   - "Export Uncategorized" button appears
5. Review the list of uncategorized products

**Step 2: Export Uncategorized Products (1 minute)**
1. With "Show Uncategorized Only" checked, click **"Export Uncategorized"** button
2. **Expected Result:** CSV file downloads with all uncategorized products
3. Open the file to review products that need categorization

**Step 3: Bulk Update All Visible Products (3 minutes)**
1. With "Show Uncategorized Only" checked, click **"Bulk Update Categories"** button
2. **Expected Result:** Modal opens
3. **Check** "Update all visible uncategorized products" (should be checked by default)
4. Select a **Category** from dropdown
5. (Optional) Select a **Subcategory** from dropdown
6. Review the count: "This will update all X visible uncategorized products"
7. Click **"Update Categories"** button
8. Confirm the action
9. **Expected Result:**
   - Success message: "Successfully updated X products"
   - Table refreshes
   - Products now have categories assigned
10. **Verify:** Uncheck "Show Uncategorized Only" - those products should no longer appear in uncategorized list

**Step 4: Bulk Update Selected Products Only (2 minutes)**
1. With "Show Uncategorized Only" checked, select specific products using checkboxes
2. Click **"Bulk Update Categories"** button
3. **Uncheck** "Update all visible uncategorized products"
4. **Expected Result:**
   - Shows: "X product(s) selected"
   - Note changes to: "This will update only selected products"
5. Select a **Category** and **Subcategory**
6. Click **"Update Categories"**
7. **Expected Result:** Only selected products are updated
8. **Verify:** Selected products have categories, unselected ones remain uncategorized

**What Was Fixed:** Complete bulk update system for uncategorized products with selection options

---

### 19. ✅ Mass Add Tool Improvements
**Status:** Complete  
**Testing Time:** 10 minutes

**How to Test:**
1. Navigate to: `https://playlist.nivessa.com/product/mass-create`
2. You'll see a **"Bulk Product Entry"** section at the top with a large textarea

**Test Format 1: Simple Dash Format (2 minutes)**
1. In the textarea, paste:
   ```
   Abbey Road - The Beatles
   Sticky Fingers - Rolling Stones
   Dark Side of the Moon - Pink Floyd
   ```
2. Click **"Preview Parsed Data"** button
3. **Expected Result:** 
   - Preview shows 3 products detected
   - Table shows: Name, Artist, Category (empty), SKU (empty), Price (empty)
4. Click **"Auto-Format"** button
5. **Expected Result:** Text is reformatted to pipe-delimited format

**Test Format 2: Pipe-Delimited Format (2 minutes)**
1. Clear the textarea
2. Paste:
   ```
   Abbey Road | The Beatles | Rock | SKU001 | 29.99 | A-12 | Warehouse A
   Sticky Fingers | Rolling Stones | Rock | SKU002 | 25.99 | B-5 | Warehouse B
   ```
3. Click **"Preview Parsed Data"**
4. **Expected Result:** 
   - Preview shows 2 products
   - All fields parsed: Name, Artist, Category, SKU, Price, Bin, Location

**Test Format 3: CSV Format (2 minutes)**
1. Clear the textarea
2. Paste:
   ```
   Abbey Road,The Beatles,Rock,SKU001,29.99,A-12,Warehouse A
   Sticky Fingers,Rolling Stones,Rock,SKU002,25.99,B-5,Warehouse B
   ```
3. Click **"Preview Parsed Data"**
4. **Expected Result:** Products parsed correctly from CSV format

**Test Format 4: Real-time Preview (1 minute)**
1. Type or paste products in the textarea
2. **Expected Result:** 
   - As you type (after 500ms delay), preview appears automatically
   - Shows count: "Preview: X products detected"
   - Table shows parsed data

**Test Format 5: Parse & Add Products (3 minutes)**
1. Paste multiple products in any format
2. Click **"Parse & Add Products"** button
3. **Expected Result:**
   - Status shows: "Adding X products..."
   - Products are added to the table below one by one
   - Progress updates: "Adding 1/5 products..."
4. Wait for completion
5. **Expected Result:**
   - Success message: "Added X products from bulk text"
   - Textarea is cleared
   - Products appear in the table with all fields filled
6. Review the products in the table
7. Click **"Save All Products"** to save them
8. **Verify:** Products are saved to database

**What Was Fixed:** Smart text parsing, real-time preview, auto-formatting, multiple format support

---

## ❌ DEFERRED (1 Feature)

### 21. ❌ AI-Powered Product Photo Fetching
**Status:** Deferred (Per Client Request)

**What Was Requested:**
- AI-powered photo fetching from web for products without photos
- 30,000 items in database need photos

**Status:** Not implemented - deferred per client request. Can be implemented in future phase if needed.

**Testing:** N/A - Feature not implemented

---

## 📋 Quick Testing Reference Table

| # | Feature | Status | Testing Time | URL/Location |
|---|---------|--------|--------------|--------------|
| 1 | Items Report Performance | ✅ Complete | 5 min | `/reports/items-report` |
| 2 | Timezone Update to PST | ✅ Complete | 3 min | `/business/settings` (Business tab) |
| 3 | POS Discount/Price Alteration | ✅ Complete | 5 min | `/pos/create` |
| 4 | Employee Discount (20%) | ✅ Complete | 8 min | `/contacts` + `/pos/create` |
| 5 | Plastic Bag Charge + Tax | ✅ Complete | 7 min | `/business/settings` (POS Settings) + `/pos/create` |
| 6 | POS Artist Autocomplete | ✅ Complete | 3 min | `/pos/create` |
| 7 | Customer Account Lookup | ✅ Complete | 5 min | `/pos/create` |
| 8 | Bin Positions | ✅ Complete | 6 min | `/products` (edit) + barcode print |
| 9 | Export Manual Products | ✅ Complete | 3 min | `/pos/create` (button) |
| 10 | Remove eBay/Discogs Suggestions | ✅ Complete | 2 min | `/product/mass-create` |
| 11 | Listing Location Field | ✅ Complete | 3 min | `/products` (edit) |
| 12 | Loyalty Program Foundation | ✅ Complete | 4 min | `/pos/create` |
| 13 | Gift Cards Management | ✅ Complete | 10 min | `/gift-cards` + `/pos/create` |
| 14 | Clover POS Auto-Amount | ✅ Complete | 5 min* | `/business/settings` (Integrations) + `/pos/create` |
| 15 | eBay Listing Integration | ✅ Complete | 8 min* | `/business/settings` (Integrations) + `/products` |
| 16 | Discogs Listing Integration | ✅ Complete | 8 min* | `/business/settings` (Integrations) + `/products` |
| 17 | Streetpulse Connection | ✅ Complete | 10 min* | `/business/settings` (Integrations) |
| 18 | Uncategorized Items Bulk Update | ✅ Complete | 8 min | `/products` (filter + bulk update) |
| 19 | Mass Add Tool Improvements | ✅ Complete | 10 min | `/product/mass-create` |
| 20 | Import Sold Items | ✅ Complete | 15 min** | `/products/import-sold-items` |
| 21 | AI Photo Fetching | ❌ Deferred | N/A | Not implemented |

**\* = After API configuration (additional 10-15 min setup time)**  
**\*\* = After file preparation**

---

## ⏱️ Testing Time Estimates

### Complete Testing Schedule

**Phase 1: Core Features Testing (1.5 hours)**
- Items Report: 5 min
- Timezone: 3 min
- POS Discount: 5 min
- Employee Discount: 8 min
- Plastic Bag Charge: 7 min
- Artist Autocomplete: 3 min
- Customer Account: 5 min
- Bin Positions: 6 min
- Export Manual: 3 min
- Remove Suggestions: 2 min
- Listing Location: 3 min
- Loyalty Program: 4 min
- Gift Cards: 10 min
- Uncategorized Bulk Update: 8 min
- Mass Add Improvements: 10 min
**Total: ~82 minutes (1 hour 22 minutes)**

**Phase 2: API Integration Testing (After Configuration)**
- Clover POS: 5 min (after 10 min setup)
- eBay Listing: 8 min (after 15 min setup)
- Discogs Listing: 8 min (after 5 min setup)
- Streetpulse: 10 min (after 10 min setup)
**Total: ~31 minutes testing + 40 minutes setup = 71 minutes**

**Phase 3: Data Import Testing**
- Import Sold Items: 15 min (after file preparation)
**Total: 15 minutes**

**Grand Total Testing Time:**
- **Core Features:** ~1.5 hours
- **API Features:** ~1.5 hours (including setup)
- **Data Import:** 15 minutes
- **Total:** ~3 hours 15 minutes

---

## 🚀 Testing Priority Order

### Priority 1: Core POS Features (Start Here)
**Time:** ~1.5 hours  
**Features:** 1-13, 18-19  
**Why:** These are production-ready and don't require external setup

### Priority 2: API Integrations (After Credentials)
**Time:** ~1.5 hours (including setup)  
**Features:** 14-17  
**Why:** Requires API credentials but fully implemented

### Priority 3: Data Import (When Ready)
**Time:** 15 minutes  
**Features:** 20  
**Why:** Requires data file preparation

---

## 📍 Key URLs

| Feature | URL |
|---------|-----|
| POS Create | `https://playlist.nivessa.com/pos/create` |
| Business Settings | `https://playlist.nivessa.com/business/settings` |
| Products List | `https://playlist.nivessa.com/products` |
| Contacts (Customers) | `https://playlist.nivessa.com/contacts?type=customer` |
| Items Report | `https://playlist.nivessa.com/reports/items-report` |
| Export Manual Products | `https://playlist.nivessa.com/pos/export-manual-products` |
| Mass Add Products | `https://playlist.nivessa.com/product/mass-create` |

---

## 📝 Configuration Guide

### How to Configure API Integrations:

**Step 1:** Navigate to Business Settings
- URL: `https://playlist.nivessa.com/business/settings`
- Click "Integrations" tab in left menu

**Step 2:** Configure Each Integration

**Clover POS:**
- App ID
- App Secret
- Merchant ID
- Environment (Sandbox/Production)
- Access Token (optional)

**eBay:**
- App ID (eBay Application ID)
- Cert ID (eBay Certificate ID)
- Dev ID (eBay Developer ID)
- Access Token (obtained via OAuth)

**Discogs:**
- API Token (Discogs Personal Access Token)

**Streetpulse:**
- API Key
- Endpoint URL
- Username (optional)

**Step 3:** Save and Test
- Click "Update Settings" button
- Use "Test Connection" buttons for each integration
- Verify success messages

---

## ✅ Summary

**Total Features:** 21  
**Fully Complete:** 15 (71%)  
**Complete - Needs API Keys:** 4 (19%)  
**Pending Data Upload:** 1 (5%)  
**Deferred:** 1 (5%)

**Overall Progress:** ✅ **95% Complete**

**Testing Status:**
- ✅ **15 Features:** Ready for immediate testing (no setup required)
- ⚠️ **4 Features:** Ready for testing after API configuration
- ⚠️ **1 Feature:** Ready for testing after data file preparation

**Total Testing Time:** ~3 hours 15 minutes
- Core Features: ~1.5 hours
- API Features: ~1.5 hours (including setup)
- Data Import: 15 minutes

**Recommended Testing Order:**
1. **Start with Core Features** (1-13, 18-19) - No setup needed
2. **Configure API Integrations** (14-17) - Then test
3. **Import Data** (20) - When file is ready

**Production Readiness:**
- ✅ All core POS features are complete and ready for production use
- ✅ Staff can start using completed features immediately
- ⚠️ API features require credentials before use
- ⚠️ Data import requires file preparation

---

**Report Generated:** January 13, 2026  
**Purpose:** Complete testing guide with step-by-step instructions and time estimates  
**Status:** Ready for Testing

