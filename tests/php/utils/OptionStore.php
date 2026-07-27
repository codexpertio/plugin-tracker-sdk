<?php
/**
 * An in-memory stand-in for the WordPress options table.
 *
 * @package Codexpert\PluginTracker\Test
 */

// No namespace, by design: tests/php/utils/ holds plain global helper classes, matching the
// house convention (wc-affiliate tests/php/utils/). The class name carries the prefix instead of
// a namespace, so it still cannot collide. Wired in via composer.json autoload-dev.classmap.

/**
 * Reset before every test (see PluginTrackerTestCase::setUp()). Backs get_option/update_option/
 * add_option/delete_option stubs so Storage\Queue, Consent\Gate, Storage\Install and
 * Cron\Scheduler can be exercised statefully without a WordPress install.
 */
final class PluginTracker_Test_Option_Store {

	/**
	 * @var array
	 */
	private static $options = array();

	/**
	 * Wipe all stored options. Called at the start of every test.
	 *
	 * @return void
	 */
	public static function reset() {
		self::$options = array();
	}

	/**
	 * Mirrors get_option( $option, $default = false ).
	 *
	 * @param string $option  Option name.
	 * @param mixed  $default Default when unset.
	 * @return mixed
	 */
	public static function get( $option, $default = false ) {
		return array_key_exists( $option, self::$options ) ? self::$options[ $option ] : $default;
	}

	/**
	 * Mirrors update_option( $option, $value, $autoload = null ).
	 *
	 * @param string $option   Option name.
	 * @param mixed  $value    Value.
	 * @param mixed  $autoload Ignored; kept for signature compatibility.
	 * @return bool
	 */
	public static function update( $option, $value, $autoload = null ) {
		unset( $autoload );
		self::$options[ $option ] = $value;
		return true;
	}

	/**
	 * Mirrors add_option( $option, $value, $deprecated = '', $autoload = 'yes' ). Real WordPress
	 * refuses to overwrite an existing option, which is the behaviour Storage\Install::salt() relies on
	 * for its "concurrent request" re-read.
	 *
	 * @param string $option     Option name.
	 * @param mixed  $value      Value.
	 * @param string $deprecated Unused.
	 * @param mixed  $autoload   Ignored.
	 * @return bool
	 */
	public static function add( $option, $value, $deprecated = '', $autoload = 'yes' ) {
		unset( $deprecated, $autoload );

		if ( array_key_exists( $option, self::$options ) ) {
			return false;
		}

		self::$options[ $option ] = $value;
		return true;
	}

	/**
	 * Mirrors delete_option( $option ).
	 *
	 * @param string $option Option name.
	 * @return bool
	 */
	public static function delete( $option ) {
		$existed = array_key_exists( $option, self::$options );
		unset( self::$options[ $option ] );
		return $existed;
	}

	/**
	 * Everything currently stored. Test introspection only.
	 *
	 * @return array
	 */
	public static function all() {
		return self::$options;
	}
}
