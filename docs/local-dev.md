# Local development

How to run this plugin in a local WordPress + WooCommerce site pointed at a Flourish test account (the shared integrator testing account or a demo company) on production, the same URL a client uses. It uses [Local](https://localwp.com/) (by Flywheel) and wp-cli. You don't need Docker.

## Prerequisites

- Local 10.x
- wp-cli: `brew install wp-cli`
- composer: `brew install composer`, only if `vendor/` is missing
- An API key and facility for a Flourish **test** account on production (integrator testing or a demo company, never a live client). Never commit keys. They live only in the site's database.

## 1. Site

1. In Local, create a site, for example `b2c-testing` (PHP 8.2, MySQL 8, nginx), or start an existing one.
2. Install WooCommerce:

   ```bash
   bin/local-wp.sh b2c-testing plugin install woocommerce --activate
   ```

`bin/local-wp.sh <site> <args>` runs wp-cli with the site's own PHP and MySQL socket, so it works from any terminal while the site is running in Local. The site name can be Local's site name, its folder name or its domain.

> Local can auto-update and restart itself, which stops every site. If wp-cli reports a missing MySQL socket, start the site again in Local.

## 2. Link the plugin

Symlink the repo into the site so edits show up live:

```bash
PLUGINS="$HOME/Local Sites/b2c-testing/app/public/wp-content/plugins"
ln -s "$(pwd)" "$PLUGINS/flourish-woocommerce-b2c"
[ -d vendor ] || composer install
bin/local-wp.sh b2c-testing plugin activate flourish-woocommerce-b2c
```

If a copy of the plugin is already in `plugins/`, move it out of `plugins/` first rather than deleting it.

## 3. Point the plugin at your Flourish test account

Enter the API key without leaving it in shell history:

```bash
read -rs FLOURISH_API_KEY
bin/local-wp.sh b2c-testing option patch update flourish_woocommerce_plugin_settings api_key "$FLOURISH_API_KEY"
unset FLOURISH_API_KEY
```

If `option patch update` says the key doesn't exist yet, use `option patch insert`. This applies to each setting below too.

```bash
W="bin/local-wp.sh b2c-testing"
$W option patch update flourish_woocommerce_plugin_settings url https://app.flourishsoftware.com
$W option patch insert flourish_woocommerce_plugin_settings shipping_fulfillment_type "postal shipping"
```

Then go to **Settings → Flourish B2C** in wp-admin, pick the facility and save. You can also set `facility_id` the same way as the other settings.

Import items so the cart has products that exist in that facility: **Settings → Flourish B2C → Import Products → Fetch Inventory from Flourish**.

## 4. Shipping and checkout

Zone 1 is the United States. It needs flat rate, which is synced as the configured type (Postal Shipping here), and local pickup, which is synced as Pickup:

```bash
W="bin/local-wp.sh b2c-testing"
$W wc shipping_zone_method create 1 --method_id=flat_rate --user=1   # set a cost in wp-admin if you want one
$W wc shipping_zone_method create 1 --method_id=local_pickup --user=1
# Block checkout shows pickup through Local Pickup locations
$W option update woocommerce_pickup_location_settings '{"enabled":"yes","title":"Pickup","tax_status":"taxable","cost":""}' --format=json
$W option update pickup_location_pickup_locations '[{"name":"Store pickup","address":{"address_1":"420 Main St","city":"Venice","state":"CA","postcode":"90291","country":"US"},"details":"","enabled":true}]' --format=json
```

Set up both checkouts:

- **Classic:** the Checkout page (`/checkout/`) holds the `[woocommerce_checkout]` shortcode.
- **Block:** a second page, `/block-checkout/`:

  ```bash
  bin/local-wp.sh b2c-testing post create --post_type=page --post_status=publish \
    --post_title="Block Checkout" --post_name=block-checkout \
    --post_content='<!-- wp:woocommerce/checkout --><div class="wp-block-woocommerce-checkout alignwide wc-block-checkout is-loading"></div><!-- /wp:woocommerce/checkout -->'
  ```

WooCommerce treats only the page set in `woocommerce_checkout_page_id` as the checkout, and the plugin's date of birth script loads only there. Point it at the page you're testing:

```bash
W="bin/local-wp.sh b2c-testing"
$W option update woocommerce_checkout_page_id "$($W post list --post_type=page --name=block-checkout --field=ID)"  # block
$W option update woocommerce_checkout_page_id "$($W post list --post_type=page --name=checkout --field=ID)"        # classic
```

Turn off WooCommerce's "Coming soon" mode, or logged-out shoppers can't reach the store: `bin/local-wp.sh b2c-testing option update woocommerce_coming_soon no`.

Enable a gateway that doesn't take real payment, such as Cash on Delivery:

```bash
bin/local-wp.sh b2c-testing wc payment_gateway update cod --enabled=true --user=1
```

## 5. Seeing what was sent to Flourish (optional)

To log the customer and order request and response bodies, drop this mu-plugin into the site (not the repo). It never logs headers, so the API key stays out of the log:

`wp-content/mu-plugins/flourish-http-log.php`

```php
<?php
add_action('http_api_debug', function ($response, $context, $class, $args, $url) {
    if (strpos($url, '/external/api/') === false || !preg_match('#/(customers|retail-orders)#', $url)) {
        return;
    }
    file_put_contents(WP_CONTENT_DIR . '/flourish-http.log', wp_json_encode([
        'time'     => gmdate('c'),
        'method'   => $args['method'] ?? 'GET',
        'url'      => $url,
        'request'  => isset($args['body']) ? (json_decode($args['body'], true) ?? $args['body']) : null,
        'status'   => is_wp_error($response) ? $response->get_error_message() : wp_remote_retrieve_response_code($response),
        'response' => is_wp_error($response) ? null : json_decode(wp_remote_retrieve_body($response), true),
    ]) . "\n", FILE_APPEND);
}, 10, 5);
```

Plugin errors also go to **WooCommerce → Status → Logs** (source `flourish-woocommerce-plugin`), and order sync results are added as order notes.
