<?php
/**
 * PSR-3 inspired logger — writes to DB log table + WP debug log.
 *
 * @package WPTaskProcessor
 */

defined( 'ABSPATH' ) || exit;

class WTP_Logger {

    const LEVEL_DEBUG   = 'debug';
    const LEVEL_INFO    = 'info';
    const LEVEL_WARNING = 'warning';
    const LEVEL_ERROR   = 'error';

    private $db;

    public function __construct() {
        $this->db = new WTP_Database();
    }

    // ── PSR-3 convenience methods ─────────────────────────────────────────────

    public function debug( string $message, array $context = [], string $task_id = '' ): void {
        $this->log( self::LEVEL_DEBUG, $message, $context, $task_id );
    }

    public function info( string $message, array $context = [], string $task_id = '' ): void {
        $this->log( self::LEVEL_INFO, $message, $context, $task_id );
    }

    public function warning( string $message, array $context = [], string $task_id = '' ): void {
        $this->log( self::LEVEL_WARNING, $message, $context, $task_id );
    }

    public function error( string $message, array $context = [], string $task_id = '' ): void {
        $this->log( self::LEVEL_ERROR, $message, $context, $task_id );
    }

    /**
     * Core log writer.
     */
    public function log( string $level, string $message, array $context = [], string $task_id = '' ): void {
        global $wpdb;

        $wpdb->insert(
            $this->db->logs_table(),
            [
                'task_id'    => $task_id ?: 'system',
                'level'      => $level,
                'message'    => $message,
                'context'    => ! empty( $context ) ? wp_json_encode( $context ) : null,
                'created_at' => current_time( 'mysql' ),
            ],
            [ '%s', '%s', '%s', '%s', '%s' ]
        );

        // Mirror to WP_DEBUG_LOG when debugging is on
        if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
            error_log( sprintf( '[WTP][%s][%s] %s %s', strtoupper( $level ), $task_id ?: 'system', $message, $context ? wp_json_encode( $context ) : '' ) );
        }
    }

    /**
     * Fetch logs for a specific task.
     *
     * @return array<object>
     */
    public function get_task_logs( string $task_id, int $limit = 50 ): array {
        global $wpdb;

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$this->db->logs_table()} WHERE task_id = %s ORDER BY created_at DESC LIMIT %d",
            $task_id,
            $limit
        ) );
    }

    /**
     * Fetch recent system-wide logs.
     *
     * @return array<object>
     */
    public function get_recent_logs( int $limit = 100 ): array {
        global $wpdb;

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$this->db->logs_table()} ORDER BY created_at DESC LIMIT %d",
            $limit
        ) );
    }

    /**
     * Prune logs older than $days days.
     */
    public function prune( int $days = 30 ): int {
        global $wpdb;

        return (int) $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$this->db->logs_table()} WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days
        ) );
    }
}
