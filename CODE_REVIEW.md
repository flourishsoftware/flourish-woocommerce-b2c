# Flourish WooCommerce B2B Plugin - Code Review for B2C Retail Readiness

**Date:** 2026-02-12
**Reviewed codebase:** [flourish-woocommerce-b2b](https://github.com/flourishsoftware/flourish-woocommerce-b2b) (main branch, v1.4.0)
**Context:** New CBD/hemp retail (B2C) client going live with WooCommerce

---

## Summary

The B2B plugin has solid foundations but contains **several critical bugs, security vulnerabilities, and missing CBD compliance features** that must be addressed before a retail client goes live. The most urgent issues involve broken stock management logic, unauthenticated PII endpoints, and duplicate webhook processing.

**Open PR #8** (`Bugfix/backordering`) fixes one of the critical stock management bugs but has not been merged.

---

## CRITICAL - Must Fix Before Go-Live

### 1. `should_manage_stock()` logic is broken — stock updates silently skipped

**Files:** `FlourishWebhook.php:435-442`, `HandlerOrdersSyncNow.php:695-703`, `HandlerOrdersOutbound.php:388-396`, `HandlerOutboundUpdateCart.php:890-898`

```php
return $manage_stock === false && (
    $backorders_allowed === 'notify' ||
    $backorders_allowed === 'yes' ||
    $stock_status == "onbackorder" ||
    $stock_status == "instock"
);
```

**Problem:** This returns `true` (skip stock management) when `manage_stock` is `false` AND the stock status is `"instock"`. Since `"instock"` is the **default status for most WooCommerce products**, this function effectively skips stock management for nearly all products that don't have explicit stock tracking enabled. For a retail client, this means **inventory won't update properly from Flourish**, leading to overselling or showing stale stock.

**Note:** PR #8 (open, not merged) partially fixes this in `FlourishWebhook.php` and `HandlerOutboundUpdateCart.php`, but the fix needs to be applied consistently across all 4 duplicated copies.

**Fix:** Simplify to `return $manage_stock === false;` — if the product doesn't manage stock, skip it. The backorders/stock-status conditions are incorrect.

---

### 2. Webhook logging function re-processes items (double execution)

**File:** `FlourishWebhook.php:135-138`

```php
private function log_webhook_result($status, $payload, $error = null) {
    // ...
    if ($payload['resource_type'] == "item") {
        $this->handle_item($payload['data']);
    }
    // ...
}
```

**Problem:** `log_webhook_result()` is called AFTER `handle_item()` already runs in `handle_webhook()`. This causes **every item webhook to be processed twice** — once in the main handler and once in the logging function. For a retail client receiving inventory updates, every product sync fires double API calls, double product saves, and double stock calculations. This will cause:
- Race conditions on stock quantities
- Double the API load on Flourish
- Confusing log entries

**Fix:** Remove the `handle_item()` call from `log_webhook_result()`.

---

### 3. DOB REST endpoints expose PII without authentication

**File:** `DateOfBirth.php:34-47, 72-78`

```php
register_rest_route('custom-endpoint', '/get-dob', [
    'methods' => 'GET',
    'callback' => [$this, 'get_dob_via_rest'],
    'permission_callback' => '__return_true',  // No auth!
]);
register_rest_route('custom-endpoint', '/save-dob', [
    'methods' => 'POST',
    'callback' => [$this, 'save_dob_via_rest'],
    'permission_callback' => '__return_true',  // No auth!
]);
```

**Problem:** Anyone can read or write date of birth data for any email address by hitting `/wp-json/custom-endpoint/get-dob?email=anyone@example.com`. For a CBD retail client, DOB is used for age verification and is PII subject to privacy regulations.

**Fix:** Add proper authentication — at minimum, nonce verification for logged-in users. For the GET endpoint, restrict to the user's own data or require admin capabilities.

---

### 4. Uncaught exceptions due to missing namespace backslash

**Files:** `FlourishWebhook.php:202`, `HandlerOrdersSyncNow.php:107,185,288,343,690`, `HandlerOrdersCancel.php:295`

```php
} catch (Exception $e) {
```

**Problem:** In namespaced PHP, `Exception` resolves to `FlourishWooCommercePlugin\...\Exception` which doesn't exist. These catch blocks **will never catch anything**. Any error in these code paths (API failures, stock update errors, order sync failures) will throw an uncaught exception, potentially crashing the request and leaving orders in inconsistent states.

**Fix:** Change to `\Exception` in all catch blocks within namespaced code.

---

### 5. No age verification on DOB

**Files:** `DateOfBirth.php`, `HandlerOrdersRetail.php`

**Problem:** The DOB field is collected at checkout and sent to Flourish, but **no local validation checks if the customer is of legal age** (21+ for cannabis, 18+ for hemp depending on jurisdiction). If the Flourish API doesn't reject underage customers, orders will go through. For a CBD/hemp retail client, this is a compliance risk.

**Fix:** Add age validation in `validate_dob_field()` or at order creation time in `handle_order_retail()`. The minimum age should be configurable in the settings page.

---

### 6. `fetch_products()` hangs forever on persistent API failure

**File:** `FlourishAPI.php:116-123`

```php
} catch (\Exception $e) {
    error_log("Error fetching products (API call): " . $e->getMessage());
    sleep(60);
    continue;  // Loops forever
}
```

**Problem:** If the Flourish API is down, the initial product import hangs indefinitely (sleeping 60 seconds between infinite retries). The admin user's browser tab will time out or appear frozen. On a retail site, if a scheduled import triggers this, it could consume a PHP worker indefinitely.

**Fix:** Add a max retry count (e.g., 3 attempts) and fail gracefully with an admin notice.

---

### 7. `FlourishAPI::fetch_product_by_id()` references undefined property

**File:** `FlourishAPI.php:85`

```php
$imported_count = $flourish_items->save_as_woocommerce_products(
    $this->existing_settings['item_sync_options'] ?? [], $webhook_status
);
```

**Problem:** `FlourishAPI` has no `$existing_settings` property — only `$api_key`, `$url`, `$facility_id`, and `$auth_header`. This will generate a PHP notice and always fall back to an empty array for sync options.

---

### 8. Retail order handler globally disables WooCommerce stock reduction

**File:** `HandlerOrdersRetail.php:21`

```php
add_filter('woocommerce_can_reduce_order_stock', '__return_false');
```

**Problem:** This globally disables WooCommerce's built-in stock reduction for ALL orders. The plugin replaces this with its own stock sync via the Flourish API. But if the API call fails or the webhook doesn't fire, **stock is never reduced and the site will oversell**. There's no fallback mechanism.

**Recommendation:** Instead of globally disabling, only disable for orders that are successfully synced to Flourish. Add a fallback that reduces WooCommerce stock if the Flourish sync fails.

---

## HIGH - Should Fix Before Go-Live

### 9. Webhook REST route missing `permission_callback`

**File:** `FlourishWebhook.php:39-43`

```php
register_rest_route('flourish-woocommerce-plugin/v1', '/webhook', [
    'methods' => WP_REST_Server::CREATABLE,
    'callback' => [$this, 'handle_webhook'],
    // Missing: 'permission_callback' => '__return_true',
]);
```

WordPress 5.5+ requires `permission_callback`. Without it, every webhook request generates a `_doing_it_wrong` notice in the error log. The HMAC auth inside the callback handles actual security, but this should be declared explicitly.

---

### 10. Inconsistent stock calculation formulas

The stock formula differs across files:

| Location | Formula |
|---|---|
| `FlourishItems.php:215` | `abs(flourish_stock - reserved_stock)` |
| `FlourishWebhook.php:310` | `flourish_stock - reserved_stock` |
| `SettingsPage.php:246` | `abs(inventory_quantity - reserved_stock)` |
| `HandlerOrdersSyncNow.php:272` | `sellable_quantity - reserved_stock` |
| `HandlerOrdersRetail.php:124` | `sellable_quantity` (no reserved stock subtraction) |

**Problem:** `abs()` means if reserved > available, the stock shows as a positive number instead of 0. `HandlerOrdersRetail.php` doesn't subtract reserved stock at all. This inconsistency means stock quantities will differ depending on which code path runs — webhooks vs. manual refresh vs. order creation.

**Fix:** Standardize on `max(0, flourish_stock - reserved_stock)` everywhere.

---

### 11. `wp_cache_flush()` called during product import

**File:** `FlourishItems.php:842`

```php
wp_cache_flush(); // More aggressive cache clearing
```

**Problem:** This flushes the **entire** WordPress object cache (Redis, Memcached, etc.) every time a brand is assigned to a product. During initial import of hundreds of products, this fires hundreds of times, wiping the cache each time. On a production retail site, this will cause severe performance degradation for all visitors during any product sync.

**Fix:** Replace with targeted cache invalidation: `clean_object_term_cache($product_id, $taxonomy)` (which is already called on the line above).

---

### 12. Raw cURL instead of WordPress HTTP API

**File:** `HttpRequestHelper.php`

**Problem:** Uses raw `curl_*` functions instead of WordPress's `wp_remote_get()`/`wp_remote_post()`. This bypasses WordPress's HTTP API which handles:
- Proxy configurations
- SSL certificate verification settings
- Request filtering hooks
- Timeout configurations

If the server has cURL disabled or if the site is behind a proxy, the plugin will fail silently.

---

### 13. `$_GET['action']` accessed without `isset()` check

**File:** `HandlerOrdersSyncNow.php:204`

```php
if ($_GET['action'] === 'trash') {
```

**Problem:** Will throw PHP notice if `action` parameter isn't in the URL. Also has no sanitization.

---

### 14. XSS risk in settings page

**File:** `SettingsPage.php:610-641`

```php
$site_url = $protocol . $_SERVER['HTTP_HOST'];
// ...
echo $site_url;  // Unescaped output
```

**Problem:** `$_SERVER['HTTP_HOST']` is user-controllable. While limited to the admin settings page, it should be escaped with `esc_html()`. Better yet, use `site_url()` which is the WordPress-native way to get the site URL.

---

### 15. Deprecated `FILTER_SANITIZE_STRING`

**File:** `HandlerOrdersRetail.php:236`

```php
$raw_dob = filter_input(INPUT_POST, 'dob', FILTER_SANITIZE_STRING);
```

**Problem:** `FILTER_SANITIZE_STRING` was deprecated in PHP 8.1 and removed in PHP 8.4. If the client's hosting runs PHP 8.1+, this generates deprecation warnings. On PHP 8.4+, it will fatal error.

**Fix:** Replace with `sanitize_text_field(filter_input(INPUT_POST, 'dob'))`.

---

### 16. Update checker points to wrong repository

**File:** `flourish-woocommerce-plugin.php:22-26`

```php
$myUpdateChecker = PucFactory::buildUpdateChecker(
    'https://github.com/flourishsoftware/flourish-woocommerce/',
    __FILE__,
    'flourish-woocommerce-plugin'
);
```

**Problem:** Points to `flourish-woocommerce/` — not the B2B or B2C repo. For the B2C version, this needs to point to the correct repository, or the client will get updates from the wrong source (or none at all).

---

### 17. `WC tested up to: 2.3` is severely outdated

**File:** `flourish-woocommerce-plugin.php:13`

```php
 * WC tested up to: 2.3
```

**Problem:** Current WooCommerce is 9.x. This header will cause WooCommerce to show a compatibility warning in the admin, which may alarm the client.

---

### 18. No CSRF protection on `fetch_inventory_callback`

**File:** `SettingsPage.php:184-266`

The AJAX handler for refreshing inventory doesn't verify a nonce. While it requires an authenticated admin session (`wp_ajax_` hook), WordPress security best practices require nonce verification for all AJAX handlers.

---

## MEDIUM - Should Address

### 19. No `.gitignore` file

Secrets (`.env`), IDE configuration, OS files (`.DS_Store`), and build artifacts could be accidentally committed. The `vendor/` directory is committed (1,800+ files) inflating the repository.

### 20. `License copy.php` legacy file committed

**File:** `src/CustomFields/License copy.php`

A backup/copy file in the source tree. Should be removed.

### 21. Country hardcoded to "United States"

**File:** `HandlerOrdersOutbound.php:147`

```php
'country' => 'United States',
```

If the retail client has any non-US customers, this will be incorrect.

### 22. Excessive `error_log()` calls in production

The codebase has 60+ `error_log()` calls, many logging routine operations (successful imports, stock updates, brand assignments). On a retail site with active inventory webhooks, this will generate large log files and potentially impact performance.

### 23. `HandlerOutboundUpdateCart` registers hooks in constructor

**File:** `HandlerOutboundUpdateCart.php:12-13`

All other classes use a `register_hooks()` method called from `ServiceProvider`. This class calls `register_hooks()` from its constructor, which is inconsistent and could lead to double-registration issues.

### 24. Nonce verification commented out in `restore_stock_on_remove_cart`

**File:** `HandlerOutboundUpdateCart.php:809-811`

```php
if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'woocommerce-cart')) {
    // wp_send_json_error(['error' => 'Invalid nonce.']);
}
```

The nonce check is present but the error response is commented out, making the check useless.

---

## CBD/Hemp Retail-Specific Concerns

### 25. No age gate at storefront level

Most CBD/hemp ecommerce sites implement an age gate popup before the visitor can browse products. The plugin only collects DOB at checkout. Consider adding a configurable age gate feature.

### 26. No FDA/compliance disclaimer support

CBD products typically require disclaimers such as:
- "These statements have not been evaluated by the FDA"
- "This product is not intended to diagnose, treat, cure, or prevent any disease"

The plugin doesn't provide any mechanism for adding compliance notices to product pages or checkout.

### 27. No COA (Certificate of Analysis) integration

Many CBD retailers are required to display COAs for their products. If Flourish stores COA data, the plugin should support displaying it.

### 28. No shipping restriction enforcement

Hemp/CBD shipping is restricted in some states and internationally. The plugin doesn't enforce any shipping restrictions based on product type or destination.

---

## B2B Fixes Not Yet Merged (from open PRs)

### PR #4 (open since Oct 2025) — Email trigger and Facility ID handling
- Completed order email not triggering properly
- Settings page doesn't prompt for API key/Facility ID if missing

### PR #8 (open since Feb 2026) — Backorder stock management fix
- Partially fixes `should_manage_stock()` bug (Critical #1 above)
- Adds proper backorder quantity handling
- JS improvements for stock message display

**Both PRs should be merged before building out the B2C version.**

---

## Architecture Recommendations for B2C

1. **Extract `should_manage_stock()` into a shared trait or helper** — it's duplicated 4 times with identical logic
2. **Add a retry mechanism to `HttpRequestHelper`** — with configurable max retries and exponential backoff
3. **Use WordPress HTTP API** (`wp_remote_*`) instead of raw cURL
4. **Add automated tests** — there are zero tests currently; at minimum, unit tests for stock calculations and webhook authentication
5. **Add a `.gitignore`** and stop committing `vendor/`
6. **Consider separating retail-specific code** — the B2B plugin mixes retail and outbound order handling; the B2C version should strip out all outbound/B2B-specific code to reduce complexity and attack surface
