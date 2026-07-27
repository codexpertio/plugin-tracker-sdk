<?php
/**
 * Frozen-contract numbers, pinned against hand-typed literals.
 *
 * Every other test in this suite that touches one of these constants compares behaviour against
 * the constant itself (e.g. `assertSame( Tracker::MAX_ATTEMPTS, $attempts )`), which proves the
 * CODE is internally consistent but proves nothing about the VALUE: rescale MAX_ATTEMPTS from 6 to
 * 2 everywhere it is used and every such assertion still passes, because both sides moved together.
 *
 * These values are the frozen wire/behavioural contract documented in docs/EVENTS.md and
 * docs/WIRE.md. Changing any one of them is a contract change -- it must be a deliberate edit to
 * both the constant AND this file (and usually the docs), never an accidental side effect of an
 * unrelated refactor. That is why every comparison below is against a literal, not a re-derived
 * value.
 *
 * @package Codexpert\PluginTracker\Test
 */

namespace Codexpert\PluginTracker\Test;

use Codexpert\PluginTracker\Consent\Gate;
use Codexpert\PluginTracker\Cron\Scheduler;
use Codexpert\PluginTracker\Event;
use Codexpert\PluginTracker\Storage\Queue;
use Codexpert\PluginTracker\Tracker;

/**
 * @covers \Codexpert\PluginTracker\Tracker
 * @covers \Codexpert\PluginTracker\Event
 * @covers \Codexpert\PluginTracker\Consent\Gate
 * @covers \Codexpert\PluginTracker\Cron\Scheduler
 * @covers \Codexpert\PluginTracker\Storage\Queue
 */
class ContractTest extends PluginTrackerTestCase {

	/**
	 * docs/WIRE.md: "Max 6 attempts, after which the batch is dropped and counted."
	 */
	public function test_max_attempts_is_frozen_at_six() {
		$this->assertSame( 6, Tracker::MAX_ATTEMPTS );
	}

	/**
	 * docs/WIRE.md: "Exponential backoff with full jitter, base 60s, doubling per attempt..."
	 */
	public function test_retry_base_is_frozen_at_sixty_seconds() {
		$this->assertSame( 60, Tracker::RETRY_BASE );
	}

	/**
	 * docs/WIRE.md: "...capped at 21600s (6h)."
	 */
	public function test_retry_cap_is_frozen_at_21600_seconds() {
		$this->assertSame( 21600, Tracker::RETRY_CAP );
	}

	/**
	 * docs/WIRE.md: the envelope's `"schema": 1` field. This is the payload contract version; see
	 * TrackerTest::test_flush_transmits_the_frozen_schema_version_on_the_wire() for proof that the
	 * value transmitted on the wire actually equals this constant, not just that the constant holds
	 * this value in isolation.
	 */
	public function test_event_schema_is_frozen_at_one() {
		$this->assertSame( 1, Event::SCHEMA );
	}

	/**
	 * docs/CONSENT.md / docs/WIRE.md's `"consent": { "policy": 1, ... }`: the consent-text version
	 * an admin's stored agreement is checked against.
	 */
	public function test_consent_policy_is_frozen_at_one() {
		$this->assertSame( 1, Gate::POLICY );
	}

	/**
	 * docs/WIRE.md: `"flush_interval": 86400` is the default in the registration response example,
	 * and Scheduler::DEFAULT_INTERVAL is the fallback when no server-supplied value has been
	 * stored.
	 */
	public function test_default_interval_is_frozen_at_one_day() {
		$this->assertSame( 86400, Scheduler::DEFAULT_INTERVAL );
	}

	/**
	 * The floor a server-supplied interval may never be narrowed below, so a bad value cannot turn
	 * the SDK into a flood.
	 */
	public function test_min_interval_is_frozen_at_one_hour() {
		$this->assertSame( 3600, Scheduler::MIN_INTERVAL );
	}

	/**
	 * docs/EVENTS.md: "...the queue trims oldest-first beyond 200 pending."
	 */
	public function test_queue_max_pending_is_frozen_at_two_hundred() {
		$this->assertSame( 200, Queue::MAX_PENDING );
	}

	/**
	 * docs/EVENTS.md / docs/WIRE.md: "Maximum 50 events per request."
	 */
	public function test_queue_max_batch_is_frozen_at_fifty() {
		$this->assertSame( 50, Queue::MAX_BATCH );
	}

	/**
	 * docs/WIRE.md: "...max 64 KB encoded."
	 */
	public function test_queue_max_bytes_is_frozen_at_64kb() {
		$this->assertSame( 65536, Queue::MAX_BYTES );
	}

	/**
	 * Not directly documented as a headline number the way the three above are, but still part of
	 * the same frozen byte-axis contract: the per-event and whole-queue caps that Storage\Queue
	 * enforces around MAX_BYTES/MAX_PENDING. Included here for the same reason -- the task's own
	 * observation that these caps "can also be coherently rescaled with all tests passing" applies
	 * to these two exactly as much as to the three explicitly named ones.
	 */
	public function test_queue_max_event_bytes_is_frozen_at_16kb() {
		$this->assertSame( 16384, Queue::MAX_EVENT_BYTES );
	}

	/**
	 * The byte-axis bound on the whole pending queue, independent of MAX_PENDING's count-axis
	 * bound.
	 */
	public function test_queue_max_queue_bytes_is_frozen_at_256kb() {
		$this->assertSame( 262144, Queue::MAX_QUEUE_BYTES );
	}

	/**
	 * The envelope identifies the reporting plugin by `hash`, the value the dashboard-issued snippet
	 * carries. `project` predates it and is still accepted by Config for older integrations, but it
	 * is NOT what goes on the wire -- nothing asserted that until now, so the switch from `project`
	 * to `hash` passed the whole suite silently.
	 */
	public function test_the_envelope_identifies_the_plugin_by_hash_not_project() {
		$tracker    = \Codexpert\PluginTracker\Tracker::init(
			$this->config_args( array( 'enabled' => true ) )
		);
		$reflection = new \ReflectionMethod( $tracker, 'envelope' );
		$reflection->setAccessible( true );
		$envelope = $reflection->invoke( $tracker, array() );

		$this->assertArrayHasKey( 'hash', $envelope, 'the envelope must carry the snippet hash' );
		$this->assertSame( 'a1b2c3d4a1b2c3d4a1b2c3d4a1b2c3d4', $envelope['hash'] );
		$this->assertArrayNotHasKey( 'project', $envelope, 'project is legacy and must not be transmitted' );
	}
}
