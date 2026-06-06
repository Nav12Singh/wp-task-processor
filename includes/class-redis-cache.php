<?php
/**
 * Redis caching layer with graceful fallback to WordPress transients.
 *
 * Priority: phpredis extension → Predis (via Composer) → WP transients.
 *
 * @package WPTaskProcessor
 */

defined( 'ABSPATH' ) || exit;

class WTP_Redis_Cache {

    /** @var \Redis|\Predis\Client|null */
    private $redis = null;

    /** @var bool */
    private $connected = false;

    /** @var string */
    private $key_prefix = 'wtp:';

    public function __construct() {
        $this->connect();
    }

    // ── Connection ────────────────────────────────────────────────────────────

    private function connect(): void {
        $settings = $this->get_settings();

        // Admin-saved settings take priority; fall back to Docker/env constants, then defaults.
        $host     = $settings['redis_host']     ?? ( defined( 'WTP_REDIS_HOST' ) ? WTP_REDIS_HOST : '127.0.0.1' );
        $port     = (int) ( $settings['redis_port'] ?? ( defined( 'WTP_REDIS_PORT' ) ? WTP_REDIS_PORT : 6379 ) );
        $password = $settings['redis_password'] ?? ( defined( 'WTP_REDIS_PASSWORD' ) ? WTP_REDIS_PASSWORD : '' );
        $db       = (int) ( $settings['redis_db'] ?? 0 );

        // Try phpredis first (faster)
        if ( extension_loaded( 'redis' ) ) {
            try {
                $this->redis = new Redis();
                $this->redis->connect( $host, $port, 2.0 );
                if ( $password ) {
                    $this->redis->auth( $password );
                }
                $this->redis->select( $db );
                $this->redis->ping();
                $this->connected = true;
                return;
            } catch ( \Exception $e ) {
                $this->redis     = null;
                $this->connected = false;
            }
        }

        // Try Predis (composer)
        if ( class_exists( '\Predis\Client' ) ) {
            try {
                $this->redis = new \Predis\Client( [
                    'scheme'   => 'tcp',
                    'host'     => $host,
                    'port'     => $port,
                    'password' => $password ?: null,
                    'database' => $db,
                    'read_write_timeout' => 2,
                ] );
                $this->redis->ping();
                $this->connected = true;
            } catch ( \Exception $e ) {
                $this->redis     = null;
                $this->connected = false;
            }
        }
    }

    public function is_connected(): bool {
        return $this->connected;
    }

    // ── Core cache operations ─────────────────────────────────────────────────

    /**
     * Store a value. TTL defaults to WTP_CACHE_TTL (45 s).
     */
    public function set( string $key, $value, int $ttl = WTP_CACHE_TTL ): bool {
        $full_key = $this->key_prefix . $key;
        $encoded  = wp_json_encode( $value );

        if ( $this->connected && $this->redis ) {
            try {
                return (bool) $this->redis->setex( $full_key, $ttl, $encoded );
            } catch ( \Exception $e ) {
                $this->connected = false;
            }
        }

        // Fallback: WP transients
        return set_transient( 'wtp_' . md5( $key ), $encoded, $ttl );
    }

    /**
     * Retrieve a value. Returns null on miss.
     */
    public function get( string $key ) {
        $full_key = $this->key_prefix . $key;

        if ( $this->connected && $this->redis ) {
            try {
                $raw = $this->redis->get( $full_key );
                if ( false === $raw || null === $raw ) {
                    return null;
                }
                return json_decode( $raw, true );
            } catch ( \Exception $e ) {
                $this->connected = false;
            }
        }

        // Fallback
        $raw = get_transient( 'wtp_' . md5( $key ) );
        return false !== $raw ? json_decode( $raw, true ) : null;
    }

    /**
     * Delete a cached value.
     */
    public function delete( string $key ): bool {
        $full_key = $this->key_prefix . $key;

        if ( $this->connected && $this->redis ) {
            try {
                $this->redis->del( $full_key );
                return true;
            } catch ( \Exception $e ) {
                $this->connected = false;
            }
        }

        return delete_transient( 'wtp_' . md5( $key ) );
    }

    // ── Task-specific helpers ─────────────────────────────────────────────────

    /**
     * Cache a task's full data.
     */
    public function set_task( WTP_Task $task, int $ttl = WTP_CACHE_TTL ): bool {
        return $this->set( 'task:' . $task->id, $task->to_array(), $ttl );
    }

    /**
     * Retrieve a cached task. Returns null on miss.
     */
    public function get_task( string $task_id ): ?array {
        return $this->get( 'task:' . $task_id );
    }

    /**
     * Invalidate the cache for a specific task.
     */
    public function invalidate_task( string $task_id ): bool {
        return $this->delete( 'task:' . $task_id );
    }

    // ── Redis Pub/Sub for WebSocket notifications ─────────────────────────────

    /**
     * Publish a task-update event so WebSocket server can broadcast it.
     */
    public function publish_task_event( WTP_Task $task ): void {
        if ( ! $this->connected || ! $this->redis ) {
            return;
        }

        try {
            $payload = wp_json_encode( [
                'event'  => 'task_updated',
                'task'   => $task->to_api_response(),
            ] );
            $this->redis->publish( 'wtp:task_events', $payload );
        } catch ( \Exception $e ) {
            // Pub/sub is a nice-to-have — fail silently
        }
    }

    // ── Settings ──────────────────────────────────────────────────────────────

    private function get_settings(): array {
        return (array) get_option( 'wtp_settings', [] );
    }
}
