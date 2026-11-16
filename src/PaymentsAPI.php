<?php

/**
 * class for processing/handling payment requests, communicate with pyment API, create Iframe if needed
 */

namespace WooCommercePMG;

class PaymentsAPI
{
    private $private_key            = false;
    private $public_key             = false;

    /**
     * @var Logger
     */
    private $logger             = false;

    protected ?int $lastStatusCode = null;

    public function __construct($private_key, $public_key, $logger)
    {
        $this->private_key = $private_key;
        $this->public_key = $public_key;
        $this->logger = $logger;
    }


    /**
     * method for getting current version of our plugin from ENV data
     * @return string
     */
    public static function get_payments_module_ver()
    {
        return isset($_ENV['PAYMENTS_MODULE_VERSION']) ? $_ENV['PAYMENTS_MODULE_VERSION'] : 'v2.0';
    }


    /**
     * method for getting current version of API payment system version from ENV data
     * @return string
     */
    public static function get_payments_api_ver()
    {
        return isset($_ENV['PAYMENT_API_VERSION']) ? $_ENV['PAYMENT_API_VERSION'] : 'v0.6';
    }


    /**
     * method for getting GATE URL for processing payment from ENV data
     * @return mixed|string
     */
    public static function get_gate_url()
    {
        return isset($_ENV['GATE']) ? $_ENV['GATE'] : '';
    }


    /**
     * method for creating new payment request from customers order
     * @param $params
     * @return mixed|null
     */
    public function create_payment($params)
    {
        $this->logger->info('----------------------------------------------------------------------');
        $this->logger->info(sprintf("Create payment request for order #%s", $params['number']));
        $result = $this->call('POST', '/api/'.$this->get_payments_api_ver().'/orders/', $params);
        if ($result == null) {
            $this->logger->error("The payment request wasn`t created by ID - ".$params['number']);
            return null;
        }

        if (
            isset($result['full_page_checkout'])
            && isset($result['id']) && isset($result['transaction_details'])
            && isset($result['transaction_details']['transactions_id'])
        ) {
            $this->logger->info(sprintf("The payment request was successfully created for ID order - #%s", $params['number']));
            return $result;
        } else {
            $this->logger->error(sprintf("Got wrong data from payment API for order - #%s", $params['number']));
            $this->logger->error("Payment request params: " . json_encode($params, true));
            $this->logger->error("Payment request response: " . json_encode($result, true));
            return null;
        }
    }


    /**
     * method for finding customers card in the payment system
     * @param $filter_email
     * @param $filter_phone
     * @return array|null
     */
    public function get_user($filter_email, $filter_phone)
    {
        $this->logger->info(sprintf("Finding customers card in the payment system"));

        $params['filter_email'] = trim($filter_email);
        $params['filter_phone'] = $this->transform_phone($filter_phone);
        $users = $this->call('GET', '/api/'.$this->get_payments_api_ver().'/clients/', $params);

        $this->logger->info("Find user request params: " . json_encode($params));
        $this->logger->info("Find user response: " . json_encode($users));

        if (isset($users) && isset($users['results']) && is_array($users['results']) && count($users['results']) > 0) {
            return $users['results'][0];
        } else {
            return null;
        }
    }


    /**
     * method for creating new customers card in the payment system
     * @param $params
     * @return mixed|null
     */
    public function create_user($params)
    {
        $params['phone'] = $this->transform_phone($params['phone']);
        $result = $this->call('POST', '/api/'.$this->get_payments_api_ver().'/clients/', $params);
        $this->logger->info("Create user request params: " . json_encode($params));

        if (empty($result)) {
            $this->logger->error("Create user response: " . json_encode($result, true));
        } else {
            $this->logger->info("Create user response: " . json_encode($result, true));
        }

        return $result;
    }


    /**
     * method for checking customers payment.
     * @param $order_id
     * @param $payment_id
     * @return bool
     */

    public function define_order_status($order, $payment_id)
    {

        require_once 'Constants.php';
        $order_id = $order->get_order_number();
        $this->logger->set_order_id($order_id);

        $this->logger->info(sprintf("Validating payment for order #%s, payment #%s", $order_id, $payment_id));

        $order_id = (string)$order_id;

        $result = $this->call('GET', sprintf('/api/' . $this->get_payments_api_ver() . '/orders/%s/', $payment_id));
        if ($result == null || !is_array($result) || !isset($result['number'])) {
            $this->logger->error(sprintf("The payment #%s was not found for the order #%s", $payment_id, $order_id));
            $this->logger->error("Get payment response: " . json_encode($result, true));
            return OrderStatus::FAILED;
        }

        if ($order_id != (string)$result['number']) {
            $this->logger->error("Got difference order ID from our system and payment request");
            $this->logger->error("Order ID - " . print_r($order_id, true));
            $this->logger->error("Order ID from response - " . print_r($result['number'], true));
            $this->logger->error("Get payment response - " . json_encode($result, true));
            return OrderStatus::FAILED;
        } elseif ($result['status'] == 'paid' || $result['status'] == 'withdrawn') {
            $order->add_order_note(__(isset($_ENV['MESSAGE_SUCCESS_TRANSACTION']) ? $_ENV['MESSAGE_SUCCESS_TRANSACTION'] : ''));
            $this->logger->info(sprintf("SUCCESS order #%s, payment #%s", $order_id, $payment_id));
            return OrderStatus::PAID;
        } elseif ($result['status'] == 'hold') {
            $order_note = isset($_ENV['MESSAGE_DMS']) ? $_ENV['MESSAGE_DMS'] : '';
            $link = isset($_ENV['GATE']) ? $_ENV['GATE'] : '';
            $order_note = str_replace('%cabinet%', '<a target="_blank" href="' . $link . '">cabinet</a>', $order_note);
            $order->add_order_note($order_note);
            $this->logger->info(sprintf("SUCCESS DMS order #%s, payment #%s", $order_id, $payment_id));
            return OrderStatus::HOLD;
        } elseif ($result['status'] == 'in_progress') {
            $this->logger->info(sprintf("IN_PROGRESS order #%s, payment #%s", $order_id, $payment_id));
            return OrderStatus::IN_PROGRESS;
        } elseif ($result['status'] == 'cancelled' || $result['status'] == 'failed' || $result['status'] == 'expired') {
            $this->logger->error('Could NOT validate payment');
            $this->logger->error("Order ID - " . print_r($order_id, true));
            $this->logger->error("Status - " . print_r($result['status'], true));
            if (isset($result['transaction_details']) && isset($result['transaction_details']['errors']) && is_array($result['transaction_details']['errors'])) {
                $messages = array_column($result['transaction_details']['errors'], 'description');
                $this->logger->error("Transaction error messages - " . implode(', ', $messages));
                $order->add_order_note('Payment failed, error description: ' . implode(', ', $messages));
            }

            return OrderStatus::FAILED;
        }
    }


    /**
    * MAIN method for making requests to the payment API
    * @param $method - allowed methods for REST API (  POST, PUT, GET, etc )
    * @param $route - API path for processing data
    * @param $params - additional params for the request to teh API
    * @return mixed|null
    */
    public function call($method, $route, $params = array())
    {

        $this->logger->info(sprintf("Init request to the payment gateway by method - #%s, url - #%s", $method, static::get_gate_url(). $route));
        $original_params = $params;
        if (!empty($params)) {
            $params = json_encode($params);
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, static::get_gate_url(). $route);

        switch ($method) {
            case 'POST':
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
                break;
            case 'PUT':
                curl_setopt($ch, CURLOPT_PUT, 1);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
                break;
            case 'GET':
                $get_params = '';
                foreach ($original_params as $key => $value) {
                    $get_params .= $key . '=' . urlencode($value) . '&';
                }
                $get_params = trim($get_params, '&');
                curl_setopt($ch, CURLOPT_URL, static::get_gate_url() . $route . '?' . $get_params);
                break;
        }

        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_FORBID_REUSE, 1);
        curl_setopt($ch, CURLOPT_FRESH_CONNECT, 1);
        curl_setopt($ch, CURLOPT_FAILONERROR, false);
        curl_setopt($ch, CURLOPT_HTTP200ALIASES, (array)400);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-type: application/json',
            'Authorization: Bearer ' . $this->private_key
        ));

        $response = curl_exec($ch);
        if (!$response) {
            $this->logger->error("Got empty answer from API");
            $this->logger->error("Curl error - ".curl_error($ch));
        }

        curl_close($ch);
        $this->lastStatusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        $result = json_decode($response, true);
        if (!$result) {
            $this->logger->error('JSON parsing error/NULL API response');
            return null;
        }

        if (!empty($result['errors'])) {
            $this->logger->error("Got some errors from API: " . json_encode($result['errors'], true));
            return null;
        }

        return $result;
    }

    /**
     * method return message about system configuration
     * @return string
     */
    public static function generate_init_message()
    {
        global $wp_version;
        return 'WP ver.:'.$wp_version.', WooCommerce ver.:'.WC_VERSION.', PHP ver.: '.phpversion();
    }

    /**
     * method with message to LOG file about customer connection
     * @return mixed|string
     */
    public static function generate_customer_data()
    {
        return isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
    }

    /**
     * method with general info about customers order
     * @param $order
     * @return false|string
     */
    public static function generate_order_data($order)
    {
        if (!is_object($order)) {
            return false;
        }
        return 'Total price: '.$order->get_total().' '.$order->get_currency()
            .', discount: '.$order->get_total_discount().' '.$order->get_currency()
            .', tax: '.$order->get_total_tax().' '.$order->get_currency();
    }

    /**
     * method for parsing phone number.
     * !IMPORTANT: by this regular expression we will get all numbers without ANY symbols.
     * It`s not so good for offices numbers and extensions for them.
     * For example: for string "+123456-213" - we wil get answer "+123456213", the same answer will be for "+123456 #213"
     * @param $phone
     * @return false|string
     */
    private function transform_phone($phone)
    {
        return substr(preg_replace("/[^+0-9]/", '', $phone ?? ''), 0, 32);
    }

    public function getLastStatusCode(): ?int
    {
        return $this->lastStatusCode;
    }
}
