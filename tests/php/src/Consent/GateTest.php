<?php
/**
 * Tests for the double consent gate.
 *
 * @package Codexpert\PluginTracker\Test
 */

namespace Codexpert\PluginTracker\Test\Consent;

use Brain\Monkey\Functions;
use Codexpert\PluginTracker\Consent\Gate;
use Codexpert\PluginTracker\Cron\Scheduler;
use Codexpert\PluginTracker\Storage\Install;
use Codexpert\PluginTracker\Test\PluginTrackerTestCase;

/**
 * @covers \Codexpert\PluginTracker\Consent\Gate
 */
class GateTest extends PluginTrackerTestCase {

	/**
	 * No consent record at all is not opted in.
	 */
	public function test_site_opted_in_is_false_with_no_record() {
		$config  = $this->make_config();
		$consent = new Gate( $config );

		$this->assertFalse( $consent->site_opted_in() );
	}

	/**
	 * An opt-in recorded under the CURRENT policy version counts.
	 */
	public function test_site_opted_in_is_true_under_current_policy() {
		$config = $this->make_config();
		$this->seed_consent( $config, Gate::POLICY, true );

		$consent = new Gate( $config );

		$this->assertTrue( $consent->site_opted_in() );
	}

	/**
	 * The critical regression case: a consent record stored under an OLDER policy version does
	 * NOT carry forward. Gate::POLICY is currently 1; a record stamped 0 must not count as
	 * opted in, even though 'opted_in' itself is true.
	 */
	public function test_site_opted_in_is_false_under_an_older_policy_version() {
		$config = $this->make_config();
		$this->seed_consent( $config, 0, true );

		$consent = new Gate( $config );

		$this->assertFalse( $consent->site_opted_in() );
	}

	/**
	 * A record stamped with a NEWER policy than the SDK currently knows about also does not
	 * count -- equality is required, not "at least".
	 */
	public function test_site_opted_in_is_false_under_a_newer_policy_version() {
		$config = $this->make_config();
		$this->seed_consent( $config, Gate::POLICY + 1, true );

		$consent = new Gate( $config );

		$this->assertFalse( $consent->site_opted_in() );
	}

	/**
	 * granted() gate 1: the author must have enabled telemetry, independent of site opt-in.
	 */
	public function test_granted_is_false_when_author_has_not_enabled() {
		$config = $this->make_config( array( 'enabled' => false ) );
		$this->seed_consent( $config, Gate::POLICY, true );

		$consent = new Gate( $config );

		$this->assertFalse( $consent->granted() );
	}

	/**
	 * granted() gate 2: the site admin must have opted in, independent of author enablement.
	 */
	public function test_granted_is_false_when_site_has_not_opted_in() {
		$config  = $this->make_config( array( 'enabled' => true ) );
		$consent = new Gate( $config );

		$this->assertFalse( $consent->granted() );
	}

	/**
	 * Both gates passing is what actually grants.
	 */
	public function test_granted_is_true_when_both_gates_pass() {
		$config = $this->make_config( array( 'enabled' => true ) );
		$this->seed_consent( $config, Gate::POLICY, true );

		$consent = new Gate( $config );

		$this->assertTrue( $consent->granted() );
	}

	/**
	 * An explicit opt-out record, even under the current policy, must not grant.
	 *
	 * @group launch-gate
	 * @group gate-telemetry-consent
	 */
	public function test_granted_is_false_after_explicit_opt_out() {
		$config = $this->make_config( array( 'enabled' => true ) );
		$this->seed_consent( $config, Gate::POLICY, false );

		$consent = new Gate( $config );

		$this->assertFalse( $consent->granted() );
	}

	/**
	 * CX_TRACKER_DISABLE is the unconditional site/host kill switch: it overrides BOTH consent
	 * gates even when both would otherwise pass. Run in isolation because the constant, once
	 * defined, cannot be undefined for the rest of the process.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_cx_tracker_disable_overrides_both_gates_passing() {
		define( 'CX_TRACKER_DISABLE', true );

		$config = $this->make_config( array( 'enabled' => true ) );
		$this->seed_consent( $config, Gate::POLICY, true );

		$consent = new Gate( $config );

		$this->assertTrue( $consent->site_opted_in(), 'precondition: site_opted_in() must be true' );
		$this->assertFalse( $consent->granted(), 'CX_TRACKER_DISABLE must override an otherwise-granted consent' );
	}

	/**
	 * opt_in() records the current policy version and answered()/site_opted_in() reflect it.
	 */
	public function test_opt_in_is_reflected_by_site_opted_in_and_answered() {
		$config  = $this->make_config();
		$consent = new Gate( $config );

		$consent->opt_in();

		$this->assertTrue( $consent->site_opted_in() );
		$this->assertTrue( $consent->answered() );
	}

	/**
	 * opt_out() is remembered (answered() is true) but does not opt the site in.
	 */
	public function test_opt_out_is_answered_but_not_opted_in() {
		$config  = $this->make_config();
		$consent = new Gate( $config );

		$consent->opt_out();

		$this->assertTrue( $consent->answered() );
		$this->assertFalse( $consent->site_opted_in() );
	}

	/**
	 * forget() erases the record entirely: neither opted in nor answered.
	 */
	public function test_forget_erases_the_consent_record() {
		$config  = $this->make_config();
		$consent = new Gate( $config );

		$consent->opt_in();
		$consent->forget();

		$this->assertFalse( $consent->site_opted_in() );
		$this->assertFalse( $consent->answered() );
	}

	/*
	|--------------------------------------------------------------------------
	| What an opt-out takes with it
	|--------------------------------------------------------------------------
	|
	| docs/CONSENT.md promises that "state does not survive an explicit opt-out". That promise used
	| to be kept by Tracker::handle_consent() -- the handler behind the built-in notice -- and by
	| nothing else, so a consumer rendering their own opt-out UI through Tracker::consent() recorded
	| the refusal and kept the token, the queue, the armed flush and the salt.
	|
	| These bind the promise to the gate, which is the door every caller comes through.
	*/

	/**
	 * The queued events go. Consent precedes collection, not merely transmission, so holding them
	 * against a possible future opt-in is not an option.
	 */
	public function test_opt_out_clears_the_queue() {
		$config = $this->make_config();
		$this->seed_queue( $config, 5 );

		$this->assertSame( 5, $this->queue_for( $config )->count(), 'the fixture must actually queue something' );

		( new Gate( $config ) )->opt_out();

		$this->assertSame( 0, $this->queue_for( $config )->count(), 'events collected under the previous consent must not survive it' );
	}

	/**
	 * The ingestion credential goes. A live token on a site that said no is the thing an opt-out is
	 * supposed to remove.
	 */
	public function test_opt_out_discards_the_install_token() {
		$config = $this->make_config();
		$this->seed_token( $config );

		( new Gate( $config ) )->opt_out();

		$this->assertSame( '', (string) $this->stored( $config, 'token' ), 'the token must not outlive the consent it was issued under' );
	}

	/**
	 * The salt goes, which is what actually breaks the correlation: the anonymous install ID is
	 * derived from it, so a new one means previously reported data can no longer be tied to this site.
	 */
	public function test_opt_out_forgets_the_install_salt() {
		$config  = $this->make_config();
		$install = new Install( $config );

		$before = $install->id();
		$this->assertNotSame( '', $before );

		( new Gate( $config ) )->opt_out();

		$this->assertNotSame(
			$before,
			$install->id(),
			'opting out and back in must yield a NEW install id -- reusing the old one leaves the site correlatable to data it declined to share'
		);
	}

	/**
	 * And the schedule, so nothing wakes up later to re-register the site that just declined.
	 *
	 * Asserted on the CALL rather than on resulting state: the suite's cron stubs are constant --
	 * wp_next_scheduled() always answers false -- so a state assertion here would pass whether or not
	 * opt_out() ever cleared anything, which is the shape of test this whole file exists to stop.
	 */
	public function test_opt_out_unschedules_the_flush() {
		$config = $this->make_config();
		$hook   = ( new Scheduler( $config ) )->hook();

		// A recording alias rather than Functions\expect(): the base class already registers a
		// catch-all when() for this function in setUp, and an expect() layered on top of that never
		// sees the call. Re-aliasing replaces the stub outright, which is unambiguous.
		$cleared = array();
		Functions\when( 'wp_clear_scheduled_hook' )->alias(
			function ( $name ) use ( &$cleared ) {
				$cleared[] = $name;
				return true;
			}
		);

		( new Gate( $config ) )->opt_out();

		$this->assertContains( $hook, $cleared, 'a declined site must not keep a scheduled flush' );
	}

	/**
	 * The record itself STAYS. It is what stops the notice asking again on every page load, and
	 * deleting it would turn a decision into an unanswered question.
	 */
	public function test_opt_out_keeps_the_consent_record_itself() {
		$config = $this->make_config();
		$gate   = new Gate( $config );

		$gate->opt_out();

		$this->assertTrue( $gate->answered(), 'the admin was asked and answered; the SDK must remember that' );
		$this->assertFalse( $gate->site_opted_in() );
	}
}
