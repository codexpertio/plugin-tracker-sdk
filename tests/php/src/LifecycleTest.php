<?php
/**
 * Tests for the SDK's own lifecycle listeners -- activation, deactivation and init -- driven
 * through a real Tracker so the consent gate and queue exercised are the real ones, not stand-ins.
 *
 * @package Codexpert\PluginTracker\Test
 */

namespace Codexpert\PluginTracker\Test;

use Brain\Monkey\Functions;
use Codexpert\PluginTracker\Config;
use Codexpert\PluginTracker\Consent\Gate;
use Codexpert\PluginTracker\Event;
use Codexpert\PluginTracker\Tracker;

/**
 * @covers \Codexpert\PluginTracker\Lifecycle
 */
class LifecycleTest extends PluginTrackerTestCase {

	/**
	 * A real Tracker/Lifecycle pair with both consent gates already passed, so track() actually
	 * queues rather than silently declining. Mirrors TrackerTest::tracker_ready_to_send().
	 *
	 * @param array $overrides Config overrides.
	 * @return array{0: Tracker, 1: Config, 2: \Codexpert\PluginTracker\Lifecycle}
	 */
	private function ready_lifecycle( array $overrides = array() ) {
		$overrides = array_merge( array( 'enabled' => true ), $overrides );
		$config    = $this->make_config( $overrides );

		$this->seed_consent( $config, Gate::POLICY, true );

		$tracker = Tracker::init( $this->config_args( $overrides ) );

		return array( $tracker, $config, $tracker->lifecycle() );
	}

	/**
	 * Filter a queue dump down to one event name, preserving order.
	 *
	 * @param array  $queued Queue::all() output.
	 * @param string $name   Event name.
	 * @return array
	 */
	private function events_named( array $queued, $name ) {
		return array_values(
			array_filter(
				$queued,
				function ( $event ) use ( $name ) {
					return $name === $event['event'];
				}
			)
		);
	}

	/**
	 * Make the synchronous deactivation flush fail, so the queue survives for inspection.
	 *
	 * on_deactivate() no longer only queues: it also sends, in the same request, because
	 * deactivating removes the WP-Cron listener that would otherwise deliver the event
	 * (Tracker::flush_on_deactivation()). A successful send therefore empties the queue -- and the
	 * tests below are about what Lifecycle BUILDS, which the queue is the visible record of.
	 * TrackerTest owns what Transport ships.
	 *
	 * A 503 is stubbed rather than the flush being suppressed, so these tests keep exercising the
	 * real code path. Registration fails, flush() returns before it reaches the batch, and the
	 * queue is left untouched -- behaviour the suite already pins elsewhere. Nothing here is
	 * weakened to accommodate the fix; the subject and the premise of each test are unchanged.
	 *
	 * @return void
	 */
	private function stub_undeliverable_flush() {
		$this->stub_remote_response( 503, array( 'success' => false ) );
	}

	/**
	 * 1. A fresh install -- no `env` option stored yet -- must not report a spurious version or
	 * compat change on its very first init(). Without this, every fresh install looks like a
	 * phantom upgrade (see the comment above the empty-$seen guard in Lifecycle::on_init()). It must
	 * also adopt and persist the current environment, so the NEXT request has a baseline to compare
	 * against instead of finding "nothing stored" again.
	 */
	public function test_on_init_with_no_stored_env_fires_nothing_and_records_environment() {
		list( , $config, $lifecycle ) = $this->ready_lifecycle();

		$lifecycle->on_init();

		$this->assertSame(
			array(),
			$this->queue_for( $config )->all(),
			'a fresh install must not fire any event on its first init()'
		);
		$this->assertSame(
			array(
				'plugin_version' => '1.0.0',
				'wp'             => '6.5.2',
				'php'            => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
			),
			$this->stored( $config, 'env' ),
			'on_init() must adopt and persist the current environment when nothing was stored yet'
		);
	}

	/**
	 * 2. `install` is the first activation ever on this site; `activate` is every activation,
	 * including that one. Two consecutive on_activate() calls must produce exactly one `install`
	 * and two `activate` events -- the `installed` marker, not an empty option, is what
	 * distinguishes them.
	 */
	public function test_on_activate_fires_install_only_once_across_two_consecutive_activations() {
		list( , $config, $lifecycle ) = $this->ready_lifecycle();

		$lifecycle->on_activate();
		$lifecycle->on_activate();

		$queued = $this->queue_for( $config )->all();

		$this->assertCount( 1, $this->events_named( $queued, Event::INSTALL ), 'install must fire exactly once ever' );
		$this->assertCount( 2, $this->events_named( $queued, Event::ACTIVATE ), 'activate must fire on every activation' );
	}

	/**
	 * 2 (continued). The realistic case: deactivate, then reactivate. The `installed` marker is a
	 * separate option from anything on_deactivate() touches, so it must survive the cycle and the
	 * second activation must still not look like a fresh install.
	 */
	public function test_deactivate_reactivate_cycle_does_not_refire_install() {
		list( , $config, $lifecycle ) = $this->ready_lifecycle();

		$this->stub_undeliverable_flush();

		$lifecycle->on_activate();
		$lifecycle->on_deactivate();
		$lifecycle->on_activate();

		$queued = $this->queue_for( $config )->all();

		$this->assertSame(
			array( Event::INSTALL, Event::ACTIVATE, Event::DEACTIVATION, Event::ACTIVATE ),
			array_column( $queued, 'event' ),
			'a deactivate/reactivate cycle must fire install only on the very first activation ever'
		);
	}

	/**
	 * 3. A plugin-version change must report the OLD value in `from`, not the new one -- getting
	 * this backwards silently inverts every upgrade path. The stored env must also be advanced so
	 * the SAME change does not fire again on the very next request.
	 */
	public function test_on_init_version_change_reports_the_previous_version_as_from() {
		list( , $config, $lifecycle ) = $this->ready_lifecycle( array( 'version' => '2.0.0' ) );

		$this->seed_stored(
			$config,
			'env',
			array(
				'plugin_version' => '1.0.0',
				'wp'             => '6.5.2',
				'php'            => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
			)
		);

		$lifecycle->on_init();

		$versions = $this->events_named( $this->queue_for( $config )->all(), Event::VERSION );

		$this->assertCount( 1, $versions );
		$this->assertSame( '1.0.0', $versions[0]['from'], 'the OLD version must be reported, not the new one' );

		$lifecycle->on_init();

		$this->assertCount(
			1,
			$this->events_named( $this->queue_for( $config )->all(), Event::VERSION ),
			'the stored env must have been advanced, so the same change does not fire again on the next request'
		);
	}

	/**
	 * 4. A WordPress-core upgrade between requests must fire `compat` with `what` = "wp" and the
	 * PREVIOUS wp version in `from`.
	 */
	public function test_on_init_wp_change_fires_compat_with_what_wp_and_previous_value() {
		list( , $config, $lifecycle ) = $this->ready_lifecycle();

		$this->seed_stored(
			$config,
			'env',
			array(
				'plugin_version' => '1.0.0',
				'wp'             => '6.5.2',
				'php'            => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
			)
		);
		Functions\when( 'get_bloginfo' )->justReturn( '6.6.0' );

		$lifecycle->on_init();

		$compats = $this->events_named( $this->queue_for( $config )->all(), Event::COMPAT );

		$this->assertCount( 1, $compats );
		$this->assertSame( 'wp', $compats[0]['what'] );
		$this->assertSame( '6.5.2', $compats[0]['from'] );
	}

	/**
	 * 4 (continued). A PHP upgrade between requests must fire `compat` with `what` = "php" and the
	 * PREVIOUS php version in `from`. PHP_MAJOR_VERSION/PHP_MINOR_VERSION are real constants of the
	 * process running the test and cannot be stubbed, so the "previous" environment is written
	 * directly -- exactly the shape it would have on disk after a real request ran under an older
	 * PHP.
	 */
	public function test_on_init_php_change_fires_compat_with_what_php_and_previous_value() {
		list( , $config, $lifecycle ) = $this->ready_lifecycle();

		$this->seed_stored(
			$config,
			'env',
			array(
				'plugin_version' => '1.0.0',
				'wp'             => '6.5.2',
				'php'            => '7.2',
			)
		);

		$lifecycle->on_init();

		$compats = $this->events_named( $this->queue_for( $config )->all(), Event::COMPAT );

		$this->assertCount( 1, $compats );
		$this->assertSame( 'php', $compats[0]['what'] );
		$this->assertSame( '7.2', $compats[0]['from'] );
	}

	/**
	 * 4 (continued). A change on BOTH axes in the same request must fire two separate compat
	 * events, not one merged event and not just the first axis checked.
	 */
	public function test_on_init_wp_and_php_changing_together_fires_two_compat_events() {
		list( , $config, $lifecycle ) = $this->ready_lifecycle();

		$this->seed_stored(
			$config,
			'env',
			array(
				'plugin_version' => '1.0.0',
				'wp'             => '6.4.0',
				'php'            => '7.2',
			)
		);
		Functions\when( 'get_bloginfo' )->justReturn( '6.6.0' );

		$lifecycle->on_init();

		$compats = $this->events_named( $this->queue_for( $config )->all(), Event::COMPAT );
		$whats   = array_column( $compats, 'what' );
		sort( $whats );

		$this->assertCount( 2, $compats, 'a change on both axes in one request must fire two compat events' );
		$this->assertSame( array( 'php', 'wp' ), $whats );
	}

	/**
	 * 5. With the stored env matching the current environment exactly, on_init() must fire nothing
	 * -- not on the first call, and not on a second, identical call either.
	 */
	public function test_on_init_twice_with_no_environment_change_fires_nothing_either_time() {
		list( , $config, $lifecycle ) = $this->ready_lifecycle();

		$this->seed_stored(
			$config,
			'env',
			array(
				'plugin_version' => '1.0.0',
				'wp'             => '6.5.2',
				'php'            => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
			)
		);

		$lifecycle->on_init();
		$this->assertSame( array(), $this->queue_for( $config )->all(), 'matching env must not fire anything on the first call' );

		$lifecycle->on_init();
		$this->assertSame( array(), $this->queue_for( $config )->all(), 'matching env must not fire anything on a second call either' );
	}

	/**
	 * 6. A reason stashed by the feedback modal (Feedback\Deactivation::handle()) before
	 * deactivation is read, attached to the event, and the stash is deleted -- so it cannot be
	 * picked up again by a later deactivation.
	 */
	public function test_on_deactivate_with_a_valid_stashed_reason_includes_it_and_clears_the_stash() {
		list( , $config, $lifecycle ) = $this->ready_lifecycle();

		$this->stub_undeliverable_flush();

		$this->seed_stored(
			$config,
			'reason',
			array(
				'reason' => 'broke_site',
				'at'     => 1700000000,
			)
		);

		$lifecycle->on_deactivate();

		$queued = $this->queue_for( $config )->all();

		$this->assertCount( 1, $queued );
		$this->assertSame( Event::DEACTIVATION, $queued[0]['event'] );
		$this->assertSame( 'broke_site', $queued[0]['reason'] );
		$this->assertFalse( $this->stored( $config, 'reason' ), 'the stash must be deleted once it has been read' );
	}

	/**
	 * 6 (continued). With no stash present at all, the event must carry no `reason` key -- not an
	 * empty string, not null, genuinely absent -- because the survey is dismissible and deactivation
	 * must never depend on it being filled in.
	 */
	public function test_on_deactivate_without_a_stashed_reason_carries_no_reason_key() {
		list( , $config, $lifecycle ) = $this->ready_lifecycle();

		$this->stub_undeliverable_flush();

		$lifecycle->on_deactivate();

		$queued = $this->queue_for( $config )->all();

		$this->assertCount( 1, $queued );
		$this->assertArrayNotHasKey( 'reason', $queued[0] );
		$this->assertFalse( $this->stored( $config, 'reason' ) );
	}

	/**
	 * 6 (continued). The stash is single-use: a reason picked before one deactivation must not
	 * silently reappear on a LATER deactivation that never stashed a reason of its own.
	 */
	public function test_a_stashed_reason_does_not_leak_into_a_later_deactivation() {
		list( , $config, $lifecycle ) = $this->ready_lifecycle();

		$this->stub_undeliverable_flush();

		$this->seed_stored(
			$config,
			'reason',
			array(
				'reason' => 'confusing',
				'at'     => 1700000000,
			)
		);

		$lifecycle->on_deactivate();
		$lifecycle->on_activate();
		$lifecycle->on_deactivate();

		$deactivations = $this->events_named( $this->queue_for( $config )->all(), Event::DEACTIVATION );

		$this->assertCount( 2, $deactivations );
		$this->assertSame( 'confusing', $deactivations[0]['reason'] );
		$this->assertArrayNotHasKey( 'reason', $deactivations[1] );
	}

	/**
	 * 7. Every lifecycle method routes through Tracker::track(), so with no consent granted none of
	 * on_activate(), on_deactivate() or on_init() may queue anything -- asserted directly rather
	 * than trusted, because a future refactor could bypass the facade and write to the queue
	 * directly.
	 *
	 * @group launch-gate
	 * @group gate-telemetry-consent
	 */
	public function test_nothing_is_queued_by_any_lifecycle_method_without_consent() {
		$config = $this->make_config( array( 'enabled' => true ) );
		// Deliberately NOT seeding consent.
		$tracker   = Tracker::init( $this->config_args( array( 'enabled' => true ) ) );
		$lifecycle = $tracker->lifecycle();

		$this->seed_stored(
			$config,
			'reason',
			array(
				'reason' => 'other',
				'at'     => 1700000000,
			)
		);

		$lifecycle->on_activate();
		$lifecycle->on_deactivate();
		$lifecycle->on_init();

		$this->assertSame( array(), $this->queue_for( $config )->all(), 'no lifecycle event may be queued without consent' );
	}

	/**
	 * 8. register() is what makes the generated snippet self-contained: it wires activation and
	 * deactivation to the consumer's own main plugin file (Config::file()) and hooks `init` at
	 * priority 20.
	 *
	 * Isolated to its own process because register_activation_hook()/register_deactivation_hook()
	 * do not exist in this harness by default (unlike add_action(), which Brain Monkey always
	 * provides) -- see the "Everything here routes through Tracker::track()" class docblock context
	 * in Lifecycle for why the function_exists() guard exists at all. The moment Functions\when()
	 * defines either function it stays defined for the rest of the PHP process (the same hazard
	 * PluginTrackerTestCase documents for get_bloginfo()/is_multisite()/etc., and the same one
	 * TrackerTest::test_cx_tracker_disable_overrides_both_gates_passing() isolates a constant
	 * definition against), which would flip Lifecycle::register()'s guard on for every OTHER test in
	 * the suite that calls Tracker::init(). Isolating this one test keeps every other test exercising
	 * the real, unstubbed guard exactly as the current 182-test baseline does.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_register_wires_activation_and_deactivation_hooks_and_hooks_init() {
		$config = $this->make_config( array( 'enabled' => true ) );

		$activations   = array();
		$deactivations = array();

		Functions\when( 'register_activation_hook' )->alias(
			function ( $file, $callback ) use ( &$activations ) {
				$activations[] = array( $file, $callback );
			}
		);
		Functions\when( 'register_deactivation_hook' )->alias(
			function ( $file, $callback ) use ( &$deactivations ) {
				$deactivations[] = array( $file, $callback );
			}
		);

		$tracker   = Tracker::init( $this->config_args( array( 'enabled' => true ) ) );
		$lifecycle = $tracker->lifecycle();

		$this->assertCount( 1, $activations, 'register_activation_hook() must be called exactly once' );
		$this->assertSame( $config->file(), $activations[0][0] );
		$this->assertSame( array( $lifecycle, 'on_activate' ), $activations[0][1] );

		$this->assertCount( 1, $deactivations, 'register_deactivation_hook() must be called exactly once' );
		$this->assertSame( $config->file(), $deactivations[0][0] );
		$this->assertSame( array( $lifecycle, 'on_deactivate' ), $deactivations[0][1] );

		$this->assertSame(
			20,
			has_action( 'init', array( $lifecycle, 'on_init' ) ),
			'init must be hooked, at priority 20, to on_init()'
		);
	}

	/**
	 * 9. The extra props Lifecycle builds by hand for each event it emits must satisfy
	 * Event::validate_props() -- that method enforces a closed key set, so a typo in one of these
	 * hand-built arrays would otherwise be silently rejected (and the event dropped) at
	 * Tracker::track() time rather than caught here. Drives every distinct shape the class emits --
	 * install, activate, deactivation with and without a reason, version, and compat on both axes --
	 * through one real flow and checks each queued event's own props, not a hand-typed restatement
	 * of them.
	 */
	public function test_every_event_lifecycle_emits_satisfies_event_validate_props() {
		list( , $config, $lifecycle ) = $this->ready_lifecycle();

		$this->stub_undeliverable_flush();

		$lifecycle->on_activate(); // install + activate.

		$this->seed_stored(
			$config,
			'reason',
			array(
				'reason' => 'missing_feature',
				'at'     => 1700000000,
			)
		);
		$lifecycle->on_deactivate(); // deactivation, with reason.
		$lifecycle->on_activate(); // activate only; install already recorded.
		$lifecycle->on_deactivate(); // deactivation, no reason (stash already consumed).

		$this->seed_stored(
			$config,
			'env',
			array(
				'plugin_version' => '0.9.0',
				'wp'             => '6.4.0',
				'php'            => '7.2',
			)
		);
		Functions\when( 'get_bloginfo' )->justReturn( '6.6.0' );
		$lifecycle->on_init(); // version + compat(wp) + compat(php).

		$queued = $this->queue_for( $config )->all();

		// Precondition: every distinct shape the class can emit is actually present in this run, so a
		// mistake that skipped one silently would show up as a wrong count here rather than the loop
		// below simply having nothing to check for that shape.
		$this->assertCount( 1, $this->events_named( $queued, Event::INSTALL ) );
		$this->assertCount( 2, $this->events_named( $queued, Event::ACTIVATE ) );
		$this->assertCount( 2, $this->events_named( $queued, Event::DEACTIVATION ) );
		$this->assertCount( 1, $this->events_named( $queued, Event::VERSION ) );
		$this->assertCount( 2, $this->events_named( $queued, Event::COMPAT ) );

		// Everything Tracker::common_fields() puts on every event, so what is left is the event's own
		// props. Kept in step by hand, but it fails in the safe direction: a common field added there
		// and forgotten here surfaces as an unexpected prop on EVERY event, which is this failure.
		$common = array( 'event', 'at', 'plugin', 'plugin_version', 'wp', 'php', 'locale', 'multisite', 'server', 'theme' );

		foreach ( $queued as $event ) {
			$name  = $event['event'];
			$props = array_diff_key( $event, array_flip( $common ) );

			$this->assertNull(
				Event::validate_props( $name, $props ),
				sprintf( '%s event props must satisfy Event::validate_props(): %s', $name, json_encode( $props ) )
			);
		}
	}

	/**
	 * forget() is the uninstall-time cleanup: it must clear every option this class owns, regardless
	 * of consent -- an uninstalling site is entitled to end up with none of this class's own state
	 * left behind either way.
	 *
	 * It was written when there were three, and 'activated' arrived in 1.3.0 without being added
	 * here, which is exactly the gap this now closes. Anything this class starts writing has to be
	 * added to both the method and this list; there is no way to derive one from the other.
	 */
	public function test_forget_clears_every_option_this_class_owns() {
		list( , $config, $lifecycle ) = $this->ready_lifecycle();

		$lifecycle->on_activate(); // writes the installed marker, the environment snapshot and the activation stamp.
		$this->seed_stored(
			$config,
			'reason',
			array(
				'reason' => 'other',
				'at'     => 1700000000,
			)
		);

		foreach ( array( 'env', 'installed', 'activated' ) as $suffix ) {
			$this->assertNotFalse( $this->stored( $config, $suffix ), "the fixture must actually write '$suffix' before forget() is asked to remove it" );
		}

		$lifecycle->forget();

		$this->assertFalse( $this->stored( $config, 'env' ) );
		$this->assertFalse( $this->stored( $config, 'installed' ) );
		$this->assertFalse( $this->stored( $config, 'reason' ) );
		$this->assertFalse( $this->stored( $config, 'activated' ) );
	}

	/**
	 * Why the activation stamp has to go with the rest, stated as behaviour rather than as bookkeeping.
	 *
	 * Notice::due() counts the author's consent delay from that stamp. A stamp that survives an
	 * uninstall is inherited by the NEXT install, so the delay reads as long since elapsed and the
	 * opt-in prompt fires on the first admin page load of a plugin the site has only just installed --
	 * the precise behaviour the delay exists to prevent. A reinstall is day one.
	 */
	public function test_a_reinstall_restarts_the_consent_delay() {
		list( , $config, $lifecycle ) = $this->ready_lifecycle();

		$this->stub_undeliverable_flush();

		$lifecycle->on_activate();
		\PluginTracker_Test_Option_Store::update( $config->option( 'activated' ), 1700000000 );

		// Uninstall, then install again.
		$lifecycle->forget();
		$lifecycle->on_activate();

		$this->assertNotSame(
			1700000000,
			(int) $this->stored( $config, 'activated' ),
			'a reinstalled plugin must count its delay from the new activation, not from a previous life'
		);
	}

	/**
	 * 10. The fix for the event that could never arrive.
	 *
	 * `deactivation` used to be queued and then abandoned. flush() is a WP-Cron callback attached
	 * in Tracker::hook(), which runs only while the plugin is active, so deactivating queued the
	 * event and removed its only sender in the same request. WP-Cron then fired the orphaned hook
	 * on some later request -- wp-cron.php calls wp_unschedule_event() BEFORE
	 * do_action_ref_array(), so the event was deleted with nothing listening -- and
	 * Scheduler::ensure_scheduled() never re-armed it, being active-only too. The event sat in the
	 * queue until a reactivation that, for an uninstall, never comes.
	 *
	 * So this asserts delivery, not queueing: the request must actually leave, and it must carry
	 * the deactivation event. Asserting only "the queue is empty afterwards" would pass equally if
	 * the queue were simply discarded.
	 */
	public function test_on_deactivate_sends_the_deactivation_event_in_the_same_request() {
		list( , $config, $lifecycle ) = $this->ready_lifecycle();

		$this->seed_token( $config );

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
		Functions\when( 'wp_remote_retrieve_header' )->justReturn( '' );
		Functions\when( 'home_url' )->justReturn( 'https://tracker-sdk-deactivation-test.example' );

		$lifecycle->on_deactivate();

		$this->assertIsArray( $captured_body, 'on_deactivate() must send in the same request, not leave the event for a cron run that can no longer happen' );
		$this->assertCount(
			1,
			$this->events_named( $captured_body['events'], Event::DEACTIVATION ),
			'the request on_deactivate() sends must carry the deactivation event itself'
		);
		$this->assertSame(
			array(),
			$this->queue_for( $config )->all(),
			'a delivered batch must leave the queue empty'
		);
	}

	/**
	 * The new send inherits the consent gate rather than sitting beside it.
	 *
	 * Without consent, track() declines and nothing is queued, so flush_on_deactivation() must
	 * find an empty queue and make no request at all. Worth pinning separately from test 7 above:
	 * that one asserts nothing is QUEUED without consent, which a flush added below it could
	 * satisfy while still transmitting. Here the assertion is on the wire.
	 *
	 * @group launch-gate
	 * @group gate-telemetry-consent
	 */
	public function test_on_deactivate_makes_no_request_without_consent() {
		// Deliberately NOT seeding consent.
		$lifecycle = Tracker::init( $this->config_args( array( 'enabled' => true ) ) )->lifecycle();

		Functions\expect( 'wp_remote_post' )->never();

		$lifecycle->on_deactivate();
	}

	/**
	 * 11. The install marker must record a fact, not an attempt.
	 *
	 * It used to be written before track() was called and regardless of what track() answered. On a
	 * genuine first install there is no telemetry consent yet -- the opt-in notice renders on the
	 * NEXT page load -- so track() declined, nothing was queued, and the marker nonetheless claimed
	 * the install had been reported. Every later activation saw the marker and fired `activate`
	 * alone. `install` was emitted exactly once, at the one moment it could never be delivered.
	 *
	 * That is why a consented site could sit at zero installs indefinitely while still reporting
	 * every other event: the one event that creates the record was spent before consent existed.
	 */
	public function test_on_activate_without_consent_does_not_record_the_install() {
		$config = $this->make_config( array( 'enabled' => true ) );
		// Deliberately NOT seeding consent.
		$lifecycle = Tracker::init( $this->config_args( array( 'enabled' => true ) ) )->lifecycle();

		$lifecycle->on_activate();

		$this->assertFalse(
			$this->stored( $config, 'installed' ),
			'an install that could not be reported must not be marked as reported'
		);
	}

	/**
	 * And the consequence that makes it matter: with the marker unset, the install is still owed,
	 * so the first activation that CAN report it does.
	 *
	 * Without the fix this assertion fails on the second activation -- the marker set by the first
	 * one suppresses `install` forever.
	 */
	public function test_install_is_reported_on_the_first_activation_after_consent_is_granted() {
		$config    = $this->make_config( array( 'enabled' => true ) );
		$lifecycle = Tracker::init( $this->config_args( array( 'enabled' => true ) ) )->lifecycle();

		$lifecycle->on_activate(); // No consent: nothing recorded, nothing marked.

		Tracker::reset();
		$this->seed_consent( $config, Gate::POLICY, true );

		$this->stub_undeliverable_flush();
		$consented = Tracker::init( $this->config_args( array( 'enabled' => true ) ) )->lifecycle();
		$consented->on_activate();

		$this->assertCount(
			1,
			$this->events_named( $this->queue_for( $config )->all(), Event::INSTALL ),
			'the install a site could not report before consent must still be reported after it'
		);
	}

	/**
	 * 12. on_consent() closes the gap that leaves open.
	 *
	 * "The next activation reports it" is not good enough for a plugin somebody installs once and
	 * leaves running: there is no next activation. The site would report `version` and `compat`
	 * drift for years and never report existing at all. Granting consent is the moment it becomes
	 * observable, so that is where the backfill happens.
	 */
	public function test_on_consent_backfills_install_and_activate_for_a_site_that_never_reported_one() {
		list( , $config, $lifecycle ) = $this->ready_lifecycle();

		$this->stub_undeliverable_flush();

		$lifecycle->on_consent();

		$queued = $this->queue_for( $config )->all();

		$this->assertCount( 1, $this->events_named( $queued, Event::INSTALL ), 'consent must backfill the unreported install' );
		$this->assertCount( 1, $this->events_named( $queued, Event::ACTIVATE ), 'the site is active, and that has never been reported either' );
	}

	/**
	 * The guard on that backfill. A site that already reported its install owes nothing, and the
	 * consent form can be submitted twice -- a double-click, a reload of the admin-post URL. Firing
	 * again would manufacture an `activate` that never happened.
	 */
	public function test_on_consent_reports_nothing_when_the_install_was_already_recorded() {
		list( , $config, $lifecycle ) = $this->ready_lifecycle();

		$this->stub_undeliverable_flush();

		$lifecycle->on_activate(); // install + activate, marker now set.
		$before = $this->queue_for( $config )->all();

		$lifecycle->on_consent();

		$this->assertSame(
			$before,
			$this->queue_for( $config )->all(),
			'a site that has already reported its install must gain nothing from a second consent submission'
		);
	}

	/**
	 * The activation stamp the consent delay counts from.
	 *
	 * Written on activation and NOT on the install event, which is the distinction that makes it
	 * usable: `installed` is only recorded once the install event was actually accepted, and that
	 * cannot happen before consent -- so at prompt time, which is the moment this value is needed,
	 * `installed` is still empty on every site that has not opted in.
	 */
	public function test_on_activate_stamps_when_this_site_activated() {
		list( , $config, $lifecycle ) = $this->ready_lifecycle();

		$before = time();
		$lifecycle->on_activate();

		$this->assertGreaterThanOrEqual( $before, (int) $this->stored( $config, 'activated' ) );
	}

	/**
	 * Written once, ever. Re-stamping on each activation would restart the delay every time the
	 * plugin is toggled, so a site that deactivates now and then is asked later and later and
	 * eventually never -- silence produced by a setting that only claims to postpone.
	 */
	public function test_the_activation_stamp_is_not_rewritten_by_a_later_activation() {
		list( , $config, $lifecycle ) = $this->ready_lifecycle();

		$this->stub_undeliverable_flush();

		\PluginTracker_Test_Option_Store::update( $config->option( 'activated' ), 1700000000 );

		$lifecycle->on_activate();
		$lifecycle->on_deactivate();
		$lifecycle->on_activate();

		$this->assertSame( 1700000000, (int) $this->stored( $config, 'activated' ) );
	}
}
