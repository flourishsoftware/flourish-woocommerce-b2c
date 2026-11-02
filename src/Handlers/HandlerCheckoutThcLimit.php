<?php

namespace FlourishWooCommercePlugin\Handlers;

defined('ABSPATH') || exit;

use FlourishWooCommercePlugin\API\FlourishAPI;

/**
 * Blocks checkout when the order's THC exceeds the per-order limit set in
 * the plugin settings (for example Georgia's 12,000 mg).
 *
 * Only per-order limits are covered. Rolling-window and category limits need
 * Flourish's purchase-limit engine, which the external API doesn't expose
 * yet (FEAT1-17095). When it does, this check should read the result from
 * the retail order quote instead.
 *
 * THC per unit, in order of preference:
 * 1. The "THC mg per unit" product field, set by the store.
 * 2. The item's thc_per_unit in Flourish. POS uses this for lines with no
 *    package assigned.
 * 3. Lab results: the highest THC across packages in stock, using each
 *    package's lab result or the item's primary result when it has none.
 *    The package that ships isn't known at checkout, so take the highest.
 * A cannabis item with none of these can't be checked, so it's blocked.
 */
class HandlerCheckoutThcLimit
{
    const DEFAULT_CANNABINOID = 'THC';

    private $existing_settings;

    public function __construct($existing_settings)
    {
        $this->existing_settings = $existing_settings;
    }

    public function register_hooks()
    {
        if ($this->get_limit_mg() <= 0) {
            return;
        }

        // Cart page and classic checkout
        add_action('woocommerce_check_cart_items', [$this, 'validate_cart']);

        // Block-based checkout. Fires before the gateway processes payment.
        add_action('woocommerce_store_api_checkout_order_processed', [$this, 'validate_block_checkout'], 5, 1);
    }

    public function validate_cart()
    {
        $lines = [];
        foreach (WC()->cart->get_cart() as $cart_item) {
            $lines[] = [$cart_item['data'], (float) $cart_item['quantity']];
        }

        foreach ($this->get_violations($lines) as $message) {
            wc_add_notice($message, 'error');
        }
    }

    public function validate_block_checkout($wc_order)
    {
        $lines = [];
        foreach ($wc_order->get_items() as $item) {
            $lines[] = [$item->get_product(), (float) $item->get_quantity()];
        }

        $violations = $this->get_violations($lines);
        if (empty($violations)) {
            return;
        }

        $message = implode(' ', $violations);
        if (class_exists('\Automattic\WooCommerce\StoreApi\Exceptions\RouteException')) {
            throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException('flourish_thc_limit', $message, 400);
        }
        throw new \Exception($message);
    }

    /**
     * @param array $lines [[WC_Product, quantity], ...]
     * @return string[]
     */
    private function get_violations($lines)
    {
        $limit = $this->get_limit_mg();
        $total = 0.0;
        $violations = [];

        foreach ($lines as [$product, $quantity]) {
            if (!$product) {
                continue;
            }

            $thc = $this->get_thc_mg_per_unit($product);
            if ($thc['mg'] === null) {
                if ($thc['cannabis']) {
                    $violations[] = sprintf(
                        \__("THC content for %s isn't available, so it can't be ordered online right now. Please contact the store.", 'flourish-woocommerce'),
                        $product->get_name()
                    );
                }
                continue;
            }

            $total += $thc['mg'] * $quantity;
        }

        if ($total > $limit) {
            $violations[] = sprintf(
                \__('Your order has %1$s mg of THC. Orders are limited to %2$s mg of THC.', 'flourish-woocommerce'),
                number_format($total, 2),
                number_format($limit)
            );
        }

        return $violations;
    }

    /**
     * @return array ['mg' => float|null, 'cannabis' => bool]
     */
    private function get_thc_mg_per_unit($product)
    {
        // A variation is the same Flourish item as its parent, but the store
        // may set its own THC value on it.
        $override = $product->get_meta('_thc_mg_per_unit');
        $item_product = $product;
        if ($product->is_type('variation')) {
            $parent = wc_get_product($product->get_parent_id());
            if ($parent) {
                $item_product = $parent;
                if ($override === '') {
                    $override = $parent->get_meta('_thc_mg_per_unit');
                }
            }
        }

        if (is_numeric($override)) {
            return ['mg' => (float) $override, 'cannabis' => true];
        }

        $flourish_item_id = $item_product->get_meta('flourish_item_id');
        if (empty($flourish_item_id)) {
            return ['mg' => null, 'cannabis' => false];
        }

        $transient = 'flourish_thc_' . md5($this->get_account_key() . '|' . $this->get_cannabinoid_name()) . '_' . (int) $flourish_item_id;
        $cached = get_transient($transient);
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $thc = $this->fetch_thc_mg_per_unit($flourish_item_id);
        } catch (\Exception $e) {
            wc_get_logger()->error(
                "Could not fetch THC for Flourish item {$flourish_item_id}: " . $e->getMessage(),
                ['source' => 'flourish-woocommerce-plugin']
            );

            // Use the last value fetched and wait 5 minutes before retrying,
            // so an outage doesn't slow every cart page.
            $last = $item_product->get_meta('flourish_thc_mg_per_unit');
            $thc = [
                'mg'       => is_numeric($last) ? (float) $last : null,
                'cannabis' => $item_product->get_meta('flourish_inventory_type') === 'Cannabis',
            ];
            set_transient($transient, $thc, 5 * MINUTE_IN_SECONDS);

            return $thc;
        }

        // Lab results and stock change, so refresh hourly.
        set_transient($transient, $thc, HOUR_IN_SECONDS);
        $item_product->update_meta_data('flourish_thc_mg_per_unit', $thc['mg'] ?? '');
        $item_product->update_meta_data('flourish_inventory_type', $thc['cannabis'] ? 'Cannabis' : '');
        $item_product->save_meta_data();

        return $thc;
    }

    private function fetch_thc_mg_per_unit($flourish_item_id)
    {
        $flourish_api = $this->initializeFlourishAPI();

        $item = $flourish_api->fetch_item($flourish_item_id);
        $cannabis = ($item['inventory_type'] ?? '') === 'Cannabis';

        $mg = $this->thc_per_unit_to_mg($item['thc_per_unit'] ?? null, $item['thc_per_unit_uom'] ?? '');
        if ($mg !== null) {
            return ['mg' => $mg, 'cannabis' => $cannabis];
        }

        $lab_results = $flourish_api->fetch_item_potency_summary($flourish_item_id);
        if (empty($lab_results)) {
            return ['mg' => null, 'cannabis' => $cannabis];
        }

        $mg_by_result = [];
        $primary_mg = null;
        foreach ($lab_results as $lab_result) {
            $result_mg = $this->get_cannabinoid_mg($lab_result);
            $mg_by_result[(string) $lab_result['lab_test_result_id']] = $result_mg;
            if (!empty($lab_result['is_primary'])) {
                $primary_mg = $result_mg;
            }
        }

        $candidates = [];
        foreach ($flourish_api->fetch_available_lab_result_ids($flourish_item_id) as $lab_result_id) {
            $candidates[] = $mg_by_result[(string) $lab_result_id] ?? $primary_mg;
        }
        if (empty($candidates)) {
            $candidates[] = $primary_mg;
        }

        $candidates = array_filter($candidates, function ($mg) {
            return $mg !== null;
        });

        return ['mg' => $candidates ? max($candidates) : null, 'cannabis' => $cannabis];
    }

    private function thc_per_unit_to_mg($amount, $uom)
    {
        if (!is_numeric($amount) || $amount <= 0) {
            return null;
        }

        switch (strtolower((string) $uom)) {
            case 'mg':
                return (float) $amount;
            case 'g':
                return (float) $amount * 1000;
            default:
                return null;
        }
    }

    /**
     * mg per package of the configured cannabinoid in one lab result.
     */
    private function get_cannabinoid_mg($lab_result)
    {
        $name = $this->get_cannabinoid_name();

        foreach ($lab_result['cannabinoids'] ?? [] as $cannabinoid) {
            if (strcasecmp($cannabinoid['name'] ?? '', $name) === 0 && is_numeric($cannabinoid['mg_per_package'] ?? null)) {
                return (float) $cannabinoid['mg_per_package'];
            }
        }

        return null;
    }

    private function get_cannabinoid_name()
    {
        $name = $this->existing_settings['thc_limit_cannabinoid'] ?? '';

        return $name !== '' ? $name : self::DEFAULT_CANNABINOID;
    }

    private function get_limit_mg()
    {
        return (float) ($this->existing_settings['thc_limit_mg'] ?? 0);
    }

    private function get_account_key()
    {
        return md5(($this->existing_settings['url'] ?? '') . '|' . ($this->existing_settings['api_key'] ?? ''));
    }

    private function initializeFlourishAPI()
    {
        $settingsHandler = new SettingsHandler($this->existing_settings);
        return new FlourishAPI(
            $settingsHandler->getSetting('api_key'),
            $settingsHandler->getSetting('url'),
            $settingsHandler->getSetting('facility_id'),
            $this->existing_settings['item_sync_options'] ?? []
        );
    }
}
