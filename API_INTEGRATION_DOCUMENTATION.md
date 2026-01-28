# API Integration Documentation

## Overview
This document provides information about API integrations for Clover, Discogs, and eBay.

---

## Clover POS Integration

### Purpose
- Import customers from Clover POS to ERP
- Sync customer data
- Import order history (future enhancement)

### API Credentials Required

**For Each Location:**
1. **Public Token** - From Clover Dashboard > Setup > API Tokens > Ecommerce API Tokens
2. **Private Token** - From same location (click eye icon to reveal)
3. **Merchant ID** - Found in Clover Dashboard URL: `/m/{merchantId}/`

### Setup Instructions

1. **Get Credentials from Clover:**
   - Log into Clover Dashboard
   - Go to Setup > API Tokens
   - Click on "Ecommerce API Tokens"
   - Create a new token or use existing
   - Copy Public Token and Private Token
   - Note the Merchant ID from the URL

2. **Configure in ERP:**
   - Go to Business Settings > Integrations
   - Find "Clover POS Integration" section
   - Enter Public Token, Private Token, and Merchant ID
   - Select Environment (Production or Sandbox)
   - Save settings

3. **Test Connection:**
   - Click "Test Connection" button
   - Verify connection is successful
   - Check customer count displayed

4. **Import Customers:**
   - Click "Import Customers from Clover" button
   - Preview customers in modal
   - Select customers to import (or select all)
   - Click "Import Selected"
   - Review import results

### API Endpoints Used

- `GET /v3/merchants/{merchant_id}/customers` - Fetch customers
- `GET /v3/merchants/{merchant_id}/customers/{customer_id}/orders` - Get customer orders (future)

### Data Mapping

**Clover → ERP:**
- `firstName` + `lastName` → `name`, `first_name`, `last_name`
- `emailAddresses[0].emailAddress` → `email`
- `phoneNumbers[0].phoneNumber` → `mobile`
- `addresses[0]` → `address_line_1`, `city`, `state`, `zip_code`, `country`
- `id` → `clover_customer_id` (stored for future sync)

### Duplicate Handling

- Customers are matched by email or phone number
- If duplicate found, customer is skipped
- Clover customer ID is stored on existing customer if not already set

### Permissions Required

In Clover Dashboard, ensure these permissions are enabled:
- ✅ Customers (Read)
- ✅ Orders (Read) - for future order import
- ✅ Payments (Read) - if needed

---

## Discogs Integration

### Purpose
- Search product prices
- Get product metadata
- List products to Discogs marketplace

### API Credentials

**Token Provided:**
- Personal Access Token: `zclfcrIrrbhilvMUnjozodOxMRvEAvwPsvJrSsmi`

### Setup Instructions

1. **Configure in ERP:**
   - Go to Business Settings > Integrations
   - Find "Discogs Integration" section
   - Enter API Token
   - Save settings

2. **Get Your Own Token (Optional):**
   - Visit https://www.discogs.com/settings/developers
   - Generate a new personal access token
   - Use it in ERP settings

### API Information

- **Base URL:** `https://api.discogs.com/`
- **Authentication:** Personal Access Token in header
- **Rate Limits:** Apply (check Discogs documentation)
- **Documentation:** https://www.discogs.com/developers

### Current Usage

- Product price search
- Product metadata retrieval
- Marketplace price suggestions

---

## eBay Integration

### Purpose
- List products to eBay marketplace
- Search product prices
- Manage listings

### API Credentials Required

Based on [eBay Developer Portal](https://developer.ebay.com/develop):

1. **App ID (Client ID)** - From eBay Developer Account
2. **Cert ID (Client Secret)** - From eBay Developer Account
3. **Dev ID** - Developer ID (optional, for some API types)
4. **OAuth Tokens** - User access tokens (per user)

### Setup Instructions

1. **Create eBay Developer Account:**
   - Visit https://developer.ebay.com/
   - Sign up for Developers Program
   - Create an application

2. **Get Credentials:**
   - Go to Application Keysets
   - Create new keyset
   - Copy App ID (Client ID) and Cert ID (Client Secret)
   - Note Dev ID if provided

3. **Configure in ERP:**
   - Go to Business Settings > Integrations
   - Find "eBay Integration" section
   - Enter App ID, Cert ID, Dev ID
   - Select Environment (Sandbox/Production)
   - Save settings

4. **OAuth Setup (Required for Listing):**
   - OAuth flow needed for user authorization
   - Tokens stored per user
   - Required for creating listings

### API Information

- **Base URL (Production):** `https://api.ebay.com`
- **Base URL (Sandbox):** `https://api.sandbox.ebay.com`
- **Authentication:** OAuth 2.0
- **Documentation:** https://developer.ebay.com/develop

### API Types Available

**Selling APIs:**
- Listing Management
- Account Management
- Order Management
- Inventory Management

**Buying APIs:**
- Browse API
- Marketplace Metadata
- Checkout/Bid

### Current Implementation Status

- Basic service class exists
- Needs OAuth implementation for full functionality
- Listing functionality pending

---

## Security Best Practices

1. **Token Storage:**
   - Private tokens stored encrypted in database
   - Never expose tokens in UI (masked)
   - Use secure API calls (HTTPS only)

2. **Access Control:**
   - Only authorized users can configure integrations
   - API credentials restricted to admin users

3. **Error Handling:**
   - API errors logged securely
   - User-friendly error messages
   - No sensitive data in error messages

4. **Rate Limiting:**
   - Respect API rate limits
   - Implement retry logic with backoff
   - Monitor API usage

---

## Testing

### Clover
1. Test connection with provided credentials
2. Preview customers before import
3. Import small batch first
4. Verify data accuracy
5. Test duplicate handling

### Discogs
1. Test token with search API
2. Verify price suggestions work
3. Test with various product types

### eBay
1. Use Sandbox environment for testing
2. Test OAuth flow
3. Test listing creation
4. Verify error handling

---

## Support Resources

- **Clover API Docs:** https://docs.clover.com/
- **Discogs API Docs:** https://www.discogs.com/developers
- **eBay Developer Portal:** https://developer.ebay.com/develop
- **eBay API Documentation:** https://developer.ebay.com/docs

---

## Notes

- All API integrations require proper credentials
- Test in sandbox/staging first
- Monitor API usage and costs
- Keep credentials secure and rotate regularly
- Document any custom implementations
