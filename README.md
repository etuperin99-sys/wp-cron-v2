# WP Cron v2

Moderni job queue WordPressille - Laravel Horizon -tason taustaprosessijärjestelmä.

## Nykytila (v0.1.0)

| Ominaisuus | Tila | Kuvaus |
|------------|------|--------|
| Job Queue | ✅ Toimii | Jobit tallennetaan tietokantaan, prioriteetit tuettu |
| Worker | ✅ Toimii | WP-CLI daemon prosessoi jonoa |
| Retry-logiikka | ✅ Toimii | Exponential backoff (2min, 4min, 8min...) |
| Prioriteetit | ✅ Toimii | high, normal, low |
| Scheduled Jobs | ✅ Toimii | Toistuvat tehtävät (minutely, hourly, daily...) |
| Admin UI | ✅ Toimii | Hallintapaneeli tilastoilla ja job-listauksella |
| WP-Cron adapter | ✅ Toimii | Ohjaa vanhat wp_schedule_event() kutsut v2:een |
| Lukitus | ✅ Perus | Tietokantapohjainen (status = running) |
| Redis-driver | ❌ Tulossa | Vain MySQL/MariaDB/SQLite |

## Arkkitehtuuri

```
┌─────────────────────────────────────────────────────────────┐
│  WordPress / Plugin / Teema                                 │
│                                                             │
│  wp_cron_v2()->dispatch( new MyJob() );                     │
│  wp_cron_v2_scheduler()->schedule('name', 'hourly', $job);  │
└─────────────────────┬───────────────────────────────────────┘
                      │
                      ▼
┌─────────────────────────────────────────────────────────────┐
│  Queue Manager + Scheduler                                  │
│                                                             │
│  dispatch($job)     → lisää jonoon heti                     │
│  later($delay,$job) → lisää jonoon viiveellä                │
│  schedule()         → toistuva tehtävä                      │
└─────────────────────┬───────────────────────────────────────┘
                      │
                      ▼
┌─────────────────────────────────────────────────────────────┐
│  wp_job_queue -taulu (MySQL/SQLite)                         │
└─────────────────────┬───────────────────────────────────────┘
                      │
                      ▼
┌─────────────────────────────────────────────────────────────┐
│  Worker (wp cron-v2 worker)                                 │
│                                                             │
│  1. Hae seuraava job (prioriteetin mukaan)                  │
│  2. Lukitse → 3. Suorita → 4. Merkitse valmis/failed        │
└─────────────────────────────────────────────────────────────┘
```

## Asennus

```bash
cd /path/to/wordpress/wp-content/plugins
git clone git@gitlab.com:etuperin99/wp-cron-v2.git
wp plugin activate wp-cron-v2
```

## Käyttö

### 1. Luo Job-luokka

```php
<?php
use WPCronV2\Jobs\Job;

class SendEmailJob extends Job {

    public int $max_attempts = 3;
    public string $queue = 'emails';
    public string $priority = 'high';

    private string $to;
    private string $subject;

    public function __construct( string $to, string $subject ) {
        $this->to = $to;
        $this->subject = $subject;
    }

    public function handle(): void {
        wp_mail( $this->to, $this->subject, 'Sisältö...' );
    }

    public function failed( \Throwable $e ): void {
        error_log( "Email failed: " . $e->getMessage() );
    }
}
```

### 2. Lähetä job jonoon

```php
// Heti jonoon
wp_cron_v2()->dispatch( new SendEmailJob( 'test@example.com', 'Otsikko' ) );

// Viiveellä (1 tunti)
wp_cron_v2()->later( 3600, new SendEmailJob( 'test@example.com', 'Otsikko' ) );

// Tietty jono ja prioriteetti
wp_cron_v2()
    ->queue( 'emails' )
    ->priority( 'high' )
    ->dispatch( new SendEmailJob( 'test@example.com', 'Otsikko' ) );
```

### 3. Toistuvat tehtävät (Scheduler)

```php
// Rekisteröi toistuva tehtävä
wp_cron_v2_scheduler()->schedule(
    'cleanup-logs',           // Uniikki nimi
    'daily',                  // Intervalli
    new CleanupLogsJob(),     // Job
    'maintenance'             // Jono
);

// Intervalleja: minutely, every_5_minutes, every_15_minutes,
// every_30_minutes, hourly, twicedaily, daily, weekly

// Pysäytä
wp_cron_v2_scheduler()->pause( 'cleanup-logs' );

// Jatka
wp_cron_v2_scheduler()->resume( 'cleanup-logs' );

// Poista
wp_cron_v2_scheduler()->unschedule( 'cleanup-logs' );
```

### 4. Käynnistä worker

```bash
# Daemon (pyörii ikuisesti)
wp cron-v2 worker --queue=default

# Prosessoi jonossa olevat ja lopeta
wp cron-v2 work --queue=default --limit=100

# Suorita yksittäinen job heti
wp cron-v2 run 123
```

## WP-CLI Komennot

### Jonon hallinta

| Komento | Kuvaus |
|---------|--------|
| `wp cron-v2 worker` | Käynnistä worker daemon |
| `wp cron-v2 work` | Prosessoi jobit kerran |
| `wp cron-v2 stats` | Näytä tilastot |
| `wp cron-v2 info` | Yksityiskohtaiset tilastot |
| `wp cron-v2 list` | Listaa jobit |
| `wp cron-v2 show <id>` | Näytä jobin tiedot |
| `wp cron-v2 run <id>` | Suorita job heti |
| `wp cron-v2 cancel <id>` | Peruuta job |
| `wp cron-v2 retry-failed` | Yritä epäonnistuneet uudelleen |
| `wp cron-v2 flush-failed` | Poista epäonnistuneet |
| `wp cron-v2 purge-completed` | Poista valmiit |

### Scheduler

| Komento | Kuvaus |
|---------|--------|
| `wp cron-v2 schedules` | Listaa ajastetut tehtävät |
| `wp cron-v2 pause-schedule <name>` | Pysäytä schedule |
| `wp cron-v2 resume-schedule <name>` | Jatka schedulea |
| `wp cron-v2 remove-schedule <name>` | Poista schedule |

### Worker optiot

```bash
wp cron-v2 worker [--queue=<queue>] [--sleep=<sec>] [--max-jobs=<n>] [--timeout=<sec>]
```

| Optio | Oletus | Kuvaus |
|-------|--------|--------|
| --queue | default | Jonon nimi |
| --sleep | 3 | Odotusaika kun jono tyhjä |
| --max-jobs | 0 | Max jobit (0 = rajaton) |
| --timeout | 0 | Timeout sekunteina |

## Admin UI

Hallintapaneeli löytyy: **WP Admin → Cron v2**

- Dashboard tilastoilla (queued/running/completed/failed)
- Job-listaus suodattimilla
- Retry ja Cancel toiminnot
- Asetukset (WP-Cron adapter, oletusjono, max yritykset)

## WP-Cron Backwards Compatibility

Ota käyttöön: **Cron v2 → Asetukset → WP-Cron Adapter**

Kun adapter on päällä, vanhat pluginit jotka käyttävät:
```php
wp_schedule_event( time(), 'hourly', 'my_hook' );
wp_schedule_single_event( time() + 3600, 'my_hook' );
```

...ohjautuvat automaattisesti WP Cron v2 jonoon.

## Retry-logiikka

| Yritys | Viive |
|--------|-------|
| 1. epäonnistuminen | 2 min |
| 2. epäonnistuminen | 4 min |
| 3. epäonnistuminen | 8 min |
| max_attempts ylitetty | → failed |

## Hookit

```php
// Job lisätty jonoon
do_action( 'wp_cron_v2_job_queued', $job_id, $job );

// Job suoritettu
do_action( 'wp_cron_v2_job_completed', $job_id, $job );

// Job epäonnistui lopullisesti
do_action( 'wp_cron_v2_job_failed', $job_id, $exception );

// Job yritetään uudelleen
do_action( 'wp_cron_v2_job_retrying', $job_id, $attempts, $exception );
```

## Tuotantokäyttö

### Systemd (suositeltu)

```ini
# /etc/systemd/system/wp-cron-v2.service
[Unit]
Description=WP Cron v2 Worker
After=network.target

[Service]
Type=simple
User=www-data
ExecStart=/usr/local/bin/wp cron-v2 worker --queue=default --path=/var/www/html
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
```

```bash
systemctl enable wp-cron-v2
systemctl start wp-cron-v2
```

### Supervisor

```ini
# /etc/supervisor/conf.d/wp-cron-v2.conf
[program:wp-cron-v2]
command=/usr/local/bin/wp cron-v2 worker --queue=default --path=/var/www/html
autostart=true
autorestart=true
user=www-data
numprocs=2
process_name=%(program_name)s_%(process_num)02d
```

## Vaatimukset

- WordPress 6.0+
- PHP 8.0+
- MySQL 5.7+ / MariaDB 10.3+ / SQLite
- WP-CLI

## Roadmap

- [x] Job Queue
- [x] Worker daemon
- [x] Admin UI
- [x] Scheduled jobs
- [x] WP-Cron adapter
- [ ] Redis-driver
- [ ] Multisite-tuki
- [ ] Job batching
- [ ] Rate limiting
- [ ] Job chains (A → B → C)

## Lisenssi

GPL-2.0+
