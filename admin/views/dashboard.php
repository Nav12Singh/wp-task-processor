<?php
/**
 * Admin dashboard view.
 *
 * Variables available: $counts (array), $instance (WP_Task_Processor)
 *
 * @package WPTaskProcessor
 */

defined( 'ABSPATH' ) || exit;

$settings = (array) get_option( 'wtp_settings', [] );
$api_key  = $settings['api_key'] ?? '';
$api_base = rest_url( WTP_NAMESPACE );
?>
<div class="wrap wtp-dashboard">
    <h1 class="wtp-header">
        <span class="dashicons dashicons-list-view"></span>
        <?php esc_html_e( 'WP Task Processor', 'wp-task-processor' ); ?>
        <span class="wtp-version">v<?php echo esc_html( WTP_VERSION ); ?></span>
    </h1>

    <!-- Stats bar -->
    <div class="wtp-stats-bar">
        <div class="wtp-stat wtp-stat--pending">
            <span class="wtp-stat__count" id="stat-pending"><?php echo esc_html( $counts['pending'] ); ?></span>
            <span class="wtp-stat__label"><?php esc_html_e( 'Pending', 'wp-task-processor' ); ?></span>
        </div>
        <div class="wtp-stat wtp-stat--processing">
            <span class="wtp-stat__count" id="stat-processing"><?php echo esc_html( $counts['processing'] ); ?></span>
            <span class="wtp-stat__label"><?php esc_html_e( 'Processing', 'wp-task-processor' ); ?></span>
        </div>
        <div class="wtp-stat wtp-stat--completed">
            <span class="wtp-stat__count" id="stat-completed"><?php echo esc_html( $counts['completed'] ); ?></span>
            <span class="wtp-stat__label"><?php esc_html_e( 'Completed', 'wp-task-processor' ); ?></span>
        </div>
        <div class="wtp-stat wtp-stat--failed">
            <span class="wtp-stat__count" id="stat-failed"><?php echo esc_html( $counts['failed'] ); ?></span>
            <span class="wtp-stat__label"><?php esc_html_e( 'Failed', 'wp-task-processor' ); ?></span>
        </div>
        <div class="wtp-stat wtp-stat--redis">
            <span class="wtp-stat__count"><?php echo $instance->cache->is_connected() ? '<span class="wtp-dot wtp-dot--green"></span> ON' : '<span class="wtp-dot wtp-dot--red"></span> OFF'; ?></span>
            <span class="wtp-stat__label"><?php esc_html_e( 'Redis', 'wp-task-processor' ); ?></span>
        </div>
    </div>

    <!-- Connection status -->
    <div class="wtp-connection-bar">
        <span id="wtp-ws-status" class="wtp-badge wtp-badge--connecting"><?php esc_html_e( 'Connecting…', 'wp-task-processor' ); ?></span>
        <span class="wtp-connection-label"><?php esc_html_e( 'Real-time connection', 'wp-task-processor' ); ?></span>
    </div>

    <!-- ── Create task form ─────────────────────────────────────────────── -->
    <div class="wtp-card">
        <h2><?php esc_html_e( 'Create Test Task', 'wp-task-processor' ); ?></h2>
        <form id="wtp-create-form">
            <div class="wtp-form-row">
                <label><?php esc_html_e( 'Task Type', 'wp-task-processor' ); ?></label>
                <select id="wtp-task-type" name="type">
                    <option value="email">email</option>
                    <option value="report">report</option>
                    <option value="export">export</option>
                    <option value="import">import</option>
                    <option value="sync">sync</option>
                    <option value="cleanup">cleanup</option>
                </select>
            </div>
            <div class="wtp-form-row">
                <label><?php esc_html_e( 'Idempotency Key', 'wp-task-processor' ); ?></label>
                <input type="text" id="wtp-idem-key" placeholder="<?php esc_attr_e( 'Leave empty to auto-generate', 'wp-task-processor' ); ?>">
            </div>
            <div class="wtp-form-row">
                <label>
                    <input type="checkbox" id="wtp-force-fail">
                    <?php esc_html_e( 'Force failure (test retry logic)', 'wp-task-processor' ); ?>
                </label>
            </div>
            <button type="submit" class="button button-primary"><?php esc_html_e( 'Submit Task', 'wp-task-processor' ); ?></button>
            <span id="wtp-create-msg" class="wtp-msg"></span>
        </form>
    </div>

    <!-- ── Task list ────────────────────────────────────────────────────── -->
    <div class="wtp-card">
        <h2>
            <?php esc_html_e( 'Tasks', 'wp-task-processor' ); ?>
            <div class="wtp-filter-bar">
                <button class="button wtp-filter-btn active" data-status=""><?php esc_html_e( 'All', 'wp-task-processor' ); ?></button>
                <button class="button wtp-filter-btn" data-status="pending"><?php esc_html_e( 'Pending', 'wp-task-processor' ); ?></button>
                <button class="button wtp-filter-btn" data-status="processing"><?php esc_html_e( 'Processing', 'wp-task-processor' ); ?></button>
                <button class="button wtp-filter-btn" data-status="completed"><?php esc_html_e( 'Completed', 'wp-task-processor' ); ?></button>
                <button class="button wtp-filter-btn" data-status="failed"><?php esc_html_e( 'Failed', 'wp-task-processor' ); ?></button>
                <button class="button" id="wtp-refresh"><?php esc_html_e( 'Refresh', 'wp-task-processor' ); ?></button>
            </div>
        </h2>
        <table class="wp-list-table widefat fixed striped" id="wtp-task-table">
            <thead>
                <tr>
                    <th width="280"><?php esc_html_e( 'Task ID', 'wp-task-processor' ); ?></th>
                    <th><?php esc_html_e( 'Type', 'wp-task-processor' ); ?></th>
                    <th><?php esc_html_e( 'Status', 'wp-task-processor' ); ?></th>
                    <th><?php esc_html_e( 'Attempts', 'wp-task-processor' ); ?></th>
                    <th><?php esc_html_e( 'Created', 'wp-task-processor' ); ?></th>
                    <th><?php esc_html_e( 'Result', 'wp-task-processor' ); ?></th>
                    <th><?php esc_html_e( 'Actions', 'wp-task-processor' ); ?></th>
                </tr>
            </thead>
            <tbody id="wtp-task-tbody">
                <tr><td colspan="7"><?php esc_html_e( 'Loading…', 'wp-task-processor' ); ?></td></tr>
            </tbody>
        </table>
        <div id="wtp-pagination" class="wtp-pagination"></div>
    </div>

    <!-- ── Live events log ─────────────────────────────────────────────── -->
    <div class="wtp-card">
        <h2>
            <?php esc_html_e( 'Live Event Stream', 'wp-task-processor' ); ?>
            <button class="button" id="wtp-clear-events"><?php esc_html_e( 'Clear', 'wp-task-processor' ); ?></button>
        </h2>
        <div id="wtp-event-log" class="wtp-event-log"></div>
    </div>
</div>
