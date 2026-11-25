<?php

namespace FlourishWooCommercePlugin\Helpers;

defined('ABSPATH') || exit;

/**
 * Matches a WooCommerce shipping address to a Flourish customer address.
 *
 * Postal Shipping orders can only ship to the address on the customer's state
 * ID, which Flourish keeps as an active shipping address on the customer. The
 * order sends that address's id (delivery_address_id), never a raw address.
 */
class AddressHelper
{
    private const WORD_ABBREVIATIONS = [
        'street'    => 'st',
        'avenue'    => 'ave',
        'road'      => 'rd',
        'drive'     => 'dr',
        'boulevard' => 'blvd',
        'lane'      => 'ln',
        'court'     => 'ct',
        'place'     => 'pl',
        'circle'    => 'cir',
        'terrace'   => 'ter',
        'highway'   => 'hwy',
        'parkway'   => 'pkwy',
        'square'    => 'sq',
        'trail'     => 'trl',
        'way'       => 'wy',
        'saint'     => 'st',
        'mount'     => 'mt',
        'fort'      => 'ft',
        'first'     => '1st',
        'second'    => '2nd',
        'third'     => '3rd',
        'fourth'    => '4th',
        'fifth'     => '5th',
        'north'     => 'n',
        'south'     => 's',
        'east'      => 'e',
        'west'      => 'w',
        'northeast' => 'ne',
        'northwest' => 'nw',
        'southeast' => 'se',
        'southwest' => 'sw',
        // Unit designators all compare as one
        'apartment' => 'unit',
        'apt'       => 'unit',
        'suite'     => 'unit',
        'ste'       => 'unit',
        '#'         => 'unit',
    ];

    /**
     * The order's shipping address in Flourish's field names.
     */
    public static function from_order_shipping($wc_order)
    {
        return [
            'address_line_1' => $wc_order->get_shipping_address_1(),
            'address_line_2' => $wc_order->get_shipping_address_2(),
            'city'           => $wc_order->get_shipping_city(),
            'state'          => $wc_order->get_shipping_state(),
            'postcode'       => $wc_order->get_shipping_postcode(),
            'country'        => $wc_order->get_shipping_country(),
        ];
    }

    /**
     * The shipping address from classic checkout's posted data. Without "Ship
     * to a different address" the order ships to the billing address.
     */
    public static function from_checkout_data($data)
    {
        $prefix = !empty($data['ship_to_different_address']) ? 'shipping_' : 'billing_';

        return [
            'address_line_1' => $data[$prefix . 'address_1'] ?? '',
            'address_line_2' => $data[$prefix . 'address_2'] ?? '',
            'city'           => $data[$prefix . 'city'] ?? '',
            'state'          => $data[$prefix . 'state'] ?? '',
            'postcode'       => $data[$prefix . 'postcode'] ?? '',
            'country'        => $data[$prefix . 'country'] ?? '',
        ];
    }

    public static function is_complete($address)
    {
        return !empty($address['address_line_1'])
            && !empty($address['city'])
            && !empty($address['state'])
            && !empty($address['postcode']);
    }

    /**
     * Id of the customer's active shipping address that matches $address, or
     * null. Prefers the primary address when more than one matches.
     *
     * @param array $customer_addresses The customer's `address` array from Flourish
     */
    public static function find_matching_address_id($customer_addresses, $address)
    {
        if (!is_array($customer_addresses) || !self::is_complete($address)) {
            return null;
        }

        $match = null;
        foreach ($customer_addresses as $candidate) {
            if (!is_array($candidate) || empty($candidate['id'])) {
                continue;
            }
            if (!self::is_active_shipping($candidate) || !self::matches($candidate, $address)) {
                continue;
            }
            if (!empty($candidate['is_primary'])) {
                return (int) $candidate['id'];
            }
            if ($match === null) {
                $match = (int) $candidate['id'];
            }
        }

        return $match;
    }

    /**
     * Flourish treats a blank address type as shipping.
     */
    private static function is_active_shipping($candidate)
    {
        $type = strtolower(trim($candidate['type'] ?? ''));
        $active = !array_key_exists('active', $candidate) || !empty($candidate['active']);

        return $active && ($type === '' || $type === 'shipping');
    }

    /**
     * Country is not compared: the store ships within the US, and Flourish
     * may hold "United States" where WooCommerce has "US".
     */
    private static function matches($a, $b)
    {
        return self::normalize_street($a) === self::normalize_street($b)
            && self::normalize_line($a['city'] ?? '') === self::normalize_line($b['city'] ?? '')
            && self::normalize_state($a['state'] ?? '') === self::normalize_state($b['state'] ?? '')
            && self::normalize_postcode($a['postcode'] ?? '') === self::normalize_postcode($b['postcode'] ?? '');
    }

    /**
     * Lines 1 and 2 joined, so a unit on either line compares the same.
     */
    private static function normalize_street($address)
    {
        return self::normalize_line(($address['address_line_1'] ?? '') . ' ' . ($address['address_line_2'] ?? ''));
    }

    private static function normalize_text($value)
    {
        // "N.E." is "ne", "O'Neil" is "oneil"
        $value = str_replace(['.', "'"], '', strtolower((string) $value));
        $value = preg_replace('/[^a-z0-9# ]+/', ' ', $value);
        return trim(preg_replace('/\s+/', ' ', $value));
    }

    private static function normalize_line($value)
    {
        // Split "#5" into "# 5" so the designator is its own word
        $value = str_replace('#', ' # ', self::normalize_text($value));

        $words = [];
        foreach (preg_split('/\s+/', trim($value), -1, PREG_SPLIT_NO_EMPTY) as $word) {
            $word = self::WORD_ABBREVIATIONS[$word] ?? $word;
            // "Apt #5" has two designators; keep one
            if ($word === 'unit' && end($words) === 'unit') {
                continue;
            }
            $words[] = $word;
        }

        return implode(' ', $words);
    }

    /**
     * Two-letter code. Accepts a full US state name as well.
     */
    private static function normalize_state($value)
    {
        $value = trim((string) $value);
        if (strlen($value) <= 2) {
            return strtoupper($value);
        }

        if (function_exists('WC') && WC()->countries) {
            foreach ((array) WC()->countries->get_states('US') as $code => $name) {
                if (strcasecmp($name, $value) === 0) {
                    return strtoupper($code);
                }
            }
        }

        return strtoupper($value);
    }

    private static function normalize_postcode($value)
    {
        return substr(preg_replace('/\D+/', '', (string) $value), 0, 5);
    }
}
