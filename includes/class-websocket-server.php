<?php
/**
 * WebSocket server — pure PHP, zero external dependencies.
 *
 * Intended to be started as a long-running CLI process:
 *   php bin/websocket-server.php
 *
 * This class encapsulates the protocol logic so it can be used
 * independently of the CLI entry-point for testing.
 *
 * Protocol:
 *   Client → Server: { "action": "subscribe", "task_id": "<uuid>|all" }
 *   Server → Client: { "event": "task_updated", "task": { ... } }
 *   Server → Client: { "event": "heartbeat", "ts": "<timestamp>" }
 *
 * @package WPTaskProcessor
 */

defined( 'ABSPATH' ) || exit;

class WTP_WebSocket_Server {

    /** @var resource */
    private $socket;

    /** @var resource[] task_id => [ socket ] */
    private $clients = [];

    /** @var array socket_id => socket */
    private $all_clients = [];

    /** @var array socket_id => [ 'task_ids' => [...] ] */
    private $subscriptions = [];

    /** @var string */
    private $host;

    /** @var int */
    private $port;

    /** @var bool */
    private $running = false;

    // ── Redis pub/sub (forked subscriber process) ─────────────────────────────

    /** @var resource|null  Read end of the IPC pipe from the Redis subscriber child */
    private $redis_pipe = null;

    /** @var int  PID of the forked Redis subscriber, 0 when not forked */
    private $redis_child = 0;

    public function __construct( string $host = '0.0.0.0', int $port = WTP_WS_PORT ) {
        $this->host = $host;
        $this->port = $port;
    }

    // ── Lifecycle ─────────────────────────────────────────────────────────────

    public function start(): void {
        $this->socket = stream_socket_server(
            "tcp://{$this->host}:{$this->port}",
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN
        );

        if ( ! $this->socket ) {
            throw new \RuntimeException( "Cannot bind WebSocket server: {$errstr} ({$errno})" );
        }

        stream_set_blocking( $this->socket, false );

        $this->log( "WebSocket server listening on ws://{$this->host}:{$this->port}" );

        // Attempt to start a forked Redis pub/sub subscriber.
        // Falls back to DB polling automatically when unavailable.
        $this->init_redis_pubsub();

        $this->running = true;
        $this->loop();
    }

    public function stop(): void {
        $this->running = false;

        // Tear down the Redis subscriber child process
        if ( $this->redis_child > 0 ) {
            if ( function_exists( 'posix_kill' ) ) {
                posix_kill( $this->redis_child, 15 ); // SIGTERM
            }
            if ( function_exists( 'pcntl_waitpid' ) ) {
                pcntl_waitpid( $this->redis_child, $status, WNOHANG );
            }
            $this->redis_child = 0;
        }
        if ( $this->redis_pipe ) {
            @fclose( $this->redis_pipe );
            $this->redis_pipe = null;
        }

        foreach ( $this->all_clients as $client ) {
            @fclose( $client );
        }
        if ( $this->socket ) {
            @fclose( $this->socket );
        }
    }

    // ── Main event loop ───────────────────────────────────────────────────────

    private function loop(): void {
        $last_heartbeat     = time();
        $heartbeat_interval = 30;

        while ( $this->running ) {
            $read   = array_merge( [ $this->socket ], array_values( $this->all_clients ) );
            $write  = null;
            $except = null;

            // Include the Redis IPC pipe in the select set when available
            if ( $this->redis_pipe ) {
                $read[] = $this->redis_pipe;
            }

            $changed = @stream_select( $read, $write, $except, 1, 0 );

            if ( false === $changed ) {
                continue;
            }

            // ── Redis pipe messages ───────────────────────────────────────────
            if ( $this->redis_pipe && in_array( $this->redis_pipe, $read, true ) ) {
                $this->handle_redis_pipe();
                $key = array_search( $this->redis_pipe, $read, true );
                unset( $read[ $key ] );
            }

            // ── Accept new WebSocket connections ──────────────────────────────
            if ( in_array( $this->socket, $read, true ) ) {
                $this->accept_connection();
                $key = array_search( $this->socket, $read, true );
                unset( $read[ $key ] );
            }

            // ── Handle data from existing clients ─────────────────────────────
            foreach ( $read as $client ) {
                $data = @fread( $client, 65536 );

                if ( false === $data || '' === $data ) {
                    $this->disconnect( $client );
                    continue;
                }

                if ( $this->is_handshake( $data ) ) {
                    $this->do_handshake( $client, $data );
                } else {
                    $this->handle_frame( $client, $data );
                }
            }

            // ── Periodic heartbeat ────────────────────────────────────────────
            if ( time() - $last_heartbeat >= $heartbeat_interval ) {
                $this->broadcast_all( [
                    'event' => 'heartbeat',
                    'ts'    => gmdate( 'c' ),
                ] );
                $last_heartbeat = time();
            }

            // ── DB polling fallback (only when Redis pipe is unavailable) ─────
            if ( ! $this->redis_pipe ) {
                $this->poll_db_updates();
            }
        }
    }

    // ── Connection management ─────────────────────────────────────────────────

    private function accept_connection(): void {
        $client = @stream_socket_accept( $this->socket, 0 );
        if ( ! $client ) {
            return;
        }

        stream_set_blocking( $client, false );
        $id = (int) $client;
        $this->all_clients[ $id ]    = $client;
        $this->subscriptions[ $id ] = [ 'task_ids' => [], 'handshake' => false ];

        $this->log( "Client #{$id} connected" );
    }

    private function disconnect( $client ): void {
        $id = (int) $client;
        @fclose( $client );
        unset( $this->all_clients[ $id ], $this->subscriptions[ $id ] );
        $this->log( "Client #{$id} disconnected" );
    }

    // ── WebSocket handshake ───────────────────────────────────────────────────

    private function is_handshake( string $data ): bool {
        return strpos( $data, 'GET ' ) === 0 && strpos( $data, 'Upgrade: websocket' ) !== false;
    }

    private function do_handshake( $client, string $request ): void {
        preg_match( '/Sec-WebSocket-Key: (.+)\r\n/', $request, $matches );
        if ( empty( $matches[1] ) ) {
            $this->disconnect( $client );
            return;
        }

        $key    = trim( $matches[1] );
        $accept = base64_encode( sha1( $key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true ) );

        $response = implode( "\r\n", [
            'HTTP/1.1 101 Switching Protocols',
            'Upgrade: websocket',
            'Connection: Upgrade',
            "Sec-WebSocket-Accept: {$accept}",
            '',
            '',
        ] );

        fwrite( $client, $response );

        $id = (int) $client;
        $this->subscriptions[ $id ]['handshake'] = true;

        // Send welcome message
        $this->send( $client, [
            'event'   => 'connected',
            'message' => 'WP Task Processor WebSocket ready',
        ] );

        $this->log( "Handshake complete for client #{$id}" );
    }

    // ── Frame encoding/decoding ───────────────────────────────────────────────

    private function handle_frame( $client, string $data ): void {
        $id = (int) $client;
        if ( empty( $this->subscriptions[ $id ]['handshake'] ) ) {
            return;
        }

        $decoded = $this->decode_frame( $data );
        if ( null === $decoded ) {
            return;
        }

        $opcode = $decoded['opcode'];

        // Handle close frame
        if ( $opcode === 8 ) {
            $this->disconnect( $client );
            return;
        }

        // Handle ping
        if ( $opcode === 9 ) {
            $this->send_raw( $client, $this->encode_frame( $decoded['payload'], 10 ) ); // pong
            return;
        }

        // Text frame
        if ( $opcode === 1 ) {
            $message = json_decode( $decoded['payload'], true );
            if ( $message ) {
                $this->handle_message( $client, $message );
            }
        }
    }

    private function decode_frame( string $data ): ?array {
        if ( strlen( $data ) < 2 ) {
            return null;
        }

        $firstByte  = ord( $data[0] );
        $secondByte = ord( $data[1] );
        $opcode     = $firstByte & 0x0f;
        $masked     = ( $secondByte & 0x80 ) >> 7;
        $payloadLen = $secondByte & 0x7f;

        $offset = 2;

        if ( $payloadLen === 126 ) {
            $payloadLen = unpack( 'n', substr( $data, $offset, 2 ) )[1];
            $offset    += 2;
        } elseif ( $payloadLen === 127 ) {
            $payloadLen = unpack( 'J', substr( $data, $offset, 8 ) )[1];
            $offset    += 8;
        }

        $mask = '';
        if ( $masked ) {
            $mask   = substr( $data, $offset, 4 );
            $offset += 4;
        }

        $payload = substr( $data, $offset, $payloadLen );

        if ( $masked && $mask ) {
            $unmasked = '';
            for ( $i = 0; $i < strlen( $payload ); $i++ ) {
                $unmasked .= $payload[ $i ] ^ $mask[ $i % 4 ];
            }
            $payload = $unmasked;
        }

        return [ 'opcode' => $opcode, 'payload' => $payload ];
    }

    private function encode_frame( string $payload, int $opcode = 1 ): string {
        $frame      = chr( 0x80 | $opcode );
        $payloadLen = strlen( $payload );

        if ( $payloadLen <= 125 ) {
            $frame .= chr( $payloadLen );
        } elseif ( $payloadLen <= 65535 ) {
            $frame .= chr( 126 ) . pack( 'n', $payloadLen );
        } else {
            $frame .= chr( 127 ) . pack( 'J', $payloadLen );
        }

        return $frame . $payload;
    }

    // ── Message handling ──────────────────────────────────────────────────────

    private function handle_message( $client, array $message ): void {
        $action  = $message['action'] ?? '';
        $task_id = $message['task_id'] ?? 'all';
        $id      = (int) $client;

        if ( 'subscribe' === $action ) {
            if ( ! in_array( $task_id, $this->subscriptions[ $id ]['task_ids'], true ) ) {
                $this->subscriptions[ $id ]['task_ids'][] = $task_id;
            }

            $this->send( $client, [
                'event'   => 'subscribed',
                'task_id' => $task_id,
            ] );

            $this->log( "Client #{$id} subscribed to task_id={$task_id}" );
        }

        if ( 'unsubscribe' === $action ) {
            $this->subscriptions[ $id ]['task_ids'] = array_filter(
                $this->subscriptions[ $id ]['task_ids'],
                fn( $t ) => $t !== $task_id
            );
        }
    }

    // ── Sending ───────────────────────────────────────────────────────────────

    public function send( $client, array $data ): void {
        $this->send_raw( $client, $this->encode_frame( wp_json_encode( $data ) ) );
    }

    private function send_raw( $client, string $frame ): void {
        @fwrite( $client, $frame );
    }

    /**
     * Broadcast a task update to all subscribed clients.
     */
    public function broadcast_task( WTP_Task $task ): void {
        $payload = [
            'event' => 'task_updated',
            'task'  => $task->to_api_response(),
        ];

        foreach ( $this->all_clients as $id => $client ) {
            $subs = $this->subscriptions[ $id ]['task_ids'] ?? [];
            if ( in_array( 'all', $subs, true ) || in_array( $task->id, $subs, true ) ) {
                $this->send( $client, $payload );
            }
        }
    }

    private function broadcast_all( array $data ): void {
        $frame = $this->encode_frame( wp_json_encode( $data ) );
        foreach ( $this->all_clients as $client ) {
            $this->send_raw( $client, $frame );
        }
    }

    // ── Redis pub/sub subscriber (forked child process) ──────────────────────

    /**
     * Fork a child process that subscribes to the Redis wtp:task_events channel
     * and forwards each message to the parent via a Unix socket pair (pipe).
     *
     * Requires pcntl + phpredis extensions. When unavailable the server falls
     * back silently to DB polling via poll_db_updates().
     */
    private function init_redis_pubsub(): void {
        if ( ! function_exists( 'pcntl_fork' ) || ! extension_loaded( 'redis' ) ) {
            $this->log( 'Redis pub/sub unavailable (requires pcntl + phpredis) — using DB polling' );
            return;
        }

        $pair = stream_socket_pair( STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP );
        if ( ! $pair ) {
            $this->log( 'Could not create IPC socket pair — using DB polling' );
            return;
        }

        [ $parent_read, $child_write ] = $pair;

        $pid = pcntl_fork();

        if ( $pid === -1 ) {
            fclose( $parent_read );
            fclose( $child_write );
            $this->log( 'pcntl_fork() failed — using DB polling' );
            return;
        }

        if ( $pid === 0 ) {
            // ── Child process: blocking Redis subscriber ──────────────────────
            fclose( $parent_read );
            $this->run_redis_subscriber( $child_write );
            fclose( $child_write );
            exit( 0 );
        }

        // ── Parent process: keep the read end ─────────────────────────────────
        fclose( $child_write );
        stream_set_blocking( $parent_read, false );
        $this->redis_pipe  = $parent_read;
        $this->redis_child = $pid;
        $this->log( "Redis pub/sub subscriber started (child PID {$pid})" );
    }

    /**
     * Runs inside the forked child. Subscribes to Redis and writes each
     * event JSON to the IPC pipe so the parent can broadcast it.
     *
     * @param resource $write_pipe
     */
    private function run_redis_subscriber( $write_pipe ): void {
        try {
            $redis = new \Redis();

            // Resolve connection details the same way WTP_Redis_Cache does
            $settings = function_exists( 'get_option' )
                ? (array) get_option( 'wtp_settings', [] )
                : [];

            $host = $settings['redis_host'] ?? ( defined( 'WTP_REDIS_HOST' ) ? WTP_REDIS_HOST : '127.0.0.1' );
            $port = (int) ( $settings['redis_port'] ?? ( defined( 'WTP_REDIS_PORT' ) ? WTP_REDIS_PORT : 6379 ) );
            $pass = $settings['redis_password'] ?? ( defined( 'WTP_REDIS_PASSWORD' ) ? WTP_REDIS_PASSWORD : '' );

            $redis->connect( $host, $port, 5.0 );
            if ( $pass ) {
                $redis->auth( $pass );
            }

            $this->log( "Child: subscribed to Redis channel wtp:task_events on {$host}:{$port}" );

            $redis->subscribe( [ 'wtp:task_events' ], function ( $r, $channel, $message ) use ( $write_pipe ) {
                // Each message is a single JSON line; append newline as delimiter
                fwrite( $write_pipe, $message . "\n" );
                fflush( $write_pipe );
            } );
        } catch ( \Exception $e ) {
            $this->log( 'Redis subscriber child error: ' . $e->getMessage() );
            // Child exits — parent detects EOF on pipe and falls back to DB polling
        }
    }

    /**
     * Read all available lines from the Redis IPC pipe and broadcast any
     * task_updated events to subscribed WebSocket clients.
     */
    private function handle_redis_pipe(): void {
        if ( ! $this->redis_pipe ) {
            return;
        }

        $buffer = '';
        while ( true ) {
            $chunk = @fread( $this->redis_pipe, 8192 );
            if ( false === $chunk || '' === $chunk ) {
                if ( feof( $this->redis_pipe ) ) {
                    // Child died — close pipe and fall back to DB polling
                    @fclose( $this->redis_pipe );
                    $this->redis_pipe  = null;
                    $this->redis_child = 0;
                    $this->log( 'Redis subscriber pipe closed — falling back to DB polling' );
                }
                break;
            }
            $buffer .= $chunk;
        }

        if ( '' === $buffer ) {
            return;
        }

        foreach ( explode( "\n", trim( $buffer ) ) as $line ) {
            $line = trim( $line );
            if ( '' === $line ) {
                continue;
            }

            $data = json_decode( $line, true );
            if ( ! $data || ! isset( $data['task'] ) ) {
                continue;
            }

            // Reconstruct a minimal WTP_Task from the API-response payload
            $t    = $data['task'];
            $task = new WTP_Task( [
                'task_id'    => $t['id']       ?? '',
                'type'       => $t['type']     ?? '',
                'status'     => $t['status']   ?? '',
                'attempts'   => (int) ( $t['attempts'] ?? 0 ),
                'result'     => $t['result']   ?? null,
                'created_at' => $t['createdAt'] ?? '',
            ] );

            $this->broadcast_task( $task );
        }
    }

    // ── DB polling (fallback when Redis pub/sub is unavailable) ───────────────

    private $last_poll_time = null;

    private function poll_db_updates(): void {
        static $tick = 0;
        $tick++;

        // Poll every ~2 seconds (every 2 loop iterations at 1s select timeout)
        if ( $tick % 2 !== 0 || empty( $this->all_clients ) ) {
            return;
        }

        // Load WordPress DB only in CLI context where WP is bootstrapped
        if ( ! function_exists( 'get_option' ) ) {
            return;
        }

        global $wpdb;
        if ( ! $wpdb ) {
            return;
        }

        $since = $this->last_poll_time ?? gmdate( 'Y-m-d H:i:s', time() - 5 );
        $table = $wpdb->prefix . WTP_Database::TASKS_TABLE;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE updated_at > %s ORDER BY updated_at ASC LIMIT 20",
            $since
        ) );

        foreach ( (array) $rows as $row ) {
            $task = WTP_Task::from_row( $row );
            $this->broadcast_task( $task );
        }

        $this->last_poll_time = gmdate( 'Y-m-d H:i:s' );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function log( string $msg ): void {
        $ts = gmdate( 'Y-m-d H:i:s' );
        echo "[{$ts}] {$msg}" . PHP_EOL;
    }
}
