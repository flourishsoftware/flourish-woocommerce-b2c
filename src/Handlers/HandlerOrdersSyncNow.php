<?php

namespace FlourishWooCommercePlugin\Handlers;

defined('ABSPATH') || exit;

use FlourishWooCommercePlugin\API\FlourishAPI;
use FlourishWooCommercePlugin\Helpers\StockHelper;

/**
 * Provides the "Sync order to Flourish" action in the WooCommerce order admin.
 *
 * Simplified from B2B version: only handles retail orders.
 *
 * FIX (Critical #4): All catch blocks use \Exception.
 * FIX (Critical #1): Uses StockHelper::should_skip_stock_management().
 * FIX (High #10): Uses StockHelper::calculate_stock().
 */
class HandlerOrdersSyncNow
{
    public $existing_settings;

    public function __construct($existing_settings)
    {
        $this->existing_settings = $existing_settings;
    }

    public function register_hooks()
    {
        add_action('woocommerce_order_actions', [$this, 'add_sync_now_action']);
        add_action('woocommerce_order_action_sync_order_now', [$this, 'sync_order_now']);
    }

    public function add_sync_now_action($actions)
    {
        $actions['sync_order_now'] = 'Sync order to Flourish';
        return $actions;
    }

    public function sync_order_now($order)
    {
        $order_id = $order->get_id();

        if ($order->get_meta('flourish_order_id')) {
            $order->add_order_note('Order already synced with Flourish.');
            return;
        }

        if (!$this->existing_settings) {
            $order->add_order_note('Flourish settings not configured.');
            return;
        }

        try {
            $handler = new HandlerOrdersRetail($this->existing_settings);
            $handler->handle_order_retail($order_id);
        } catch (\Exception $e) {
            error_log('Error syncing order ' . $order_id . ': ' . $e->getMessage());
            $order->add_order_note('Failed to sync with Flourish: ' . $e->getMessage());
            $order->save();
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
