<?php

namespace FlourishWooCommercePlugin\Importer;

defined('ABSPATH') || exit;

use FlourishWooCommercePlugin\Helpers\StockHelper;
use WC_Product_Simple;
use WC_Product_Variable;
use WC_Product_Attribute;

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

            if (!empty($product['item_category'])) {
                $this->assign_product_category($product['item_category'], $product_id);
            }

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

        // Determine if we need a variable or simple product
        $attribute_exists = false;
        $attributes = wc_get_attribute_taxonomies();
        foreach ($attributes as $attribute) {
            $taxonomy = 'pa_' . $attribute->attribute_name;
            if ($taxonomy === 'pa_' . sanitize_title($uom)) {
                $attribute_exists = true;
                break;
            }
        }

        $new_product = $attribute_exists ? new WC_Product_Variable() : new WC_Product_Simple();
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

        if (empty($item_sync_options['name']) || $item_sync_options['name']) {
            $wc_product->set_name($product['name']);
        }

        $current_description = $wc_product->get_description();
        if (empty($current_description) && (!isset($item_sync_options['description']) || $item_sync_options['description'] === true)) {
            $wc_product->set_description($product['description']);
        }

        if (empty($item_sync_options['price']) || $item_sync_options['price']) {
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

        if ($wc_product->is_type('variable')) {
            $this->create_attributes($wc_product, $product);
            wc_delete_product_transients($wc_product->get_id());
        }

        return $product_id;
    }

    public function create_attributes_update($wc_product)
    {
        $price = get_post_meta($wc_product->get_id(), '_price', true);
        if (!empty($price)) {
            $wc_product->set_price($price);
            $wc_product->set_regular_price($price);
            update_post_meta($wc_product->get_id(), '_price', $price);
        }

        $uom = get_post_meta($wc_product->get_id(), 'uom', true);
        if (empty($uom)) {
            return;
        }

        $attributes = wc_get_attribute_taxonomies();
        $product_attributes = [];

        foreach ($attributes as $attribute) {
            $taxonomy = 'pa_' . $attribute->attribute_name;

            if ($taxonomy === 'pa_' . sanitize_title($uom) && taxonomy_exists($taxonomy)) {
                $existing_attributes = $wc_product->get_attributes();
                $selected_terms = [];

                if (isset($existing_attributes[$taxonomy])) {
                    $selected_terms = $existing_attributes[$taxonomy]->get_options();
                } else {
                    $terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false]);
                    if (!empty($terms)) {
                        $first_term = reset($terms);
                        $selected_terms = [$first_term->slug];
                    }
                }

                if (!empty($selected_terms)) {
                    $product_attribute = new WC_Product_Attribute();
                    $product_attribute->set_name($taxonomy);
                    $product_attribute->set_options($selected_terms);
                    $product_attribute->set_visible(true);
                    $product_attribute->set_variation(true);
                    $product_attributes[] = $product_attribute;
                }
            }
        }

        $wc_product->set_attributes($product_attributes);
        $wc_product->save();

        $attributes_data = [];
        foreach ($product_attributes as $attribute) {
            $taxonomy = $attribute->get_name();
            $options = $attribute->get_options();
            $term_names = [];
            foreach ($options as $slug) {
                $term = get_term_by('slug', $slug, $taxonomy);
                if ($term) {
                    $term_names[] = $term->name;
                }
            }
            $attributes_data[$taxonomy] = $term_names;
        }

        if (!empty($attributes_data)) {
            $this->generate_product_variations($wc_product->get_id(), $attributes_data);
        }
    }

    public function create_attributes($wc_product, $product)
    {
        $uom = get_post_meta($wc_product->get_id(), 'uom', true);

        if (empty($uom)) {
            update_post_meta($wc_product->get_id(), 'uom', $product['uom']);
            return;
        }

        $attributes = wc_get_attribute_taxonomies();
        $product_attributes = [];

        foreach ($attributes as $attribute) {
            $taxonomy = 'pa_' . $attribute->attribute_name;

            if ($taxonomy === 'pa_' . sanitize_title($uom) && taxonomy_exists($taxonomy)) {
                $terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false]);
                if (!empty($terms)) {
                    $term_names = wp_list_pluck($terms, 'slug');
                    $product_attribute = new WC_Product_Attribute();
                    $product_attribute->set_name($taxonomy);
                    $product_attribute->set_options($term_names);
                    $product_attribute->set_visible(true);
                    $product_attribute->set_variation(true);
                    $product_attributes[] = $product_attribute;
                }
            }
        }

        $wc_product->set_attributes($product_attributes);
        $wc_product->save();

        $attributes_data = [];
        foreach ($product_attributes as $attribute) {
            $taxonomy = $attribute->get_name();
            $options = $attribute->get_options();
            $attributes_data[$taxonomy] = $options;
        }

        if (!empty($attributes_data)) {
            $this->generate_product_variations($wc_product->get_id(), $attributes_data);
        }
    }

    private function generate_product_variations($product_id, $attributes_data)
    {
        $product = wc_get_product($product_id);
        if (!$product || !$product->is_type('variable')) {
            return;
        }

        foreach ($attributes_data as $taxonomy => $options) {
            if (!taxonomy_exists($taxonomy)) {
                $this->create_attribute_taxonomy($taxonomy);
            }

            foreach ($options as $option) {
                $option = trim($option);
                if (!empty($option) && !term_exists($option, $taxonomy)) {
                    wp_insert_term($option, $taxonomy);
                }
            }
        }

        $combinations = $this->get_attribute_combinations($attributes_data);
        $index = 0;

        foreach ($combinations as $combination) {
            $existing_variations = $product->get_children();
            $variation_exists = false;
            $variation_id = null;

            foreach ($existing_variations as $existing_variation_id) {
                $attributes_match = true;
                foreach ($combination as $taxonomy => $term_name) {
                    $existing_value = get_post_meta($existing_variation_id, 'attribute_' . $taxonomy, true);
                    if ($existing_value !== $term_name) {
                        $attributes_match = false;
                        break;
                    }
                }

                if ($attributes_match) {
                    $variation_exists = true;
                    $variation_id = $existing_variation_id;
                    break;
                }
            }

            if ($variation_exists) {
                $this->update_variation_price($product, $combination, $variation_id);
                continue;
            }

            $variation_data = [
                'post_title'  => $product->get_name() . ' - ' . implode(', ', $combination),
                'post_name'   => 'product-' . $product_id . '-variation-' . sanitize_title(implode('-', $combination)),
                'post_status' => 'publish',
                'post_parent' => $product_id,
                'post_type'   => 'product_variation',
            ];

            $variation_id = wp_insert_post($variation_data);

            foreach ($attributes_data as $taxonomy => $options) {
                $value = $combination[$taxonomy] ?? '';
                update_post_meta($variation_id, 'attribute_' . $taxonomy, $value);
            }

            $this->update_variation_price($product, $combination, $variation_id);

            if ($index === 0) {
                update_post_meta($product_id, '_default_attributes', [
                    $taxonomy => $combination[$taxonomy] ?? '',
                ]);
            }

            $index++;
        }
    }

    private function update_variation_price($product, $combination, $variation_id)
    {
        $custom_price_multiplier = 1;
        foreach ($combination as $taxonomy => $term_name) {
            $term = get_term_by('name', $term_name, $taxonomy);
            if ($term) {
                $quantity = get_term_meta($term->term_id, 'quantity', true);
                if ($quantity) {
                    $custom_price_multiplier *= floatval($quantity);
                }
            }
        }

        $product_price = floatval($product->get_price());
        $variation_price = $product_price * $custom_price_multiplier;

        update_post_meta($variation_id, '_regular_price', $variation_price);
        update_post_meta($variation_id, '_price', $variation_price);
    }

    private function get_attribute_combinations($attributes_data)
    {
        $combinations = [[]];

        foreach ($attributes_data as $attribute => $terms) {
            $new_combinations = [];
            foreach ($combinations as $combination) {
                foreach ($terms as $term) {
                    $new_combinations[] = array_merge($combination, [$attribute => $term]);
                }
            }
            $combinations = $new_combinations;
        }

        return $combinations;
    }

    private function create_attribute_taxonomy($taxonomy)
    {
        $args = [
            'label'             => ucfirst($taxonomy),
            'hierarchical'      => true,
            'show_ui'           => true,
            'show_admin_column' => true,
            'query_var'         => true,
            'rewrite'           => ['slug' => $taxonomy],
        ];

        register_taxonomy($taxonomy, 'product', $args);
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

    private function assign_product_category($category_name, $product_id)
    {
        $term = term_exists($category_name, 'product_cat');

        if (!$term) {
            $term = wp_insert_term($category_name, 'product_cat');
        }

        if (!is_wp_error($term)) {
            $term_id = $term['term_id'] ?? $term['term_taxonomy_id'];
            wp_set_object_terms($product_id, (int) $term_id, 'product_cat');
        } else {
            throw new \Exception("Error inserting category term.");
        }
    }

    private function map_flourish_item_to_woocommerce_product($flourish_item)
    {
        return [
            'flourish_item_id'      => $flourish_item['id'],
            'item_category'         => $flourish_item['item_category'] ?? '',
            'name'                  => $flourish_item['item_name'],
            'description'           => $flourish_item['item_description'] ?? '',
            'sku'                   => $flourish_item['sku'],
            'price'                 => $flourish_item['price'] ?? 0,
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
