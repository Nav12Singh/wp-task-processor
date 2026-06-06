<?php
/**
 * Extra WordPress configuration injected at container startup.
 * Reads environment variables set by docker-compose.
 */

// Redis settings
if ( getenv( 'WTP_REDIS_HOST' ) ) {
    define( 'WTP_REDIS_HOST', getenv( 'WTP_REDIS_HOST' ) );
    define( 'WTP_REDIS_PORT', (int) ( getenv( 'WTP_REDIS_PORT' ) ?: 6379 ) );
}

// Disable the built-in WP-Cron HTTP ping — the dedicated cron container
// runs `wp cron event run --due-now` every 60 s instead.
define( 'DISABLE_WP_CRON', true );

// Debug
define( 'WP_DEBUG',         (bool) getenv( 'WORDPRESS_DEBUG' ) );
define( 'WP_DEBUG_LOG',     true );
define( 'WP_DEBUG_DISPLAY', false );
define( 'SCRIPT_DEBUG',     false );
