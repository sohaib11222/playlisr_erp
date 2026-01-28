# Clover Customer Import Integration Plan

## Overview
Import customers from Clover POS to the Playlist ERP system, along with other beneficial data.

## Clover API Credentials Provided

### Pico Store
- **Public Token:** 64d5e239-bcb1-82be-7708-f92e822504f0
- **Merchant ID:** 5482983600020311
- **Private Token:** Required (needs to be obtained from Clover dashboard - click eye icon to reveal)

### Hollywood Store
- **Public Token:** 4087d617-c216-74b5-1a77-4aad5c0c1b3e
- **Merchant ID:** 526494042884
- **Private Token:** Required (needs to be obtained from Clover dashboard - click eye icon to reveal)

**Note:** Private tokens are sensitive and should be stored securely. They are masked in the UI but required for API calls.

## Clover API Information

Based on the Clover API documentation and the provided credentials:

### Authentication
- Clover uses **Ecommerce API Tokens** (Public/Private token pairs)
- Private token is required for API calls
- Tokens are location-specific (each store has its own tokens)
- Permissions must be configured in Clover dashboard

### Required Permissions
The following permissions should be enabled in Clover:
- **Customers:** Read access to customer data
- **Orders:** Read access to order history (beneficial for ERP)
- **Inventory:** Read access (if needed)
- **Payments:** Read access (if needed)

## Implementation Plan

### Phase 1: Clover Service Enhancement

**Files to Modify:**
- `app/app/Services/CloverService.php` - Add customer import methods
- `app/app/Http/Controllers/CloverController.php` (new) - Handle import operations
- `app/resources/views/business/partials/settings_integrations.blade.php` - Add import UI

**New Methods Needed:**
1. `getCustomers($merchant_id, $private_token)` - Fetch customers from Clover
2. `importCustomers($business_id, $location_id)` - Import customers to ERP
3. `syncCustomerData($clover_customer, $business_id)` - Map and sync customer data

### Phase 2: Customer Data Mapping

**Clover Customer Fields → ERP Contact Fields:**
- `id` → Store as external reference
- `firstName` + `lastName` → `first_name`, `last_name`, `name`
- `emailAddresses[0].emailAddress` → `email`
- `phoneNumbers[0].phoneNumber` → `mobile`
- `addresses[0]` → `address_line_1`, `city`, `state`, `zip_code`
- `createdTime` → `created_at`
- `modifiedTime` → `updated_at`

**Additional Data to Import:**
- Order history (for lifetime purchases calculation)
- Payment methods (if available)
- Customer notes/tags

### Phase 3: Import UI

**Features:**
- Import button in Business Settings > Integrations
- Select location/store to import from
- Preview customers before import
- Handle duplicates (match by email/phone)
- Import progress indicator
- Log import results

### Phase 4: Additional Clover Data (Future)

**Potential Data to Import:**
- Order history
- Product inventory (if needed)
- Payment methods
- Customer tags/segments

## API Endpoints Needed

Based on Clover API documentation:

1. **Get Customers:**
   - `GET /v3/merchants/{merchant_id}/customers`
   - Requires: Private token in Authorization header
   - Returns: List of customers

2. **Get Customer Orders:**
   - `GET /v3/merchants/{merchant_id}/customers/{customer_id}/orders`
   - For importing order history

## Database Changes

**New Table (if needed):**
- `clover_customer_mappings` - Map Clover customer IDs to ERP contact IDs
  - `id`, `business_id`, `location_id`, `clover_customer_id`, `contact_id`, `created_at`

**Or add to existing:**
- Add `clover_customer_id` to `contacts` table (nullable)
- Add `clover_merchant_id` to `business_locations` table

## Security Considerations

- Store private tokens encrypted in database
- Never expose private tokens in UI
- Use secure API calls (HTTPS)
- Handle API rate limits
- Implement error handling and retry logic

## Testing Requirements

1. Test with both locations (Pico and Hollywood)
2. Test duplicate handling
3. Test with large customer lists
4. Test error scenarios (invalid tokens, API failures)
5. Verify data accuracy after import

---

## Discogs API Information

**Token Provided:**
- Personal Access Token: `zclfcrIrrbhilvMUnjozodOxMRvEAvwPsvJrSsmi`

**API Details:**
- Discogs API v2
- Personal access token for authentication
- Base URL: `https://api.discogs.com`
- Rate limits apply

**Use Cases:**
- Search product prices
- Get product metadata
- List products to Discogs marketplace

---

## eBay API Information

**Developer Portal:** https://developer.ebay.com/develop

**API Types:**
- RESTful APIs
- OAuth 2.0 authentication
- Application keysets required
- User access tokens needed

**Use Cases:**
- List products to eBay
- Search product prices
- Manage listings
- Order management

**Required Credentials:**
- App ID (Client ID)
- Client Secret
- OAuth tokens (per user)
- Sandbox credentials for testing

---

## Next Steps

1. **Obtain Clover Private Tokens** from Clover dashboard
2. **Test Clover API** with provided credentials
3. **Implement customer import** functionality
4. **Set up eBay API** credentials (if needed)
5. **Enhance Discogs integration** with provided token

---

## Notes

- Clover tokens are location-specific
- Private tokens must be kept secure
- API rate limits must be respected
- Duplicate customer handling is critical
- Import should be idempotent (can run multiple times safely)
