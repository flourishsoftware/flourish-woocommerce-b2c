<?php

namespace FlourishWooCommercePlugin\Importer;

defined('ABSPATH') || exit;

class FlourishDiscounts
{
    private $discounts = [];

    public function __construct($discounts)
    {
        $this->discounts = $discounts;
    }

    /**
     * Sync Flourish discounts as WooCommerce coupons.
     * Returns count of created/updated coupons.
     */
    public function save_as_woocommerce_coupons()
    {
        $synced_count = 0;

        foreach ($this->discounts as $discount) {
            if (empty($discount['promo_code'])) {
                continue;
            }

            $coupon = $this->get_or_create_coupon($discount['promo_code']);
            $this->map_discount_to_coupon($coupon, $discount);
            $coupon->save();
            $synced_count++;
        }

        return $synced_count;
    }

    private function get_or_create_coupon($code)
    {
        // WooCommerce caches the code-to-ID lookup, so the ID can belong to a
        // coupon that has since been deleted or trashed. Reusing it saves a
        // coupon with no code, and typing the code by hand then fails with
        // "already exists". On a stale hit, clear the cache and look again so
        // a coupon recreated under a new ID is reused, not duplicated.
        $coupon_id = wc_get_coupon_id_by_code($code);
        if ($coupon_id && !$this->is_live_coupon($coupon_id, $code)) {
            \WC_Cache_Helper::invalidate_cache_group('coupons');
            $coupon_id = wc_get_coupon_id_by_code($code);
        }

        if ($coupon_id && $this->is_live_coupon($coupon_id, $code)) {
            return new \WC_Coupon($coupon_id);
        }

        $coupon = new \WC_Coupon();
        $coupon->set_code($code);
        return $coupon;
    }

    private function is_live_coupon($coupon_id, $code)
    {
        $post = get_post($coupon_id);

        return $post
            && $post->post_type === 'shop_coupon'
            && $post->post_status === 'publish'
            && wc_strtolower($post->post_title) === wc_strtolower(wc_format_coupon_code($code));
    }

    private function map_discount_to_coupon(\WC_Coupon $coupon, array $discount)
    {
        $coupon->set_discount_type($this->get_wc_discount_type(
            $discount['method'] ?? 'dollar',
            $discount['level'] ?? 'order'
        ));

        $coupon->set_amount($discount['amount'] ?? 0);

        if (!empty($discount['order_minimum_amount'])) {
            $coupon->set_minimum_amount($discount['order_minimum_amount']);
        }

        if (!empty($discount['end_date'])) {
            $coupon->set_date_expires($discount['end_date']);
        }

        // Flourish's max_redemptions_per_order limits order lines, while
        // WooCommerce's item limit counts units. Mapping one onto the other
        // undercounts the discount once a line has 3+ units, so WooCommerce
        // totals came out higher than Flourish and blocked the Aeropay capture.
        // The line cap is stored as meta and enforced by HandlerCouponLineLimit.
        $coupon->set_limit_usage_to_x_items(null);
        $max_lines = (int) ($discount['max_redemptions_per_order'] ?? 0);
        if ($max_lines > 0) {
            $coupon->update_meta_data('_flourish_max_redemptions_per_order', $max_lines);
        } else {
            $coupon->delete_meta_data('_flourish_max_redemptions_per_order');
        }

        if (!empty($discount['eligible_items'])) {
            $coupon->set_product_ids($this->resolve_skus_to_product_ids($discount['eligible_items']));
        }

        if (!empty($discount['ineligible_items'])) {
            $coupon->set_excluded_product_ids($this->resolve_skus_to_product_ids($discount['ineligible_items']));
        }

        $coupon->update_meta_data('_flourish_discount_id', $discount['discount_id'] ?? '');
        $coupon->update_meta_data('_flourish_synced', true);
        $coupon->update_meta_data('_flourish_last_synced', current_time('mysql'));

        if (!empty($discount['start_date'])) {
            $coupon->update_meta_data('_flourish_start_date', $discount['start_date']);
        }
    }

    private function get_wc_discount_type($method, $level)
    {
        if ($method === 'percentage') {
            return 'percent';
        }

        return $level === 'line' ? 'fixed_product' : 'fixed_cart';
    }

    private function resolve_skus_to_product_ids(array $items)
    {
        $product_ids = [];
        foreach ($items as $item) {
            $sku = $item['sku'] ?? '';
            if (empty($sku)) {
                continue;
            }
            $product_id = wc_get_product_id_by_sku($sku);
            if ($product_id) {
                $product_ids[] = $product_id;
            }
        }
        return $product_ids;
    }
}
