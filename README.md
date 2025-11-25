# WP Cron v2

Moderni job queue WordPressille - Laravel Horizon -tason taustaprosessijärjestelmä.

## Miksi WP Cron v2?

Nykyinen WP-Cron on WordPressin suurin tekninen pullonkaula:

| Ongelma | WP-Cron | WP Cron v2 |
|---------|---------|------------|
| Riippuu käyttäjäliikenteestä | ❌ | ✅ Erillinen worker |
| Aiheuttaa CPU-piikkejä | ❌ | ✅ Tasainen kuorma |
| Prioriteetit | ❌ | ✅ high/normal/low |
| Retry epäonnistumisessa | ❌ | ✅ Exponential backoff |
| Timeout-käsittely | ❌ | ✅ Stale job recovery |
| Rinnakkaisuus | ❌ | ✅ Useita workereita |
| Monitoring | ❌ | ✅ Admin UI + CLI |
| Health check | ❌ | ✅ `wp cron-v2 health` |
| Skaalautuvuus | ❌ | ✅ Cluster-ready |

## Nykytila (v0.3.0)

| Ominaisuus | Tila |
|------------|------|
| Job Queue (dispatch, later) | ✅ |
| Worker daemon | ✅ |
| Prioriteetit (high/normal/low) | ✅ |
| Retry + exponential backoff | ✅ |
| Timeout + stale job recovery | ✅ |
| Scheduled jobs (toistuvat) | ✅ |
| Admin UI (dashboard, job-lista) | ✅ |
| WP-Cron backwards compatibility | ✅ |
| WP-CLI komennot (31 komentoa) | ✅ |
| Health check | ✅ |
| Cleanup & maintenance | ✅ |
| Job Batching | ✅ |
| Job Chains | ✅ |
| REST API | ✅ |
| Rate Limiting | ✅ |
| Webhooks | ✅ |
| Job Unique/Deduplication | ✅ |
| Redis-driver | ❌ Tulossa |
| Multisite | ❌ Tulossa |

## Asennus

```bash
# Kloonaa
cd /path/to/wordpress/wp-content/plugins
git clone git@gitlab.com:etuperin99/wp-cron-v2.git

# Aktivoi
wp plugin activate wp-cron-v2

# Tarkista tila
wp cron-v2 health

# Käynnistä worker
wp cron-v2 worker --queue=default
```

## Pikaopas

### 1. Luo Job

```php
<?php
use WPCronV2\Jobs\Job;

class SendEmailJob extends Job {

    public int $max_attempts = 3;     // Retry 3 kertaa
    public string $queue = 'emails';  // Jono
    public string $priority = 'high'; // Prioriteetti

    private string $to;

    public function __construct( string $to ) {
        $this->to = $to;
    }

    public function handle(): void {
        wp_mail( $this->to, 'Otsikko', 'Sisältö' );
    }

    public function failed( \Throwable $e ): void {
        error_log( 'Email failed: ' . $e->getMessage() );
    }
}
```

### 2. Dispatch

```php
// Heti
wp_cron_v2()->dispatch( new SendEmailJob( 'test@example.com' ) );

// 1 tunnin päästä
wp_cron_v2()->later( 3600, new SendEmailJob( 'test@example.com' ) );

// Tietty jono + prioriteetti
wp_cron_v2()
    ->queue( 'emails' )
    ->priority( 'high' )
    ->dispatch( new SendEmailJob( 'test@example.com' ) );
```

### 3. Scheduler (toistuvat)

```php
// Päivittäin
wp_cron_v2_scheduler()->schedule(
    'cleanup-logs',
    'daily',
    new CleanupJob()
);

// Intervallit: minutely, every_5_minutes, every_15_minutes,
// every_30_minutes, hourly, twicedaily, daily, weekly
```

### 4. Batch (useita jobeja kerralla)

```php
// Lähetä 100 sähköpostia batchina
$users = get_users( [ 'number' => 100 ] );

$batch = wp_cron_v2_batch( 'newsletter-send' );

foreach ( $users as $user ) {
    $batch->add( new SendNewsletterJob( $user->ID ) );
}

$batch->onQueue( 'emails' )
    ->then( function( $batch ) {
        error_log( 'Newsletter lähetetty kaikille!' );
    })
    ->dispatch();
```

### 5. Chain (peräkkäiset jobit)

```php
// Tilauksen käsittely vaiheittain
wp_cron_v2_chain( 'order-' . $order_id )
    ->add( new ValidateOrderJob( $order_id ) )
    ->add( new ChargePaymentJob( $order_id ) )
    ->add( new UpdateInventoryJob( $order_id ) )
    ->add( new SendConfirmationJob( $order_id ) )
    ->onQueue( 'orders' )
    ->catch( function( $chain, $e ) use ( $order_id ) {
        // Jos jokin vaihe epäonnistuu, peruuta tilaus
        wp_update_post( [ 'ID' => $order_id, 'post_status' => 'cancelled' ] );
    })
    ->dispatch();
```

### 6. Rate Limiting

```php
<?php
use WPCronV2\Jobs\Job;

class ApiCallJob extends Job {

    // Rajoita: max 10 kutsua per minuutti
    public ?array $rate_limit = [
        'max' => 10,
        'per' => 60,  // sekuntia
    ];

    public function handle(): void {
        // API-kutsu joka ei saa kuormittaa liikaa
        wp_remote_get( 'https://api.example.com/data' );
    }
}
```

Rate limit tarkistetaan ennen jobin suoritusta. Jos raja ylittyy, job palautetaan jonoon ja yritetään myöhemmin.

### 7. Unique Jobs (Deduplication)

```php
<?php
use WPCronV2\Jobs\Job;

class ImportUsersJob extends Job {

    // Estä duplikaatit - sama job voi olla jonossa vain kerran
    public ?string $unique_key = 'import-users';

    // Lukituksen kesto sekunteina (oletus: kunnes job valmis)
    public ?int $unique_for = 3600;  // 1 tunti

    public function handle(): void {
        // Pitkä importti joka ei saa käynnistyä uudelleen
    }
}

// Dynaaminen unique key
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
// Rekisteröi webhook
wp_cron_v2_webhooks()->register(
    'slack-alerts',                          // Nimi
    'https://hooks.slack.com/services/xxx',  // URL
    [ 'job.failed', 'health.issue' ],        // Tapahtumat
    [
        'secret' => 'my-secret-key',         // HMAC signature
        'headers' => [ 'X-Custom' => 'value' ],
    ]
);

// Poista webhook
wp_cron_v2_webhooks()->unregister( 'slack-alerts' );

// Ota käyttöön/pois
wp_cron_v2_webhooks()->setEnabled( 'slack-alerts', false );

// Testaa
$result = wp_cron_v2_webhooks()->test( 'slack-alerts' );
```

**Tuetut tapahtumat:**
- `job.completed` - Job suoritettu
- `job.failed` - Job epäonnistui
- `batch.dispatched` - Batch lähetetty
- `chain.completed` - Chain valmis
- `chain.failed` - Chain epäonnistui
- `health.issue` - Terveysongelma havaittu
- `*` - Kaikki tapahtumat

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
Jos secret on asetettu, header `X-WPCronV2-Signature` sisältää HMAC-SHA256 allekirjoituksen.

### 9. Worker

```bash
# Daemon
wp cron-v2 worker --queue=default

# Prosessoi ja lopeta
wp cron-v2 work --limit=100

# Health check
wp cron-v2 health
```

## WP-CLI Komennot

### Worker & Prosessointi

```bash
# Käynnistä worker daemon
wp cron-v2 worker --queue=default --sleep=3

# Prosessoi jobit kerran ja lopeta
wp cron-v2 work --queue=default --limit=100

# Suorita yksittäinen job heti
wp cron-v2 run 123
```

### Tilastot & Monitoring

```bash
# Health check - tarkista ongelmat
wp cron-v2 health

# Nopea tilastokatsaus
wp cron-v2 stats

# Yksityiskohtaiset tilastot
wp cron-v2 info

# Listaa jobit
wp cron-v2 list
wp cron-v2 list --status=failed --queue=emails --limit=50

# Näytä yksittäisen jobin tiedot
wp cron-v2 show 123
```

### Jobin Hallinta

```bash
# Peruuta job (vain queued)
wp cron-v2 cancel 123

# Yritä epäonnistuneet uudelleen
wp cron-v2 retry-failed
wp cron-v2 retry-failed --queue=emails --limit=50

# Poista epäonnistuneet
wp cron-v2 flush-failed
wp cron-v2 flush-failed --older-than=7

# Poista valmiit
wp cron-v2 purge-completed
wp cron-v2 purge-completed --older-than=30
```

### Maintenance

```bash
# Vapauta jumittuneet jobit (timeout)
wp cron-v2 release-stale
wp cron-v2 release-stale --timeout=60

# Siivoa vanhat jobit
wp cron-v2 cleanup --days=7
wp cron-v2 cleanup --days=30 --include-failed
```

### Scheduler

```bash
# Listaa toistuvat tehtävät
wp cron-v2 schedules

# Pysäytä/Jatka
wp cron-v2 pause-schedule cleanup-logs
wp cron-v2 resume-schedule cleanup-logs

# Poista
wp cron-v2 remove-schedule cleanup-logs
```

### Batch & Chain

```bash
# Listaa batchit
wp cron-v2 batches

# Näytä batchin tiedot
wp cron-v2 batch-show abc12345

# Peruuta batch (jonossa olevat jobit)
wp cron-v2 batch-cancel abc12345

# Listaa job chainit
wp cron-v2 chains

# Näytä chainin tiedot ja edistyminen
wp cron-v2 chain-show abc12345

# Poista chain
wp cron-v2 chain-delete abc12345
```

### Webhooks

```bash
# Listaa kaikki webhookit
wp cron-v2 webhooks

# Lisää uusi webhook
wp cron-v2 webhook-add slack-alerts https://hooks.slack.com/xxx --events=job.failed,health.issue --secret=my-secret

# Testaa webhook (lähettää test-eventin)
wp cron-v2 webhook-test slack-alerts

# Ota käyttöön/pois käytöstä
wp cron-v2 webhook-toggle slack-alerts disable
wp cron-v2 webhook-toggle slack-alerts enable

# Poista webhook
wp cron-v2 webhook-remove slack-alerts
```

### Rate Limiting

```bash
# Näytä rate limit tilastot tietylle avaimelle
wp cron-v2 rate-limit-stats email-jobs --max=10 --per=60

# Nollaa rate limit (sallii heti uudet yritykset)
wp cron-v2 rate-limit-reset email-jobs
```

## Kaikki CLI-komennot (31)

| Komento | Kuvaus |
|---------|--------|
| `worker` | Käynnistä worker daemon |
| `work` | Prosessoi jobit kerran |
| `run <id>` | Suorita yksittäinen job |
| `list` | Listaa jobit |
| `show <id>` | Näytä jobin tiedot |
| `cancel <id>` | Peruuta job |
| `stats` | Tilastot |
| `info` | Yksityiskohtaiset tilastot |
| `health` | Health check + ongelmat |
| `retry-failed` | Yritä epäonnistuneet |
| `flush-failed` | Poista epäonnistuneet |
| `purge-completed` | Poista valmiit |
| `release-stale` | Vapauta jumittuneet |
| `cleanup` | Siivoa vanhat jobit |
| `schedules` | Listaa schedulet |
| `pause-schedule` | Pysäytä schedule |
| `resume-schedule` | Jatka schedulea |
| `remove-schedule` | Poista schedule |
| `batches` | Listaa batchit |
| `batch-show <id>` | Näytä batchin tiedot |
| `batch-cancel <id>` | Peruuta batch |
| `chains` | Listaa job chainit |
| `chain-show <id>` | Näytä chainin tiedot |
| `chain-delete <id>` | Poista chain |
| `webhooks` | Listaa webhookit |
| `webhook-add` | Lisää webhook |
| `webhook-remove` | Poista webhook |
| `webhook-test` | Testaa webhook |
| `webhook-toggle` | Käyttöön/pois käytöstä |
| `rate-limit-stats` | Näytä rate limit tilastot |
| `rate-limit-reset` | Nollaa rate limit |

## Admin UI

**WP Admin → Cron v2**

### Dashboard
- Tilastokortit: queued, running, completed, failed
- Auto-refresh 30s välein

### Jono-näkymä
- Job-listaus suodattimilla (status, queue)
- Retry-nappi epäonnistuneille
- Cancel-nappi jonossa oleville

### Asetukset
- WP-Cron Adapter toggle
- Oletusjono
- Maksimi yritykset

## Timeout & Stale Job Recovery

Jos job jää "running" tilaan (esim. worker kaatui), se vapautetaan automaattisesti:

```bash
# Vapauta jobit jotka ovat olleet running > 30 min
wp cron-v2 release-stale --timeout=30
```

Toiminta:
1. Jos yrityksiä jäljellä → palautetaan jonoon retry-logiikalla
2. Jos max_attempts täynnä → merkitään failed

## Retry-logiikka

Exponential backoff kun job epäonnistuu:

| Yritys | Viive | Kaava |
|--------|-------|-------|
| 1 | 2 min | 2¹ × 60s |
| 2 | 4 min | 2² × 60s |
| 3 | 8 min | 2³ × 60s |
| max_attempts | → failed | |

## Hookit

```php
// Job lisätty jonoon
add_action( 'wp_cron_v2_job_queued', function( $job_id, $job ) {
    // ...
}, 10, 2 );

// Job suoritettu onnistuneesti
add_action( 'wp_cron_v2_job_completed', function( $job_id, $job ) {
    // ...
}, 10, 2 );

// Job epäonnistui lopullisesti
add_action( 'wp_cron_v2_job_failed', function( $job_id, $exception ) {
    // Lähetä alert, logita...
}, 10, 2 );

// Job yritetään uudelleen
add_action( 'wp_cron_v2_job_retrying', function( $job_id, $attempts, $exception ) {
    // ...
}, 10, 3 );

// Job timeout (stale)
add_action( 'wp_cron_v2_job_timeout', function( $job_id, $action ) {
    // $action = 'retrying' tai 'failed'
}, 10, 2 );

// Vanhat jobit siivottu
add_action( 'wp_cron_v2_jobs_cleaned', function( $count ) {
    // ...
}, 10, 1 );

// Batch lähetetty
add_action( 'wp_cron_v2_batch_dispatched', function( $batch_id, $job_count ) {
    // ...
}, 10, 2 );

// Batch peruutettu
add_action( 'wp_cron_v2_batch_cancelled', function( $batch_id, $cancelled_count ) {
    // ...
}, 10, 2 );

// Chain käynnistetty
add_action( 'wp_cron_v2_chain_started', function( $chain_id, $job_count ) {
    // ...
}, 10, 2 );

// Chain valmistui
add_action( 'wp_cron_v2_chain_completed', function( $chain_id ) {
    // ...
}, 10, 1 );

// Chain epäonnistui
add_action( 'wp_cron_v2_chain_failed', function( $chain_id, $exception ) {
    // ...
}, 10, 2 );

// Job rate limited (palautettiin jonoon)
add_action( 'wp_cron_v2_job_rate_limited', function( $job_id, $job, $delay ) {
    // $delay = sekunteja kunnes voi yrittää uudelleen
}, 10, 3 );

// Webhook lähetetty
add_action( 'wp_cron_v2_webhook_sent', function( $url, $event, $payload ) {
    // ...
}, 10, 3 );
```

## WP-Cron Yhteensopivuus

Ota käyttöön: **Cron v2 → Asetukset → WP-Cron Adapter**

Kun adapter on päällä:

```php
// Nämä ohjautuvat automaattisesti WP Cron v2 jonoon:
wp_schedule_event( time(), 'hourly', 'my_hook' );
wp_schedule_single_event( time() + 3600, 'my_hook' );
```

Pluginien ei tarvitse muuttua - adapter hoitaa ohjauksen.

## Tuotantokäyttö

### Systemd (suositeltu)

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
# Käynnistä eri jonoille
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
# Crontab - prosessoi jobit
* * * * * cd /var/www/html && wp cron-v2 work --queue=default --limit=20

# Päivittäin - siivoa vanhat ja vapauta jumit
0 3 * * * cd /var/www/html && wp cron-v2 cleanup --days=7 && wp cron-v2 release-stale
```

## Tiedostorakenne

```
wp-cron-v2/
├── wp-cron-v2.php              # Pääplugin + helper-funktiot
├── uninstall.php               # Poistaa taulut & optiot
├── README.md
├── .gitignore
│
├── src/
│   ├── Queue/
│   │   ├── Manager.php         # Jonojen hallinta
│   │   ├── Scheduler.php       # Toistuvat tehtävät
│   │   ├── Batch.php           # Job batching
│   │   ├── Chain.php           # Job chains
│   │   ├── RateLimiter.php     # Rate limiting
│   │   └── Webhooks.php        # HTTP webhooks
│   │
│   ├── Jobs/
│   │   ├── Job.php             # Abstrakti base class
│   │   └── ExampleJob.php      # Esimerkki
│   │
│   ├── Adapter/
│   │   └── WPCronAdapter.php   # WP-Cron yhteensopivuus
│   │
│   ├── Api/
│   │   └── RestController.php  # REST API
│   │
│   └── Admin/
│       └── AdminPage.php       # Hallintapaneeli
│
├── includes/
│   ├── class-activator.php     # Taulujen luonti
│   ├── class-deactivator.php
│   └── class-cli-commands.php  # WP-CLI (31 komentoa)
│
└── assets/
    ├── css/admin.css
    └── js/admin.js
```

## Tietokantarakenne

### wp_job_queue

Pääjono kaikille jobeille.

| Sarake | Tyyppi | Kuvaus |
|--------|--------|--------|
| id | bigint | Primary key |
| job_type | varchar(255) | PHP-luokan nimi |
| payload | longtext | Serialisoitu job-objekti |
| queue | varchar(100) | Jonon nimi (default, emails, etc.) |
| priority | varchar(20) | low / normal / high |
| attempts | tinyint | Yrityskerrat |
| max_attempts | tinyint | Max yritykset |
| available_at | datetime | Milloin job on käsiteltävissä |
| created_at | datetime | Luontiaika |
| updated_at | datetime | Päivitysaika |
| status | varchar(20) | queued / running / completed / failed |
| error_message | text | Virheilmoitus (jos failed) |
| batch_id | varchar(36) | Batch ID (jos osa batchia) |

### wp_job_queue_failed

Historia epäonnistuneista jobeista.

| Sarake | Tyyppi | Kuvaus |
|--------|--------|--------|
| id | bigint | Primary key |
| job_type | varchar(255) | PHP-luokan nimi |
| payload | longtext | Serialisoitu job-objekti |
| queue | varchar(100) | Jonon nimi |
| exception | longtext | Virheviesti |
| failed_at | datetime | Epäonnistumisaika |

### wp_job_batches

Batchien metatiedot.

| Sarake | Tyyppi | Kuvaus |
|--------|--------|--------|
| id | varchar(36) | UUID Primary key |
| name | varchar(255) | Batchin nimi |
| total_jobs | int | Jobien kokonaismäärä |
| pending_jobs | int | Odottavien määrä |
| failed_jobs | int | Epäonnistuneiden määrä |
| options | longtext | Callbackit yms. |
| created_at | datetime | Luontiaika |
| cancelled_at | datetime | Peruutusaika |
| finished_at | datetime | Valmistumisaika |

## API Reference

### wp_cron_v2()

```php
// Dispatch heti
wp_cron_v2()->dispatch( Job $job ): int|false

// Dispatch viiveellä
wp_cron_v2()->later( int $seconds, Job $job ): int|false

// Aseta jono
wp_cron_v2()->queue( string $name ): Manager

// Aseta prioriteetti
wp_cron_v2()->priority( string $level ): Manager

// Prosessoi seuraava job
wp_cron_v2()->process_next_job( string $queue ): bool

// Hae tilastot
wp_cron_v2()->get_stats( string $queue ): array

// Vapauta jumittuneet jobit
wp_cron_v2()->release_stale_jobs( int $timeout_minutes = 30 ): int

// Siivoa vanhat valmiit
wp_cron_v2()->cleanup_old_jobs( int $days = 7 ): int
```

### wp_cron_v2_scheduler()

```php
// Rekisteröi toistuva tehtävä
wp_cron_v2_scheduler()->schedule(
    string $name,
    string $interval,
    Job $job,
    string $queue = 'default'
): bool

// Poista
wp_cron_v2_scheduler()->unschedule( string $name ): bool

// Pysäytä/Jatka
wp_cron_v2_scheduler()->pause( string $name ): bool
wp_cron_v2_scheduler()->resume( string $name ): bool

// Hae schedulet
wp_cron_v2_scheduler()->get_schedules(): array
```

### wp_cron_v2_batch()

```php
// Luo batch useille jobeille
wp_cron_v2_batch( 'import-users' )
    ->add( new ImportUserJob( $user1 ) )
    ->add( new ImportUserJob( $user2 ) )
    ->add( new ImportUserJob( $user3 ) )
    ->onQueue( 'imports' )
    ->then( function( $batch ) {
        // Callback kun kaikki valmiit
    })
    ->catch( function( $batch, $e ) {
        // Callback kun jokin epäonnistuu
    })
    ->dispatch();

// Hae batch tilastot
WPCronV2\Queue\Batch::getStats( $batch_id ): array
WPCronV2\Queue\Batch::isFinished( $batch_id ): bool
WPCronV2\Queue\Batch::cancel( $batch_id ): int
```

### wp_cron_v2_chain()

```php
// Suorita jobit peräkkäin (seuraava alkaa kun edellinen valmis)
wp_cron_v2_chain( 'process-order' )
    ->add( new ValidateOrderJob( $order ) )
    ->add( new ProcessPaymentJob( $order ) )
    ->add( new SendConfirmationJob( $order ) )
    ->onQueue( 'orders' )
    ->then( function( $chain ) {
        // Callback kun ketju valmis
    })
    ->catch( function( $chain, $e ) {
        // Callback kun jokin vaihe epäonnistuu
    })
    ->dispatch();
```

### wp_cron_v2_rate_limiter()

```php
// Tarkista onko kutsu sallittu
$allowed = wp_cron_v2_rate_limiter()->attempt(
    'api-calls',     // Avain
    10,              // Max yritykset
    60               // Per sekuntia
);

// Tarkista ilman "kulutusta"
$allowed = wp_cron_v2_rate_limiter()->check( 'api-calls', 10, 60 );

// Kuinka monta jäljellä
$remaining = wp_cron_v2_rate_limiter()->remaining( 'api-calls', 10, 60 );

// Milloin voi yrittää uudelleen
$seconds = wp_cron_v2_rate_limiter()->availableIn( 'api-calls', 60 );

// Nollaa rate limit
wp_cron_v2_rate_limiter()->clear( 'api-calls' );
```

### wp_cron_v2_webhooks()

```php
// Rekisteröi webhook
wp_cron_v2_webhooks()->register(
    string $name,
    string $url,
    array $events = [ 'job.completed', 'job.failed' ],
    array $options = [ 'secret' => '', 'headers' => [], 'enabled' => true ]
): bool

// Poista webhook
wp_cron_v2_webhooks()->unregister( string $name ): bool

// Ota käyttöön/pois
wp_cron_v2_webhooks()->setEnabled( string $name, bool $enabled ): bool

// Hae webhook
wp_cron_v2_webhooks()->get( string $name ): ?array

// Hae kaikki
wp_cron_v2_webhooks()->getAll(): array

// Lähetä manuaalinen webhook
wp_cron_v2_webhooks()->dispatch( string $event, array $payload ): void

// Testaa webhook
wp_cron_v2_webhooks()->test( string $name ): array
```

### REST API

```bash
# Endpointit (vaatii manage_options -oikeuden)
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

## Vaatimukset

- WordPress 6.0+
- PHP 8.0+
- MySQL 5.7+ / MariaDB 10.3+ / SQLite
- WP-CLI 2.0+

## Roadmap

- [x] Job Queue
- [x] Worker daemon
- [x] Prioriteetit
- [x] Retry + exponential backoff
- [x] Timeout + stale recovery
- [x] Scheduled jobs
- [x] Admin UI
- [x] WP-Cron adapter
- [x] WP-CLI (31 komentoa)
- [x] Health check
- [x] Cleanup & maintenance
- [x] Job batching
- [x] Job chains
- [x] REST API
- [x] Rate limiting
- [x] Webhooks
- [x] Job unique/deduplication
- [ ] Redis-driver
- [ ] Multisite-tuki

## Kehitys

```bash
# Kloonaa
git clone git@gitlab.com:etuperin99/wp-cron-v2.git

# Symlink WP:hen
ln -s /path/to/wp-cron-v2 /path/to/wordpress/wp-content/plugins/wp-cron-v2

# Aktivoi
wp plugin activate wp-cron-v2

# Testaa
wp cron-v2 health
```

## Lisenssi

GPL-2.0+
