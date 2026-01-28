# Comprehensive Testing Documentation

## Overview
This document provides complete testing procedures for all implemented features in the Playlist ERP POS system.

---

## Table of Contents

1. [Phase 1: Critical Fixes](#phase-1-critical-fixes)
2. [Phase 2: Core Features](#phase-2-core-features)
3. [Loyalty System](#loyalty-system)
4. [Clover Customer Import](#clover-customer-import)
5. [Customer Account Management](#customer-account-management)

---

## Phase 1: Critical Fixes

### 1. Column Alignment After New Customer Creation

**Objective:** Verify POS layout remains aligned after creating a new customer.

**Prerequisites:**
- Access to POS screen (`/pos/create`)
- Permission to create customers

**Test Steps:**
1. Navigate to `/pos/create`
2. Click the "+" icon next to customer search field
3. Fill in customer details:
   - Name: "Test Customer"
   - Email: "test@example.com"
   - Phone: "1234567890"
4. Click "Save"
5. **Verification:**
   - ✅ Customer is automatically selected
   - ✅ Customer account panel displays correctly
   - ✅ All columns are properly aligned
   - ✅ No overlapping elements
   - ✅ Product search area remains functional

**Expected Result:** Layout remains stable and properly aligned.

---

### 2. Automatic Sales Tax Application

**Objective:** Verify sales tax is automatically applied unless product is tax-exempt.

**Prerequisites:**
- Default sales tax configured in Business Settings
- At least one taxable product
- At least one tax-exempt product

**Test Steps:**

#### Part A: Configure Default Tax
1. Go to `Business Settings` > `Sales` tab
2. Set `Default Sales Tax` (e.g., "CA Sales Tax @ 8.75%")
3. Save settings

#### Part B: Test Taxable Product
1. Create/edit a product: `/products/create`
2. Ensure product is **NOT** marked as `Tax Exempt`
3. Set selling price: $10.00
4. Save product
5. Go to POS: `/pos/create`
6. Add the product to cart
7. **Verification:**
   - ✅ Tax is automatically applied
   - ✅ Order Tax shows correct amount
   - ✅ Final Total includes tax

#### Part C: Test Tax-Exempt Product
1. Create/edit a product: `/products/create`
2. Check `Tax Exempt` checkbox
3. Set selling price: $10.00
4. Save product
5. Go to POS: `/pos/create`
6. Add the product to cart
7. **Verification:**
   - ✅ No tax is applied
   - ✅ Order Tax shows $0.00 for this product
   - ✅ Final Total does not include tax for this product

#### Part D: Test Mixed Cart
1. Add both taxable and tax-exempt products
2. **Verification:**
   - ✅ Tax only applies to taxable products
   - ✅ Tax-exempt products show "No Tax"
   - ✅ Final total is correct

**Expected Result:** Tax applies automatically to non-exempt products only.

---

### 3. Rename "Plastic Bag" to "Bag Fee"

**Objective:** Verify all references updated throughout system.

**Test Steps:**
1. Go to `Business Settings` > `POS Settings`
2. **Verification:**
   - ✅ Section title: "Shopping Bag / Bag Fee Charge Settings"
   - ✅ Checkbox label: "Enable Shopping Bag Charge"
   - ✅ Price label: "Shopping Bag Charge Price"
3. Go to POS: `/pos/create`
4. Check "Add Shopping Bag Charge"
5. **Verification:**
   - ✅ Line item shows "Bag Fee" (or "Shopping Bag Fee")
   - ✅ No references to "Plastic Bag"

**Expected Result:** All references updated to "Bag Fee".

---

### 4. Remove Bag Fee from Tax Calculation

**Objective:** Verify bag fee is tax-exempt.

**Test Steps:**
1. Configure default sales tax (as in Test 2)
2. Enable bag fee in POS Settings ($0.10)
3. Go to POS: `/pos/create`
4. Add a taxable product ($10.00)
5. Check "Add Shopping Bag Charge"
6. **Verification:**
   - ✅ Bag Fee line item appears
   - ✅ Bag Fee shows "No Tax"
   - ✅ Order Tax only includes tax from product
   - ✅ Final Total = Product + Tax + Bag Fee

**Expected Result:** Bag fee is excluded from tax calculation.

---

### 5. Remove Employee Discount Checkbox from New Customer Creation

**Objective:** Verify checkbox doesn't appear when creating customer from POS.

**Test Steps:**
1. Go to POS: `/pos/create`
2. Click "+" to add new customer
3. **Verification:**
   - ✅ "Employee (20% discount)" checkbox is **NOT** present
   - ✅ Only standard customer fields are shown
4. Create customer and verify it works normally

**Expected Result:** Employee discount checkbox removed from creation modal.

---

## Phase 2: Core Features

### 6. Customer Preorder Tracking

**Objective:** Verify complete preorder management system.

**Prerequisites:**
- Access to Preorders module (`/preorders`)
- At least one customer and product

**Test Steps:**

#### Part A: Create Preorder
1. Navigate to `/preorders`
2. Click "Add Preorder"
3. Fill in:
   - Customer: Select existing customer
   - Product: Select product
   - Quantity: 2
   - Order Date: Today
   - Expected Date: Future date
   - Notes: "Test preorder"
4. Click "Save"
5. **Verification:**
   - ✅ Preorder appears in list
   - ✅ Status: "Pending"
   - ✅ All details correct

#### Part B: View Preorder in POS
1. Go to POS: `/pos/create`
2. Select customer with preorder
3. Click customer name or "View Details"
4. Navigate to "Preorders" section
5. **Verification:**
   - ✅ Preorder listed with details
   - ✅ Product name, SKU, quantity shown
   - ✅ Order date and expected date shown

#### Part C: Fulfill Preorder
1. Go to `/preorders`
2. Click "Fulfill" on a preorder
3. Confirm action
4. **Verification:**
   - ✅ Status changes to "Fulfilled"
   - ✅ Preorder no longer appears in POS (pending list)

**Expected Result:** Complete preorder workflow functions correctly.

---

### 7. Customer Account Info in Contact Listing

**Objective:** Verify customer account information displays in listing.

**Test Steps:**
1. Navigate to `/contacts?type=customer`
2. **Verification:**
   - ✅ Columns visible: Lifetime Purchases, Loyalty Points, Loyalty Tier, Preorders
   - ✅ Data displays correctly for each customer
3. Click "Actions" > "View Profile" for a customer
4. **Verification:**
   - ✅ Modal opens with customer details
   - ✅ Account Summary shows:
     - Balance
     - Lifetime Purchases
     - Loyalty Points
     - Loyalty Tier
   - ✅ Gift Cards section displays
   - ✅ Preorders section displays
   - ✅ Purchase History displays

**Expected Result:** All customer account info accessible from listing.

---

## Loyalty System

### 8. Loyalty Points Calculation

**Objective:** Verify points are calculated and awarded correctly.

**Prerequisites:**
- Reward points enabled in Business Settings
- Loyalty tiers configured
- Customer with assigned tier

**Test Steps:**

#### Part A: Basic Points Calculation
1. Configure reward points:
   - Enable reward points
   - Set: $1 = 1 point (or your setting)
   - Min order: $0
2. Go to POS: `/pos/create`
3. Select a customer
4. Add products totaling $50.00
5. Complete sale
6. **Verification:**
   - ✅ Points calculated correctly (e.g., 50 points for $50)
   - ✅ Points added to customer account
   - ✅ `loyalty_points` field updated
   - ✅ `total_rp` field updated (synced)

#### Part B: Tier Multiplier
1. Create loyalty tier:
   - Name: "Gold"
   - Min Lifetime Purchases: $500
   - Points Multiplier: 1.5x
2. Update customer to have $600 lifetime purchases
3. Customer should be in "Gold" tier
4. Make a $100 sale
5. **Verification:**
   - ✅ Base points: 100
   - ✅ With 1.5x multiplier: 150 points awarded
   - ✅ Customer receives bonus points

#### Part C: Tier Upgrade
1. Customer has $400 lifetime purchases (Bronze tier)
2. Make a $150 sale
3. **Verification:**
   - ✅ Lifetime purchases updated to $550
   - ✅ Tier automatically upgraded to Gold (if threshold met)
   - ✅ Points calculated with new tier multiplier

**Expected Result:** Points calculated correctly with tier multipliers applied.

---

### 9. Loyalty Tier Management

**Objective:** Verify tier system works correctly.

**Test Steps:**
1. Go to `/loyalty-tiers`
2. Create tiers:
   - Bronze: $0 minimum, 1.0x multiplier
   - Silver: $200 minimum, 1.25x multiplier
   - Gold: $500 minimum, 1.5x multiplier
3. **Verification:**
   - ✅ Tiers created successfully
   - ✅ Can edit tiers
   - ✅ Can activate/deactivate tiers
4. Test customer tier assignment:
   - Customer with $100 → Bronze
   - Customer with $300 → Silver
   - Customer with $600 → Gold

**Expected Result:** Tier system functions correctly.

---

## Clover Customer Import

### 10. Clover Connection Test

**Objective:** Verify Clover API connection works.

**Prerequisites:**
- Clover API credentials configured
- Public Token, Private Token, Merchant ID entered

**Test Steps:**
1. Go to `Business Settings` > `Integrations`
2. Enter Clover credentials:
   - Public Token
   - Private Token
   - Merchant ID
   - Environment: Production
3. Click "Test Connection"
4. **Verification:**
   - ✅ Connection successful message
   - ✅ Customer count displayed
   - ✅ No error messages

**Expected Result:** Connection test passes.

---

### 11. Import Customers from Clover

**Objective:** Verify customer import functionality.

**Test Steps:**
1. Click "Import Customers from Clover"
2. **Verification:**
   - ✅ Modal opens
   - ✅ Loading indicator shows
   - ✅ Customers list appears
3. Select customers to import (or select all)
4. Click "Import Selected"
5. **Verification:**
   - ✅ Import progress shown
   - ✅ Success message: "Imported X customers, skipped Y duplicates"
   - ✅ Customers appear in `/contacts?type=customer`
   - ✅ Duplicate customers skipped (by email/phone)

**Expected Result:** Customers imported successfully.

---

## Customer Account Management

### 12. Customer Profile View

**Objective:** Verify complete customer profile functionality.

**Test Steps:**
1. Go to POS: `/pos/create`
2. Select a customer
3. Click customer name or "View Details"
4. **Verification - Account Summary:**
   - ✅ Account Balance
   - ✅ Lifetime Purchases
   - ✅ Loyalty Points
   - ✅ Loyalty Tier (with badge)
   - ✅ Last Purchase Date
5. **Verification - Gift Cards:**
   - ✅ Active gift cards listed
   - ✅ Balance shown for each
   - ✅ Expiry dates shown (if applicable)
6. **Verification - Preorders:**
   - ✅ Pending preorders listed
   - ✅ Product details shown
   - ✅ Quantity, dates shown
7. **Verification - Purchase History:**
   - ✅ All purchases listed
   - ✅ Invoice numbers clickable
   - ✅ Dates, totals, status shown
   - ✅ Can view individual transactions

**Expected Result:** Complete customer profile accessible.

---

## Integration Testing

### 13. End-to-End Sale Flow

**Objective:** Verify complete sale process with all features.

**Test Steps:**
1. Go to POS: `/pos/create`
2. Select customer (or create new)
3. **Verification:**
   - ✅ Customer account info displays
   - ✅ Loyalty points shown
   - ✅ Tier displayed
4. Add products:
   - Taxable product
   - Tax-exempt product
   - Bag fee
5. **Verification:**
   - ✅ Tax applied to taxable product only
   - ✅ Bag fee tax-exempt
   - ✅ Totals correct
6. Complete sale
7. **Verification:**
   - ✅ Points awarded
   - ✅ Lifetime purchases updated
   - ✅ Tier upgraded if threshold met
   - ✅ Last purchase date updated
   - ✅ Transaction saved

**Expected Result:** Complete sale flow works with all features.

---

## Test Checklist

Use this checklist to track testing:

### Phase 1
- [ ] Column alignment after customer creation
- [ ] Automatic tax application (taxable products)
- [ ] Tax exemption (tax-exempt products)
- [ ] Bag fee terminology updated
- [ ] Bag fee tax exemption
- [ ] Employee discount checkbox removed

### Phase 2
- [ ] Preorder creation
- [ ] Preorder viewing in POS
- [ ] Preorder fulfillment
- [ ] Customer account columns in listing
- [ ] Customer profile link
- [ ] Customer profile modal

### Loyalty System
- [ ] Points calculation
- [ ] Points awarded on sale
- [ ] Tier multiplier applied
- [ ] Tier upgrade automatic
- [ ] Loyalty points synced with reward points

### Clover Integration
- [ ] Connection test
- [ ] Customer preview
- [ ] Customer import
- [ ] Duplicate handling

### Customer Account
- [ ] Account summary
- [ ] Gift cards display
- [ ] Preorders display
- [ ] Purchase history

---

## Reporting Issues

When reporting issues, include:
1. Feature name
2. Test step where issue occurred
3. Expected vs actual behavior
4. Browser and version
5. Screenshots
6. Browser console errors
7. Steps to reproduce

---

## Sign-off

**Tester Name:** _________________  
**Date:** _________________  
**Status:** ☐ Passed  ☐ Failed  ☐ Needs Retest  
**Notes:** _________________________________

---

**Last Updated:** January 22, 2026
