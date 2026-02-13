<?php

namespace FlourishWooCommercePlugin\Helpers;

defined('ABSPATH') || exit;

/**
 * HTTP request helper.
 *
 * FIX (High #12): Migrated from raw cURL to WordPress HTTP API (wp_remote_*).
 * This ensures compatibility with WordPress proxy settings, SSL configuration,
 * and environments where cURL may be disabled.
 *
 * FIX (High #27): Added retry logic with exponential backoff for transient failures.
 */
class HttpRequestHelper
{
    const MAX_RETRIES = 3;

    /**
     * Make an HTTP request using the WordPress HTTP API.
     *
     * @param string $url
     * @param string $method GET, POST, PUT, DELETE
     * @param array  $headers Array of header strings like ['x-api-key: abc123']
     * @param string|null $body Request body (for POST/PUT)
     * @return array ['body' => string, 'http_code' => int]
     * @throws \Exception on failure after retries
     */
    public static function make_request($url, $method = 'GET', $headers = [], $body = null)
    {
        // Convert header strings to associative array for WordPress HTTP API
        $wp_headers = [];
        foreach ($headers as $header) {
            $parts = explode(': ', $header, 2);
            if (count($parts) === 2) {
                $wp_headers[trim($parts[0])] = trim($parts[1]);
            }
        }

        $args = [
            'method'  => $method,
            'headers' => $wp_headers,
            'timeout' => 30,
        ];

        if ($body !== null && in_array($method, ['POST', 'PUT', 'PATCH'])) {
            $args['body'] = $body;
        }

        $last_error = null;

        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            $response = wp_remote_request($url, $args);

            if (is_wp_error($response)) {
                $last_error = $response->get_error_message();
                if ($attempt < self::MAX_RETRIES) {
                    sleep(pow(2, $attempt)); // Exponential backoff: 2s, 4s
                }
                continue;
            }

            return [
                'body'      => wp_remote_retrieve_body($response),
                'http_code' => wp_remote_retrieve_response_code($response),
            ];
        }

        throw new \Exception("HTTP request failed after " . self::MAX_RETRIES . " attempts: " . $last_error);
    }

    /**
     * Validate the HTTP response.
     *
     * @param array $response ['body' => string, 'http_code' => int]
     * @return array Decoded JSON response data
     * @throws \Exception if response indicates an error
     */
    public static function validate_response($response)
    {
        $http_code = $response['http_code'] ?? 0;
        $body = $response['body'] ?? '';

        if ($http_code < 200 || $http_code >= 300) {
            throw new \Exception("HTTP error {$http_code}: {$body}");
        }

        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception("Invalid JSON response: " . json_last_error_msg());
        }

        return $data;
    }

    /**
     * Send order failure email to admin.
     *
     * @param string $error_message
     * @param int $order_id
     * @return bool
     */
    public static function send_order_failure_email_to_admin($error_message, $order_id)
    {
        $admin_email = get_option('admin_email');
        $subject = sprintf('Flourish Order Sync Failed - Order #%d', $order_id);
        $message = sprintf(
            "The following order failed to sync with Flourish:\n\nOrder ID: %d\nError: %s\n\nPlease check the order and retry the sync.",
            $order_id,
            $error_message
        );

        return wp_mail($admin_email, $subject, $message);
    }
}
