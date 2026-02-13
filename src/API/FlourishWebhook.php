<?php

namespace FlourishWooCommercePlugin\API;

defined('ABSPATH') || exit;

use FlourishWooCommercePlugin\Importer\FlourishItems;
use FlourishWooCommercePlugin\Helpers\StockHelper;
use WP_REST_Request;
use WP_REST_Server;
use WP_REST_Response;

class FlourishWebhook
{
    public $existing_settings;

    public function __construct($existing_settings)
    {
        $this->existing_settings = $existing_settings;
    }

    /**
     * Authenticates the webhook request using HMAC SHA-256.
     *
     * FIX (High #25): Added check for empty webhook_key to prevent
     * authentication bypass with empty signatures.
     */
    public function authenticate($request_body, $signature)
    {
        $webhook_key = $this->existing_settings['webhook_key'] ?? '';
        if (empty($webhook_key) || empty($signature)) {
            return false;
        }

        return hash_equals(
            hash_hmac('sha256', $request_body, $webhook_key),
            $signature
        );
    }

    /**
     * Registers the REST API route for handling Flourish webhooks.
     *
     * FIX (High #9): Added permission_callback to suppress WordPress 5.5+ warnings.
     * Actual authentication is done via HMAC inside the callback.
     */
    public function register_hooks()
    {
        add_action('rest_api_init', function () {
            register_rest_route('flourish-woocommerce-plugin/v1', '/webhook', [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'handle_webhook'],
                'permission_callback' => '__return_true',
            ]);
        });
    }

    /**
     * Handles incoming webhook requests.
     */
    public function handle_webhook(WP_REST_Request $request)
    {
        $body = $request->get_body();
        $headers = $request->get_headers();

        if (empty($headers['auth_signature'][0])) {
            wc_get_logger()->error(
                "Missing authentication signature in webhook",
                ['source' => 'flourish-woocommerce-plugin']
            );
            return new WP_REST_Response(['message' => 'Missing authentication signature.'], 400);
        }

        if (!$this->authenticate($body, $headers['auth_signature'][0])) {
            wc_get_logger()->error(
                "Invalid authentication signature in webhook",
                ['source' => 'flourish-woocommerce-plugin']
            );
            return new WP_REST_Response(['message' => 'Invalid authentication signature.'], 403);
        }

        $decode_data = json_decode($body, true);

        if (!is_array($decode_data) || empty($decode_data['resource_type']) || empty($decode_data['data'])) {
            $this->log_webhook_result('failure', $decode_data ?? [], 'Invalid payload structure');
            return new WP_REST_Response(['message' => 'Invalid payload.'], 400);
        }

        switch ($decode_data['resource_type']) {
            case 'item':
                $response = $this->handle_item($decode_data['data']);
                break;
            case 'retail_order':
                $response = $this->handle_retail_order($decode_data['data']);
                break;
            case 'order':
                $response = $this->handle_order($decode_data['data']);
                break;
            case 'inventory_summary':
                $response = $this->handle_inventory_summary($decode_data['data']);
                break;
            default:
                $this->log_webhook_result('failure', $decode_data, 'Unknown resource type');
                return new WP_REST_Response(['message' => 'Unknown resource type.'], 400);
        }

        if ($response instanceof WP_REST_Response && $response->get_status() !== 200) {
            $this->log_webhook_result('failure', $decode_data, 'Resource handling failed');
            return $response;
        }

        return new WP_REST_Response(['message' => 'Webhook processed successfully.'], 200);
    }

    /**
     * Log webhook result.
     *
     * FIX (Critical #2): REMOVED the call to handle_item() that was inside this
     * method. The original code called $this->handle_item($payload['data']) here,
     * causing every item webhook to be processed TWICE — once in handle_webhook()
     * and again in log_webhook_result(). This caused race conditions, double API
     * calls, and confusing stock updates.
     */
    private function log_webhook_result($status, $payload, $error = null)
    {
        $log_data = [
            'timestamp' => current_time('mysql'),
            'status'    => $status,
            'error'     => $error,
        ];

        $logger = wc_get_logger();
        $log_message = print_r($log_data, true);
        $context = ['source' => 'flourish-webhook'];

        if ($status === 'success') {
            $logger->info($log_message, $context);
        } else {
            $logger->error($log_message, $context);
        }
    }

    /**
     * Handles the 'item' resource type.
     *
     * FIX (Critical #4): Changed catch(Exception) to catch(\Exception) so
     * exceptions are actually caught in namespaced code.
     */
    private function handle_item($data)
    {
        if (empty($data['ecommerce_active'])) {
            return new WP_REST_Response(['message' => 'Item is not eCommerce active. Not handling.'], 200);
        }

        if (empty($data['sku'])) {
            return new WP_REST_Response(['message' => 'Item does not have a SKU. Not handling.'], 200);
        }

        $brands = $this->existing_settings['brands'] ?? [];
        $filter_brands = $this->existing_settings['filter_brands'] ?? false;
        if ($filter_brands && !in_array($data['brand'], $brands)) {
            return new WP_REST_Response(['message' => 'Item does not match brand filter. Not handling.'], 200);
        }

        try {
            $flourish_api = new FlourishAPI(
                $this->existing_settings['api_key'] ?? '',
                $this->existing_settings['url'] ?? '',
                $this->existing_settings['facility_id'] ?? ''
            );

            $inventory_records = $flourish_api->fetch_inventory($data['id']);
            $inventory_quantity = 0;

            foreach ($inventory_records as $inventory) {
                if ($inventory['sku'] === $data['sku']) {
                    $inventory_quantity = $inventory['sellable_qty'];
                    break;
                }
            }

            $data['inventory_quantity'] = $inventory_quantity;
            $items = [$data];
            $item_sync_options = $this->existing_settings['item_sync_options'] ?? [];

            $flourish_items = new FlourishItems($items);
            $webhook_status = true;
            $flourish_items->save_as_woocommerce_products($item_sync_options, $webhook_status);

            return new WP_REST_Response(['message' => 'Item handled successfully.'], 200);
        } catch (\Exception $e) {
            error_log('Error handling item webhook: ' . $e->getMessage());
            return new WP_REST_Response(['message' => 'Error processing item: ' . $e->getMessage()], 500);
        }
    }

    private function handle_retail_order($data)
    {
        $wc_order = wc_get_order($data['original_order_id']);
        $post_id = $data['original_order_id'];

        if (!$wc_order) {
            return new WP_REST_Response(['message' => 'Retail order not found.'], 404);
        }

        $new_status = 'created';
        switch ($data['order_status']) {
            case 'Packed':
            case 'Out for Delivery':
            case 'Completed':
                $new_status = 'completed';
                break;
            case 'Cancelled':
                $new_status = 'cancelled';
                break;
        }

        $this->sync_stock_from_flourish($wc_order, $post_id);
        $wc_order->update_status($new_status, 'Flourish retail order status: ' . $data['order_status'] . '. Updated by webhook.');

        return new WP_REST_Response(['message' => 'Retail order handled successfully.'], 200);
    }

    private function handle_order($data)
    {
        $wc_order = wc_get_order($data['original_order_id']);
        $post_id = $data['original_order_id'];

        if (!$wc_order) {
            return new WP_REST_Response(['message' => 'Order not found.'], 404);
        }

        $new_status = 'created';
        switch ($data['order_status']) {
            case 'Shipped':
            case 'Completed':
                $new_status = 'completed';
                break;
            case 'Cancelled':
                $new_status = 'cancelled';
                break;
        }

        $this->sync_stock_from_flourish($wc_order, $post_id);
        $wc_order->update_status($new_status, 'Flourish order status: ' . $data['order_status'] . '. Updated by webhook.');

        return new WP_REST_Response(['message' => 'Order handled successfully.'], 200);
    }

    /**
     * Handles the 'inventory_summary' resource type.
     *
     * FIX (High #10): Uses StockHelper::calculate_stock() for consistent formula.
     */
    private function handle_inventory_summary($data)
    {
        $brands = $this->existing_settings['brands'] ?? [];
        $filter_brands = $this->existing_settings['filter_brands'] ?? false;
        if ($filter_brands && !in_array($data['brand'], $brands)) {
            return new WP_REST_Response(['message' => 'Item does not match brand filter. Not handling.'], 200);
        }

        $wc_product = wc_get_products([
            'sku'   => $data['sku'],
            'limit' => 1,
        ]);

        if (empty($wc_product)) {
            return new WP_REST_Response(['message' => 'Product not found.'], 404);
        }

        $wc_product = $wc_product[0];
        $product_id = $wc_product->get_id();
        $reserved_stock = (int) get_post_meta($product_id, '_reserved_stock', true);
        $flourish_stock = (int) ($data['sellable_qty'] ?? 0);

        $woocommerce_stock = StockHelper::calculate_stock($flourish_stock, $reserved_stock);

        $wc_product->set_stock_quantity($woocommerce_stock);
        $wc_product->save();

        return new WP_REST_Response(['message' => 'Inventory summary handled successfully.'], 200);
    }

    /**
     * Sync stock from Flourish after order status changes via webhook.
     *
     * FIX (Critical #4): Uses \Exception instead of Exception.
     * FIX (Critical #1): Uses StockHelper::should_skip_stock_management().
     * FIX (High #10): Uses StockHelper::calculate_stock().
     */
    private function sync_stock_from_flourish($wc_order, $post_id)
    {
        if (!$wc_order) {
            return;
        }

        $flourish_order_id = $wc_order->get_meta('flourish_order_id');
        if (empty($flourish_order_id)) {
            return;
        }

        $order_items = self::get_flourish_item_ids_from_order($post_id);
        $this->order_stock_update($order_items);
    }

    public static function get_flourish_item_ids_from_order($order_id)
    {
        $wc_order = wc_get_order($order_id);
        if (!$wc_order) {
            return [];
        }

        $flourish_items = [];

        foreach ($wc_order->get_items() as $item) {
            $product = $item->get_product();
            if (!$product) {
                continue;
            }

            $flourish_item_id = null;
            $parent_id = null;

            if ($product->is_type('variation')) {
                $parent_id = $product->get_parent_id();
                $parent_product = wc_get_product($parent_id);
                if ($parent_product) {
                    $flourish_item_id = $parent_product->get_meta('flourish_item_id');
                }
            } else {
                $flourish_item_id = $product->get_meta('flourish_item_id');
            }

            $flourish_items[] = [
                'product_id'       => $product->get_id(),
                'parent_id'        => $parent_id,
                'flourish_item_id' => $flourish_item_id,
                'quantity'         => $item->get_quantity(),
            ];
        }

        return $flourish_items;
    }

    /**
     * Update stock for order items from Flourish inventory.
     *
     * FIX (Critical #1): Uses StockHelper::should_skip_stock_management().
     * FIX (Critical #4): Uses \Exception.
     * FIX (High #10): Uses StockHelper::calculate_stock().
     */
    public function order_stock_update($order_items)
    {
        foreach ($order_items as $item) {
            $flourish_item_id = $item['flourish_item_id'];
            $product_id = $item['parent_id'] ?? $item['product_id'];

            if (!$flourish_item_id || !$product_id) {
                continue;
            }

            try {
                $settings = $this->existing_settings;
                $flourish_api = new FlourishAPI(
                    $settings['api_key'] ?? '',
                    $settings['url'] ?? '',
                    $settings['facility_id'] ?? ''
                );

                $inventory_data = $flourish_api->fetch_inventory($flourish_item_id);

                foreach ($inventory_data as $inv) {
                    if (!isset($inv['sellable_qty'])) {
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
                continue;
            }
        }

        return true;
    }
}
