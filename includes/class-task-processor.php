<?php
/**
 * Background worker: processes tasks from the queue.
 *
 * - 2–5 second simulated processing delay
 * - ~30% random failure simulation
 * - Retry up to WTP_MAX_ATTEMPTS with exponential backoff
 * - Publishes status changes to WebSocket clients via Redis
 *
 * @package WPTaskProcessor
 */

defined( 'ABSPATH' ) || exit;

class WTP_Task_Processor {

    /** @var WTP_Task_Manager */
    private $manager;

    /** @var WTP_Task_Queue */
    private $queue;

    /** @var WTP_Logger */
    private $logger;

    /** Max tasks processed per cron tick */
    const BATCH_SIZE = 5;

    public function __construct(
        WTP_Task_Manager $manager,
        WTP_Task_Queue   $queue,
        WTP_Logger       $logger
    ) {
        $this->manager = $manager;
        $this->queue   = $queue;
        $this->logger  = $logger;

        // Also handle single-task dispatches
        add_action( 'wtp_process_single', [ $this, 'process_single' ] );
    }

    // ── Cron entry-points ─────────────────────────────────────────────────────

    /**
     * Called every minute by WP-Cron. Processes up to BATCH_SIZE pending tasks.
     */
    public function run(): void {
        // Release stale locks first
        $released = $this->manager->release_stale_locks();
        if ( $released > 0 ) {
            $this->logger->warning( "Released {$released} stale processing locks" );
        }

        $processed = 0;

        while ( $processed < self::BATCH_SIZE ) {
            $task = $this->queue->claim_next();
            if ( ! $task ) {
                break;
            }

            $this->execute( $task );
            $processed++;
        }

        if ( $processed > 0 ) {
            $this->logger->info( "Processed {$processed} task(s) in this cron tick" );
        }
    }

    /**
     * Process a single task by ID (triggered by immediate dispatch).
     */
    public function process_single( string $task_id ): void {
        $task = $this->queue->claim( $task_id );
        if ( ! $task ) {
            return; // Already claimed by another worker
        }
        $this->execute( $task );
    }

    /**
     * Retry tasks that failed but still have attempts left.
     * Called every 5 minutes by WP-Cron.
     *
     * Uses update_status() (not queue->schedule_retry) so that cache is
     * refreshed and WebSocket clients receive the status-change event.
     */
    public function retry_failed_tasks(): void {
        $tasks = $this->manager->get_retryable_tasks( 10 );

        foreach ( $tasks as $task ) {
            $delay        = (int) pow( 2, $task->attempts );
            $scheduled_at = gmdate( 'Y-m-d H:i:s', time() + $delay );

            $this->manager->update_status( $task->id, WTP_Task::STATUS_PENDING, [
                'locked_until' => null,
                'scheduled_at' => $scheduled_at,
            ] );

            $this->logger->info(
                "Re-queued for retry in {$delay}s (attempt {$task->attempts}/{$task->max_attempts})",
                [],
                $task->id
            );
        }

        if ( ! empty( $tasks ) ) {
            $this->logger->info( count( $tasks ) . ' task(s) re-queued for retry' );
        }
    }

    // ── Core execution logic ──────────────────────────────────────────────────

    /**
     * Execute a task: simulate work, handle success/failure, update state.
     */
    private function execute( WTP_Task $task ): void {
        $this->logger->info( "Processing started (attempt #{$task->attempts})", [], $task->id );

        // Broadcast "processing" status to WebSocket clients
        $processing_task = $this->manager->update_status(
            $task->id,
            WTP_Task::STATUS_PROCESSING,
            [ 'attempts' => $task->attempts + 1 ]
        );

        if ( ! $processing_task ) {
            return;
        }

        // ── Simulate async work: 2–5 second delay ────────────────────────────
        $delay = mt_rand( 2, 5 );

        // In a real system this would be actual async work.
        // PHP on WP-Cron is synchronous, so we use sleep().
        // The long-running WP-Cron loopback keeps the request alive.
        sleep( $delay );

        $task->attempts = $processing_task->attempts;

        // ── Simulate ~30% failure rate ────────────────────────────────────────
        $failed = $this->should_fail( $task );

        if ( $failed ) {
            $this->handle_failure( $processing_task, 'Simulated processing failure (30% random failure rate)' );
        } else {
            $this->handle_success( $processing_task, $delay );
        }
    }

    // ── Success handler ───────────────────────────────────────────────────────

    private function handle_success( WTP_Task $task, int $delay ): void {
        $result = [
            'processed'   => true,
            'duration_ms' => $delay * 1000,
            'output'      => $this->generate_result_output( $task ),
            'completed_at'=> current_time( 'mysql' ),
        ];

        $this->manager->update_status( $task->id, WTP_Task::STATUS_COMPLETED, [
            'result'       => wp_json_encode( $result ),
            'locked_until' => null,
            'error_message'=> null,
        ] );

        $this->logger->info( "Completed successfully in {$delay}s", $result, $task->id );
        do_action( 'wtp_task_completed', $task, $result );
    }

    // ── Failure handler ───────────────────────────────────────────────────────

    private function handle_failure( WTP_Task $task, string $reason ): void {
        $this->logger->error( "Failed: {$reason}", [ 'attempts' => $task->attempts ], $task->id );

        if ( $task->can_retry() ) {
            // Single update: set pending + backoff scheduled_at + error_message + release lock.
            // Previously this was two separate writes (schedule_retry then update_status),
            // which caused double cache refreshes and double WebSocket broadcasts.
            $delay        = (int) pow( 2, $task->attempts ); // exponential backoff
            $scheduled_at = gmdate( 'Y-m-d H:i:s', time() + $delay );

            $this->manager->update_status( $task->id, WTP_Task::STATUS_PENDING, [
                'error_message' => $reason,
                'locked_until'  => null,
                'scheduled_at'  => $scheduled_at,
            ] );

            $this->logger->info(
                "Retry scheduled in {$delay}s (attempt {$task->attempts}/{$task->max_attempts})",
                [],
                $task->id
            );
        } else {
            // Exhausted all retries — permanently failed
            $this->manager->update_status( $task->id, WTP_Task::STATUS_FAILED, [
                'error_message' => $reason,
                'locked_until'  => null,
            ] );
            $this->logger->error( 'All retries exhausted — task permanently failed', [], $task->id );
            do_action( 'wtp_task_failed', $task, $reason );
        }
    }

    // ── Failure simulation ────────────────────────────────────────────────────

    /**
     * Returns true ~30% of the time, simulating a real-world failure.
     * First attempt is slightly more lenient to improve demo UX.
     */
    private function should_fail( WTP_Task $task ): bool {
        $rate = WTP_FAILURE_RATE;

        // Allow overriding via task payload for testing
        if ( isset( $task->payload['_force_fail'] ) && $task->payload['_force_fail'] ) {
            return true;
        }
        if ( isset( $task->payload['_force_success'] ) && $task->payload['_force_success'] ) {
            return false;
        }

        // Use a seeded random to make tests reproducible when needed
        $rand = ( mt_rand( 0, 99 ) / 100 );
        return $rand < $rate;
    }

    // ── Result generator ──────────────────────────────────────────────────────

    private function generate_result_output( WTP_Task $task ): array {
        $outputs = [
            'email'    => [ 'message' => 'Email sent successfully', 'recipients' => 1 ],
            'report'   => [ 'message' => 'Report generated', 'rows' => mt_rand( 10, 500 ) ],
            'export'   => [ 'message' => 'Export complete', 'file_size' => mt_rand( 1024, 512000 ) ],
            'import'   => [ 'message' => 'Import complete', 'records' => mt_rand( 5, 200 ) ],
            'sync'     => [ 'message' => 'Sync complete', 'synced' => mt_rand( 1, 50 ) ],
            'cleanup'  => [ 'message' => 'Cleanup complete', 'deleted' => mt_rand( 0, 100 ) ],
            'default'  => [ 'message' => 'Task completed', 'status' => 'ok' ],
        ];

        return $outputs[ $task->type ] ?? $outputs['default'];
    }
}
