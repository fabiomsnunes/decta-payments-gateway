<?php

namespace WooCommercePMG\Model;

class WebhookEventRepository
{
    /** @var string */
    private $table;

    public const ENTITY_ID = 'entity_id';

    public const ORDER_ID = 'order_id';

    public const TRANSACTION_ID = 'transaction_id';

    public const IP = 'ip';

    public const STATUS = 'status';

    public const RETRY_COUNT = 'retry_count';

    public const NEXT_RETRY_AT = 'next_retry_at';

    public const CREATED_AT = 'created_at';

    public const UPDATED_AT = 'updated_at';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSED = 'processed';

    public const STATUS_RETRY = 'retry';

    public const STATUS_FAILED = 'failed';

    public const STATUS_FINAL_FAILED = 'final_failed';

    private const RETRY_DELAYS = [
        1 => 5,
        2 => 15,
        3 => 30,
        4 => 60,
        5 => 240,
    ];

    public function __construct()
    {
        global $wpdb;
        $this->table = $wpdb->prefix . 'woocommerce_pmg_payment_webhook_event';
    }

    /**
     * Insert new webhook event.
     *
     * @return int entity_id
     */
    public function insert(string $orderId, string $transactionId, string $ip): int
    {
        global $wpdb;

        $row = [
            self::ORDER_ID => $orderId,
            self::TRANSACTION_ID => $transactionId,
            self::IP => $ip,
        ];

        $formats = ['%s', '%s', '%s'];
        $wpdb->insert($this->table, $row, $formats);

        return (int)$wpdb->insert_id;
    }

    /**
     * Update status/retries of event by entity_id.
     */
    public function updateStatus(int $entityId, string $status, ?int $retryCount = null, ?int $nextRetryAt = null): bool
    {
        global $wpdb;

        $data = ['status' => $status];
        $where = ['entity_id' => $entityId];
        $fmt = ['%s'];
        $wfmt = ['%d'];

        if ($retryCount !== null) {
            $data['retry_count'] = (int)$retryCount;
            $fmt[] = '%d';
        }
        if ($nextRetryAt !== null) {
            $data['next_retry_at'] = gmdate('Y-m-d H:i:s', (int)$nextRetryAt);
            $fmt[] = '%s';
        }

        return false !== $wpdb->update($this->table, $data, $where, $fmt, $wfmt);
    }

    /**
     * Find events by status (with optional limit/offset).
     * @return array<int, array> rows
     */
    public function findByStatus(string $status, int $limit = 50, int $offset = 0): array
    {
        global $wpdb;
        $sql = $wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE status = %s ORDER BY entity_id ASC LIMIT %d OFFSET %d",
            $status,
            $limit,
            $offset
        );
        return $wpdb->get_results($sql, ARRAY_A) ?: [];
    }

    /**
     * Get single event by ID.
     */
    public function getById(int $entityId): ?array
    {
        global $wpdb;
        $sql = $wpdb->prepare("SELECT * FROM {$this->table} WHERE entity_id = %d", $entityId);
        $row = $wpdb->get_row($sql, ARRAY_A);
        return $row ?: null;
    }

    /**
     * Soft requeue helper: move to pending and increment retry_count.
     */
    public function requeue(int $entityId): bool
    {
        $event = $this->getById($entityId);
        $retry = (int)(($event['retry_count'] ?? 0) + 1);
        // Schedule next attempt after 1 minute plus per-attempt extra seconds from RETRY_DELAYS
        $delay = (int)(self::RETRY_DELAYS[$retry] ?? 60);
        $next = time() + 60 + max(0, $delay);
        return $this->updateStatus($entityId, self::STATUS_RETRY, $retry, $next);
    }

    /**
     * Hard delete (use обережно).
     */
    public function delete(int $entityId): bool
    {
        global $wpdb;
        return false !== $wpdb->delete($this->table, ['entity_id' => $entityId], ['%d']);
    }
}
