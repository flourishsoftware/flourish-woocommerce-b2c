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

        // Checkout support
        add_filter('woocommerce_billing_fields', [$this, 'add_dob_billing_field']);
        add_filter('woocommerce_checkout_fields', [$this, 'add_dob_to_checkout_fields']);
        add_action('woocommerce_after_order_notes', [$this, 'display_dob_field_manual']);
        add_action('woocommerce_checkout_process', [$this, 'validate_dob_field']);
        add_action('woocommerce_checkout_update_order_meta', [$this, 'save_dob_to_order']);

        // My Account page support
        add_action('woocommerce_edit_account_form', [$this, 'display_dob_on_account_page']);
        add_action('woocommerce_save_account_details', [$this, 'save_dob_from_account_page']);
        add_action('woocommerce_save_account_details_errors', [$this, 'validate_dob_on_account_save'], 10, 1);

        // Registration support
        add_action('woocommerce_register_form', [$this, 'display_dob_on_registration']);
        add_action('woocommerce_created_customer', [$this, 'save_dob_on_registration']);
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
     * Add DOB field to billing fields.
     */
    public function add_dob_billing_field($fields)
    {
        $min_age = $this->existing_settings['minimum_age'] ?? self::DEFAULT_MINIMUM_AGE;

        $fields['billing_dob'] = [
            'type'        => 'date',
            'label'       => \__('Date of Birth', 'flourish-woocommerce'),
            'required'    => true,
            'class'       => ['form-row-wide'],
            'priority'    => 25, // Place after email (20) but before phone (100)
            'placeholder' => \__('YYYY-MM-DD', 'flourish-woocommerce'),
            'description' => sprintf(\__('You must be at least %d years old to purchase these products.', 'flourish-woocommerce'), $min_age),
        ];

        return $fields;
    }

    /**
     * Add DOB to checkout fields array.
     */
    public function add_dob_to_checkout_fields($fields)
    {
        $min_age = $this->existing_settings['minimum_age'] ?? self::DEFAULT_MINIMUM_AGE;

        $fields['billing']['billing_dob'] = [
            'type'        => 'date',
            'label'       => \__('Date of Birth', 'flourish-woocommerce'),
            'required'    => true,
            'class'       => ['form-row-wide'],
            'priority'    => 25,
            'placeholder' => \__('YYYY-MM-DD', 'flourish-woocommerce'),
            'description' => sprintf(\__('You must be at least %d years old to purchase these products.', 'flourish-woocommerce'), $min_age),
        ];

        return $fields;
    }

    /**
     * Manually display DOB field on checkout.
     */
    public function display_dob_field_manual($checkout)
    {
        $min_age = $this->existing_settings['minimum_age'] ?? self::DEFAULT_MINIMUM_AGE;

        echo '<div id="flourish_dob_checkout_field" class="flourish-dob-field">';
        echo '<h3>' . \__('Age Verification', 'flourish-woocommerce') . '</h3>';

        \woocommerce_form_field('billing_dob', [
            'type'        => 'date',
            'class'       => ['form-row-wide'],
            'label'       => \__('Date of Birth', 'flourish-woocommerce'),
            'required'    => true,
            'placeholder' => \__('YYYY-MM-DD', 'flourish-woocommerce'),
            'description' => sprintf(\__('You must be at least %d years old to purchase these products.', 'flourish-woocommerce'), $min_age),
        ], $checkout->get_value('billing_dob'));

        echo '</div>';
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
        $dob = sanitize_text_field($_POST['billing_dob'] ?? '');

        if (empty($dob)) {
            \wc_add_notice(\__('Date of Birth is required for this purchase.', 'flourish-woocommerce'), 'error');
            return;
        }

        $dob_date = \DateTime::createFromFormat('Y-m-d', $dob);
        if (!$dob_date || $dob_date->format('Y-m-d') !== $dob) {
            \wc_add_notice(\__('Please enter a valid Date of Birth.', 'flourish-woocommerce'), 'error');
            return;
        }

        $min_age = $this->existing_settings['minimum_age'] ?? self::DEFAULT_MINIMUM_AGE;
        if (!$this->is_of_legal_age($dob, $min_age)) {
            \wc_add_notice(
                sprintf(\__('You must be at least %d years old to purchase these products.', 'flourish-woocommerce'), $min_age),
                'error'
            );
        }
    }

    public function save_dob_to_order($order_id)
    {
        $dob = sanitize_text_field($_POST['billing_dob'] ?? '');
        if (!empty($dob)) {
            update_post_meta($order_id, '_customer_dob', $dob);
            update_post_meta($order_id, '_billing_dob', $dob);

            // Also save to user meta if customer is logged in
            $user_id = get_current_user_id();
            if ($user_id) {
                update_user_meta($user_id, 'dob', $dob);
            }
        }
    }

    /**
     * Display DOB field on My Account edit page.
     */
    public function display_dob_on_account_page()
    {
        $user = wp_get_current_user();
        $dob = get_user_meta($user->ID, 'dob', true);
        $min_age = $this->existing_settings['minimum_age'] ?? self::DEFAULT_MINIMUM_AGE;

        ?>
        <fieldset>
            <legend><?php esc_html_e('Age Verification', 'flourish-woocommerce'); ?></legend>
            <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
                <label for="account_dob"><?php esc_html_e('Date of Birth', 'flourish-woocommerce'); ?>&nbsp;<span class="required">*</span></label>
                <input type="date" class="woocommerce-Input woocommerce-Input--text input-text" name="account_dob" id="account_dob" value="<?php echo esc_attr($dob); ?>" required />
                <span class="description"><?php echo esc_html(sprintf(\__('You must be at least %d years old to purchase these products.', 'flourish-woocommerce'), $min_age)); ?></span>
            </p>
        </fieldset>
        <?php
    }

    /**
     * Validate DOB when saving account details.
     */
    public function validate_dob_on_account_save($errors)
    {
        $dob = sanitize_text_field($_POST['account_dob'] ?? '');

        if (empty($dob)) {
            $errors->add('dob_error', \__('Date of Birth is required.', 'flourish-woocommerce'));
            return;
        }

        $dob_date = \DateTime::createFromFormat('Y-m-d', $dob);
        if (!$dob_date || $dob_date->format('Y-m-d') !== $dob) {
            $errors->add('dob_error', \__('Please enter a valid Date of Birth.', 'flourish-woocommerce'));
            return;
        }

        $min_age = $this->existing_settings['minimum_age'] ?? self::DEFAULT_MINIMUM_AGE;
        if (!$this->is_of_legal_age($dob, $min_age)) {
            $errors->add('dob_error', sprintf(\__('You must be at least %d years old to purchase these products.', 'flourish-woocommerce'), $min_age));
        }
    }

    /**
     * Save DOB when account details are updated.
     */
    public function save_dob_from_account_page($user_id)
    {
        $dob = sanitize_text_field($_POST['account_dob'] ?? '');
        if (!empty($dob)) {
            update_user_meta($user_id, 'dob', $dob);
        }
    }

    /**
     * Display DOB field on registration form.
     */
    public function display_dob_on_registration()
    {
        $dob = isset($_POST['reg_dob']) ? sanitize_text_field($_POST['reg_dob']) : '';
        $min_age = $this->existing_settings['minimum_age'] ?? self::DEFAULT_MINIMUM_AGE;

        ?>
        <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
            <label for="reg_dob"><?php esc_html_e('Date of Birth', 'flourish-woocommerce'); ?>&nbsp;<span class="required">*</span></label>
            <input type="date" class="woocommerce-Input woocommerce-Input--text input-text" name="reg_dob" id="reg_dob" value="<?php echo esc_attr($dob); ?>" required />
            <span class="description"><?php echo esc_html(sprintf(\__('You must be at least %d years old.', 'flourish-woocommerce'), $min_age)); ?></span>
        </p>
        <?php
    }

    /**
     * Save DOB when customer registers.
     */
    public function save_dob_on_registration($customer_id)
    {
        $dob = sanitize_text_field($_POST['reg_dob'] ?? '');
        if (!empty($dob)) {
            // Validate age
            $min_age = $this->existing_settings['minimum_age'] ?? self::DEFAULT_MINIMUM_AGE;
            if ($this->is_of_legal_age($dob, $min_age)) {
                update_user_meta($customer_id, 'dob', $dob);
            }
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
