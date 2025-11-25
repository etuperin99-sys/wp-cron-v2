<?php
/**
 * Example Job class
 *
 * @package WPCronV2\Jobs
 */

namespace WPCronV2\Jobs;

/**
 * Example job implementation
 */
class ExampleJob extends Job {

    /**
     * Max attempts
     *
     * @var int
     */
    public int $max_attempts = 3;

    /**
     * Queue
     *
     * @var string
     */
    public string $queue = 'default';

    /**
     * Priority
     *
     * @var string
     */
    public string $priority = 'normal';

    /**
     * Job data
     *
     * @var array
     */
    private array $data;

    /**
     * Constructor
     *
     * @param array $data Data for job to process
     */
    public function __construct( array $data = [] ) {
        $this->data = $data;
    }

    /**
     * Execute job
     *
     * @return void
     */
    public function handle(): void {
        // Execute the actual job work here
        // E.g. send email, process order, sync data...

        if ( empty( $this->data ) ) {
            throw new \Exception( 'No data to process' );
        }

        // Example: log data
        error_log( 'ExampleJob executed: ' . wp_json_encode( $this->data ) );

        // Fire action when job is complete
        do_action( 'wp_cron_v2_example_job_completed', $this->data );
    }

    /**
     * Called when job fails permanently
     *
     * @param \Throwable $exception
     * @return void
     */
    public function failed( \Throwable $exception ): void {
        error_log( 'ExampleJob failed: ' . $exception->getMessage() );

        // Fire action on failure
        do_action( 'wp_cron_v2_example_job_failed', $this->data, $exception );
    }
}
