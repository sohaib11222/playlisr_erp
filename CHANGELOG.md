# Changelog - Recent Updates

## Date: March 19, 2026

### POS & Mass Add UX batch

**Status:** ✅ Complete

**Changes:**
- **POS:** Added read-only Purchase Price column to the left of Selling Price; pre-tax total styled in black with larger text; product table scrolls so totals/actions stay visible without page scroll.
- **Store credit:** "Use Store Credit" no longer opens the payment modal; applied amount is deducted from the customer’s account when the sale is completed with Cash (or when no advance payment line is used).
- **POS & Mass Add:** Category/subcategory search improved with tokenized partial matching (e.g. "used rock" finds "Used Vinyl - Rock").
- **Mass Add:** Purchase Price column moved before Selling Price; new prominent **"Save & send to add purchase"** button that saves products then either posts product IDs to the parent (iframe) or redirects to Add Purchase with `from_products`.
- **Products list:** Default sort changed to newest first (by Updated date desc).
- **Discounts (admin):** Preset selector on create/edit (Senior, Military, Student, Senior Citizens) auto-fills name and sets type to percentage.
- **POS discount modal:** Preset dropdown lists active percentage discounts matching those names; selecting one fills type, amount, and reason.
- **POS rewards/account:** Customer account info and Customer Account modal made responsive (wrapping grid, modal max-width and vertical scroll on small screens).

**Documentation:**
- `TESTING_ERP_CHANGES_2026-03-19.md` – client testing steps for this batch.

---

## Date: January 21, 2026

### 1. StreetPulse FTP Integration

**Status:** ✅ Complete

**Changes:**
- Replaced API-based StreetPulse integration with FTP-based system
- Implemented SPULSE02 file format generation
- Added automatic daily uploads via cron job (runs at 2:00 AM)
- Added manual upload functionality with date selection
- Configured FTP connection with primary/backup server fallback

**Files Modified:**
- `app/app/Services/StreetpulseService.php` - Complete rewrite for FTP
- `app/app/Services/StreetpulseFileGenerator.php` - New service for file generation
- `app/app/Http/Controllers/BusinessController.php` - Updated test and sync methods
- `app/resources/views/business/partials/settings_integrations.blade.php` - Updated UI
- `app/app/Console/Commands/UploadStreetpulseDaily.php` - New daily upload command
- `app/app/Console/Commands/UploadStreetpulseManual.php` - New manual upload command
- `app/app/Console/Kernel.php` - Added cron schedule
- `app/database/migrations/2026_01_21_224112_add_streetpulse_settings_to_business_table.php` - New migration

**Configuration Required:**
- StreetPulse Store Acronym (3-4 characters) - Must be configured in Business Settings > Integrations
- Check Digit Option (default: NOCHECKDIGIT)

**Features:**
- Automatic daily uploads of yesterday's sales data
- Manual upload for specific dates
- File compression for large datasets (>10,000 records)
- Duplicate prevention (tracks last upload date)
- Error handling and logging

---

### 2. Products Page Enhancements

**Status:** ✅ Complete

**Changes:**
- Added Subcategory column to products table
- Enabled multi-select category/subcategory editing for all products (not just uncategorized)
- Made "Bulk Update Categories" button always visible

**Files Modified:**
- `app/resources/views/product/index.blade.php` - Added subcategory column, updated bulk update UI
- `app/app/Http/Controllers/ProductController.php` - Updated DataTable to include subcategory column

**Features:**
- Subcategory now displays as separate column in products list
- Bulk update categories/subcategories for any products (selected or all visible)
- Improved user experience for managing product categories

---

### 3. Customer Purchase History & Loyalty View

**Status:** ✅ Complete

**Changes:**
- Enhanced customer account modal to show full purchase history (all purchases, not just recent 10)
- Made customer name clickable to open purchase history modal
- Added item count and view links to purchase history
- Improved purchase history display with better formatting

**Files Modified:**
- `app/app/Http/Controllers/SellPosController.php` - Updated `getCustomerAccountInfo()` to return all purchases with item details
- `app/resources/views/sale_pos/partials/customer_account_modal.blade.php` - Enhanced modal to show full purchase history
- `app/public/js/pos.js` - Added clickable customer name, updated modal to display all purchases

**Features:**
- Click on customer name in POS to view full purchase history
- View all purchases (not limited to 10)
- See item count per purchase
- Direct link to view each transaction
- Complete loyalty information display
- Gift card information
- Lifetime purchase totals

**How to Use:**
1. Select a customer in POS
2. Click on the customer name (now clickable with info icon)
3. Or click "View Details" button
4. Modal shows complete purchase history, loyalty points, gift cards, and account summary

---

## Technical Notes

### StreetPulse Integration
- FTP credentials are hardcoded (standard StreetPulse credentials)
- Files stored in `storage/app/streetpulse/` with automatic cleanup (7 days)
- Supports both compressed (.gz) and uncompressed files
- Retry logic: tries primary server, then backup server

### Products Enhancements
- Subcategory column uses `c2.name` from database join
- Bulk update works for any products, not just uncategorized
- Maintains backward compatibility with existing functionality

### Customer Purchase History
- All purchases loaded (no pagination limit)
- Purchase history includes item details
- Direct links to transaction view pages
- Real-time calculation of lifetime purchases and loyalty points

---

## Testing Checklist

### StreetPulse
- [ ] Configure StreetPulse acronym in settings
- [ ] Test FTP connection
- [ ] Test manual upload for specific date
- [ ] Verify daily cron job runs at 2:00 AM
- [ ] Check file format matches SPULSE02 specification
- [ ] Verify file upload to StreetPulse servers

### Products
- [ ] Verify subcategory column appears in products table
- [ ] Test bulk category update for selected products
- [ ] Test bulk category update for all visible products
- [ ] Verify subcategory updates correctly

### Customer Purchase History
- [ ] Select customer in POS
- [ ] Click customer name to open modal
- [ ] Verify all purchases are displayed
- [ ] Check purchase details (items, amounts, dates)
- [ ] Verify loyalty points and gift cards display correctly
- [ ] Test "View" links to transaction pages

---

## Migration Required

Run the following migration when ready:
```bash
php artisan migrate
```

This will add:
- `streetpulse_acronym` field to `business` table
- `streetpulse_last_upload_date` field to `business` table
