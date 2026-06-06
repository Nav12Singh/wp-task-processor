<?php
/**
 * CLI entry-point for the WTP WebSocket server.
 *
 * Usage (from WordPress root):
 *   php wp-content/plugins/wp-task-processor/bin/websocket-server.php
 *
 * Environment variables:
 *   WTP_WS_HOST  — bind address    (default: 0.0.0.0)
 *   WTP_WS_PORT  — listen port     (default: 8080)
 *   WP_ROOT      — override path to WordPress installation root
 *
 * @package WPTaskProcessor
 */

// ── Guard: CLI only ───────────────────────────────────────────────────────────
if ( php_sapi_name() !== 'cli' ) {
    http_response_code( 403 );
    exit( 'This script must be run from the command line.' );
}

// ── Locate wp-load.php ────────────────────────────────────────────────────────
// Directory layout: bin/ → wp-task-processor/ → plugins/ → wp-content/ → WP root
$wp_load = dirname( __DIR__, 4 ) . '/wp-load.php';

if ( getenv( 'WP_ROOT' ) ) {
    $wp_load = rtrim( getenv( 'WP_ROOT' ), '/' ) . '/wp-load.php';
}

if ( ! file_exists( $wp_load ) ) {
    // Fallback candidates (e.g. when running inside Docker)
    foreach ( [ '/var/www/html/wp-load.php', dirname( __DIR__, 3 ) . '/wp-load.php' ] as $candidate ) {
        if ( file_exists( $candidate ) ) {
            $wp_load = $candidate;
            break;
        }
    }
}

if ( ! file_exists( $wp_load ) ) {
    fwrite( STDERR, "ERROR: Cannot locate wp-load.php.\n" );
    fwrite( STDERR, "  Set the WP_ROOT environment variable to your WordPress root directory.\n" );
    fwrite( STDERR, "  Example: WP_ROOT=/var/www/html php bin/websocket-server.php\n" );
    exit( 1 );
}

// ── Bootstrap WordPress (no theme, no front-end output) ───────────────────────
define( 'WP_USE_THEMES', false );
require_once $wp_load;

// ── Resolve host / port (env → WP constant → default) ────────────────────────
$host = getenv( 'WTP_WS_HOST' ) ?: '0.0.0.0';
$port = (int) ( getenv( 'WTP_WS_PORT' )
    ?: ( defined( 'WTP_WS_PORT' ) ? WTP_WS_PORT : 8080 ) );

// ── Signal handlers (graceful shutdown) ──────────────────────────────────────
if ( function_exists( 'pcntl_signal' ) ) {
    $server_ref = null; // Set after construction below

    pcntl_signal( SIGTERM, function () use ( &$server_ref ) {
        echo "\n[WTP] SIGTERM received — shutting down…\n";
        if ( $server_ref ) {
            $server_ref->stop();
        }
        exit( 0 );
    } );

    pcntl_signal( SIGINT, function () use ( &$server_ref ) {
        echo "\n[WTP] SIGINT received — shutting down…\n";
        if ( $server_ref ) {
            $server_ref->stop();
        }
        exit( 0 );
    } );

    pcntl_async_signals( true );
}

// ── Start the server ──────────────────────────────────────────────────────────
echo "[WTP] Starting WebSocket server on ws://{$host}:{$port}" . PHP_EOL;

try {
    $server     = new WTP_WebSocket_Server( $host, $port );
    $server_ref = $server; // Let signal handlers reach it
    $server->start();
} catch ( \RuntimeException $e ) {
    fwrite( STDERR, "ERROR: " . $e->getMessage() . "\n" );
    exit( 1 );
}
