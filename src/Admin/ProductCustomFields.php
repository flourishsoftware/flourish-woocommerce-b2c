<?php

namespace FlourishWooCommercePlugin\Admin;

use FlourishWooCommercePlugin\Importer\FlourishItems;

defined('ABSPATH') || exit;

class ProductCustomFields
{
    public $existing_settings;

    public function __construct($existing_settings)
    {
        $this->existing_settings = $existing_settings;
    }

    public function register_hooks()
    {
        add_action('woocommerce_product_options_general_product_data', [$this, 'add_custom_fields']);
        add_action('woocommerce_product_options_inventory_product_data', [$this, 'add_custom_fields_inventory']);
        add_action('woocommerce_process_product_meta', [$this, 'save_custom_fields']);
        add_action('woocommerce_admin_process_product_object', [$this, 'auto_update_stock_status'], 25, 1);

        add_filter('woocommerce_add_to_cart_validation', [$this, 'validate_min_max_order_quantity'], 10, 3);
        add_action('woocommerce_check_cart_items', [$this, 'validate_cart_min_max_order_quantity']);
    }

    public function add_custom_fields_inventory()
    {
        global $post;

        $fields = [
            '_reserved_stock' => [
                'label'       => __('Reserved Stock', 'woocommerce'),
                'description' => __('Stock allocated for confirmed orders but not yet fulfilled.', 'woocommerce'),
            ],
        ];

        echo '<div class="options_group">';
        foreach ($fields as $id => $field) {
            woocommerce_wp_text_input([
                'id'                => $id,
                'label'             => $field['label'],
                'description'       => $field['description'],
                'type'              => 'number',
                'value'             => get_post_meta($post->ID, $id, true) ?: 0,
                'desc_tip'          => true,
                'custom_attributes' => ['readonly' => 'readonly'],
            ]);
        }
        echo '</div>';
    }

    public function add_custom_fields()
    {
        global $post;

        $fields = [
            'unit_weight' => [
                'label'       => __('Unit Weight', 'flourish-woocommerce'),
                'description' => __('Enter the weight of the unit', 'flourish-woocommerce'),
                'type'        => 'text',
                'value'       => get_post_meta($post->ID, 'unit_weight', true),
            ],
            'uom' => [
                'label'       => __('Unit of Measure', 'flourish-woocommerce'),
                'description' => __('Unit of measure from Flourish', 'flourish-woocommerce'),
                'type'        => 'text',
                'value'       => get_post_meta($post->ID, 'uom', true),
            ],
            'uom_description' => [
                'label'       => __('UOM Description', 'flourish-woocommerce'),
                'description' => __('Description of the unit of measure', 'flourish-woocommerce'),
                'type'        => 'text',
                'value'       => get_post_meta($post->ID, 'uom_description', true),
            ],
            '_min_order_quantity' => [
                'label'             => __('Minimum Order Quantity', 'woocommerce'),
                'description'       => __('Set the minimum quantity customers can order.', 'woocommerce'),
                'type'              => 'number',
                'value'             => get_post_meta($post->ID, '_min_order_quantity', true),
                'custom_attributes' => ['step' => '1', 'min' => '1'],
            ],
            '_max_order_quantity' => [
                'label'             => __('Maximum Order Quantity', 'woocommerce'),
                'description'       => __('Set the maximum quantity customers can order.', 'woocommerce'),
                'type'              => 'number',
                'value'             => get_post_meta($post->ID, '_max_order_quantity', true),
                'custom_attributes' => ['step' => '1', 'min' => '1'],
            ],
        ];

        echo '<div class="options_group">';
        foreach ($fields as $id => $field) {
            woocommerce_wp_text_input([
                'id'                => $id,
                'label'             => $field['label'],
                'description'       => $field['description'],
                'type'              => $field['type'],
                'value'             => $field['value'],
                'desc_tip'          => true,
                'custom_attributes' => $field['custom_attributes'] ?? [],
            ]);
        }
        echo '</div>';
    }

    public function save_custom_fields($post_id)
    {
        $fields = [
            'unit_weight',
            'uom',
            'uom_description',
            '_min_order_quantity',
            '_max_order_quantity',
        ];

        foreach ($fields as $field) {
            if (isset($_POST[$field])) {
                update_post_meta($post_id, $field, sanitize_text_field($_POST[$field]));
            }
        }
    }

    public function auto_update_stock_status($product)
    {
        if (!$product || !($product instanceof \WC_Product)) {
            return;
        }

        $product_id = $product->get_id();
        $manage_stock = filter_var($_POST['_manage_stock'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $stock_status = sanitize_text_field($_POST['_stock_status'] ?? 'instock');

        $backorders = '';
        if (isset($_POST['_backorders']) && in_array($_POST['_backorders'], ['yes', 'notify'])) {
            $backorders = sanitize_text_field($_POST['_backorders']);
            update_post_meta($product_id, '_backorders', $backorders);
        } else {
            delete_post_meta($product_id, '_backorders');
        }

        $product->set_manage_stock($manage_stock);

        if (!empty($backorders)) {
            $product->set_backorders($backorders);
        }

        if (!$manage_stock) {
            if ($backorders === 'yes' || $backorders === 'notify') {
                $product->set_stock_status('onbackorder');
            } else {
                $product->set_stock_status($stock_status);
            }
        }

        $product->save();

        if ($product->is_type('variable')) {
            $variation_ids = $product->get_children();
            foreach ($variation_ids as $variation_id) {
                $variation = wc_get_product($variation_id);
                if (!$variation) continue;

                if (!empty($backorders)) {
                    $variation->set_backorders($backorders);
                }

                if ($backorders === 'yes' || $backorders === 'notify') {
                    $variation->set_stock_status('onbackorder');
                } else {
                    $variation->set_stock_status($stock_status);
                }

                $variation->save();
            }
        }

        wc_delete_product_transients($product->get_id());

        $flourish_items = new FlourishItems($product);
        $flourish_items->create_attributes_update($product);
    }

    public function validate_min_max_order_quantity($passed, $product_id, $quantity)
    {
        $min_quantity = get_post_meta($product_id, '_min_order_quantity', true);
        $max_quantity = get_post_meta($product_id, '_max_order_quantity', true);

        if (!empty($min_quantity) && $quantity < $min_quantity) {
            wc_add_notice(
                sprintf(__('You must purchase at least %s of this product.', 'woocommerce'), $min_quantity),
                'error'
            );
            $passed = false;
        }

        if (!empty($max_quantity) && $quantity > $max_quantity) {
            wc_add_notice(
                sprintf(__('You can only purchase a maximum of %s of this product.', 'woocommerce'), $max_quantity),
                'error'
            );
            $passed = false;
        }

        return $passed;
    }

    public function validate_cart_min_max_order_quantity()
    {
        foreach (WC()->cart->get_cart() as $cart_item) {
            $product_id = $cart_item['product_id'];
            $quantity = $cart_item['quantity'];
            $product_name = $cart_item['data']->get_name();

            $min_quantity = get_post_meta($product_id, '_min_order_quantity', true);
            $max_quantity = get_post_meta($product_id, '_max_order_quantity', true);

            if (!empty($min_quantity) && $quantity < $min_quantity) {
                wc_add_notice(
                    sprintf(__('Product "%s" requires a minimum quantity of %s.', 'woocommerce'), $product_name, $min_quantity),
                    'error'
                );
            }

            if (!empty($max_quantity) && $quantity > $max_quantity) {
                wc_add_notice(
                    sprintf(__('Product "%s" allows a maximum quantity of %s.', 'woocommerce'), $product_name, $max_quantity),
                    'error'
                );
            }
        }
    }
}
