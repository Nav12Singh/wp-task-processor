<?php
/**
 * Plugin Name:       WP Task Processor
 * Plugin URI:        https://github.com/wpswings/wp-task-processor
 * Description:       Real-Time Task Processing System — async queue, retry logic, Redis caching, WebSockets, BullMQ-style job processing.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            WP Swings
 * Author URI:        https://wpswings.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-task-processor
 * Domain Path:       /languages
 *
 * @package WPTaskProcessor
 */

defined( 'ABSPATH' ) || exit;

// ── Constants ────────────────────────────────────────────────────────────────
define( 'WTP_VERSION',      '1.0.0' );
define( 'WTP_PLUGIN_FILE',  __FILE__ );
define( 'WTP_PLUGIN_DIR',   plugin_dir_path( __FILE__ ) );
define( 'WTP_PLUGIN_URL',   plugin_dir_url( __FILE__ ) );
define( 'WTP_MAX_ATTEMPTS', 3 );
define( 'WTP_FAILURE_RATE', 0.30 );  // 30 % simulated failure
define( 'WTP_CACHE_TTL',    45 );    // Redis TTL in seconds (30–60 range)
define( 'WTP_WS_PORT',      8080 );  // Default WebSocket port
define( 'WTP_NAMESPACE',    'wtp/v1' );

// ── Include all classes ───────────────────────────────────────────────────────
$wtp_includes = [
    'includes/class-database.php',
    'includes/class-task.php',
    'includes/class-logger.php',
    'includes/class-redis-cache.php',
    'includes/class-task-manager.php',
    'includes/class-task-queue.php',
    'includes/class-task-processor.php',
    'includes/class-rest-api.php',
    'includes/class-websocket-server.php',
    'admin/class-admin.php',
];

foreach ( $wtp_includes as $file ) {
    require_once WTP_PLUGIN_DIR . $file;
}

// ── Main plugin singleton ─────────────────────────────────────────────────────
final class WP_Task_Processor {

    /** @var self|null */
    private static $instance = null;

    /** @var WTP_Database */
    public $db;

    /** @var WTP_Logger */
    public $logger;

    /** @var WTP_Redis_Cache */
    public $cache;

    /** @var WTP_Task_Manager */
    public $task_manager;

    /** @var WTP_Task_Queue */
    public $queue;

    /** @var WTP_Task_Processor */
    public $processor;

    private function __construct() {
        $this->db           = new WTP_Database();
        $this->logger       = new WTP_Logger();
        $this->cache        = new WTP_Redis_Cache();
        $this->task_manager = new WTP_Task_Manager( $this->db, $this->cache, $this->logger );
        $this->queue        = new WTP_Task_Queue( $this->db, $this->logger );
        $this->processor    = new WTP_Task_Processor( $this->task_manager, $this->queue, $this->logger );
    }

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function boot(): void {
        $this->db->maybe_upgrade();

        // REST API
        add_action( 'rest_api_init', function () {
            ( new WTP_Rest_API( $this->task_manager, $this->logger ) )->register_routes();
        } );

        // Admin panel
        if ( is_admin() ) {
            new WTP_Admin( $this->task_manager, $this->logger );
        }

        // WP-Cron hooks
        add_action( 'wtp_process_queue',  [ $this->processor, 'run' ] );
        add_action( 'wtp_retry_failed',   [ $this->processor, 'retry_failed_tasks' ] );

        // Register cron intervals
        add_filter( 'cron_schedules', [ $this, 'cron_intervals' ] );

        // Schedule recurring jobs on first boot
        $this->schedule_cron();
    }

    public function cron_intervals( array $schedules ): array {
        if ( ! isset( $schedules['every_minute'] ) ) {
            $schedules['every_minute'] = [
                'interval' => 60,
                'display'  => __( 'Every Minute', 'wp-task-processor' ),
            ];
        }
        if ( ! isset( $schedules['every_five_minutes'] ) ) {
            $schedules['every_five_minutes'] = [
                'interval' => 300,
                'display'  => __( 'Every 5 Minutes', 'wp-task-processor' ),
            ];
        }
        return $schedules;
    }

    private function schedule_cron(): void {
        if ( ! wp_next_scheduled( 'wtp_process_queue' ) ) {
            wp_schedule_event( time(), 'every_minute', 'wtp_process_queue' );
        }
        if ( ! wp_next_scheduled( 'wtp_retry_failed' ) ) {
            wp_schedule_event( time(), 'every_five_minutes', 'wtp_retry_failed' );
        }
    }
}

// ── Lifecycle hooks ───────────────────────────────────────────────────────────
register_activation_hook( __FILE__, function () {
    ( new WTP_Database() )->install();

    // Auto-generate an API key so the REST API works immediately on fresh installs.
    $settings = (array) get_option( 'wtp_settings', [] );
    if ( empty( $settings['api_key'] ) ) {
        $settings['api_key'] = wp_generate_password( 32, false );
        update_option( 'wtp_settings', $settings );
    }

    flush_rewrite_rules();
    do_action( 'wtp_activated' );
} );

register_deactivation_hook( __FILE__, function () {
    wp_clear_scheduled_hook( 'wtp_process_queue' );
    wp_clear_scheduled_hook( 'wtp_retry_failed' );
    flush_rewrite_rules();
    do_action( 'wtp_deactivated' );
} );

register_uninstall_hook( __FILE__, [ 'WTP_Database', 'uninstall' ] );

// ── Boot ──────────────────────────────────────────────────────────────────────
add_action( 'plugins_loaded', function () {
    WP_Task_Processor::instance()->boot();
} );
