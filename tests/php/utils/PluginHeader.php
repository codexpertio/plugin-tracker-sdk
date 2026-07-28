<?php
/**
 * Stand-ins for the two WordPress functions that read a plugin's identity off disk.
 *
 * @package Codexpert\PluginTracker\Test
 */

// No namespace, by design: tests/php/utils/ holds plain global helper classes, matching the
// house convention (wc-affiliate tests/php/utils/). The class name carries the prefix instead of
// a namespace, so it still cannot collide. Wired in via composer.json autoload-dev.classmap.

/**
 * Backs the `get_file_data` and `plugin_basename` stubs (see PluginTrackerTestCase::setUp()).
 *
 * ## Why these are stubbed centrally rather than per-test
 *
 * Brain Monkey patches a stubbed function PROCESS-WIDE. Config::header() and Config::basename()
 * both branch on `function_exists()`, so the moment any single test stubbed one of these, the guard
 * would start passing in every other test too -- and Config would silently switch derivation paths
 * depending on test ORDER. Stubbing both here makes the whole suite run one consistent path. Same
 * hazard, and same remedy, as the get_bloginfo()/wp_rand() defaults beside them.
 *
 * ## Why real implementations rather than canned return values
 *
 * A stub that just returned `array( 'Name' => 'Acme Widgets' )` would assert nothing about whether
 * the SDK can read a plugin header -- it would only re-assert the fixture's own contents. These are
 * faithful ports of the WordPress originals, so the fixture FILE genuinely drives the result and a
 * header the SDK asks for by the wrong key still comes back empty.
 */
final class PluginTracker_Test_Plugin_Header {

	/**
	 * A port of WordPress's get_file_data().
	 *
	 * Mirrors wp-includes/functions.php: read the first 8 KB only, normalise classic-Mac line
	 * endings, then one case-insensitive multiline match per requested header. A field that is
	 * absent comes back as an empty string rather than being omitted, which is what lets
	 * Config::derive_from_header() treat "no Version line" and "no file" identically.
	 *
	 * The `$context` argument is accepted and ignored: in WordPress it only selects extra headers
	 * via the `extra_{$context}_headers` filter, and this suite registers no filters.
	 *
	 * @param string $file            Absolute path to the file.
	 * @param array  $default_headers Map of field name => header label.
	 * @param string $context         Unused. Present to match the WordPress signature.
	 * @return array Map of field name => value, every requested key present.
	 */
	public static function get_file_data( $file, $default_headers, $context = '' ) {
		unset( $context );

		/*
		 * file_get_contents() on a LOCAL path, with an explicit 8 KB cap -- exactly what WordPress's own
		 * get_file_data() does with fopen()/fread(). The sniff's suggested wp_remote_get() is for remote
		 * URLs and would be wrong here; there is no HTTP involved, and this file is a test double that
		 * never ships.
		 */
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$data = is_readable( $file ) ? (string) file_get_contents( $file, false, null, 0, 8192 ) : '';

		// WordPress does this so a file saved with classic Mac line endings still parses.
		$data = str_replace( "\r", "\n", $data );

		$headers = array();

		foreach ( (array) $default_headers as $field => $label ) {
			$pattern = '/^(?:[ \t]*<\?php)?[ \t\/*#@]*' . preg_quote( $label, '/' ) . ':(.*)$/mi';

			$headers[ $field ] = preg_match( $pattern, $data, $match ) && $match[1]
				? trim( preg_replace( '/\s*(?:\*\/|\?>).*/', '', $match[1] ) )
				: '';
		}

		return $headers;
	}

	/**
	 * A port of WordPress's plugin_basename().
	 *
	 * WordPress returns the path relative to WP_PLUGIN_DIR (or WPMU_PLUGIN_DIR). There is no
	 * WordPress here to define either, so the last `/plugins/` or `/mu-plugins/` segment stands in
	 * for that root -- which gives the identical answer for any path under a real plugins directory,
	 * and that is the only shape the SDK is ever handed in production.
	 *
	 * A path with no such segment is returned normalised but otherwise untouched, exactly as
	 * WordPress does for a file outside the plugins directory.
	 *
	 * @param string $file Absolute path to a plugin file.
	 * @return string
	 */
	public static function plugin_basename( $file ) {
		$file = str_replace( '\\', '/', (string) $file );

		if ( preg_match( '#^.*/(?:mu-)?plugins/(.+)$#', $file, $match ) ) {
			return trim( $match[1], '/' );
		}

		return trim( $file, '/' );
	}
}
