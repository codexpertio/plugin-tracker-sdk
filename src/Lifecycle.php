<?php
/**
 * The SDK's own lifecycle listeners.
 *
 * @package Codexpert\PluginTracker
 */

namespace Codexpert\PluginTracker;

/**
 * Registers activation, deactivation and init listeners on the consumer's behalf.
 *
 * This is what makes the generated snippet self-contained: a developer pastes one block into their
 * main plugin file, before initialising their own plugin, and never writes a `register_*_hook` or a
 * `track()` call. Every event in docs/EVENTS.md is raised from here.
 *
 * Why the main plugin file is mandatory (Config::file()): register_activation_hook() keys on
 * plugin_basename( $file ). Given anything other than the file carrying the plugin header, it
 * computes a basename WordPress never fires -- so the hooks are silently never called, nothing
 * errors, and data simply never arrives. Config validates that up front rather than letting it fail
 * quietly in production.
 *
 * Everything here routes through Tracker::track(), so the consent gate applies unchanged: with no
 * consent, nothing is queued, let alone sent.
 *
 * Deactivation is the one listener that also sends, rather than only queueing. It has to: it is the
 * event whose own delivery mechanism the act of deactivating removes. Tracker::flush_on_deactivation()
 * carries the reasoning and the cost.
 */
class Lifecycle {

	/**
	 * Config.
	 *
	 * @var Config
	 */
	private $config;

	/**
	 * The facade, so every event goes through the same consent gate and queue.
	 *
	 * @var Tracker
	 */
	private $tracker;

	/**
	 * Constructor.
	 *
	 * @param Config  $config  Config.
	 * @param Tracker $tracker Facade.
	 */
	public function __construct( Config $config, Tracker $tracker ) {
		$this->config  = $config;
		$this->tracker = $tracker;
	}

	/**
	 * Register the listeners.
	 *
	 * @return void
	 */
	public function register() {

		$file = $this->config->file();

		if ( '' !== $file && function_exists( 'register_activation_hook' ) ) {
			register_activation_hook( $file, array( $this, 'on_activate' ) );
			register_deactivation_hook( $file, array( $this, 'on_deactivate' ) );
		}

		// Version and environment drift are detected on init rather than on activation, because a
		// plugin updated in place -- the normal case -- never fires the activation hook again. A
		// consumer relying on activation alone would never see an upgrade.
		if ( function_exists( 'add_action' ) ) {
			add_action( 'init', array( $this, 'on_init' ), 20 );
		}
	}

	/**
	 * Activation.
	 *
	 * @return void
	 */
	public function on_activate() {

		// `install` is the first activation ever on this site, `activate` is every activation
		// including that one. Distinguished by a stored marker rather than by "is the version option
		// empty", so a consumer who clears their own options cannot make the site look new.
		$key = $this->config->option( 'installed' );

		// The marker is written only when the event was actually RECORDED, not merely attempted.
		//
		// It used to be written first, unconditionally. On a genuine first install that lost the
		// `install` event permanently: telemetry consent does not exist yet at activation time --
		// the opt-in notice renders on the next page load -- so track() declined and queued
		// nothing, while the marker said the install had been reported. Every later activation
		// then saw the marker and fired `activate` only. `install` was emitted exactly once, at
		// the one moment it could never be delivered, which is why a consented site could sit at
		// zero installs forever.
		//
		// Keyed on the return value, the marker now means what it says: this site's install has
		// been recorded. A site that grants consent later reports its install then -- later than
		// the truth, but the earliest point at which we were allowed to observe it. See
		// on_consent(), which is what makes that happen without waiting for another activation.
		if ( ! get_option( $key ) && $this->tracker->track( Event::INSTALL ) ) {
			update_option( $key, time(), false );
		}

		$this->tracker->track( Event::ACTIVATE );

		// Record the environment now, so the first on_init() after activation does not report a
		// spurious version or compat change against empty state.
		$this->remember_environment();
	}

	/**
	 * Deactivation.
	 *
	 * The event is queued with no reason. The reason, when the administrator gives one, arrives
	 * separately through the feedback modal -- deactivation must never depend on a user filling in
	 * a form, and the modal is dismissible.
	 *
	 * @return void
	 */
	public function on_deactivate() {

		// The modal stashes the chosen reason (never the free text) just before deactivating, and
		// only when telemetry consent is present. Read and clear it here so the reason documented in
		// docs/EVENTS.md is actually populated instead of being permanently absent.
		$key   = $this->config->option( 'reason' );
		$stash = get_option( $key );
		$props = array();

		if ( is_array( $stash ) && ! empty( $stash['reason'] ) ) {
			$props['reason'] = (string) $stash['reason'];
		}

		delete_option( $key );

		$this->tracker->track( Event::DEACTIVATION, $props );

		// Sent now, not queued for cron. Deactivating removes the flush callback along with the
		// rest of the plugin, so there is no later run that can deliver this -- see
		// Tracker::flush_on_deactivation() for what goes wrong if it is left to the schedule.
		$this->tracker->flush_on_deactivation();
	}

	/**
	 * Backfill the events this site owes, now that it is allowed to report them.
	 *
	 * Called from Tracker::handle_consent() when an administrator opts in.
	 *
	 * Consent almost never exists when the activation hook fires. On a first install the opt-in
	 * notice has not rendered yet, so on_activate() declines to record anything and returns having
	 * queued nothing. Without this, `install` and `activate` would then wait for the NEXT
	 * activation -- which, for a plugin somebody installs once and leaves running, never comes. The
	 * site would report `version` and `compat` drift for years and never report existing.
	 *
	 * The install marker is the guard, and it is the right one: it means "this site's install has
	 * been recorded", so an unset marker is exactly the case that needs backfilling. A site that
	 * already reported its install gets nothing here, so a double-submitted consent form cannot
	 * manufacture a second `activate`.
	 *
	 * @return void
	 */
	public function on_consent() {

		if ( get_option( $this->config->option( 'installed' ) ) ) {
			return;
		}

		$this->on_activate();
	}

	/**
	 * Detect version and environment drift.
	 *
	 * @return void
	 */
	public function on_init() {

		$seen = get_option( $this->config->option( 'env' ) );
		$seen = is_array( $seen ) ? $seen : array();
		$now  = $this->environment();

		// Nothing recorded yet: adopt the current environment silently. Reporting a change against
		// empty state would turn every fresh install into a phantom upgrade.
		if ( empty( $seen ) ) {
			$this->remember_environment();
			return;
		}

		if ( isset( $seen['plugin_version'] ) && $seen['plugin_version'] !== $now['plugin_version'] ) {
			$this->tracker->track( Event::VERSION, array( 'from' => (string) $seen['plugin_version'] ) );
		}

		foreach ( array( 'wp', 'php' ) as $axis ) {
			if ( isset( $seen[ $axis ] ) && $seen[ $axis ] !== $now[ $axis ] ) {
				$this->tracker->track(
					Event::COMPAT,
					array(
						'what' => $axis,
						'from' => (string) $seen[ $axis ],
					)
				);
			}
		}

		if ( $seen !== $now ) {
			$this->remember_environment();
		}
	}

	/**
	 * The environment as it stands.
	 *
	 * @return array
	 */
	private function environment() {
		return array(
			'plugin_version' => $this->config->version(),
			'wp'             => function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'version' ) : '',
			'php'            => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
		);
	}

	/**
	 * Persist the current environment.
	 *
	 * @return void
	 */
	private function remember_environment() {
		update_option( $this->config->option( 'env' ), $this->environment(), false );
	}

	/**
	 * Forget lifecycle state.
	 *
	 * @return void
	 */
	public function forget() {
		delete_option( $this->config->option( 'env' ) );
		delete_option( $this->config->option( 'installed' ) );
		delete_option( $this->config->option( 'reason' ) );
	}
}
