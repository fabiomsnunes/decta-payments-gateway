<?php

/**
 * class checks capabilities with different modules, depencies, etc
 */

namespace WooCommercePMG\Checkers;

use WooCommercePMG\Notification;

class CapabilityChecker implements DefaultChecker
{
    public static function execute()
    {
        $plugin_name = isset($_ENV['PLUGIN_NAME']) ? $_ENV['PLUGIN_NAME'] : 'WooCommercePMG';
        $needed_version = isset($_ENV['PHP_VERSION_MIN']) ? $_ENV['PHP_VERSION_MIN'] : '5.6';
        if (version_compare(phpversion(), $needed_version, '<')) {
            $message = sprintf('%s requires PHP version more than %s. ', $plugin_name, $needed_version);

            $obj_notification = new Notification();
            $obj_notification->set_type(Notification::NOTIF_STYLE_ERROR);
            $obj_notification->set_message($message);
            Notification::add_notification_to_poll($obj_notification);
            return false;
        }
        return true;
    }
}
