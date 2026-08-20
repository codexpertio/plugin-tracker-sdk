<?php
/**
 * The double consent gate.
 *
 * @package Codexpert\PluginTracker
 */

namespace Codexpert\PluginTracker\Consent;

use Codexpert\PluginTracker\Config;
use Codexpert\PluginTracker\Cron\Scheduler;
use Codexpert\PluginTracker\Storage\Install;
use Codexpert\PluginTracker\Storage\Queue;

/**
 * Nothing is transmitted unless BOTH gates pass.
 *
 * Issue #40 requires double consent: the plugin author enables telemetry for their project, AND
 * the site administrator explicitly opts in. Either one alone is not enough.
 *
 * This class is the reason the SDK is safe for a consumer to list on WordPress.org. Sending user
 * data off-site without explicit opt-in breaches the Plugin Directory guidelines, and the
 * consumer -- not us -- is the one who gets removed. So the compliant path is the default and
 * the gate is fail-closed at every step.
 */
class Gate {

	/**
	 * Which consent text the admin agreed to. Bump when the wording materially changes, so a
	 * stale agreement is detectable rather than assumed to still hold.
	 *
	 * History, because a bump is not free -- it re-prompts every site that had already agreed:
	 *
	 *   1: the original wording.
	 *   2: says the programme is a beta. Issue #40 is "consent (opt-in beta)" and PLANS.md §11.E
	 *      calls it "Telemetry beta" with "supported beta events" -- so the event set, the retention
	 *      window and the endpoints may still change. An administrator deciding whether to share
	 *      data should be told that, and the notice did not say it.
	 *
	 * Bumped to 2 while the SDK is unreleased, deliberately: the re-prompt costs nothing today and
	 * would cost every consumer's users a fresh prompt after publication.
	 */
	const POLICY = 2;

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
	 * May the SDK transmit?
	 *
	 * @return bool
	 */
	public function granted() {

		// Site-level kill switch, checked first and unconditionally. A site owner or host must
		// be able to stop all telemetry without touching plugin settings, and that decision
		// outranks everything below it.
		if ( defined( 'CX_TRACKER_DISABLE' ) && CX_TRACKER_DISABLE ) {
			return false;
		}

		// Gate 1: the plugin author enabled telemetry for this project.
		if ( ! $this->config->enabled() ) {
			return false;
		}

		// Gate 2: this site's administrator explicitly opted in.
		if ( ! $this->site_opted_in() ) {
			return false;
		}

		/**
		 * Final veto for site owners and hosts.
		 *
		 * Filtered last so it can only ever turn transmission OFF in practice -- everything
		 * above has already had to pass. Deliberately not a way to turn consent ON.
		 *
		 * @param bool   $granted Whether transmission is permitted.
		 * @param string $plugin  Consumer plugin slug.
		 */
		if ( function_exists( 'apply_filters' ) ) {
			return (bool) apply_filters( 'cx_tracker_consent', true, $this->config->plugin() );
		}

		return true;
	}

	/**
	 * Has the site administrator opted in, under the current policy version?
	 *
	 * @return bool
	 */
	public function site_opted_in() {
		$record = get_option( $this->config->option( 'consent' ) );

		if ( ! is_array( $record ) || empty( $record['opted_in'] ) ) {
			return false;
		}

		// A recorded agreement to an OLDER policy does not carry forward. If the consent text
		// changed materially, the previous agreement was to different terms, so it no longer
		// counts and the admin is asked again.
		if ( ! isset( $record['policy'] ) || self::POLICY !== (int) $record['policy'] ) {
			return false;
		}

		return true;
	}

	/**
	 * Record an explicit opt-in.
	 *
	 * @return void
	 */
	public function opt_in() {
		update_option(
			$this->config->option( 'consent' ),
			array(
				'opted_in' => true,
				'policy'   => self::POLICY,
				'at'       => time(),
			),
			false
		);
	}

	/**
	 * Record an explicit opt-out, and discard what the site said no to.
	 *
	 * The record itself is kept rather than deleted, so the SDK knows the admin was asked and
	 * declined and does not nag them again on every page load. Everything else goes.
	 *
	 * ## Why the discarding happens HERE
	 *
	 * It used to live in Tracker::handle_consent(), the admin-post handler behind the built-in
	 * notice, and only there. docs/CONSENT.md has always promised that "state does not survive an
	 * explicit opt-out" -- and that promise was true of the form and false of the API. Tracker
	 * exposes consent() precisely so a consumer can render their own opt-out UI, and a consumer who
	 * did that recorded the refusal while keeping the install token, the queued events, the armed
	 * flush and the salt the anonymous install ID is derived from.
	 *
	 * That is the worst possible place for the two paths to disagree. A site that declined kept a
	 * live ingestion credential and the identifier its previously reported data is keyed to, and
	 * the consumer had no way to know: they called the method the docblock pointed them at.
	 *
	 * A guarantee that only holds when you enter through one door is not a guarantee. It belongs at
	 * the point the decision is recorded, which is here.
	 *
	 * @return void
	 */
	public function opt_out() {
		update_option(
			$this->config->option( 'consent' ),
			array(
				'opted_in' => false,
				'policy'   => self::POLICY,
				'at'       => time(),
			),
			false
		);

		$this->discard_state();
	}

	/**
	 * Everything an opt-out has to take with it.
	 *
	 * Collaborators are constructed from Config rather than injected, which keeps opt_out() callable
	 * from anywhere holding a Gate -- including a consumer's own settings screen. Each of Queue,
	 * Scheduler and Install takes nothing but Config and owns one option, so there is no shared state
	 * for a second instance to disagree with.
	 *
	 * Order matters at the end: the salt goes LAST. The install ID is derived from it, so anything
	 * that still wants to name this install has to do so before that line runs.
	 *
	 * The retry counters are deliberately NOT touched here. They are integers describing how a
	 * transmission went, they identify nothing and correlate to nothing, and Tracker owns them --
	 * this method is for what a site declining could be tracked BY.
	 *
	 * @return void
	 */
	private function discard_state() {

		// The schedule first, so nothing can fire mid-teardown and re-register against the token
		// this is about to delete.
		( new Scheduler( $this->config ) )->unschedule();

		// Events collected under the previous consent. CONSENT.md's rule is that consent precedes
		// collection, not merely transmission, so holding them for a possible future opt-in is not
		// an option.
		( new Queue( $this->config ) )->clear();

		// The ingestion credential. Keeping a live token for a site that has explicitly said no is
		// not defensible, and re-registration is cheap if they opt back in.
		delete_option( $this->config->option( 'token' ) );

		// Consequence, accepted deliberately and documented in CONSENT.md: a site that opts out and
		// later opts back in gets a NEW install ID, so it counts more than once on the dashboard.
		// Leaving a live identifier on a site that declined is the worse trade.
		( new Install( $this->config ) )->forget();
	}

	/**
	 * Has the admin been asked yet, either way?
	 *
	 * @return bool
	 */
	public function answered() {
		$record = get_option( $this->config->option( 'consent' ) );

		return is_array( $record ) && isset( $record['policy'] ) && self::POLICY === (int) $record['policy'];
	}

	/**
	 * The consent record, for the registration payload.
	 *
	 * @return array
	 */
	public function record() {
		$record = get_option( $this->config->option( 'consent' ) );

		return array(
			'policy' => self::POLICY,
			'at'     => is_array( $record ) && isset( $record['at'] ) ? (int) $record['at'] : 0,
		);
	}

	/**
	 * Erase the consent record entirely.
	 *
	 * @return void
	 */
	public function forget() {
		delete_option( $this->config->option( 'consent' ) );
	}
}
