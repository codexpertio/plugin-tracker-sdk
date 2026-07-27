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

		if ( ! get_option( $key ) ) {
			update_option( $key, time(), false );
			$this->tracker->track( Event::INSTALL );
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
