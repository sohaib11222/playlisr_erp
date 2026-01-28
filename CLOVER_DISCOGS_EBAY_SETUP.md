# Clover, Discogs, and eBay API Setup Guide

## Quick Reference

### Clover API Credentials

**Pico Store:**
- Public Token: `64d5e239-bcb1-82be-7708-f92e822504f0`
- Merchant ID: `5482983600020311`
- Private Token: **Required** - Get from Clover Dashboard (click eye icon)

**Hollywood Store:**
- Public Token: `4087d617-c216-74b5-1a77-4aad5c0c1b3e`
- Merchant ID: `526494042884`
- Private Token: **Required** - Get from Clover Dashboard (click eye icon)

### Discogs API Token

- Personal Access Token: `zclfcrIrrbhilvMUnjozodOxMRvEAvwPsvJrSsmi`
- Already configured in system

### eBay API

- Developer Portal: https://developer.ebay.com/develop
- Requires App ID, Cert ID, Dev ID, and OAuth tokens
- See detailed setup below

---

## Clover Setup Instructions

### Step 1: Get Private Token

1. Log into Clover Dashboard
2. Go to **Setup** > **API Tokens**
3. Click on **Ecommerce API Tokens**
4. Find your token (named "Sohaib" or similar)
5. Click the **eye icon** to reveal the private token
6. Copy the private token (it will be masked with dots)

### Step 2: Configure in ERP

1. Go to **Business Settings** > **Integrations** tab
2. Find **Clover POS Integration** section
3. Enter credentials:
   - **Public Token:** (already provided above)
   - **Private Token:** (paste from Step 1)
   - **Merchant ID:** (already provided above)
   - **Environment:** Production
4. Click **Save**

### Step 3: Test Connection

1. Click **"Test Connection"** button
2. Verify it shows "Connection successful!"
3. Check customer count displayed

### Step 4: Import Customers

1. Click **"Import Customers from Clover"** button
2. Preview customers in the modal
3. Select customers to import (checkboxes)
4. Click **"Import Selected"**
5. Review results (imported, skipped, errors)

### Permissions Required

In Clover Dashboard, ensure these permissions are enabled for your API token:
- ✅ **Customers** (Read)
- ✅ **Orders** (Read) - for future enhancements
- ✅ **Payments** (Read) - if needed

---

## Discogs Setup

### Current Status
- Token already provided: `zclfcrIrrbhilvMUnjozodOxMRvEAvwPsvJrSsmi`
- Token is configured in Business Settings > Integrations

### To Update Token (if needed)

1. Go to https://www.discogs.com/settings/developers
2. Generate new personal access token
3. Go to Business Settings > Integrations > Discogs
4. Update token and save

### Usage
- Product price search
- Marketplace price suggestions
- Product metadata retrieval

---

## eBay Setup

### Step 1: Create Developer Account

1. Visit https://developer.ebay.com/
2. Click **"Join"** or **"Sign In"**
3. Complete registration

### Step 2: Create Application

1. Go to **Application Keysets**
2. Click **"Create Keyset"**
3. Fill in application details:
   - Application Name
   - Application Type
   - OAuth Redirect URI (if needed)
4. Save and get credentials:
   - **App ID (Client ID)**
   - **Cert ID (Client Secret)**
   - **Dev ID** (if provided)

### Step 3: Configure in ERP

1. Go to **Business Settings** > **Integrations** tab
2. Find **eBay Integration** section
3. Enter:
   - **App ID (Client ID)**
   - **Cert ID (Client Secret)**
   - **Dev ID** (if available)
   - **Environment:** Sandbox (for testing) or Production
4. Click **Save**

### Step 4: OAuth Setup (Required for Listing)

**Note:** OAuth is required to create listings. This needs to be implemented.

1. User must authorize application
2. OAuth tokens stored per user
3. Tokens used for API calls

### API Documentation

- **Developer Portal:** https://developer.ebay.com/develop
- **API Reference:** https://developer.ebay.com/docs
- **OAuth Guide:** Available in developer portal

---

## Testing

### Clover
1. ✅ Test connection
2. ✅ Preview customers
3. ✅ Import small batch
4. ✅ Verify data accuracy

### Discogs
1. ✅ Test token with search
2. ✅ Verify price suggestions

### eBay
1. ⏳ Use Sandbox for testing
2. ⏳ Test OAuth flow
3. ⏳ Test listing creation

---

## Support

- **Clover Support:** https://docs.clover.com/
- **Discogs Support:** https://www.discogs.com/developers
- **eBay Support:** https://developer.ebay.com/support

---

## Important Notes

1. **Private Tokens are Sensitive:**
   - Never share private tokens
   - Store securely
   - Rotate regularly

2. **Rate Limits:**
   - All APIs have rate limits
   - Monitor usage
   - Implement retry logic

3. **Environment:**
   - Use Sandbox for testing
   - Switch to Production when ready

4. **Permissions:**
   - Ensure correct permissions enabled
   - Review API access regularly
