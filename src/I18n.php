<?php
/**
 * Translation loading for the SDK's own strings.
 *
 * @package Codexpert\PluginTracker
 */

namespace Codexpert\PluginTracker;

/**
 * Loads the SDK's own text domain from the SDK's own languages/ directory.
 *
 * A bundled library cannot use `load_plugin_textdomain()`: that helper resolves paths relative to
 * WP_PLUGIN_DIR and the *consumer's* plugin directory, and this SDK lives somewhere inside it with
 * no plugin header of its own. `load_textdomain()` takes an explicit .mo path instead, which is
 * exactly what a bundled library needs.
 *
 * The path is derived from this file's own location, so it keeps working in a scoped copy built by
 * .gitattributes (which keeps languages/ alongside src/) no matter where a consumer bundles it.
 *
 * Consumers can still override any individual string through the `cx_tracker_notice_strings`
 * filter -- useful when a consumer wants the prompt to match their own product's wording rather
 * than merely translate it.
 */
class I18n {

	/**
	 * The SDK's text domain.
	 */
	const DOMAIN = 'plugin-tracker-sdk';

	/**
	 * Whether loading has already been attempted this request.
	 *
	 * @var bool
	 */
	private static $attempted = false;

	/**
	 * Load the SDK's translations.
	 *
	 * Safe to call repeatedly and from several bundled copies: WordPress keys loaded domains
	 * globally, so the first copy to load wins and the rest are cheap no-ops. Two consumers
	 * bundling different SDK versions therefore share one set of strings for this domain -- which is
	 * correct, because the strings are the SDK's, not the consumer's.
	 *
	 * @return bool Whether a translation file was loaded.
	 */
	public static function load() {

		if ( self::$attempted ) {
			return false;
		}

		self::$attempted = true;

		if ( ! function_exists( 'load_textdomain' ) ) {
			return false;
		}

		// Already provided by another bundled copy, or by the site.
		if ( function_exists( 'is_textdomain_loaded' ) && is_textdomain_loaded( self::DOMAIN ) ) {
			return false;
		}

		$locale = self::locale();

		if ( '' === $locale ) {
			return false;
		}

		$mofile = self::languages_dir() . self::DOMAIN . '-' . $locale . '.mo';

		if ( ! is_readable( $mofile ) ) {
			return false;
		}

		return (bool) load_textdomain( self::DOMAIN, $mofile );
	}

	/**
	 * Reset the load guard. Test seam only.
	 *
	 * @return void
	 */
	public static function reset() {
		self::$attempted = false;
	}

	/**
	 * Absolute path to the SDK's languages directory, with a trailing slash.
	 *
	 * @return string
	 */
	public static function languages_dir() {
		return dirname( __DIR__ ) . '/languages/';
	}

	/**
	 * The locale to load.
	 *
	 * `determine_locale()` respects a user's admin language preference and exists from WordPress
	 * 5.0; `get_locale()` is the fallback for older sites, which matters because the SDK's floor is
	 * lower than the plugin's.
	 *
	 * @return string
	 */
	private static function locale() {

		if ( function_exists( 'determine_locale' ) ) {
			return (string) determine_locale();
		}

		if ( function_exists( 'get_locale' ) ) {
			return (string) get_locale();
		}

		return '';
	}
}
