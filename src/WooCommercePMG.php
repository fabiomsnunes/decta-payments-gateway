<?php

/**
 * main class of the plugin
 * extends on teh WooCommerce Payment Gateway and realises/rewrites some methods from this class. Also
 * realise basic methods which needed plugin and init webhooks
 */

namespace WooCommercePMG;

use WooCommercePMG\Checkers\CapabilityChecker;
use WooCommercePMG\Checkers\WCChecker;
use WooCommercePMG\Checkers\WordpressChecker;

class WooCommercePMG extends \WC_Payment_Gateway
{
    private $plugin_basename = false;
    private $plugin_url = false;
    /** Logger instance */
    private $log = false;
    private $use_iframe;

    protected $debug;

    public function __construct()
    {
        $this->plugin_basename  = plugin_basename(__FILE__);
        $this->plugin_url  = rtrim(plugin_dir_url(__DIR__), '/\\');

        $this->id = $_ENV['PLUGIN_ID'] ?? "payments_pmg";
        $this->method_title = $_ENV['PLUGIN_NAME'] ?? "PaymentsGateway";

        $this->title = $_ENV['PLUGIN_SHORTINFO'] ?? "Pay with card";
        $this->method_description = $_ENV['PLUGIN_DESCRIPTION'] ?? "Pay using the bank card";
        $this->description = '';

        $this->debug = ('yes' === $this->get_option('debug', 'no'));
        $this->use_iframe = 'yes' === $this->get_option('iniframe', 'no');

        //init Logger and enable/disable it
        $this->log = new Logger((bool)$this->debug);

        $this->hooks();
        $this->init_form_fields();
        $this->init_settings();
    }

    /**
     * method with hooks which will be run on different Wordpress/WooCommerce actions
     * @return void
     */
    public function hooks()
    {
        // Various notifications.
        if (is_admin()) {
            add_action('admin_notices', array($this, 'check_capabilities'));
        }

        if (!has_action('woocommerce_update_options_payment_gateways_' . $this->id)) {
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        }

        if (!has_action('woocommerce_receipt_' . $this->id)) {
            add_action('woocommerce_receipt_' . $this->id, array($this, 'payment_pmg_receipt_page'));
        }

        if (!has_action('woocommerce_api_wc_gateway_'. $this->id)) {
            add_action('woocommerce_api_wc_gateway_'. $this->id, array($this, 'handle_callback'));
        }

        //        add_filter( 'plugin_row_meta', array( __CLASS__, 'set_plugin_row_extra_meta' ), 10, 2 );
        if (is_admin()) {
            add_filter('plugins_api', array( $this, 'get_info' ), 20, 3);
            add_filter('site_transient_update_plugins', [$this, 'get_upgrade']);
            //                add_action( 'upgrader_process_complete', array( $this, 'set_purge' ), 10, 2 );
        }
    }

    /**
     * method with fields which will be allowed on page "Manage plugin"
     * @return void
     */
    public function init_form_fields()
    {
        // transaction options
        $tx_options = array(
            'payment' => __('Payment', 'woocommerce-'.$this->id),
            'authorization' => __('Authorization', 'woocommerce-'.$this->id)
        );
        $webhook_url = esc_url(get_rest_url(
            null,
            '/'. WebhookHandler::ROUTE_NAMESPACE. WebhookHandler::ROUTE_PREFIX
        ));
        $gateUrl = sprintf('%s/%s', rtrim(\WooCommercePMG\PaymentsAPI::get_gate_url(), '/'), ltrim('merchants/en/merchant/api', '/'));

        // transaction options
        $this->form_fields = array(
            'enabled' => array(
                'title' => __('Enable API', 'woocommerce'),
                'label' => __('Enable API', 'woocommerce'),
                'type' => 'checkbox',
                'description' => '',
                'default' => 'no'
            ),
            'public-key' => array(
                'title' => __('Public key', 'woocommerce-'.$this->id),
                'type' => 'text',
                'description' => __('Please enter your public key.', 'woocommerce-'.$this->id),
                'default' => ''
            ),
            'private-key' => array(
                'title' => __('Secret key', 'woocommerce-'.$this->id),
                'type' => 'text',
                'description' => __('Please enter your secret key.', 'woocommerce-'.$this->id),
                'default' => ''
            ),
            'debug' => array(
                'title' => __('Debug Log', 'woocommerce'),
                'type' => 'checkbox',
                'label' => __('Enable logging', 'woocommerce'),
                'default' => 'yes',
                'description' => sprintf(
                    __('Log '.$this->method_title.' events, inside <code>%s</code>', 'woocommerce'),
                    \WC_Log_Handler_File::get_log_file_path($this->id)
                )
            ),
            'iniframe' => array(
                'title' => __('Iframe payment form.', 'woocommerce-'.$this->id),
                'type' => 'checkbox',
                'label' => __('Build-in payment form on the website.', 'woocommerce-'.$this->id),
                'description' => __('Payment form will be located on your website.'),
                'default' => 'no',
            ),
            'webhook_url' => array(
                'title' => __('Webhook url', 'woocommerce-'.$this->id),
                'type' => 'title',
                'description' => $webhook_url,
            ),
            'webhook_info' => array(
                'title' => '',
                'type' => 'title',
                'description' => sprintf(
                    'Follow this <code><a href="%1$s" target="_blank">link</a></code> to add your webhook in GATE',
                    $gateUrl
                ),
            )
        );
    }


    /**
     * method for creating payment request, linking it to the customer and create order for the payment system
     * @param $order_id - order ID from WooCommerce plugin
     * @return array|mixed
     */
    public function process_payment($order_id)
    {
        $order = new \WC_Order($order_id);

        $this->log->set_order_id($order_id);
        $this->log->info('----------------------------------------------------------------------');
        $this->log->info(PaymentsAPI::generate_init_message());
        $this->log->info('----------------------------------------------------------------------');
        $this->log->info(PaymentsAPI::generate_customer_data());
        $this->log->info('----------------------------------------------------------------------');
        $this->log->info(PaymentsAPI::generate_order_data($order));
        $this->log->info('----------------------------------------------------------------------');

        $this->log->info('Created new order - '.$order_id);

        $obj_payment_api = new PaymentsAPI(
            $this->settings['private-key'],
            $this->settings['public-key'],
            $this->log
        );

        $params = array(
            'number' => (string)$order->get_order_number(),
            'referrer' => 'woocommerce v4.x module ' . PaymentsAPI::get_payments_module_ver(),
            'language' => 'en',
            'success_redirect' => home_url() . '/?wc-api=wc_gateway_'.$this->id.'&action=paid&id=' . $order_id,
            'failure_redirect' => home_url() . '/?wc-api=wc_gateway_'.$this->id.'&action=sent&id=' . $order_id,
            'in_progress_redirect' => home_url() . '/?wc-api=wc_gateway_'.$this->id.'&action=sent&id=' . $order_id,
            'currency' => $order->get_currency()
        );

        $this->add_user_data($obj_payment_api, $order, $params);
        $this->add_products($order, $params);

        $this->log->info("Dump of data for new payment request: " . json_encode($params['products'], true));

        $payment = $obj_payment_api->create_payment($params);
        $this->log->info("Payment ID request: " . $payment['id']);

        //write to the customers session/cockie/order payment ID request + iframe_checkout
        \WC()->session->set($this->id.'_payment_id', $payment['id']);
        $set = 'Set-Cookie: payment_id=' . $payment['id'] . '; SameSite=None; Secure';
        header($set, false);

        //set up transactions_id data
        \WC()->session->set($this->id.'_transactions_id', $payment['transaction_details']['transactions_id']);
        $set = 'Set-Cookie: transactions_id=' . $payment['transaction_details']['transactions_id'] . '; SameSite=None; Secure';
        header($set, false);

        // write info about order and transaction id to the clients order
        $message = 'Order ID: '.$payment['id'].'; ';
        if (isset($payment['transaction_details'])
            && isset($payment['transaction_details']['transactions_id'])) {
            $message .= 'Transaction ID: '.$payment['transaction_details']['transactions_id'].'; ';
        }
        $order->add_order_note($message);

        $order->set_transaction_id($payment['id']);
        $order->update_meta_data('transactions_id', $payment['transaction_details']['transactions_id']);
        $order->save();
        $this->log->info("Added payment ID request to the session and to the order");

        $payment['result'] = 'success';
        if ($this->use_iframe) {
            $this->log->info("The payment will be processed in IFRAME");
            $payment['redirect'] = $order->get_checkout_payment_url(true);
            $this->log->info('Set up all data for Iframe processing ' . $set);
        } else {
            $this->log->info("The payment will be processed on the REMOTE PAGE");
            $payment['redirect'] = $payment['full_page_checkout'];
        }
        return $payment;
    }


    /**
     * method for handling payment request from remote page or iframe
     * @return void
     */
    public function handle_callback()
    {
        // Docs http://docs.woothemes.com/document/payment-gateway-api/
        // Handle the thing here! http://127.0.0.1/wordpress/?wc-api=wc_gateway_neterapayments&id=&action={paid,sent}
        // The new URL scheme (http://127.0.0.1/wordpress/wc-api/wc_gateway_neterapayments) is broken for some reason.
        // Old one still works.
        $order = new \WC_Order($_GET["id"]);

        $this->log->set_order_id($_GET["id"]);
        $this->log->info('Get order request for handling with ID - '.$_GET["id"]);

        $obj_payment_api = new PaymentsAPI(
            $this->settings['private-key'],
            $this->settings['public-key'],
            $this->log
        );

        $payment_id = $this->get_payment_id($order);

        if (!$this->use_iframe) {
            $this->log->info('Processing payment on the REMOTE PAGE');
        } else {
            $this->log->info('Processing payment in IFRAME');

            if (empty($payment_id)) {
                $this->log->error('Didn`t get payment ID for handling in IFRAME mode');
                $order->update_status('wc-failed', __('ERROR: Payment id not found'));
                die('<script>window.top.location.href = "' . $this->get_return_url($order) . '";</script>');
            }
        }

        //processing payment, checking payment status by the payment API
        require_once 'Constants.php';
        try {
            $status = $obj_payment_api->define_order_status($order, $payment_id);
            $this->process_order_status($order, $status);
        } catch (Exception $e) {
            $order->update_status('wc-failed', __('ERROR: Unexpected order status.'));
        }

        $this->redirect_customer($order);
    }

    /**
     * Get payment_id from different sources (session, order, cookie)
     * 
     * @param \WC_Order $order
     * @return string|false
     */
    private function get_payment_id($order)
    {
        if (!empty(\WC()->session->get($this->id.'_payment_id'))) {
            $payment_id = \WC()->session->get($this->id.'_payment_id');
            $this->log->info('Get new payment request from session - ' . $payment_id);
            return $payment_id;
        }
        
        if (!empty($order->get_transaction_id())) {
            $payment_id = $order->get_transaction_id();
            $this->log->info('Get new payment request from ORDER - ' . $payment_id);
            return $payment_id;
        }
        
        if (!empty($_COOKIE['payment_id'])) {
            $payment_id = $_COOKIE['payment_id'];
            $this->log->info('Get new payment request from COOKIE - ' . $payment_id);
            return $payment_id;
        }
        
        return false;
    }

    /**
     * Process order status based on payment gateway response
     * 
     * @param \WC_Order $order
     * @param string $status Payment status from gateway
     * @return void
     */
    private function process_order_status($order, $status)
    {
        switch ($status) {
            case OrderStatus::IN_PROGRESS:
            case OrderStatus::HOLD:
                $this->handle_hold_status($order);
                break;
                
            case OrderStatus::PAID:
                $this->handle_paid_status($order);
                break;
                
            case OrderStatus::FAILED:
                $this->handle_failed_status($order);
                break;
                
            default:
                $this->log->warning('Unknown order status: ' . $status);
        }
    }

    /**
     * Handle HOLD and IN_PROGRESS statuses
     * Set order to on-hold (stock will be reduced automatically via hook)
     * 
     * @param \WC_Order $order
     * @return void
     */
    private function handle_hold_status($order)
    {
        if ($order->get_status() === 'on-hold') {
            return; // Already in the correct status
        }
        
        // Stock will be reduced automatically via 'woocommerce_order_status_on-hold' hook
        $order->update_status('on-hold', __('Awaiting payment confirmation.'));
    }

    /**
     * Handle PAID status
     * Complete payment (stock is reduced automatically by payment_complete)
     * 
     * @param \WC_Order $order
     * @return void
     */
    private function handle_paid_status($order)
    {
        // Stock will be reduced automatically via 'woocommerce_payment_complete' hook
        $order->payment_complete();
    }

    /**
     * Handle FAILED status
     * Set order to failed
     * 
     * @param \WC_Order $order
     * @return void
     */
    private function handle_failed_status($order)
    {
        if ($order->get_status() !== 'failed') {
            $order->update_status('failed', __('ERROR: Payment failed.'));
        }
    }

    /**
     * Redirect customer to order confirmation page
     * Use iframe or direct redirect based on settings
     * 
     * @param \WC_Order $order
     * @return void
     */
    private function redirect_customer($order)
    {
        $this->log->info('Redirect customer to the page with status of the payment');
        
        if ($this->use_iframe) {
            $this->log->info('Redirect via Iframe');
            die('<script>window.top.location.href = "' . $this->get_return_url($order) . '";</script>');
        } else {
            $this->log->info('Redirect via remote payment page');
            header("Location: " . $this->get_return_url($order));
        }
    }


    /**
     * method for showing checkout page in Iframe
     * @param $order
     * @return void
     */
    public function payment_pmg_receipt_page($order)
    {
        if ($this->use_iframe) {
            $obj_order = new \WC_Order($order);
            $this->log->set_order_id($order);

            $transactions_id = false;
            //try to find iframe_checkout from session/cookie/wp order
            if (!empty(\WC()->session->get($this->id.'_transactions_id'))) {
                $this->log->info('Iframe got transactions_id URL from session');
                $transactions_id = \WC()->session->get($this->id.'_transactions_id');
            } elseif (!empty($_COOKIE['transactions_id'])) {
                $this->log->info('Iframe got transactions_id URL from $_COOKIE');
                $transactions_id = $_COOKIE['transactions_id'];
            } elseif (!empty($obj_order->get_meta('transactions_id'))) {
                $this->log->info('Iframe got transactions_id URL from WP order metadata');
                $transactions_id = $obj_order->get_meta('transactions_id');
            } else {
                $this->log->info('Iframe did not find transactions_id URL');
                $obj_order->update_status('wc-failed', __('ERROR: Payment id not found'));
                header("Location: " . $this->get_return_url($obj_order));
            }

            $form_html = '<div id="complex_wrapper" style="text-align: -webkit-center;">';
            $web_sdk_url = isset($_ENV['WEB_SDK_URL']) ? $_ENV['WEB_SDK_URL'] : '';
            $timestamp = time();
            $urlWithTimestamp = $web_sdk_url . '?t=' . $timestamp;
            $form_html .= '<div data-payment-sdk-form></div>
                <script>
                const onPayformload = () => {
                PaymentSdk.Form.create({ paymentId: \'' . $transactions_id . '\' });
                }
                </script>
                <script src="' . $urlWithTimestamp . '" onload="onPayformload()"></script>';
            echo $form_html;
        }
    }

    /**
     * method for checking current or create new customers card in WP for processing order
     * @param PaymentsAPI $obj_payment_api
     * @param $order - object of the order which was created by the customer
     * @param $params - array of parameters which we need for create payment request
     * @return void
     */
    protected function add_user_data(PaymentsAPI $obj_payment_api, $order, &$params)
    {
        $this->send_to_email = filter_var(
            $_ENV['SEND_TO_EMAIL'] ?? 'true',
            FILTER_VALIDATE_BOOLEAN
        );

        $user_data = array(
            'email' => $order->get_billing_email($context = 'view'),
            'phone' => $order->get_billing_phone($context = 'view'),
            'first_name' => $order->get_billing_first_name(),
            'last_name' => $order->get_billing_last_name(),
            'country' => $order->get_billing_country(),
            'send_to_email' => $this->send_to_email,
        );

        $this->log->info('Find customer by the email, phone - '.$user_data['email'].', ', $user_data['phone']);
        $findUser = $obj_payment_api->get_user($user_data['email'], $user_data['phone']);
        if (!$findUser) {
            $this->log->info('The customer wasn`t found. Create new customers card.');
            if ($obj_payment_api->create_user($user_data)) {
                $findUser = $obj_payment_api->get_user($user_data['email'], $user_data['phone']);
            }
        }

        $this->log->info('The customers ID - '.$findUser['id']);
        $user_data['original_client'] = $findUser['id'];
        $params['client'] = $user_data;
    }

    /**
     * method for create one product with price the same like and summary price for order
     * @param $order - the object of WP customers order
     * @param $params - array of parameters which we need for create payment request
     * @return void
     */
    protected function add_products($order, &$params)
    {
        $params['products'][] = [
            'price' => round($order->get_total(), 2),
            'title' => ('Invoice for payment #') . (string)$order->get_order_number(),
            'quantity' => 1
        ];
    }

    /**
     * method for checking last stable version from author sites
     * @param $transient
     * @return mixed
     */
    public function get_upgrade($transient)
    {
        $obj_updater = new Updater();
        return $obj_updater->get_last_update($transient);
    }


    /**
     * method for creating/decorating view about plugin
     * @param $res
     * @param $action
     * @param $args
     * @return \stdClass
     */
    public function get_info($res, $action, $args)
    {
        $obj_updater = new Updater();
        return $obj_updater->get_info($res, $action, $args);
    }


    /**
     * method which check plugin capabilities with WP/WC/PHP and notify about it
     * @return void
     */
    public function check_capabilities()
    {
        WCChecker::execute();
        CapabilityChecker::execute();
        WordpressChecker::execute();

        foreach (Notification::get_poll_notifications() as $notification) {
            echo $notification;
        }
    }
}
