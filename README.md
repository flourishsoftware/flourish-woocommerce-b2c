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

## Payment Pre-Authorization (AeroPay)

The plugin can send a pre-authorized AeroPay payment with the order. Flourish records the hold, then captures it when staff confirm payment. Your checkout pre-authorizes; Flourish does everything after that.

### Settings

Under **Settings → Flourish B2C**:

- **Send Payment to Flourish:** turn on to send pre-authorized payments.
- **Pre-Auth Gateway ID:** your gateway's WooCommerce payment method ID (`WC_Payment_Gateway::$id`). Orders paid with it don't sync at checkout; they sync when the gateway reports the authorization (below).
- **Shipped Orders:** choose **Postal Shipping** if orders are mailed.

AeroPay must be configured for the facility in Flourish, and the pre-auth must be created under that same AeroPay merchant and location. Otherwise Flourish rejects the order's payment.

### Gateway contract

After the AeroPay SDK `userAuthorize` flow succeeds (`onSuccess`), the gateway writes this order meta, sets the order to `on-hold`, and fires the action:

| Meta key | Required | Value |
|---|---|---|
| `_flourish_payment_reference_id` | Yes | Pre-auth transaction `id` from `onSuccess` |
| `_flourish_payment_amount` | Yes | Authorized amount in dollars (`onSuccess` `total`). Defaults to the order total if omitted |
| `_flourish_payment_method` | No | `aeropay` (default) |
| `_flourish_payment_status` | No | `preauthorized` (default) |
| `_flourish_payment_processor_data` | Recommended | JSON of the `onSuccess` object. Must include `merchantId`; never include credentials |
| `_flourish_payment_expires_at` | No | `expiryDate` from `GET /v2/preauthTransaction/{id}`, ISO 8601 UTC. Flourish defaults to 72 hours |
| `_flourish_payment_tip_amount` | No | Tip included in the amount, if any |

```php
$order->update_meta_data('_flourish_payment_reference_id', $preauth['id']);
$order->update_meta_data('_flourish_payment_amount', $preauth['total']);
$order->update_meta_data('_flourish_payment_processor_data', wp_json_encode($preauth));
$order->update_status('on-hold', 'AeroPay payment pre-authorized.');
$order->save();

do_action('flourish_payment_authorized', $order);
```

**The gateway must never capture, update, void, or refund the pre-auth.** Flourish adjusts the hold to the final order total and captures it when staff confirm payment, and voids it when the order is cancelled in Flourish.

### Order lifecycle

- **Packed / Out for Delivery** in Flourish: the WooCommerce order stays `on-hold` (note added).
- **Payment confirmed** in Flourish: the order is marked paid (`processing`) with the pre-auth ID as its transaction ID.
- **Completed** in Flourish: `completed`.
- **Cancel pre-auth orders in Flourish**, which releases the hold. Cancelling in WooCommerce doesn't cancel the Flourish order; the plugin adds a note and emails the site admin.

## Requirements

- WordPress 5.0 or higher
- WooCommerce 7.0 or higher
- PHP 7.4 or higher
