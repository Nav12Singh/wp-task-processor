# WP Task Processor

A production-grade **Real-Time Task Processing System** built as a WordPress plugin.

Async job queue · Retry logic with exponential backoff · Redis caching · WebSocket + SSE real-time updates · Idempotency · Concurrent-safe processing · Docker support

---

## Table of Contents

1. [Features](#features)
2. [Requirements](#requirements)
3. [Installation](#installation)
4. [Quick Start](#quick-start)
5. [Authentication](#authentication)
6. [REST API](#rest-api)
7. [Admin Dashboard](#admin-dashboard)
8. [Task Lifecycle](#task-lifecycle)
9. [Retry Logic](#retry-logic)
10. [Idempotency](#idempotency)
11. [Real-Time Updates](#real-time-updates)
12. [Redis Caching](#redis-caching)
13. [Concurrent Request Safety](#concurrent-request-safety)
14. [Postman Collection](#postman-collection)
15. [Docker](#docker)
16. [Configuration](#configuration)
17. [Project Structure](#project-structure)

---

## Features

| Requirement | Implementation |
|---|---|
| REST API (POST + GET) | 3 endpoints: create task, get task, list tasks |
| Async background processing | WP-Cron queue worker + immediate dispatch via loopback |
| 2–5 second processing delay | `sleep()` inside the worker simulates real async work |
| Retry up to 3 times on failure | Exponential backoff: 2 s → 4 s → 8 s |
| ~30% random failure simulation | `mt_rand()` check in `WTP_Task_Processor` |
| Idempotency — no duplicate tasks | Unique-key lookup + MySQL `UNIQUE` constraint |
| WebSocket real-time updates | Pure-PHP WebSocket server (`bin/websocket-server.php`) |
| SSE real-time fallback | `/wp-json/wtp/v1/events` endpoint |
| Redis caching (TTL 30–60 s) | `phpredis` → `Predis` → WP transients (auto-fallback) |
| Concurrent request safety | Optimistic DB locking via `locked_until` column |
| BullMQ-style queue | MySQL-backed queue with priorities, delay, status tracking |
| Structured logging | PSR-3 inspired logger → `wtp_task_logs` DB table |
| Docker | `docker-compose.yml` with WordPress, MySQL, Redis, WebSocket, Cron |

---

## Requirements

| Dependency | Minimum version | Notes |
|---|---|---|
| WordPress | 5.8 | |
| PHP | 7.4 | 8.x recommended |
| MySQL / MariaDB | 5.7 / 10.3 | InnoDB required |
| Redis | 6+ | Optional — WP transients used as fallback |
| phpredis **or** Predis | any | Optional — needed for Redis caching |
| `pcntl` extension | any | Optional — needed for Redis pub/sub in WebSocket server |

---

## Installation

### Option A — Manual

```bash
# Copy plugin to WordPress
cp -r wp-task-processor/ /path/to/wp-content/plugins/

# Activate via WP-CLI
wp plugin activate wp-task-processor

# OR activate via WordPress admin: Plugins → Installed Plugins
```

On activation the plugin:
- Creates `wp_wtp_tasks` and `wp_wtp_task_logs` database tables
- Auto-generates a REST API key (copy it from **Settings → API Details**)
- Registers WP-Cron jobs (queue worker every 60 s, retry sweep every 5 min)

### Option B — Docker (recommended for development)

```bash
cd wp-task-processor/docker
docker-compose up -d

# First-time WordPress setup
docker exec -it wtp_wordpress wp core install \
  --url=http://localhost:8000 \
  --title="WTP Dev" \
  --admin_user=admin \
  --admin_password=admin \
  --admin_email=admin@example.com \
  --allow-root

# Activate the plugin
docker exec -it wtp_wordpress wp plugin activate wp-task-processor --allow-root
```

---

## Quick Start

### 1 — Get your API key

Go to **Task Processor → Settings → API Details** and copy the `X-WTP-API-Key` header value.

### 2 — Create a task

```http
POST /wp-json/wtp/v1/tasks
Content-Type: application/json
X-WTP-API-Key: your_api_key_here

{
  "type": "email",
  "payload": { "to": "user@example.com", "subject": "Hello" }
}
```

Response `201 Created`:

```json
{
  "id":        "4ba0034d-344f-48fc-96e9-bc1d7ce6c802",
  "type":      "email",
  "status":    "pending",
  "attempts":  0,
  "result":    null,
  "createdAt": "2024-01-15 10:30:00"
}
```

### 3 — Poll for status

```http
GET /wp-json/wtp/v1/tasks/4ba0034d-344f-48fc-96e9-bc1d7ce6c802
X-WTP-API-Key: your_api_key_here
```

Response `200 OK` (when completed):

```json
{
  "id":        "4ba0034d-344f-48fc-96e9-bc1d7ce6c802",
  "type":      "email",
  "status":    "completed",
  "attempts":  1,
  "result": {
    "processed":    true,
    "duration_ms":  3000,
    "output":       { "message": "Email sent successfully", "recipients": 1 },
    "completed_at": "2024-01-15 10:30:03"
  },
  "createdAt": "2024-01-15 10:30:00"
}
```

---

## Authentication

All endpoints require one of:

| Method | Header | When to use |
|---|---|---|
| API Key | `X-WTP-API-Key: <key>` | External tools (Postman, scripts, apps) |
| WP Nonce | `X-WP-Nonce: <nonce>` | Logged-in WordPress users (browser/admin) |

The API key is **auto-generated on first activation**. Find it under **Task Processor → Settings → API Details**.  
To rotate it, paste a new value in **Task Processor → Settings → General**.

---

## REST API

**Base URL:** `https://your-site.com/wp-json/wtp/v1`

---

### `POST /tasks` — Create Task

Creates a new background task and queues it for immediate processing.

```http
POST /wp-json/wtp/v1/tasks
Content-Type: application/json
X-WTP-API-Key: {your_api_key}
X-Idempotency-Key: {unique_key}        ← optional
```

**Request body:**

| Field | Required | Type | Description |
|---|---|---|---|
| `type` | **yes** | string | Task category: `email` `report` `export` `import` `sync` `cleanup` |
| `payload` | no | object | Any JSON — passed to the worker as-is |
| `payload._force_fail` | no | boolean | `true` = always fail (for testing retry logic) |
| `payload._force_success` | no | boolean | `true` = always succeed (bypasses 30% failure sim) |

**Example request:**

```json
{
  "type": "report",
  "payload": {
    "report_type": "monthly",
    "format": "pdf"
  }
}
```

**Response `201 Created`:**

```json
{
  "id":        "4ba0034d-344f-48fc-96e9-bc1d7ce6c802",
  "type":      "report",
  "status":    "pending",
  "attempts":  0,
  "result":    null,
  "createdAt": "2024-01-15 10:30:00"
}
```

**Error responses:**

| Code | Reason |
|---|---|
| `400` | Missing or invalid `type` field |
| `401` | Missing or wrong `X-WTP-API-Key` |

---

### `GET /tasks/:id` — Get Task Status

Fetches a single task by its UUID. Use this to poll the status after creation.

```http
GET /wp-json/wtp/v1/tasks/4ba0034d-344f-48fc-96e9-bc1d7ce6c802
X-WTP-API-Key: {your_api_key}
```

**Response `200 OK`:**

```json
{
  "id":        "4ba0034d-344f-48fc-96e9-bc1d7ce6c802",
  "type":      "report",
  "status":    "completed",
  "attempts":  2,
  "result": {
    "processed":    true,
    "duration_ms":  4000,
    "output":       { "message": "Report generated", "rows": 248 },
    "completed_at": "2024-01-15 10:30:04"
  },
  "createdAt": "2024-01-15 10:30:00"
}
```

**Response fields:**

| Field | Type | Description |
|---|---|---|
| `id` | UUID string | Unique task identifier |
| `type` | string | Task category submitted at creation |
| `status` | string | Current state — see [Task Lifecycle](#task-lifecycle) |
| `attempts` | integer | Number of processing attempts so far |
| `result` | object / null | Populated when `status` is `completed`; `null` otherwise |
| `createdAt` | datetime | UTC timestamp of task creation |

**Error responses:**

| Code | Reason |
|---|---|
| `401` | Missing or wrong `X-WTP-API-Key` |
| `404` | No task found with that ID |

---

### `GET /tasks` — List Tasks

Returns a paginated list of tasks with optional filters.

```http
GET /wp-json/wtp/v1/tasks?status=failed&per_page=20&page=1
X-WTP-API-Key: {your_api_key}
```

**Query parameters:**

| Param | Type | Default | Description |
|---|---|---|---|
| `status` | string | *(all)* | Filter: `pending` `processing` `completed` `failed` |
| `type` | string | *(all)* | Filter by task type, e.g. `email` |
| `page` | integer | `1` | Page number |
| `per_page` | integer | `20` | Results per page |

**Response `200 OK`:**

```json
{
  "tasks": [
    {
      "id":        "4ba0034d-344f-48fc-96e9-bc1d7ce6c802",
      "type":      "email",
      "status":    "completed",
      "attempts":  1,
      "result":    { "processed": true, "duration_ms": 2000, "output": { ... } },
      "createdAt": "2024-01-15 10:30:00"
    }
  ],
  "total": 42,
  "page":  1
}
```

**Response fields:**

| Field | Description |
|---|---|
| `tasks` | Array of task objects (same shape as `GET /tasks/:id`) |
| `total` | Total count matching the filter (use for pagination) |
| `page` | Current page number |

---

## Admin Dashboard

Access via **Task Processor** in the WordPress admin sidebar.

### Dashboard

- **Stats bar** — live counts of pending / processing / completed / failed tasks
- **Real-time connection indicator** — shows WebSocket or SSE status
- **Create Test Task** form — submit tasks directly from the browser, with force-fail toggle
- **Task table** — filterable, paginated list; rows update live via WebSocket/SSE
- **Live Event Stream** — scrolling log of real-time task events

### Settings → General

Configure Redis host/port/password, the REST API key, and the WebSocket server port.

### Settings → API Details

Full API reference for all 3 endpoints, designed to get you running in Postman immediately:

- **Base URL** and **Auth Header** rows — with one-click Copy buttons
- **POST /tasks** — headers, request body with field reference, response 201 example, error codes
- **GET /tasks/{id}** — URL parameter, task lifecycle diagram, full response example, error codes
- **GET /tasks** — query parameters, ready-to-copy example URLs, response example

### Logs

Last 200 log entries from `wp_wtp_task_logs` — filterable by level (debug / info / warning / error).

---

## Task Lifecycle

```
              ┌────────────────────────────────────────────┐
              │                  success                   │
              ▼                                            │
 pending ──► processing ─────────────────────────────► completed
    ▲            │
    │            │  failure + attempts < max_attempts (3)
    │            ▼
    └────── pending (scheduled_at += backoff delay)
                 │
                 │  failure + attempts >= max_attempts
                 ▼
              failed ──► POST /tasks/:id/retry ──► pending (attempts reset)
```

| Status | Meaning |
|---|---|
| `pending` | Queued, waiting to be picked up by the worker |
| `processing` | Worker is executing the task (2–5 s) |
| `completed` | Finished successfully — `result` is populated |
| `failed` | All retry attempts exhausted |

---

## Retry Logic

Failed tasks are automatically retried up to **3 times** using **exponential backoff**:

```
Attempt 1 failed  →  retry after  2 s  (2^1)
Attempt 2 failed  →  retry after  4 s  (2^2)
Attempt 3 failed  →  retry after  8 s  (2^3)
Attempt 4         →  status = failed (permanent)
```

The `scheduled_at` column stores the earliest time the task may be re-claimed. The queue worker's `WHERE scheduled_at <= NOW()` guard prevents premature execution.

A background sweep runs every 5 minutes to re-queue any tasks that missed their retry window.

**Force failure for testing:**

```json
{ "type": "test", "payload": { "_force_fail": true } }
```

---

## Idempotency

Sending the same request twice will not create a duplicate task.

### How it works

1. Provide an `X-Idempotency-Key` header (or `idempotency_key` body field).
2. If no key is provided, one is auto-generated from a hash of `type + sorted payload`.
3. Before inserting, the system checks for an existing row with that key. If found, the **existing task is returned** — no new row is written.
4. If two identical requests arrive simultaneously, a MySQL `UNIQUE KEY` constraint on `idempotency_key` ensures only one row is ever inserted. The losing request reads back the existing row.

### Example

```bash
# First call — creates the task
curl -X POST /wp-json/wtp/v1/tasks \
  -H "X-Idempotency-Key: welcome-email-user-42" \
  -H "Content-Type: application/json" \
  -d '{"type":"email","payload":{"user_id":42}}'
# → 201 Created, id: abc-123, status: pending

# Same key — returns the same task, no duplicate
curl -X POST /wp-json/wtp/v1/tasks \
  -H "X-Idempotency-Key: welcome-email-user-42" \
  -H "Content-Type: application/json" \
  -d '{"type":"email","payload":{"user_id":42}}'
# → 201 Created, id: abc-123, status: completed  ← same task, now finished
```

---

## Real-Time Updates

Task status changes are pushed to connected clients without polling. Two transports are supported; the browser client tries WebSocket first and falls back to SSE automatically.

### WebSocket

Start the standalone server:

```bash
# Direct
php wp-content/plugins/wp-task-processor/bin/websocket-server.php

# Docker (runs automatically as wtp_websocket service)
docker-compose up -d
```

**Client usage:**

```javascript
const ws = new WebSocket('ws://localhost:8080');

ws.onopen = () => {
  // Subscribe to all tasks
  ws.send(JSON.stringify({ action: 'subscribe', task_id: 'all' }));

  // Or subscribe to one specific task
  ws.send(JSON.stringify({ action: 'subscribe', task_id: 'uuid-here' }));
};

ws.onmessage = e => {
  const msg = JSON.parse(e.data);
  if (msg.event === 'task_updated') {
    console.log(msg.task); // { id, type, status, attempts, result, createdAt }
  }
};
```

**Update delivery path:**

1. WordPress updates the task in MySQL
2. `WTP_Task_Manager::update_status()` publishes to Redis channel `wtp:task_events`
3. The WS server's Redis subscriber receives the message and calls `broadcast_task()`
4. All subscribed clients receive a `task_updated` frame

When Redis is unavailable, the WS server polls MySQL directly every ~2 seconds.

### Server-Sent Events (SSE fallback)

```javascript
const sse = new EventSource('/wp-json/wtp/v1/events');
sse.addEventListener('task_updated', e => {
  const task = JSON.parse(e.data);
  console.log(task.status);
});

// Filter to a single task
const sse = new EventSource('/wp-json/wtp/v1/events?task_id=uuid-here');
```

The SSE endpoint closes automatically after 60 seconds; the `EventSource` reconnects via the `retry: 3000` directive.

---

## Redis Caching

Task objects are cached with a **45-second TTL** (within the 30–60 s requirement).

**Cache key:** `wtp:task:<uuid>`

**Read path:**

```
GET /tasks/:id
  → L1: Redis GET "wtp:task:<uuid>"   → hit: return immediately (no DB)
  → L2: MySQL SELECT                  → miss: query DB, populate cache, return
```

**Write path (on every status change):**

```
update_status()
  → MySQL UPDATE
  → Redis SET (refresh cache with new status)
  → Redis PUBLISH wtp:task_events (notify WebSocket server)
```

**Fallback:** If Redis is unavailable (wrong host, missing extension), the plugin transparently falls back to **WordPress transients** backed by the database. No configuration change is needed.

---

## Concurrent Request Safety

Multiple cron workers can run simultaneously without processing the same task twice.

**Claim sequence (optimistic locking):**

```sql
-- 1. Find a candidate
SELECT * FROM wtp_tasks
WHERE  status       = 'pending'
  AND  scheduled_at <= NOW()
  AND  (locked_until IS NULL OR locked_until < NOW())
ORDER  BY created_at ASC
LIMIT  1;

-- 2. Atomically claim it (compare-and-swap)
UPDATE wtp_tasks
SET    status       = 'processing',
       locked_until = DATE_ADD(NOW(), INTERVAL 5 MINUTE),
       updated_at   = NOW()
WHERE  task_id = ?
  AND  status  = 'pending'
  AND  (locked_until IS NULL OR locked_until < NOW());

-- rows_affected = 1  → this worker won, proceed
-- rows_affected = 0  → another worker claimed it first, skip
```

**Stale lock recovery:** If a worker crashes mid-task, its `locked_until` timestamp expires. The next cron tick resets tasks stuck in `processing` for more than 10 minutes back to `pending`:

```sql
UPDATE wtp_tasks
SET    status = 'pending', locked_until = NULL
WHERE  status       = 'processing'
  AND  locked_until < DATE_SUB(NOW(), INTERVAL 10 MINUTE);
```

---

## Postman Collection

Import `postman/WP-Task-Processor.postman_collection.json` for ready-to-run requests with automated tests.

### Setup

1. **Import** the collection file into Postman.
2. **Set `base_url`** collection variable — e.g. `http://localhost:8000` for Docker.
3. **Set `api_key`** — copy it from **Task Processor → Settings → API Details**.

The collection uses API Key auth at the collection level, so `X-WTP-API-Key: {{api_key}}` is added to every request automatically.

### Included requests

| Request | Method | Description |
|---|---|---|
| Create Task | `POST /tasks` | Creates a task; stores `task_id` in a collection variable |
| Create Task — Force Fail | `POST /tasks` | `_force_fail: true` — use to trigger retry logic |
| Create Task — Idempotency | `POST /tasks` | Send twice with the same key, get the same task back |
| List All Tasks | `GET /tasks` | Paginated task list |
| List Tasks by Status | `GET /tasks?status=failed` | Filter by status |
| Get Single Task | `GET /tasks/{{task_id}}` | Fetch by UUID |
| Get Task Logs | `GET /tasks/{{task_id}}/logs` | Full audit trail for a task |
| Retry Failed Task | `POST /tasks/{{task_id}}/retry` | Manually re-queue a failed task |
| Queue Stats | `GET /stats` | Counts per status + Redis state |
| SSE Events Stream | `GET /events` | Streaming real-time updates |

---

## Docker

### Quick start

```bash
cd docker
docker-compose up -d
```

### Services

| Container | Description | Exposed port |
|---|---|---|
| `wtp_wordpress` | WordPress + plugin | `8000` |
| `wtp_websocket` | PHP WebSocket server | `8080` |
| `wtp_cron` | WP-CLI cron runner (every 60 s) | — |
| `wtp_mysql` | MySQL 8.0 | `3307` |
| `wtp_redis` | Redis 7 | `6379` |

### Debug mode

```bash
docker-compose --profile debug up -d
# Redis Commander UI: http://localhost:8081
```

### Environment variables (set in `docker-compose.yml`)

| Variable | Default | Description |
|---|---|---|
| `WTP_REDIS_HOST` | `redis` | Redis hostname inside Docker network |
| `WTP_REDIS_PORT` | `6379` | Redis port |
| `WTP_WS_HOST` | `0.0.0.0` | WebSocket server bind address |
| `WTP_WS_PORT` | `8080` | WebSocket server port |
| `WORDPRESS_DEBUG` | `false` | Enables `WP_DEBUG` + `WP_DEBUG_LOG` |

---

## Configuration

**Task Processor → Settings → General**

| Setting | Default | Description |
|---|---|---|
| Redis Host | `127.0.0.1` | Redis server hostname |
| Redis Port | `6379` | Redis server port |
| Redis Password | — | Redis `AUTH` password (leave blank if none) |
| Redis DB | `0` | Redis database index |
| REST API Key | *(auto-generated)* | `X-WTP-API-Key` value — see **API Details** tab to copy |
| WebSocket Port | `8080` | Port for the standalone WebSocket server |

**Plugin constants** (defined in `wp-task-processor.php`, override in `wp-config.php`):

| Constant | Default | Description |
|---|---|---|
| `WTP_MAX_ATTEMPTS` | `3` | Maximum processing attempts per task |
| `WTP_FAILURE_RATE` | `0.30` | Simulated failure probability (0.0 – 1.0) |
| `WTP_CACHE_TTL` | `45` | Redis cache TTL in seconds |
| `WTP_WS_PORT` | `8080` | Default WebSocket port |

---

## Project Structure

```
wp-task-processor/
│
├── wp-task-processor.php            Plugin bootstrap, constants, lifecycle hooks
│
├── includes/
│   ├── class-database.php           DB schema install / upgrade
│   ├── class-task.php               Task entity / DTO
│   ├── class-logger.php             Structured logger → wp_wtp_task_logs table
│   ├── class-redis-cache.php        Cache layer: phpredis → Predis → WP transients
│   ├── class-task-manager.php       CRUD, idempotency, cache invalidation, pub/sub
│   ├── class-task-queue.php         BullMQ-style queue with optimistic locking
│   ├── class-task-processor.php     Background worker: delay, failure sim, retry
│   ├── class-rest-api.php           REST endpoints + SSE stream
│   └── class-websocket-server.php   Pure-PHP WebSocket server (RFC 6455)
│
├── admin/
│   ├── class-admin.php              Admin menus, settings, AJAX handlers
│   └── views/
│       ├── dashboard.php            Dashboard: stats, task table, live events
│       └── api-details.php          Settings → API Details tab
│
├── assets/
│   ├── js/task-dashboard.js         WS/SSE client, live table updates, copy buttons
│   └── css/admin.css                Admin UI styles
│
├── bin/
│   └── websocket-server.php         CLI entry-point for the WebSocket server
│
├── docker/
│   ├── Dockerfile                   WordPress image + phpredis + WP-CLI
│   ├── docker-compose.yml           Full dev stack (WP + MySQL + Redis + WS + Cron)
│   └── wp-config-extra.php          Env-var → WP config bridge
│
└── postman/
    └── WP-Task-Processor.postman_collection.json
```

---

## Database Schema

### `wp_wtp_tasks`

| Column | Type | Description |
|---|---|---|
| `task_id` | `VARCHAR(36)` PK | UUID v4 |
| `type` | `VARCHAR(100)` | Task category |
| `status` | `ENUM` | `pending` `processing` `completed` `failed` |
| `attempts` | `INT` | Processing attempt count |
| `max_attempts` | `INT` | Retry limit (default 3) |
| `payload` | `LONGTEXT` JSON | Input data |
| `result` | `LONGTEXT` JSON | Output data (set on completion) |
| `idempotency_key` | `VARCHAR(255)` UNIQUE | Deduplication key |
| `error_message` | `TEXT` | Last failure reason |
| `scheduled_at` | `DATETIME` | Earliest time the task may be claimed |
| `locked_until` | `DATETIME` | Lock expiry for concurrent safety |
| `created_at` | `DATETIME` | Creation timestamp |
| `updated_at` | `DATETIME` | Last modification timestamp |

### `wp_wtp_task_logs`

| Column | Type | Description |
|---|---|---|
| `id` | `BIGINT` PK | Auto-increment |
| `task_id` | `VARCHAR(36)` | FK to `wtp_tasks.task_id` |
| `level` | `VARCHAR(20)` | `debug` `info` `warning` `error` |
| `message` | `TEXT` | Log message |
| `context` | `LONGTEXT` JSON | Additional data |
| `created_at` | `DATETIME` | Log timestamp |

---

## License

GPL v2 or later — see [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html)
