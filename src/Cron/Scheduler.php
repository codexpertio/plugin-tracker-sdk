<?php
/**
 * Scheduled flushing.
 *
 * @package Codexpert\PluginTracker
 */

namespace Codexpert\PluginTracker\Cron;

use Codexpert\PluginTracker\Config;

/**
 * Owns the flush schedule.
 *
 * WP-Cron only. `plugin-tracker` itself declares `Requires Plugins: action-scheduler`, but a
 * third-party consumer bundling this SDK may not have Action Scheduler active, and an SDK that
 * silently stops working without an unrelated plugin is a support burden for its consumer. So the
 * lowest common denominator is the right choice here even though it is the weaker scheduler.
 *
 * Jitter is required rather than cosmetic. Without it, every site that installed the consumer's
 * plugin in the same release window flushes at the same offset and the backend sees a periodic
 * thundering herd. With N scoped SDK copies per site unable to batch with each other (spec 10.3),
 * that multiplies.
 */
class Scheduler {

	/**
	 * Default interval, seconds. Long on purpose -- telemetry has no freshness requirement, and
	 * a chatty SDK is what site owners blame for slowness.
	 */
	const DEFAULT_INTERVAL = 86400;

	/**
	 * Lower bound for a server-supplied interval, so a bad value cannot turn the SDK into a
	 * flood. The server may widen the interval, never narrow it below this.
	 */
	const MIN_INTERVAL = 3600;

	/**
	 * Upper bound for a server-supplied interval.
	 *
	 * Clamped on both sides, not just the low side. An unbounded value persists, so a single bad or
	 * hostile `flush_interval` (say 315360000) would schedule the next flush ten years out and
	 * silently end telemetry for that site forever. Symmetric with Tracker::RETRY_CAP.
	 */
	const MAX_INTERVAL = 604800;

	/**
	 * Config.
	 *
	 * @var Config
	 */
	private $config;

	/**
	 * Constructor.
	 *
	 * @param Config $config Config.
	 */
	public function __construct( Config $config ) {
		$this->config = $config;
	}

	/**
	 * The cron hook for this consumer.
	 *
	 * Namespaced per plugin slug: two consumers on one site each have their own scoped copy of
	 * this SDK, so a shared hook name would have both copies handling each other's events.
	 *
	 * @return string
	 */
	public function hook() {
		return 'cx_tracker_flush_' . str_replace( '-', '_', $this->config->plugin() );
	}

	/**
	 * The effective interval.
	 *
	 * @return int
	 */
	public function interval() {
		$stored = (int) get_option( $this->config->option( 'interval' ) );

		// Clamped on read as well as on write, so a value persisted by an earlier build of this SDK
		// cannot outlive the bound. Bundled consumers cannot be updated, so old stored state is a
		// real case, not a hypothetical.
		if ( $stored >= self::MIN_INTERVAL ) {
			return min( $stored, self::MAX_INTERVAL );
		}

		return self::DEFAULT_INTERVAL;
	}

	/**
	 * Record a server-supplied interval.
	 *
	 * Lets the backend widen intervals under load without every consumer shipping an update --
	 * which matters because consumers freeze their bundled SDK version permanently.
	 *
	 * @param int $seconds Requested interval.
	 * @return void
	 */
	public function remember_interval( $seconds ) {
		$seconds = (int) $seconds;

		if ( $seconds < self::MIN_INTERVAL ) {
			return;
		}

		update_option( $this->config->option( 'interval' ), min( $seconds, self::MAX_INTERVAL ), false );
	}

	/**
	 * Ensure a flush is scheduled.
	 *
	 * A re-armed SINGLE event, not a recurring one. A recurring event is pinned to one of
	 * WordPress's named recurrences ('hourly', 'daily', ...), which cannot express a
	 * server-supplied interval -- passing an arbitrary number of seconds alongside 'daily' would
	 * silently ignore the number. Re-arming a single event after each run supports any interval
	 * without registering a custom schedule through the cron_schedules filter.
	 *
	 * The usual objection to single events is that one missed re-arm stops the job forever. That
	 * is handled by calling this on every init: if nothing is scheduled, it is scheduled again.
	 *
	 * @return void
	 */
	public function ensure_scheduled() {

		if ( wp_next_scheduled( $this->hook() ) ) {
			return;
		}

		// Jittered across the whole interval rather than "now + interval", so installs that
		// activated in the same release window do not stay in lockstep forever.
		wp_schedule_single_event( time() + $this->jitter(), $this->hook() );
	}

	/**
	 * Arm the next flush, called after one completes.
	 *
	 * @return void
	 */
	public function reschedule() {
		wp_clear_scheduled_hook( $this->hook() );

		// A full interval plus a small jitter. The jitter is a fraction here rather than the
		// whole interval, because the cadence is already established at this point -- the goal
		// is only to stop identical sites converging, not to randomise the period.
		wp_schedule_single_event( time() + $this->interval() + $this->jitter( 300 ), $this->hook() );
	}

	/**
	 * Arm the next flush after a short backoff, instead of a full interval.
	 *
	 * Used when a send failed but is worth retrying. The normal interval is a day, so without
	 * this a transient network blip would cost a full day of delay; with it the retry happens in
	 * minutes. It is deliberately still a scheduled run and never an inline retry -- retrying
	 * inside the same request is how an SDK becomes the thing site owners blame for slowness.
	 *
	 * @param int $seconds Delay before the next attempt.
	 * @return void
	 */
	public function reschedule_after( $seconds ) {
		$seconds = max( 1, (int) $seconds );

		wp_clear_scheduled_hook( $this->hook() );
		wp_schedule_single_event( time() + $seconds, $this->hook() );
	}

	/**
	 * Remove the schedule.
	 *
	 * @return void
	 */
	public function unschedule() {
		wp_clear_scheduled_hook( $this->hook() );
	}

	/**
	 * A random offset, defaulting to the whole interval.
	 *
	 * @param int|null $max Upper bound in seconds; the full interval when omitted.
	 * @return int
	 */
	private function jitter( $max = null ) {
		$max = null === $max ? $this->interval() : (int) $max;

		if ( $max < 1 ) {
			return 0;
		}

		// wp_rand() rather than rand(): seeded consistently by WordPress and available wherever
		// WP is loaded.
		if ( function_exists( 'wp_rand' ) ) {
			return (int) wp_rand( 0, $max );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.rand_mt_rand -- this branch runs only when wp_rand() does not exist, so the recommended alternative is unavailable by definition.
		return mt_rand( 0, $max );
	}
}
