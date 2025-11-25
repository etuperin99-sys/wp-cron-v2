<?php
/**
 * Esimerkki Job-luokka
 *
 * @package WPCronV2\Jobs
 */

namespace WPCronV2\Jobs;

/**
 * Esimerkki jobin toteutuksesta
 */
class ExampleJob extends Job {

    /**
     * Maksimi yritykset
     *
     * @var int
     */
    public int $max_attempts = 3;

    /**
     * Jono
     *
     * @var string
     */
    public string $queue = 'default';

    /**
     * Prioriteetti
     *
     * @var string
     */
    public string $priority = 'normal';

    /**
     * Jobin data
     *
     * @var array
     */
    private array $data;

    /**
     * Konstruktori
     *
     * @param array $data Jobin käsittelemä data
     */
    public function __construct( array $data = [] ) {
        $this->data = $data;
    }

    /**
     * Suorita job
     *
     * @return void
     */
    public function handle(): void {
        // Tässä suoritetaan jobin varsinainen työ
        // Esim. lähetä sähköposti, prosessoi tilaus, synkronoi dataa...

        if ( empty( $this->data ) ) {
            throw new \Exception( 'Ei dataa käsiteltäväksi' );
        }

        // Esimerkki: logita data
        error_log( 'ExampleJob suoritettu: ' . wp_json_encode( $this->data ) );

        // Laukaise action kun job on valmis
        do_action( 'wp_cron_v2_example_job_completed', $this->data );
    }

    /**
     * Kutsutaan kun job epäonnistuu lopullisesti
     *
     * @param \Throwable $exception
     * @return void
     */
    public function failed( \Throwable $exception ): void {
        error_log( 'ExampleJob epäonnistui: ' . $exception->getMessage() );

        // Laukaise action epäonnistumisesta
        do_action( 'wp_cron_v2_example_job_failed', $this->data, $exception );
    }
}
