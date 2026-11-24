<?php

namespace FlourishWooCommercePlugin\Importer;

defined('ABSPATH') || exit;

use FlourishWooCommercePlugin\Helpers\StockHelper;
use WC_Product_Simple;

class FlourishItems
{
    public $items = [];

    public function __construct($items)
    {
        $this->items = $items;
    }

    public function map_items_to_woocommerce_products()
    {
        if (!count($this->items)) {
            throw new \Exception("No items to map.");
        }

        return array_map([$this, 'map_flourish_item_to_woocommerce_product'], $this->items);
    }

    public function save_as_woocommerce_products($item_sync_options = [], $webhook_status = false)
    {
        $imported_count = 0;

        foreach ($this->map_items_to_woocommerce_products() as $product) {
            if (!strlen($product['sku'])) {
                continue;
            }

            $wc_product = $this->get_existing_or_new_product($product['sku'], $product['uom']);

            $product_id = $this->update_product_attributes($wc_product, $product, $item_sync_options, $webhook_status);

            $this->assign_product_categories($product, $product_id);

            if (!empty($product['brand'])) {
                $this->assign_product_brand($product['brand'], $product_id);
            }

            do_action('flourish_item_imported', $product, $product_id);

            if ($product_id > 0) {
                $imported_count++;
            }
        }

        return $imported_count;
    }

    private function get_existing_or_new_product($sku, $uom)
    {
        $product_id = wc_get_product_id_by_sku($sku);

        if (!empty($product_id)) {
            $product = wc_get_product($product_id);
            if ($product) {
                if ($product->is_type('variation')) {
                    $parent_id = $product->get_parent_id();
                    $parent_product = wc_get_product($parent_id);
                    return $parent_product ?: $product;
                }
                return $product;
            }
        }

        // B2C: Always create simple products (no variable product support)
        $new_product = new WC_Product_Simple();
        $new_product->set_sku($sku);
        $new_product->set_status('draft');
        $new_product->save();

        return $new_product;
    }

    /**
     * Update product attributes.
     *
     * FIX (High #10): Uses StockHelper::calculate_stock() for consistent formula.
     * Previously used abs(flourish_stock - reserved_stock) which could yield a
     * positive number when reserved > available, allowing overselling.
     */
    private function update_product_attributes($wc_product, $product, $item_sync_options, $webhook_status)
    {
        $this->save_custom_fields_automated($wc_product, $product);

        if (!isset($item_sync_options['name']) || $item_sync_options['name'] === true) {
            $wc_product->set_name($product['name']);
        }

        $current_description = $wc_product->get_description();
        if (empty($current_description) && (!isset($item_sync_options['description']) || $item_sync_options['description'] === true)) {
            $wc_product->set_description($product['description']);
        }

        if (!isset($item_sync_options['price']) || $item_sync_options['price'] === true) {
            if ($webhook_status === false) {
                $wc_product->set_price($product['price']);
                $wc_product->set_regular_price($product['price']);
                update_post_meta($wc_product->get_id(), '_price', $product['price']);
            } else {
                $price = get_post_meta($wc_product->get_id(), '_price', true);
                if (!empty($price)) {
                    $wc_product->set_price($price);
                    $wc_product->set_regular_price($price);
                }
            }
        }

        $wc_product->set_sku($product['sku']);

        // Enable stock management
        if (method_exists($wc_product, 'set_manage_stock')) {
            $wc_product->set_manage_stock(true);
        } else {
            $wc_product->update_meta_data('_manage_stock', 'yes');
        }

        $product_id = $wc_product->get_id();
        $reserved_stock = (int) get_post_meta($product_id, '_reserved_stock', true);
        $flourish_stock = (int) ($product['inventory_quantity'] ?? 0);

        $woocommerce_stock = StockHelper::calculate_stock($flourish_stock, $reserved_stock);
        $wc_product->set_stock_quantity($woocommerce_stock);

        $product_id = $wc_product->save();

        return $product_id;
    }

    private function save_custom_fields_automated($wc_product, $product)
    {
        $fields = [
            'uom'                  => 'uom',
            'uom_description'      => 'uom_description',
            'unit_weight'          => 'unit_weight',
            'weight_uom'           => 'weight_uom',
            'weight_uom_description' => 'weight_uom_description',
        ];

        foreach ($fields as $meta_key => $field_name) {
            if (isset($product[$field_name])) {
                $wc_product->update_meta_data($meta_key, $product[$field_name]);
            }
        }

        $wc_product->update_meta_data('flourish_item_id', $product['flourish_item_id']);
        $wc_product->update_meta_data('flourish_item_class', $product['item_class']);
        $wc_product->update_meta_data('flourish_inventory_type', $product['inventory_type']);
    }

    /**
     * Assign a brand to a product.
     *
     * FIX (High #11): Replaced wp_cache_flush() with targeted cache invalidation.
     * The original code flushed the ENTIRE WordPress object cache every time a brand
     * was assigned. During initial import of hundreds of products, this would destroy
     * site performance for all visitors.
     */
    public function assign_product_brand($brand, $product_id)
    {
        if (empty($brand) || empty($product_id)) {
            return false;
        }

        $taxonomy = 'product_brand';

        $existing_terms = get_terms([
            'taxonomy'   => $taxonomy,
            'hide_empty' => false,
            'name__like' => $brand,
        ]);

        $term_id = null;

        if (!empty($existing_terms)) {
            foreach ($existing_terms as $existing_term) {
                if (strtolower($existing_term->name) === strtolower($brand)) {
                    $term_id = $existing_term->term_id;
                    break;
                }
            }
        }

        if (!$term_id) {
            $term = wp_insert_term($brand, $taxonomy);
            if (is_wp_error($term)) {
                error_log('Error creating brand term: ' . $term->get_error_message());
                return false;
            }
            $term_id = $term['term_id'];
        }

        $result = wp_set_object_terms($product_id, [$term_id], $taxonomy, false);

        if (is_wp_error($result)) {
            error_log('Error assigning brand term: ' . $result->get_error_message());
            return false;
        }

        clean_object_term_cache($product_id, $taxonomy);

        return true;
    }

    /**
     * Categorize by the eCommerce category (with its sub-category as a child
     * term) when set, falling back to the Flourish item_category.
     *
     * If the payload has no eCommerce category fields at all (webhook payloads
     * aren't confirmed to include them), existing categories are left alone so
     * a webhook doesn't overwrite what the full sync set. A product with no
     * category yet still gets item_category.
     */
    private function assign_product_categories($product, $product_id)
    {
        if (empty($product_id)) {
            return;
        }

        $parent_name = $product['ecommerce_category'];
        $child_name  = $product['ecommerce_sub_category'];

        if (!$product['has_ecommerce_fields']) {
            if (!empty($product['item_category']) && !$this->has_assigned_category($product_id)) {
                $this->set_product_category($this->get_or_create_category_term($product['item_category']), $product_id);
            }
            return;
        }

        if ($parent_name !== '') {
            $term_id = $this->get_or_create_category_term($parent_name);
            if ($child_name !== '') {
                $term_id = $this->get_or_create_category_term($child_name, $term_id);
            }
            $this->set_product_category($term_id, $product_id);
            return;
        }

        if (!empty($product['item_category'])) {
            $this->set_product_category($this->get_or_create_category_term($product['item_category']), $product_id);
        }
    }

    private function has_assigned_category($product_id)
    {
        $default_cat = (int) get_option('default_product_cat', 0);
        $term_ids = wp_get_object_terms($product_id, 'product_cat', ['fields' => 'ids']);

        if (is_wp_error($term_ids)) {
            return false;
        }

        return count(array_diff(array_map('intval', $term_ids), [$default_cat])) > 0;
    }

    /**
     * Without a parent, match the name at any level so a category the store
     * moved under a parent isn't duplicated at the top level. With a parent
     * (sub-categories), match only under that parent.
     */
    private function get_or_create_category_term($category_name, $parent_id = 0)
    {
        $term = $parent_id
            ? term_exists($category_name, 'product_cat', $parent_id)
            : term_exists($category_name, 'product_cat');

        if (!$term) {
            $term = wp_insert_term($category_name, 'product_cat', ['parent' => $parent_id]);
        }

        if (is_wp_error($term)) {
            throw new \Exception("Error inserting category term: " . $term->get_error_message());
        }

        return (int) ($term['term_id'] ?? $term['term_taxonomy_id']);
    }

    private function set_product_category($term_id, $product_id)
    {
        wp_set_object_terms($product_id, (int) $term_id, 'product_cat');
    }

    /**
     * Retail price, falling back to price. Production Flourish returns
     * retail_price 0 for some items, which synced them at $0.00. Returns ''
     * (no price, not purchasable) when both are zero; never 0.
     */
    private function resolve_price($flourish_item)
    {
        foreach (['retail_price', 'price'] as $key) {
            if (isset($flourish_item[$key]) && is_numeric($flourish_item[$key]) && (float) $flourish_item[$key] > 0) {
                return (float) $flourish_item[$key];
            }
        }

        return '';
    }

    private function first_non_empty($flourish_item, array $keys)
    {
        foreach ($keys as $key) {
            if (isset($flourish_item[$key]) && trim((string) $flourish_item[$key]) !== '') {
                return trim((string) $flourish_item[$key]);
            }
        }

        return '';
    }

    private function map_flourish_item_to_woocommerce_product($flourish_item)
    {
        return [
            'flourish_item_id'      => $flourish_item['id'],
            'item_category'         => $flourish_item['item_category'] ?? '',
            'has_ecommerce_fields'  => array_key_exists('ecommerce_category_name', $flourish_item)
                || array_key_exists('ecommerce_category_description', $flourish_item),
            'ecommerce_category'    => $this->first_non_empty($flourish_item, ['ecommerce_category_name', 'ecommerce_category_description']),
            'ecommerce_sub_category' => $this->first_non_empty($flourish_item, ['ecommerce_sub_category_name', 'ecommerce_sub_category_description']),
            'item_class'            => $flourish_item['item_class'] ?? '',
            // Companies can add their own inventory types, so anything that
            // isn't Cannabis is stored as Non-Cannabis.
            'inventory_type'        => strcasecmp(trim($flourish_item['inventory_type'] ?? ''), 'Cannabis') === 0 ? 'Cannabis' : 'Non-Cannabis',
            'name'                  => !empty(trim($flourish_item['retail_display_name'] ?? '')) ? trim($flourish_item['retail_display_name']) : $flourish_item['item_name'],
            'description'           => $flourish_item['item_description'] ?? '',
            'sku'                   => $flourish_item['sku'],
            'price'                 => $this->resolve_price($flourish_item),
            'uom'                   => $flourish_item['uom'] ?? '',
            'uom_description'       => $flourish_item['uom_description'] ?? '',
            'unit_weight'           => $flourish_item['unit_weight'] ?? '',
            'weight_uom'            => $flourish_item['weight_uom'] ?? '',
            'weight_uom_description' => $flourish_item['weight_uom_description'] ?? '',
            'inventory_quantity'    => $flourish_item['inventory_quantity'] ?? 0,
            'brand'                 => $flourish_item['brand'] ?? '',
        ];
    }
}
