<?php
/**
 * Public facade.
 *
 * @package Codexpert\PluginTracker
 */

namespace Codexpert\PluginTracker;

use Codexpert\PluginTracker\Consent\Gate;
use Codexpert\PluginTracker\Consent\Notice;
use Codexpert\PluginTracker\Cron\Scheduler;
use Codexpert\PluginTracker\Http\Transport;
use Codexpert\PluginTracker\Privacy\Personal_Data;
use Codexpert\PluginTracker\Storage\Install;
use Codexpert\PluginTracker\Storage\Queue;

/**
 * The only class a consumer needs to touch.
 *
 * Two invariants govern everything here, and both are about not damaging the consumer:
 *
 *   1. NOTHING is transmitted before both consent gates pass (author enable + admin opt-in).
 *   2. NOTHING blocks, delays or breaks the consumer's plugin. No network call in an activation
 *      hook, no fatal if the endpoint is down, no exception escaping into someone else's code.
 *
 * The second is why track() only ever writes to a local queue, and why every public method
 * returns rather than throws.
 *
 * WHY THIS CLASS IS AT THE ROOT OF src/ (and Config and Event with it)
 * ---------------------------------------------------------------------------------------------
 * src/ is organised into sub-namespaces by responsibility -- Consent\, Storage\, Http\, Cron\,
 * Privacy\ -- and exactly three classes deliberately stay at the root:
 *
 *   - Tracker, because it is the single public entry point. A consumer writes
 *     Codexpert\PluginTracker\Tracker::init(); pushing it into a sub-namespace would make the one
 *     FQCN every consumer must type longer and less obvious, for no gain.
 *   - Config and Event, because they are the contract types EVERY sub-namespace depends on. Both
 *     are imported by classes in several sub-namespaces, so putting either inside one of them
 *     would arbitrarily privilege that sub-namespace and create an import direction that reads
 *     backwards (e.g. Http\Transport importing Storage\Config).
 *
 * The dependency direction is therefore one-way and easy to check: sub-namespaces import from the
 * root, never the reverse -- except Tracker itself, which composes all of them because that is
 * precisely its job as the facade.
 */
class Tracker {

	/**
	 * SDK version. Transmitted so ingestion can attribute traffic and drive deprecation notices.
	 */
	const VERSION = '1.0.0';

	/**
	 * How many times one batch may be retried before it is dropped.
	 *
	 * Bounded because the alternative is an unbounded retry loop against an endpoint that may
	 * never recover, on a site we cannot reach. Telemetry is the least important data on the
	 * site, so it is the first thing to give up on.
	 */
	const MAX_ATTEMPTS = 6;

	/**
	 * Backoff base, seconds. Doubles per attempt: 60, 120, 240, 480, 960, 1920.
	 */
	const RETRY_BASE = 60;

	/**
	 * Ceiling on a single backoff delay, so a server-supplied Retry-After cannot park the queue
	 * for days.
	 */
	const RETRY_CAP = 21600;

	/**
	 * Live instances, keyed by plugin slug, so two consumers in one process stay independent.
	 *
	 * @var array
	 */
	private static $instances = array();

	/**
	 * Config.
	 *
	 * @var Config
	 */
	private $config;

	/**
	 * Consent gate.
	 *
	 * @var Gate
	 */
	private $consent;

	/**
	 * Install identity.
	 *
	 * @var Install
	 */
	private $install;

	/**
	 * Event buffer.
	 *
	 * @var Queue
	 */
	private $queue;

	/**
	 * Flush schedule.
	 *
	 * @var Scheduler
	 */
	private $scheduler;

	/**
	 * HTTP transport.
	 *
	 * @var Transport
	 */
	private $transport;

	/**
	 * Constructor.
	 *
	 * @param Config $config Validated config.
	 */
	private function __construct( Config $config ) {
		$this->config    = $config;
		$this->consent   = new Gate( $config );
		$this->install   = new Install( $config );
		$this->queue     = new Queue( $config );
		$this->scheduler = new Scheduler( $config );
		$this->transport = new Transport( $config );
	}

	/**
	 * Initialise for a consumer plugin.
	 *
	 * Returns null on invalid config rather than throwing. A consumer's site must not white-screen
	 * because our SDK was misconfigured; the errors are surfaced through a wp-admin notice in
	 * development instead.
	 *
	 * @param array $args project, plugin, version, enabled, endpoint.
	 * @return Tracker|null
	 */
	public static function init( array $args ) {

		$config = new Config( $args );

		if ( ! $config->is_valid() ) {
			Notice::config_errors( $config );
			return null;
		}

		$slug = $config->plugin();

		if ( isset( self::$instances[ $slug ] ) ) {
			return self::$instances[ $slug ];
		}

		$tracker                  = new self( $config );
		self::$instances[ $slug ] = $tracker;
		$tracker->hook();

		return $tracker;
	}

	/**
	 * Retrieve an initialised instance.
	 *
	 * @param string $plugin Consumer plugin slug.
	 * @return Tracker|null
	 */
	public static function instance( $plugin ) {
		return isset( self::$instances[ $plugin ] ) ? self::$instances[ $plugin ] : null;
	}

	/**
	 * Reset all instances. Test seam only.
	 *
	 * @return void
	 */
	public static function reset() {
		self::$instances = array();
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	private function hook() {

		// Before anything user-facing runs, so the consent prompt is translated on first render.
		I18n::load();

		add_action( $this->scheduler->hook(), array( $this, 'flush' ) );

		// The consent prompt and any deprecation notice are admin-only concerns.
		if ( function_exists( 'is_admin' ) && is_admin() ) {
			add_action( 'admin_notices', array( new Notice( $this->config, $this->consent ), 'render' ) );
			add_action( 'admin_post_cx_tracker_consent_' . $this->config->plugin(), array( $this, 'handle_consent' ) );
		}

		// Scheduling is cheap and idempotent, and doing it on every load is what makes a missed
		// single-event re-arm self-healing.
		if ( $this->consent->granted() ) {
			$this->scheduler->ensure_scheduled();
		}

		Personal_Data::register( $this->config, $this->consent, $this->install );
	}

	/**
	 * Record an event.
	 *
	 * Queues locally. Never sends, never blocks, never throws -- this may be called from an
	 * activation hook, and a slow or down endpoint must not delay a consumer's activation.
	 *
	 * @param string $name  Allow-listed event name.
	 * @param array  $props Event-specific properties.
	 * @return bool Whether the event was queued.
	 */
	public function track( $name, array $props = array() ) {

		// Fail closed before anything else. No consent means no queue write either -- not just no
		// send. Buffering events "in case" consent arrives later would mean holding data the
		// admin never agreed to us holding.
		if ( ! $this->consent->granted() ) {
			return false;
		}

		if ( ! Event::is_allowed( $name ) ) {
			return false;
		}

		$error = Event::validate_props( $name, $props );

		if ( null !== $error ) {
			Notice::dev_warning( $this->config, sprintf( 'tracker: %s', $error ) );
			return false;
		}

		// common_fields() is merged LAST, deliberately. Merging $props last let a consumer override
		// every documented field -- plugin, plugin_version, wp, php, locale, multisite, even the
		// timestamp -- so the payload could claim to be from a different plugin, or carry a
		// site address where a version was documented. Environment facts are ours to state, not
		// the caller's to supply.
		$this->queue->push( array_merge( $props, $this->common_fields(), array( 'event' => $name ) ) );

		return true;
	}

	/**
	 * Environment fields attached to every event.
	 *
	 * Every field here is an environment fact. Nothing identifies a person, and nothing
	 * identifies the site -- see docs/EVENTS.md for the drop decisions, particularly why the
	 * active-plugin list is refused.
	 *
	 * @return array
	 */
	private function common_fields() {
		return array(
			'at'             => time(),
			'plugin'         => $this->config->plugin(),
			'plugin_version' => $this->config->version(),
			'wp'             => function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'version' ) : '',
			// Major.minor only. The patch level is needless precision for a compatibility
			// question and adds fingerprint surface.
			'php'            => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
			'locale'         => function_exists( 'get_locale' ) ? (string) get_locale() : '',
			'multisite'      => function_exists( 'is_multisite' ) ? is_multisite() : false,
		);
	}

	/**
	 * Send whatever is queued.
	 *
	 * @param bool $force Bypass the background-request check. Tests and deliberate synchronous
	 *                    flushes only -- never pass true from a page-request code path.
	 * @return void
	 */
	public function flush( $force = false ) {

		// Never on a page request. flush() has to be public because it is an action callback, so a
		// consumer can call it directly -- and doing so would make a blocking HTTP request inside
		// somebody's page load, which is the single behaviour this SDK is most careful to avoid.
		// WP-Cron and WP-CLI are the legitimate callers; $force exists for tests and for a
		// consumer who deliberately wants a synchronous flush.
		if ( ! $force && ! $this->is_background_request() ) {
			return;
		}

		// Re-checked here, not just at track() time. An admin may have opted out after events
		// were queued, and that decision must win.
		if ( ! $this->consent->granted() ) {
			$this->queue->clear();
			$this->reset_attempts();
			$this->scheduler->unschedule();
			return;
		}

		$token = $this->token();

		if ( '' === $token ) {
			$token = $this->register();
		}

		// Fail closed: no token, no transmission. Events stay queued (capped) and registration is
		// retried on the next run.
		if ( '' === $token ) {
			$this->scheduler->reschedule();
			return;
		}

		$batch = $this->queue->peek_batch();

		if ( empty( $batch ) ) {
			$this->scheduler->reschedule();
			return;
		}

		$sent = $this->transport->send( $this->envelope( $batch ), $token );

		// apply() owns rescheduling from here: a retry needs a short backoff rather than a full
		// interval, so the decision belongs with the code that classifies the result.
		$this->apply( $sent, $batch );
	}

	/**
	 * Is this a request where a blocking HTTP call is acceptable?
	 *
	 * @return bool
	 */
	private function is_background_request() {

		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
			return true;
		}

		return defined( 'WP_CLI' ) && WP_CLI;
	}

	/**
	 * Act on a send result.
	 *
	 * @param array $sent  Transport::send() result.
	 * @param array $batch The events that were sent.
	 * @return void
	 */
	private function apply( array $sent, array $batch ) {
		$count = count( $batch );

		if ( ! empty( $sent['notice'] ) ) {
			Notice::remember_server_notice( $this->config, $sent['notice'] );
		}

		if ( Transport::RESULT_OK === $sent['result'] ) {
			// Discarded even on partial acceptance. Ingestion applies the allow-list per event,
			// so a batch containing one disallowed event would otherwise be retried forever.
			$this->queue->forget( $count );
			$this->reset_attempts();

			if ( ! empty( $sent['raw']['body']['data']['flush_interval'] ) ) {
				$this->scheduler->remember_interval( $sent['raw']['body']['data']['flush_interval'] );
			}

			$this->scheduler->reschedule();
			return;
		}

		if ( Transport::RESULT_AUTH === $sent['result'] ) {
			// The token is dead -- revoked, rotated out, or the project was disabled. Discard it
			// so the next run re-registers, rather than replaying a dead credential forever.
			//
			// Deliberately NOT counted against the retry budget: this is a credential problem, not
			// a delivery problem, and the batch itself is still perfectly good. Counting it would
			// throw away valid events because of a rotation.
			$this->forget_token();

			// Short backoff, not a full interval. The batch is still valid and re-registration is
			// cheap, so making a credential rotation cost up to a day of delay would be needless.
			// If re-registration then fails, flush() falls back to the full interval, so a
			// project disabled server-side does not turn into a tight loop.
			$this->scheduler->reschedule_after( self::RETRY_BASE );
			return;
		}

		if ( Transport::RESULT_PERMANENT === $sent['result'] ) {
			// The server will never accept this batch. Retrying cannot fix it, so drop it.
			//
			// Counted, like a budget-exhaustion drop. Both are events that existed and will never
			// arrive, and the counter's whole purpose is to answer "how much did we lose". Counting
			// only one of the two paths would make a server rejecting malformed batches look like
			// no loss at all.
			$this->queue->forget( $count );
			$this->reset_attempts();
			$this->note_dropped( $count );
			$this->scheduler->reschedule();
			return;
		}

		// RESULT_RETRY and RESULT_RATE: the batch is kept and retried, up to the budget.
		$attempts = $this->bump_attempts( $batch );

		if ( $attempts >= self::MAX_ATTEMPTS ) {
			// Budget exhausted. Drop the batch and count it, so a persistently failing endpoint
			// shows up as evidence rather than as silence.
			$this->queue->forget( $count );
			$this->reset_attempts();
			$this->note_dropped( $count );
			$this->scheduler->reschedule();
			return;
		}

		$this->scheduler->reschedule_after( $this->backoff( $attempts, $sent['retry_after'] ) );
	}

	/**
	 * Backoff delay for an attempt: exponential with FULL jitter.
	 *
	 * Full jitter (a random point in [1, window]) rather than the window itself, because every
	 * site running the consumer's plugin fails at the same moment when the endpoint goes down --
	 * a fixed backoff would have them all return in lockstep and re-flatten it on recovery.
	 *
	 * @param int $attempts    Attempts made so far.
	 * @param int $retry_after Server-requested delay, if any.
	 * @return int Seconds.
	 */
	private function backoff( $attempts, $retry_after = 0 ) {
		$retry_after = (int) $retry_after;

		// A server asking for a specific delay is obeyed -- it knows about load we cannot see --
		// but still capped, so one bad header cannot park the queue for days.
		if ( $retry_after > 0 ) {
			return min( $retry_after, self::RETRY_CAP );
		}

		$window = min( self::RETRY_BASE * (int) pow( 2, max( 0, $attempts - 1 ) ), self::RETRY_CAP );

		if ( function_exists( 'wp_rand' ) ) {
			return max( 1, (int) wp_rand( 1, $window ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.rand_mt_rand -- this branch runs only when wp_rand() does not exist, so the recommended alternative is unavailable by definition.
		return max( 1, mt_rand( 1, $window ) );
	}

	/**
	 * A stable identity for a batch, so the retry budget can belong to it.
	 *
	 * @param array $batch Events.
	 * @return string
	 */
	private function fingerprint( array $batch ) {
		return md5( (string) wp_json_encode( $batch ) );
	}

	/**
	 * Attempts made against the batch currently at the head of the queue.
	 *
	 * Scoped to a batch fingerprint, not just to the consumer. A bare counter leaks across
	 * batches: it survives queue->clear() and it survives the head batch changing identity, so an
	 * unrelated batch could inherit a nearly-spent budget and be destroyed on its first failure.
	 * WIRE.md promises six attempts per batch, so the counter has to know which batch it counts.
	 *
	 * @param array $batch Events.
	 * @return int
	 */
	private function attempts( array $batch ) {
		$record = get_option( $this->config->option( 'attempts' ) );

		if ( ! is_array( $record ) || ! isset( $record['fp'], $record['n'] ) ) {
			return 0;
		}

		// A different batch means a fresh budget.
		if ( $this->fingerprint( $batch ) !== $record['fp'] ) {
			return 0;
		}

		return (int) $record['n'];
	}

	/**
	 * Increment and return the attempt count for this batch.
	 *
	 * @param array $batch Events.
	 * @return int
	 */
	private function bump_attempts( array $batch ) {
		$attempts = $this->attempts( $batch ) + 1;

		update_option(
			$this->config->option( 'attempts' ),
			array(
				'fp' => $this->fingerprint( $batch ),
				'n'  => $attempts,
			),
			false
		);

		return $attempts;
	}

	/**
	 * Clear the attempt count.
	 *
	 * @return void
	 */
	private function reset_attempts() {
		delete_option( $this->config->option( 'attempts' ) );
	}

	/**
	 * Count events dropped because the retry budget ran out.
	 *
	 * Kept locally rather than reported: reporting it would need the very transport that just
	 * failed. It is here so a developer debugging "why is there no data" can see the reason on
	 * the site itself.
	 *
	 * @param int $count How many events were dropped.
	 * @return void
	 */
	private function note_dropped( $count ) {
		$key = $this->config->option( 'dropped' );
		update_option( $key, (int) get_option( $key ) + (int) $count, false );
	}

	/**
	 * Build the wire envelope.
	 *
	 * @param array $events Batch.
	 * @return array
	 */
	private function envelope( array $events ) {
		return array(
			'schema'  => Event::SCHEMA,
			'sdk'     => self::VERSION,
			'project' => $this->config->project(),
			'install' => $this->install->id(),
			'sent_at' => time(),
			'events'  => $events,
		);
	}

	/**
	 * Register this install and store the token.
	 *
	 * @return string The token, or '' on failure.
	 */
	private function register() {

		$registered = $this->transport->register(
			array(
				'schema'  => Event::SCHEMA,
				'sdk'     => self::VERSION,
				'project' => $this->config->project(),
				'install' => $this->install->id(),
				'plugin'  => $this->config->plugin(),
				'consent' => $this->consent->record(),
			)
		);

		if ( Transport::RESULT_OK !== $registered['result'] || '' === $registered['token'] ) {
			return '';
		}

		update_option( $this->config->option( 'token' ), $registered['token'], false );

		if ( $registered['flush_interval'] > 0 ) {
			$this->scheduler->remember_interval( $registered['flush_interval'] );
		}

		return $registered['token'];
	}

	/**
	 * The stored install token.
	 *
	 * @return string
	 */
	private function token() {
		$token = get_option( $this->config->option( 'token' ) );

		return is_string( $token ) ? $token : '';
	}

	/**
	 * Discard the stored token.
	 *
	 * @return void
	 */
	private function forget_token() {
		delete_option( $this->config->option( 'token' ) );
	}

	/**
	 * Handle the consent form submission.
	 *
	 * @return void
	 */
	public function handle_consent() {

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'plugin-tracker-sdk' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'cx_tracker_consent_' . $this->config->plugin() );

		$choice = isset( $_POST['choice'] ) ? sanitize_key( wp_unslash( $_POST['choice'] ) ) : '';

		if ( 'in' === $choice ) {
			$this->consent->opt_in();
			$this->scheduler->ensure_scheduled();
		} else {
			$this->consent->opt_out();
			$this->scheduler->unschedule();
			$this->queue->clear();
			$this->reset_attempts();

			// The ingestion credential is dropped too. Keeping a live token for a site that has
			// explicitly said no is not defensible, and re-registration is cheap if they opt back in.
			$this->forget_token();

			// And the salt, which is what the anonymous install ID is derived from. A site that
			// declined should retain nothing that can be correlated to data we already hold.
			// Consequence, accepted deliberately: opting out and back in yields a NEW install ID, so
			// a site that cycles consent counts more than once. Leaving a live identifier on a site
			// that said no is the worse trade.
			$this->install->forget();
		}

		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}

	/**
	 * Consent gate, for consumers that want to render their own UI.
	 *
	 * @return Gate
	 */
	public function consent() {
		return $this->consent;
	}

	/**
	 * Remove all SDK state for this consumer. Call from the consumer's uninstall routine.
	 *
	 * @return void
	 */
	public function uninstall() {
		$this->scheduler->unschedule();
		$this->queue->clear();
		$this->forget_token();
		$this->install->forget();
		$this->consent->forget();
		Notice::forget( $this->config );
		delete_option( $this->config->option( 'interval' ) );
		delete_option( $this->config->option( 'attempts' ) );
		delete_option( $this->config->option( 'dropped' ) );
	}
}
