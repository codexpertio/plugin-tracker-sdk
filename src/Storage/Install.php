<?php
/**
 * The anonymous install identifier.
 *
 * @package Codexpert\PluginTracker
 */

namespace Codexpert\PluginTracker\Storage;

use Codexpert\PluginTracker\Config;

/**
 * Derives a stable, non-reversible identifier for this install.
 *
 * The identifier must satisfy two things at once: stable enough that counts mean something, and
 * not reversible back to a site. Hashing home_url() alone would fail the second -- the set of
 * WordPress site URLs is enumerable, so a plain hash is reversible in practice. An HMAC under a
 * salt that never leaves the site closes that: even we cannot map an id back to a URL.
 *
 * See docs/EVENTS.md.
 */
class Install {

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
	 * The anonymous install id, generating the local salt on first use.
	 *
	 * @return string
	 */
	public function id() {
		return 'ins_' . substr( hash_hmac( 'sha256', $this->site_key(), $this->salt() ), 0, 32 );
	}

	/**
	 * What the id is derived from.
	 *
	 * The site URL is the input but is never transmitted. See docs/EVENTS.md, where home_url()
	 * is an explicit drop decision.
	 *
	 * @return string
	 */
	private function site_key() {
		return function_exists( 'home_url' ) ? (string) home_url() : '';
	}

	/**
	 * The per-site salt, created once and never transmitted.
	 *
	 * @return string
	 */
	private function salt() {
		$key  = $this->config->option( 'salt' );
		$salt = get_option( $key );

		if ( is_string( $salt ) && 32 <= strlen( $salt ) ) {
			return $salt;
		}

		$salt = $this->random_hex();

		if ( false === get_option( $key ) ) {
			// autoload = no. The salt is only needed on the scheduled flush and at registration,
			// never on a normal page load, so it should not sit in the alloptions cache of every
			// request on the site.
			add_option( $key, $salt, '', false );
		} else {
			// The row exists but held something unusable -- empty, an array, a short string. It has
			// to be OVERWRITTEN, because add_option() is a no-op when the row is present: the fresh
			// salt would never persist and every single call would derive a different install id,
			// inflating install counts without bound.
			update_option( $key, $salt, false );
		}

		// Re-read: a concurrent request may have created the row first, in which case the value we
		// generated is not the one stored. Returning the local value would give two requests two
		// different install ids for the same site.
		$stored = get_option( $key );

		// Only accept the stored value if it is usable; otherwise keep ours for this request rather
		// than deriving an id from known-bad data.
		return is_string( $stored ) && 32 <= strlen( $stored ) ? $stored : $salt;
	}

	/**
	 * 64 hex characters of the best randomness available.
	 *
	 * @return string
	 */
	private function random_hex() {

		if ( function_exists( 'random_bytes' ) ) {
			try {
				return bin2hex( random_bytes( 32 ) );
			} catch ( \Exception $e ) {
				// No CSPRNG available. Fall through -- an install id does not need to be
				// unpredictable to an attacker, only unique and non-reversible, so a weaker
				// source is acceptable here rather than failing the SDK closed.
				unset( $e );
			}
		}

		if ( function_exists( 'wp_generate_password' ) ) {
			return hash( 'sha256', wp_generate_password( 64, false, false ) );
		}

		return hash( 'sha256', uniqid( 'cx', true ) );
	}

	/**
	 * Forget this install.
	 *
	 * Deleting the salt changes the derived id, which is the intended effect of a privacy
	 * erasure request: prior data can no longer be correlated to this site by anyone.
	 *
	 * @return void
	 */
	public function forget() {
		delete_option( $this->config->option( 'salt' ) );
	}
}
