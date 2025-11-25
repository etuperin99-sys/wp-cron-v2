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

## Nykytila (v0.1.0)

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
| WP-CLI komennot (18 komentoa) | ✅ |
| Health check | ✅ |
| Cleanup & maintenance | ✅ |
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

### 4. Worker

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

## Kaikki CLI-komennot

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
├── wp-cron-v2.php              # Pääplugin
├── uninstall.php               # Poistaa taulut & optiot
├── README.md
├── .gitignore
│
├── src/
│   ├── Queue/
│   │   ├── Manager.php         # Jonojen hallinta
│   │   └── Scheduler.php       # Toistuvat tehtävät
│   │
│   ├── Jobs/
│   │   ├── Job.php             # Abstrakti base class
│   │   └── ExampleJob.php      # Esimerkki
│   │
│   ├── Adapter/
│   │   └── WPCronAdapter.php   # WP-Cron yhteensopivuus
│   │
│   └── Admin/
│       └── AdminPage.php       # Hallintapaneeli
│
├── includes/
│   ├── class-activator.php     # Taulujen luonti
│   ├── class-deactivator.php
│   └── class-cli-commands.php  # WP-CLI (18 komentoa)
│
└── assets/
    ├── css/admin.css
    └── js/admin.js
```

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
- [x] WP-CLI (18 komentoa)
- [x] Health check
- [x] Cleanup & maintenance
- [ ] Redis-driver
- [ ] Multisite-tuki
- [ ] Job batching
- [ ] Rate limiting
- [ ] Job chains
- [ ] Webhooks
- [ ] REST API

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
