<?php

/**
 * class for generating notification messages and ading then to the notifications poll
 */

namespace WooCommercePMG;

class Notification
{
    public const NOTIF_STYLE_ERROR = 'error';
    public const NOTIF_STYLE_WARNING = 'warning';
    public const NOTIF_STYLE_INFO = 'info';
    public const NOTIF_STYLE_SUCCESS = 'success';

    private $message = '';
    private $type = 'error';

    private static $notifications_poll = array();

    /**
     * method for getting allowed notifications types
     * @return string[]
     */
    public static function get_allowed_types()
    {
        return array(
            self::NOTIF_STYLE_ERROR,
            self::NOTIF_STYLE_INFO,
            self::NOTIF_STYLE_SUCCESS,
            self::NOTIF_STYLE_WARNING
        );
    }


    /**
     * method for generatio HTML code for the link
     * @param $link
     * @param $title
     * @return string
     */
    public static function generate_link($link, $title)
    {
        return '<a href="' . $link . '" target="_blank">' . $title . '</a>';
    }


    /**
     * method for adding object of Notification to the notification poll
     * @param $obj_notification
     * @return void
     */
    public static function add_notification_to_poll($obj_notification)
    {
        self::$notifications_poll[] = $obj_notification;
    }


    /**
     * method returns all notifications which we added to the notification poll
     * @return Notification[]
     */
    public static function get_poll_notifications()
    {
        return self::$notifications_poll;
    }

    /**
     * setter message value for the Notification object
     * @param $message
     * @return void
     */
    public function set_message($message)
    {
        $this->message = trim($message);
    }

    /**
     * getter message value for the Notification object
     * @return string
     */
    public function get_message()
    {
        return $this->message;
    }


    /**
     * setter type notification (from allowed types)
     * @param $type
     * @return void
     */
    public function set_type($type)
    {
        if (in_array($type, self::get_allowed_types())) {
            $this->type == $type;
        }
    }

    /**
     * getter type notification
     * @return mixed
     */
    public function get_type()
    {
        return $this->get_type();
    }

    /**
     * method for generating additional style for different allowed notification types
     * @return string|void
     */
    public function get_styles()
    {
        switch ($this->type) {
            case self::NOTIF_STYLE_WARNING:
                return 'border-left-color: orange';
            case self::NOTIF_STYLE_INFO:
                return 'border-left-color: blue';
            case self::NOTIF_STYLE_SUCCESS:
                return 'border-left-color: green';
        }
    }

    /**
     * magic method which convert our Notification object to the string
     * @return string
     */
    public function __toString()
    {
        return '<div class="error" style="' . $this->get_styles() . '"><p><strong>' . $this->message . '</strong></p></div>';
    }
}
