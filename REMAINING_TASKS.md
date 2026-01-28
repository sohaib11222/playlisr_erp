# Remaining Tasks from Plan

## Overview
This document lists all remaining tasks that have not been completed yet.

---

## ✅ COMPLETED

### Phase 1: Critical Fixes
1. ✅ Column Alignment After New Customer Creation
2. ✅ Automatic Sales Tax Application (with exemption)
3. ✅ Rename Plastic Bag to "Bag Fee"
4. ✅ Remove Bag Fee from Sales Tax Calculation
5. ✅ Remove "Add Employee Discount" Checkbox from New Customer Creation

### Phase 2: Core Features
1. ✅ Customer Preorder Tracking System
2. ✅ Customer Account Info in Contact Listing (with profile link)
3. ✅ Clover Customer Import (just completed)

---

## ⏳ PENDING TASKS

### Phase 2 Remaining

#### 1. MongoDB Customer Points Sync
**Status:** ⏳ Pending  
**Priority:** Medium  
**Description:** Connect to MongoDB database to read customer data from the website and update their loyalty points whenever a purchase is made.

**Requirements:**
- MongoDB connection configuration
- Read customer data from website database
- Sync loyalty points on purchase
- Handle duplicate customers (website vs ERP)

**Files Needed:**
- MongoDB connection service
- Customer sync service
- Update loyalty points on transaction completion
- Configuration in Business Settings

**Estimated Time:** 4-6 hours

---

### Phase 3: Additional Features

#### 2. Import 50,000 Sold Items
**Status:** ⏳ Pending  
**Priority:** Medium  
**Description:** Upload 50,000 items sold in store to the "list products" database so they appear in autotext when adding purchases.

**Requirements:**
- Bulk import functionality
- CSV/Excel file upload
- Parse and import product data
- Add to "list products" table (or products table with flag)
- Handle duplicates
- Progress tracking for large imports

**Files Needed:**
- Import controller
- Import view with file upload
- Parser for CSV/Excel
- Background job for large imports
- Migration if new table needed

**Estimated Time:** 6-8 hours

---

#### 3. eBay/Discogs Listing from POS
**Status:** ⏳ Pending  
**Priority:** Low-Medium  
**Description:** Add option to list products to eBay or Discogs directly from POS, including a separate "location" field (separate from Hollywood or Pico).

**Requirements:**
- Listing modal/form in POS
- Pre-fill product details
- Separate "location" field (e.g., "Warehouse A", "Storage B")
- eBay API integration for listing creation
- Discogs API integration for listing creation
- Store listing IDs and status
- Show listing status in product list

**Files Needed:**
- Listing modal in POS
- eBay listing service (enhance existing)
- Discogs listing service (enhance existing)
- Database fields for listing status and location
- UI for listing management

**Estimated Time:** 8-10 hours

---

#### 4. User Deletion Data Preservation
**Status:** ⏳ Pending  
**Priority:** Low  
**Description:** Ensure that deleting users in ERP does not delete any data input by that user (products, transactions, etc.).

**Requirements:**
- Verify current behavior
- Ensure soft deletes or data preservation
- Update foreign key constraints if needed
- Test deletion scenarios
- Document data preservation

**Files Needed:**
- Review User model and relationships
- Check database foreign keys
- Update constraints if needed
- Add tests

**Estimated Time:** 2-3 hours

---

## Summary

### Completed: 8 tasks ✅
- Phase 1: 5 tasks
- Phase 2: 3 tasks

### Pending: 4 tasks ⏳
- Phase 2: 1 task (MongoDB sync)
- Phase 3: 3 tasks (Import items, eBay/Discogs listing, User deletion)

### Total Estimated Time Remaining: 20-27 hours

---

## Priority Order (Recommended)

1. **MongoDB Customer Points Sync** - Medium priority, important for loyalty program
2. **Import 50,000 Sold Items** - Medium priority, improves autocomplete functionality
3. **eBay/Discogs Listing from POS** - Lower priority, nice-to-have feature
4. **User Deletion Data Preservation** - Low priority, verification task

---

## Notes

- All Phase 1 tasks are complete and tested
- Phase 2.1 (Preorder Tracking) is complete
- Phase 2.2 (Customer Account Info) is complete
- Clover Customer Import is complete
- MongoDB sync requires MongoDB connection details
- Import 50,000 items requires the CSV/Excel file from client
- eBay/Discogs listing requires OAuth implementation for eBay
- User deletion verification is a quick task but important for data integrity
