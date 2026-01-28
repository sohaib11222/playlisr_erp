# Client Requirements - Implementation Plan

**Date:** January 21, 2026  
**Status:** Planning Phase

---

## Overview

This document outlines all client requirements for the Playlist ERP POS system. Each requirement includes implementation details, estimated complexity, and dependencies.

---

## Requirements List

### 1. ✅ Fix Column Alignment After Creating New Customer

**Priority:** High  
**Complexity:** Low  
**Status:** Pending

**Description:**
After creating a new customer in POS checkout, columns in the customer account info panel should align correctly.

**Details:**
- Issue occurs when customer account info panel displays after new customer creation
- Columns may misalign due to dynamic content loading
- Need to ensure proper CSS/layout refresh after customer creation

**Implementation:**
- Review customer account info panel layout after customer creation
- Add CSS refresh or layout recalculation after new customer is added
- Test column alignment in customer account display

**Files to Modify:**
- `app/resources/views/sale_pos/partials/pos_form.blade.php`
- `app/public/js/pos.js` (customer creation callback)

---

### 2. Automatically Show Sales Tax on All Purchases (Unless Product is Tax Exempt)

**Priority:** High  
**Complexity:** Medium  
**Status:** Pending

**Description:**
Sales tax should automatically be applied to all purchases unless the product is specifically marked as sales tax exempt.

**Details:**
- Currently, sales tax may need manual application
- Need to check if products have a tax exemption flag
- Automatically apply default tax rate unless product is exempt

**Implementation:**
- Check if `products` table has `tax_exempt` or similar field
- If not, add migration to add tax exemption field
- Modify POS product addition logic to automatically apply tax
- Check product tax exemption status before applying tax
- Use default business tax rate if product is not exempt

**Files to Modify:**
- `app/database/migrations/xxxx_add_tax_exempt_to_products_table.php` (if needed)
- `app/app/Http/Controllers/SellPosController.php`
- `app/public/js/pos.js` (product addition logic)
- `app/app/Models/Product.php`

**Database Changes:**
- Add `tax_exempt` boolean field to `products` table (if not exists)

---

### 3. Rename "Plastic Bag" to "Bag Fee"

**Priority:** Medium  
**Complexity:** Low  
**Status:** Pending

**Description:**
Rename all references to "Plastic Bag" to "Bag Fee" throughout the system (California doesn't allow plastic bags anymore).

**Details:**
- Update all UI text, labels, and messages
- Update database field names/comments if applicable
- Maintain functionality, only change terminology

**Implementation:**
- Search and replace "Plastic Bag" with "Bag Fee" in all views
- Update JavaScript strings and messages
- Update database field comments/descriptions
- Update any documentation or help text

**Files to Modify:**
- `app/resources/views/sale_pos/partials/pos_form.blade.php`
- `app/public/js/pos.js`
- Any other views referencing plastic bag

---

### 4. Remove Bag Fee from Sales Tax Calculation

**Priority:** High  
**Complexity:** Medium  
**Status:** Pending

**Description:**
Bag fee should not be included in sales tax calculation. It should be added as a separate line item that is tax-exempt.

**Details:**
- Currently, bag fee may be included in taxable amount
- Need to ensure bag fee is added as a non-taxable line item
- Tax should only apply to product purchases, not bag fee

**Implementation:**
- Review how bag fee is currently added to transactions
- Ensure bag fee line item is marked as tax-exempt
- Modify tax calculation to exclude bag fee amount
- Test that tax is calculated correctly with and without bag fee

**Files to Modify:**
- `app/app/Http/Controllers/SellPosController.php` (transaction creation)
- `app/public/js/pos.js` (bag fee addition and tax calculation)
- `app/app/Utils/TransactionUtil.php` (if tax calculation is centralized)

---

### 5. Import 50,000 Sold Items to Products Database

**Priority:** Medium  
**Complexity:** High  
**Status:** Pending

**Description:**
Import 50,000 previously sold items into the products database so they appear in autocomplete when adding purchases.

**Details:**
- Client will provide a file with 50,000 sold items
- Items should be imported into products table
- Should be searchable via autocomplete in "Add Purchase" flow
- Need to handle duplicates and data validation

**Implementation:**
- Create import functionality for bulk product import
- Support CSV/Excel file format
- Validate and clean data before import
- Handle duplicates (skip or update existing)
- Map fields from import file to product fields
- Show import progress and results
- Allow preview before final import

**Files to Create/Modify:**
- `app/app/Http/Controllers/ProductController.php` (import method)
- `app/resources/views/product/import_sold_items.blade.php` (if not exists)
- `app/app/Imports/SoldItemsImport.php` (Laravel Excel import class)
- `app/database/migrations/xxxx_add_import_source_to_products_table.php` (track imported items)

**Data Requirements:**
- Need to know file format and field mapping
- Required fields: product name, SKU, price, etc.
- Optional fields: category, artist, etc.

---

### 6. Enable Customer Preorder Tracking

**Priority:** High  
**Complexity:** High  
**Status:** Pending

**Description:**
Add preorder tracking functionality to customer profiles. Currently tracked in a spreadsheet. Need to ensure preorders don't get put in bins when AMS orders arrive.

**Details:**
- Customers can preorder items
- Preorders should be visible in customer profile
- When AMS orders arrive, system should flag items that are preordered
- Need to prevent preordered items from being put in bins

**Implementation:**
- Create `preorders` table with fields:
  - `id`, `business_id`, `contact_id`, `product_id`, `variation_id`
  - `quantity`, `status` (pending, fulfilled, cancelled)
  - `order_date`, `expected_date`, `notes`
  - `created_by`, `created_at`, `updated_at`
- Add preorder management UI in customer profile
- Add preorder section in POS (when viewing customer)
- Create preorder fulfillment workflow
- Add alerts/notifications when preordered items arrive
- Integration with purchase/stock receiving to flag preordered items

**Files to Create:**
- `app/database/migrations/xxxx_create_preorders_table.php`
- `app/app/Preorder.php` (Model)
- `app/app/Http/Controllers/PreorderController.php`
- `app/resources/views/preorder/index.blade.php`
- `app/resources/views/preorder/create.blade.php`
- `app/resources/views/sale_pos/partials/customer_preorders.blade.php`

**Files to Modify:**
- `app/app/Http/Controllers/SellPosController.php` (show preorders in customer modal)
- `app/app/Http/Controllers/PurchaseController.php` (flag preordered items on receipt)
- `app/resources/views/sale_pos/partials/customer_account_modal.blade.php`

**Business Logic:**
- When receiving stock, check if item is preordered
- Show alert/flag for preordered items
- Allow marking preorder as fulfilled
- Track preorder status and history

---

### 7. Ensure Deleting Users Doesn't Delete Their Data

**Priority:** Medium  
**Complexity:** Low  
**Status:** Pending

**Description:**
When deleting a user from the system, all data they created (products, transactions, etc.) should remain intact. Currently uncertain if deletion removes user's data.

**Details:**
- Need to verify current behavior
- Ensure soft deletes or foreign key constraints preserve data
- Products, transactions, and other records should not be deleted when user is deleted
- May need to set `created_by` to NULL or system user instead

**Implementation:**
- Review user deletion logic
- Check database foreign key constraints
- Implement soft delete for users (if not already)
- Update `created_by` references to preserve data
- Add confirmation message explaining data preservation
- Test deletion to verify data remains

**Files to Modify:**
- `app/app/Http/Controllers/ManageUserController.php` (user deletion)
- `app/app/User.php` (soft deletes if needed)
- Database foreign key constraints review

**Testing:**
- Create test user
- Create products/transactions as test user
- Delete test user
- Verify all data remains accessible

---

### 8. Remove "Add Employee Discount" Checkbox When Creating New Customer from POS

**Priority:** Low  
**Complexity:** Low  
**Status:** Pending

**Description:**
When creating a new customer from POS checkout, the "Add Employee Discount" checkbox should not be shown or should be removed.

**Details:**
- Employee discount checkbox appears in customer creation flow
- Should only be available for existing employee customers, not during creation
- Remove from new customer creation modal/form

**Implementation:**
- Find customer creation modal/form in POS
- Remove employee discount checkbox from creation form
- Ensure checkbox only appears for existing employee customers (already implemented)

**Files to Modify:**
- Customer creation modal/view (need to locate)
- `app/public/js/pos.js` (if checkbox is added via JS)

---

### 9. Connect to Mongo Database to Sync Customer Points

**Priority:** High  
**Complexity:** High  
**Status:** Pending

**Description:**
Connect to MongoDB database (website database) to read customer data and update their loyalty points whenever a purchase is made in POS.

**Details:**
- Website uses MongoDB for customer data
- POS system uses MySQL
- Need to sync loyalty points between systems
- When purchase is made in POS, update points in MongoDB
- May need to read customer data from MongoDB when customer is selected

**Implementation:**
- Install MongoDB PHP driver/package
- Configure MongoDB connection in Laravel
- Create service class for MongoDB operations
- Create sync method to update customer points in MongoDB after POS purchase
- Optionally: Read customer data from MongoDB when customer is selected
- Handle connection errors gracefully
- Add logging for sync operations

**Files to Create:**
- `app/config/mongodb.php` (MongoDB configuration)
- `app/app/Services/MongoDbService.php` (MongoDB service class)
- `app/app/Http/Controllers/MongoSyncController.php` (optional)

**Files to Modify:**
- `app/app/Http/Controllers/SellPosController.php` (sync points after purchase)
- `app/config/database.php` (add MongoDB connection)
- `composer.json` (add MongoDB package)

**Configuration Required:**
- MongoDB connection string
- Database name
- Collection names
- Authentication credentials

**Dependencies:**
- MongoDB server accessible from application
- MongoDB PHP driver installed
- Network access to MongoDB server

---

### 10. ✅ Fix Broken StreetPulse Connection

**Priority:** High  
**Complexity:** High  
**Status:** ✅ Complete

**Description:**
StreetPulse connection was broken after VPS migration. Need to restore daily sales data reporting.

**Implementation Status:**
- ✅ Replaced API-based integration with FTP-based system
- ✅ Implemented SPULSE02 file format
- ✅ Added automatic daily uploads (2:00 AM)
- ✅ Added manual upload functionality
- ✅ Configured FTP with primary/backup servers

**Configuration Required:**
- StreetPulse Store Acronym (3-4 characters) - Must be configured in Business Settings

**See:** `app/STREETPULSE_SETUP_INSTRUCTIONS.md` for setup details

---

### 11. Option to Add Products to eBay/Discogs When Listing from POS

**Priority:** Medium  
**Complexity:** High  
**Status:** Pending

**Description:**
Add functionality to list products to eBay or Discogs directly from POS. Also need a location field that's separate from Hollywood/Pico business locations.

**Details:**
- When listing products from POS, should have option to list to eBay or Discogs
- Need separate "Listing Location" field (different from business locations)
- Should integrate with existing eBay/Discogs API settings
- May need to create listing draft or directly publish

**Implementation:**
- Add "Listing Location" field to products (separate from `business_locations`)
- Create listing location management (similar to business locations but separate)
- Add "List to eBay" and "List to Discogs" buttons/options in POS
- Create listing modal/form with product details pre-filled
- Integrate with eBay API (using existing credentials)
- Integrate with Discogs API (using existing credentials)
- Store listing IDs and status in database
- Show listing status in product views

**Files to Create:**
- `app/database/migrations/xxxx_add_listing_location_to_products_table.php`
- `app/database/migrations/xxxx_create_listing_locations_table.php` (if separate table needed)
- `app/app/Http/Controllers/ListingController.php`
- `app/resources/views/listing/ebay.blade.php`
- `app/resources/views/listing/discogs.blade.php`
- `app/resources/views/sale_pos/partials/list_to_marketplace.blade.php`

**Files to Modify:**
- `app/app/Http/Controllers/SellPosController.php` (add listing options)
- `app/resources/views/sale_pos/create.blade.php` (add listing buttons)
- `app/app/Models/Product.php` (add listing location relationship)
- `app/app/Services/EbayService.php` (if exists, enhance)
- `app/app/Services/DiscogsService.php` (if exists, enhance)

**Database Changes:**
- Add `listing_location_id` to `products` table
- Create `listing_locations` table (if separate from business_locations)
- Add `ebay_listing_id`, `discogs_listing_id`, `listing_status` fields to products

**API Integration:**
- Use existing eBay API credentials from settings
- Use existing Discogs API credentials from settings
- Handle API rate limits and errors
- Support draft and published listings

---

## Implementation Priority

### Phase 1: Critical Fixes (High Priority)
1. ✅ StreetPulse Connection (Complete)
2. Column Alignment After New Customer
3. Automatic Sales Tax (with tax exemption)
4. Remove Bag Fee from Tax Calculation
5. Remove Employee Discount Checkbox from New Customer Creation

### Phase 2: Core Features (High Priority)
6. Customer Preorder Tracking
7. MongoDB Customer Points Sync
8. Bag Fee Rename

### Phase 3: Enhancements (Medium Priority)
9. Import 50,000 Sold Items
10. eBay/Discogs Listing from POS
11. User Deletion Data Preservation

---

## Dependencies & Prerequisites

### External Services
- **MongoDB:** Connection details, database name, collection structure
- **eBay API:** Existing credentials (check if already configured)
- **Discogs API:** Existing credentials (check if already configured)
- **StreetPulse:** Store Acronym (already documented)

### Data Requirements
- **50,000 Sold Items:** File format, field mapping, sample data
- **Preorder Data:** Current spreadsheet structure (if available)

### Technical Requirements
- MongoDB PHP driver installation
- Network access to MongoDB server
- API credentials for eBay/Discogs

---

## Questions for Client

1. **Sales Tax Exemption:**
   - Do products currently have a tax exemption field, or do we need to add it?
   - What is the default tax rate to apply?

2. **50,000 Items Import:**
   - What file format will be provided? (CSV, Excel, etc.)
   - What fields are available in the file?
   - Should we skip duplicates or update existing products?

3. **Preorder Tracking:**
   - Can we see a sample of the current spreadsheet?
   - What fields are currently tracked?
   - How should preorder fulfillment workflow work?

4. **MongoDB Integration:**
   - MongoDB connection string and credentials
   - Database name and collection names
   - Customer data structure in MongoDB
   - Should we also read customer data from MongoDB, or just write points?

5. **Listing Locations:**
   - What listing locations are needed? (e.g., "Warehouse A", "Storage B", etc.)
   - Should this be a separate management interface or simple dropdown?

6. **eBay/Discogs Listing:**
   - Should listings be created immediately or saved as drafts?
   - What product information should be included in listings?
   - Any specific listing requirements or templates?

---

## Estimated Timeline

### Phase 1 (Critical Fixes): 1-2 weeks
- Column alignment: 2 hours
- Automatic sales tax: 1 day
- Bag fee tax exclusion: 1 day
- Employee discount checkbox: 1 hour
- **Total:** ~3-4 days

### Phase 2 (Core Features): 2-3 weeks
- Preorder tracking: 1 week
- MongoDB sync: 3-5 days
- Bag fee rename: 2 hours
- **Total:** ~2 weeks

### Phase 3 (Enhancements): 2-3 weeks
- Import 50K items: 1 week
- eBay/Discogs listing: 1 week
- User deletion fix: 1 day
- **Total:** ~2 weeks

**Overall Estimated Timeline:** 5-8 weeks (depending on client feedback and testing)

---

## Notes

- StreetPulse integration is complete and ready for configuration
- Some requirements need client input before implementation can begin
- MongoDB integration may require server configuration and network access
- Preorder tracking is a significant feature that will require careful design
- Import functionality exists but may need enhancement for 50K items

---

## Next Steps

1. Review this plan with client
2. Get answers to questions listed above
3. Prioritize requirements based on business needs
4. Begin Phase 1 implementation
5. Schedule regular updates and testing sessions
