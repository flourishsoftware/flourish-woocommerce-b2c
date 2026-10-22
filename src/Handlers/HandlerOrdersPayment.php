<?php

namespace FlourishWooCommercePlugin\Handlers;

defined('ABSPATH') || exit;

use FlourishWooCommercePlugin\API\FlourishAPI;
use FlourishWooCommercePlugin\Helpers\HttpRequestHelper;

/**
 * Sends payments captured in WooCommerce to Flourish.
 *
 * The configured AeroPay gateway pre-authorizes at checkout and captures when
 * staff confirm payment in WooCommerce. It then fires flourish_payment_captured
 * and this handler records the payment on the Flourish order as paid.
 * See README "AeroPay Payments".
 */
class HandlerOrdersPayment
{
    public $existing_settings;

    public function __construct($existing_settings)
    {
        $this->existing_settings = $existing_settings;
    }

    public function register_hooks()
    {
        add_action('flourish_payment_captured', [$this, 'send_payment'], 10, 1);

        // Manual retry from the order admin
        add_action('woocommerce_order_actions', [$this, 'add_send_payment_action']);
        add_action('woocommerce_order_action_flourish_send_payment', [$this, 'send_payment']);
    }

    /**
     * Whether the order was paid with the configured AeroPay gateway, so the
     * gateway (not Flourish) owns capture, void and refund.
     */
    public static function is_gateway_order($wc_order, $settings)
    {
        if (empty($settings['send_payment_data'])) {
            return false;
        }

        $gateway_id = $settings['preauth_gateway_id'] ?? '';
        return $gateway_id !== '' && $wc_order->get_payment_method() === $gateway_id;
    }

    public function add_send_payment_action($actions)
    {
        $actions['flourish_send_payment'] = 'Send payment to Flourish';
        return $actions;
    }

    /**
     * Record the captured payment on the Flourish order. Syncs the order first
     * if it never reached Flourish. Safe to call more than once.
     */
    public function send_payment($order_id)
    {
        $wc_order = $order_id instanceof \WC_Order ? $order_id : wc_get_order($order_id);
        if (!$wc_order) {
            return;
        }

        if (!self::is_gateway_order($wc_order, $this->existing_settings)) {
            $wc_order->add_order_note('Payment not sent to Flourish: the order was not paid with the configured AeroPay gateway, or sending payments is turned off.');
            $wc_order->save();
            return;
        }

        if ($wc_order->get_meta('_flourish_payment_transaction_id')) {
            $wc_order->add_order_note('Payment already recorded in Flourish.');
            $wc_order->save();
            return;
        }

        try {
            if (!$wc_order->get_meta('flourish_order_id')) {
                $handler = new HandlerOrdersRetail($this->existing_settings);
                $handler->handle_order_retail($wc_order->get_id());
                $wc_order = wc_get_order($wc_order->get_id());
            }

            $flourish_order_id = $wc_order->get_meta('flourish_order_id');
            if (!$flourish_order_id) {
                throw new \Exception('The order is not in Flourish yet. Sync the order, then send the payment.');
            }

            $payment = $this->build_payment_data($wc_order);
            $flourish_api = $this->initializeFlourishAPI();
            $transaction_id = $flourish_api->create_retail_order_payment($flourish_order_id, $payment);

            $wc_order->update_meta_data('_flourish_payment_transaction_id', $transaction_id ?: 'recorded');
            $wc_order->add_order_note(sprintf(
                'Payment of %s recorded in Flourish (AeroPay transaction %s).',
                $payment['amount'],
                $payment['reference_id']
            ));
            $wc_order->save();
        } catch (\Exception $e) {
            wc_get_logger()->error(
                "Error sending payment to Flourish: " . $e->getMessage(),
                ['source' => 'flourish-woocommerce-plugin']
            );

            $wc_order->add_order_note('Failed to send payment to Flourish: ' . $e->getMessage() . ' Use "Send payment to Flourish" to retry.');
            $wc_order->save();

            HttpRequestHelper::send_order_failure_email_to_admin($e->getMessage(), $wc_order->get_id());
        }
    }

    /**
     * Build the `payment` object from the _flourish_payment_* meta written by
     * the gateway at capture.
     *
     * @throws \Exception When the captured payment is missing or invalid.
     */
    private function build_payment_data($wc_order)
    {
        $reference_id = (string) $wc_order->get_meta('_flourish_payment_reference_id');
        if ($reference_id === '') {
            throw new \Exception('No captured AeroPay transaction ID (_flourish_payment_reference_id) on the order.');
        }

        $meta_amount = $wc_order->get_meta('_flourish_payment_amount');
        $amount = round((float) ($meta_amount !== '' ? $meta_amount : $wc_order->get_total()), 2);
        if ($amount <= 0) {
            throw new \Exception("Captured amount must be greater than zero (got {$amount}).");
        }

        $payment = [
            'status'       => 'paid',
            'method'       => 'aeropay',
            'amount'       => $amount,
            'reference_id' => $reference_id,
        ];

        $tip_amount = $wc_order->get_meta('_flourish_payment_tip_amount');
        if ($tip_amount !== '') {
            $tip_amount = round((float) $tip_amount, 2);
            if ($tip_amount < 0 || $tip_amount > $amount) {
                throw new \Exception("Tip amount ({$tip_amount}) must be between 0 and the captured amount ({$amount}).");
            }
            $payment['tip_amount'] = $tip_amount;
        }

        $processor_data = $wc_order->get_meta('_flourish_payment_processor_data');
        if (!empty($processor_data)) {
            $payment['processor_data'] = is_string($processor_data) ? $processor_data : wp_json_encode($processor_data);
        }

        return $payment;
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
