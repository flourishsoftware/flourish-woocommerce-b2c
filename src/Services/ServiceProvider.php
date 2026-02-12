<?php

namespace FlourishWooCommercePlugin\Services;

defined('ABSPATH') || exit;

use FlourishWooCommercePlugin\Admin\SettingsPage;
use FlourishWooCommercePlugin\Admin\ProductCustomFields;
use FlourishWooCommercePlugin\API\FlourishWebhook;
use FlourishWooCommercePlugin\CustomFields\DateOfBirth;
use FlourishWooCommercePlugin\CustomFields\FlourishOrderID;
use FlourishWooCommercePlugin\Handlers\HandlerOrdersRetail;
use FlourishWooCommercePlugin\Handlers\HandlerOrdersCancel;
use FlourishWooCommercePlugin\Handlers\HandlerOrdersSyncNow;

/**
 * Registers all plugin services and hooks.
 *
 * Simplified from B2B version — no outbound handlers, license fields,
 * draft order management, or cart reservation features.
 */
class ServiceProvider
{
    public function register()
    {
        $existing_settings = get_option('flourish_woocommerce_plugin_settings', []);
        $plugin_basename = plugin_basename(dirname(dirname(__DIR__)) . '/flourish-woocommerce-plugin.php');

        // Admin settings page
        $settings_page = new SettingsPage($existing_settings, $plugin_basename);
        $settings_page->register_hooks();

        // Product custom fields
        $product_custom_fields = new ProductCustomFields($existing_settings);
        $product_custom_fields->register_hooks();

        // Webhook handler
        $webhook = new FlourishWebhook($existing_settings);
        $webhook->register_hooks();

        // Date of Birth field
        $dob = new DateOfBirth($existing_settings);
        $dob->register_hooks();

        // Flourish Order ID display
        $flourish_order_id = new FlourishOrderID();
        $flourish_order_id->register_hooks();

        // Retail order handler
        $handler_retail = new HandlerOrdersRetail($existing_settings);
        $handler_retail->register_hooks();

        // Order cancellation handler
        $handler_cancel = new HandlerOrdersCancel($existing_settings);
        $handler_cancel->register_hooks();

        // Sync Now action
        $handler_sync = new HandlerOrdersSyncNow($existing_settings);
        $handler_sync->register_hooks();
    }
}
