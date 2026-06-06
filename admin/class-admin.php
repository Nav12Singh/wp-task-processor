<?php
/**
 * WordPress Admin panel: dashboard + settings.
 *
 * @package WPTaskProcessor
 */

defined( 'ABSPATH' ) || exit;

class WTP_Admin {

    /** @var WTP_Task_Manager */
    private $manager;

    /** @var WTP_Logger */
    private $logger;

    public function __construct( WTP_Task_Manager $manager, WTP_Logger $logger ) {
        $this->manager = $manager;
        $this->logger  = $logger;

        add_action( 'admin_menu',           [ $this, 'register_menus' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'admin_init',           [ $this, 'register_settings' ] );
        add_action( 'wp_ajax_wtp_get_tasks', [ $this, 'ajax_get_tasks' ] );
    }

    // ── Menus ─────────────────────────────────────────────────────────────────

    public function register_menus(): void {
        add_menu_page(
            __( 'Task Processor', 'wp-task-processor' ),
            __( 'Task Processor', 'wp-task-processor' ),
            'manage_options',
            'wtp-dashboard',
            [ $this, 'render_dashboard' ],
            'dashicons-list-view',
            30
        );

        add_submenu_page(
            'wtp-dashboard',
            __( 'Dashboard', 'wp-task-processor' ),
            __( 'Dashboard', 'wp-task-processor' ),
            'manage_options',
            'wtp-dashboard',
            [ $this, 'render_dashboard' ]
        );

        add_submenu_page(
            'wtp-dashboard',
            __( 'Settings', 'wp-task-processor' ),
            __( 'Settings', 'wp-task-processor' ),
            'manage_options',
            'wtp-settings',
            [ $this, 'render_settings' ]
        );

        add_submenu_page(
            'wtp-dashboard',
            __( 'API Details', 'wp-task-processor' ),
            __( 'API Details', 'wp-task-processor' ),
            'manage_options',
            'wtp-api-details',
            [ $this, 'render_api_details' ]
        );

        add_submenu_page(
            'wtp-dashboard',
            __( 'Logs', 'wp-task-processor' ),
            __( 'Logs', 'wp-task-processor' ),
            'manage_options',
            'wtp-logs',
            [ $this, 'render_logs' ]
        );
    }

    // ── Assets ────────────────────────────────────────────────────────────────

    public function enqueue_assets( string $hook ): void {
        if ( strpos( $hook, 'wtp-' ) === false ) {
            return;
        }

        wp_enqueue_style(
            'wtp-admin',
            WTP_PLUGIN_URL . 'assets/css/admin.css',
            [],
            WTP_VERSION
        );

        wp_enqueue_script(
            'wtp-dashboard',
            WTP_PLUGIN_URL . 'assets/js/task-dashboard.js',
            [ 'wp-api-fetch' ],
            WTP_VERSION,
            true
        );

        $settings = (array) get_option( 'wtp_settings', [] );

        wp_localize_script( 'wtp-dashboard', 'WTP', [
            'root'       => esc_url_raw( rest_url() ),
            'nonce'      => wp_create_nonce( 'wp_rest' ),
            'ws_url'     => 'ws://' . ( $_SERVER['HTTP_HOST'] ?? 'localhost' ) . ':' . WTP_WS_PORT,
            'sse_url'    => rest_url( WTP_NAMESPACE . '/events' ),
            'ns'         => WTP_NAMESPACE,
            'ajaxurl'    => admin_url( 'admin-ajax.php' ),
            'ajax_nonce' => wp_create_nonce( 'wtp_ajax' ),
            'api_base'   => esc_url_raw( rest_url( WTP_NAMESPACE ) ),
            'api_key'    => $settings['api_key'] ?? '',
        ] );
    }

    // ── Settings ──────────────────────────────────────────────────────────────

    public function register_settings(): void {
        register_setting( 'wtp_settings_group', 'wtp_settings', [
            'type'              => 'array',
            'sanitize_callback' => [ $this, 'sanitize_settings' ],
        ] );
    }

    public function sanitize_settings( $input ): array {
        return [
            'redis_host'     => sanitize_text_field( $input['redis_host']     ?? '127.0.0.1' ),
            'redis_port'     => (int) ( $input['redis_port']     ?? 6379 ),
            'redis_password' => sanitize_text_field( $input['redis_password'] ?? '' ),
            'redis_db'       => (int) ( $input['redis_db']       ?? 0 ),
            'api_key'        => sanitize_text_field( $input['api_key']        ?? '' ),
            'ws_port'        => (int) ( $input['ws_port']        ?? WTP_WS_PORT ),
        ];
    }

    // ── Views ─────────────────────────────────────────────────────────────────

    public function render_dashboard(): void {
        $counts   = $this->manager->count_by_status();
        $instance = WP_Task_Processor::instance();
        require WTP_PLUGIN_DIR . 'admin/views/dashboard.php';
    }

    public function render_settings(): void {
        $settings = (array) get_option( 'wtp_settings', [] );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Task Processor — Settings', 'wp-task-processor' ); ?></h1>

            <nav class="nav-tab-wrapper wtp-settings-tabs">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wtp-settings' ) ); ?>"
                   class="nav-tab nav-tab-active"><?php esc_html_e( 'General', 'wp-task-processor' ); ?></a>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wtp-api-details' ) ); ?>"
                   class="nav-tab"><?php esc_html_e( 'API Details', 'wp-task-processor' ); ?></a>
            </nav>

            <form method="post" action="options.php">
                <?php settings_fields( 'wtp_settings_group' ); ?>
                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e( 'Redis Host', 'wp-task-processor' ); ?></th>
                        <td><input type="text" name="wtp_settings[redis_host]" value="<?php echo esc_attr( $settings['redis_host'] ?? '127.0.0.1' ); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Redis Port', 'wp-task-processor' ); ?></th>
                        <td><input type="number" name="wtp_settings[redis_port]" value="<?php echo esc_attr( $settings['redis_port'] ?? 6379 ); ?>" class="small-text"></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Redis Password', 'wp-task-processor' ); ?></th>
                        <td><input type="password" name="wtp_settings[redis_password]" value="<?php echo esc_attr( $settings['redis_password'] ?? '' ); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Redis DB Index', 'wp-task-processor' ); ?></th>
                        <td><input type="number" name="wtp_settings[redis_db]" value="<?php echo esc_attr( $settings['redis_db'] ?? 0 ); ?>" class="small-text"></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'REST API Key', 'wp-task-processor' ); ?></th>
                        <td>
                            <input type="text" name="wtp_settings[api_key]" value="<?php echo esc_attr( $settings['api_key'] ?? '' ); ?>" class="regular-text">
                            <p class="description"><?php esc_html_e( 'Sent as X-WTP-API-Key header. Auto-generated on activation — see API Details tab to copy it.', 'wp-task-processor' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'WebSocket Port', 'wp-task-processor' ); ?></th>
                        <td>
                            <input type="number" name="wtp_settings[ws_port]" value="<?php echo esc_attr( $settings['ws_port'] ?? WTP_WS_PORT ); ?>" class="small-text">
                            <p class="description"><?php echo esc_html( sprintf( __( 'Run: php %s', 'wp-task-processor' ), WTP_PLUGIN_DIR . 'bin/websocket-server.php' ) ); ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    public function render_api_details(): void {
        $settings = (array) get_option( 'wtp_settings', [] );
        $api_key  = $settings['api_key'] ?? '';
        $api_base = rest_url( WTP_NAMESPACE );
        require WTP_PLUGIN_DIR . 'admin/views/api-details.php';
    }

    public function render_logs(): void {
        $logs = $this->logger->get_recent_logs( 200 );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Task Processor — Logs', 'wp-task-processor' ); ?></h1>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Time', 'wp-task-processor' ); ?></th>
                        <th><?php esc_html_e( 'Level', 'wp-task-processor' ); ?></th>
                        <th><?php esc_html_e( 'Task ID', 'wp-task-processor' ); ?></th>
                        <th><?php esc_html_e( 'Message', 'wp-task-processor' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $logs as $log ) : ?>
                    <tr>
                        <td><?php echo esc_html( $log->created_at ); ?></td>
                        <td><span class="wtp-badge wtp-badge--<?php echo esc_attr( $log->level ); ?>"><?php echo esc_html( strtoupper( $log->level ) ); ?></span></td>
                        <td><code><?php echo esc_html( substr( $log->task_id, 0, 8 ) ); ?>…</code></td>
                        <td><?php echo esc_html( $log->message ); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    // ── AJAX ──────────────────────────────────────────────────────────────────

    public function ajax_get_tasks(): void {
        check_ajax_referer( 'wtp_ajax', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Forbidden', 403 );
        }

        $data = $this->manager->list( [
            'status'   => sanitize_text_field( $_GET['status'] ?? '' ),
            'per_page' => 20,
            'offset'   => 0,
        ] );

        wp_send_json_success( [
            'tasks' => array_map( fn( $t ) => $t->to_api_response(), $data['tasks'] ),
            'total' => $data['total'],
        ] );
    }
}
