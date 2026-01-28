# Loyalty System Documentation

## Overview
The Playlist ERP POS system includes a comprehensive loyalty and rewards program that integrates with the existing reward points system.

---

## Features

### 1. Loyalty Points
- Points are earned on every purchase
- Points are synced with reward points (`total_rp`)
- Points can be redeemed for discounts
- Points are displayed in customer account

### 2. Loyalty Tiers
- Automatic tier assignment based on lifetime purchases
- Tier multipliers for bonus points
- Tier-based discounts (configurable)
- Visual tier badges (Bronze, Silver, Gold)

### 3. Lifetime Purchases Tracking
- Automatically calculated from all sales
- Updated on every purchase
- Used for tier determination
- Displayed in customer profile

### 4. Automatic Tier Upgrades
- Tiers upgrade automatically when thresholds are met
- Upgrades happen immediately after purchase
- No manual intervention required

---

## Configuration

### Setting Up Reward Points

1. **Enable Reward Points:**
   - Go to `Business Settings` > `Reward Point Settings`
   - Enable "Enable Reward Points"
   - Configure:
     - Amount per unit point (e.g., $1 = 1 point)
     - Minimum order total for points
     - Maximum points per order
     - Minimum points to redeem
     - Maximum points to redeem

2. **Configure Loyalty Tiers:**
   - Go to `/loyalty-tiers`
   - Create tiers:
     - **Bronze:** $0 minimum, 1.0x multiplier
     - **Silver:** $200 minimum, 1.25x multiplier
     - **Gold:** $500 minimum, 1.5x multiplier
     - **Platinum:** $1000 minimum, 2.0x multiplier (optional)

### Tier Configuration Fields

- **Name:** Tier name (e.g., "Gold")
- **Description:** Tier description
- **Min Lifetime Purchases:** Minimum total purchases to reach tier
- **Discount Percentage:** Discount % for this tier (optional)
- **Points Multiplier:** Bonus multiplier (e.g., 1.5 = 50% bonus)
- **Sort Order:** Display order
- **Is Active:** Enable/disable tier

---

## How It Works

### Points Calculation

1. **Base Points:**
   ```
   Base Points = Floor(Order Total / Amount Per Unit Point)
   ```

2. **With Tier Multiplier:**
   ```
   Final Points = Floor(Base Points × Tier Multiplier)
   ```

3. **Example:**
   - Order Total: $100
   - Amount Per Unit Point: $1
   - Base Points: 100
   - Customer Tier: Gold (1.5x multiplier)
   - Final Points: 150

### Tier Assignment

1. **On Purchase:**
   - Lifetime purchases calculated
   - Tier determined based on thresholds
   - Customer tier updated automatically

2. **Tier Logic:**
   - System finds highest tier where `lifetime_purchases >= min_lifetime_purchases`
   - If no tier found, defaults to "Bronze"

### Points Synchronization

- `loyalty_points` field synced with `total_rp` (reward points)
- Both fields updated together
- Ensures consistency across system

---

## Database Structure

### Contacts Table
- `lifetime_purchases` (decimal) - Total purchase amount
- `loyalty_points` (integer) - Current points balance
- `loyalty_tier` (string) - Current tier name
- `last_purchase_date` (date) - Last purchase date
- `total_rp` (integer) - Reward points (synced)

### Loyalty Tiers Table
- `business_id` - Business reference
- `name` - Tier name
- `min_lifetime_purchases` - Threshold amount
- `discount_percentage` - Discount for tier
- `points_multiplier` - Points bonus multiplier
- `is_active` - Active status

---

## Usage Examples

### Example 1: New Customer
1. Customer makes first purchase: $50
2. Earns: 50 points (base)
3. Tier: Bronze (1.0x)
4. Lifetime Purchases: $50

### Example 2: Tier Upgrade
1. Customer has $400 lifetime purchases (Bronze)
2. Makes $150 purchase
3. Lifetime Purchases: $550
4. Tier upgrades to Gold (if threshold is $500)
5. Earns: 150 base points × 1.5 = 225 points

### Example 3: High-Value Customer
1. Customer has $2000 lifetime purchases
2. Tier: Platinum (2.0x multiplier)
3. Makes $100 purchase
4. Earns: 100 base points × 2.0 = 200 points

---

## API Integration

### Updating Points (Internal)
```php
$transactionUtil->updateCustomerRewardPoints(
    $customer_id,
    $earned_points,
    $earned_before = 0,
    $redeemed = 0,
    $redeemed_before = 0
);
```

### Calculating Points
```php
$points = $transactionUtil->calculateRewardPoints(
    $business_id,
    $order_total,
    $customer_id // Optional, for tier multiplier
);
```

### Getting Tier
```php
$tier = LoyaltyTier::getTierForPurchaseAmount(
    $business_id,
    $lifetime_purchases
);
```

---

## Display Locations

### POS Screen
- Customer account panel shows:
  - Loyalty Points
  - Loyalty Tier (with badge)
  - Lifetime Purchases

### Customer Listing
- Columns display:
  - Lifetime Purchases
  - Loyalty Points
  - Loyalty Tier
  - Preorders Count

### Customer Profile Modal
- Complete account summary:
  - Account Balance
  - Lifetime Purchases
  - Loyalty Points
  - Loyalty Tier
  - Last Purchase Date

---

## Best Practices

1. **Tier Thresholds:**
   - Set realistic thresholds
   - Consider customer spending patterns
   - Provide meaningful benefits at each tier

2. **Points Multipliers:**
   - Start conservative (1.0x, 1.25x)
   - Increase for higher tiers
   - Monitor point accumulation

3. **Tier Names:**
   - Use clear, recognizable names
   - Bronze, Silver, Gold, Platinum work well
   - Consider your brand identity

4. **Regular Updates:**
   - Lifetime purchases update automatically
   - Tiers upgrade automatically
   - Points sync automatically

---

## Troubleshooting

### Points Not Awarded
- Check reward points enabled in settings
- Verify order total meets minimum
- Check customer is not walk-in customer
- Verify tier is active

### Tier Not Upgrading
- Check lifetime purchases calculation
- Verify tier thresholds configured
- Check tier is active
- Review tier sort order

### Points Not Syncing
- Check `loyalty_points` and `total_rp` fields
- Verify `updateCustomerRewardPoints` called
- Check for errors in logs

---

## Future Enhancements

Potential improvements:
1. Tier-specific discounts at POS
2. Points expiration dates
3. Referral bonus points
4. Birthday bonus points
5. Special promotion multipliers
6. Points transfer between customers
7. Points purchase (buy points)

---

**Last Updated:** January 22, 2026
