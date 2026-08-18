<?php
/**
 * Tests for the public facade -- principally the double consent gate as observed through
 * track(), the invalid-config no-op path, and the retry/backoff budget flush() enforces around
 * Http\Transport results.
 *
 * @package Codexpert\PluginTracker\Test
 */

namespace Codexpert\PluginTracker\Test;

use Brain\Monkey\Functions;
use Codexpert\PluginTracker\Config;
use Codexpert\PluginTracker\Consent\Gate;
use Codexpert\PluginTracker\Cron\Scheduler;
use Codexpert\PluginTracker\Event;
use Codexpert\PluginTracker\Http\Transport;
use Codexpert\PluginTracker\Storage\Install;
use Codexpert\PluginTracker\Tracker;

/**
 * @covers \Codexpert\PluginTracker\Tracker
 */
class TrackerTest extends PluginTrackerTestCase {

	/**
	 * Gate 1 alone (site opted in) is not enough: the author must also have enabled telemetry.
	 * track() must both return false AND queue nothing -- not just decline to send later.
	 */
	public function test_track_queues_nothing_when_author_has_not_enabled() {
		$config = $this->make_config( array( 'enabled' => false ) );
		$this->seed_consent( $config, Gate::POLICY, true );

		$tracker = Tracker::init( $this->config_args( array( 'enabled' => false ) ) );

		$this->assertFalse( $tracker->track( 'install' ) );
		$this->assertSame( array(), $this->queue_for( $config )->all() );
	}

	/**
	 * Gate 2 alone (author enabled) is not enough: the site admin must also have opted in.
	 *
	 * @group launch-gate
	 * @group gate-telemetry-consent
	 */
	public function test_track_queues_nothing_when_site_has_not_opted_in() {
		$config  = $this->make_config( array( 'enabled' => true ) );
		$tracker = Tracker::init( $this->config_args( array( 'enabled' => true ) ) );

		$this->assertFalse( $tracker->track( 'install' ) );
		$this->assertSame( array(), $this->queue_for( $config )->all() );
	}

	/**
	 * Both gates passing is what actually allows queuing.
	 */
	public function test_track_queues_only_when_both_gates_pass() {
		$config = $this->make_config( array( 'enabled' => true ) );
		$this->seed_consent( $config, Gate::POLICY, true );

		$tracker = Tracker::init( $this->config_args( array( 'enabled' => true ) ) );

		$this->assertTrue( $tracker->track( 'install' ) );

		$queued = $this->queue_for( $config )->all();
		$this->assertCount( 1, $queued );
		$this->assertSame( 'install', $queued[0]['event'] );
	}

	/**
	 * CX_TRACKER_DISABLE is the unconditional kill switch: it overrides BOTH gates passing.
	 * Isolated to its own process since the constant, once defined, persists for the rest of the
	 * PHP process.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_cx_tracker_disable_overrides_both_gates_passing() {
		define( 'CX_TRACKER_DISABLE', true );

		$config = $this->make_config( array( 'enabled' => true ) );
		$this->seed_consent( $config, Gate::POLICY, true );

		$tracker = Tracker::init( $this->config_args( array( 'enabled' => true ) ) );

		$this->assertFalse( $tracker->track( 'install' ) );
		$this->assertSame( array(), $this->queue_for( $config )->all() );
	}

	/**
	 * The event allow-list is closed at the Tracker boundary too: an unknown name is rejected and
	 * nothing is queued, even with full consent.
	 */
	public function test_track_rejects_unknown_event_name_even_with_full_consent() {
		$config = $this->make_config( array( 'enabled' => true ) );
		$this->seed_consent( $config, Gate::POLICY, true );

		$tracker = Tracker::init( $this->config_args( array( 'enabled' => true ) ) );

		$this->assertFalse( $tracker->track( 'uninstall' ) );
		$this->assertSame( array(), $this->queue_for( $config )->all() );
	}

	/**
	 * An event with invalid event-specific props (e.g. a deactivation "note") is rejected and
	 * nothing is queued, even with full consent.
	 */
	public function test_track_rejects_invalid_props_even_with_full_consent() {
		$config = $this->make_config( array( 'enabled' => true ) );
		$this->seed_consent( $config, Gate::POLICY, true );

		$tracker = Tracker::init( $this->config_args( array( 'enabled' => true ) ) );

		$this->assertFalse( $tracker->track( 'deactivation', array( 'note' => 'free text' ) ) );
		$this->assertSame( array(), $this->queue_for( $config )->all() );
	}

	/**
	 * Config invalid means Tracker::init() must no-op with null rather than throwing or building
	 * a half-working instance.
	 */
	public function test_init_returns_null_for_invalid_config() {
		$tracker = Tracker::init( $this->config_args( array( 'project' => 'not-a-valid-project-id' ) ) );

		$this->assertNull( $tracker );
	}

	/**
	 * init() is idempotent per plugin slug: a second call for the same slug returns the same
	 * instance rather than re-registering hooks.
	 */
	public function test_init_returns_the_same_instance_for_the_same_plugin_slug() {
		$args = $this->config_args( array( 'enabled' => true ) );

		$first  = Tracker::init( $args );
		$second = Tracker::init( $args );

		$this->assertSame( $first, $second );
	}

	/**
	 * Tracker::hook() wires Personal_Data::register() in on every init(), regardless of admin
	 * context. Captured here by intercepting add_filter() directly (Brain Monkey's own hooks fake
	 * is a no-op unless configured), then proving the captured callbacks are genuinely the
	 * Personal_Data exporter/eraser -- not merely registered under the right hook name -- by
	 * invoking them and checking their distinctive return shape.
	 */
	public function test_init_registers_the_personal_data_exporter_and_eraser_filters() {
		$captured = array();
		Functions\when( 'add_filter' )->alias(
			function ( $hook, $callback ) use ( &$captured ) {
				$captured[ $hook ][] = $callback;
				return true;
			}
		);

		Tracker::init( $this->config_args( array( 'enabled' => true ) ) );

		$this->assertArrayHasKey(
			'wp_privacy_personal_data_exporters',
			$captured,
			'Tracker::hook() must register a personal-data exporter'
		);
		$this->assertArrayHasKey(
			'wp_privacy_personal_data_erasers',
			$captured,
			'Tracker::hook() must register a personal-data eraser'
		);
		$this->assertCount( 1, $captured['wp_privacy_personal_data_exporters'] );
		$this->assertCount( 1, $captured['wp_privacy_personal_data_erasers'] );

		$slug = 'cx-tracker-my-plugin';

		$exporters = call_user_func( $captured['wp_privacy_personal_data_exporters'][0], array() );
		$this->assertArrayHasKey( $slug, $exporters );
		$this->assertArrayHasKey( 'callback', $exporters[ $slug ] );

		$erasers = call_user_func( $captured['wp_privacy_personal_data_erasers'][0], array() );
		$this->assertArrayHasKey( $slug, $erasers );
		$this->assertArrayHasKey( 'callback', $erasers[ $slug ] );
	}

	/**
	 * handle_consent() is a state-changing admin endpoint reached via admin-post.php. Without a
	 * capability check, any authenticated user -- not just an administrator -- could flip this
	 * site's consent state.
	 *
	 * wp_die() is stubbed to throw a catchable exception rather than exit the test process; that
	 * substitution lives here in the test, not in src/.
	 */
	public function test_handle_consent_rejects_a_user_without_manage_options() {
		$config  = $this->make_config( array( 'enabled' => true ) );
		$tracker = Tracker::init( $this->config_args( array( 'enabled' => true ) ) );

		Functions\when( 'current_user_can' )->justReturn( false );
		// wp_die()'s message argument is evaluated before wp_die() is even called.
		Functions\when( 'esc_html__' )->alias(
			function ( $text ) {
				return $text;
			}
		);
		Functions\when( 'wp_die' )->alias(
			function () {
				throw new \RuntimeException( 'wp_die called' );
			}
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'wp_die called' );

		$tracker->handle_consent();
	}

	/**
	 * Without a nonce check, handle_consent() is a CSRF hole: any page could submit a form (or an
	 * attacker could craft one) to this admin-post.php action and flip consent for a logged-in
	 * admin who never intended it. check_admin_referer() is stubbed to throw a catchable exception
	 * standing in for its real "fail closed" behaviour (which normally calls wp_die() itself).
	 */
	public function test_handle_consent_rejects_a_bad_or_missing_nonce() {
		$config  = $this->make_config( array( 'enabled' => true ) );
		$tracker = Tracker::init( $this->config_args( array( 'enabled' => true ) ) );

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'check_admin_referer' )->alias(
			function () {
				throw new \RuntimeException( 'check_admin_referer failed' );
			}
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'check_admin_referer failed' );

		$tracker->handle_consent();
	}

	/**
	 * handle_consent()'s explicit opt-out path (choice=out) forgets the install salt too, not just
	 * the token -- see the comment above Storage\Install::forget() in Tracker::handle_consent().
	 * Proven here by comparing the install id before and after opting out: since the id is an HMAC
	 * under that salt (Storage\Install::id()), deleting the salt must change the id.
	 *
	 * wp_safe_redirect() is stubbed to throw a catchable exception, the same substitution the two
	 * tests above make for wp_die()/check_admin_referer() -- it stands in for the real exit() that
	 * follows it in Tracker::handle_consent(), which would otherwise terminate the test process.
	 * Every state mutation under test happens before that call, so the exception is caught and
	 * asserted on rather than treated as a failure.
	 *
	 * Deliberate, documented consequence -- not a bug this test is guarding against, but the
	 * accepted trade-off: opting out and back in yields a NEW install id, so a site that cycles
	 * consent counts more than once in install-count metrics. Accepted because leaving a live,
	 * correlatable identifier on a site that explicitly said no is the worse trade.
	 */
	public function test_handle_consent_opt_out_forgets_the_install_salt_and_changes_the_install_id() {
		$config = $this->make_config( array( 'enabled' => true ) );
		$this->seed_consent( $config, Gate::POLICY, true );
		$tracker = Tracker::init( $this->config_args( array( 'enabled' => true ) ) );

		Functions\when( 'home_url' )->justReturn( 'https://tracker-sdk-optout-test.example' );
		$install = new Install( $config );
		$before  = $install->id();

		$this->stub_consent_request();

		$_POST['choice'] = 'out';

		try {
			$tracker->handle_consent();
			$this->fail( 'handle_consent() must still reach its wp_safe_redirect()/exit tail' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'wp_safe_redirect called', $e->getMessage() );
		} finally {
			unset( $_POST['choice'] );
		}

		$after = $install->id();

		$this->assertNotSame( $before, $after, 'opting out must forget the install salt, changing the derived install id' );
	}

	/**
	 * The admin-post scaffolding handle_consent() runs through: capability, nonce, the two
	 * unslash/sanitize passes over $_POST, and the redirect tail.
	 *
	 * wp_safe_redirect() throws a catchable exception, standing in for the real exit() that follows
	 * it -- which would otherwise terminate the test process. Every state mutation under test
	 * happens before that call, so the exception is caught and asserted on rather than treated as a
	 * failure.
	 *
	 * @return void
	 */
	private function stub_consent_request() {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'check_admin_referer' )->justReturn( true );
		Functions\when( 'sanitize_key' )->alias(
			function ( $value ) {
				return $value;
			}
		);
		Functions\when( 'wp_unslash' )->alias(
			function ( $value ) {
				return $value;
			}
		);
		Functions\when( 'wp_get_referer' )->justReturn( '' );
		Functions\when( 'admin_url' )->justReturn( 'https://tracker-sdk-consent-test.example/wp-admin/' );
		Functions\when( 'wp_safe_redirect' )->alias(
			function () {
				throw new \RuntimeException( 'wp_safe_redirect called' );
			}
		);
	}

	/**
	 * Opting in must register and send in that same request, not schedule and hope.
	 *
	 * ensure_scheduled() alone arms a single event jittered across the WHOLE interval, so clicking
	 * "Allow" bought up to a day of silence before the site even obtained a token -- longer on a
	 * low-traffic site, where WP-Cron runs only on incoming requests and may not run at all. The
	 * observed shape of this was a consented site with no stored token, no install record and a
	 * queue that only grew.
	 *
	 * Asserted on the URL, not merely on "a request happened": registration is the specific call
	 * that was missing, and it is what creates the install record.
	 */
	public function test_handle_consent_opt_in_registers_in_the_same_request() {
		$this->make_config( array( 'enabled' => true ) );
		$tracker = Tracker::init( $this->config_args( array( 'enabled' => true ) ) );

		$calls = array();
		Functions\when( 'wp_remote_post' )->alias(
			function ( $url, $args ) use ( &$calls ) {
				$calls[] = array(
					'url'  => $url,
					'body' => json_decode( $args['body'], true ),
				);
				return array( 'fake' => 'response' );
			}
		);
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn(
			json_encode(
				array(
					'success' => true,
					'data'    => array( 'token' => 'ins_tok_fresh' ),
				)
			)
		);
		Functions\when( 'wp_remote_retrieve_header' )->justReturn( '' );
		Functions\when( 'home_url' )->justReturn( 'https://tracker-sdk-optin-test.example' );
		$this->stub_consent_request();

		$_POST['choice'] = 'in';

		try {
			$tracker->handle_consent();
			$this->fail( 'handle_consent() must still reach its wp_safe_redirect()/exit tail' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'wp_safe_redirect called', $e->getMessage() );
		} finally {
			unset( $_POST['choice'] );
		}

		$this->assertNotEmpty( $calls, 'opting in must not leave the first contact to a cron run that may never happen' );
		$this->assertStringEndsWith(
			'/telemetry/register',
			$calls[0]['url'],
			'the first call on opt-in must be registration -- it is what obtains the token and creates the install record'
		);

		// And the backfill rides out on the same opt-in, rather than waiting for an activation
		// that a plugin installed once and left running never has again.
		$events = array();

		foreach ( $calls as $call ) {
			if ( isset( $call['body']['events'] ) ) {
				$events = array_merge( $events, array_column( $call['body']['events'], 'event' ) );
			}
		}

		$this->assertContains(
			Event::INSTALL,
			$events,
			'opting in must report the install the site could not report before it had consent'
		);
	}

	/**
	 * Opting out must still make no request at all. The synchronous flush belongs to the opt-in
	 * branch only; reaching the network on the way to recording "no" would be the one thing a
	 * refusal must never do.
	 *
	 * @group launch-gate
	 * @group gate-telemetry-consent
	 */
	public function test_handle_consent_opt_out_makes_no_request() {
		$config = $this->make_config( array( 'enabled' => true ) );
		$this->seed_consent( $config, Gate::POLICY, true );
		$this->seed_queue( $config, 3 );
		$tracker = Tracker::init( $this->config_args( array( 'enabled' => true ) ) );

		Functions\when( 'home_url' )->justReturn( 'https://tracker-sdk-optout-request-test.example' );
		$this->stub_consent_request();

		Functions\expect( 'wp_remote_post' )->never();

		$_POST['choice'] = 'out';

		try {
			$tracker->handle_consent();
			$this->fail( 'handle_consent() must still reach its wp_safe_redirect()/exit tail' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'wp_safe_redirect called', $e->getMessage() );
		} finally {
			unset( $_POST['choice'] );
		}
	}

	/**
	 * A Tracker instance with both consent gates already passed and a token already stored, so
	 * flush() goes straight into Transport::send() rather than also exercising register().
	 *
	 * @param array $overrides Config overrides.
	 * @return array{0: Tracker, 1: Config}
	 */
	private function tracker_ready_to_send( array $overrides = array() ) {
		$overrides = array_merge( array( 'enabled' => true ), $overrides );
		$config    = $this->make_config( $overrides );

		$this->seed_consent( $config, Gate::POLICY, true );
		$this->seed_token( $config );

		$tracker = Tracker::init( $this->config_args( $overrides ) );

		return array( $tracker, $config );
	}

	/**
	 * flush() has to be public because it is an action callback, so a consumer could otherwise call
	 * it directly from inside a page request -- which is exactly the blocking-HTTP-call-in-someone-
	 * elses-page-load scenario Tracker::is_background_request() exists to prevent. With
	 * wp_doing_cron() false and WP_CLI undefined (neither test double indicates a background
	 * context), flush() must return before ever reaching Transport: zero HTTP requests, and the
	 * queue left exactly as seeded -- not cleared, not sent, not touched at all.
	 */
	public function test_flush_makes_no_requests_and_leaves_the_queue_untouched_outside_a_background_request() {
		list( $tracker, $config ) = $this->tracker_ready_to_send();
		$this->seed_queue( $config, 3 );

		Functions\when( 'wp_doing_cron' )->justReturn( false );

		Functions\expect( 'wp_remote_post' )->never();

		$tracker->flush();

		$this->assertCount(
			3,
			$this->queue_for( $config )->all(),
			'flush() must leave the queue exactly as seeded outside a background request'
		);
	}

	/**
	 * $force is the documented escape hatch from that same guard -- for tests, and for a consumer
	 * who deliberately wants a synchronous flush. Same non-background setup as immediately above
	 * (wp_doing_cron() false, WP_CLI undefined), but flush( true ) must bypass the guard and
	 * actually proceed to send, proven here by the queue being cleared on a successful send.
	 */
	public function test_flush_sends_when_forced_even_outside_a_background_request() {
		list( $tracker, $config ) = $this->tracker_ready_to_send();
		$this->seed_queue( $config, 2 );

		Functions\when( 'wp_doing_cron' )->justReturn( false );
		$this->stub_remote_response(
			200,
			array(
				'success' => true,
				'data'    => array(
					'accepted' => 2,
					'rejected' => 0,
				),
			)
		);

		$tracker->flush( true );

		$this->assertCount(
			0,
			$this->queue_for( $config )->all(),
			'flush( true ) must bypass the background-request guard and actually send'
		);
	}

	/**
	 * flush_on_deactivation() is that escape hatch's one real caller, and its whole reason for
	 * existing is that the deactivation request IS a page request -- the guard tested immediately
	 * above would otherwise stop it. wp_doing_cron() false and WP_CLI undefined here, exactly as
	 * on a real deactivation, and the send must still happen.
	 */
	public function test_flush_on_deactivation_sends_from_a_page_request() {
		list( $tracker, $config ) = $this->tracker_ready_to_send();
		$this->seed_queue( $config, 2 );

		Functions\when( 'wp_doing_cron' )->justReturn( false );
		$this->stub_remote_response(
			200,
			array(
				'success' => true,
				'data'    => array(
					'accepted' => 2,
					'rejected' => 0,
				),
			)
		);

		$tracker->flush_on_deactivation();

		$this->assertCount(
			0,
			$this->queue_for( $config )->all(),
			'flush_on_deactivation() must send from the page request that is deactivating the plugin'
		);
	}

	/**
	 * An empty queue must cost nothing on the way out.
	 *
	 * flush() obtains a token before it looks at the batch, so handing it an empty queue would
	 * still make a blocking register() call -- adding latency to an admin action for no payload,
	 * and creating an install record for a site that is leaving. No token is seeded here, so a
	 * missing guard shows up as a registration request rather than as nothing at all.
	 *
	 * This is the common case, not an edge one: on a healthy site cron drained the queue hours
	 * before anyone clicked Deactivate.
	 */
	public function test_flush_on_deactivation_makes_no_request_when_the_queue_is_empty() {
		$config = $this->make_config( array( 'enabled' => true ) );
		$this->seed_consent( $config, Gate::POLICY, true );
		$tracker = Tracker::init( $this->config_args( array( 'enabled' => true ) ) );

		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Functions\expect( 'wp_remote_post' )->never();

		$tracker->flush_on_deactivation();
	}

	/**
	 * flush()'s consent re-check is the mechanism by which an admin's opt-out AFTER events were
	 * already queued (with consent, at the time) wins over what is already sitting in the queue --
	 * see docs/CONSENT.md. All three consequences of losing consent must hold together: zero HTTP
	 * requests, the queue cleared, and the scheduled flush cancelled. A mutation deleting this
	 * re-check would instead try to send the stale batch.
	 */
	public function test_flush_makes_no_requests_and_clears_queue_and_unschedules_when_consent_is_revoked_after_queuing() {
		list( $tracker, $config ) = $this->tracker_ready_to_send();
		$this->seed_queue( $config, 3 );

		$this->assertNotSame( array(), $this->queue_for( $config )->all(), 'precondition: events must be queued while consent is granted' );

		// The admin opts out AFTER the events above were already queued under consent.
		$this->seed_consent( $config, Gate::POLICY, false );

		Functions\expect( 'wp_remote_post' )->never();

		$cleared = array();
		Functions\when( 'wp_clear_scheduled_hook' )->alias(
			function ( $hook ) use ( &$cleared ) {
				$cleared[] = $hook;
				return true;
			}
		);

		$tracker->flush();

		$this->assertSame( array(), $this->queue_for( $config )->all(), 'flush() must clear the queue once consent is revoked' );
		$this->assertSame(
			array( ( new Scheduler( $config ) )->hook() ),
			$cleared,
			'flush() must unschedule the flush job once consent is revoked'
		);
	}

	/**
	 * The wire envelope's `schema` field must actually carry Event::SCHEMA's value end to end --
	 * distinct from a contract test on the constant itself, which cannot catch envelope()/register()
	 * hardcoding or dropping the field on the way to the wire.
	 */
	public function test_flush_transmits_the_frozen_schema_version_on_the_wire() {
		list( $tracker, $config ) = $this->tracker_ready_to_send();
		$this->seed_queue( $config, 1 );

		$captured_body = null;
		Functions\when( 'wp_remote_post' )->alias(
			function ( $url, $args ) use ( &$captured_body ) {
				$captured_body = json_decode( $args['body'], true );
				return array( 'fake' => 'response' );
			}
		);
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn(
			json_encode(
				array(
					'success' => true,
					'data'    => array( 'accepted' => 1 ),
				)
			)
		);
		Functions\when( 'home_url' )->justReturn( 'https://tracker-sdk-schema-test.example' );

		$tracker->flush();

		$this->assertIsArray( $captured_body, 'precondition: flush() must have sent a request' );
		$this->assertSame( 1, $captured_body['schema'], 'the wire envelope must carry the frozen Event::SCHEMA contract value' );
	}

	/**
	 * 1. A retryable failure (here: HTTP 500 with an unparseable body -- Transport::RESULT_RETRY)
	 * keeps the whole batch queued -- flush() must not clear it the way a success or a permanent
	 * failure would -- and it must record one attempt.
	 */
	public function test_retryable_failure_keeps_the_batch_queued() {
		list( $tracker, $config ) = $this->tracker_ready_to_send();
		$this->seed_queue( $config, 3 );
		$this->stub_remote_response( 500, null );

		$tracker->flush();

		$this->assertCount( 3, $this->queue_for( $config )->all(), 'a retryable failure must not drop any queued event' );
		$this->assertSame( 1, $this->attempt_count( $config ) );
	}

	/**
	 * 1 (continued). The reschedule after a retryable failure is a short backoff, not the full
	 * (86400s) interval -- otherwise a transient blip would cost the site a full day before the
	 * next attempt. After exactly one failed attempt the backoff window is
	 * [1, RETRY_BASE] = [1, 60], well under the daily interval, so asserting that tight range also
	 * proves it is not the full-interval reschedule.
	 */
	public function test_retryable_failure_schedules_a_short_backoff_not_a_full_interval() {
		list( $tracker, $config ) = $this->tracker_ready_to_send();
		$this->seed_queue( $config, 2 );
		$this->stub_remote_response( 500, null );

		$captured = array();
		Functions\when( 'wp_schedule_single_event' )->alias(
			function ( $timestamp, $hook ) use ( &$captured ) {
				$captured[] = $timestamp;
				return true;
			}
		);

		$before = time();
		$tracker->flush();
		$after = time();

		$this->assertCount( 1, $captured, 'exactly one reschedule must happen per flush()' );
		$this->assertGreaterThanOrEqual( $before + 1, $captured[0] );
		$this->assertLessThanOrEqual( $after + Tracker::RETRY_BASE, $captured[0] );
	}

	/**
	 * 2. The retry budget is bounded: fewer than MAX_ATTEMPTS consecutive retryable failures must
	 * NOT drop the batch or touch the dropped-events counter.
	 */
	public function test_retry_budget_does_not_drop_before_max_attempts_is_reached() {
		list( $tracker, $config ) = $this->tracker_ready_to_send();
		$this->seed_queue( $config, 4 );
		$this->stub_remote_response( 500, null );

		for ( $i = 1; $i < Tracker::MAX_ATTEMPTS; $i++ ) {
			$tracker->flush();

			$this->assertCount( 4, $this->queue_for( $config )->all(), "batch must survive failure #{$i}" );
			$this->assertSame( 0, (int) $this->stored( $config, 'dropped' ), 'dropped counter must stay at 0 before attempt #' . Tracker::MAX_ATTEMPTS );
			$this->assertSame( $i, $this->attempt_count( $config ) );
		}
	}

	/**
	 * 2 (continued). Exactly on the MAX_ATTEMPTS-th consecutive retryable failure, the batch is
	 * dropped and the dropped-events counter is incremented by precisely the batch size -- not
	 * before (covered above) and not by some other amount.
	 */
	public function test_retry_budget_drops_the_batch_on_the_max_attempts_failure() {
		list( $tracker, $config ) = $this->tracker_ready_to_send();
		$this->seed_queue( $config, 5 );
		$this->stub_remote_response( 500, null );

		for ( $i = 1; $i < Tracker::MAX_ATTEMPTS; $i++ ) {
			$tracker->flush();
		}

		$this->assertCount( 5, $this->queue_for( $config )->all(), 'precondition: batch must still be intact one short of the budget' );

		$tracker->flush();

		$this->assertCount( 0, $this->queue_for( $config )->all(), 'the batch must be dropped on the MAX_ATTEMPTS-th failure' );
		$this->assertSame( 5, (int) $this->stored( $config, 'dropped' ), 'the dropped counter must equal exactly the batch size' );
		$this->assertSame( 0, $this->attempt_count( $config ), 'attempts must reset once the batch is dropped' );
	}

	/**
	 * 3. A success resets the attempt counter. Simulated here by seeding a non-zero attempt count
	 * (as if a prior batch had already failed a few times), then succeeding -- the counter must go
	 * back to zero rather than carrying forward into whatever comes next.
	 */
	public function test_success_resets_the_attempt_counter() {
		list( $tracker, $config ) = $this->tracker_ready_to_send();
		$this->seed_queue( $config, 2 );
		$this->seed_attempts( $config, 3 );
		$this->stub_remote_response(
			200,
			array(
				'success' => true,
				'data'    => array(
					'accepted' => 2,
					'rejected' => 0,
				),
			)
		);

		$tracker->flush();

		$this->assertSame( 0, $this->attempt_count( $config ) );
		$this->assertCount( 0, $this->queue_for( $config )->all() );
	}

	/**
	 * 3 (continued). Because the reset happened, a later, unrelated batch that starts failing gets
	 * the FULL retry budget rather than inheriting the exhausted-looking count from before the
	 * success. Proven here by driving MAX_ATTEMPTS - 1 failures after the reset and confirming the
	 * new batch survives all of them -- if the old count of 3 had leaked through, it would already
	 * have been dropped after only MAX_ATTEMPTS - 3 failures.
	 */
	public function test_success_reset_gives_the_next_batch_a_full_retry_budget() {
		list( $tracker, $config ) = $this->tracker_ready_to_send();

		// First batch: seed a near-exhausted attempt count, then succeed -- resetting it.
		$this->seed_queue( $config, 1 );
		$this->seed_attempts( $config, 3 );
		$this->stub_remote_response(
			200,
			array(
				'success' => true,
				'data'    => array(
					'accepted' => 1,
					'rejected' => 0,
				),
			)
		);
		$tracker->flush();

		$this->assertSame( 0, $this->attempt_count( $config ), 'precondition: the counter must be reset' );

		// Second, unrelated batch: fail it MAX_ATTEMPTS - 1 times. If the reset had not happened,
		// carrying forward attempts=3 would drop this batch after only MAX_ATTEMPTS - 3 failures.
		$this->seed_queue( $config, 6 );
		$this->stub_remote_response( 500, null );

		for ( $i = 1; $i < Tracker::MAX_ATTEMPTS; $i++ ) {
			$tracker->flush();
		}

		$this->assertCount( 6, $this->queue_for( $config )->all(), 'the new batch must have received a full, unshared retry budget' );
	}

	/**
	 * 4. An auth failure (401/403) must NOT consume the retry budget: the attempt counter is left
	 * exactly as it was, the queue is untouched, and only the token is discarded. This is
	 * deliberate -- see Tracker::apply() -- a credential rotation must not destroy valid queued
	 * events nor count against the same budget a delivery problem would.
	 */
	public function test_auth_failure_does_not_consume_the_retry_budget() {
		list( $tracker, $config ) = $this->tracker_ready_to_send();
		$this->seed_queue( $config, 3 );
		$this->seed_attempts( $config, 3 );
		$this->stub_remote_response(
			401,
			array(
				'success' => false,
				'data'    => array( 'message' => 'invalid token' ),
			)
		);

		$before_attempts = $this->stored( $config, 'attempts' );
		$tracker->flush();

		$this->assertSame(
			$before_attempts,
			$this->stored( $config, 'attempts' ),
			'the attempt record must be untouched by an auth failure'
		);
		$this->assertCount( 3, $this->queue_for( $config )->all(), 'the batch must survive an auth failure intact' );
		$this->assertFalse( $this->stored( $config, 'token' ), 'the dead token must be discarded' );
	}

	/**
	 * 5. A permanent failure (e.g. {"success":false} with 200 -- a malformed batch the server will
	 * never accept) drops the batch on the very first failure, without consuming the retry budget.
	 *
	 * The dropped-events counter IS incremented. Both drop paths -- budget exhaustion and permanent
	 * rejection -- are events that existed and will never arrive, and the counter exists to answer
	 * "how much did we lose". Counting only budget exhaustion would make a server rejecting
	 * malformed batches look like no loss at all.
	 */
	public function test_permanent_failure_drops_the_batch_immediately_and_counts_it() {
		list( $tracker, $config ) = $this->tracker_ready_to_send();
		$this->seed_queue( $config, 4 );
		$this->stub_remote_response(
			200,
			array(
				'success' => false,
				'data'    => array( 'message' => 'malformed batch' ),
			)
		);

		$tracker->flush();

		$this->assertCount( 0, $this->queue_for( $config )->all(), 'a permanent failure must drop the batch on the first attempt' );
		$this->assertSame( 0, $this->attempt_count( $config ), 'a permanent failure must not consume the retry budget' );
		$this->assertSame( 4, (int) $this->stored( $config, 'dropped' ), 'dropped events must be counted however they were dropped' );
	}

	/**
	 * 7. backoff() is private, so it is observed here through Scheduler::reschedule_after(): a
	 * server-supplied Retry-After far above RETRY_CAP (999999s) must be clamped to exactly
	 * RETRY_CAP, not honoured verbatim -- otherwise one bad header could park the queue for days.
	 * This path is deterministic (no jitter is applied once retry_after > 0 -- see
	 * Tracker::backoff()), so the assertion can be exact rather than a range.
	 */
	public function test_retry_after_far_above_cap_is_clamped_to_retry_cap() {
		list( $tracker, $config ) = $this->tracker_ready_to_send();
		$this->seed_queue( $config, 1 );
		// 429 (RESULT_RATE) is required for Transport::send() to surface retry_after at all.
		$this->stub_remote_response(
			429,
			array(
				'code'    => 'rest_rate_limited',
				'message' => 'slow down',
				'data'    => array( 'status' => 429 ),
			),
			'999999'
		);

		$captured = array();
		Functions\when( 'wp_schedule_single_event' )->alias(
			function ( $timestamp, $hook ) use ( &$captured ) {
				$captured[] = $timestamp;
				return true;
			}
		);

		$before = time();
		$tracker->flush();
		$after = time();

		$this->assertCount( 1, $captured );
		$this->assertGreaterThanOrEqual( $before + Tracker::RETRY_CAP, $captured[0] );
		$this->assertLessThanOrEqual( $after + Tracker::RETRY_CAP, $captured[0] );
	}

	/**
	 * 7 (continued). The general invariant, independent of the exact-clamp case above: whatever
	 * backoff() computes, Scheduler::reschedule_after() must always be armed within [1, RETRY_CAP]
	 * seconds from now. Exercised via an ordinary (jittered) retryable failure rather than a
	 * Retry-After header.
	 */
	public function test_backoff_reschedule_is_always_within_one_second_and_retry_cap() {
		list( $tracker, $config ) = $this->tracker_ready_to_send();
		$this->seed_queue( $config, 1 );
		$this->stub_remote_response( 500, null );

		$captured = array();
		Functions\when( 'wp_schedule_single_event' )->alias(
			function ( $timestamp, $hook ) use ( &$captured ) {
				$captured[] = $timestamp;
				return true;
			}
		);

		$before = time();
		$tracker->flush();
		$after = time();

		$this->assertCount( 1, $captured );
		$this->assertGreaterThanOrEqual( $before + 1, $captured[0] );
		$this->assertLessThanOrEqual( $after + Tracker::RETRY_CAP, $captured[0] );
	}
}
