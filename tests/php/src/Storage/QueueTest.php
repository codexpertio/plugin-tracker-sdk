<?php
/**
 * Tests for the bounded, option-backed event buffer.
 *
 * @package Codexpert\PluginTracker\Test
 */

namespace Codexpert\PluginTracker\Test\Storage;

use Codexpert\PluginTracker\Storage\Queue;
use Codexpert\PluginTracker\Test\PluginTrackerTestCase;

/**
 * @covers \Codexpert\PluginTracker\Storage\Queue
 */
class QueueTest extends PluginTrackerTestCase {

	/**
	 * Pushing past MAX_PENDING keeps exactly the cap and drops the OLDEST events -- not the
	 * newest. The surviving first element must be the 11th event pushed (index 10), because
	 * MAX_PENDING (200) + 10 pushes means the first 10 (indices 0-9) fell off the front.
	 */
	public function test_push_beyond_max_pending_drops_oldest_and_keeps_the_cap() {
		$queue = new Queue( $this->make_config() );
		$total = Queue::MAX_PENDING + 10;

		for ( $i = 0; $i < $total; $i++ ) {
			$queue->push(
				array(
					'event' => 'install',
					'n'     => $i,
				)
			);
		}

		$all = $queue->all();

		$this->assertCount( Queue::MAX_PENDING, $all );
		$this->assertSame( 10, $all[0]['n'], 'the surviving first element must be the 11th pushed, not the 1st' );
		$this->assertSame( $total - 1, $all[ count( $all ) - 1 ]['n'], 'the most recent push must survive' );
	}

	/**
	 * peek_batch() never returns more than MAX_BATCH events, even when far more are pending.
	 */
	public function test_peek_batch_caps_at_max_batch() {
		$queue = new Queue( $this->make_config() );

		for ( $i = 0; $i < Queue::MAX_BATCH + 20; $i++ ) {
			$queue->push(
				array(
					'event' => 'install',
					'n'     => $i,
				)
			);
		}

		$this->assertCount( Queue::MAX_BATCH, $queue->peek_batch() );
	}

	/**
	 * forget( $n ) removes from the FRONT of the queue -- i.e. the events already accepted by the
	 * server, which are always the oldest ones peeked.
	 */
	public function test_forget_removes_from_the_front() {
		$queue = new Queue( $this->make_config() );

		for ( $i = 0; $i < 5; $i++ ) {
			$queue->push(
				array(
					'event' => 'install',
					'n'     => $i,
				)
			);
		}

		$queue->forget( 2 );

		$remaining = $queue->all();

		$this->assertCount( 3, $remaining );
		$this->assertSame( array( 2, 3, 4 ), array_column( $remaining, 'n' ) );
	}

	/**
	 * forget() with a non-positive count is a no-op, not an accidental full-clear.
	 */
	public function test_forget_with_non_positive_count_is_a_no_op() {
		$queue = new Queue( $this->make_config() );
		$queue->push( array( 'event' => 'install' ) );

		$queue->forget( 0 );
		$queue->forget( -5 );

		$this->assertCount( 1, $queue->all() );
	}

	/**
	 * A batch whose encoded size would exceed MAX_BYTES is trimmed from the end until it fits --
	 * checked against the ENCODED size, not the event count, because one oversized event must not
	 * silently blow the request past the server's body limit.
	 */
	public function test_peek_batch_trims_an_oversized_batch_to_fit_max_bytes() {
		$queue = new Queue( $this->make_config() );

		// Each event encodes to roughly 2 KB; MAX_BATCH (50) of them would be ~100 KB, well over
		// the 64 KB cap, so peek_batch() must return fewer than 50.
		for ( $i = 0; $i < Queue::MAX_BATCH; $i++ ) {
			$queue->push(
				array(
					'event' => 'feature',
					'n'     => $i,
					'blob'  => str_repeat( 'x', 2000 ),
				)
			);
		}

		$batch = $queue->peek_batch();

		$this->assertNotEmpty( $batch );
		$this->assertLessThan( Queue::MAX_BATCH, count( $batch ) );
		$this->assertLessThanOrEqual( Queue::MAX_BYTES, strlen( (string) json_encode( $batch ) ) );
	}

	/**
	 * clear() discards everything.
	 */
	public function test_clear_discards_everything() {
		$queue = new Queue( $this->make_config() );
		$queue->push( array( 'event' => 'install' ) );

		$queue->clear();

		$this->assertSame( array(), $queue->all() );
		$this->assertSame( 0, $queue->count() );
	}

	/**
	 * peek_batch() on an empty queue returns an empty array rather than erroring.
	 */
	public function test_peek_batch_on_empty_queue_is_empty() {
		$queue = new Queue( $this->make_config() );

		$this->assertSame( array(), $queue->peek_batch() );
	}
}
