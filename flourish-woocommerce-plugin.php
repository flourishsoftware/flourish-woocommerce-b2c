<?php

/**
 * Plugin Name: Flourish WooCommerce B2C
 * Plugin URI: https://github.com/flourishsoftware/flourish-woocommerce-b2c
 * Description: A WooCommerce plugin for B2C retail sales powered by Flourish.
 * Version: 1.0.0
 * Author: Flourish Software
 * Author URI: https://flourishsoftware.com
 * License: GPLv3
 * WC requires at least: 7.0
 * WC tested up to: 9.6
 */

defined('ABSPATH') || exit;

// Autoloader
require_once __DIR__ . '/vendor/autoload.php';

// Plugin update checker — points to the correct B2C repository
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$myUpdateChecker = PucFactory::buildUpdateChecker(
    'https://github.com/flourishsoftware/flourish-woocommerce-b2c/',
    __FILE__,
    'flourish-woocommerce-b2c-plugin'
);

// Bootstrap the plugin
FlourishWooCommercePlugin\FlourishWooCommercePlugin::get_instance();
