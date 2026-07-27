<?php
/**
 * Tests for the double consent gate.
 *
 * @package Codexpert\PluginTracker\Test
 */

namespace Codexpert\PluginTracker\Test\Consent;

use Codexpert\PluginTracker\Consent\Gate;
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
}
