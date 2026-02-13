<?php

namespace FlourishWooCommercePlugin\Helpers;

defined('ABSPATH') || exit;

/**
 * Centralized stock management helper.
 *
 * FIX: Extracted from 4 duplicated copies across the codebase into a single
 * shared helper to ensure consistent behavior.
 */
class StockHelper
{
    /**
     * Determine whether the plugin should skip stock management for this product.
     *
     * FIX (Critical #1): The original logic was:
     *   return $manage_stock === false && ($backorders === 'notify' || $backorders === 'yes'
     *          || $stock_status == 'onbackorder' || $stock_status == 'instock');
     *
     * This incorrectly skipped stock updates for nearly all products because
     * 'instock' is the default stock status. The fix simplifies the check:
     * if the product doesn't have stock management enabled, we skip it.
     *
     * @param \WC_Product|null $product
     * @return bool True if stock management should be SKIPPED for this product.
     */
    public static function should_skip_stock_management($product): bool
    {
        if (!$product) {
            return true;
        }

        return $product->get_manage_stock() === false;
    }

    /**
     * Calculate the effective WooCommerce stock quantity.
     *
     * FIX (High #10): Standardized stock formula across the entire codebase.
     * Previously, different files used abs(), raw subtraction, or no reserved
     * stock subtraction at all, leading to inconsistent quantities.
     *
     * @param int $flourish_stock The sellable quantity from Flourish.
     * @param int $reserved_stock The reserved stock from WooCommerce meta.
     * @return int The stock quantity to set in WooCommerce (never negative).
     */
    public static function calculate_stock(int $flourish_stock, int $reserved_stock): int
    {
        return max(0, $flourish_stock - $reserved_stock);
    }
}
