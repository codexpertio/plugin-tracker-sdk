<?php
/**
 * The narrowest possible stand-in for WP_Error.
 *
 * @package Codexpert\PluginTracker\Test
 */

// No namespace, by design: tests/php/utils/ holds plain global helper classes, matching the house
// convention. The class name carries the prefix instead of a namespace. Wired in via
// composer.json autoload-dev.classmap.

/**
 * Http\Transport calls exactly one method on a WP_Error -- get_error_message(), after is_wp_error()
 * says so -- and nothing else in src/ touches one at all. Anything richer here would be scaffolding
 * pretending to be a fixture.
 *
 * Real WP_Error is not available: this suite never loads WordPress (see tests/php/bootstrap.php),
 * and brain/monkey mocks functions rather than classes.
 */
final class PluginTracker_Test_Wp_Error {

	/**
	 * @var string
	 */
	private $message;

	/**
	 * @param string $message Error message.
	 */
	public function __construct( $message ) {
		$this->message = $message;
	}

	/**
	 * @return string
	 */
	public function get_error_message() {
		return $this->message;
	}
}
