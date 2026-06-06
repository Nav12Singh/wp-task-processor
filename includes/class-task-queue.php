<?php
/**
 * BullMQ-style job queue using MySQL with optimistic locking.
 *
 * Provides concurrent-safe task claiming via DB-level locks so multiple
 * cron workers can run without processing the same task twice.
 *
 * @package WPTaskProcessor
 */

defined( 'ABSPATH' ) || exit;

class WTP_Task_Queue {

    /** @var WTP_Database */
    private $db;

    /** @var WTP_Logger */
    private $logger;

    /** Lock duration in seconds while a task is being processed */
    const LOCK_TTL_SECONDS = 300; // 5 minutes

    public function __construct( WTP_Database $db, WTP_Logger $logger ) {
        $this->db     = $db;
        $this->logger = $logger;
    }

    // ── Enqueue ───────────────────────────────────────────────────────────────

    /**
     * Mark a task as ready to be processed (ensure it stays/returns to pending).
     * Called after creating a task to kick off the queue immediately via an
     * async loopback request rather than waiting for the next cron tick.
     */
    public function dispatch( WTP_Task $task ): void {
        // Trigger async processing via wp-cron loopback
        wp_schedule_single_event( time(), 'wtp_process_single', [ $task->id ] );
        $this->logger->debug( 'Task dispatched to queue', [], $task->id );
        do_action( 'wtp_task_dispatched', $task );
    }

    // ── Dequeue / claim (concurrent-safe) ────────────────────────────────────

    /**
     * Atomically claim the next available pending task.
     *
     * Uses optimistic locking: SELECT the row, then UPDATE with a WHERE guard
     * on the lock column. If rows affected = 0, another worker won the race.
     *
     * @return WTP_Task|null
     */
    public function claim_next(): ?WTP_Task {
        global $wpdb;

        $table        = $this->db->tasks_table();
        $now          = current_time( 'mysql' );
        $locked_until = gmdate( 'Y-m-d H:i:s', time() + self::LOCK_TTL_SECONDS );

        // Find a candidate (no transaction lock needed for the SELECT)
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE status = 'pending'
               AND scheduled_at <= %s
               AND (locked_until IS NULL OR locked_until < %s)
             ORDER BY created_at ASC
             LIMIT 1",
            $now,
            $now
        ) );

        if ( ! $row ) {
            return null;
        }

        // Optimistic UPDATE — only succeeds if no other worker grabbed it
        $affected = $wpdb->query( $wpdb->prepare(
            "UPDATE {$table}
             SET status       = 'processing',
                 locked_until = %s,
                 updated_at   = %s
             WHERE task_id = %s
               AND status  = 'pending'
               AND (locked_until IS NULL OR locked_until < %s)",
            $locked_until,
            $now,
            $row->task_id,
            $now
        ) );

        if ( ! $affected ) {
            // Race lost — another worker claimed it
            return null;
        }

        $row->status       = WTP_Task::STATUS_PROCESSING;
        $row->locked_until = $locked_until;
        $row->updated_at   = $now;

        $this->logger->debug( 'Task claimed by worker', [], $row->task_id );

        return WTP_Task::from_row( $row );
    }

    /**
     * Claim a specific task by ID (used for single-task dispatches).
     */
    public function claim( string $task_id ): ?WTP_Task {
        global $wpdb;

        $table        = $this->db->tasks_table();
        $now          = current_time( 'mysql' );
        $locked_until = gmdate( 'Y-m-d H:i:s', time() + self::LOCK_TTL_SECONDS );

        $affected = $wpdb->query( $wpdb->prepare(
            "UPDATE {$table}
             SET status       = 'processing',
                 locked_until = %s,
                 updated_at   = %s
             WHERE task_id = %s
               AND status  = 'pending'
               AND (locked_until IS NULL OR locked_until < %s)",
            $locked_until,
            $now,
            $task_id,
            $now
        ) );

        if ( ! $affected ) {
            return null;
        }

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE task_id = %s",
            $task_id
        ) );

        return $row ? WTP_Task::from_row( $row ) : null;
    }

    // ── Release / complete ────────────────────────────────────────────────────

    /**
     * Release lock without changing status (on abnormal termination).
     */
    public function release( string $task_id ): void {
        global $wpdb;

        $wpdb->query( $wpdb->prepare(
            "UPDATE {$this->db->tasks_table()}
             SET locked_until = NULL, updated_at = %s
             WHERE task_id = %s",
            current_time( 'mysql' ),
            $task_id
        ) );
    }

    /**
     * Schedule a retry with exponential backoff.
     * Delay = 2^attempt seconds (1, 2, 4, 8 …).
     */
    public function schedule_retry( WTP_Task $task ): void {
        global $wpdb;

        $delay        = (int) pow( 2, $task->attempts ); // exponential backoff
        $scheduled_at = gmdate( 'Y-m-d H:i:s', time() + $delay );

        $wpdb->update(
            $this->db->tasks_table(),
            [
                'status'       => WTP_Task::STATUS_PENDING,
                'locked_until' => null,
                'scheduled_at' => $scheduled_at,
                'updated_at'   => current_time( 'mysql' ),
            ],
            [ 'task_id' => $task->id ],
            [ '%s', '%s', '%s', '%s' ],
            [ '%s' ]
        );

        $this->logger->info(
            "Retry scheduled in {$delay}s (attempt {$task->attempts}/{$task->max_attempts})",
            [],
            $task->id
        );
    }

    // ── Queue stats ───────────────────────────────────────────────────────────

    /**
     * Number of tasks waiting in the queue.
     */
    public function pending_count(): int {
        global $wpdb;

        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->db->tasks_table()} WHERE status = 'pending'"
        );
    }

    /**
     * Number of tasks currently being processed.
     */
    public function processing_count(): int {
        global $wpdb;

        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->db->tasks_table()} WHERE status = 'processing'"
        );
    }
}
