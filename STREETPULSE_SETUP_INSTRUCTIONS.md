# StreetPulse Integration - Setup Instructions

## ✅ Implementation Complete

The StreetPulse FTP integration has been successfully implemented in your POS system. Your daily sales data will now be automatically uploaded to StreetPulse servers in the required SPULSE02 format.

## 📋 What You Need to Provide

**Only ONE thing is required:**

### StreetPulse Store Acronym
- **What it is:** A 3-4 character code assigned to your store by StreetPulse
- **Examples:** `WSQ`, `BQ01`, `BQ02`
- **Where to get it:** Contact StreetPulse or check your StreetPulse account documentation
- **Required:** Yes - This is the only credential you need to provide

## ⚙️ Setup Steps

1. **Go to Business Settings**
   - Navigate to: **Business Settings > Integrations tab**

2. **Enter Your Store Acronym**
   - Find the "StreetPulse Integration" section
   - Enter your 3-4 character Store Acronym in the "StreetPulse Store Acronym" field
   - Example: If StreetPulse assigned you `WSQ`, enter `WSQ`

3. **Select Check Digit Option** (Optional)
   - Default: `NOCHECKDIGIT` (recommended - removes last digit from UPCs)
   - Alternative: `CHECKDIGIT` (keeps full UPC with check digit)
   - Most stores use `NOCHECKDIGIT` - only change if StreetPulse specifically requires it

4. **Test Connection**
   - Click "Test FTP Connection" button to verify connectivity
   - You should see a success message if everything is working

5. **Save Settings**
   - Click "Update Settings" at the bottom of the page to save your configuration

## 🚀 How It Works

### Automatic Daily Uploads
- **When:** Every day at 2:00 AM
- **What:** Uploads yesterday's sales data (12:00 AM to 11:59 PM)
- **Format:** SPULSE02 format as required by StreetPulse
- **No action needed:** Runs automatically in the background

### Manual Uploads
- **When:** Anytime you need to upload data for a specific date
- **How:** 
  1. Go to Business Settings > Integrations
  2. Select the date you want to upload
  3. Click "Upload Selected Date"
- **Use cases:** 
  - Backfilling historical data
  - Retrying a failed upload
  - Testing the integration

## 📊 What Gets Uploaded

For each sale transaction, the system uploads:
- **UPC Code:** From your product SKU/barcode
- **Timestamp:** Date and time of the sale
- **Used Status:** 0 (New items) or 1 (Used items)
- **Count:** Quantity sold

**File Format:** Files are named `{ACRONYM}-{YYYYMMDD}.txt` (e.g., `WSQ-20260121.txt`)
**Compression:** Files with more than 10,000 records are automatically compressed

## 🔒 Security & Credentials

**Good News:** No FTP credentials needed!
- FTP username and password are already configured (standard StreetPulse credentials)
- FTP servers are pre-configured (primary and backup)
- You only need to provide your Store Acronym

## ✅ Verification

After setup, you can verify it's working by:
1. **Check Last Upload Date:** The system displays when data was last successfully uploaded
2. **Test Connection:** Use the "Test FTP Connection" button anytime
3. **Manual Upload:** Try uploading a test date to confirm everything works
4. **Check StreetPulse Dashboard:** Verify data appears in your StreetPulse account

## 📞 Support

If you need help:
- **Missing Acronym:** Contact StreetPulse to get your assigned Store Acronym
- **Connection Issues:** Use "Test FTP Connection" to diagnose problems
- **Upload Failures:** Check the system logs or try manual upload for specific dates

## 📝 Important Notes

- **Duplicate Prevention:** The system tracks the last upload date to prevent uploading the same data twice
- **File Cleanup:** Old upload files are automatically deleted after 7 days
- **Multi-Store:** If you have multiple stores, each needs its own Store Acronym
- **Date Range:** Each upload covers one full day (midnight to midnight)

---

**Ready to start?** Just provide your StreetPulse Store Acronym and we'll configure it for you!
