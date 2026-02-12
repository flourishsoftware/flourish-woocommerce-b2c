<?php

namespace FlourishWooCommercePlugin\Handlers;

defined('ABSPATH') || exit;

class SettingsHandler
{
    private $existing_settings;

    public function __construct($existing_settings)
    {
        $this->existing_settings = $existing_settings;
    }

    public function getSetting($key, $default = null)
    {
        return $this->existing_settings[$key] ?? $default;
    }

    public function getAllSettings()
    {
        return $this->existing_settings;
    }
}
