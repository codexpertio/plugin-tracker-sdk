<?php
/**
 * The double consent gate.
 *
 * @package Codexpert\PluginTracker
 */

namespace Codexpert\PluginTracker\Consent;

use Codexpert\PluginTracker\Config;

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
	 */
	const POLICY = 1;

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
	 * Record an explicit opt-out.
	 *
	 * The record is kept rather than deleted, so the SDK knows the admin was asked and declined
	 * and does not nag them again on every page load.
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
