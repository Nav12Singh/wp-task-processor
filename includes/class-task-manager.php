<?php
/**
 * Core business logic: create, read, update, list tasks.
 * Handles idempotency and cache invalidation.
 *
 * @package WPTaskProcessor
 */

defined( 'ABSPATH' ) || exit;

class WTP_Task_Manager {

    /** @var WTP_Database */
    private $db;

    /** @var WTP_Redis_Cache */
    private $cache;

    /** @var WTP_Logger */
    private $logger;

    public function __construct( WTP_Database $db, WTP_Redis_Cache $cache, WTP_Logger $logger ) {
        $this->db     = $db;
        $this->cache  = $cache;
        $this->logger = $logger;
    }

    // ── Create ────────────────────────────────────────────────────────────────

    /**
     * Create a new task with idempotency check.
     *
     * @param  array  $data  { type, payload?, idempotency_key? }
     * @return WTP_Task|WP_Error  Returns existing task if idempotency key already used.
     */
    public function create( array $data ) {
        global $wpdb;

        // Validate required fields
        if ( empty( $data['type'] ) ) {
            return new WP_Error( 'missing_type', __( 'Task type is required.', 'wp-task-processor' ), [ 'status' => 400 ] );
        }

        // Resolve idempotency key
        $idem_key = $this->resolve_idempotency_key( $data );

        // Idempotency check — return existing task if key already seen
        $existing = $this->get_by_idempotency_key( $idem_key );
        if ( $existing ) {
            $this->logger->info( 'Idempotent request — returning existing task', [ 'key' => $idem_key ], $existing->id );
            return $existing;
        }

        $now  = current_time( 'mysql' );
        $task = new WTP_Task( [
            'type'            => sanitize_text_field( $data['type'] ),
            'payload'         => isset( $data['payload'] ) ? (array) $data['payload'] : null,
            'idempotency_key' => $idem_key,
            'max_attempts'    => (int) ( $data['max_attempts'] ?? WTP_MAX_ATTEMPTS ),
            'created_at'      => $now,
            'updated_at'      => $now,
            'scheduled_at'    => $now,
        ] );

        $inserted = $wpdb->insert(
            $this->db->tasks_table(),
            [
                'task_id'         => $task->id,
                'type'            => $task->type,
                'status'          => $task->status,
                'attempts'        => $task->attempts,
                'max_attempts'    => $task->max_attempts,
                'payload'         => wp_json_encode( $task->payload ),
                'idempotency_key' => $task->idempotency_key,
                'created_at'      => $task->created_at,
                'updated_at'      => $task->updated_at,
                'scheduled_at'    => $task->scheduled_at,
            ],
            [ '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s' ]
        );

        if ( false === $inserted ) {
            // Duplicate key race — return the existing row
            $existing = $this->get_by_idempotency_key( $idem_key );
            if ( $existing ) {
                return $existing;
            }
            return new WP_Error( 'db_error', __( 'Failed to create task.', 'wp-task-processor' ), [ 'status' => 500 ] );
        }

        $this->cache->set_task( $task );
        $this->logger->info( 'Task created', [ 'type' => $task->type ], $task->id );
        do_action( 'wtp_task_created', $task );

        return $task;
    }

    // ── Read ──────────────────────────────────────────────────────────────────

    /**
     * Get task by UUID. Checks Redis cache first.
     */
    public function get( string $task_id ): ?WTP_Task {
        // L1 cache hit
        $cached = $this->cache->get_task( $task_id );
        if ( $cached ) {
            return new WTP_Task( $cached );
        }

        return $this->get_from_db( $task_id );
    }

    /**
     * Force-fetch from DB and refresh cache.
     */
    public function get_from_db( string $task_id ): ?WTP_Task {
        global $wpdb;

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->db->tasks_table()} WHERE task_id = %s LIMIT 1",
            $task_id
        ) );

        if ( ! $row ) {
            return null;
        }

        $task = WTP_Task::from_row( $row );
        $this->cache->set_task( $task );
        return $task;
    }

    /**
     * List tasks with optional filters and pagination.
     *
     * @return array{ tasks: WTP_Task[], total: int }
     */
    public function list( array $args = [] ): array {
        global $wpdb;

        $table   = $this->db->tasks_table();
        $status  = isset( $args['status'] ) ? sanitize_text_field( $args['status'] ) : '';
        $type    = isset( $args['type'] )   ? sanitize_text_field( $args['type'] )   : '';
        $limit   = (int) ( $args['per_page'] ?? 20 );
        $offset  = (int) ( $args['offset']   ?? 0 );
        $limit   = min( max( 1, $limit ), 100 );

        $where  = '1=1';
        $params = [];

        if ( $status && in_array( $status, [ 'pending', 'processing', 'completed', 'failed' ], true ) ) {
            $where   .= ' AND status = %s';
            $params[] = $status;
        }

        if ( $type ) {
            $where   .= ' AND type = %s';
            $params[] = $type;
        }

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $total = (int) $wpdb->get_var( $params
            ? $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where}", ...$params )
            : "SELECT COUNT(*) FROM {$table} WHERE {$where}"
        );

        $rows = $params
            ? $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$table} WHERE {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d",
                array_merge( $params, [ $limit, $offset ] )
            ) )
            : $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$table} WHERE {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d",
                $limit, $offset
            ) );
        // phpcs:enable

        return [
            'tasks' => array_map( [ WTP_Task::class, 'from_row' ], $rows ?: [] ),
            'total' => $total,
        ];
    }

    // ── Update ────────────────────────────────────────────────────────────────

    /**
     * Atomically update task status.
     * Returns updated WTP_Task or null if the task wasn't found.
     */
    public function update_status( string $task_id, string $status, array $extra = [] ): ?WTP_Task {
        global $wpdb;

        $allowed = [ WTP_Task::STATUS_PENDING, WTP_Task::STATUS_PROCESSING, WTP_Task::STATUS_COMPLETED, WTP_Task::STATUS_FAILED ];
        if ( ! in_array( $status, $allowed, true ) ) {
            return null;
        }

        $data = array_merge( [
            'status'     => $status,
            'updated_at' => current_time( 'mysql' ),
        ], $extra );

        // Build format array to match $data keys
        $formats = [];
        foreach ( $data as $col => $val ) {
            if ( in_array( $col, [ 'attempts', 'max_attempts' ], true ) ) {
                $formats[] = '%d';
            } else {
                $formats[] = '%s';
            }
        }

        $wpdb->update( $this->db->tasks_table(), $data, [ 'task_id' => $task_id ], $formats, [ '%s' ] );

        $task = $this->get_from_db( $task_id );
        if ( $task ) {
            $this->cache->set_task( $task );
            $this->cache->publish_task_event( $task );
            do_action( 'wtp_task_status_changed', $task, $status );
            $this->logger->info( "Status → {$status}", [], $task_id );
        }

        return $task;
    }

    // ── Idempotency helpers ───────────────────────────────────────────────────

    private function resolve_idempotency_key( array $data ): string {
        if ( ! empty( $data['idempotency_key'] ) ) {
            return sanitize_text_field( $data['idempotency_key'] );
        }

        // Auto-generate from type + canonicalised payload
        $payload = isset( $data['payload'] ) ? (array) $data['payload'] : [];
        ksort( $payload );

        return 'auto:' . md5( ( $data['type'] ?? '' ) . ':' . serialize( $payload ) );
    }

    private function get_by_idempotency_key( string $key ): ?WTP_Task {
        global $wpdb;

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->db->tasks_table()} WHERE idempotency_key = %s LIMIT 1",
            $key
        ) );

        return $row ? WTP_Task::from_row( $row ) : null;
    }

    // ── Bulk helpers (used by processor & admin) ──────────────────────────────

    /**
     * Count tasks by status.
     */
    public function count_by_status(): array {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT status, COUNT(*) as cnt FROM {$this->db->tasks_table()} GROUP BY status"
        );

        $counts = [
            WTP_Task::STATUS_PENDING    => 0,
            WTP_Task::STATUS_PROCESSING => 0,
            WTP_Task::STATUS_COMPLETED  => 0,
            WTP_Task::STATUS_FAILED     => 0,
        ];

        foreach ( $rows as $row ) {
            $counts[ $row->status ] = (int) $row->cnt;
        }

        return $counts;
    }

    /**
     * Get failed tasks eligible for retry.
     *
     * @return WTP_Task[]
     */
    public function get_retryable_tasks( int $limit = 10 ): array {
        global $wpdb;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$this->db->tasks_table()}
             WHERE status = 'failed'
               AND attempts < max_attempts
             ORDER BY updated_at ASC
             LIMIT %d",
            $limit
        ) );

        return array_map( [ WTP_Task::class, 'from_row' ], $rows ?: [] );
    }

    /**
     * Release stale processing locks (tasks stuck > 10 min).
     */
    public function release_stale_locks(): int {
        global $wpdb;

        return (int) $wpdb->query(
            "UPDATE {$this->db->tasks_table()}
             SET status = 'pending', locked_until = NULL, updated_at = NOW()
             WHERE status = 'processing'
               AND locked_until IS NOT NULL
               AND locked_until < DATE_SUB(NOW(), INTERVAL 10 MINUTE)"
        );
    }
}
