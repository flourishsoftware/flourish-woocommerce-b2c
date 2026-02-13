<?php

namespace FlourishWooCommercePlugin\API;

use FlourishWooCommercePlugin\Importer\FlourishItems;
use FlourishWooCommercePlugin\Helpers\HttpRequestHelper;

defined('ABSPATH') || exit;

class FlourishAPI
{
    const API_LIMIT = 50;

    public $api_key;
    public $url;
    public $facility_id;
    public $auth_header;
    private $item_sync_options;

    /**
     * Constructor — uses x-api-key authentication.
     *
     * FIX (Auth Migration): Uses x-api-key header instead of username/password
     * or Bearer token authentication.
     *
     * @param string $api_key The service-based API key from Flourish
     * @param string $url The API base URL
     * @param string $facility_id The facility ID
     * @param array  $item_sync_options Options for item sync (name, price, description flags)
     */
    public function __construct($api_key, $url, $facility_id, $item_sync_options = [])
    {
        $this->api_key = $api_key;
        $this->url = $url;
        $this->facility_id = $facility_id;
        $this->auth_header = $api_key;
        $this->item_sync_options = $item_sync_options;
    }

    /**
     * Get headers for API requests.
     *
     * FIX (Auth Migration): Uses x-api-key header.
     */
    private function get_headers($include_facility_id = false, $include_content_type = false)
    {
        $headers = [
            'x-api-key: ' . $this->auth_header
        ];

        if ($include_facility_id && $this->facility_id) {
            $headers[] = 'FacilityID: ' . $this->facility_id;
        }

        if ($include_content_type) {
            $headers[] = 'Content-Type: application/json';
        }

        return $headers;
    }

    /**
     * Fetch and import a single product by its Flourish item ID.
     *
     * FIX (Critical #7): Was referencing $this->existing_settings which didn't exist.
     * Now uses $this->item_sync_options which is passed via the constructor.
     */
    public function fetch_product_by_id($item_id)
    {
        $api_url = $this->url . "/external/api/v1/items?item_id=$item_id";
        $headers = $this->get_headers();

        try {
            $response_http = HttpRequestHelper::make_request($api_url, 'GET', $headers);
            $response_data = HttpRequestHelper::validate_response($response_http);
        } catch (\Exception $e) {
            throw new \Exception("Error fetching item: " . $e->getMessage());
        }

        if (!isset($response_data['data']) || !is_array($response_data['data']) || empty($response_data['data'])) {
            return;
        }

        $inventory_data = $this->fetch_inventory($item_id);
        if (isset($inventory_data[0]['sellable_qty'])) {
            $response_data['data'][0]['inventory_quantity'] = $inventory_data[0]['sellable_qty'];
        } else {
            $response_data['data'][0]['inventory_quantity'] = 0;
        }

        $flourish_items = new FlourishItems($response_data['data']);
        $webhook_status = true;
        $flourish_items->save_as_woocommerce_products($this->item_sync_options, $webhook_status);
    }

    /**
     * Fetch all products with pagination.
     *
     * FIX (Critical #6): Added max retry count to prevent infinite loop on
     * persistent API failure. Was previously: sleep(60); continue; — which
     * loops forever if the API is down.
     *
     * FIX (Critical #7): Uses $this->item_sync_options instead of undefined
     * $this->existing_settings.
     */
    public function fetch_products($filter_brands = false, $brands = [])
    {
        $offset = 0;
        $limit = self::API_LIMIT;
        $total_imported_count = 0;
        $all_products = [];
        $consecutive_failures = 0;
        $max_failures = 3;

        while (true) {
            $api_url = $this->url . "/external/api/v1/items?active=true&ecommerce_active=true&offset={$offset}&limit={$limit}";

            if ($filter_brands && !empty($brands)) {
                $brand_query = array_map('urlencode', $brands);
                $api_url .= "&" . implode("&", array_map(fn($brand) => "brand_name={$brand}", $brand_query));
            }

            $headers = $this->get_headers();

            try {
                $response_http = HttpRequestHelper::make_request($api_url, 'GET', $headers);
                $response_data = HttpRequestHelper::validate_response($response_http);
                $consecutive_failures = 0;
            } catch (\Exception $e) {
                $consecutive_failures++;
                error_log("Error fetching products (attempt {$consecutive_failures}): " . $e->getMessage());

                if ($consecutive_failures >= $max_failures) {
                    error_log("Aborting product fetch after {$max_failures} consecutive failures.");
                    break;
                }

                sleep(min(60, pow(2, $consecutive_failures)));
                continue;
            }

            if (!isset($response_data['data']) || !is_array($response_data['data'])) {
                break;
            }

            $all_products = array_merge($all_products, $response_data['data']);

            if (count($response_data['data']) < $limit) {
                break;
            }

            $offset += $limit;
        }

        // Fetch inventory in bulk
        $item_ids = array_column($all_products, 'id');
        $inventory_map = $this->fetch_bulk_inventory($item_ids);

        // Process in batches
        $batch = [];
        foreach ($all_products as $product) {
            $product['inventory_quantity'] = $inventory_map[$product['id']] ?? 0;
            $batch[] = $product;

            if (count($batch) === 50) {
                $total_imported_count += $this->process_batch($batch);
                $batch = [];
            }
        }

        if (!empty($batch)) {
            $total_imported_count += $this->process_batch($batch);
        }

        return $total_imported_count;
    }

    private function fetch_bulk_inventory($item_ids)
    {
        $inventory_map = [];
        $limit = 50;
        $chunks = array_chunk($item_ids, $limit);

        foreach ($chunks as $batch) {
            $query_params = implode('&', array_map(fn($id) => "item_id={$id}", $batch));
            $api_url = $this->url . "/external/api/v1/inventory/summary?" . $query_params;
            $headers = $this->get_headers(true);

            try {
                $response_http = HttpRequestHelper::make_request($api_url, 'GET', $headers);
                $response_data = HttpRequestHelper::validate_response($response_http);
            } catch (\Exception $e) {
                error_log("Error fetching bulk inventory: " . $e->getMessage());
                continue;
            }

            if (!isset($response_data['data']) || !is_array($response_data['data'])) {
                continue;
            }

            foreach ($response_data['data'] as $inventory) {
                $inventory_map[$inventory['item_id']] = $inventory['sellable_qty'] ?? 0;
            }
        }

        return $inventory_map;
    }

    private function process_batch($batch)
    {
        try {
            $flourish_items = new FlourishItems($batch);
            $webhook_status = false;
            $imported_count = $flourish_items->save_as_woocommerce_products($this->item_sync_options, $webhook_status);
            unset($flourish_items);
            return $imported_count;
        } catch (\Exception $e) {
            error_log("Error importing batch: " . $e->getMessage());
            return 0;
        }
    }

    public function fetch_facilities()
    {
        $facilities = [];
        $offset = 0;
        $limit = self::API_LIMIT;
        $has_more = true;

        while ($has_more) {
            $api_url = $this->url . "/external/api/v1/facilities?offset={$offset}&limit={$limit}";
            $headers = $this->get_headers();

            try {
                $response_http = HttpRequestHelper::make_request($api_url, 'GET', $headers);
                $response_data = HttpRequestHelper::validate_response($response_http);
            } catch (\Exception $e) {
                throw new \Exception("Error fetching facilities: " . $e->getMessage());
            }

            if (isset($response_data['data']) && is_array($response_data['data'])) {
                $facilities = array_merge($facilities, $response_data['data']);
            }

            $has_more = isset($response_data['meta']['next']) && !empty($response_data['meta']['next']);
            $offset += $limit;
        }

        return $facilities;
    }

    public function fetch_facility_config($facility_id)
    {
        $api_url = $this->url . "/external/api/v1/facilities/{$facility_id}";
        $headers = $this->get_headers();

        try {
            $response_http = HttpRequestHelper::make_request($api_url, 'GET', $headers);
            $response_data = HttpRequestHelper::validate_response($response_http);
        } catch (\Exception $e) {
            if (isset($response_http['http_code']) && ($response_http['http_code'] == 400 || $response_http['http_code'] == 401)) {
                return true;
            }
            throw new \Exception("Error fetching facility config: " . $e->getMessage());
        }

        if (isset($response_data['data']) && is_array($response_data['data'])) {
            return $response_data['data'];
        }

        return false;
    }

    public function fetch_inventory($item_id)
    {
        $api_url = $this->url . "/external/api/v1/inventory/summary?item_id=$item_id";
        $headers = $this->get_headers(true);

        try {
            $response_http = HttpRequestHelper::make_request($api_url, 'GET', $headers);
            $response_data = HttpRequestHelper::validate_response($response_http);
        } catch (\Exception $e) {
            throw new \Exception("Error fetching inventory: " . $e->getMessage());
        }

        if (isset($response_data['data']) && is_array($response_data['data'])) {
            return $response_data['data'];
        }

        return [];
    }

    /**
     * Validate that an item exists in Flourish.
     *
     * Ported from B2B PR #5 fix for mixed CBD/THC orders failing.
     */
    public function flourish_item_exists($item_id, $sku)
    {
        if (empty($item_id) && empty($sku)) {
            return false;
        }

        try {
            $query = $item_id ? "item_id={$item_id}" : "sku=" . urlencode($sku);
            $api_url = $this->url . "/external/api/v1/items?" . $query;
            $headers = $this->get_headers();

            $response_http = HttpRequestHelper::make_request($api_url, 'GET', $headers);
            $response_data = HttpRequestHelper::validate_response($response_http);

            return isset($response_data['data']) && is_array($response_data['data']) && count($response_data['data']) > 0;
        } catch (\Exception $e) {
            error_log("Error checking if item exists in Flourish: " . $e->getMessage());
            return false;
        }
    }

    public function get_or_create_customer_by_email($customer)
    {
        $api_url = $this->url . "/external/api/v1/customers?email=" . urlencode($customer['email']);
        $headers = $this->get_headers();

        try {
            $response_http = HttpRequestHelper::make_request($api_url, 'GET', $headers);
            $response_data = HttpRequestHelper::validate_response($response_http);
        } catch (\Exception $e) {
            throw new \Exception("Error fetching customer: " . $e->getMessage());
        }

        if (isset($response_data['data']) && is_array($response_data['data'])) {
            if (count($response_data['data'])) {
                $customer['flourish_customer_id'] = $response_data['data'][0]['id'];
            } else {
                return $this->create_customer($customer);
            }
        } else {
            throw new \Exception('Invalid API response format.');
        }

        return $customer;
    }

    private function create_customer($customer)
    {
        if (empty($customer['dob'])) {
            if (function_exists('wc_add_notice')) {
                \wc_add_notice(\__('Date of Birth is required. Please update your account details.', 'woocommerce'), 'error');
            }
            throw new \Exception('Date of Birth is required.');
        }

        $api_url = $this->url . "/external/api/v1/customers";
        $headers = $this->get_headers(false, true);

        try {
            $response_http = HttpRequestHelper::make_request($api_url, 'POST', $headers, json_encode($customer));
            $response_data = HttpRequestHelper::validate_response($response_http);
        } catch (\Exception $e) {
            throw new \Exception("Error creating customer: " . $e->getMessage());
        }

        if (!isset($response_data['data']['id'])) {
            throw new \Exception("Customer created but no ID returned from Flourish.");
        }

        $customer['flourish_customer_id'] = $response_data['data']['id'];

        return $customer;
    }

    public function create_retail_order($order)
    {
        $api_url = $this->url . "/external/api/v2/retail-orders";
        $headers = $this->get_headers(true, true);

        try {
            $response_http = HttpRequestHelper::make_request($api_url, 'POST', $headers, json_encode($order));
            $response_data = HttpRequestHelper::validate_response($response_http);
        } catch (\Exception $e) {
            throw new \Exception("Error creating retail order: " . $e->getMessage());
        }

        if (!isset($response_data['data']['id'])) {
            throw new \Exception("Retail order created but no ID returned from Flourish.");
        }

        return $response_data['data']['id'];
    }

    public function update_retail_order($order, $flourish_order_id)
    {
        $api_url = $this->url . "/external/api/v1/retail-orders/{$flourish_order_id}";
        $headers = $this->get_headers(true, true);

        try {
            $response_http = HttpRequestHelper::make_request($api_url, 'PUT', $headers, json_encode($order));
            $response_data = HttpRequestHelper::validate_response($response_http);
        } catch (\Exception $e) {
            throw new \Exception("Error updating retail order: " . $e->getMessage());
        }

        if (!isset($response_data['data']['id'])) {
            throw new \Exception("Retail order updated but no ID returned from Flourish.");
        }

        return $response_data['data']['id'];
    }

    public function get_order_by_id($order_id, $order_type_api)
    {
        $api_url = $this->url . "/external/api/v1/{$order_type_api}/{$order_id}";
        $headers = $this->get_headers();

        try {
            $response_http = HttpRequestHelper::make_request($api_url, 'GET', $headers);
            $response_data = HttpRequestHelper::validate_response($response_http);
        } catch (\Exception $e) {
            throw new \Exception("Error fetching order by ID: " . $e->getMessage());
        }

        if (isset($response_data['data']) && is_array($response_data['data'])) {
            return $response_data['data'];
        }

        throw new \Exception('Invalid API response format.');
    }

    public function fetch_brands()
    {
        $brands = [];
        $offset = 0;
        $limit = self::API_LIMIT;
        $has_more = true;

        while ($has_more) {
            $api_url = $this->url . "/external/api/v1/brands?offset={$offset}&limit={$limit}";
            $headers = $this->get_headers();

            try {
                $response_http = HttpRequestHelper::make_request($api_url, 'GET', $headers);
                $response_data = HttpRequestHelper::validate_response($response_http);
            } catch (\Exception $e) {
                throw new \Exception("Error fetching brands: " . $e->getMessage());
            }

            if (isset($response_data['data']) && is_array($response_data['data'])) {
                $brands = array_merge($brands, $response_data['data']);
            }

            $has_more = isset($response_data['meta']['next']) && !empty($response_data['meta']['next']);
            $offset += $limit;
        }

        return $brands;
    }

    public function fetch_uoms()
    {
        $uoms = [];
        $offset = 0;
        $limit = self::API_LIMIT;
        $has_more = true;

        while ($has_more) {
            $api_url = $this->url . "/external/api/v1/uoms?offset={$offset}&limit={$limit}";
            $headers = $this->get_headers();

            try {
                $response_http = HttpRequestHelper::make_request($api_url, 'GET', $headers);
                $response_data = HttpRequestHelper::validate_response($response_http);
            } catch (\Exception $e) {
                if (str_contains($e->getMessage(), '401')) {
                    return 'Authorization denied. Please check your API key.';
                }
                return 'An error occurred while fetching UOMs. Please try again later.';
            }

            if (isset($response_data['data']) && is_array($response_data['data'])) {
                $uoms = array_merge($uoms, $response_data['data']);
            }

            $has_more = isset($response_data['meta']['next']) && !empty($response_data['meta']['next']);
            $offset += $limit;
        }

        return $uoms;
    }
}
