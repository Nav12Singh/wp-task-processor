<?php
/**
 * API Details settings tab.
 *
 * Variables available: $api_key (string), $api_base (string)
 *
 * @package WPTaskProcessor
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap wtp-dashboard">
    <h1><?php esc_html_e( 'Task Processor — Settings', 'wp-task-processor' ); ?></h1>

    <nav class="nav-tab-wrapper wtp-settings-tabs">
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=wtp-settings' ) ); ?>"
           class="nav-tab"><?php esc_html_e( 'General', 'wp-task-processor' ); ?></a>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=wtp-api-details' ) ); ?>"
           class="nav-tab nav-tab-active"><?php esc_html_e( 'API Details', 'wp-task-processor' ); ?></a>
    </nav>

    <div class="wtp-card wtp-api-ref" style="margin-top:16px;">

        <!-- ── Connection info ───────────────────────────────────────────── -->
        <div class="wtp-api-meta">
            <div class="wtp-api-meta__row">
                <span class="wtp-api-meta__label"><?php esc_html_e( 'Base URL', 'wp-task-processor' ); ?></span>
                <code class="wtp-api-meta__val" id="wtp-base-url"><?php echo esc_html( $api_base ); ?></code>
                <button type="button" class="button button-small wtp-copy" data-target="wtp-base-url">
                    <?php esc_html_e( 'Copy', 'wp-task-processor' ); ?>
                </button>
            </div>

            <div class="wtp-api-meta__row">
                <span class="wtp-api-meta__label"><?php esc_html_e( 'Auth Header', 'wp-task-processor' ); ?></span>
                <?php if ( $api_key ) : ?>
                    <code class="wtp-api-meta__val" id="wtp-auth-header">X-WTP-API-Key: <?php echo esc_html( $api_key ); ?></code>
                    <button type="button" class="button button-small wtp-copy" data-target="wtp-auth-header">
                        <?php esc_html_e( 'Copy', 'wp-task-processor' ); ?>
                    </button>
                <?php else : ?>
                    <code class="wtp-api-meta__val">X-WTP-API-Key: <em><?php esc_html_e( '(generate one in the General tab)', 'wp-task-processor' ); ?></em></code>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=wtp-settings' ) ); ?>" class="button button-small">
                        <?php esc_html_e( 'Go to General', 'wp-task-processor' ); ?>
                    </a>
                <?php endif; ?>
            </div>

            <div class="wtp-api-meta__row">
                <span class="wtp-api-meta__label"><?php esc_html_e( 'Auth Type', 'wp-task-processor' ); ?></span>
                <span style="font-size:13px;color:#555;">
                    <?php esc_html_e( 'API Key — add header', 'wp-task-processor' ); ?>
                    <code style="background:#f0f0f0;padding:2px 6px;border-radius:3px;">X-WTP-API-Key: {value}</code>
                    <?php esc_html_e( 'to every request', 'wp-task-processor' ); ?>
                </span>
            </div>
        </div>

        <!-- ── Endpoint 1: POST /tasks ───────────────────────────────────── -->
        <div class="wtp-ep">
            <div class="wtp-ep__head">
                <span class="wtp-method wtp-method--post">POST</span>
                <code class="wtp-ep__url" id="wtp-ep1-url"><?php echo esc_html( $api_base ); ?>/tasks</code>
                <button type="button" class="button button-small wtp-copy" data-target="wtp-ep1-url">
                    <?php esc_html_e( 'Copy URL', 'wp-task-processor' ); ?>
                </button>
                <span class="wtp-ep__title"><?php esc_html_e( 'Create a new task', 'wp-task-processor' ); ?></span>
            </div>

            <div class="wtp-ep__body">

                <!-- Headers -->
                <div class="wtp-ep__col">
                    <p class="wtp-ep__section-label"><?php esc_html_e( 'Headers', 'wp-task-processor' ); ?></p>
                    <pre class="wtp-code" id="wtp-ep1-headers">Content-Type: application/json
X-WTP-API-Key: <?php echo esc_html( $api_key ?: '{your_api_key}' ); ?>

X-Idempotency-Key: {unique_key}   (optional)</pre>
                    <button type="button" class="button button-small wtp-copy" data-target="wtp-ep1-headers">
                        <?php esc_html_e( 'Copy', 'wp-task-processor' ); ?>
                    </button>

                    <p class="wtp-ep__section-label" style="margin-top:14px;"><?php esc_html_e( 'Field Reference', 'wp-task-processor' ); ?></p>
                    <table class="wtp-params-table">
                        <tr>
                            <td><code>Content-Type</code></td>
                            <td><span class="wtp-req"><?php esc_html_e( 'required', 'wp-task-processor' ); ?></span></td>
                            <td><?php esc_html_e( 'Must be application/json', 'wp-task-processor' ); ?></td>
                        </tr>
                        <tr>
                            <td><code>X-WTP-API-Key</code></td>
                            <td><span class="wtp-req"><?php esc_html_e( 'required', 'wp-task-processor' ); ?></span></td>
                            <td><?php esc_html_e( 'Your API key from the General tab', 'wp-task-processor' ); ?></td>
                        </tr>
                        <tr>
                            <td><code>X-Idempotency-Key</code></td>
                            <td><span class="wtp-opt"><?php esc_html_e( 'optional', 'wp-task-processor' ); ?></span></td>
                            <td><?php esc_html_e( 'Send the same key twice — returns the existing task instead of creating a duplicate', 'wp-task-processor' ); ?></td>
                        </tr>
                    </table>
                </div>

                <!-- Request Body -->
                <div class="wtp-ep__col">
                    <p class="wtp-ep__section-label">
                        <?php esc_html_e( 'Request Body', 'wp-task-processor' ); ?>
                        <span class="wtp-ep__note"><?php esc_html_e( '(JSON)', 'wp-task-processor' ); ?></span>
                    </p>
                    <pre class="wtp-code" id="wtp-ep1-body">{
  "type": "email",
  "payload": {
    "to": "user@example.com",
    "subject": "Hello"
  }
}</pre>
                    <button type="button" class="button button-small wtp-copy" data-target="wtp-ep1-body">
                        <?php esc_html_e( 'Copy', 'wp-task-processor' ); ?>
                    </button>

                    <p class="wtp-ep__section-label" style="margin-top:14px;"><?php esc_html_e( 'Body Fields', 'wp-task-processor' ); ?></p>
                    <table class="wtp-params-table">
                        <tr>
                            <td><code>type</code></td>
                            <td><span class="wtp-req"><?php esc_html_e( 'required', 'wp-task-processor' ); ?></span></td>
                            <td>
                                <?php esc_html_e( 'Task category. Accepted values:', 'wp-task-processor' ); ?><br>
                                <code>email</code> <code>report</code> <code>export</code>
                                <code>import</code> <code>sync</code> <code>cleanup</code>
                            </td>
                        </tr>
                        <tr>
                            <td><code>payload</code></td>
                            <td><span class="wtp-opt"><?php esc_html_e( 'optional', 'wp-task-processor' ); ?></span></td>
                            <td><?php esc_html_e( 'Any JSON object. Passed to the task worker as-is.', 'wp-task-processor' ); ?></td>
                        </tr>
                        <tr>
                            <td><code>payload._force_fail</code></td>
                            <td><span class="wtp-opt"><?php esc_html_e( 'optional', 'wp-task-processor' ); ?></span></td>
                            <td><?php esc_html_e( 'Set true to force the task to fail every time (testing retry logic).', 'wp-task-processor' ); ?></td>
                        </tr>
                    </table>
                </div>

                <!-- Response -->
                <div class="wtp-ep__col">
                    <p class="wtp-ep__section-label">
                        <?php esc_html_e( 'Response', 'wp-task-processor' ); ?>
                        <span class="wtp-badge wtp-badge--completed" style="font-size:10px;">201 Created</span>
                    </p>
                    <pre class="wtp-code">{
  "id":        "4ba0034d-344f-48fc-96e9-bc1d7ce6c802",
  "type":      "email",
  "status":    "pending",
  "attempts":  0,
  "result":    null,
  "createdAt": "2024-01-15 10:30:00"
}</pre>

                    <p class="wtp-ep__section-label" style="margin-top:14px;"><?php esc_html_e( 'Response Fields', 'wp-task-processor' ); ?></p>
                    <table class="wtp-params-table">
                        <tr><td><code>id</code></td><td><?php esc_html_e( 'UUID — use in GET /tasks/{id}', 'wp-task-processor' ); ?></td></tr>
                        <tr><td><code>type</code></td><td><?php esc_html_e( 'Task type you submitted', 'wp-task-processor' ); ?></td></tr>
                        <tr><td><code>status</code></td><td><?php esc_html_e( 'Always "pending" immediately after creation', 'wp-task-processor' ); ?></td></tr>
                        <tr><td><code>attempts</code></td><td><?php esc_html_e( 'Number of processing attempts so far', 'wp-task-processor' ); ?></td></tr>
                        <tr><td><code>result</code></td><td><?php esc_html_e( 'null until the task completes', 'wp-task-processor' ); ?></td></tr>
                        <tr><td><code>createdAt</code></td><td><?php esc_html_e( 'Creation timestamp (UTC)', 'wp-task-processor' ); ?></td></tr>
                    </table>

                    <p class="wtp-ep__section-label" style="margin-top:14px;"><?php esc_html_e( 'Error Responses', 'wp-task-processor' ); ?></p>
                    <table class="wtp-params-table">
                        <tr><td><span class="wtp-badge wtp-badge--failed" style="font-size:10px;">400</span></td><td><?php esc_html_e( 'Missing or invalid "type" field', 'wp-task-processor' ); ?></td></tr>
                        <tr><td><span class="wtp-badge wtp-badge--failed" style="font-size:10px;">401</span></td><td><?php esc_html_e( 'Missing or wrong X-WTP-API-Key', 'wp-task-processor' ); ?></td></tr>
                    </table>
                </div>

            </div><!-- /.wtp-ep__body -->
        </div><!-- /.wtp-ep -->

        <!-- ── Endpoint 2: GET /tasks/:id ────────────────────────────────── -->
        <div class="wtp-ep">
            <div class="wtp-ep__head">
                <span class="wtp-method wtp-method--get">GET</span>
                <code class="wtp-ep__url" id="wtp-ep2-url"><?php echo esc_html( $api_base ); ?>/tasks/{id}</code>
                <button type="button" class="button button-small wtp-copy" data-target="wtp-ep2-url">
                    <?php esc_html_e( 'Copy URL', 'wp-task-processor' ); ?>
                </button>
                <span class="wtp-ep__title"><?php esc_html_e( 'Get task status', 'wp-task-processor' ); ?></span>
            </div>

            <div class="wtp-ep__body">

                <!-- Headers + URL param -->
                <div class="wtp-ep__col">
                    <p class="wtp-ep__section-label"><?php esc_html_e( 'Headers', 'wp-task-processor' ); ?></p>
                    <pre class="wtp-code" id="wtp-ep2-headers">X-WTP-API-Key: <?php echo esc_html( $api_key ?: '{your_api_key}' ); ?></pre>
                    <button type="button" class="button button-small wtp-copy" data-target="wtp-ep2-headers">
                        <?php esc_html_e( 'Copy', 'wp-task-processor' ); ?>
                    </button>

                    <p class="wtp-ep__section-label" style="margin-top:14px;"><?php esc_html_e( 'URL Parameter', 'wp-task-processor' ); ?></p>
                    <table class="wtp-params-table">
                        <tr>
                            <td><code>id</code></td>
                            <td><span class="wtp-req"><?php esc_html_e( 'required', 'wp-task-processor' ); ?></span></td>
                            <td><?php esc_html_e( 'UUID returned by POST /tasks', 'wp-task-processor' ); ?></td>
                        </tr>
                    </table>

                    <p class="wtp-ep__hint">
                        <strong><?php esc_html_e( 'Example URL', 'wp-task-processor' ); ?></strong><br>
                        <code id="wtp-ep2-example"><?php echo esc_html( $api_base ); ?>/tasks/4ba0034d-344f-48fc-96e9-bc1d7ce6c802</code>
                        <button type="button" class="button button-small wtp-copy" data-target="wtp-ep2-example" style="margin-top:4px;">
                            <?php esc_html_e( 'Copy', 'wp-task-processor' ); ?>
                        </button>
                    </p>
                </div>

                <!-- Status lifecycle -->
                <div class="wtp-ep__col">
                    <p class="wtp-ep__section-label"><?php esc_html_e( 'Task Status Lifecycle', 'wp-task-processor' ); ?></p>
                    <div class="wtp-lifecycle">
                        <div class="wtp-lifecycle__step">
                            <span class="wtp-badge wtp-badge--pending">pending</span>
                            <span class="wtp-lifecycle__desc"><?php esc_html_e( 'Task is queued, waiting to be picked up', 'wp-task-processor' ); ?></span>
                        </div>
                        <div class="wtp-lifecycle__arrow">↓</div>
                        <div class="wtp-lifecycle__step">
                            <span class="wtp-badge wtp-badge--processing">processing</span>
                            <span class="wtp-lifecycle__desc"><?php esc_html_e( 'Worker is running the task (2–5 s)', 'wp-task-processor' ); ?></span>
                        </div>
                        <div class="wtp-lifecycle__arrow">↓</div>
                        <div class="wtp-lifecycle__fork">
                            <div class="wtp-lifecycle__step">
                                <span class="wtp-badge wtp-badge--completed">completed</span>
                                <span class="wtp-lifecycle__desc"><?php esc_html_e( 'Finished — result is populated', 'wp-task-processor' ); ?></span>
                            </div>
                            <div class="wtp-lifecycle__step" style="margin-top:6px;">
                                <span class="wtp-badge wtp-badge--failed">failed</span>
                                <span class="wtp-lifecycle__desc"><?php esc_html_e( 'All 3 attempts exhausted', 'wp-task-processor' ); ?></span>
                            </div>
                        </div>
                    </div>
                    <p class="wtp-ep__hint" style="margin-top:8px;">
                        <?php esc_html_e( '30% random failure rate is simulated. Failed tasks auto-retry up to 3 times with exponential backoff (2 s, 4 s, 8 s).', 'wp-task-processor' ); ?>
                    </p>
                </div>

                <!-- Response -->
                <div class="wtp-ep__col">
                    <p class="wtp-ep__section-label">
                        <?php esc_html_e( 'Response', 'wp-task-processor' ); ?>
                        <span class="wtp-badge wtp-badge--completed" style="font-size:10px;">200 OK</span>
                    </p>
                    <pre class="wtp-code">{
  "id":        "4ba0034d-344f-48fc-96e9-bc1d7ce6c802",
  "type":      "email",
  "status":    "completed",
  "attempts":  2,
  "result": {
    "processed":    true,
    "duration_ms":  3000,
    "output": {
      "message":    "Email sent successfully",
      "recipients": 1
    },
    "completed_at": "2024-01-15 10:30:05"
  },
  "createdAt": "2024-01-15 10:30:00"
}</pre>

                    <p class="wtp-ep__section-label" style="margin-top:14px;"><?php esc_html_e( 'Error Responses', 'wp-task-processor' ); ?></p>
                    <table class="wtp-params-table">
                        <tr><td><span class="wtp-badge wtp-badge--failed" style="font-size:10px;">401</span></td><td><?php esc_html_e( 'Missing or wrong X-WTP-API-Key', 'wp-task-processor' ); ?></td></tr>
                        <tr><td><span class="wtp-badge wtp-badge--failed" style="font-size:10px;">404</span></td><td><?php esc_html_e( 'No task found with that ID', 'wp-task-processor' ); ?></td></tr>
                    </table>
                </div>

            </div><!-- /.wtp-ep__body -->
        </div><!-- /.wtp-ep -->

        <!-- ── Endpoint 3: GET /tasks ─────────────────────────────────────── -->
        <div class="wtp-ep">
            <div class="wtp-ep__head">
                <span class="wtp-method wtp-method--get">GET</span>
                <code class="wtp-ep__url" id="wtp-ep3-url"><?php echo esc_html( $api_base ); ?>/tasks</code>
                <button type="button" class="button button-small wtp-copy" data-target="wtp-ep3-url">
                    <?php esc_html_e( 'Copy URL', 'wp-task-processor' ); ?>
                </button>
                <span class="wtp-ep__title"><?php esc_html_e( 'List tasks', 'wp-task-processor' ); ?></span>
            </div>

            <div class="wtp-ep__body">

                <!-- Headers + query params -->
                <div class="wtp-ep__col">
                    <p class="wtp-ep__section-label"><?php esc_html_e( 'Headers', 'wp-task-processor' ); ?></p>
                    <pre class="wtp-code" id="wtp-ep3-headers">X-WTP-API-Key: <?php echo esc_html( $api_key ?: '{your_api_key}' ); ?></pre>
                    <button type="button" class="button button-small wtp-copy" data-target="wtp-ep3-headers">
                        <?php esc_html_e( 'Copy', 'wp-task-processor' ); ?>
                    </button>

                    <p class="wtp-ep__section-label" style="margin-top:14px;">
                        <?php esc_html_e( 'Query Parameters', 'wp-task-processor' ); ?>
                        <span class="wtp-ep__note"><?php esc_html_e( '(all optional)', 'wp-task-processor' ); ?></span>
                    </p>
                    <table class="wtp-params-table">
                        <tr>
                            <td><code>status</code></td>
                            <td><span class="wtp-opt"><?php esc_html_e( 'optional', 'wp-task-processor' ); ?></span></td>
                            <td><code>pending</code> <code>processing</code> <code>completed</code> <code>failed</code></td>
                        </tr>
                        <tr>
                            <td><code>type</code></td>
                            <td><span class="wtp-opt"><?php esc_html_e( 'optional', 'wp-task-processor' ); ?></span></td>
                            <td><?php esc_html_e( 'Filter by task type, e.g.', 'wp-task-processor' ); ?> <code>email</code></td>
                        </tr>
                        <tr>
                            <td><code>page</code></td>
                            <td><span class="wtp-opt"><?php esc_html_e( 'optional', 'wp-task-processor' ); ?></span></td>
                            <td><?php esc_html_e( 'Page number, default 1', 'wp-task-processor' ); ?></td>
                        </tr>
                        <tr>
                            <td><code>per_page</code></td>
                            <td><span class="wtp-opt"><?php esc_html_e( 'optional', 'wp-task-processor' ); ?></span></td>
                            <td><?php esc_html_e( 'Results per page, default 20', 'wp-task-processor' ); ?></td>
                        </tr>
                    </table>
                </div>

                <!-- Example URLs -->
                <div class="wtp-ep__col">
                    <p class="wtp-ep__section-label"><?php esc_html_e( 'Example Requests', 'wp-task-processor' ); ?></p>

                    <p class="wtp-ep__hint" style="margin-bottom:6px;"><strong><?php esc_html_e( 'All tasks', 'wp-task-processor' ); ?></strong></p>
                    <code class="wtp-api-meta__val" id="wtp-ep3-ex1"><?php echo esc_html( $api_base ); ?>/tasks</code>
                    <button type="button" class="button button-small wtp-copy" data-target="wtp-ep3-ex1" style="margin-top:4px;"><?php esc_html_e( 'Copy', 'wp-task-processor' ); ?></button>

                    <p class="wtp-ep__hint" style="margin-top:12px;margin-bottom:6px;"><strong><?php esc_html_e( 'Only failed tasks', 'wp-task-processor' ); ?></strong></p>
                    <code class="wtp-api-meta__val" id="wtp-ep3-ex2"><?php echo esc_html( $api_base ); ?>/tasks?status=failed</code>
                    <button type="button" class="button button-small wtp-copy" data-target="wtp-ep3-ex2" style="margin-top:4px;"><?php esc_html_e( 'Copy', 'wp-task-processor' ); ?></button>

                    <p class="wtp-ep__hint" style="margin-top:12px;margin-bottom:6px;"><strong><?php esc_html_e( 'Page 2, 10 per page', 'wp-task-processor' ); ?></strong></p>
                    <code class="wtp-api-meta__val" id="wtp-ep3-ex3"><?php echo esc_html( $api_base ); ?>/tasks?page=2&per_page=10</code>
                    <button type="button" class="button button-small wtp-copy" data-target="wtp-ep3-ex3" style="margin-top:4px;"><?php esc_html_e( 'Copy', 'wp-task-processor' ); ?></button>
                </div>

                <!-- Response -->
                <div class="wtp-ep__col">
                    <p class="wtp-ep__section-label">
                        <?php esc_html_e( 'Response', 'wp-task-processor' ); ?>
                        <span class="wtp-badge wtp-badge--completed" style="font-size:10px;">200 OK</span>
                    </p>
                    <pre class="wtp-code">{
  "tasks": [
    {
      "id":        "4ba0034d-...",
      "type":      "email",
      "status":    "completed",
      "attempts":  1,
      "result": {
        "processed":    true,
        "duration_ms":  2000,
        "output": { ... },
        "completed_at": "2024-01-15 10:30:05"
      },
      "createdAt": "2024-01-15 10:30:00"
    }
  ],
  "total": 42,
  "page":  1
}</pre>

                    <p class="wtp-ep__section-label" style="margin-top:14px;"><?php esc_html_e( 'Response Fields', 'wp-task-processor' ); ?></p>
                    <table class="wtp-params-table">
                        <tr><td><code>tasks</code></td><td><?php esc_html_e( 'Array of task objects (same shape as GET /tasks/{id})', 'wp-task-processor' ); ?></td></tr>
                        <tr><td><code>total</code></td><td><?php esc_html_e( 'Total tasks matching the filter (for pagination)', 'wp-task-processor' ); ?></td></tr>
                        <tr><td><code>page</code></td><td><?php esc_html_e( 'Current page number', 'wp-task-processor' ); ?></td></tr>
                    </table>
                </div>

            </div><!-- /.wtp-ep__body -->
        </div><!-- /.wtp-ep -->

    </div><!-- /.wtp-api-ref -->
</div>
