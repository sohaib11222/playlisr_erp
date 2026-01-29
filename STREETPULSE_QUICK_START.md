# StreetPulse Integration - Quick Start Guide

## ✅ What's Already Implemented

The StreetPulse integration is **fully implemented** and ready to use. Here's what you need to do to make it work:

## 📋 Step-by-Step Setup

### Step 1: Run the Migration (if not already done)

The migration adds two columns to the `business` table:
- `streetpulse_acronym` - Your store's 3-4 character code
- `streetpulse_last_upload_date` - Tracks last successful upload

**Run this command:**
```bash
cd /Users/macbookpro/playlist-erp/app
php artisan migrate
```

If you get an error saying the table column already exists, that's fine - the migration has already been run.

### Step 2: Configure Your Store Acronym

1. **Get your StreetPulse Store Acronym** from StreetPulse (it's a 3-4 character code like `WSQ`, `BQ01`, etc.)

2. **Go to Business Settings:**
   - Navigate to: **Business Settings > Integrations tab**
   - Find the **"StreetPulse Integration"** section

3. **Enter your Store Acronym:**
   - In the "StreetPulse Store Acronym" field, enter your 3-4 character code
   - Example: If StreetPulse assigned you `WSQ`, enter `WSQ`

4. **Select Check Digit Option:**
   - **NOCHECKDIGIT** (default) - Removes the last digit from UPCs (recommended)
   - **CHECKDIGIT** - Keeps full UPC with check digit
   - Most stores use `NOCHECKDIGIT` - only change if StreetPulse specifically requires it

5. **Click "Update Settings"** at the bottom of the page to save

### Step 3: Test the Connection

1. In the StreetPulse Integration section, click **"Test FTP Connection"**
2. You should see a success message if everything is working
3. If you see an error, check:
   - Is your Store Acronym entered correctly?
   - Is your server able to connect to the internet?
   - Are there any firewall restrictions blocking FTP connections?

### Step 4: Test Manual Upload

1. Select a date (defaults to yesterday)
2. Click **"Upload Selected Date"**
3. Wait for the success message
4. The "Last Upload" date should update automatically

### Step 5: Set Up Automatic Daily Uploads

The system is configured to automatically upload data every day at 2:00 AM (uploading yesterday's data).

**For this to work, you need to set up Laravel's task scheduler:**

1. **Add this to your server's crontab:**
   ```bash
   * * * * * cd /path/to/your/app && php artisan schedule:run >> /dev/null 2>&1
   ```

2. **Or if using a control panel (cPanel, Plesk, etc.):**
   - Set up a cron job that runs every minute
   - Command: `php artisan schedule:run`
   - Working directory: `/Users/macbookpro/playlist-erp/app` (or your actual path)

3. **Verify it's working:**
   - Check the logs at `storage/logs/laravel.log` after 2:00 AM
   - Look for "StreetPulse Upload Success" messages

## 🔍 Troubleshooting

### Issue: "StreetPulse acronym not configured"
**Solution:** Make sure you've entered your Store Acronym in Business Settings > Integrations and clicked "Update Settings"

### Issue: "FTP connection failed"
**Possible causes:**
- Server firewall blocking FTP connections
- Network connectivity issues
- FTP credentials changed (contact StreetPulse)

**Solution:** 
- Check server logs: `storage/logs/laravel.log`
- Try the "Test FTP Connection" button to see detailed error messages
- Contact your server administrator if firewall is blocking connections

### Issue: "No sales data found for date"
**Solution:** 
- Make sure you have sales transactions for that date
- Check that transactions are marked as "final" status
- Verify the date range in your sales reports

### Issue: Automatic uploads not running
**Solution:**
- Verify the cron job is set up correctly (see Step 5 above)
- Check `storage/logs/laravel.log` for scheduler errors
- Manually test the command: `php artisan streetpulse:upload-daily`

## 📊 What Gets Uploaded

For each sale transaction, the system uploads:
- **UPC Code:** Extracted from product SKU/barcode
- **Timestamp:** Date and time of the sale
- **Used Status:** 0 (New items) or 1 (Used items) - currently defaults to 0
- **Count:** Quantity sold

**File Format:** SPULSE02 format as required by StreetPulse
**File Naming:** `{ACRONYM}-{YYYYMMDD}.txt` (e.g., `WSQ-20260121.txt`)
**Compression:** Files with more than 10,000 records are automatically compressed

## 🔒 Security

- FTP credentials are already configured (standard StreetPulse credentials)
- No additional credentials needed from you
- Files are stored locally in `storage/app/streetpulse/` and automatically cleaned up after 7 days

## 📞 Need Help?

1. **Check the logs:** `storage/logs/laravel.log`
2. **Test manually:** Use the "Test FTP Connection" and "Upload Selected Date" buttons
3. **Verify configuration:** Make sure Store Acronym is saved in Business Settings
4. **Check cron:** Verify the scheduler is running (see Step 5)

## ✅ Verification Checklist

- [ ] Migration has been run
- [ ] Store Acronym is configured in Business Settings
- [ ] "Test FTP Connection" button works
- [ ] Manual upload works for a test date
- [ ] Cron job is set up for automatic daily uploads
- [ ] Check logs after 2:00 AM to verify automatic uploads are working

---

**That's it!** Once you've configured your Store Acronym, the integration will automatically upload your daily sales data to StreetPulse.
