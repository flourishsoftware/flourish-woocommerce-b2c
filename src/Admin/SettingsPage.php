<?php

namespace FlourishWooCommercePlugin\Admin;

defined('ABSPATH') || exit;

use FlourishWooCommercePlugin\API\FlourishAPI;
use FlourishWooCommercePlugin\Handlers\SettingsHandler;

/**
 * Flourish settings page for WooCommerce B2C retail.
 *
 * Simplified from the B2B version — removed outbound order settings,
 * draft order management, destination/license fields, and sales rep config.
 *
 * FIX (High #14): Uses esc_html()/esc_attr() for all output. Uses site_url()
 * instead of raw $_SERVER['HTTP_HOST'].
 *
 * FIX (High #18): Added nonce verification on fetch_inventory AJAX handler.
 *
 * FIX (High #17): Updated WC version headers.
 * FIX (High #16): Update checker points to correct B2C repository.
 */
class SettingsPage
{
    public $plugin_basename;
    public $existing_settings;

    public function __construct($existing_settings, $plugin_basename)
    {
        $this->existing_settings = $existing_settings ?: [];
        $this->plugin_basename = $plugin_basename;
    }

    public function register_hooks()
    {
        add_action('add_meta_boxes', [$this, 'add_refresh_inventory_button_meta_box']);
        add_action('wp_ajax_fetch_inventory', [$this, 'fetch_inventory_callback']);

        add_filter('plugin_action_links_' . $this->plugin_basename, [$this, 'add_settings_link']);

        add_action('admin_menu', function () {
            add_options_page(
                'Flourish WooCommerce B2C Settings',
                'Flourish B2C',
                'manage_options',
                'flourish-woocommerce-plugin-settings',
                [$this, 'render_settings_page']
            );
        });

        add_action('admin_init', function () {
            // Register settings - must be in admin_init
            $this->register_settings();

            // Enqueue admin scripts and styles
            wp_enqueue_style(
                'flourish-admin-style',
                plugin_dir_url(dirname(dirname(__FILE__))) . 'assets/css/style.css',
                [],
                '1.0.0'
            );

            wp_enqueue_script(
                'flourish-admin-js',
                plugin_dir_url(dirname(dirname(__FILE__))) . 'assets/js/flourish-woocommerce-plugin.js',
                ['jquery'],
                '1.0.0',
                true
            );
        });

        // Enqueue frontend styles
        add_action('wp_enqueue_scripts', function () {
            wp_enqueue_style(
                'flourish-frontend-style',
                plugin_dir_url(dirname(dirname(__FILE__))) . 'assets/css/style.css',
                [],
                '1.0.0'
            );
        });
    }

    public function add_settings_link($links)
    {
        $settings_link = '<a href="options-general.php?page=flourish-woocommerce-plugin-settings">Settings</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    public function register_settings()
    {
        register_setting('flourish_woocommerce_plugin_settings', 'flourish_woocommerce_plugin_settings', [
            'sanitize_callback' => [$this, 'sanitize_settings'],
        ]);
    }

    public function sanitize_settings($input)
    {
        $sanitized = [];

        $sanitized['api_key'] = sanitize_text_field($input['api_key'] ?? '');
        $sanitized['url'] = esc_url_raw($input['url'] ?? 'https://app.flourishsoftware.com');
        $sanitized['facility_id'] = sanitize_text_field($input['facility_id'] ?? '');
        $sanitized['minimum_age'] = absint($input['minimum_age'] ?? 21);
        $sanitized['order_status'] = in_array($input['order_status'] ?? '', ['created', 'submitted'])
            ? $input['order_status']
            : 'submitted';

        // Default to true for new installations, otherwise respect checkbox value
        if (!isset($this->existing_settings['is_recreational'])) {
            // First time saving - default to true
            $sanitized['is_recreational'] = isset($input['is_recreational']) ? !empty($input['is_recreational']) : true;
        } else {
            // Setting exists - use checkbox value (unchecked = false)
            $sanitized['is_recreational'] = !empty($input['is_recreational']);
        }

        // Generate webhook key from API key
        if (!empty($sanitized['api_key'])) {
            $sanitized['webhook_key'] = hash('sha256', $sanitized['api_key'] . wp_salt());
        }

        // Brand filtering
        $sanitized['filter_brands'] = !empty($input['filter_brands']);
        $sanitized['brands'] = [];
        if (!empty($input['brands']) && is_array($input['brands'])) {
            $sanitized['brands'] = array_map('sanitize_text_field', $input['brands']);
        }

        // Item sync options
        $sanitized['item_sync_options'] = [
            'name'        => !empty($input['item_sync_options']['name']),
            'description' => !empty($input['item_sync_options']['description']),
            'price'       => !empty($input['item_sync_options']['price']),
        ];

        return $sanitized;
    }

    /**
     * AJAX handler for fetching inventory.
     *
     * FIX (High #18): Added nonce verification for CSRF protection.
     */
    public function fetch_inventory_callback()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized.');
        }

        check_ajax_referer('flourish_fetch_inventory', 'nonce');

        $settings = get_option('flourish_woocommerce_plugin_settings', []);
        if (empty($settings['api_key']) || empty($settings['url'])) {
            wp_send_json_error('API key and URL are required.');
        }

        try {
            $flourish_api = new FlourishAPI(
                $settings['api_key'],
                $settings['url'],
                $settings['facility_id'] ?? '',
                $settings['item_sync_options'] ?? []
            );

            $filter_brands = !empty($settings['filter_brands']);
            $brands = $settings['brands'] ?? [];
            $count = $flourish_api->fetch_products($filter_brands, $brands);

            wp_send_json_success("Successfully imported/updated {$count} products.");
        } catch (\Exception $e) {
            wp_send_json_error('Error: ' . $e->getMessage());
        }
    }

    public function add_refresh_inventory_button_meta_box()
    {
        add_meta_box(
            'flourish_refresh_inventory',
            'Flourish Inventory',
            [$this, 'render_refresh_inventory_meta_box'],
            'product',
            'side',
            'high'
        );
    }

    public function render_refresh_inventory_meta_box($post)
    {
        $flourish_item_id = get_post_meta($post->ID, 'flourish_item_id', true);
        if ($flourish_item_id) {
            echo '<p>Flourish Item ID: <strong>' . esc_html($flourish_item_id) . '</strong></p>';
        }
    }

    /**
     * Render the settings page.
     *
     * FIX (High #14): All output is properly escaped with esc_html()/esc_attr().
     * Uses site_url() instead of raw $_SERVER['HTTP_HOST'].
     */
    public function render_settings_page()
    {
        $settings = $this->existing_settings;
        $nonce = wp_create_nonce('flourish_fetch_inventory');

        // Fetch available brands if API is configured
        $available_brands = [];
        if (!empty($settings['api_key']) && !empty($settings['url'])) {
            try {
                $api = new FlourishAPI(
                    $settings['api_key'],
                    $settings['url'],
                    $settings['facility_id'] ?? ''
                );
                $brand_data = $api->fetch_brands();
                // API returns: { "brand_id": 16, "brand_name": "BuildCO", "active": 1 }
                if (is_array($brand_data)) {
                    $available_brands = array_column($brand_data, 'brand_name');
                }
            } catch (\Exception $e) {
                // Silently fail — brands will just be empty
            }
        }

        // Fetch available facilities
        $available_facilities = [];
        if (!empty($settings['api_key']) && !empty($settings['url'])) {
            try {
                $api = $api ?? new FlourishAPI($settings['api_key'], $settings['url'], $settings['facility_id'] ?? '');
                $raw_facilities = $api->fetch_facilities();

                // Normalize facility data structure
                // API returns: { "id": "...", "facility_name": "...", "alias": "..." }
                if (is_array($raw_facilities)) {
                    foreach ($raw_facilities as $facility) {
                        if (!empty($facility['id'])) {
                            // Build display name: "Facility Name - Alias" or just "Facility Name"
                            $display_name = $facility['facility_name'] ?? 'Unknown Facility';
                            if (!empty($facility['alias'])) {
                                $display_name .= ' - ' . $facility['alias'];
                            }

                            $available_facilities[] = [
                                'id' => $facility['id'],
                                'name' => $display_name
                            ];
                        }
                    }
                }
            } catch (\Exception $e) {
                // Log errors for debugging
                error_log('Flourish API Error fetching facilities: ' . $e->getMessage());
            }
        }

        ?>
        <div class="wrap">
            <h1>Flourish WooCommerce B2C Settings</h1>

            <?php if (empty($settings['api_key']) || empty($settings['facility_id'])): ?>
                <div class="flourish_setting_warn">
                    <strong>Setup Required:</strong> Please enter your API Key and select a Facility ID to get started.
                </div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php settings_fields('flourish_woocommerce_plugin_settings'); ?>

                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="api_key">API Key</label></th>
                        <td>
                            <input type="<?php echo empty($settings['api_key']) ? 'text' : 'password'; ?>"
                                   id="api_key"
                                   name="flourish_woocommerce_plugin_settings[api_key]"
                                   value="<?php echo esc_attr($settings['api_key'] ?? ''); ?>"
                                   class="regular-text" />
                            <p class="description">Your Flourish API key (x-api-key authentication).</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="url">API URL</label></th>
                        <td>
                            <input type="url"
                                   id="url"
                                   name="flourish_woocommerce_plugin_settings[url]"
                                   value="<?php echo esc_attr($settings['url'] ?? 'https://app.flourishsoftware.com'); ?>"
                                   class="regular-text" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="facility_id">Facility</label></th>
                        <td>
                            <?php if (!empty($available_facilities)): ?>
                                <select id="facility_id" name="flourish_woocommerce_plugin_settings[facility_id]">
                                    <option value="">-- Select Facility --</option>
                                    <?php foreach ($available_facilities as $facility): ?>
                                        <option value="<?php echo esc_attr($facility['id']); ?>"
                                            <?php selected($settings['facility_id'] ?? '', $facility['id']); ?>>
                                            <?php echo esc_html($facility['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            <?php else: ?>
                                <input type="text"
                                       id="facility_id"
                                       name="flourish_woocommerce_plugin_settings[facility_id]"
                                       value="<?php echo esc_attr($settings['facility_id'] ?? ''); ?>"
                                       class="regular-text" />
                                <p class="description">Save your API key and URL first to load available facilities.</p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="minimum_age">Minimum Age</label></th>
                        <td>
                            <input type="number"
                                   id="minimum_age"
                                   name="flourish_woocommerce_plugin_settings[minimum_age]"
                                   value="<?php echo esc_attr($settings['minimum_age'] ?? 21); ?>"
                                   min="18"
                                   max="99"
                                   class="small-text" />
                            <p class="description">Minimum age required for CBD/hemp purchases (default: 21).</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="order_status">Order Status</label></th>
                        <td>
                            <select id="order_status" name="flourish_woocommerce_plugin_settings[order_status]">
                                <option value="submitted" <?php selected($settings['order_status'] ?? 'submitted', 'submitted'); ?>>
                                    Submitted (Ready to Pack)
                                </option>
                                <option value="created" <?php selected($settings['order_status'] ?? 'submitted', 'created'); ?>>
                                    Created (Draft)
                                </option>
                            </select>
                            <p class="description">Status for orders posted to Flourish. "Submitted" means ready to pack (recommended for most use cases).</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="is_recreational">Recreational Purchases</label></th>
                        <td>
                            <label>
                                <input type="checkbox"
                                       id="is_recreational"
                                       name="flourish_woocommerce_plugin_settings[is_recreational]"
                                       value="1"
                                    <?php checked(!empty($settings['is_recreational']) ? $settings['is_recreational'] : true); ?> />
                                Mark orders as recreational (not medical)
                            </label>
                            <p class="description">Indicates purchases are recreational, subject to compliance rules of the facility state.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Webhook URL</th>
                        <td>
                            <code><?php echo esc_html(site_url('/wp-json/flourish-woocommerce-plugin/v1/webhook')); ?></code>
                            <p class="description">Configure this URL in your Flourish webhook settings.</p>
                        </td>
                    </tr>
                    <?php if (!empty($settings['webhook_key'])): ?>
                    <tr>
                        <th scope="row">Webhook Signing Key</th>
                        <td>
                            <input type="text"
                                   value="<?php echo esc_attr($settings['webhook_key']); ?>"
                                   class="regular-text"
                                   readonly />
                            <p class="description">Use this key in Flourish to sign webhook requests (HMAC SHA-256).</p>
                        </td>
                    </tr>
                    <?php endif; ?>
                </table>

                <h2>Item Sync Options</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">Sync Fields on Webhook</th>
                        <td>
                            <fieldset>
                                <label>
                                    <input type="checkbox"
                                           name="flourish_woocommerce_plugin_settings[item_sync_options][name]"
                                           value="1"
                                        <?php checked(!empty($settings['item_sync_options']['name'])); ?> />
                                    Product Name
                                </label><br>
                                <label>
                                    <input type="checkbox"
                                           name="flourish_woocommerce_plugin_settings[item_sync_options][description]"
                                           value="1"
                                        <?php checked(!empty($settings['item_sync_options']['description'])); ?> />
                                    Product Description
                                </label><br>
                                <label>
                                    <input type="checkbox"
                                           name="flourish_woocommerce_plugin_settings[item_sync_options][price]"
                                           value="1"
                                        <?php checked(!empty($settings['item_sync_options']['price'])); ?> />
                                    Product Price
                                </label>
                            </fieldset>
                            <p class="description">Select which fields to update when Flourish sends an item webhook. Stock is always synced.</p>
                        </td>
                    </tr>
                </table>

                <h2>Brand Filtering</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">Filter by Brand</th>
                        <td>
                            <label>
                                <input type="checkbox"
                                       id="flourish-woocommerce-plugin-filter-brands"
                                       name="flourish_woocommerce_plugin_settings[filter_brands]"
                                       value="1"
                                    <?php checked(!empty($settings['filter_brands'])); ?> />
                                Only import products from selected brands
                            </label>
                        </td>
                    </tr>
                    <tr id="flourish-woocommerce-plugin-brand-selection"
                        style="<?php echo empty($settings['filter_brands']) ? 'display:none;' : ''; ?>">
                        <th scope="row">Select Brands</th>
                        <td>
                            <?php if (!empty($available_brands)): ?>
                                <?php
                                $selected_brands = $settings['brands'] ?? [];
                                foreach ($available_brands as $brand):
                                    ?>
                                    <label>
                                        <input type="checkbox"
                                               name="flourish_woocommerce_plugin_settings[brands][]"
                                               value="<?php echo esc_attr($brand); ?>"
                                            <?php checked(in_array($brand, $selected_brands)); ?> />
                                        <?php echo esc_html($brand); ?>
                                    </label><br>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p class="description">Save your API settings first to load available brands.</p>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>

            <hr>
            <h2>Import Products</h2>
            <p>Click below to import or refresh all products from Flourish.</p>
            <button type="button" class="button button-primary" id="flourish-fetch-inventory">
                Fetch Inventory from Flourish
            </button>
            <div id="flourish-fetch-result" style="margin-top: 10px;"></div>

            <script>
            jQuery(document).ready(function($) {
                $('#flourish-fetch-inventory').on('click', function() {
                    var $btn = $(this);
                    var $result = $('#flourish-fetch-result');

                    $btn.prop('disabled', true).text('Fetching...');
                    $result.html('<p>Importing products from Flourish. This may take a few minutes...</p>');

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'fetch_inventory',
                            nonce: '<?php echo esc_js($nonce); ?>'
                        },
                        success: function(response) {
                            if (response.success) {
                                $result.html('<div class="notice notice-success"><p>' + response.data + '</p></div>');
                            } else {
                                $result.html('<div class="notice notice-error"><p>' + response.data + '</p></div>');
                            }
                        },
                        error: function() {
                            $result.html('<div class="notice notice-error"><p>Request failed. Please try again.</p></div>');
                        },
                        complete: function() {
                            $btn.prop('disabled', false).text('Fetch Inventory from Flourish');
                        }
                    });
                });
            });
            </script>
        </div>
        <?php
    }
}
