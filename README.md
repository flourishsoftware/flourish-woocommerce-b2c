# flourish-woocommerce-b2c
A WooCommerce plugin for B2C retail sales powered by Flourish.

## Installation

### New Installation
1. Download the latest release from GitHub (Code → Download ZIP)
2. Log in to your WordPress admin panel
3. Navigate to Plugins → Add New → Upload Plugin
4. Choose the downloaded ZIP file and click "Install Now"
5. Click "Activate Plugin"

### Updating from a Previous Version
**Important:** Do not update in place. Follow these steps:

1. Deactivate the current plugin (your settings will be preserved)
2. Delete the old plugin files
3. Upload and install the new version as described above
4. Reactivate the plugin

## Discount Sync

The plugin can sync eligible discounts from Flourish into WooCommerce as coupons. When a customer uses a synced promo code at checkout, the plugin sends the promo code to Flourish so the discount is applied on both sides.

### Setting Up Discounts in Flourish

Discounts must meet two criteria to sync into WooCommerce:

1. **E-commerce eligible** — the discount must be marked as e-commerce eligible in Flourish
2. **Applied automatically** — the discount must be set to apply automatically

### Supported Discount Types

**Line-level discounts** (applied per item):
- "Gets $X EACH off the following items"
- "Gets X% off the following items"

**Order-level discounts** (applied to the entire order):
- "Gets $X off the entire purchase"
- "Gets X% off the entire purchase"

**Order Minimum** is supported — if a discount requires a minimum order amount in Flourish, the synced WooCommerce coupon will enforce the same minimum.

### How to Sync

1. Go to **Settings → Flourish WooCommerce B2C**
2. Click **Sync Discounts from Flourish**
3. The plugin will create or update WooCommerce coupons matching the Flourish discounts

Re-sync whenever discounts are added or changed in Flourish.

### How Discounts Work on Order Sync

When a WooCommerce order is synced to Flourish:

- **Matched promo code:** If the coupon code matches a Flourish promo code, the plugin sends the promo code to Flourish and lets Flourish apply the discount. Unit prices are sent at full (pre-discount) price.
- **Unmatched coupon:** If the coupon was created only in WooCommerce (not synced from Flourish), the plugin sends post-discount unit prices so the order total is correct in Flourish. An order note is added indicating the coupon was not recognized in Flourish.

## Requirements

- WordPress 5.0 or higher
- WooCommerce 7.0 or higher
- PHP 7.4 or higher
