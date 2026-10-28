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

## AeroPay Payments

For stores whose AeroPay gateway pre-authorizes at checkout and captures when staff confirm payment in WooCommerce. The gateway owns the AeroPay transaction (authorize, capture, void, refund) with the store's own AeroPay credentials. The plugin sends the order to Flourish once the payment is authorized, then records the captured payment on the Flourish order as paid.

### Settings

Under **Settings → Flourish B2C**:

- **Send Payments to Flourish:** turn on.
- **AeroPay Gateway ID:** your gateway's WooCommerce payment method ID (`WC_Payment_Gateway::$id`). Orders paid with it don't sync at checkout; they sync when the gateway reports the authorization.
- **Shipped Orders:** choose **Postal Shipping** if orders are mailed.

AeroPay must be enabled as a payment type for the facility in Flourish.

### Gateway contract

**1. After the pre-auth succeeds** (SDK `userAuthorize` `onSuccess`): set the order to `on-hold` and fire the action. The plugin creates the Flourish order with no payment, so it shows **Awaiting Payment**.

```php
$order->update_status('on-hold', 'AeroPay payment pre-authorized.');
do_action('flourish_payment_authorized', $order);
```

**2. After capture** (when staff confirm payment in WooCommerce): write this order meta, mark the order paid, and fire the action. The plugin posts the payment to Flourish as paid.

| Meta key | Required | Value |
|---|---|---|
| `_flourish_payment_reference_id` | Yes | AeroPay **transaction** ID from the `capturePreauthTransaction` response (not the pre-auth ID; refunds use this ID) |
| `_flourish_payment_amount` | Yes | Captured amount in dollars. Defaults to the order total if omitted |
| `_flourish_payment_processor_data` | Recommended | JSON of the capture response. Never include credentials |
| `_flourish_payment_tip_amount` | No | Tip included in the amount, if any |

```php
$order->update_meta_data('_flourish_payment_reference_id', $capture['transaction']['id']);
$order->update_meta_data('_flourish_payment_amount', $capture['transaction']['amount']['amount'] / 100);
$order->update_meta_data('_flourish_payment_processor_data', wp_json_encode($capture));
$order->save();
$order->payment_complete($capture['transaction']['id']);

do_action('flourish_payment_captured', $order);
```

If recording the payment fails, the plugin adds an order note and emails the site admin. Retry from the order screen with **Order actions → Send payment to Flourish**.

### Order of operations

1. **Checkout:** the gateway pre-authorizes; the order reaches Flourish as Awaiting Payment.
2. **Pack in Flourish:** this sets the final order total.
3. **Print the label and confirm payment in WooCommerce:** the gateway captures the final total. If it differs from the authorization, `PATCH /v2/preauthTransaction/{id}` the amount before capturing.
4. **Payment recorded in Flourish:** the order shows Paid.
5. **Complete the order in Flourish and ship:** completion reports the sale for compliance. The WooCommerce order moves to `completed`.

Notes:

- **Capture after packing.** Flourish rejects a payment larger than the order's remaining balance. A smaller payment leaves the order Partially Paid.
- **Don't take payment for these orders in Flourish.** They show Awaiting Payment until the payment is captured in WooCommerce.
- **Voids and refunds happen in the gateway.** The pre-auth expires after 72 hours by default (configurable per merchant), so capture within that window.
- **Cancel in both places.** The gateway releases the hold. Cancelling in WooCommerce doesn't cancel the Flourish order, so the plugin adds a note and emails the site admin to cancel it in Flourish.
- Flourish's Packed and Out for Delivery statuses don't change these WooCommerce orders. Completed and Cancelled do.

## Flourish API Access

### Endpoints

The plugin calls these Flourish external API endpoints, so the API key needs access to each one. Requests send the key in the `x-api-key` header and, where the endpoint is facility-scoped, the selected facility in the `FacilityID` header.

| Endpoint | Method + path | Used for |
|---|---|---|
| PostRetailOrderV2 | `POST /external/api/v2/retail-orders` | Sending WooCommerce orders to Flourish |
| PostRetailOrderPaymentV2 | `POST /external/api/v2/retail-orders/{id}/payment` | Recording payments on the Flourish order |
| GetRetailOrder | `GET /external/api/v1/retail-orders/{id}` | Checking the Flourish status before and after a cancel |
| PutRetailOrder | `PUT /external/api/v1/retail-orders/{id}` | Cancelling orders that are still Created in Flourish |
| GetEligibleDiscounts | `GET /external/api/v1/retail-discounts` | Discount sync and promo code matching at order sync |
| GetRetailCustomers | `GET /external/api/v1/customers?email=` | Finding the customer by email |
| PostRetailCustomer | `POST /external/api/v1/customers` | Creating the customer if none exists |
| GetItems | `GET /external/api/v1/items` | Product import and SKU checks |
| GetInventorySummary | `GET /external/api/v1/inventory/summary` | Stock levels for import, webhooks and cancellations |
| GetBrands | `GET /external/api/v1/brands` | Brand filter options on the settings page |
| GetFacilities | `GET /external/api/v1/facilities` | Facility picker on the settings page |

### Webhooks

Flourish sends webhooks to:

```
https://<your-site>/wp-json/flourish-woocommerce-plugin/v1/webhook
```

The settings page shows the exact URL. Each request must include an `auth_signature` header: the HMAC-SHA256 of the raw request body, keyed with the **Webhook Key** from the plugin settings. Requests with a missing or invalid signature are rejected.

Subscribe to these resource types in Flourish:

| Resource type | What the plugin does |
|---|---|
| `item` | Creates or updates the WooCommerce product. Skips items that aren't e-commerce active, have no SKU, or don't match the brand filter |
| `inventory_summary` | Updates the product's stock level by SKU |
| `retail_order` | Updates the WooCommerce order status and refreshes stock for its items. Packed and Out for Delivery → `completed` (except AeroPay gateway orders), Completed → `completed`, Cancelled → `cancelled` |

## Requirements

- WordPress 5.0 or higher
- WooCommerce 7.0 or higher
- PHP 7.4 or higher
