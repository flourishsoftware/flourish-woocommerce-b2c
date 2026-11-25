<?php

namespace FlourishWooCommercePlugin\Handlers;

defined('ABSPATH') || exit;

use FlourishWooCommercePlugin\API\FlourishAPI;
use FlourishWooCommercePlugin\Helpers\AddressHelper;

/**
 * Blocks a Postal Shipping checkout whose shipping address is not on the
 * customer's Flourish record.
 *
 * Postal orders ship only to the address on the customer's state ID, kept in
 * Flourish as an active shipping address. An existing customer must ship to
 * that address; one with no address on file is blocked too, until the store
 * adds it. A new customer (no Flourish record for the email) is allowed: the
 * order creates them with the checkout address (HandlerOrdersRetail).
 *
 * Blocking here, before the gateway runs, keeps an AeroPay hold off an order
 * that could not be sent to Flourish. If Flourish can't be reached, checkout
 * is allowed and the order sync applies the same check.
 */
class HandlerCheckoutPostalAddress
{
    public $existing_settings;

    public function __construct($existing_settings)
    {
        $this->existing_settings = $existing_settings;
    }

    public function register_hooks()
    {
        if (($this->existing_settings['shipping_fulfillment_type'] ?? 'delivery') !== 'postal shipping') {
            return;
        }

        // Classic checkout
        add_action('woocommerce_after_checkout_validation', [$this, 'validate_classic_checkout'], 10, 2);

        // Block-based checkout. Fires before the gateway processes payment;
        // runs ahead of HandlerOrdersRetail (priority 10).
        add_action('woocommerce_store_api_checkout_order_processed', [$this, 'validate_block_checkout'], 5, 1);
    }

    public function validate_classic_checkout($data, $errors)
    {
        $method_ids = (array) ($data['shipping_method'] ?? []);
        if (!$this->is_postal($method_ids)) {
            return;
        }

        $message = $this->get_violation($data['billing_email'] ?? '', AddressHelper::from_checkout_data($data));
        if ($message) {
            $errors->add('flourish_postal_address', $message);
        }
    }

    public function validate_block_checkout($wc_order)
    {
        $method_ids = [];
        foreach ($wc_order->get_shipping_methods() as $shipping_method) {
            $method_ids[] = $shipping_method->get_method_id();
        }
        if (!$this->is_postal($method_ids)) {
            return;
        }

        $message = $this->get_violation($wc_order->get_billing_email(), AddressHelper::from_order_shipping($wc_order));
        if (!$message) {
            return;
        }

        if (class_exists('\Automattic\WooCommerce\StoreApi\Exceptions\RouteException')) {
            throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException('flourish_postal_address', $message, 400);
        }
        throw new \Exception($message);
    }

    private function is_postal($method_ids)
    {
        return HandlerOrdersRetail::fulfillment_type_for_methods($method_ids, $this->existing_settings) === 'postal shipping';
    }

    /**
     * The message to block checkout with, or null to allow it.
     */
    private function get_violation($email, $shipping_address)
    {
        // WooCommerce reports missing required fields itself
        if (empty($email) || !AddressHelper::is_complete($shipping_address)) {
            return null;
        }

        try {
            $customer = $this->initializeFlourishAPI()->find_customer_by_email($email);
        } catch (\Exception $e) {
            wc_get_logger()->warning(
                'Postal address check skipped, Flourish customer lookup failed: ' . $e->getMessage(),
                ['source' => 'flourish-woocommerce-plugin']
            );
            return null;
        }

        // New customer: the order creates them with this address
        if (!$customer) {
            return null;
        }

        if (AddressHelper::find_matching_address_id($customer['address'] ?? [], $shipping_address)) {
            return null;
        }

        $message = sprintf(
            __('We can only ship to the address on your state ID, and the shipping address you entered doesn\'t match the one we have on file. Please contact %s to update your ID address.', 'flourish-woocommerce'),
            get_bloginfo('name')
        );

        return apply_filters('flourish_postal_address_mismatch_message', $message, $customer, $shipping_address);
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
