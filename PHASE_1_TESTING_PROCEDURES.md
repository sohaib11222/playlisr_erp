hese messges get the# Phase 1 Testing Procedures

## Overview
This document provides detailed step-by-step testing procedures for all Phase 1 features that have been implemented.

---

## 1. Column Alignment After New Customer Creation

### Objective
Verify that the customer account info panel displays correctly with proper column alignment after creating a new customer in POS.

### Prerequisites
- Access to POS screen (`/pos/create`)
- Permission to create customers

### Test Steps

1. **Navigate to POS**
   - Go to `/pos/create`
   - Verify the POS screen loads correctly

2. **Open Customer Creation Modal**
   - Click the "+" button next to the customer search field
   - Verify the customer creation modal opens

3. **Create New Customer**
   - Fill in required fields:
     - First Name: "Test"
     - Last Name: "Customer"
     - Mobile: "555-1234"
     - Email: "test@example.com" (optional)
   - Click "Save"

4. **Verify Column Alignment**
   - After customer is created and selected, check the customer account info panel
   - Verify the panel appears below the customer search field
   - Check that the 4 columns (Credit, Gift Cards, Lifetime, Points) are properly aligned
   - Each column should be in a `col-md-3` layout
   - Verify no overlapping or misaligned text

5. **Test Layout Refresh**
   - Create another new customer
   - Verify the layout refreshes correctly without visual glitches
   - Check that columns maintain proper spacing

### Expected Results
- Customer account info panel displays immediately after customer creation
- All 4 columns (Credit, Gift Cards, Lifetime, Points) are properly aligned
- No layout shifts or misalignment issues
- Panel refreshes correctly on subsequent customer creations

### Browser Testing
Test in:
- Chrome (latest)
- Firefox (latest)
- Safari (latest)
- Edge (latest)

---

## 2. Automatic Sales Tax Application

### Objective
Verify that sales tax is automatically applied to products unless they are marked as tax-exempt.

### Prerequisites
- Access to Business Settings
- Access to Product Management
- Access to POS screen
- At least one tax rate configured in the system
- Default sales tax set in Business Settings

### Test Steps

#### Part A: Configure Default Tax

1. **Set Default Sales Tax**
   - Go to Business Settings (`/business/settings`)
   - Navigate to "Sales" tab
   - Find "Default Sales Tax" dropdown
   - Select a tax rate (e.g., "Sales Tax - 8.75%")
   - Save settings

#### Part B: Test Non-Exempt Product

2. **Create/Edit Product Without Tax Exempt**
   - Go to Products (`/products`)
   - Create a new product or edit existing product
   - Fill in required fields:
     - Product Name: "Test Product"
     - SKU: "TEST-001"
     - Category and Subcategory
     - Price information
   - **Do NOT check "Tax Exempt" checkbox**
   - Leave "Tax" field empty (no specific tax rate)
   - Save product

3. **Add Product to POS**
   - Go to POS (`/pos/create`)
   - Search for "Test Product"
   - Add product to cart

4. **Verify Automatic Tax Application**
   - Check the product row in POS table
   - Verify that the "Tax" dropdown is automatically populated with the default sales tax
   - Verify tax amount is calculated and displayed
   - Check that line total includes tax

#### Part C: Test Tax-Exempt Product

5. **Create Tax-Exempt Product**
   - Go to Products (`/products`)
   - Create a new product or edit existing product
   - Fill in required fields
   - **Check "Tax Exempt" checkbox**
   - Leave "Tax" field empty
   - Save product

6. **Add Tax-Exempt Product to POS**
   - Go to POS (`/pos/create`)
   - Search for the tax-exempt product
   - Add product to cart

7. **Verify No Tax Applied**
   - Check the product row in POS table
   - Verify that the "Tax" dropdown is empty (no tax selected)
   - Verify no tax amount is calculated
   - Check that line total does not include tax

#### Part D: Test Product with Specific Tax

8. **Create Product with Specific Tax Rate**
   - Go to Products (`/products`)
   - Create a new product
   - Fill in required fields
   - **Select a specific tax rate** in the "Tax" dropdown (different from default)
   - Do NOT check "Tax Exempt"
   - Save product

9. **Add Product to POS**
   - Go to POS (`/pos/create`)
   - Add the product to cart

10. **Verify Specific Tax Applied**
    - Check that the product uses its specific tax rate (not default)
    - Verify tax calculation is correct

### Expected Results
- Products without tax and not tax-exempt automatically get default sales tax
- Tax-exempt products do not get any tax applied
- Products with specific tax rates use their assigned rate
- Tax calculations are correct in all scenarios

### Edge Cases to Test
- Product with no tax set, not exempt, but no default tax configured → Should show no tax
- Multiple products with different tax scenarios in same transaction
- Location-specific default tax (if implemented)

---

## 3. Rename Plastic Bag to Bag Fee

### Objective
Verify that all references to "Plastic Bag" have been renamed to "Bag Fee" throughout the system.

### Prerequisites
- Access to Business Settings
- Access to POS screen
- Bag fee feature enabled

### Test Steps

1. **Check Business Settings**
   - Go to Business Settings (`/business/settings`)
   - Navigate to "POS Settings" tab
   - Find the bag fee section
   - Verify heading says "Bag Fee Settings:" (not "Plastic Bag")
   - Verify checkbox label says "Enable Bag Fee" (not "Plastic Bag Charge")
   - Verify help text uses "Bag Fee" terminology
   - Verify price label says "Bag Fee Price:" (not "Plastic Bag Price")

2. **Check POS Screen**
   - Go to POS (`/pos/create`)
   - Find the bag fee checkbox
   - Verify label says "Add Bag Fee" (not "Add Plastic Bag Charge")
   - Verify price display shows correct amount

3. **Test Bag Fee Addition**
   - Check the "Add Bag Fee" checkbox
   - Verify a new row appears in the POS table
   - Check the product name column
   - Verify it says "Bag Fee" (not "Plastic Bag")

4. **Check Transaction Receipt**
   - Complete a transaction with bag fee
   - View the receipt/invoice
   - Verify it shows "Bag Fee" (not "Plastic Bag")

5. **Check JavaScript Messages**
   - Open browser console (F12)
   - Add bag fee to transaction
   - Check for any error messages
   - Verify no references to "Plastic Bag" in console logs

### Expected Results
- All UI text uses "Bag Fee" terminology
- No references to "Plastic Bag" in visible text
- Help text and tooltips use "Bag Fee"
- Product name in transaction shows "Bag Fee"

### Areas to Check
- Business Settings page
- POS screen
- Transaction receipts
- Error messages
- Help text and tooltips
- Database field comments (if visible)

---

## 4. Remove Bag Fee from Tax Calculation

### Objective
Verify that bag fee is excluded from sales tax calculation and is tax-exempt.

### Prerequisites
- Access to Business Settings
- Access to POS screen
- Bag fee feature enabled
- Sales tax configured

### Test Steps

1. **Enable Bag Fee**
   - Go to Business Settings (`/business/settings`)
   - Navigate to "POS Settings" tab
   - Enable "Bag Fee"
   - Set bag fee price (e.g., $0.10)
   - Save settings

2. **Configure Sales Tax**
   - Ensure a sales tax rate is configured (e.g., 8.75%)
   - Set as default sales tax if needed

3. **Create Test Transaction**
   - Go to POS (`/pos/create`)
   - Add a product to cart (e.g., $10.00 product)
   - Check "Add Bag Fee" checkbox
   - Verify bag fee row appears

4. **Verify Bag Fee Tax Status**
   - Check the bag fee row in POS table
   - Look at the "Tax" dropdown for bag fee row
   - **Verify it is empty or set to "No Tax"**
   - Bag fee should NOT have any tax applied

5. **Verify Tax Calculation**
   - Check the order totals section
   - Note the "Subtotal" amount
   - Note the "Tax" amount
   - Note the "Total" amount
   - **Verify that tax is only calculated on the product, not on bag fee**

6. **Calculate Expected Values**
   - Product: $10.00
   - Bag Fee: $0.10
   - Tax (8.75% on product only): $0.875 = $0.88
   - **Expected Subtotal (for tax): $10.00** (bag fee excluded from taxable amount)
   - **Expected Tax: $0.88** (only on product)
   - **Expected Total: $10.00 + $0.10 + $0.88 = $10.98**

7. **Verify Final Total**
   - Check that final total matches expected calculation
   - Bag fee should be included in final total
   - But NOT in taxable subtotal

8. **Test with Multiple Products**
   - Add multiple products to cart
   - Add bag fee
   - Verify tax is calculated only on products
   - Bag fee remains tax-exempt

9. **Test Order Tax**
   - If order-level tax is applied
   - Verify bag fee is excluded from order tax calculation
   - Only products should be included in taxable amount

### Expected Results
- Bag fee row shows "No Tax" or empty tax dropdown
- Tax calculation excludes bag fee from taxable amount
- Final total includes bag fee but tax is only on products
- Order tax (if applicable) excludes bag fee

### Calculation Verification
```
Product Price: $10.00
Bag Fee: $0.10
Tax Rate: 8.75%

Taxable Amount: $10.00 (bag fee excluded)
Tax Amount: $10.00 × 8.75% = $0.875 ≈ $0.88
Final Total: $10.00 + $0.10 + $0.88 = $10.98
```

### Edge Cases to Test
- Transaction with only bag fee (no products) → Should show $0.10 total, $0 tax
- Multiple bag fees (if possible) → All should be tax-exempt
- Bag fee with tax-exempt products → Both should be tax-exempt

---

## 5. Remove Employee Discount Checkbox from New Customer Creation

### Objective
Verify that the employee discount checkbox is removed from the new customer creation modal.

### Prerequisites
- Access to POS screen
- Permission to create customers

### Test Steps

1. **Open Customer Creation Modal**
   - Go to POS (`/pos/create`)
   - Click the "+" button next to customer search field
   - Customer creation modal should open

2. **Verify Checkbox Removal**
   - Scroll through the customer creation form
   - Look for any checkbox related to "Employee" or "Employee Discount"
   - **Verify NO employee discount checkbox is present**
   - Check both visible fields and any "More Info" sections

3. **Verify Employee Discount Still Works in POS**
   - Create a customer (regular customer, not employee)
   - Select the customer in POS
   - Verify employee discount checkbox appears in customer account panel (if customer is employee)
   - This confirms the feature still works, just not in creation modal

4. **Test Existing Employee Customer**
   - If an employee customer already exists
   - Select them in POS
   - Verify employee discount checkbox appears in customer account panel
   - Verify it works correctly

5. **Check Form Fields**
   - Verify all other customer fields are present and working
   - Name, email, phone, address fields should all be there
   - Form should save successfully without employee checkbox

### Expected Results
- No employee discount checkbox in customer creation modal
- Customer creation form works normally
- Employee discount feature still works for existing employee customers in POS
- Employee discount checkbox appears in POS customer account panel (not in creation modal)

### Areas to Verify
- Customer creation modal (`/contact/create` or quick add)
- Customer edit form (should still have employee checkbox if needed)
- POS customer account panel (should show checkbox for employees)

---

## General Testing Notes

### Browser Compatibility
Test all features in:
- Chrome (latest version)
- Firefox (latest version)
- Safari (latest version)
- Edge (latest version)

### Mobile Responsiveness
- Test on tablet sizes (768px - 1024px)
- Test on mobile sizes (320px - 767px)
- Verify layouts adapt correctly

### Error Handling
- Test with invalid data
- Test with missing required fields
- Verify error messages are clear and helpful
- Check browser console for JavaScript errors

### Performance
- Test with large number of products
- Test with multiple transactions
- Verify no significant slowdowns

### Data Integrity
- Verify all data saves correctly to database
- Check that migrations run successfully
- Verify no data loss during updates

---

## Test Checklist

Use this checklist to track testing progress:

### Feature 1: Column Alignment
- [ ] Customer account panel displays after new customer creation
- [ ] Columns are properly aligned (4 columns in row)
- [ ] No layout shifts or glitches
- [ ] Works in all major browsers

### Feature 2: Automatic Sales Tax
- [ ] Default tax configured in settings
- [ ] Non-exempt product gets default tax automatically
- [ ] Tax-exempt product gets no tax
- [ ] Product with specific tax uses its own rate
- [ ] Tax calculations are correct

### Feature 3: Bag Fee Rename
- [ ] Business Settings uses "Bag Fee" terminology
- [ ] POS screen uses "Bag Fee" terminology
- [ ] Transaction receipts show "Bag Fee"
- [ ] No "Plastic Bag" references remain

### Feature 4: Bag Fee Tax Exemption
- [ ] Bag fee row shows no tax
- [ ] Tax calculation excludes bag fee
- [ ] Final total includes bag fee
- [ ] Tax amount is correct (only on products)

### Feature 5: Employee Discount Checkbox Removal
- [ ] No checkbox in customer creation modal
- [ ] Customer creation works normally
- [ ] Employee discount still works in POS
- [ ] Existing employee customers work correctly

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
