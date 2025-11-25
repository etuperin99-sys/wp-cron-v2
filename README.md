# WP Cron v2

Moderni job queue WordPressille - Laravel Horizon -tason taustaprosessijärjestelmä.

## Ominaisuudet

- FIFO tai prioriteettipohjainen jonotus
- Retry-logiikka exponential backoffilla
- Prioriteetit (high, normal, low)
- Distributed locking
- WP-CLI worker daemon
- Monitoring ja tilastot

## Asennus

1. Lataa plugin `wp-content/plugins/` -kansioon
2. Aktivoi plugin WordPress-hallinnasta
3. Plugin luo tarvittavat tietokantataulut automaattisesti

## Käyttö

### Jobin luominen

```php
use WPCronV2\Jobs\Job;

class SendEmailJob extends Job {

    public int $max_attempts = 3;
    public string $queue = 'emails';
    public string $priority = 'high';

    private string $email;
    private string $subject;

    public function __construct( string $email, string $subject ) {
        $this->email = $email;
        $this->subject = $subject;
    }

    public function handle(): void {
        wp_mail( $this->email, $this->subject, 'Sisältö...' );
    }

    public function failed( \Throwable $e ): void {
        error_log( "Email lähetys epäonnistui: " . $e->getMessage() );
    }
}
```

### Jobin lähettäminen jonoon

```php
// Välitön lähetys
wp_cron_v2()->dispatch( new SendEmailJob( 'test@example.com', 'Otsikko' ) );

// Viivästetty lähetys (1 tunti)
wp_cron_v2()->later( 3600, new SendEmailJob( 'test@example.com', 'Otsikko' ) );

// Tiettyyn jonoon korkealla prioriteetilla
wp_cron_v2()
    ->queue( 'emails' )
    ->priority( 'high' )
    ->dispatch( new SendEmailJob( 'test@example.com', 'Otsikko' ) );
```

## WP-CLI Komennot

```bash
# Käynnistä worker
wp cron-v2 worker --queue=default --sleep=3

# Näytä tilastot
wp cron-v2 stats
wp cron-v2 stats --queue=emails --format=json

# Tyhjennä epäonnistuneet
wp cron-v2 flush-failed --older-than=7

# Yritä epäonnistuneita uudelleen
wp cron-v2 retry-failed --queue=emails --limit=50
```

## Tietokantarakenne

### wp_job_queue

| Sarake | Tyyppi | Kuvaus |
|--------|--------|--------|
| id | bigint | Automaattinen ID |
| job_type | varchar | PHP-luokan nimi |
| payload | longtext | Serialisoitu job |
| queue | varchar | Jonon nimi |
| priority | varchar | low/normal/high |
| attempts | tinyint | Yritysten määrä |
| max_attempts | tinyint | Maksimi yritykset |
| available_at | datetime | Milloin job on ajettavissa |
| status | varchar | queued/running/failed/completed |

## Vaatimukset

- WordPress 6.0+
- PHP 8.0+
- MySQL 5.7+ / MariaDB 10.3+

## Lisenssi

GPL-2.0+
