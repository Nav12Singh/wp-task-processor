<?php
/**
 * Database schema management.
 *
 * @package WPTaskProcessor
 */

defined( 'ABSPATH' ) || exit;

class WTP_Database {

    const TASKS_TABLE = 'wtp_tasks';
    const LOGS_TABLE  = 'wtp_task_logs';
    const DB_VERSION  = '1.0.0';

    public function tasks_table(): string {
        global $wpdb;
        return $wpdb->prefix . self::TASKS_TABLE;
    }

    public function logs_table(): string {
        global $wpdb;
        return $wpdb->prefix . self::LOGS_TABLE;
    }

    /**
     * Install / upgrade database tables.
     */
    public function install(): void {
        global $wpdb;

        $charset = $wpdb->get_charset_collate();
        $tasks   = $this->tasks_table();
        $logs    = $this->logs_table();

        $sql_tasks = "CREATE TABLE {$tasks} (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            task_id         CHAR(36)        NOT NULL,
            type            VARCHAR(100)    NOT NULL,
            status          ENUM('pending','processing','completed','failed') NOT NULL DEFAULT 'pending',
            attempts        TINYINT UNSIGNED NOT NULL DEFAULT 0,
            max_attempts    TINYINT UNSIGNED NOT NULL DEFAULT 3,
            payload         LONGTEXT,
            result          LONGTEXT,
            idempotency_key VARCHAR(255)    NOT NULL,
            error_message   TEXT,
            created_at      DATETIME        NOT NULL,
            updated_at      DATETIME        NOT NULL,
            scheduled_at    DATETIME        NOT NULL,
            locked_until    DATETIME        DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY task_id          (task_id),
            UNIQUE KEY idempotency_key  (idempotency_key),
            KEY status_scheduled        (status, scheduled_at),
            KEY created_at_idx          (created_at)
        ) ENGINE=InnoDB {$charset};";

        $sql_logs = "CREATE TABLE {$logs} (
            id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            task_id    CHAR(36)        NOT NULL,
            level      ENUM('debug','info','warning','error') NOT NULL DEFAULT 'info',
            message    TEXT            NOT NULL,
            context    LONGTEXT,
            created_at DATETIME        NOT NULL,
            PRIMARY KEY (id),
            KEY task_id_idx    (task_id),
            KEY created_at_idx (created_at)
        ) ENGINE=InnoDB {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql_tasks );
        dbDelta( $sql_logs );

        update_option( 'wtp_db_version', self::DB_VERSION );
    }

    /**
     * Drop all plugin tables on uninstall.
     */
    public static function uninstall(): void {
        global $wpdb;
        $prefix = $wpdb->prefix;
        $wpdb->query( "DROP TABLE IF EXISTS {$prefix}" . self::TASKS_TABLE );
        $wpdb->query( "DROP TABLE IF EXISTS {$prefix}" . self::LOGS_TABLE );
        delete_option( 'wtp_db_version' );
        delete_option( 'wtp_settings' );
    }

    /**
     * Check and upgrade schema after plugin update.
     */
    public function maybe_upgrade(): void {
        $installed = get_option( 'wtp_db_version', '0.0.0' );
        if ( version_compare( $installed, self::DB_VERSION, '<' ) ) {
            $this->install();
        }
    }
}
