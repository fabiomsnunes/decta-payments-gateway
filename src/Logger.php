<?php

/**
 * class Logger
 * uses default logger from WooCommerce - WC_Logger
 * has methods for writing NOTICE, WARNING, ERROR messages to the log of the plugin
 */

namespace WooCommercePMG;

class Logger
{
    private $enabled = false;
    private $vendor = false;
    private $plugin_id = false;
    private $api_rev = false;
    private $logger = false;
    private $order_id = false;

    public function __construct($enabled = true)
    {
        $this->enabled = $enabled;
        $this->plugin_id = isset($_ENV['PLUGIN_ID']) ? $_ENV['PLUGIN_ID'] : 'payments_pmg';
        $this->vendor = isset($_ENV['PLUGIN_NAME']) ? $_ENV['PLUGIN_NAME'] : 'WooCommercePMG';
        $this->api_rev = isset($_ENV['PAYMENTS_MODULE_VERSION']) ? $_ENV['PAYMENTS_MODULE_VERSION'] : 'v1.0';
        $this->logger = new \WC_Logger();
    }

    /**
     * SET Order ID
     * @param $id_order
     * @return void
     */
    public function set_order_id($id_order = false)
    {
        $this->order_id = intval($id_order);
    }

    /**
     * get order ID
     * @return bool
     */
    public function get_order_id()
    {
        return $this->order_id;
    }

    /**
     * method for getting is enabled functional for logging to the plugin .log file
     * @return bool
     */
    public function get_is_enabled()
    {
        return $this->enabled;
    }

    /**
     * method for writing NOTICE mesagges to the .log file
     * @param $message
     * @return void
     */
    public function info($message)
    {
        if ($this->enabled === false) {
            return;
        }

        $order_message = '';
        if (!empty($this->order_id)) {
            $order_message .= ', Order #'.intval($this->order_id);
        }
        $this->logger->add($this->plugin_id, 'INFO, '.$this->vendor.' ('.$this->api_rev.')'.$order_message.': '.$message);
    }

    /**
     * method for writing WARNING messages to the .log file
     * @param $message
     * @return void
     */
    public function warning($message)
    {
        if ($this->enabled === false) {
            return;
        }

        $order_message = '';
        if (!empty($this->order_id)) {
            $order_message .= ', Order #'.intval($this->order_id);
        }
        $this->logger->add($this->plugin_id, 'WARNING, '.$this->vendor.' ('.$this->api_rev.')'.$order_message.': '.$message);
    }

    /**
     * method for writing ERROR messages to the .log file
     * @param $message
     * @return void
     */
    public function error($message)
    {
        if ($this->enabled === false) {
            return;
        }

        $order_message = '';
        if (!empty($this->order_id)) {
            $order_message .= ', Order #'.intval($this->order_id);
        }
        $this->logger->add($this->plugin_id, 'ERROR, '.$this->vendor.' ('.$this->api_rev.')'.$order_message.': '. $message);
    }

    /**
     * Method for logging messages with additional data to the .log file.
     * @param string $message The message to be logged.
     * @param mixed $data Additional data to include in the log entry.
     * @return void
     */
    public function log_info_with_data($message, $data)
    {
        if ($this->enabled === false) {
            return;
        }

        $this->logger->log($this->plugin_id, 'INFO, '.$this->vendor.' ('.$this->api_rev.'): '.$message, $data);
    }
}
