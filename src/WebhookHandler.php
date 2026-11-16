<?php

namespace WooCommercePMG;

use WooCommercePMG\Model\WebhookEventRepository;

class WebhookHandler
{
    public const ROUTE_NAMESPACE = 'wc-pmg/v1';

    public const ROUTE_PREFIX = '/webhook';

    private const EVENTS = [
        'order.payment_success',
        'order.payment_failure',
        'order.in_progress',
        'order.expired',
        'order.hold',
        'order.canceled',
    ];

    private const RETRY_DELAYS = [
        1 => 5,
        2 => 15,
        3 => 30,
        4 => 60,
        5 => 240,
    ];

    private static function get_plugin_id(): string
    {
        return $_ENV['PLUGIN_ID'] ?? 'payments_pmg';
    }

    /**
     * Register REST API route for webhook intake.
     * POST /wp-json/wc-pmg/v1/webhook
     */
    public static function register_routes()
    {
        register_rest_route(
            self::ROUTE_NAMESPACE,
            self::ROUTE_PREFIX,
            array(
                'methods' => \WP_REST_Server::CREATABLE,
                'callback' => array(__CLASS__, 'intake'),
                'permission_callback' => '__return_true',
            )
        );
    }

    /**
     * Intake: accept webhook, enqueue async job, return 200 quickly.
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public static function intake(\WP_REST_Request $request): \WP_REST_Response
    {
        if (self::validateRequest($request) === false) {
            return new \WP_REST_Response(['message' => 'webhook caught'], 200);
        }
        $logger = self::make_logger();

        $payload = $request->get_json_params() ?: array();

        if (!is_array($payload)) {
            return new \WP_REST_Response(array('ok' => false, 'error' => 'missing_event'), 400);
        }
        try {
            $repo = new WebhookEventRepository();
            $repo->insert(
                sanitize_text_field((string)$payload['number']),
                sanitize_text_field((string)$payload['transaction_details']['transactions_id']),
                $_SERVER['REMOTE_ADDR']
            );
        } catch (\Exception $e) {
            $logger->error('Error saving webhook event: ' . $e->getMessage());
        }


        $hook = 'pmg_webhook_worker';
        $args = [];
        $group = 'pmg-webhooks';

        $hasAS = function_exists('as_has_scheduled_action') && function_exists('as_enqueue_async_action');

        if (!$hasAS) {
            self::process_queue();
            return new \WP_REST_Response(['message' => 'webhook caught'], 200);
        }

        // Check if there's an active worker AFTER saving event to DB
        // - If worker is 'pending': it hasn't started yet, it will pick up this event from DB
        // - If worker is 'in-progress': it already loaded its batch, won't see this event → need new worker
        $workerStatus = self::get_worker_status($hook, $args, $group);
        if ($workerStatus === 'pending') {
            $logger->info('AS: worker pending (not started yet), will process this event, skipping enqueue');
            return new \WP_REST_Response(['message' => 'webhook caught'], 200);
        }
        
        // For 'in-progress' or no worker → create new worker

        // No active worker - create new one
        $enqueued = false;
        try {
            as_enqueue_async_action($hook, $args, $group);
            $enqueued = true;
            $logger->info('AS: new worker enqueued');
        } catch (\Throwable $e) {
            $logger->info('as_enqueue_async_action function has thrown an exception: ' . $e->getMessage());
        }

        if (!$enqueued) {
            self::process_queue();
        }

        return new \WP_REST_Response(['message' => 'webhook caught'], 200);
    }

    /**
     * Action Scheduler job: verify with provider API and update order status.
     * @param array $event
     * @return string
     */
    public static function process_job($event): string
    {
        $logger = self::make_logger();

        $eventId = (int)($event[WebhookEventRepository::ENTITY_ID] ?? 0);
        $orderNumber = (string)($event[WebhookEventRepository::ORDER_ID] ?? '');
        $transactionsId = (string)($event[WebhookEventRepository::TRANSACTION_ID] ?? '');
        $retryCount = (int)($event[WebhookEventRepository::RETRY_COUNT] ?? 0);

        $logger->info('AS: processing webhook event_id=' . $eventId . ', transaction_id=' . $transactionsId . ', order_number_from_webhook=' . $orderNumber);

        // Find order by transaction ID instead of order ID for better reliability
        $order = self::find_order_by_transaction_id($transactionsId);
        if (!($order instanceof \WC_Order)) {
            $order = self::find_order_by_number($orderNumber);
        }

        if ($order instanceof \WC_Order) {
            $logger->set_order_id($order->get_id());
            
            // Log if order_id from webhook doesn't match the found order
            $foundOrderId = (string)$order->get_id();
            if ($orderNumber !== $foundOrderId) {
                $logger->warning("Order ID mismatch: webhook sent order_id={$orderNumber}, but transaction_id={$transactionsId} belongs to order_id={$foundOrderId}");
            }
        } else {
            $logger->error("Webhook event {$eventId}: order not found for transaction_id={$transactionsId}");
            return \WooCommercePMG\Model\WebhookEventRepository::STATUS_FAILED;
        }

        if ($order->get_payment_method() !== self::get_plugin_id()) {
            $logger->error("Webhook event {$eventId} could not be applied, payment method mismatch");
            return \WooCommercePMG\Model\WebhookEventRepository::STATUS_FAILED;
        }
        // The webhook can belong to an earlier attempt than the one stored as the order's transaction_id.
        $eventPaymentId = PaymentsAPI::get_payment_id_for_transactions_id($order, $transactionsId);

        $settings = get_option('woocommerce_' . self::get_plugin_id() . '_settings', array());
        $private = $settings['private-key'] ?? '';
        $public = $settings['public-key'] ?? '';

        $api = new PaymentsAPI($private, $public, $logger);

        $status = $api->define_order_status_across_attempts($order, $eventPaymentId);
        if ($api->getLastStatusCode() === 0) {
            // Network/timeout or no HTTP response: schedule retry
            $repo = new WebhookEventRepository();
            $nextRetryNum = $retryCount + 1;
            if ($nextRetryNum > 5) {
                return \WooCommercePMG\Model\WebhookEventRepository::STATUS_FAILED;
            }
            $extra = (self::RETRY_DELAYS[$nextRetryNum] ?? 60);
            $runAt = time() + 60 + $extra * 60;
            $repo->updateStatus($eventId, WebhookEventRepository::STATUS_RETRY, $nextRetryNum, $runAt);

            // Schedule retry worker - finds nearest retry time from all retry events
            self::schedule_next_retry_worker();

            return WebhookEventRepository::STATUS_RETRY;
        }
        $statusResult = self::process_payment_status($order, $status, $logger);

        $logger->info('AS: webhook processing finished for order #' . $order->get_id());

        return $statusResult;
    }

    /**
     * Process payment status and update order accordingly
     * 
     * @param \WC_Order $order
     * @param string $status Payment status from gateway
     * @param Logger $logger
     * @return string Webhook event status (processed/failed)
     */
    private static function process_payment_status(\WC_Order $order, string $status, Logger $logger): string
    {
        switch ($status) {
            case OrderStatus::IN_PROGRESS:
            case OrderStatus::HOLD:
                return self::handle_webhook_hold_status($order, $logger);
                
            case OrderStatus::PAID:
                return self::handle_webhook_paid_status($order, $logger);
                
            case OrderStatus::FAILED:
                return self::handle_webhook_failed_status($order, $logger);
                
            default:
                $logger->warning('Unknown payment status from webhook: ' . $status);
                return WebhookEventRepository::STATUS_FAILED;
        }
    }

    /**
     * Handle HOLD and IN_PROGRESS statuses from webhook
     * Set order to on-hold (stock will be reduced automatically via hook)
     * 
     * @param \WC_Order $order
     * @param Logger $logger
     * @return string
     */
    private static function handle_webhook_hold_status(\WC_Order $order, Logger $logger): string
    {
        if ($order->get_status() !== 'on-hold' && !$order->is_paid()) {
            // Stock will be reduced automatically via 'woocommerce_order_status_on-hold' hook
            $order->update_status('on-hold', __('Awaiting payment confirmation.'));
            $logger->info('Order status updated to on-hold via webhook');
        }
        
        return WebhookEventRepository::STATUS_PROCESSED;
    }

    /**
     * Handle PAID status from webhook
     * Complete payment (stock is reduced automatically by payment_complete)
     * 
     * @param \WC_Order $order
     * @param Logger $logger
     * @return string
     */
    private static function handle_webhook_paid_status(\WC_Order $order, Logger $logger): string
    {
        // Stock will be reduced automatically via 'woocommerce_payment_complete' hook
        $order->payment_complete();
        $logger->info('Payment completed via webhook');
        
        return WebhookEventRepository::STATUS_PROCESSED;
    }

    /**
     * Handle FAILED status from webhook
     * Set order to failed
     * 
     * @param \WC_Order $order
     * @param Logger $logger
     * @return string
     */
    private static function handle_webhook_failed_status(\WC_Order $order, Logger $logger): string
    {
        // A failed attempt never overrides an order that another attempt already paid.
        if ($order->get_status() !== 'failed' && !$order->is_paid()) {
            $order->update_status('failed', __('ERROR: Payment failed.'));
            $logger->info('Order status updated to failed via webhook');
        }
        
        return WebhookEventRepository::STATUS_FAILED;
    }


    protected static function validateRequest(\WP_REST_Request $request): bool
    {
        $logger = self::make_logger();
        $logger->info('Validating webhook request');

        if (!$request->get_header('x-webhook-event')) {
            $logger->log_info_with_data('Invalid webhook request from Ip', ['ip' => $_SERVER['REMOTE_ADDR']]);
            $logger->log_info_with_data('Invalid webhook request headers', ['headers' => $request->get_headers()]);
            $logger->log_info_with_data('Webhook request data', ['data' => $request->get_body() ?? 'No data']);

            return false;
        }

        if (in_array($request->get_header('x-webhook-event'), self::EVENTS)) {

            return true;
        }

        $logger->log_info_with_data('Skipping webhook event: event not supported', [
            'event_type' => $request->get_header('x-webhook-event'),
        ]);

        return false;
    }

    /**
     * Find WooCommerce order by transaction ID
     * Searches in 'transactions_id' custom meta field
     * 
     * Note: We store transaction_id using: $order->update_meta_data('transactions_id', $value)
     * The standard WC field '_transaction_id' contains payment_id, not transaction_id
     * 
     * @param string $transactionId Transaction ID to search for
     * @return \WC_Order|false Order object if found, false otherwise
     */
    private static function find_order_by_transaction_id(string $transactionId)
    {
        if (empty($transactionId)) {
            return false;
        }

        // Find by custom 'transactions_id' meta field
        // This field is set in WooCommercePMG::process_payment() via:
        // $order->update_meta_data('transactions_id', $payment['transaction_details']['transactions_id'])
        // 'transactions_id' only holds the latest attempt; earlier attempts are kept one row each.
        foreach (array('transactions_id', PaymentsAPI::ATTEMPT_TRANSACTIONS_ID_META) as $metaKey) {
            $orders = wc_get_orders([
                'limit' => 1,
                'meta_key' => $metaKey,
                'meta_value' => $transactionId,
                'payment_method' => self::get_plugin_id(), // Only search in PMG orders
            ]);

            if (!empty($orders) && $orders[0] instanceof \WC_Order) {
                return $orders[0];
            }
        }

        return false;
    }

    /**
     * Fallback for webhooks whose transaction_id is not stored on any order,
     * e.g. attempts created before payment attempts were recorded.
     *
     * @param string $orderNumber Order number sent by the gateway
     * @return \WC_Order|false
     */
    private static function find_order_by_number(string $orderNumber)
    {
        if ($orderNumber === '' || !ctype_digit($orderNumber)) {
            return false;
        }

        $order = wc_get_order((int)$orderNumber);
        if ($order instanceof \WC_Order && (string)$order->get_order_number() === $orderNumber) {
            return $order;
        }

        return false;
    }

    private static function make_logger(?\WC_Order $order = null): Logger
    {
        $gateway_id = $order instanceof \WC_Order ? $order->get_payment_method() : self::get_plugin_id();
        $settings = get_option('woocommerce_' . $gateway_id . '_settings', []);
        $debug = isset($settings['debug']) && $settings['debug'] === 'yes';

        $logger = new Logger((bool)$debug);
        if ($order instanceof \WC_Order) {
            $logger->set_order_id($order->get_id());
        }
        return $logger;
    }

    /**
     * Get current worker status in Action Scheduler
     * 
     * @param string $hook
     * @param array $args
     * @param string $group
     * @return string|null Status: 'pending', 'in-progress', 'complete', 'failed', 'canceled', or null if not found
     */
    private static function get_worker_status(string $hook, array $args, string $group): ?string
    {
        if (!function_exists('as_get_scheduled_actions')) {
            return null;
        }

        // Check all active statuses (pending and in-progress)
        $activeStatuses = ['pending', 'in-progress'];
        
        foreach ($activeStatuses as $status) {
            $actions = as_get_scheduled_actions(array(
                'hook' => $hook,
                'args' => $args,
                'group' => $group,
                'status' => $status,
                'per_page' => 1,
            ), 'ids');
            
            if (!empty($actions)) {
                return $status;
            }
        }

        // Check if there are any completed/failed actions (recent activity)
        $completedStatuses = ['complete', 'failed'];
        foreach ($completedStatuses as $status) {
            $actions = as_get_scheduled_actions(array(
                'hook' => $hook,
                'args' => $args,
                'group' => $group,
                'status' => $status,
                'per_page' => 1,
                'date' => new \DateTime('-5 minutes'), // Check last 5 minutes
                'date_compare' => '>=',
            ), 'ids');
            
            if (!empty($actions)) {
                return $status;
            }
        }

        return null;
    }

    /**
     * Processes a batch of pending webhook events in the payment queue.
     * Fetches up to 50 unprocessed events, processes each event, and updates their statuses
     * depending on whether processing succeeded or failed. Failed events are scheduled for a retry
     * with a fixed delay of 5 minutes.
     *
     * @return void
     */
    public static function process_queue(): void
    {
        $repo = new \WooCommercePMG\Model\WebhookEventRepository();
        $logger = self::make_logger();

        // Process only pending events (retry events have separate worker)
        $events = $repo->findByStatus(\WooCommercePMG\Model\WebhookEventRepository::STATUS_PENDING, 50, 0);
        if (empty($events)) {
            $logger->info("AS: no pending events to process, worker completed");
            return;
        }

        foreach ($events as $event) {
            $entityId = (int)($event['entity_id'] ?? 0);
            if ($entityId <= 0) {
                continue;
            }

            try {
                $status = self::process_job($event);

                if ($status === \WooCommercePMG\Model\WebhookEventRepository::STATUS_PROCESSED) {
                    $repo->updateStatus($entityId, \WooCommercePMG\Model\WebhookEventRepository::STATUS_PROCESSED);
                }

                if ($status === \WooCommercePMG\Model\WebhookEventRepository::STATUS_FAILED) {
                    $repo->updateStatus($entityId, \WooCommercePMG\Model\WebhookEventRepository::STATUS_FAILED);
                }

            } catch (\Throwable $e) {
                $logger->error('Error processing webhook event id - ' . $entityId . ' : ' . $e->getMessage());
            }
        }

        $logger->info("AS: processed " . count($events) . " pending events");
    }

    /**
     * Processes a batch of retry webhook events.
     * Fetches up to 50 retry events that are ready to process (next_retry_at <= now).
     * After processing, checks if there are more retry events and schedules next retry worker.
     *
     * @return void
     */
    public static function process_retry_queue(): void
    {
        $repo = new \WooCommercePMG\Model\WebhookEventRepository();
        $logger = self::make_logger();

        $nowGmt = time();

        // Get retry events
        $retryEvents = $repo->findByStatus(\WooCommercePMG\Model\WebhookEventRepository::STATUS_RETRY, 50, 0);
        
        if (empty($retryEvents)) {
            $logger->info("AS: no retry events to process, retry worker completed");
            return;
        }

        // Filter events that are ready to process (next_retry_at <= now)
        $readyEvents = [];
        foreach ($retryEvents as $event) {
            $nextRetryAt = isset($event['next_retry_at']) && $event['next_retry_at'] !== null
                ? strtotime($event['next_retry_at'] . ' UTC')
                : null;

            // Process if no next_retry_at (shouldn't happen) or if time has come
            if ($nextRetryAt === null || $nextRetryAt <= $nowGmt) {
                $readyEvents[] = $event;
            }
        }

        if (empty($readyEvents)) {
            $logger->info("AS: no retry events ready yet, scheduling nearest retry");
            self::schedule_next_retry_worker();
            return;
        }

        // Process ready retry events
        foreach ($readyEvents as $event) {
            $entityId = (int)($event['entity_id'] ?? 0);
            if ($entityId <= 0) {
                continue;
            }

            try {
                $status = self::process_job($event);

                if ($status === \WooCommercePMG\Model\WebhookEventRepository::STATUS_PROCESSED) {
                    $repo->updateStatus($entityId, \WooCommercePMG\Model\WebhookEventRepository::STATUS_PROCESSED);
                }

                if ($status === \WooCommercePMG\Model\WebhookEventRepository::STATUS_FAILED) {
                    $repo->updateStatus($entityId, \WooCommercePMG\Model\WebhookEventRepository::STATUS_FAILED);
                }

            } catch (\Throwable $e) {
                $logger->error('Error processing retry webhook event id - ' . $entityId . ' : ' . $e->getMessage());
            }
        }

        $logger->info("AS: processed " . count($readyEvents) . " retry events");

        // After processing, check if there are more retry events and schedule next worker
        self::schedule_next_retry_worker();
    }

    /**
     * Schedule next retry worker if there are retry events
     * Finds nearest retry time and schedules worker
     *
     * @return void
     */
    private static function schedule_next_retry_worker(): void
    {
        $logger = self::make_logger();
        $repo = new \WooCommercePMG\Model\WebhookEventRepository();
        $hook = 'pmg_webhook_worker_retry';
        $args = [];
        $group = 'pmg-webhooks';

        // Check if there are more retry events
        $retryEvents = $repo->findByStatus(\WooCommercePMG\Model\WebhookEventRepository::STATUS_RETRY, 100, 0);
        if (empty($retryEvents)) {
            $logger->info("AS: no more retry events, retry worker completed");
            return;
        }

        // Find nearest retry time from all retry events
        $nowGmt = time();
        $nearestRetryAt = null;

        foreach ($retryEvents as $event) {
            $nextRetryAt = isset($event['next_retry_at']) && $event['next_retry_at'] !== null
                ? strtotime($event['next_retry_at'] . ' UTC')
                : null;

            if ($nextRetryAt !== null && $nextRetryAt > $nowGmt) {
                if ($nearestRetryAt === null || $nextRetryAt < $nearestRetryAt) {
                    $nearestRetryAt = $nextRetryAt;
                }
            }
        }

        if ($nearestRetryAt === null) {
            // All retry events are ready, schedule immediately
            $nearestRetryAt = time();
        }

        if (function_exists('as_schedule_single_action')) {
            try {
                $existingStatus = self::get_worker_status($hook, $args, $group);
                
                if ($existingStatus === 'pending') {
                    // Worker already scheduled, check if we need to reschedule for earlier time
                    $scheduledActions = as_get_scheduled_actions(array(
                        'hook' => $hook,
                        'args' => $args,
                        'group' => $group,
                        'status' => 'pending',
                        'per_page' => 1,
                    ));
                    
                    if (!empty($scheduledActions)) {
                        $existingAction = reset($scheduledActions);
                        $existingScheduleTime = $existingAction->get_schedule()->get_date()->getTimestamp();
                        
                        // Only reschedule if new time is earlier
                        if ($nearestRetryAt < $existingScheduleTime) {
                            as_unschedule_action($hook, $args, $group);
                            as_schedule_single_action($nearestRetryAt, $hook, $args, $group);
                            $logger->info("AS: rescheduled retry worker to earlier time " . gmdate('c', $nearestRetryAt) . " (was " . gmdate('c', $existingScheduleTime) . ")");
                        } else {
                            $logger->info("AS: retry worker already scheduled at " . gmdate('c', $existingScheduleTime) . ", no need to reschedule");
                        }
                    }
                } else {
                    // No worker scheduled, create new one
                    as_schedule_single_action($nearestRetryAt, $hook, $args, $group);
                    $logger->info("AS: scheduled retry worker at " . gmdate('c', $nearestRetryAt));
                }
            } catch (\Throwable $e) {
                $logger->info('as_schedule_single_action failed: ' . $e->getMessage());
            }
        }
    }

    /**
     * Daily cleanup: delete completed Action Scheduler actions for our worker older than 7 days.
     * Runs via 'pmg_as_cleanup_completed'.
     */
    public static function cleanup_completed_actions(): void
    {
        if (! class_exists('ActionScheduler')) {
            return;
        }
        $logger = self::make_logger();

        try {
            $store = \ActionScheduler::store();

            $ids = $store->query_actions([
                'hook'         => 'pmg_webhook_worker',
                'group'        => 'pmg-webhooks',
                'status'       => \ActionScheduler_Store::STATUS_COMPLETE,
                'date'         => new \DateTime('-1 days'),
                'date_compare' => '<=',
                'per_page'     => 1000,
            ]);

            if (! empty($ids)) {
                foreach ($ids as $id) {
                    $store->delete_action($id);
                }
            }
        } catch (\Throwable $e) {
            $logger->info('ActionScheduler cleanup failed: ' . $e->getMessage());
        }

        $logger->info('AS: completed actions cleanup completed. Was removed ' . count($ids) . ' actions');
    }
}

