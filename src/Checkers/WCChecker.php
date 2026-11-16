<?php

/**
 * class checks capability with WooCommerce plugin
 */

namespace WooCommercePMG\Checkers;

use WooCommercePMG\Notification;

class WCChecker implements DefaultChecker
{
    public static function execute()
    {
        $plugin_name = isset($_ENV['PLUGIN_NAME']) ? $_ENV['PLUGIN_NAME'] : 'WooCommercePMG';
        $needed_version = isset($_ENV['WOOCOMMERCE_VERSION_MIN']) ? $_ENV['WOOCOMMERCE_VERSION_MIN'] : '3.4';

        if (! class_exists('\WooCommerce')) {
            $link = Notification::generate_link('https://woocommerce.com/', 'WooCommerce');
            $message = sprintf('%s requires WooCommerce to be installed and active. You can download %s here.', $plugin_name, $link);

            $obj_notification = new Notification();
            $obj_notification->set_type(Notification::NOTIF_STYLE_ERROR);
            $obj_notification->set_message($message);
            Notification::add_notification_to_poll($obj_notification);
            return false;
        } elseif (version_compare(WC_VERSION, $needed_version, '<')) {
            $link = Notification::generate_link('https://woocommerce.com/', 'WooCommerce');
            $message = sprintf('%s requires WooCommerce version more than %s. You can download %s here.', $plugin_name, $needed_version, $link);

            $obj_notification = new Notification();
            $obj_notification->set_type(Notification::NOTIF_STYLE_ERROR);
            $obj_notification->set_message($message);
            Notification::add_notification_to_poll($obj_notification);
            return false;
        }
        return true;
    }
}
