<?php

namespace FlourishWooCommercePlugin\Handlers;

defined('ABSPATH') || exit;

/**
 * Enforces Flourish's max_redemptions_per_order on synced coupons.
 *
 * Flourish caps how many order lines a line-level discount applies to.
 * WooCommerce's own "limit usage to X items" counts units instead, so the
 * sync leaves it empty and this filter zeroes the discount on eligible lines
 * past the cap, taking lines in cart order.
 *
 * Only line-level coupons (percent, fixed_product) are affected; fixed_cart
 * discounts apply to the order as a whole.
 */
class HandlerCouponLineLimit
{
    const META_KEY = '_flourish_max_redemptions_per_order';

    public function register_hooks()
    {
        add_filter('woocommerce_coupon_get_discount_amount', [$this, 'limit_discounted_lines'], 10, 5);
    }

    public function limit_discounted_lines($discount, $discounting_amount, $cart_item, $single, $coupon)
    {
        if (!($coupon instanceof \WC_Coupon) || !$coupon->is_type(['percent', 'fixed_product'])) {
            return $discount;
        }

        $max_lines = (int) $coupon->get_meta(self::META_KEY);
        if ($max_lines <= 0 || !is_array($cart_item) || empty($cart_item['key'])) {
            return $discount;
        }

        if (!function_exists('WC') || !WC()->cart) {
            return $discount;
        }

        $eligible_keys = [];
        foreach (WC()->cart->get_cart() as $key => $values) {
            $product = $values['data'] ?? null;
            if ($product && $coupon->is_valid_for_product($product, $values)) {
                $eligible_keys[] = $key;
            }
        }

        $allowed_keys = array_slice($eligible_keys, 0, $max_lines);

        return in_array($cart_item['key'], $allowed_keys, true) ? $discount : 0;
    }
}
