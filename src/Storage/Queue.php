<?php
/**
 * The local event buffer.
 *
 * @package Codexpert\PluginTracker
 */

namespace Codexpert\PluginTracker\Storage;

use Codexpert\PluginTracker\Config;

/**
 * An option-backed, bounded event buffer.
 *
 * Option-backed rather than table-backed on purpose. A bundled SDK has no activation hook of its
 * own and cannot reliably create or migrate a table inside someone else's plugin -- and a
 * telemetry buffer is not worth the schema risk. The cost is that the buffer must stay small,
 * which the caps below enforce.
 *
 * Bounded rather than growing: if the endpoint is unreachable for a month, an unbounded queue
 * would grow without limit inside a site's options table. Telemetry is the least important data
 * on the site, so it is the first thing to discard.
 */
class Queue {

	/**
	 * Hard cap on pending events. Beyond this the OLDEST are dropped.
	 *
	 * Oldest-first because recent events are more useful than stale ones, and because dropping
	 * newest would make the queue permanently stuck at the cap with nothing new ever recorded.
	 */
	const MAX_PENDING = 200;

	/**
	 * Maximum events per request.
	 */
	const MAX_BATCH = 50;

	/**
	 * Maximum encoded body size, bytes.
	 */
	const MAX_BYTES = 65536;

	/**
	 * Maximum encoded size of a SINGLE event, bytes.
	 *
	 * An event larger than MAX_BYTES can never be sent, so storing one wedges the queue
	 * permanently: peek_batch() trims until the batch fits, reaches empty, and every later flush
	 * finds nothing to send while events pile up behind it -- silently, with nothing counted as
	 * dropped. Rejected at push() instead. 16 KB is far above any documented event, which carries
	 * a handful of short fields, so hitting this is a consumer bug worth surfacing.
	 */
	const MAX_EVENT_BYTES = 16384;

	/**
	 * Maximum encoded size of the whole pending queue, bytes.
	 *
	 * MAX_PENDING bounds the queue by COUNT, which is not a bound on bytes: 200 large events
	 * measured over 11 MB in one wp_options row, re-read and re-written on every push. This is the
	 * byte-axis bound.
	 */
	const MAX_QUEUE_BYTES = 262144;

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
	 * Option key for the queue.
	 *
	 * @return string
	 */
	private function key() {
		return $this->config->option( 'queue' );
	}

	/**
	 * All pending events.
	 *
	 * @return array
	 */
	public function all() {
		$queue = get_option( $this->key() );

		return is_array( $queue ) ? array_values( $queue ) : array();
	}

	/**
	 * How many events are pending.
	 *
	 * @return int
	 */
	public function count() {
		return count( $this->all() );
	}

	/**
	 * Append an event.
	 *
	 * @param array $event A fully-formed event array.
	 * @return bool False when the event was too large to store.
	 */
	public function push( array $event ) {

		// Reject an unsendable event rather than storing it; see MAX_EVENT_BYTES.
		if ( strlen( (string) wp_json_encode( $event ) ) > self::MAX_EVENT_BYTES ) {
			return false;
		}

		$queue   = $this->all();
		$queue[] = $event;

		if ( count( $queue ) > self::MAX_PENDING ) {
			$queue = array_slice( $queue, -self::MAX_PENDING );
		}

		// Byte bound, oldest-first, so a burst of large events cannot grow the options row without
		// limit. After the count bound because it is the more expensive check.
		// phpcs:ignore Squiz.PHP.DisallowSizeFunctionsInLoops.Found -- the collection shrinks every pass, so the encoded length genuinely has to be recomputed; hoisting it would compare against a stale size.
		while ( count( $queue ) > 1 && strlen( (string) wp_json_encode( $queue ) ) > self::MAX_QUEUE_BYTES ) {
			array_shift( $queue );
		}

		$this->write( $queue );

		return true;
	}

	/**
	 * Take the next batch without removing it.
	 *
	 * Not removed until the transport confirms acceptance -- otherwise a failed request loses
	 * the events it was carrying.
	 *
	 * @return array
	 */
	public function peek_batch() {
		$all = $this->all();

		// Defensive: drop a head event that could never be sent. push() rejects these now, but a
		// queue written by an earlier build of this SDK may still hold one, and a bundled consumer
		// cannot be updated. Without this the queue stays wedged on that event forever.
		// phpcs:ignore Squiz.PHP.DisallowSizeFunctionsInLoops.Found -- the collection shrinks every pass, so the encoded length genuinely has to be recomputed; hoisting it would compare against a stale size.
		while ( ! empty( $all ) && strlen( (string) wp_json_encode( array( $all[0] ) ) ) > self::MAX_BYTES ) {
			array_shift( $all );
			$this->forget( 1 );
		}

		$batch = array_slice( $all, 0, self::MAX_BATCH );

		// Trim until the encoded batch fits. Checked on the ENCODED size because that is what the
		// server limit applies to; counting events would not catch one oversized batch.
		// phpcs:ignore Squiz.PHP.DisallowSizeFunctionsInLoops.Found -- the collection shrinks every pass, so the encoded length genuinely has to be recomputed; hoisting it would compare against a stale size.
		while ( ! empty( $batch ) && strlen( (string) wp_json_encode( $batch ) ) > self::MAX_BYTES ) {
			array_pop( $batch );
		}

		return $batch;
	}

	/**
	 * Remove the first N events, after they were accepted.
	 *
	 * @param int $count How many to drop from the front.
	 * @return void
	 */
	public function forget( $count ) {
		$count = (int) $count;

		if ( $count < 1 ) {
			return;
		}

		$this->write( array_slice( $this->all(), $count ) );
	}

	/**
	 * Discard everything.
	 *
	 * @return void
	 */
	public function clear() {
		delete_option( $this->key() );
	}

	/**
	 * Persist the queue.
	 *
	 * @param array $queue Events.
	 * @return void
	 */
	private function write( array $queue ) {

		if ( empty( $queue ) ) {
			delete_option( $this->key() );
			return;
		}

		// autoload = no. The queue is only touched on the scheduled flush and when an event is
		// recorded, so loading it into alloptions on every request would be pure overhead on a
		// site that has done nothing.
		if ( false === get_option( $this->key() ) ) {
			add_option( $this->key(), $queue, '', false );
			return;
		}

		update_option( $this->key(), $queue, false );
	}
}
