<?php

namespace FlourishWooCommercePlugin\Handlers;

defined('ABSPATH') || exit;

use FlourishWooCommercePlugin\API\FlourishAPI;

/**
 * Blocks checkout when the customer is younger than the minimum age set on
 * an item class in Flourish (FEAT1-17037).
 *
 * Flourish rejects these orders at POST /v2/retail-orders, but the AeroPay
 * gateway authorizes payment before the order is sent. Checking here stops
 * the order before any payment is taken. The rule mirrors Flourish's:
 * - Age uses the DOB on the customer's Flourish record when one exists,
 *   because that is what Flourish checks. New customers use the DOB entered
 *   at checkout.
 * - A customer turns N on their birthday, in the site's time zone.
 * - No DOB on file counts as under age.
 */
class HandlerCheckoutAgeRestriction
{
    const MIN_AGES_TRANSIENT = 'flourish_item_class_min_ages';

    private $existing_settings;

    public function __construct($existing_settings)
    {
        $this->existing_settings = $existing_settings;
    }

    public function register_hooks()
    {
        // Classic checkout
        add_action('woocommerce_after_checkout_validation', [$this, 'validate_classic_checkout'], 10, 2);

        // Block-based checkout. Fires before the gateway processes payment;
        // runs ahead of HandlerOrdersRetail (priority 10).
        add_action('woocommerce_store_api_checkout_order_processed', [$this, 'validate_block_checkout'], 5, 1);
    }

    public function validate_classic_checkout($data, $errors)
    {
        $products = [];
        foreach (WC()->cart->get_cart() as $cart_item) {
            $products[] = $cart_item['data'];
        }

        $email = $data['billing_email'] ?? '';
        $entered_dob = $data['billing_dob'] ?? sanitize_text_field($_POST['billing_dob'] ?? '');

        foreach ($this->get_violations($products, $email, $entered_dob) as $message) {
            $errors->add('flourish_age_restricted', $message);
        }
    }

    public function validate_block_checkout($wc_order)
    {
        $products = [];
        foreach ($wc_order->get_items() as $item) {
            $products[] = $item->get_product();
        }

        $email = $wc_order->get_billing_email();

        $violations = $this->get_violations($products, $email, $this->get_block_checkout_dob($wc_order, $email));
        if (empty($violations)) {
            return;
        }

        $message = implode(' ', $violations);
        if (class_exists('\Automattic\WooCommerce\StoreApi\Exceptions\RouteException')) {
            throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException('flourish_age_restricted', $message, 400);
        }
        throw new \Exception($message);
    }

    /**
     * Block checkout doesn't post billing_dob, so read it from wherever the
     * DOB field saved it.
     */
    private function get_block_checkout_dob($wc_order, $email)
    {
        $dob = $wc_order->get_meta('_customer_dob') ?: $wc_order->get_meta('_billing_dob');

        if (empty($dob) && $wc_order->get_customer_id()) {
            $dob = get_user_meta($wc_order->get_customer_id(), 'dob', true);
        }

        if (empty($dob) && !empty($email)) {
            $dob = get_transient('flourish_dob_' . md5($email));
        }

        return $dob ?: '';
    }

    /**
     * Returns one message per restricted item class the customer is too young for.
     *
     * @param \WC_Product[] $products
     * @return string[]
     */
    private function get_violations($products, $email, $entered_dob)
    {
        $min_ages = $this->get_item_class_min_ages();
        if (empty($min_ages)) {
            return [];
        }

        $restricted = [];
        foreach ($products as $product) {
            $item_class = $this->get_item_class($product);
            if ($item_class !== '' && isset($min_ages[$item_class])) {
                $restricted[$item_class] = $min_ages[$item_class];
            }
        }

        if (empty($restricted)) {
            return [];
        }

        [$dob, $from_flourish] = $this->get_customer_dob($email, $entered_dob);
        $age = $this->get_age($dob);

        $violations = [];
        foreach ($restricted as $item_class => $min_age) {
            if ($age === null && $from_flourish) {
                // The storefront can't update a returning customer's Flourish record.
                $violations[] = sprintf(
                    \__('Your customer record has no date of birth on file. Please contact the store to purchase %s items.', 'flourish-woocommerce'),
                    $item_class
                );
            } elseif ($age === null) {
                $violations[] = sprintf(
                    \__('A date of birth is required to purchase %s items.', 'flourish-woocommerce'),
                    $item_class
                );
            } elseif ($age < $min_age) {
                // Same wording as Flourish's rejection.
                $violations[] = sprintf(
                    \__("Customers under %d can't purchase %s items.", 'flourish-woocommerce'),
                    $min_age,
                    $item_class
                );
            }
        }

        return $violations;
    }

    /**
     * Item sync stores the class on the product. Products synced before that
     * have none, so look it up once and save it.
     */
    private function get_item_class($product)
    {
        if (!$product) {
            return '';
        }

        if ($product->is_type('variation')) {
            $parent = wc_get_product($product->get_parent_id());
            if ($parent) {
                $product = $parent;
            }
        }

        $item_class = (string) $product->get_meta('flourish_item_class');
        if ($item_class !== '') {
            return $item_class;
        }

        $flourish_item_id = $product->get_meta('flourish_item_id');
        if (empty($flourish_item_id)) {
            return '';
        }

        try {
            $item_class = $this->initializeFlourishAPI()->fetch_item_class($flourish_item_id);
        } catch (\Exception $e) {
            wc_get_logger()->error(
                "Could not fetch item class for Flourish item {$flourish_item_id}: " . $e->getMessage(),
                ['source' => 'flourish-woocommerce-plugin']
            );
            return '';
        }

        if ($item_class !== '') {
            $product->update_meta_data('flourish_item_class', $item_class);
            $product->save_meta_data();
        }

        return $item_class;
    }

    /**
     * Cached for an hour so a min age changed in Flourish applies without a
     * product re-sync. The cache is per Flourish account so switching the API
     * key or URL takes effect at once.
     *
     * When Flourish can't be reached, use the last list fetched for this
     * account (or none) and wait 5 minutes before trying again, so an outage
     * doesn't slow every checkout. Flourish still rejects the order on sync.
     */
    private function get_item_class_min_ages()
    {
        $account = md5(($this->existing_settings['url'] ?? '') . '|' . ($this->existing_settings['api_key'] ?? ''));
        $transient = self::MIN_AGES_TRANSIENT . '_' . $account;

        $min_ages = get_transient($transient);
        if (is_array($min_ages)) {
            return $min_ages;
        }

        try {
            $min_ages = $this->initializeFlourishAPI()->fetch_item_class_min_ages();
        } catch (\Exception $e) {
            wc_get_logger()->error(
                "Could not fetch item class min ages: " . $e->getMessage(),
                ['source' => 'flourish-woocommerce-plugin']
            );

            $last = get_option(self::MIN_AGES_TRANSIENT, []);
            $min_ages = ($last['account'] ?? '') === $account ? ($last['min_ages'] ?? []) : [];
            set_transient($transient, $min_ages, 5 * MINUTE_IN_SECONDS);

            return $min_ages;
        }

        set_transient($transient, $min_ages, HOUR_IN_SECONDS);
        update_option(self::MIN_AGES_TRANSIENT, ['account' => $account, 'min_ages' => $min_ages], false);

        return $min_ages;
    }

    /**
     * Returning customers keep their Flourish record (the order sync doesn't
     * update its DOB), so Flourish checks the DOB stored there.
     *
     * @return array [DOB, whether it came from the Flourish record]
     */
    private function get_customer_dob($email, $entered_dob)
    {
        if (empty($email)) {
            return [$entered_dob, false];
        }

        try {
            $customer = $this->initializeFlourishAPI()->find_customer_by_email($email);
        } catch (\Exception $e) {
            wc_get_logger()->error(
                "Could not look up Flourish customer for age check: " . $e->getMessage(),
                ['source' => 'flourish-woocommerce-plugin']
            );
            return [$entered_dob, false];
        }

        if (!$customer) {
            return [$entered_dob, false];
        }

        return [$customer['dob'] ?? '', true];
    }

    /**
     * Whole years old today in the site's time zone, or null when the DOB is
     * missing or invalid. Flourish stores a missing DOB as 0001-01-01.
     */
    private function get_age($dob)
    {
        $dob = substr((string) $dob, 0, 10);
        if ($dob === '' || $dob === '0001-01-01') {
            return null;
        }

        $timezone = wp_timezone();
        $birth_date = \DateTime::createFromFormat('!Y-m-d', $dob, $timezone);
        if (!$birth_date || $birth_date->format('Y-m-d') !== $dob) {
            return null;
        }

        return $birth_date->diff(new \DateTime('today', $timezone))->y;
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
