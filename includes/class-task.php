<?php
/**
 * Task entity / data-transfer object.
 *
 * @package WPTaskProcessor
 */

defined( 'ABSPATH' ) || exit;

class WTP_Task {

    // ── Status constants ──────────────────────────────────────────────────────
    const STATUS_PENDING    = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_COMPLETED  = 'completed';
    const STATUS_FAILED     = 'failed';

    // ── Properties ────────────────────────────────────────────────────────────
    /** @var string UUID v4 */
    public $id;

    /** @var string */
    public $type;

    /** @var string */
    public $status;

    /** @var int */
    public $attempts;

    /** @var int */
    public $max_attempts;

    /** @var array|null */
    public $payload;

    /** @var array|null */
    public $result;

    /** @var string */
    public $idempotency_key;

    /** @var string|null */
    public $error_message;

    /** @var string MySQL datetime */
    public $created_at;

    /** @var string MySQL datetime */
    public $updated_at;

    /** @var string MySQL datetime */
    public $scheduled_at;

    /** @var string|null MySQL datetime */
    public $locked_until;

    public function __construct( array $data = [] ) {
        $now = current_time( 'mysql' );

        $this->id              = $data['task_id'] ?? $data['id'] ?? self::generate_uuid();
        $this->type            = $data['type']           ?? '';
        $this->status          = $data['status']         ?? self::STATUS_PENDING;
        $this->attempts        = (int) ( $data['attempts']     ?? 0 );
        $this->max_attempts    = (int) ( $data['max_attempts']  ?? WTP_MAX_ATTEMPTS );
        $this->idempotency_key = $data['idempotency_key'] ?? '';
        $this->error_message   = $data['error_message']  ?? null;
        $this->created_at      = $data['created_at']     ?? $now;
        $this->updated_at      = $data['updated_at']     ?? $now;
        $this->scheduled_at    = $data['scheduled_at']   ?? $now;
        $this->locked_until    = $data['locked_until']   ?? null;

        // Decode JSON strings coming from DB rows
        $this->payload = isset( $data['payload'] )
            ? ( is_string( $data['payload'] ) ? json_decode( $data['payload'], true ) : $data['payload'] )
            : null;

        $this->result = isset( $data['result'] )
            ? ( is_string( $data['result'] ) ? json_decode( $data['result'], true ) : $data['result'] )
            : null;
    }

    /**
     * Build a WTP_Task from a raw stdClass DB row.
     */
    public static function from_row( $row ): self {
        return new self( (array) $row );
    }

    /**
     * Public representation returned by REST API.
     */
    public function to_api_response(): array {
        return [
            'id'        => $this->id,
            'type'      => $this->type,
            'status'    => $this->status,
            'attempts'  => $this->attempts,
            'result'    => $this->result,
            'createdAt' => $this->created_at,
        ];
    }

    /**
     * Full representation for admin / internal use.
     */
    public function to_array(): array {
        return [
            'id'              => $this->id,
            'type'            => $this->type,
            'status'          => $this->status,
            'attempts'        => $this->attempts,
            'max_attempts'    => $this->max_attempts,
            'payload'         => $this->payload,
            'result'          => $this->result,
            'idempotency_key' => $this->idempotency_key,
            'error_message'   => $this->error_message,
            'created_at'      => $this->created_at,
            'updated_at'      => $this->updated_at,
            'scheduled_at'    => $this->scheduled_at,
        ];
    }

    /** Can this task be retried? */
    public function can_retry(): bool {
        return $this->attempts < $this->max_attempts;
    }

    /** Is the task in a terminal state? */
    public function is_terminal(): bool {
        return in_array( $this->status, [ self::STATUS_COMPLETED, self::STATUS_FAILED ], true );
    }

    /** Generate a UUID v4. */
    public static function generate_uuid(): string {
        if ( function_exists( 'wp_generate_uuid4' ) ) {
            return wp_generate_uuid4();
        }
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ),
            mt_rand( 0, 0xffff ),
            mt_rand( 0, 0x0fff ) | 0x4000,
            mt_rand( 0, 0x3fff ) | 0x8000,
            mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff )
        );
    }
}
