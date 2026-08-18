<?php
/**
 * Edge cases for the two events the SDK now delivers without waiting for cron: the registration and
 * `install` that follow an opt-in, and the `deactivation` that a plugin's own removal would
 * otherwise strand.
 *
 * These paths run on page requests -- an admin_post submit and a deactivation -- which is exactly
 * where a telemetry SDK must not misbehave. Three properties are worth more here than anywhere else
 * in the suite, so this file is organised around them:
 *
 *   1. A refused site transmits NOTHING, by any route. The consent gate has four independent vetoes
 *      (the kill switch, the author's switch, the admin's answer, and a host filter) and the
 *      synchronous flushes must respect every one, not just the admin's answer.
 *   2. A failure never breaks the admin action. Connection errors, rejections and malformed stored
 *      state must leave a redirect happening and a queue intact, not a fatal in wp-admin.
 *   3. Nothing is reported twice. A double-submitted consent form and a repeated activation are
 *      both ordinary, and neither may manufacture an event.
 *
 * @package Codexpert\PluginTracker\Test
 */

namespace Codexpert\PluginTracker\Test;

use Brain\Monkey\Functions;
use Codexpert\PluginTracker\Consent\Gate;
use Codexpert\PluginTracker\Event;
use Codexpert\PluginTracker\Storage\Queue;
use Codexpert\PluginTracker\Tracker;

/**
 * @covers \Codexpert\PluginTracker\Tracker::flush_on_deactivation
 * @covers \Codexpert\PluginTracker\Tracker::handle_consent
 * @covers \Codexpert\PluginTracker\Lifecycle::on_consent
 * @covers \Codexpert\PluginTracker\Lifecycle::on_activate
 */
class ImmediateDeliveryTest extends PluginTrackerTestCase {

	/**
	 * Every request the SDK made during a test, in order.
	 *
	 * @var array<int, array{url: string, body: array|null}>
	 */
	private $requests = array();

	/**
	 * Capture requests and answer them with a chosen status and body.
	 *
	 * Capturing rather than merely counting: several tests below assert on WHICH endpoint was
	 * called and what it carried, and "some request happened" would pass for the wrong reason in
	 * most of them.
	 *
	 * @param int        $status HTTP status to answer with.
	 * @param array|null $body   Decoded body to answer with.
	 * @return void
	 */
	private function capture_requests( $status = 200, $body = null ) {
		$this->requests = array();

		Functions\when( 'wp_remote_post' )->alias(
			function ( $url, $args ) {
				$this->requests[] = array(
					'url'  => (string) $url,
					'body' => json_decode( isset( $args['body'] ) ? $args['body'] : '', true ),
				);
				return array( 'fake' => 'response' );
			}
		);
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( $status );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn(
			json_encode( null === $body ? array( 'success' => true ) : $body )
		);
		Functions\when( 'wp_remote_retrieve_header' )->justReturn( '' );
		Functions\when( 'home_url' )->justReturn( 'https://tracker-sdk-immediate-test.example' );
	}

	/**
	 * Answer every request with a transport-level failure, the way an unreachable host does.
	 *
	 * @return void
	 */
	private function capture_requests_failing() {
		$this->requests = array();

		Functions\when( 'wp_remote_post' )->alias(
			function ( $url, $args ) {
				$this->requests[] = array(
					'url'  => (string) $url,
					'body' => json_decode( isset( $args['body'] ) ? $args['body'] : '', true ),
				);
				return new \PluginTracker_Test_Wp_Error( 'Could not resolve host' );
			}
		);
		Functions\when( 'is_wp_error' )->alias(
			function ( $thing ) {
				return $thing instanceof \PluginTracker_Test_Wp_Error;
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 0 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '' );
		Functions\when( 'wp_remote_retrieve_header' )->justReturn( '' );
		Functions\when( 'home_url' )->justReturn( 'https://tracker-sdk-immediate-test.example' );
	}

	/**
	 * Every event name that reached the wire, across every request, in order.
	 *
	 * @return string[]
	 */
	private function transmitted_events() {
		$events = array();

		foreach ( $this->requests as $request ) {
			if ( isset( $request['body']['events'] ) && is_array( $request['body']['events'] ) ) {
				foreach ( $request['body']['events'] as $event ) {
					$events[] = isset( $event['event'] ) ? $event['event'] : '';
				}
			}
		}

		return $events;
	}

	/**
	 * The admin-post scaffolding handle_consent() runs through, with the redirect turned into a
	 * catchable exception standing in for the exit() that follows it.
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
		Functions\when( 'admin_url' )->justReturn( 'https://tracker-sdk-immediate-test.example/wp-admin/' );
		Functions\when( 'wp_safe_redirect' )->alias(
			function () {
				throw new \RuntimeException( 'redirected' );
			}
		);
	}

	/**
	 * Submit the consent form and assert it reached its redirect, which is the tail of the method.
	 *
	 * Asserted every time rather than swallowed: "the admin action still completes" is one of the
	 * three properties this file exists to defend, and a flush that fataled would be invisible
	 * otherwise.
	 *
	 * @param Tracker $tracker Tracker.
	 * @param string  $choice  'in' or 'out'.
	 * @return void
	 */
	private function submit_consent( Tracker $tracker, $choice ) {
		$_POST['choice'] = $choice;

		try {
			$tracker->handle_consent();
			$this->fail( 'handle_consent() must reach its redirect' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'redirected', $e->getMessage(), 'the admin action must complete, not fatal' );
		} finally {
			unset( $_POST['choice'] );
		}
	}

	/**
	 * Make the `cx_tracker_consent` filter -- the host/site-owner veto, applied last in
	 * Gate::granted() -- refuse.
	 *
	 * @return void
	 */
	private function veto_consent_filter() {
		Functions\when( 'apply_filters' )->alias(
			function ( $hook, $value ) {
				return 'cx_tracker_consent' === $hook ? false : $value;
			}
		);
	}

	/**
	 * A Tracker whose site has opted in and whose author gate is on.
	 *
	 * @param array $overrides Config overrides.
	 * @return array{0: Tracker, 1: Config}
	 */
	private function consented( array $overrides = array() ) {
		$overrides = array_merge( array( 'enabled' => true ), $overrides );
		$config    = $this->make_config( $overrides );

		$this->seed_consent( $config, Gate::POLICY, true );

		return array( Tracker::init( $this->config_args( $overrides ) ), $config );
	}

	// -------------------------------------------------------------------------------------------
	// 1. A refused site transmits nothing, by any route.
	// -------------------------------------------------------------------------------------------

	/**
	 * The host veto outranks the admin's answer, and the synchronous opt-in flush must honour it.
	 *
	 * This is the failure mode that matters most in the whole change. `cx_tracker_consent` is
	 * applied LAST in Gate::granted() precisely so a site owner or host can turn transmission off
	 * over the top of everything else. Before this change the first transmission after an opt-in
	 * was a cron run, which re-checks consent; now it is inline, and inline code that skipped the
	 * re-check would transmit from a site that had been told not to.
	 *
	 * @group launch-gate
	 * @group gate-telemetry-consent
	 */
	public function test_opting_in_transmits_nothing_when_the_host_filter_vetoes() {
		list( $tracker, $config ) = $this->consented();

		$this->capture_requests();
		$this->stub_consent_request();
		$this->veto_consent_filter();

		$this->submit_consent( $tracker, 'in' );

		$this->assertSame( array(), $this->requests, 'a vetoed site must make no request, however the admin answered' );
		$this->assertSame( array(), $this->queue_for( $config )->all(), 'and the queue must be cleared, not held' );
	}

	/**
	 * Same veto, the other new inline path.
	 *
	 * @group launch-gate
	 * @group gate-telemetry-consent
	 */
	public function test_deactivation_transmits_nothing_when_the_host_filter_vetoes() {
		list( $tracker, $config ) = $this->consented();
		$this->seed_queue( $config, 3 );

		$this->capture_requests();
		$this->veto_consent_filter();

		$tracker->flush_on_deactivation();

		$this->assertSame( array(), $this->requests, 'a vetoed site must not transmit on its way out either' );
		$this->assertSame( array(), $this->queue_for( $config )->all(), 'and what was queued under consent must be dropped' );
	}

	/**
	 * The author's own switch, turned off after events were queued while it was on. A consumer who
	 * ships a release with `enabled => false` has withdrawn the project, and the queue on every
	 * site running it must not drain on the next deactivation.
	 *
	 * @group launch-gate
	 * @group gate-telemetry-consent
	 */
	public function test_deactivation_transmits_nothing_when_the_author_gate_is_off() {
		$config = $this->make_config( array( 'enabled' => false ) );
		$this->seed_consent( $config, Gate::POLICY, true );
		$this->seed_queue( $config, 3 );
		$tracker = Tracker::init( $this->config_args( array( 'enabled' => false ) ) );

		$this->capture_requests();

		$tracker->flush_on_deactivation();

		$this->assertSame( array(), $this->requests, 'the author gate is a consent gate too' );
	}

	/**
	 * A site that answered "No thanks" and is then deactivated. Nothing was ever queued, so the
	 * empty-queue guard is what has to hold -- and it must hold before the token check, or the
	 * refusal itself would produce a registration.
	 *
	 * @group launch-gate
	 * @group gate-telemetry-consent
	 */
	public function test_deactivating_a_site_that_refused_makes_no_request() {
		$config = $this->make_config( array( 'enabled' => true ) );
		$this->seed_consent( $config, Gate::POLICY, false );
		$tracker = Tracker::init( $this->config_args( array( 'enabled' => true ) ) );

		$this->capture_requests();

		$tracker->lifecycle()->on_deactivate();

		$this->assertSame( array(), $this->requests, 'a site that said no must not be registered on its way out' );
	}

	// -------------------------------------------------------------------------------------------
	// 2. A failure never breaks the admin action.
	// -------------------------------------------------------------------------------------------

	/**
	 * An unreachable endpoint on opt-in. The administrator's browser must still be redirected and
	 * the events must still be queued -- this is the ordinary case for a site behind a firewall,
	 * and it must look like nothing happened rather than like the plugin broke.
	 */
	public function test_opting_in_survives_an_unreachable_endpoint_and_keeps_the_events_queued() {
		list( $tracker, $config ) = $this->consented();

		$this->capture_requests_failing();
		$this->stub_consent_request();

		$this->submit_consent( $tracker, 'in' );

		$this->assertNotEmpty( $this->requests, 'registration must still have been attempted' );
		$this->assertSame(
			array( Event::INSTALL, Event::ACTIVATE ),
			array_column( $this->queue_for( $config )->all(), 'event' ),
			'an unreachable endpoint must leave the backfilled events queued for the next attempt'
		);
	}

	/**
	 * A backend that answers, and refuses. 503 is the shape of a backend under load or mid-deploy,
	 * and registration failing there must not discard what was queued.
	 */
	public function test_opting_in_keeps_the_events_queued_when_registration_is_refused() {
		list( $tracker, $config ) = $this->consented();

		$this->capture_requests( 503, array( 'success' => false ) );
		$this->stub_consent_request();

		$this->submit_consent( $tracker, 'in' );

		$this->assertCount( 1, $this->requests, 'a failed registration must not be followed by a send' );
		$this->assertStringEndsWith( '/telemetry/register', $this->requests[0]['url'] );
		$this->assertCount( 2, $this->queue_for( $config )->all(), 'the backfilled events must survive a refused registration' );
		$this->assertFalse( $this->stored( $config, 'token' ), 'and no token may be stored' );
	}

	/**
	 * The success path, asserted on the stored credential rather than on the request count: a
	 * registration that answers a token and does not persist it would re-register on every flush
	 * forever, which looks like it works and quietly doubles the traffic.
	 */
	public function test_opting_in_stores_the_token_registration_returns() {
		list( $tracker, $config ) = $this->consented();

		$this->capture_requests(
			200,
			array(
				'success' => true,
				'data'    => array( 'token' => 'ins_tok_from_optin' ),
			)
		);
		$this->stub_consent_request();

		$this->submit_consent( $tracker, 'in' );

		$this->assertSame( 'ins_tok_from_optin', $this->stored( $config, 'token' ) );
	}

	/**
	 * A site that already holds a token -- it consented before, opted out, and opted back in, or
	 * the policy was bumped and re-answered. Registration is not repeated; the events go straight
	 * out. Pinned because an unconditional register on opt-in would issue a second credential for
	 * an install that already had one.
	 */
	public function test_opting_in_with_a_stored_token_does_not_register_again() {
		list( $tracker, $config ) = $this->consented();
		$this->seed_token( $config );

		$this->capture_requests(
			200,
			array(
				'success' => true,
				'data'    => array( 'accepted' => 2 ),
			)
		);
		$this->stub_consent_request();

		$this->submit_consent( $tracker, 'in' );

		$this->assertCount( 1, $this->requests, 'exactly one request: the send' );
		$this->assertStringEndsWith( '/telemetry/events', $this->requests[0]['url'] );
	}

	/**
	 * The same failure on the deactivation path. A plugin being removed while the endpoint is down
	 * must not throw inside wp-admin's deactivation handler, and the event must stay queued for a
	 * reactivation that may still happen.
	 */
	public function test_deactivating_against_an_unreachable_endpoint_keeps_the_event_queued() {
		list( $tracker, $config ) = $this->consented();
		$this->seed_token( $config );

		$this->capture_requests_failing();

		$tracker->lifecycle()->on_deactivate();

		$this->assertNotEmpty( $this->requests, 'delivery must still have been attempted' );
		$this->assertSame(
			array( Event::DEACTIVATION ),
			array_column( $this->queue_for( $config )->all(), 'event' ),
			'a failed send must keep the deactivation queued, not drop it'
		);
	}

	/**
	 * Stored state that is not what it should be. `flush_on_deactivation()` reads a count before it
	 * decides to transmit, and a queue option holding a string -- another plugin's collision, a
	 * partially-written option, a hand-edited row -- must read as empty rather than as one item.
	 */
	public function test_a_corrupt_queue_option_does_not_produce_a_deactivation_request() {
		list( $tracker, $config ) = $this->consented();
		$this->seed_stored( $config, 'queue', 'not-an-array' );

		$this->capture_requests();

		$tracker->flush_on_deactivation();

		$this->assertSame( array(), $this->requests, 'a corrupt queue must not be mistaken for pending work' );
	}

	// -------------------------------------------------------------------------------------------
	// 3. Nothing is reported twice.
	// -------------------------------------------------------------------------------------------

	/**
	 * The consent form submitted twice -- a double click, a reload of the admin-post URL, a browser
	 * retrying a POST. The install must appear once across both submissions.
	 *
	 * Counted on the wire rather than in the queue, because the first submission empties the queue
	 * on success; a queue assertion would pass while the backend received two.
	 */
	public function test_submitting_the_consent_form_twice_reports_the_install_once() {
		list( $tracker ) = $this->consented();

		$this->capture_requests(
			200,
			array(
				'success' => true,
				'data'    => array(
					'token'    => 'ins_tok_double',
					'accepted' => 2,
				),
			)
		);
		$this->stub_consent_request();

		$this->submit_consent( $tracker, 'in' );
		$this->submit_consent( $tracker, 'in' );

		$this->assertSame(
			1,
			count( array_keys( $this->transmitted_events(), Event::INSTALL, true ) ),
			'a resubmitted consent form must not report a second install'
		);
	}

	/**
	 * Activation repeated while the site has never consented. Each one declines, and none of them
	 * may leave a marker behind -- so when consent finally arrives the install is still owed, and
	 * owed exactly once.
	 */
	public function test_repeated_activation_without_consent_still_owes_exactly_one_install() {
		$config    = $this->make_config( array( 'enabled' => true ) );
		$lifecycle = Tracker::init( $this->config_args( array( 'enabled' => true ) ) )->lifecycle();

		$lifecycle->on_activate();
		$lifecycle->on_activate();
		$lifecycle->on_activate();

		$this->assertFalse( $this->stored( $config, 'installed' ), 'nothing was reported, so nothing may be marked' );

		Tracker::reset();
		$this->seed_consent( $config, Gate::POLICY, true );
		$this->capture_requests( 503, array( 'success' => false ) );

		Tracker::init( $this->config_args( array( 'enabled' => true ) ) )->lifecycle()->on_consent();

		$this->assertSame(
			1,
			count( array_keys( array_column( $this->queue_for( $config )->all(), 'event' ), Event::INSTALL, true ) ),
			'three declined activations must still produce exactly one install once consent arrives'
		);
	}

	/**
	 * The author gate alone is enough to withhold the marker. A consumer who ships with
	 * `enabled => false` while a site has opted in is not collecting, and a marker written here
	 * would silently consume the install for whenever they do switch it on.
	 *
	 * @group launch-gate
	 * @group gate-telemetry-consent
	 */
	public function test_the_author_gate_alone_withholds_the_install_marker() {
		$config = $this->make_config( array( 'enabled' => false ) );
		$this->seed_consent( $config, Gate::POLICY, true );

		Tracker::init( $this->config_args( array( 'enabled' => false ) ) )->lifecycle()->on_activate();

		$this->assertFalse( $this->stored( $config, 'installed' ) );
	}

	/**
	 * on_consent() is public and could be reached with the gate still closed -- through a
	 * consumer's own code, or through handle_consent() on a build where the gates disagree. It must
	 * record nothing, and in particular must not set the marker, or the install it was meant to
	 * rescue would be lost by the rescue itself.
	 *
	 * @group launch-gate
	 * @group gate-telemetry-consent
	 */
	public function test_on_consent_without_consent_records_nothing_and_leaves_the_install_owed() {
		$config    = $this->make_config( array( 'enabled' => true ) );
		$lifecycle = Tracker::init( $this->config_args( array( 'enabled' => true ) ) )->lifecycle();

		$lifecycle->on_consent();

		$this->assertSame( array(), $this->queue_for( $config )->all() );
		$this->assertFalse( $this->stored( $config, 'installed' ), 'the backfill must not consume the install it could not report' );
	}

	// -------------------------------------------------------------------------------------------
	// Bounds and ordering.
	// -------------------------------------------------------------------------------------------

	/**
	 * The deactivation flush sends one batch, not the whole queue.
	 *
	 * Deliberate, and the bound has a cost worth pinning: with more than Queue::MAX_BATCH pending,
	 * the events left behind are the NEWEST -- the queue is FIFO -- so the deactivation event
	 * itself can be among them. One blocking request is the entire budget an admin request should
	 * spend on telemetry, and a backlog this deep only happens when the endpoint has been failing
	 * for long enough that a second request would fail too.
	 */
	public function test_deactivation_sends_one_batch_and_leaves_the_rest_queued() {
		list( $tracker, $config ) = $this->consented();
		$this->seed_token( $config );
		$this->seed_queue( $config, Queue::MAX_BATCH + 10 );

		$this->capture_requests(
			200,
			array(
				'success' => true,
				'data'    => array( 'accepted' => Queue::MAX_BATCH ),
			)
		);

		$tracker->flush_on_deactivation();

		$this->assertCount( 1, $this->requests, 'one batch means one request' );
		$this->assertCount( Queue::MAX_BATCH, $this->requests[0]['body']['events'] );
		$this->assertCount( 10, $this->queue_for( $config )->all(), 'the overflow must remain queued' );
	}

	/**
	 * The whole sequence a real first install goes through, in order, with nothing stubbed away
	 * between the steps: activate with no consent, opt in, deactivate.
	 *
	 * Every one of the three events must reach the wire exactly once and in that order. Before this
	 * work the same sequence transmitted nothing at all -- `install` was consumed by a marker
	 * written before consent existed, registration waited on a cron run up to a day out, and
	 * `deactivation` was queued into a plugin that had just removed its own sender.
	 */
	public function test_the_first_install_lifecycle_reports_install_activate_and_deactivation_in_order() {
		$config = $this->make_config( array( 'enabled' => true ) );

		// 1. Activation, before the opt-in notice has ever rendered.
		$lifecycle = Tracker::init( $this->config_args( array( 'enabled' => true ) ) )->lifecycle();
		$lifecycle->on_activate();

		$this->assertFalse( $this->stored( $config, 'installed' ), 'precondition: nothing recorded yet' );

		// 2. The administrator opts in.
		Tracker::reset();
		$this->seed_consent( $config, Gate::POLICY, true );
		$tracker = Tracker::init( $this->config_args( array( 'enabled' => true ) ) );

		$this->capture_requests(
			200,
			array(
				'success' => true,
				'data'    => array(
					'token'    => 'ins_tok_lifecycle',
					'accepted' => 2,
				),
			)
		);
		$this->stub_consent_request();
		$this->submit_consent( $tracker, 'in' );

		// 3. And later removes the plugin.
		$tracker->lifecycle()->on_deactivate();

		$this->assertSame(
			array( Event::INSTALL, Event::ACTIVATE, Event::DEACTIVATION ),
			$this->transmitted_events(),
			'each event exactly once, in the order it happened'
		);
		$this->assertSame( array(), $this->queue_for( $config )->all(), 'and nothing left behind' );
	}
	// -------------------------------------------------------------------------------------------
	// The per-request budget shared across scoped copies.
	// -------------------------------------------------------------------------------------------

	/**
	 * Bulk deactivation is the case this bounds, and it is not a hypothetical.
	 *
	 * wp-admin's "Deactivate" bulk action hands the whole selection to deactivate_plugins(), which
	 * fires each plugin's deactivation hook inside one foreach and writes the shortened
	 * `active_plugins` option only AFTER the loop. Ten selected plugins each bundling this SDK,
	 * each holding events, against an endpoint that is timing out, is ten times up to
	 * Transport::TIMEOUT twice over -- past a default max_execution_time. The request dies inside
	 * the loop, before the option write, and NOTHING is deactivated: a timeout, and a plugins page
	 * where every plugin is still active.
	 *
	 * Losing one copy's telemetry is the cheaper failure by a wide margin.
	 */
	public function test_a_deactivation_flush_is_skipped_once_the_request_budget_is_spent() {
		list( $tracker, $config ) = $this->consented();
		$this->seed_token( $config );
		$this->seed_queue( $config, 2 );

		$this->capture_requests();

		// As if an earlier copy of the SDK, on this same request, had already spent it.
		$GLOBALS[ Tracker::SYNC_BUDGET_GLOBAL ] = Tracker::SYNC_BUDGET;

		$tracker->flush_on_deactivation();

		$this->assertSame( array(), $this->requests, 'the budget is a request-wide bound, not a per-plugin one' );
		$this->assertCount( 2, $this->queue_for( $config )->all(), 'and the events wait, exactly as they did before this flush existed' );
	}

	/**
	 * The first copy through still flushes, and pays into the budget so the next one can see it.
	 *
	 * Asserted as "greater than zero" rather than against a duration: what matters is that the cost
	 * is recorded at all. A flush that spent time without recording it would leave the budget at
	 * zero forever and bound nothing, which is the failure this pair of tests exists to catch.
	 */
	public function test_a_deactivation_flush_records_what_it_spent_against_the_budget() {
		list( $tracker, $config ) = $this->consented();
		$this->seed_token( $config );
		$this->seed_queue( $config, 1 );

		$this->capture_requests(
			200,
			array(
				'success' => true,
				'data'    => array( 'accepted' => 1 ),
			)
		);

		$this->assertArrayNotHasKey( Tracker::SYNC_BUDGET_GLOBAL, $GLOBALS, 'precondition: nothing spent yet' );

		$tracker->flush_on_deactivation();

		$this->assertCount( 1, $this->requests, 'the first copy through must still send' );
		$this->assertArrayHasKey( Tracker::SYNC_BUDGET_GLOBAL, $GLOBALS );
		$this->assertGreaterThan( 0, $GLOBALS[ Tracker::SYNC_BUDGET_GLOBAL ] );
	}

	/**
	 * An exhausted budget must not stop the flush from happening at all -- only from happening on
	 * THIS request. The next request starts clean, which is what makes skipping acceptable: the
	 * events are deferred, not dropped.
	 */
	public function test_the_budget_does_not_survive_into_the_next_request() {
		list( $tracker, $config ) = $this->consented();
		$this->seed_token( $config );
		$this->seed_queue( $config, 1 );

		$this->capture_requests();

		$GLOBALS[ Tracker::SYNC_BUDGET_GLOBAL ] = Tracker::SYNC_BUDGET;
		$tracker->flush_on_deactivation();
		$this->assertSame( array(), $this->requests, 'precondition: skipped while the budget is spent' );

		// A new request, which in production is a new PHP process and therefore a clean global.
		unset( $GLOBALS[ Tracker::SYNC_BUDGET_GLOBAL ] );

		$tracker->flush_on_deactivation();

		$this->assertCount( 1, $this->requests, 'the deferred events must go out on the next opportunity' );
	}

	/**
	 * A global is shared ground, so its contents are not trustworthy.
	 *
	 * The name is prefixed and nothing else should be writing it, but $GLOBALS is exactly where a
	 * collision or a debugging leftover lands, and reading a string where a float was expected must
	 * not turn into a comparison that silently disables the flush forever. Cast, then compare.
	 */
	public function test_a_non_numeric_budget_global_is_treated_as_nothing_spent() {
		list( $tracker, $config ) = $this->consented();
		$this->seed_token( $config );
		$this->seed_queue( $config, 1 );

		$this->capture_requests(
			200,
			array(
				'success' => true,
				'data'    => array( 'accepted' => 1 ),
			)
		);

		$GLOBALS[ Tracker::SYNC_BUDGET_GLOBAL ] = 'not-a-number';

		$tracker->flush_on_deactivation();

		$this->assertCount( 1, $this->requests, 'junk in the global must not read as an exhausted budget' );
	}

	/**
	 * The opt-in flush is deliberately NOT budgeted, and that asymmetry is the point.
	 *
	 * handle_consent() is reached through admin_post_cx_tracker_consent_<slug>, which names one
	 * plugin, so exactly one copy of the SDK can run it per request -- there is nothing to compound
	 * with. Budgeting it would only mean an administrator who happened to deactivate plugins
	 * earlier in the same request got no registration, and there is no such request.
	 */
	public function test_the_opt_in_flush_is_not_subject_to_the_deactivation_budget() {
		list( $tracker ) = $this->consented();

		$this->capture_requests(
			200,
			array(
				'success' => true,
				'data'    => array( 'token' => 'ins_tok_budgeted' ),
			)
		);
		$this->stub_consent_request();

		$GLOBALS[ Tracker::SYNC_BUDGET_GLOBAL ] = Tracker::SYNC_BUDGET;

		$this->submit_consent( $tracker, 'in' );

		$this->assertNotEmpty( $this->requests, 'opting in must register regardless of what the request spent elsewhere' );
	}
}
