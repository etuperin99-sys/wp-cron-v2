# WP Cron v2

Modern job queue for WordPress - Laravel Horizon-level background processing system.

## Why WP Cron v2?

The current WP-Cron is WordPress's biggest technical bottleneck:

| Problem | WP-Cron | WP Cron v2 |
|---------|---------|------------|
| Depends on user traffic | ❌ | ✅ Separate worker |
| Causes CPU spikes | ❌ | ✅ Even load |
| Priorities | ❌ | ✅ high/normal/low |
| Retry on failure | ❌ | ✅ Exponential backoff |
| Timeout handling | ❌ | ✅ Stale job recovery |
| Concurrency | ❌ | ✅ Multiple workers |
| Monitoring | ❌ | ✅ Admin UI + CLI |
| Health check | ❌ | ✅ `wp cron-v2 health` |
| Scalability | ❌ | ✅ Cluster-ready |

## Current Status (v0.4.0)

| Feature | Status |
|---------|--------|
| Job Queue (dispatch, later) | ✅ |
| Worker daemon | ✅ |
| Priorities (high/normal/low) | ✅ |
| Retry + exponential backoff | ✅ |
| Timeout + stale job recovery | ✅ |
| Scheduled jobs (recurring) | ✅ |
| Admin UI (dashboard, job list) | ✅ |
| WP-Cron backwards compatibility | ✅ |
| WP-CLI commands (38 commands) | ✅ |
| Health check | ✅ |
| Cleanup & maintenance | ✅ |
| Job Batching | ✅ |
| Job Chains | ✅ |
| REST API | ✅ |
| Rate Limiting | ✅ |
| Webhooks | ✅ |
| Job Unique/Deduplication | ✅ |
| Redis driver | ✅ |
| Multisite support | ✅ |

## Installation

```bash
# Clone
cd /path/to/wordpress/wp-content/plugins
git clone git@github.com:etuperin99-sys/wp-cron-v2.git

# Activate
wp plugin activate wp-cron-v2

# Check status
wp cron-v2 health

# Start worker
wp cron-v2 worker --queue=default
```

## Quick Start

### 1. Create a Job

```php
<?php
use WPCronV2\Jobs\Job;

class SendEmailJob extends Job {

    public int $max_attempts = 3;     // Retry 3 times
    public string $queue = 'emails';  // Queue
    public string $priority = 'high'; // Priority

    private string $to;

    public function __construct( string $to ) {
        $this->to = $to;
    }

    public function handle(): void {
        wp_mail( $this->to, 'Subject', 'Content' );
    }

    public function failed( \Throwable $e ): void {
        error_log( 'Email failed: ' . $e->getMessage() );
    }
}
```

### 2. Dispatch

```php
// Immediately
wp_cron_v2()->dispatch( new SendEmailJob( 'test@example.com' ) );

// After 1 hour
wp_cron_v2()->later( 3600, new SendEmailJob( 'test@example.com' ) );

// Specific queue + priority
wp_cron_v2()
    ->queue( 'emails' )
    ->priority( 'high' )
    ->dispatch( new SendEmailJob( 'test@example.com' ) );
```

### 3. Scheduler (recurring)

```php
// Daily
wp_cron_v2_scheduler()->schedule(
    'cleanup-logs',
    'daily',
    new CleanupJob()
);

// Intervals: minutely, every_5_minutes, every_15_minutes,
// every_30_minutes, hourly, twicedaily, daily, weekly
```

### 4. Batch (multiple jobs at once)

```php
// Send 100 emails as a batch
$users = get_users( [ 'number' => 100 ] );

$batch = wp_cron_v2_batch( 'newsletter-send' );

foreach ( $users as $user ) {
    $batch->add( new SendNewsletterJob( $user->ID ) );
}

$batch->onQueue( 'emails' )
    ->then( function( $batch ) {
        error_log( 'Newsletter sent to everyone!' );
    })
    ->dispatch();
```

### 5. Chain (sequential jobs)

```php
// Order processing step by step
wp_cron_v2_chain( 'order-' . $order_id )
    ->add( new ValidateOrderJob( $order_id ) )
    ->add( new ChargePaymentJob( $order_id ) )
    ->add( new UpdateInventoryJob( $order_id ) )
    ->add( new SendConfirmationJob( $order_id ) )
    ->onQueue( 'orders' )
    ->catch( function( $chain, $e ) use ( $order_id ) {
        // If any step fails, cancel the order
        wp_update_post( [ 'ID' => $order_id, 'post_status' => 'cancelled' ] );
    })
    ->dispatch();
```

### 6. Rate Limiting

```php
<?php
use WPCronV2\Jobs\Job;

class ApiCallJob extends Job {

    // Limit: max 10 calls per minute
    public ?array $rate_limit = [
        'max' => 10,
        'per' => 60,  // seconds
    ];

    public function handle(): void {
        // API call that shouldn't overload
        wp_remote_get( 'https://api.example.com/data' );
    }
}
```

Rate limit is checked before job execution. If the limit is exceeded, the job is returned to the queue and retried later.

### 7. Unique Jobs (Deduplication)

```php
<?php
use WPCronV2\Jobs\Job;

class ImportUsersJob extends Job {

    // Prevent duplicates - same job can only be in queue once
    public ?string $unique_key = 'import-users';

    // Lock duration in seconds (default: until job completes)
    public ?int $unique_for = 3600;  // 1 hour

    public function handle(): void {
        // Long import that shouldn't restart
    }
}

// Dynamic unique key
class ProcessOrderJob extends Job {

    private int $order_id;

    public function __construct( int $order_id ) {
        $this->order_id = $order_id;
        $this->unique_key = 'process-order-' . $order_id;
    }
}
```

### 8. Webhooks

```php
// Register webhook
wp_cron_v2_webhooks()->register(
    'slack-alerts',                          // Name
    'https://hooks.slack.com/services/xxx',  // URL
    [ 'job.failed', 'health.issue' ],        // Events
    [
        'secret' => 'my-secret-key',         // HMAC signature
        'headers' => [ 'X-Custom' => 'value' ],
    ]
);

// Unregister webhook
wp_cron_v2_webhooks()->unregister( 'slack-alerts' );

// Enable/disable
wp_cron_v2_webhooks()->setEnabled( 'slack-alerts', false );

// Test
$result = wp_cron_v2_webhooks()->test( 'slack-alerts' );
```

**Supported events:**
- `job.completed` - Job executed
- `job.failed` - Job failed
- `batch.dispatched` - Batch dispatched
- `chain.completed` - Chain completed
- `chain.failed` - Chain failed
- `health.issue` - Health issue detected
- `*` - All events

**Webhook payload:**
```json
{
    "event": "job.failed",
    "timestamp": "2024-01-15 12:34:56",
    "payload": {
        "job_id": 123,
        "error": "Connection timeout",
        "file": "/path/to/Job.php",
        "line": 45
    }
}
```

**HMAC Signature:**
If secret is set, the header `X-WPCronV2-Signature` contains the HMAC-SHA256 signature.

### 9. Worker

```bash
# Daemon
wp cron-v2 worker --queue=default

# Process and exit
wp cron-v2 work --limit=100

# Health check
wp cron-v2 health
```

### 10. Redis Driver

The Redis driver provides significantly better performance with large job volumes.

**Requirements:**
- PHP Redis extension (`php-redis`)
- Redis Server

**Configuration in wp-config.php:**
```php
// Redis settings
define( 'WP_CRON_V2_REDIS_HOST', '127.0.0.1' );
define( 'WP_CRON_V2_REDIS_PORT', 6379 );
define( 'WP_CRON_V2_REDIS_PASSWORD', '' );
define( 'WP_CRON_V2_REDIS_DATABASE', 0 );
```

**Switch driver:**
```bash
# Test Redis connection
wp cron-v2 redis-test

# Switch to Redis driver
wp cron-v2 set-driver redis --save

# Show driver info
wp cron-v2 driver
```

**Programmatically:**
```php
// Switch to Redis driver for this session
wp_cron_v2()->setDriver( 'redis' );

// Or to database driver
wp_cron_v2()->setDriver( 'database' );
```

### 11. Multisite

WP Cron v2 supports WordPress Multisite installations. Jobs are site-specific by default.

**Site-specific jobs:**
```php
// Jobs are automatically saved to the current site
wp_cron_v2()->dispatch( new MyJob() );
```

**Network commands:**
```bash
# Show statistics for all sites
wp cron-v2 network-stats

# List sites and their job counts
wp cron-v2 sites

# Start worker for specific site
wp cron-v2 site-worker 2 --queue=default
```

**Programmatically:**
```php
// Execute code on specific site
wp_cron_v2_network()->runOnSite( 2, function() {
    wp_cron_v2()->dispatch( new SiteSpecificJob() );
});

// Get network statistics
$stats = wp_cron_v2_network()->getNetworkStats();
```

## WP-CLI Commands

### Worker & Processing

```bash
# Start worker daemon
wp cron-v2 worker --queue=default --sleep=3

# Process jobs once and exit
wp cron-v2 work --queue=default --limit=100

# Run single job immediately
wp cron-v2 run 123
```

### Statistics & Monitoring

```bash
# Health check - check for issues
wp cron-v2 health

# Quick statistics overview
wp cron-v2 stats

# Detailed statistics
wp cron-v2 info

# List jobs
wp cron-v2 list
wp cron-v2 list --status=failed --queue=emails --limit=50

# Show single job details
wp cron-v2 show 123
```

### Job Management

```bash
# Cancel job (queued only)
wp cron-v2 cancel 123

# Retry failed jobs
wp cron-v2 retry-failed
wp cron-v2 retry-failed --queue=emails --limit=50

# Delete failed jobs
wp cron-v2 flush-failed
wp cron-v2 flush-failed --older-than=7

# Delete completed jobs
wp cron-v2 purge-completed
wp cron-v2 purge-completed --older-than=30
```

### Maintenance

```bash
# Release stuck jobs (timeout)
wp cron-v2 release-stale
wp cron-v2 release-stale --timeout=60

# Clean up old jobs
wp cron-v2 cleanup --days=7
wp cron-v2 cleanup --days=30 --include-failed
```

### Scheduler

```bash
# List recurring tasks
wp cron-v2 schedules

# Pause/Resume
wp cron-v2 pause-schedule cleanup-logs
wp cron-v2 resume-schedule cleanup-logs

# Remove
wp cron-v2 remove-schedule cleanup-logs
```

### Batch & Chain

```bash
# List batches
wp cron-v2 batches

# Show batch details
wp cron-v2 batch-show abc12345

# Cancel batch (queued jobs)
wp cron-v2 batch-cancel abc12345

# List job chains
wp cron-v2 chains

# Show chain details and progress
wp cron-v2 chain-show abc12345

# Delete chain
wp cron-v2 chain-delete abc12345
```

### Webhooks

```bash
# List all webhooks
wp cron-v2 webhooks

# Add new webhook
wp cron-v2 webhook-add slack-alerts https://hooks.slack.com/xxx --events=job.failed,health.issue --secret=my-secret

# Test webhook (sends test event)
wp cron-v2 webhook-test slack-alerts

# Enable/disable
wp cron-v2 webhook-toggle slack-alerts disable
wp cron-v2 webhook-toggle slack-alerts enable

# Remove webhook
wp cron-v2 webhook-remove slack-alerts
```

### Rate Limiting

```bash
# Show rate limit statistics for a key
wp cron-v2 rate-limit-stats email-jobs --max=10 --per=60

# Reset rate limit (allows immediate new attempts)
wp cron-v2 rate-limit-reset email-jobs
```

## All CLI Commands (38)

| Command | Description |
|---------|-------------|
| `worker` | Start worker daemon |
| `work` | Process jobs once |
| `run <id>` | Run single job |
| `list` | List jobs |
| `show <id>` | Show job details |
| `cancel <id>` | Cancel job |
| `stats` | Statistics |
| `info` | Detailed statistics |
| `health` | Health check + issues |
| `retry-failed` | Retry failed jobs |
| `flush-failed` | Delete failed jobs |
| `purge-completed` | Delete completed jobs |
| `release-stale` | Release stuck jobs |
| `cleanup` | Clean up old jobs |
| `schedules` | List schedules |
| `pause-schedule` | Pause schedule |
| `resume-schedule` | Resume schedule |
| `remove-schedule` | Remove schedule |
| `batches` | List batches |
| `batch-show <id>` | Show batch details |
| `batch-cancel <id>` | Cancel batch |
| `chains` | List job chains |
| `chain-show <id>` | Show chain details |
| `chain-delete <id>` | Delete chain |
| `webhooks` | List webhooks |
| `webhook-add` | Add webhook |
| `webhook-remove` | Remove webhook |
| `webhook-test` | Test webhook |
| `webhook-toggle` | Enable/disable |
| `rate-limit-stats` | Show rate limit stats |
| `rate-limit-reset` | Reset rate limit |
| `driver` | Show driver info |
| `redis-test` | Test Redis connection |
| `set-driver <type>` | Switch driver |
| `redis-flush` | Flush Redis queues |
| `network-stats` | Multisite statistics |
| `sites` | List multisite sites |
| `site-worker <id>` | Worker for specific site |

## Admin UI

**WP Admin → Cron v2**

### Dashboard
- Stat cards: queued, running, completed, failed
- Auto-refresh every 30s

### Queue View
- Job listing with filters (status, queue)
- Retry button for failed jobs
- Cancel button for queued jobs

### Settings
- WP-Cron Adapter toggle
- Default queue
- Max attempts

## Timeout & Stale Job Recovery

If a job gets stuck in "running" state (e.g., worker crashed), it's automatically released:

```bash
# Release jobs that have been running > 30 min
wp cron-v2 release-stale --timeout=30
```

Behavior:
1. If attempts remaining → returned to queue with retry logic
2. If max_attempts reached → marked as failed

## Retry Logic

Exponential backoff when job fails:

| Attempt | Delay | Formula |
|---------|-------|---------|
| 1 | 2 min | 2¹ × 60s |
| 2 | 4 min | 2² × 60s |
| 3 | 8 min | 2³ × 60s |
| max_attempts | → failed | |

## Hooks

```php
// Job added to queue
add_action( 'wp_cron_v2_job_queued', function( $job_id, $job ) {
    // ...
}, 10, 2 );

// Job executed successfully
add_action( 'wp_cron_v2_job_completed', function( $job_id, $job ) {
    // ...
}, 10, 2 );

// Job failed permanently
add_action( 'wp_cron_v2_job_failed', function( $job_id, $exception ) {
    // Send alert, log...
}, 10, 2 );

// Job being retried
add_action( 'wp_cron_v2_job_retrying', function( $job_id, $attempts, $exception ) {
    // ...
}, 10, 3 );

// Job timeout (stale)
add_action( 'wp_cron_v2_job_timeout', function( $job_id, $action ) {
    // $action = 'retrying' or 'failed'
}, 10, 2 );

// Old jobs cleaned up
add_action( 'wp_cron_v2_jobs_cleaned', function( $count ) {
    // ...
}, 10, 1 );

// Batch dispatched
add_action( 'wp_cron_v2_batch_dispatched', function( $batch_id, $job_count ) {
    // ...
}, 10, 2 );

// Batch cancelled
add_action( 'wp_cron_v2_batch_cancelled', function( $batch_id, $cancelled_count ) {
    // ...
}, 10, 2 );

// Chain started
add_action( 'wp_cron_v2_chain_started', function( $chain_id, $job_count ) {
    // ...
}, 10, 2 );

// Chain completed
add_action( 'wp_cron_v2_chain_completed', function( $chain_id ) {
    // ...
}, 10, 1 );

// Chain failed
add_action( 'wp_cron_v2_chain_failed', function( $chain_id, $exception ) {
    // ...
}, 10, 2 );

// Job rate limited (returned to queue)
add_action( 'wp_cron_v2_job_rate_limited', function( $job_id, $job, $delay ) {
    // $delay = seconds until can retry
}, 10, 3 );

// Webhook sent
add_action( 'wp_cron_v2_webhook_sent', function( $url, $event, $payload ) {
    // ...
}, 10, 3 );
```

## WP-Cron Compatibility

Enable: **Cron v2 → Settings → WP-Cron Adapter**

When adapter is enabled:

```php
// These are automatically routed to WP Cron v2 queue:
wp_schedule_event( time(), 'hourly', 'my_hook' );
wp_schedule_single_event( time() + 3600, 'my_hook' );
```

Plugins don't need to change - the adapter handles routing.

## Production Usage

### Systemd (recommended)

```ini
# /etc/systemd/system/wp-cron-v2@.service
[Unit]
Description=WP Cron v2 Worker (%i)
After=network.target mysql.service

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/html
ExecStart=/usr/local/bin/wp cron-v2 worker --queue=%i
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
```

```bash
# Start for different queues
systemctl enable wp-cron-v2@default
systemctl enable wp-cron-v2@emails
systemctl start wp-cron-v2@default
```

### Supervisor

```ini
# /etc/supervisor/conf.d/wp-cron-v2.conf
[program:wp-cron-v2-default]
command=/usr/local/bin/wp cron-v2 worker --queue=default --path=/var/www/html
autostart=true
autorestart=true
user=www-data
numprocs=2
process_name=%(program_name)s_%(process_num)02d
```

### Cron + Maintenance

```bash
# Crontab - process jobs
* * * * * cd /var/www/html && wp cron-v2 work --queue=default --limit=20

# Daily - clean up old and release stuck
0 3 * * * cd /var/www/html && wp cron-v2 cleanup --days=7 && wp cron-v2 release-stale
```

## File Structure

```
wp-cron-v2/
├── wp-cron-v2.php              # Main plugin + helper functions
├── uninstall.php               # Removes tables & options
├── README.md
├── .gitignore
│
├── src/
│   ├── Queue/
│   │   ├── Manager.php         # Queue management
│   │   ├── Scheduler.php       # Recurring tasks
│   │   ├── Batch.php           # Job batching
│   │   ├── Chain.php           # Job chains
│   │   ├── RateLimiter.php     # Rate limiting
│   │   ├── Webhooks.php        # HTTP webhooks
│   │   └── Drivers/
│   │       ├── DriverInterface.php   # Driver interface
│   │       ├── DriverFactory.php     # Driver factory
│   │       ├── DatabaseDriver.php    # MySQL/SQLite driver
│   │       └── RedisDriver.php       # Redis driver
│   │
│   ├── Jobs/
│   │   ├── Job.php             # Abstract base class
│   │   └── ExampleJob.php      # Example
│   │
│   ├── Adapter/
│   │   └── WPCronAdapter.php   # WP-Cron compatibility
│   │
│   ├── Api/
│   │   └── RestController.php  # REST API
│   │
│   ├── Multisite/
│   │   └── NetworkManager.php  # Multisite management
│   │
│   └── Admin/
│       └── AdminPage.php       # Admin panel
│
├── includes/
│   ├── class-activator.php     # Table creation
│   ├── class-deactivator.php
│   └── class-cli-commands.php  # WP-CLI (38 commands)
│
└── assets/
    ├── css/admin.css
    └── js/admin.js
```

## Database Structure

### wp_job_queue

Main queue for all jobs.

| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| job_type | varchar(255) | PHP class name |
| payload | longtext | Serialized job object |
| queue | varchar(100) | Queue name (default, emails, etc.) |
| priority | varchar(20) | low / normal / high |
| attempts | tinyint | Attempt count |
| max_attempts | tinyint | Max attempts |
| available_at | datetime | When job is available for processing |
| created_at | datetime | Created time |
| updated_at | datetime | Updated time |
| status | varchar(20) | queued / running / completed / failed |
| error_message | text | Error message (if failed) |
| batch_id | varchar(36) | Batch ID (if part of batch) |

### wp_job_queue_failed

History of failed jobs.

| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| job_type | varchar(255) | PHP class name |
| payload | longtext | Serialized job object |
| queue | varchar(100) | Queue name |
| exception | longtext | Error message |
| failed_at | datetime | Failed time |

### wp_job_batches

Batch metadata.

| Column | Type | Description |
|--------|------|-------------|
| id | varchar(36) | UUID Primary key |
| name | varchar(255) | Batch name |
| total_jobs | int | Total job count |
| pending_jobs | int | Pending count |
| failed_jobs | int | Failed count |
| options | longtext | Callbacks etc. |
| created_at | datetime | Created time |
| cancelled_at | datetime | Cancelled time |
| finished_at | datetime | Finished time |

## API Reference

### wp_cron_v2()

```php
// Dispatch immediately
wp_cron_v2()->dispatch( Job $job ): int|false

// Dispatch with delay
wp_cron_v2()->later( int $seconds, Job $job ): int|false

// Set queue
wp_cron_v2()->queue( string $name ): Manager

// Set priority
wp_cron_v2()->priority( string $level ): Manager

// Process next job
wp_cron_v2()->process_next_job( string $queue ): bool

// Get statistics
wp_cron_v2()->get_stats( string $queue ): array

// Release stuck jobs
wp_cron_v2()->release_stale_jobs( int $timeout_minutes = 30 ): int

// Clean up old completed jobs
wp_cron_v2()->cleanup_old_jobs( int $days = 7 ): int
```

### wp_cron_v2_scheduler()

```php
// Register recurring task
wp_cron_v2_scheduler()->schedule(
    string $name,
    string $interval,
    Job $job,
    string $queue = 'default'
): bool

// Remove
wp_cron_v2_scheduler()->unschedule( string $name ): bool

// Pause/Resume
wp_cron_v2_scheduler()->pause( string $name ): bool
wp_cron_v2_scheduler()->resume( string $name ): bool

// Get schedules
wp_cron_v2_scheduler()->get_schedules(): array
```

### wp_cron_v2_batch()

```php
// Create batch for multiple jobs
wp_cron_v2_batch( 'import-users' )
    ->add( new ImportUserJob( $user1 ) )
    ->add( new ImportUserJob( $user2 ) )
    ->add( new ImportUserJob( $user3 ) )
    ->onQueue( 'imports' )
    ->then( function( $batch ) {
        // Callback when all complete
    })
    ->catch( function( $batch, $e ) {
        // Callback when any fails
    })
    ->dispatch();

// Get batch statistics
WPCronV2\Queue\Batch::getStats( $batch_id ): array
WPCronV2\Queue\Batch::isFinished( $batch_id ): bool
WPCronV2\Queue\Batch::cancel( $batch_id ): int
```

### wp_cron_v2_chain()

```php
// Execute jobs sequentially (next starts when previous completes)
wp_cron_v2_chain( 'process-order' )
    ->add( new ValidateOrderJob( $order ) )
    ->add( new ProcessPaymentJob( $order ) )
    ->add( new SendConfirmationJob( $order ) )
    ->onQueue( 'orders' )
    ->then( function( $chain ) {
        // Callback when chain completes
    })
    ->catch( function( $chain, $e ) {
        // Callback when any step fails
    })
    ->dispatch();
```

### wp_cron_v2_rate_limiter()

```php
// Check if call is allowed
$allowed = wp_cron_v2_rate_limiter()->attempt(
    'api-calls',     // Key
    10,              // Max attempts
    60               // Per seconds
);

// Check without "consuming"
$allowed = wp_cron_v2_rate_limiter()->check( 'api-calls', 10, 60 );

// How many remaining
$remaining = wp_cron_v2_rate_limiter()->remaining( 'api-calls', 10, 60 );

// When can retry
$seconds = wp_cron_v2_rate_limiter()->availableIn( 'api-calls', 60 );

// Reset rate limit
wp_cron_v2_rate_limiter()->clear( 'api-calls' );
```

### wp_cron_v2_webhooks()

```php
// Register webhook
wp_cron_v2_webhooks()->register(
    string $name,
    string $url,
    array $events = [ 'job.completed', 'job.failed' ],
    array $options = [ 'secret' => '', 'headers' => [], 'enabled' => true ]
): bool

// Unregister webhook
wp_cron_v2_webhooks()->unregister( string $name ): bool

// Enable/disable
wp_cron_v2_webhooks()->setEnabled( string $name, bool $enabled ): bool

// Get webhook
wp_cron_v2_webhooks()->get( string $name ): ?array

// Get all
wp_cron_v2_webhooks()->getAll(): array

// Send manual webhook
wp_cron_v2_webhooks()->dispatch( string $event, array $payload ): void

// Test webhook
wp_cron_v2_webhooks()->test( string $name ): array
```

### REST API

```bash
# Endpoints (requires manage_options capability)
GET  /wp-json/wp-cron-v2/v1/stats
GET  /wp-json/wp-cron-v2/v1/health
GET  /wp-json/wp-cron-v2/v1/jobs
GET  /wp-json/wp-cron-v2/v1/jobs/{id}
DELETE /wp-json/wp-cron-v2/v1/jobs/{id}
POST /wp-json/wp-cron-v2/v1/jobs/{id}/retry
GET  /wp-json/wp-cron-v2/v1/queues
GET  /wp-json/wp-cron-v2/v1/schedules
GET  /wp-json/wp-cron-v2/v1/batches
GET  /wp-json/wp-cron-v2/v1/chains
POST /wp-json/wp-cron-v2/v1/actions/retry-failed
POST /wp-json/wp-cron-v2/v1/actions/flush-failed
POST /wp-json/wp-cron-v2/v1/actions/release-stale
```

## Requirements

- WordPress 6.0+
- PHP 8.0+
- MySQL 5.7+ / MariaDB 10.3+ / SQLite
- WP-CLI 2.0+

## Roadmap

- [x] Job Queue
- [x] Worker daemon
- [x] Priorities
- [x] Retry + exponential backoff
- [x] Timeout + stale recovery
- [x] Scheduled jobs
- [x] Admin UI
- [x] WP-Cron adapter
- [x] WP-CLI (38 commands)
- [x] Health check
- [x] Cleanup & maintenance
- [x] Job batching
- [x] Job chains
- [x] REST API
- [x] Rate limiting
- [x] Webhooks
- [x] Job unique/deduplication
- [x] Redis driver
- [x] Multisite support

**Coming soon:**
- [ ] Amazon SQS driver
- [ ] Horizon-style dashboard
- [ ] Job metrics & analytics

## Development

```bash
# Clone
git clone git@github.com:etuperin99-sys/wp-cron-v2.git

# Symlink to WP
ln -s /path/to/wp-cron-v2 /path/to/wordpress/wp-content/plugins/wp-cron-v2

# Activate
wp plugin activate wp-cron-v2

# Test
wp cron-v2 health
```

## License

GPL-2.0+
