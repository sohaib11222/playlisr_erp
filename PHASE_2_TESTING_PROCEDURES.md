# Phase 2 Testing Procedures

## Overview
This document provides detailed step-by-step testing procedures for Phase 2 features that have been implemented.

---

## 1. Customer Preorder Tracking System

### Objective
Verify that the preorder management system works correctly for tracking customer preorders, including creation, viewing, editing, and fulfillment.

### Prerequisites
- Access to Preorders module (`/preorders`)
- Access to POS screen (`/pos/create`)
- At least one customer and one product in the system
- Permission to create/edit/delete preorders

### Test Steps

#### Part A: Create Preorder

1. **Navigate to Preorders**
   - Go to `/preorders` (or find Preorders in the menu)
   - Verify the preorders listing page loads

2. **Create New Preorder**
   - Click "Add Preorder" button
   - Fill in the form:
     - **Customer:** Select a customer from dropdown
     - **Product:** Select a product
     - **Variation/SKU:** (Optional) Select specific variation if product has variations
     - **Quantity:** Enter quantity (e.g., 2)
     - **Order Date:** Select today's date
     - **Expected Date:** (Optional) Select expected arrival date
     - **Notes:** (Optional) Add any notes
   - Click "Save"

3. **Verify Preorder Created**
   - Check that you're redirected to preorders list
   - Verify the new preorder appears in the table
   - Check that status shows "Pending"
   - Verify all entered information is displayed correctly

#### Part B: View Preorder Details

4. **View Preorder**
   - Click the eye icon (View) on a preorder row
   - Verify preorder details modal/page opens
   - Check that all information is displayed:
     - Customer name
     - Product name
     - SKU/Variation
     - Quantity
     - Order date
     - Expected date
     - Status
     - Notes
     - Created by
     - Created at

#### Part C: Edit Preorder

5. **Edit Pending Preorder**
   - Click the edit icon on a pending preorder
   - Modify some fields:
     - Change quantity
     - Update expected date
     - Add/modify notes
   - Click "Update"
   - Verify changes are saved

6. **Verify Edit Restrictions**
   - Try to edit a fulfilled preorder (if exists)
   - Verify that only pending preorders can be edited
   - Check error message if trying to edit non-pending preorder

#### Part D: Fulfill Preorder

7. **Mark Preorder as Fulfilled**
   - Click "Fulfill" button on a pending preorder
   - Confirm the action in the popup
   - Verify status changes to "Fulfilled"
   - Verify preorder can no longer be edited or deleted

#### Part E: Delete Preorder

8. **Delete Pending Preorder**
   - Click delete icon on a pending preorder
   - Confirm deletion
   - Verify preorder is removed from list

9. **Verify Delete Restrictions**
   - Try to delete a fulfilled preorder
   - Verify that only pending preorders can be deleted

#### Part F: Filter and Search

10. **Filter by Status**
    - Use the status filter dropdown
    - Select "Pending"
    - Verify only pending preorders are shown
    - Select "Fulfilled"
    - Verify only fulfilled preorders are shown
    - Select "All Statuses"
    - Verify all preorders are shown

#### Part G: POS Integration

11. **View Preorders in POS**
    - Go to POS (`/pos/create`)
    - Select a customer who has pending preorders
    - Click on customer name or "View Details" button
    - Verify customer account modal opens
    - Check "Pending Preorders" section
    - Verify preorders are listed with:
      - Product name (with artist if available)
      - SKU
      - Quantity
      - Order date
      - Expected date

12. **Verify Preorder Count**
    - Check that preorder count badge shows correct number
    - Verify "No pending preorders" message if customer has none

### Expected Results
- Preorders can be created, viewed, edited, and deleted
- Only pending preorders can be edited/deleted
- Preorders can be marked as fulfilled
- Preorders appear in customer account modal in POS
- Status filtering works correctly
- All data displays correctly in all views

### Edge Cases to Test
- Create preorder without expected date
- Create preorder with past order date
- Create preorder for product with no variations
- Create preorder for product with multiple variations
- Customer with many preorders (pagination)
- Preorder with special characters in notes

---

## 2. Customer Account Info in Contact Listing

### Objective
Verify that customer account information (lifetime purchases, loyalty points, tier, preorders) is visible in the customer listing page and profile link works.

### Prerequisites
- Access to Customers page (`/contacts?type=customer`)
- At least one customer with purchase history
- Customer with loyalty points (if loyalty system enabled)
- Customer with pending preorders

### Test Steps

1. **Navigate to Customers**
   - Go to `/contacts?type=customer`
   - Verify customer listing page loads

2. **Verify New Columns**
   - Check table headers include:
     - Lifetime Purchases
     - Loyalty Points
     - Loyalty Tier
     - Preorders
   - Verify columns are visible and properly aligned

3. **Verify Data Display**
   - Check that lifetime purchases show correct amounts
   - Verify loyalty points are displayed
   - Check loyalty tier badges (Bronze/Silver/Gold)
   - Verify preorder count shows as badge (0 if none, number if exists)

4. **Test Profile Link**
   - Click on "Actions" dropdown for a customer
   - Click "View Profile"
   - Verify customer account modal opens
   - Check that modal displays:
     - Account Summary (balance, lifetime purchases, loyalty points, tier)
     - Gift Cards section
     - Pending Preorders section
     - Purchase History section

5. **Verify Profile Data**
   - In the profile modal, verify:
     - Account balance is correct
     - Lifetime purchases match
     - Loyalty points are correct
     - Loyalty tier is displayed
     - Gift cards are listed (if any)
     - Preorders are listed (if any)
     - Purchase history shows all transactions

6. **Test Multiple Customers**
   - Check different customers with varying data:
     - Customer with no purchases
     - Customer with purchases but no preorders
     - Customer with preorders
     - Customer with gift cards
   - Verify all display correctly

### Expected Results
- All new columns are visible in customer listing
- Data displays correctly for all customers
- Profile link opens customer account modal
- Modal shows complete customer information
- All sections (gift cards, preorders, purchases) display correctly

### Edge Cases to Test
- Customer with zero lifetime purchases
- Customer with no loyalty points
- Customer with many preorders
- Customer with many purchases (pagination in modal)
- Customer with expired gift cards (should not show)

---

## 3. Integration Testing

### Objective
Verify that all components work together correctly.

### Test Steps

1. **End-to-End Preorder Flow**
   - Create a preorder from Preorders page
   - View customer in POS
   - Verify preorder appears in customer account modal
   - Fulfill preorder from Preorders page
   - Check POS again - verify preorder no longer appears (fulfilled)

2. **Customer Profile Access**
   - Access customer profile from:
     - Customer listing page (View Profile)
     - POS screen (click customer name)
   - Verify both methods show same data
   - Verify data is up-to-date

3. **Data Consistency**
   - Create a sale for a customer
   - Check customer listing - verify lifetime purchases updated
   - Check customer profile - verify purchase appears in history
   - Create a preorder for same customer
   - Check customer listing - verify preorder count updated
   - Check customer profile - verify preorder appears

### Expected Results
- All components integrate seamlessly
- Data is consistent across all views
- Updates reflect immediately
- No data discrepancies

---

## Test Checklist

Use this checklist to track testing progress:

### Preorder Management
- [ ] Can create preorder
- [ ] Can view preorder details
- [ ] Can edit pending preorder
- [ ] Cannot edit fulfilled preorder
- [ ] Can fulfill preorder
- [ ] Can delete pending preorder
- [ ] Cannot delete fulfilled preorder
- [ ] Status filter works
- [ ] Preorders appear in POS customer modal
- [ ] Preorder count is correct

### Customer Listing Enhancements
- [ ] Lifetime Purchases column visible
- [ ] Loyalty Points column visible
- [ ] Loyalty Tier column visible
- [ ] Preorders column visible
- [ ] Data displays correctly
- [ ] Profile link works
- [ ] Profile modal shows all data
- [ ] Gift cards display in profile
- [ ] Preorders display in profile
- [ ] Purchase history displays in profile

### Integration
- [ ] Preorder creation updates customer listing
- [ ] Preorder fulfillment updates customer listing
- [ ] Sales update lifetime purchases
- [ ] Data consistent across all views

---

## Reporting Issues

When reporting issues, include:
1. Feature name
2. Test step where issue occurred
3. Expected vs actual behavior
4. Browser and version
5. Screenshots if applicable
6. Browser console errors (if any)
7. Steps to reproduce

---

## Sign-off

**Tester Name:** _________________  
**Date:** _________________  
**Status:** ☐ Passed  ☐ Failed  ☐ Needs Retest  
**Notes:** _________________________________
