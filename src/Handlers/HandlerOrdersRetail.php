<?php

namespace FlourishWooCommercePlugin\Handlers;

defined('ABSPATH') || exit;

use FlourishWooCommercePlugin\API\FlourishAPI;
use FlourishWooCommercePlugin\Helpers\HttpRequestHelper;
use FlourishWooCommercePlugin\Helpers\StockHelper;

class HandlerOrdersRetail
{
    public $existing_settings;
    private $current_order_synced = false;

    public function __construct($existing_settings)
    {
        $this->existing_settings = $existing_settings;
    }

    public function register_hooks()
    {
        /**
         * FIX (Critical #8): Instead of globally disabling stock reduction with
         * __return_false, we only disable it for orders that were successfully
         * synced to Flourish. If the sync fails, WooCommerce's native stock
         * reduction acts as a fallback to prevent overselling.
         */
        add_filter('woocommerce_can_reduce_order_stock', [$this, 'conditionally_disable_stock_reduction'], 10, 2);

        add_action('woocommerce_checkout_order_processed', [$this, 'handle_order_retail'], 10, 1);
    }

    /**
     * Only disable WooCommerce stock reduction for orders that were
     * successfully synced to Flourish (which manages its own stock).
     */
    public function conditionally_disable_stock_reduction($can_reduce, $order)
    {
        if ($order && $order->get_meta('flourish_order_id')) {
            return false; // Flourish is managing stock for this order
        }
        return $can_reduce; // Let WooCommerce handle stock as fallback
    }

    /**
     * Handle retail order creation and sync to Flourish.
     *
     * FIX (Critical #4): Uses \Exception throughout.
     * FIX (High #10): Uses StockHelper::calculate_stock() for consistent formula.
     * FIX (High #15): Replaced deprecated FILTER_SANITIZE_STRING with sanitize_text_field().
     */
    public function handle_order_retail($order_id)
    {
        $wc_order = wc_get_order($order_id);
        if (!$wc_order) {
            return;
        }

        if ($wc_order->get_meta('flourish_order_id')) {
            return; // Already synced
        }

        try {
            $flourish_api = $this->initializeFlourishAPI();

            // Get or create customer
            $raw_dob = sanitize_text_field($_POST['dob'] ?? '');
            $customer_data = $this->build_customer_data($wc_order, $raw_dob);
            $customer = $flourish_api->get_or_create_customer_by_email($customer_data);

            // Build retail order
            $order_lines = $this->get_retail_order_lines($wc_order, $flourish_api);

            if (empty($order_lines)) {
                $wc_order->add_order_note('No valid order lines found for Flourish sync. Items may not exist in Flourish.');
                $wc_order->save();
                return;
            }

            $order_payload = [
                'original_order_id' => (string) $wc_order->get_id(),
                'customer_id'       => $customer['flourish_customer_id'],
                'order_lines'       => $order_lines,
                'order_timestamp'   => gmdate("Y-m-d\TH:i:s.v\Z"),
            ];

            $flourish_order_id = $flourish_api->create_retail_order($order_payload);

            // Update stock from Flourish
            $order_items = $this->get_flourish_item_ids_from_order($order_id);
            $this->update_stock_from_flourish($order_items, $flourish_api);

            // Update reserved stock
            $this->update_reserved_stock($wc_order);

            $wc_order->update_meta_data('flourish_order_id', $flourish_order_id);
            $wc_order->add_order_note("Order synced with Flourish. Flourish Order ID: {$flourish_order_id}");
            $wc_order->save();

            $this->current_order_synced = true;

            do_action('flourish_order_retail_created', $wc_order, $flourish_order_id);
        } catch (\Exception $e) {
            wc_get_logger()->error(
                "Error creating retail order: " . $e->getMessage(),
                ['source' => 'flourish-woocommerce-plugin']
            );

            $wc_order->add_order_note("Failed to sync with Flourish: " . $e->getMessage());
            $wc_order->save();

            HttpRequestHelper::send_order_failure_email_to_admin($e->getMessage(), $order_id);
        }
    }

    private function build_customer_data($wc_order, $dob)
    {
        return [
            'first_name' => $wc_order->get_billing_first_name(),
            'last_name'  => $wc_order->get_billing_last_name(),
            'email'      => $wc_order->get_billing_email(),
            'phone'      => $wc_order->get_billing_phone(),
            'dob'        => $dob,
            'address'    => [
                'address1' => $wc_order->get_billing_address_1(),
                'address2' => $wc_order->get_billing_address_2(),
                'city'     => $wc_order->get_billing_city(),
                'state'    => $wc_order->get_billing_state(),
                'zip_code' => $wc_order->get_billing_postcode(),
                'country'  => $wc_order->get_billing_country() ?: 'United States',
            ],
        ];
    }

    /**
     * Build retail order lines, validating items exist in Flourish.
     *
     * Ported from B2B PR #5 fix for mixed CBD/THC orders.
     */
    private function get_retail_order_lines($wc_order, FlourishAPI $flourish_api)
    {
        $order_lines = [];

        foreach ($wc_order->get_items() as $item) {
            $product = $item->get_product();
            if (!$product) {
                continue;
            }

            $flourish_item_id = null;
            $sku = $product->get_sku();

            if ($product->is_type('variation')) {
                $parent_product = wc_get_product($product->get_parent_id());
                if ($parent_product) {
                    $flourish_item_id = $parent_product->get_meta('flourish_item_id');
                    if (empty($sku)) {
                        $sku = $parent_product->get_sku();
                    }
                }
            } else {
                $flourish_item_id = $product->get_meta('flourish_item_id');
            }

            // Validate item exists in Flourish before adding to order
            if (!$flourish_api->flourish_item_exists($flourish_item_id, $sku)) {
                $wc_order->add_order_note(
                    sprintf('Skipped item "%s" (SKU: %s) — not found in Flourish.', $item->get_name(), $sku)
                );
                continue;
            }

            $order_lines[] = [
                'item_id'  => $flourish_item_id,
                'sku'      => $sku,
                'quantity'  => $item->get_quantity(),
                'unit_price' => $item->get_total() / max(1, $item->get_quantity()),
            ];
        }

        return $order_lines;
    }

    private function get_flourish_item_ids_from_order($order_id)
    {
        $wc_order = wc_get_order($order_id);
        if (!$wc_order) {
            return [];
        }

        $items = [];
        foreach ($wc_order->get_items() as $item) {
            $product = $item->get_product();
            if (!$product) {
                continue;
            }

            $parent_id = null;
            $flourish_item_id = null;

            if ($product->is_type('variation')) {
                $parent_id = $product->get_parent_id();
                $parent_product = wc_get_product($parent_id);
                if ($parent_product) {
                    $flourish_item_id = $parent_product->get_meta('flourish_item_id');
                }
            } else {
                $flourish_item_id = $product->get_meta('flourish_item_id');
            }

            $items[] = [
                'product_id'       => $product->get_id(),
                'parent_id'        => $parent_id,
                'flourish_item_id' => $flourish_item_id,
                'quantity'         => $item->get_quantity(),
            ];
        }

        return $items;
    }

    /**
     * FIX (High #10): Uses StockHelper::calculate_stock() for consistent formula.
     * FIX (Critical #1): Uses StockHelper::should_skip_stock_management().
     */
    private function update_stock_from_flourish($order_items, FlourishAPI $flourish_api)
    {
        foreach ($order_items as $item) {
            $flourish_item_id = $item['flourish_item_id'];
            $product_id = $item['parent_id'] ?? $item['product_id'];

            if (!$flourish_item_id || !$product_id) {
                continue;
            }

            try {
                $inventory_data = $flourish_api->fetch_inventory($flourish_item_id);

                foreach ($inventory_data as $inv) {
                    if (empty($inv['sellable_qty'])) {
                        continue;
                    }

                    $wc_product = wc_get_product($product_id);
                    if (!$wc_product || StockHelper::should_skip_stock_management($wc_product)) {
                        continue;
                    }

                    $sellable_quantity = (int) $inv['sellable_qty'];
                    $reserved_stock = (int) get_post_meta($product_id, '_reserved_stock', true);
                    $stock = StockHelper::calculate_stock($sellable_quantity, $reserved_stock);

                    $wc_product->set_manage_stock(true);
                    wc_update_product_stock($wc_product, $stock, 'set');
                    $wc_product->save();
                    wc_delete_product_transients($product_id);
                }
            } catch (\Exception $e) {
                error_log('Error updating stock for product ' . $product_id . ': ' . $e->getMessage());
            }
        }
    }

    private function update_reserved_stock($wc_order)
    {
        foreach ($wc_order->get_items() as $item) {
            $product = $item->get_product();
            if (!$product) {
                continue;
            }

            $product_id = $product->is_type('variation') ? $product->get_parent_id() : $product->get_id();
            $quantity = $item->get_quantity();
            $current_reserved = (int) get_post_meta($product_id, '_reserved_stock', true);
            update_post_meta($product_id, '_reserved_stock', $current_reserved + $quantity);
        }
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
