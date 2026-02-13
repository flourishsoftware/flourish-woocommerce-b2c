<?php

namespace FlourishWooCommercePlugin;

defined('ABSPATH') || exit;

use FlourishWooCommercePlugin\Services\ServiceProvider;

class FlourishWooCommercePlugin
{
    private static $instance = null;

    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_action('plugins_loaded', [$this, 'init']);
    }

    public function init()
    {
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', function () {
                echo '<div class="error"><p><strong>Flourish WooCommerce B2C</strong> requires WooCommerce to be installed and active.</p></div>';
            });
            return;
        }

        $service_provider = new ServiceProvider();
        $service_provider->register();
    }
}
