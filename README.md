# WP Cron v2

Moderni job queue WordPressille - Laravel Horizon -tason taustaprosessijärjestelmä.

## Nykytila (v0.1.0 MVP)

### Mitä plugin tekee nyt

| Ominaisuus | Tila | Kuvaus |
|------------|------|--------|
| Job Queue | ✅ Toimii | Jobit tallennetaan tietokantaan, prioriteetit tuettu |
| Worker | ✅ Toimii | WP-CLI daemon prosessoi jonoa |
| Retry-logiikka | ✅ Toimii | Exponential backoff (2min, 4min, 8min...) |
| Prioriteetit | ✅ Toimii | high, normal, low |
| Lukitus | ✅ Perus | Tietokantapohjainen (status = running) |
| Tilastot | ✅ Toimii | CLI:llä näkee jonon tilan |
| Admin UI | ❌ Puuttuu | Ei vielä hallintapaneelia |
| Redis-driver | ❌ Puuttuu | Vain MySQL/MariaDB |
| WP-Cron adapter | ❌ Puuttuu | Ei vielä yhteensopivuutta vanhojen pluginien kanssa |

### Arkkitehtuuri

```
┌─────────────────────────────────────────────────────────────┐
│  WordPress / Plugin / Teema                                 │
│                                                             │
│  wp_cron_v2()->dispatch( new MyJob() );                     │
└─────────────────────┬───────────────────────────────────────┘
                      │
                      ▼
┌─────────────────────────────────────────────────────────────┐
│  Queue Manager (src/Queue/Manager.php)                      │
│                                                             │
│  - dispatch($job)      → lisää jonoon heti                  │
│  - later($delay, $job) → lisää jonoon viiveellä             │
│  - queue('emails')     → valitse jono                       │
│  - priority('high')    → aseta prioriteetti                 │
└─────────────────────┬───────────────────────────────────────┘
                      │
                      ▼
┌─────────────────────────────────────────────────────────────┐
│  wp_job_queue -taulu (MySQL/SQLite)                         │
│                                                             │
│  id | job_type | payload | queue | priority | status        │
│  1  | SendEmail| {data}  | emails| high     | queued        │
│  2  | Cleanup  | {data}  | default| normal  | running       │
└─────────────────────┬───────────────────────────────────────┘
                      │
                      ▼
┌─────────────────────────────────────────────────────────────┐
│  Worker (wp cron-v2 worker)                                 │
│                                                             │
│  while (true):                                              │
│    1. Hae seuraava job (prioriteetin mukaan)                │
│    2. Lukitse (status = running)                            │
│    3. Suorita $job->handle()                                │
│    4. Merkitse completed / failed                           │
│    5. Jos failed: retry exponential backoffilla             │
└─────────────────────────────────────────────────────────────┘
```

### Tietokantataulu

Plugin luo aktivoinnissa `wp_job_queue` -taulun:

```sql
CREATE TABLE wp_job_queue (
    id              BIGINT UNSIGNED AUTO_INCREMENT,
    job_type        VARCHAR(255),      -- PHP-luokan nimi
    payload         LONGTEXT,          -- Serialisoitu job-objekti
    queue           VARCHAR(100),      -- Jonon nimi (default, emails, woocommerce...)
    priority        VARCHAR(20),       -- low, normal, high
    attempts        TINYINT UNSIGNED,  -- Kuinka monta kertaa yritetty
    max_attempts    TINYINT UNSIGNED,  -- Maksimi yritykset ennen failausta
    available_at    DATETIME,          -- Milloin job voidaan ajaa
    reserved_at     DATETIME,          -- Milloin worker varasi jobin
    created_at      DATETIME,
    updated_at      DATETIME,
    status          VARCHAR(20),       -- queued, running, completed, failed
    error_message   TEXT,              -- Virheilmoitus jos failed
    worker_id       VARCHAR(100),      -- Mikä worker prosessoi
    PRIMARY KEY (id)
);
```

## Käyttö

### 1. Luo Job-luokka

```php
<?php
use WPCronV2\Jobs\Job;

class SendWelcomeEmailJob extends Job {

    public int $max_attempts = 3;      // Yritä 3 kertaa
    public string $queue = 'emails';   // Emails-jono
    public string $priority = 'high';  // Korkea prioriteetti

    private int $user_id;

    public function __construct( int $user_id ) {
        $this->user_id = $user_id;
    }

    /**
     * Tämä metodi suoritetaan kun worker prosessoi jobin
     */
    public function handle(): void {
        $user = get_user_by( 'id', $this->user_id );

        if ( ! $user ) {
            throw new \Exception( 'Käyttäjää ei löydy' );
        }

        wp_mail(
            $user->user_email,
            'Tervetuloa!',
            'Kiitos rekisteröitymisestä...'
        );
    }

    /**
     * Kutsutaan kun kaikki yritykset epäonnistuvat
     */
    public function failed( \Throwable $e ): void {
        error_log( "Welcome email failed for user {$this->user_id}: " . $e->getMessage() );
    }
}
```

### 2. Lähetä job jonoon

```php
// Heti jonoon
wp_cron_v2()->dispatch( new SendWelcomeEmailJob( $user_id ) );

// 1 tunnin päästä
wp_cron_v2()->later( 3600, new SendWelcomeEmailJob( $user_id ) );

// Tiettyyn jonoon tietyllä prioriteetilla
wp_cron_v2()
    ->queue( 'emails' )
    ->priority( 'high' )
    ->dispatch( new SendWelcomeEmailJob( $user_id ) );
```

### 3. Käynnistä worker

```bash
# Perus worker (pyörii ikuisesti)
wp cron-v2 worker --path=/web-develop/wordpress/testi --allow-root

# Tietty jono, lyhyempi sleep
wp cron-v2 worker --queue=emails --sleep=1 --path=/web-develop/wordpress/testi --allow-root

# Prosessoi max 100 jobia ja lopeta
wp cron-v2 worker --max-jobs=100 --path=/web-develop/wordpress/testi --allow-root

# Timeout 5 minuutin päästä
wp cron-v2 worker --timeout=300 --path=/web-develop/wordpress/testi --allow-root
```

## WP-CLI Komennot

### `wp cron-v2 worker`
Käynnistää worker-daemonin joka prosessoi jonoa.

```bash
wp cron-v2 worker [--queue=<queue>] [--sleep=<seconds>] [--max-jobs=<n>] [--timeout=<seconds>]
```

| Optio | Oletus | Kuvaus |
|-------|--------|--------|
| --queue | default | Minkä jonon jobeja prosessoidaan |
| --sleep | 3 | Odotusaika sekunteina kun jono on tyhjä |
| --max-jobs | 0 | Pysäytä kun n jobia prosessoitu (0 = rajaton) |
| --timeout | 0 | Pysäytä n sekunnin päästä (0 = rajaton) |

### `wp cron-v2 stats`
Näyttää jonojen tilastot.

```bash
wp cron-v2 stats [--queue=<queue>] [--format=<format>]

# Esimerkki output:
+------------+--------+---------+-----------+--------+
| queue      | queued | running | completed | failed |
+------------+--------+---------+-----------+--------+
| default    | 5      | 1       | 142       | 3      |
| emails     | 12     | 0       | 89        | 0      |
| woocommerce| 0      | 0       | 567       | 2      |
+------------+--------+---------+-----------+--------+
```

### `wp cron-v2 flush-failed`
Poistaa epäonnistuneet jobit.

```bash
wp cron-v2 flush-failed [--queue=<queue>] [--older-than=<days>]

# Poista kaikki failed
wp cron-v2 flush-failed

# Poista vain yli viikon vanhat
wp cron-v2 flush-failed --older-than=7
```

### `wp cron-v2 retry-failed`
Palauttaa epäonnistuneet jobit jonoon uudelleenyritystä varten.

```bash
wp cron-v2 retry-failed [--queue=<queue>] [--limit=<n>]

# Yritä kaikki failed jobit uudelleen
wp cron-v2 retry-failed

# Yritä max 50 jobia emails-jonosta
wp cron-v2 retry-failed --queue=emails --limit=50
```

## Retry-logiikka

Kun job epäonnistuu (heittää exceptionin), se laitetaan takaisin jonoon exponential backoff -viiveellä:

| Yritys | Viive | Aika |
|--------|-------|------|
| 1. epäonnistuminen | 2 min | 2^1 * 60s |
| 2. epäonnistuminen | 4 min | 2^2 * 60s |
| 3. epäonnistuminen | 8 min | 2^3 * 60s |
| max_attempts ylitetty | → status = failed | |

## Hookit

### Actions

```php
// Kun job lisätään jonoon
do_action( 'wp_cron_v2_job_queued', $job_id, $job );

// Kun job on suoritettu onnistuneesti
do_action( 'wp_cron_v2_job_completed', $job_id, $job );

// Kun job epäonnistuu lopullisesti
do_action( 'wp_cron_v2_job_failed', $job_id, $exception );

// Kun jobia yritetään uudelleen
do_action( 'wp_cron_v2_job_retrying', $job_id, $attempts, $exception );
```

## Tuotantokäyttö

### Systemd service (suositeltu)

```ini
# /etc/systemd/system/wp-cron-v2-worker.service
[Unit]
Description=WP Cron v2 Worker
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/html
ExecStart=/usr/local/bin/wp cron-v2 worker --queue=default
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
```

```bash
systemctl enable wp-cron-v2-worker
systemctl start wp-cron-v2-worker
```

### Supervisor

```ini
# /etc/supervisor/conf.d/wp-cron-v2.conf
[program:wp-cron-v2-worker]
command=/usr/local/bin/wp cron-v2 worker --queue=default --path=/var/www/html
directory=/var/www/html
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
- WP-CLI (worker-prosessille)

## Roadmap

- [ ] Admin UI (monitoring dashboard)
- [ ] Redis-driver vaihtoehtona tietokannalle
- [ ] WP-Cron backwards compatibility adapter
- [ ] Multisite-tuki
- [ ] Job batching
- [ ] Rate limiting
- [ ] Job chains (A → B → C)

## Lisenssi

GPL-2.0+

## Kehitys

```bash
# Repo
git clone git@gitlab.com:etuperin99/wp-cron-v2.git

# Symlink WP:hen
ln -s /path/to/wp-cron-v2 /path/to/wordpress/wp-content/plugins/wp-cron-v2

# Aktivoi
wp plugin activate wp-cron-v2
```
