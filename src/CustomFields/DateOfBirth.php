<?php

namespace FlourishWooCommercePlugin\CustomFields;

defined('ABSPATH') || exit;

/**
 * Handles Date of Birth field for checkout.
 *
 * FIX (Critical #3): REST endpoints now require authentication.
 * GET endpoint requires logged-in user and only returns their own DOB.
 * POST endpoint requires a valid WooCommerce checkout nonce.
 *
 * FIX (Critical #5): Added age verification. Customers must be at least
 * the configured minimum age (default 21) for CBD/hemp purchases.
 */
class DateOfBirth
{
    const DEFAULT_MINIMUM_AGE = 21;

    private $existing_settings;

    public function __construct($existing_settings = [])
    {
        $this->existing_settings = $existing_settings;
    }

    public function register_hooks()
    {
        add_action('rest_api_init', [$this, 'register_dob_routes']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_dob_scripts']);
        add_action('woocommerce_checkout_process', [$this, 'validate_dob_field']);
        add_action('woocommerce_checkout_update_order_meta', [$this, 'save_dob_to_order']);
    }

    /**
     * Register REST routes with proper authentication.
     *
     * FIX (Critical #3): GET endpoint requires logged-in user. POST endpoint
     * verifies a nonce to prevent CSRF.
     */
    public function register_dob_routes()
    {
        register_rest_route('flourish/v1', '/get-dob', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_dob_via_rest'],
            'permission_callback' => function () {
                return is_user_logged_in();
            },
        ]);

        register_rest_route('flourish/v1', '/save-dob', [
            'methods'             => 'POST',
            'callback'            => [$this, 'save_dob_via_rest'],
            'permission_callback' => function ($request) {
                $nonce = $request->get_header('X-WP-Nonce');
                return wp_verify_nonce($nonce, 'wp_rest');
            },
        ]);
    }

    /**
     * GET DOB — only returns the current user's own DOB.
     *
     * FIX (Critical #3): No longer accepts arbitrary email addresses.
     */
    public function get_dob_via_rest(\WP_REST_Request $request)
    {
        $user = wp_get_current_user();
        if (!$user || !$user->ID) {
            return new \WP_REST_Response(['status' => 'error', 'message' => 'Not authenticated.'], 401);
        }

        $dob = get_user_meta($user->ID, 'dob', true);

        if ($dob) {
            return new \WP_REST_Response(['status' => 'success', 'dob' => $dob], 200);
        }

        return new \WP_REST_Response(['status' => 'error', 'message' => 'No DOB found.'], 404);
    }

    /**
     * Save DOB via REST — stores against the current user or in a session transient.
     *
     * FIX (Critical #3): Validates and sanitizes DOB input. Stores against
     * authenticated user only.
     */
    public function save_dob_via_rest(\WP_REST_Request $request)
    {
        $params = $request->get_json_params();
        $dob = sanitize_text_field($params['dob'] ?? '');
        $email = sanitize_email($params['email'] ?? '');

        if (empty($dob)) {
            return new \WP_REST_Response(['status' => 'error', 'message' => 'DOB is required.'], 400);
        }

        // Validate DOB format
        $dob_date = \DateTime::createFromFormat('Y-m-d', $dob);
        if (!$dob_date || $dob_date->format('Y-m-d') !== $dob) {
            return new \WP_REST_Response(['status' => 'error', 'message' => 'Invalid date format.'], 400);
        }

        // Age verification
        $min_age = $this->existing_settings['minimum_age'] ?? self::DEFAULT_MINIMUM_AGE;
        if (!$this->is_of_legal_age($dob, $min_age)) {
            return new \WP_REST_Response([
                'status'  => 'error',
                'message' => sprintf('You must be at least %d years old.', $min_age),
            ], 403);
        }

        // Store DOB for the current user
        $user = wp_get_current_user();
        if ($user && $user->ID) {
            update_user_meta($user->ID, 'dob', $dob);
        }

        // Also store in transient for guest checkout (keyed by email)
        if (!empty($email)) {
            set_transient('flourish_dob_' . md5($email), $dob, HOUR_IN_SECONDS);
        }

        return new \WP_REST_Response(['status' => 'success', 'message' => 'DOB saved.'], 200);
    }

    /**
     * Validate DOB during checkout.
     *
     * FIX (Critical #5): Added age verification. The original code collected
     * DOB but never validated that the customer was of legal age.
     *
     * FIX (High #15): Replaced deprecated FILTER_SANITIZE_STRING with
     * sanitize_text_field().
     */
    public function validate_dob_field()
    {
        $dob = sanitize_text_field($_POST['dob'] ?? '');

        if (empty($dob)) {
            wc_add_notice(__('Date of Birth is required for this purchase.', 'flourish-woocommerce'), 'error');
            return;
        }

        $dob_date = \DateTime::createFromFormat('Y-m-d', $dob);
        if (!$dob_date || $dob_date->format('Y-m-d') !== $dob) {
            wc_add_notice(__('Please enter a valid Date of Birth.', 'flourish-woocommerce'), 'error');
            return;
        }

        $min_age = $this->existing_settings['minimum_age'] ?? self::DEFAULT_MINIMUM_AGE;
        if (!$this->is_of_legal_age($dob, $min_age)) {
            wc_add_notice(
                sprintf(__('You must be at least %d years old to purchase these products.', 'flourish-woocommerce'), $min_age),
                'error'
            );
        }
    }

    public function save_dob_to_order($order_id)
    {
        $dob = sanitize_text_field($_POST['dob'] ?? '');
        if (!empty($dob)) {
            update_post_meta($order_id, '_customer_dob', $dob);
        }
    }

    public function enqueue_dob_scripts()
    {
        if (is_checkout()) {
            wp_enqueue_script(
                'flourish-checkout-dob',
                plugins_url('assets/js/custom-checkout-dob.js', dirname(__DIR__) . '/flourish-woocommerce-plugin.php'),
                [],
                '1.0.0',
                true
            );

            wp_localize_script('flourish-checkout-dob', 'dobData', [
                'getApiUrl'  => rest_url('flourish/v1/get-dob'),
                'postApiUrl' => rest_url('flourish/v1/save-dob'),
                'nonce'      => wp_create_nonce('wp_rest'),
                'minimumAge' => $this->existing_settings['minimum_age'] ?? self::DEFAULT_MINIMUM_AGE,
            ]);
        }
    }

    /**
     * Check if a person is of legal age.
     *
     * @param string $dob Date of birth in Y-m-d format.
     * @param int $min_age Minimum age required.
     * @return bool
     */
    private function is_of_legal_age($dob, $min_age)
    {
        try {
            $birth_date = new \DateTime($dob);
            $today = new \DateTime('today');
            $age = $birth_date->diff($today)->y;
            return $age >= $min_age;
        } catch (\Exception $e) {
            return false;
        }
    }
}
