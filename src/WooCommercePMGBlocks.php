<?php

namespace WooCommercePMG;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

class WooCommercePMGBlocks extends AbstractPaymentMethodType
{
    private $gateway;
    protected $name;

    public function __construct()
    {
        $this->name = $_ENV['PLUGIN_ID'] ?? "payments_pmg";
    }

    public function initialize()
    {
        $this->settings = get_option('woocommerce_' . $this->name . '_settings', []);
        $this->gateway = new WooCommercePMG();
    }

    public function is_active()
    {
        return $this->gateway->is_available();
    }

    public function get_payment_method_script_handles()
    {
        wp_register_script(
            $this->name . '-blocks-integration',
            plugin_dir_url(dirname(__FILE__)) . 'checkout-pmg-block.js',
            [
                'wc-blocks-registry',
                'wc-settings',
                'wp-element',
                'wp-html-entities',
                'wp-i18n',
            ],
            null,
            true
        );
        if (function_exists('wp_set_script_translations')) {
            wp_set_script_translations($this->name . '-blocks-integration');

        }
        $env_data = [
            'PMG_PLUGIN_CODE' => $this->name,
        ];
        wp_localize_script(
            $this->name . '-blocks-integration',
            'pmgEnvVars',
            $env_data
        );

        return [$this->name . '-blocks-integration'];
    }

    public function get_payment_method_data()
    {
        return [
            'title' => $this->gateway->title,
            'description' => $this->gateway->description ?? '',
        ];
    }
}
