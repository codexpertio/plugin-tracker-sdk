<?php
/**
 * Shared test scaffolding: brain/monkey lifecycle, an in-memory options table, and config
 * fixtures.
 *
 * @package Codexpert\PluginTracker\Test
 */

namespace Codexpert\PluginTracker\Test;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Codexpert\PluginTracker\Config;
use Codexpert\PluginTracker\Consent\Gate;
use Codexpert\PluginTracker\Storage\Queue;
use Codexpert\PluginTracker\Tracker;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use PluginTracker_Test_Option_Store;

/**
 * Base test case for the whole suite. No WordPress install is loaded anywhere -- every WP
 * function the SDK touches is either mocked here or stubbed per-test.
 */
abstract class PluginTrackerTestCase extends PHPUnitTestCase {

	protected function setUp(): void {
		parent::setUp();

		Monkey\setUp();
		PluginTracker_Test_Option_Store::reset();
		Tracker::reset();

		$this->stub_options();
		$this->stub_cron();
	}

	protected function tearDown(): void {
		Tracker::reset();
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * get_option/update_option/add_option/delete_option backed by an in-memory array, so Storage\Queue,
	 * Consent\Gate, Storage\Install and Cron\Scheduler can be exercised statefully.
	 *
	 * @return void
	 */
	private function stub_options() {
		Functions\when( 'get_option' )->alias( array( PluginTracker_Test_Option_Store::class, 'get' ) );
		Functions\when( 'update_option' )->alias( array( PluginTracker_Test_Option_Store::class, 'update' ) );
		Functions\when( 'add_option' )->alias( array( PluginTracker_Test_Option_Store::class, 'add' ) );
		Functions\when( 'delete_option' )->alias( array( PluginTracker_Test_Option_Store::class, 'delete' ) );

		// Queue::peek_batch() calls wp_json_encode(); route it to the real encoder rather than
		// leaving it to Brain Monkey's own built-in default (same effect, made explicit).
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		// Default to absent. Transport reads the Retry-After header on every response, so an
		// unstubbed default here fails every transport test rather than only the rate-limit ones.
		// Individual tests override this with Functions\when(...)->justReturn( '120' ).
		Functions\when( 'wp_remote_retrieve_header' )->justReturn( '' );
	}

	/**
	 * Cron\Scheduler::ensure_scheduled() is reached whenever consent has been granted (Tracker::hook()
	 * calls it on every init). Stub the WP-Cron primitives so that path doesn't fatal on an
	 * undefined function; no test in this suite asserts scheduling behaviour itself.
	 *
	 * @return void
	 */
	private function stub_cron() {
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_single_event' )->justReturn( true );
		Functions\when( 'wp_clear_scheduled_hook' )->justReturn( true );

		// Cron\Scheduler::jitter() and Tracker::backoff() both consult wp_rand(). Brain Monkey
		// patches it process-wide the moment ANY test (e.g. Cron/SchedulerTest.php's jitter tests)
		// stubs it, so an unstubbed call in an unrelated test throws MissingFunctionExpectations
		// instead of falling through to the mt_rand() fallback branch those methods carry for a
		// WordPress-less environment -- unlike a function nothing in the suite ever stubs.
		// Defaulted here to a real random value via mt_rand() so tests that don't care about jitter
		// continue to not care about it; a test that DOES care overrides this with its own
		// Functions\when( 'wp_rand' ) to pin the value.
		Functions\when( 'wp_rand' )->alias(
			function ( $min, $max ) {
				return mt_rand( $min, $max );
			}
		);
	}

	/**
	 * Raw Tracker::init()/Config args, valid by default. Endpoint is https, so validity does not
	 * accidentally hinge on the localhost/.test carve-out.
	 *
	 * @param array $overrides Fields to override.
	 * @return array
	 */
	protected function config_args( array $overrides = array() ) {
		return array_merge(
			array(
				'project'  => 'pt_proj_abc123',
				'plugin'   => 'my-plugin',
				'version'  => '1.0.0',
				'file'     => '/tmp/my-plugin/my-plugin.php',
				'enabled'  => false,
				'endpoint' => 'https://tracker.example.test/wp-json/plugin-tracker/v1',
			),
			$overrides
		);
	}

	/**
	 * A valid Config built from config_args().
	 *
	 * @param array $overrides Fields to override.
	 * @return Config
	 */
	protected function make_config( array $overrides = array() ) {
		return new Config( $this->config_args( $overrides ) );
	}

	/**
	 * Write a site opt-in consent record directly into the option store, bypassing
	 * Consent\Gate::opt_in() so tests can control the stored policy version independently.
	 *
	 * @param Config   $config Config.
	 * @param int|null $policy Policy version to record; defaults to the current one.
	 * @param bool     $opted_in Whether opted in.
	 * @return void
	 */
	protected function seed_consent( Config $config, $policy = null, $opted_in = true ) {
		PluginTracker_Test_Option_Store::update(
			$config->option( 'consent' ),
			array(
				'opted_in' => $opted_in,
				'policy'   => null === $policy ? Gate::POLICY : $policy,
				'at'       => 1700000000,
			)
		);
	}

	/**
	 * A fresh Queue reading/writing through the same in-memory option store, for inspecting what
	 * a Tracker instance queued without reaching into its private state.
	 *
	 * @param Config $config Config.
	 * @return Queue
	 */
	protected function queue_for( Config $config ) {
		return new Queue( $config );
	}

	/**
	 * Push $count synthetic, already-valid events directly into the queue -- bypassing
	 * Tracker::track()'s consent/allow-list checks -- for retry/backoff tests that need a batch
	 * to exist without caring how it got there.
	 *
	 * @param Config $config Config.
	 * @param int    $count  How many events to push.
	 * @return void
	 */
	protected function seed_queue( Config $config, $count ) {
		$queue = $this->queue_for( $config );

		for ( $i = 0; $i < $count; $i++ ) {
			$queue->push(
				array(
					'event' => 'install',
					'at'    => 1700000000 + $i,
				)
			);
		}
	}

	/**
	 * Seed a stored install token directly, bypassing Transport::register(), so flush() can be
	 * driven straight into the send() path.
	 *
	 * @param Config $config Config.
	 * @param string $token  Token value.
	 * @return void
	 */
	protected function seed_token( Config $config, $token = 'ins_tok_abc' ) {
		PluginTracker_Test_Option_Store::update( $config->option( 'token' ), $token );
	}

	/**
	 * Seed the retry-attempt counter directly, so a test can start mid-retry-cycle (e.g. "as if"
	 * a batch had already failed a few times) without looping flush() to get there.
	 *
	 * @param Config $config   Config.
	 * @param int    $attempts Attempt count to record.
	 * @return void
	 */
	protected function seed_attempts( Config $config, $attempts ) {
		PluginTracker_Test_Option_Store::update( $config->option( 'attempts' ), $attempts );
	}

	/**
	 * Whatever is currently stored for the given per-consumer option suffix (e.g. 'attempts',
	 * 'dropped', 'token'), read straight from the in-memory store rather than through get_option()
	 * defaulting rules.
	 *
	 * @param Config $config Config.
	 * @param string $suffix Option suffix, as passed to Config::option().
	 * @return mixed
	 */
	/**
	 * The retry-attempt count, read out of the batch-scoped {fp, n} record.
	 *
	 * The counter is deliberately not a bare integer: it is scoped to a batch fingerprint so a
	 * budget cannot leak from one batch to an unrelated one. Tests read through this helper so they
	 * pin the COUNT and not the record's internal shape.
	 *
	 * @param \Codexpert\PluginTracker\Config $config Config.
	 * @return int
	 */
	protected function attempt_count( $config ) {
		$record = $this->stored( $config, 'attempts' );

		return is_array( $record ) && isset( $record['n'] ) ? (int) $record['n'] : 0;
	}

	protected function stored( Config $config, $suffix ) {
		return PluginTracker_Test_Option_Store::get( $config->option( $suffix ) );
	}

	/**
	 * Stub the wp_remote_post()/wp_remote_retrieve_*() chain so Transport::post() resolves to a
	 * chosen status/body/Retry-After header without a real HTTP call. Overrides the
	 * wp_remote_retrieve_header() default set in stub_options().
	 *
	 * @param int        $status      Response status code.
	 * @param array|null $body        Decoded JSON body; null simulates an unparseable body.
	 * @param string     $retry_after Raw Retry-After header value, '' for absent.
	 * @return void
	 */
	protected function stub_remote_response( $status, $body = null, $retry_after = '' ) {
		Functions\when( 'wp_remote_post' )->justReturn( array( 'fake' => 'response' ) );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( $status );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( null === $body ? 'not-json' : json_encode( $body ) );
		Functions\when( 'wp_remote_retrieve_header' )->justReturn( $retry_after );

		// Tracker::envelope() calls Storage\Install::id(), which calls home_url(). Brain Monkey
		// patches this function process-wide the moment ANY test (e.g. Storage/InstallTest)
		// stubs it, so an un-stubbed call in a later test throws MissingFunctionExpectations
		// instead of silently no-op'ing -- unlike a function nothing in the suite ever stubs.
		Functions\when( 'home_url' )->justReturn( 'https://tracker-sdk-flush-test.example' );
	}
}
