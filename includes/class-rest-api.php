<?php
/**
 * WordPress REST API endpoints.
 *
 * POST /wp-json/wtp/v1/tasks         — create task
 * GET  /wp-json/wtp/v1/tasks         — list tasks
 * GET  /wp-json/wtp/v1/tasks/:id     — get task
 * GET  /wp-json/wtp/v1/tasks/:id/logs — task logs
 * POST /wp-json/wtp/v1/tasks/:id/retry — manual retry
 * GET  /wp-json/wtp/v1/stats         — queue stats
 * GET  /wp-json/wtp/v1/events        — SSE stream (real-time fallback)
 *
 * @package WPTaskProcessor
 */

defined( 'ABSPATH' ) || exit;

class WTP_Rest_API {

    /** @var WTP_Task_Manager */
    private $manager;

    /** @var WTP_Logger */
    private $logger;

    public function __construct( WTP_Task_Manager $manager, WTP_Logger $logger ) {
        $this->manager = $manager;
        $this->logger  = $logger;
    }

    public function register_routes(): void {
        $ns = WTP_NAMESPACE;

        // Task collection
        register_rest_route( $ns, '/tasks', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'list_tasks' ],
                'permission_callback' => [ $this, 'check_read_permission' ],
                'args'                => $this->list_args(),
            ],
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'create_task' ],
                'permission_callback' => [ $this, 'check_write_permission' ],
                'args'                => $this->create_args(),
            ],
        ] );

        // Single task
        register_rest_route( $ns, '/tasks/(?P<id>[a-f0-9\-]{36})', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_task' ],
            'permission_callback' => [ $this, 'check_read_permission' ],
        ] );

        // Task logs
        register_rest_route( $ns, '/tasks/(?P<id>[a-f0-9\-]{36})/logs', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_task_logs' ],
            'permission_callback' => [ $this, 'check_read_permission' ],
        ] );

        // Manual retry
        register_rest_route( $ns, '/tasks/(?P<id>[a-f0-9\-]{36})/retry', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'retry_task' ],
            'permission_callback' => [ $this, 'check_write_permission' ],
        ] );

        // Queue stats
        register_rest_route( $ns, '/stats', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_stats' ],
            'permission_callback' => [ $this, 'check_read_permission' ],
        ] );

        // Server-Sent Events stream (real-time updates without WebSocket)
        register_rest_route( $ns, '/events', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'sse_stream' ],
            'permission_callback' => [ $this, 'check_read_permission' ],
        ] );
    }

    // ── Handlers ──────────────────────────────────────────────────────────────

    /**
     * POST /tasks
     */
    public function create_task( WP_REST_Request $request ): WP_REST_Response {
        $params = $request->get_json_params() ?: $request->get_params();

        // Support X-Idempotency-Key header
        $idem_header = $request->get_header( 'X-Idempotency-Key' );
        if ( $idem_header ) {
            $params['idempotency_key'] = sanitize_text_field( $idem_header );
        }

        $result = $this->manager->create( $params );

        if ( is_wp_error( $result ) ) {
            return new WP_REST_Response(
                [ 'error' => $result->get_error_message(), 'code' => $result->get_error_code() ],
                (int) ( $result->get_error_data()['status'] ?? 400 )
            );
        }

        // Kick off async processing immediately
        $queue = WP_Task_Processor::instance()->queue;
        $queue->dispatch( $result );

        return new WP_REST_Response( $result->to_api_response(), 201 );
    }

    /**
     * GET /tasks
     */
    public function list_tasks( WP_REST_Request $request ): WP_REST_Response {
        $args   = [
            'status'   => $request->get_param( 'status' ),
            'type'     => $request->get_param( 'type' ),
            'per_page' => (int) ( $request->get_param( 'per_page' ) ?? 20 ),
            'offset'   => ( (int) ( $request->get_param( 'page' ) ?? 1 ) - 1 )
                          * (int) ( $request->get_param( 'per_page' ) ?? 20 ),
        ];

        $data = $this->manager->list( $args );

        return new WP_REST_Response( [
            'tasks' => array_map( fn( $t ) => $t->to_api_response(), $data['tasks'] ),
            'total' => $data['total'],
            'page'  => (int) ( $request->get_param( 'page' ) ?? 1 ),
        ], 200 );
    }

    /**
     * GET /tasks/:id
     */
    public function get_task( WP_REST_Request $request ): WP_REST_Response {
        $task = $this->manager->get( $request->get_param( 'id' ) );

        if ( ! $task ) {
            return new WP_REST_Response( [ 'error' => 'Task not found.' ], 404 );
        }

        return new WP_REST_Response( $task->to_api_response(), 200 );
    }

    /**
     * GET /tasks/:id/logs
     */
    public function get_task_logs( WP_REST_Request $request ): WP_REST_Response {
        $task = $this->manager->get( $request->get_param( 'id' ) );
        if ( ! $task ) {
            return new WP_REST_Response( [ 'error' => 'Task not found.' ], 404 );
        }

        $logs = $this->logger->get_task_logs( $task->id );
        return new WP_REST_Response( [ 'logs' => $logs ], 200 );
    }

    /**
     * POST /tasks/:id/retry
     */
    public function retry_task( WP_REST_Request $request ): WP_REST_Response {
        $task = $this->manager->get( $request->get_param( 'id' ) );
        if ( ! $task ) {
            return new WP_REST_Response( [ 'error' => 'Task not found.' ], 404 );
        }

        if ( $task->status !== WTP_Task::STATUS_FAILED ) {
            return new WP_REST_Response( [ 'error' => 'Only failed tasks can be manually retried.' ], 422 );
        }

        // Reset attempts counter and re-queue
        $this->manager->update_status( $task->id, WTP_Task::STATUS_PENDING, [
            'attempts'      => 0,
            'error_message' => null,
            'scheduled_at'  => current_time( 'mysql' ),
        ] );

        $task->attempts = 0;
        WP_Task_Processor::instance()->queue->dispatch( $task );

        $updated = $this->manager->get( $task->id );
        return new WP_REST_Response( $updated ? $updated->to_api_response() : [], 200 );
    }

    /**
     * GET /stats
     */
    public function get_stats( WP_REST_Request $request ): WP_REST_Response {
        $counts    = $this->manager->count_by_status();
        $processor = WP_Task_Processor::instance();

        return new WP_REST_Response( [
            'counts'        => $counts,
            'redis_enabled' => $processor->cache->is_connected(),
            'ws_port'       => WTP_WS_PORT,
        ], 200 );
    }

    /**
     * GET /events — Server-Sent Events (real-time fallback for non-WS clients)
     */
    public function sse_stream( WP_REST_Request $request ): void {
        // Disable WP output buffering and timeouts
        if ( ob_get_level() ) {
            ob_end_clean();
        }
        set_time_limit( 0 );

        header( 'Content-Type: text/event-stream' );
        header( 'Cache-Control: no-cache' );
        header( 'X-Accel-Buffering: no' ); // Nginx: disable proxy buffering

        $task_id    = $request->get_param( 'task_id' );
        $last_check = current_time( 'mysql' );
        $ticks      = 0;
        $max_ticks  = 60; // close after ~60 s to avoid zombie connections

        echo "retry: 3000\n\n";
        flush();

        while ( $ticks < $max_ticks ) {
            sleep( 1 );
            $ticks++;

            global $wpdb;
            $table = ( new WTP_Database() )->tasks_table();

            $query  = $task_id
                ? $wpdb->prepare(
                    "SELECT * FROM {$table} WHERE task_id = %s AND updated_at > %s",
                    $task_id, $last_check
                )
                : $wpdb->prepare(
                    "SELECT * FROM {$table} WHERE updated_at > %s ORDER BY updated_at DESC LIMIT 10",
                    $last_check
                );

            $rows = $wpdb->get_results( $query );

            foreach ( (array) $rows as $row ) {
                $task = WTP_Task::from_row( $row );
                echo 'event: task_updated' . "\n";
                echo 'data: ' . wp_json_encode( $task->to_api_response() ) . "\n\n";
            }

            if ( ! empty( $rows ) ) {
                $last_check = current_time( 'mysql' );
            }

            // Heartbeat every 15 ticks
            if ( $ticks % 15 === 0 ) {
                echo ": heartbeat\n\n";
            }

            flush();

            if ( connection_aborted() ) {
                break;
            }
        }

        exit;
    }

    // ── Permission callbacks ──────────────────────────────────────────────────

    public function check_read_permission( WP_REST_Request $request ): bool {
        // Allow if user is logged in OR a valid API key is provided
        if ( is_user_logged_in() ) {
            return true;
        }
        return $this->verify_api_key( $request );
    }

    public function check_write_permission( WP_REST_Request $request ): bool {
        if ( current_user_can( 'manage_options' ) ) {
            return true;
        }
        return $this->verify_api_key( $request );
    }

    private function verify_api_key( WP_REST_Request $request ): bool {
        $settings = (array) get_option( 'wtp_settings', [] );
        $api_key  = $settings['api_key'] ?? '';

        if ( empty( $api_key ) ) {
            return false; // No key configured → deny unauthenticated
        }

        $provided = $request->get_header( 'X-WTP-API-Key' )
            ?? $request->get_param( 'api_key' )
            ?? '';

        return hash_equals( $api_key, $provided );
    }

    // ── Schema definitions ────────────────────────────────────────────────────

    private function create_args(): array {
        return [
            'type'            => [
                'required'          => true,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'description'       => 'Task type identifier (e.g. email, report, export)',
            ],
            'payload'         => [
                'required'          => false,
                'type'              => 'object',
                'description'       => 'Arbitrary task data',
            ],
            'idempotency_key' => [
                'required'          => false,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'description'       => 'Unique key to prevent duplicate task creation',
            ],
        ];
    }

    private function list_args(): array {
        return [
            'status'   => [
                'required' => false,
                'type'     => 'string',
                'enum'     => [ 'pending', 'processing', 'completed', 'failed' ],
            ],
            'type'     => [ 'required' => false, 'type' => 'string' ],
            'page'     => [ 'required' => false, 'type' => 'integer', 'default' => 1 ],
            'per_page' => [ 'required' => false, 'type' => 'integer', 'default' => 20 ],
        ];
    }
}
