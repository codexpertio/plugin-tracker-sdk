<?php
/**
 * Tests for the flush schedule, focused on reschedule_after() -- the short-backoff arm used by
 * Tracker::apply() for retryable failures, as opposed to reschedule()'s full interval -- and on
 * jitter(), which keeps sites that installed in the same window from flushing in lockstep.
 *
 * @package Codexpert\PluginTracker\Test
 */

namespace Codexpert\PluginTracker\Test\Cron;

use Brain\Monkey\Functions;
use Codexpert\PluginTracker\Cron\Scheduler;
use Codexpert\PluginTracker\Test\PluginTrackerTestCase;

/**
 * @covers \Codexpert\PluginTracker\Cron\Scheduler
 */
class SchedulerTest extends PluginTrackerTestCase {

	/**
	 * reschedule_after() must clear whatever was previously scheduled for this consumer's hook
	 * before arming the new one -- otherwise a retry could stack a second event alongside a
	 * still-pending full-interval one.
	 */
	public function test_reschedule_after_clears_the_existing_schedule_before_arming_the_next_one() {
		$config    = $this->make_config();
		$scheduler = new Scheduler( $config );

		$cleared = array();
		$hooked  = array();
		Functions\when( 'wp_clear_scheduled_hook' )->alias(
			function ( $hook ) use ( &$cleared ) {
				$cleared[] = $hook;
				return true;
			}
		);
		Functions\when( 'wp_schedule_single_event' )->alias(
			function ( $timestamp, $hook ) use ( &$hooked ) {
				$hooked[] = array( $timestamp, $hook );
				return true;
			}
		);

		$scheduler->reschedule_after( 120 );

		$this->assertSame( array( $scheduler->hook() ), $cleared );
		$this->assertCount( 1, $hooked );
		$this->assertSame( $scheduler->hook(), $hooked[0][1] );
	}

	/**
	 * The requested delay is honoured (within a small timing tolerance for time() ticking during
	 * the test) rather than being reinterpreted or jittered -- reschedule_after() is the "obey the
	 * caller's exact backoff" primitive; jitter is Tracker::backoff()'s job, not Scheduler's.
	 */
	public function test_reschedule_after_arms_the_event_at_now_plus_the_given_seconds() {
		$config    = $this->make_config();
		$scheduler = new Scheduler( $config );

		$captured = array();
		Functions\when( 'wp_schedule_single_event' )->alias(
			function ( $timestamp, $hook ) use ( &$captured ) {
				$captured[] = $timestamp;
				return true;
			}
		);

		$before = time();
		$scheduler->reschedule_after( 120 );
		$after = time();

		$this->assertCount( 1, $captured );
		$this->assertGreaterThanOrEqual( $before + 120, $captured[0] );
		$this->assertLessThanOrEqual( $after + 120, $captured[0] );
	}

	/**
	 * A non-positive delay (0, or negative -- which should never happen, but a caller error must
	 * not silently schedule the retry in the past or "now") is floored to 1 second, never to 0 or
	 * below.
	 */
	public function test_reschedule_after_floors_a_non_positive_delay_to_one_second() {
		$config    = $this->make_config();
		$scheduler = new Scheduler( $config );

		$captured = array();
		Functions\when( 'wp_schedule_single_event' )->alias(
			function ( $timestamp, $hook ) use ( &$captured ) {
				$captured[] = $timestamp;
				return true;
			}
		);

		$before = time();
		$scheduler->reschedule_after( -50 );
		$after = time();

		$this->assertCount( 1, $captured );
		$this->assertGreaterThanOrEqual( $before + 1, $captured[0] );
		$this->assertLessThanOrEqual( $after + 1, $captured[0] );
	}

	/**
	 * A numeric-string delay (as could arrive from an option or a filter) is cast to int rather
	 * than rejected or truncated unexpectedly.
	 */
	public function test_reschedule_after_casts_a_numeric_string_delay_to_int_seconds() {
		$config    = $this->make_config();
		$scheduler = new Scheduler( $config );

		$captured = array();
		Functions\when( 'wp_schedule_single_event' )->alias(
			function ( $timestamp, $hook ) use ( &$captured ) {
				$captured[] = $timestamp;
				return true;
			}
		);

		$before = time();
		$scheduler->reschedule_after( '45' );
		$after = time();

		$this->assertCount( 1, $captured );
		$this->assertGreaterThanOrEqual( $before + 45, $captured[0] );
		$this->assertLessThanOrEqual( $after + 45, $captured[0] );
	}

	/**
	 * jitter() (exercised through ensure_scheduled(), which calls it with no argument -- the full
	 * interval) must actually consult wp_rand() with [0, interval] as the range, and the value
	 * wp_rand() returns must actually reach the scheduled timestamp. This is the mechanism that
	 * keeps every site that installed the consumer's plugin in the same release window from
	 * flushing at the same offset; a jitter() that always returned 0 would pass every OTHER test in
	 * this suite while quietly defeating that guarantee.
	 */
	public function test_jitter_passes_the_interval_as_the_wp_rand_range_and_the_result_reaches_the_schedule() {
		$config    = $this->make_config();
		$scheduler = new Scheduler( $config );

		$rand_calls = array();
		Functions\when( 'wp_rand' )->alias(
			function ( $min, $max ) use ( &$rand_calls ) {
				$rand_calls[] = array( $min, $max );
				return $max;
			}
		);

		$captured = array();
		Functions\when( 'wp_schedule_single_event' )->alias(
			function ( $timestamp, $hook ) use ( &$captured ) {
				$captured[] = $timestamp;
				return true;
			}
		);

		$scheduler->ensure_scheduled();

		$this->assertCount( 1, $rand_calls, 'jitter() must consult wp_rand() exactly once per call' );
		$this->assertSame( 0, $rand_calls[0][0], 'the lower bound passed to wp_rand() must be zero' );
		$this->assertSame(
			$scheduler->interval(),
			$rand_calls[0][1],
			'the upper bound passed to wp_rand() must be the full interval, not a fixed subset'
		);

		$high_timestamp = $captured[0];

		// Now make wp_rand() return the OTHER end of the range and confirm the scheduled timestamp
		// actually shifts -- proving the return value is used, not discarded.
		Functions\when( 'wp_rand' )->justReturn( 0 );
		$captured = array();
		$scheduler->ensure_scheduled();
		$low_timestamp = $captured[0];

		$this->assertLessThan(
			$high_timestamp,
			$low_timestamp,
			'a different wp_rand() return value must produce a different scheduled timestamp'
		);
		// The two ensure_scheduled() calls happen microseconds apart in the same test, so a gap
		// this large can only be explained by the jitter value itself, not by time() ticking.
		$this->assertGreaterThan(
			$scheduler->interval() - 5,
			$high_timestamp - $low_timestamp,
			'the shift between the two runs must be attributable to jitter, not clock drift'
		);
	}

	/**
	 * reschedule()'s jitter call is bounded to a small fraction (300s), not the whole interval --
	 * the cadence is already established at that point, so this asserts the OTHER call site passes
	 * its own bound through to wp_rand() rather than jitter() ignoring its argument.
	 */
	public function test_reschedule_passes_its_own_smaller_bound_to_wp_rand() {
		$config    = $this->make_config();
		$scheduler = new Scheduler( $config );

		$rand_calls = array();
		Functions\when( 'wp_rand' )->alias(
			function ( $min, $max ) use ( &$rand_calls ) {
				$rand_calls[] = array( $min, $max );
				return $max;
			}
		);
		Functions\when( 'wp_schedule_single_event' )->justReturn( true );

		$scheduler->reschedule();

		$this->assertCount( 1, $rand_calls );
		$this->assertSame( array( 0, 300 ), $rand_calls[0], 'reschedule() must jitter within 300s, not the full interval' );
	}
}
