<?php

namespace FlourishWooCommercePlugin\Handlers;

defined('ABSPATH') || exit;

use FlourishWooCommercePlugin\API\FlourishAPI;
use FlourishWooCommercePlugin\Helpers\StockHelper;

/**
 * Handles order cancellation and stock restoration for retail orders.
 *
 * Simplified from B2B version: removed outbound order handling.
 */
class HandlerOrdersCancel
{
    public $existing_settings;

    public function __construct($existing_settings)
    {
        $this->existing_settings = $existing_settings;
    }

    public function register_hooks()
    {
        add_action('woocommerce_order_status_cancelled', [$this, 'handle_order_cancelled'], 10, 1);
        add_action('wp_trash_post', [$this, 'handle_order_trashed'], 10, 1);
    }

    /**
     * Handle order cancellation — cancel in Flourish and restore stock.
     *
     * FIX (Critical #4): Uses \Exception throughout.
     */
    public function handle_order_cancelled($order_id)
    {
        $wc_order = wc_get_order($order_id);
        if (!$wc_order) {
            return;
        }

        $flourish_order_id = $wc_order->get_meta('flourish_order_id');

        if (empty($flourish_order_id)) {
            // Order was never synced to Flourish — just restore local stock
            if (!$wc_order->get_meta('_stock_adjusted')) {
                $this->adjust_stock($wc_order, 'increase');
                $wc_order->update_meta_data('_stock_adjusted', true);
                $wc_order->save();
            }
            return;
        }

        try {
            $flourish_api = $this->initializeFlourishAPI();
            $order_data = $flourish_api->get_order_by_id($flourish_order_id, 'retail-orders');
            $order_status = $order_data['order_status'] ?? null;

            if ($order_status === 'Created') {
                $cancel_payload = [
                    'original_order_id' => (string) $wc_order->get_id(),
                    'order_timestamp'   => gmdate("Y-m-d\TH:i:s.v\Z"),
                    'order_status'      => 'Cancelled',
                ];

                $flourish_api->update_retail_order($cancel_payload, $flourish_order_id);
                $wc_order->add_order_note("Order cancelled in Flourish.");
            } else {
                $wc_order->add_order_note("Cannot cancel in Flourish — order status is: {$order_status}");
            }

            // Refresh stock from Flourish
            $order_items = $this->get_flourish_item_ids_from_order($order_id);
            $this->refresh_stock_from_flourish($order_items, $flourish_api);

            $wc_order->save();
        } catch (\Exception $e) {
            error_log('Error cancelling order in Flourish: ' . $e->getMessage());
            $wc_order->add_order_note("Error cancelling in Flourish: " . $e->getMessage());
            $wc_order->save();
        }
    }

    /**
     * Handle order trashed from admin.
     *
     * FIX (High #13): Added isset() check for $_GET['action'] to prevent
     * PHP notice when the parameter is missing.
     */
    public function handle_order_trashed($post_id)
    {
        if ('shop_order' !== get_post_type($post_id)) {
            return;
        }

        if (!isset($_GET['action']) || $_GET['action'] !== 'trash') {
            return;
        }

        $wc_order = wc_get_order($post_id);
        if ($wc_order) {
            $this->handle_order_cancelled($post_id);
        }
    }

    /**
     * Adjust local stock when an order is cancelled (no Flourish sync).
     *
     * FIX (Critical #1): Uses StockHelper::should_skip_stock_management().
     */
    private function adjust_stock($order, $action = 'increase')
    {
        foreach ($order->get_items() as $item) {
            $product_id = $item->get_product_id();
            $product = wc_get_product($product_id);

            if (!$product || StockHelper::should_skip_stock_management($product)) {
                continue;
            }

            $quantity = $item->get_quantity();
            $current_stock = max(0, (int) $product->get_stock_quantity());

            if ($action === 'increase') {
                $new_stock = $current_stock + $quantity;
                // Decrease reserved stock
                $reserved = (int) get_post_meta($product_id, '_reserved_stock', true);
                update_post_meta($product_id, '_reserved_stock', max(0, $reserved - $quantity));
            } else {
                $new_stock = max(0, $current_stock - $quantity);
            }

            $product->set_stock_quantity($new_stock);
            $product->save();
        }
    }

    private function refresh_stock_from_flourish($order_items, FlourishAPI $flourish_api)
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
                    if (!isset($inv['sellable_qty'])) {
                        continue;
                    }

                    $wc_product = wc_get_product($product_id);
                    if (!$wc_product || StockHelper::should_skip_stock_management($wc_product)) {
                        continue;
                    }

                    $sellable = (int) $inv['sellable_qty'];
                    $reserved = (int) get_post_meta($product_id, '_reserved_stock', true);
                    $stock = StockHelper::calculate_stock($sellable, $reserved);

                    $wc_product->set_manage_stock(true);
                    wc_update_product_stock($wc_product, $stock, 'set');
                    $wc_product->save();
                    wc_delete_product_transients($product_id);
                }
            } catch (\Exception $e) {
                error_log('Error refreshing stock for product ' . $product_id . ': ' . $e->getMessage());
            }
        }
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
