<?php
/**
 * Facts about the site this copy is running on.
 *
 * @package Codexpert\PluginTracker
 */

namespace Codexpert\PluginTracker;

/**
 * The environment readings both transmission lanes share.
 *
 * These lived as private statics on `Feedback\Deactivation`, which was correct while feedback was the
 * only payload carrying them. From 1.2.0 the event stream carries `server` and `theme` too, and
 * `Tracker` cannot reach into `Feedback\` for them -- the dependency runs the other way, and a facade
 * importing from a sub-namespace is the direction this package deliberately does not have (see the
 * note in Tracker's class docblock about why Config, Event and Tracker sit at the root).
 *
 * So they moved here rather than being duplicated. Two copies of `server()` would have been two
 * closed lists to keep in step, and the moment they disagreed the same install would report one
 * server in its events and another in its feedback -- a discrepancy nobody would think to look for.
 *
 * Every method answers a bounded value or an empty string. Nothing here returns a raw header, a path,
 * or anything a site owner has not already published: the whole point of the closed list in
 * `server()` is that `SERVER_SOFTWARE` never leaves the function.
 */
class Environment {

	/**
	 * Which web server this is, as a bare product name.
	 *
	 * Matched against a closed list and only a literal FROM that list is ever returned, so the raw
	 * header never reaches a payload. That is the whole design: `SERVER_SOFTWARE` reads
	 * `Apache/2.4.41 (Ubuntu)` or `nginx/1.18.0`, and the version and distribution in there are the
	 * "server hostname, OS" that docs/FEEDBACK.md refuses. The question asked is "Apache or Nginx",
	 * and that is exactly what is answered -- the same reasoning that keeps `php` to major.minor.
	 *
	 * Order matters. OpenResty and LiteSpeed both advertise a string that also contains the name of
	 * the server they are built on or emulating, so the more specific name is tested first.
	 *
	 * @return string One of the known names, 'other' when unrecognised, '' when unavailable.
	 */
	public static function server() {

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- This value provably cannot escape this function: it is lowercased, matched against the closed list below, and only a literal FROM that list is ever returned. Neither remedy the sniff asks for would do anything. wp_unslash() is for the superglobals WordPress adds slashes to -- $_POST, $_GET, $_COOKIE, $_REQUEST -- and $_SERVER is not one of them; sanitize_text_field() on a string that is about to be discarded in favour of a hardcoded literal is theatre. Both would read as diligence while changing nothing.
		$raw = isset( $_SERVER['SERVER_SOFTWARE'] ) ? strtolower( (string) $_SERVER['SERVER_SOFTWARE'] ) : '';

		if ( '' === $raw ) {
			return '';
		}

		foreach ( array( 'litespeed', 'openresty', 'nginx', 'apache', 'caddy', 'lighttpd', 'iis' ) as $name ) {
			if ( false !== strpos( $raw, $name ) ) {
				return $name;
			}
		}

		return 'other';
	}

	/**
	 * The active theme.
	 *
	 * `parent` is populated only for a child theme, and it matters: "the bug appears under theme X"
	 * is a different report when X is a two-file child of a parent doing the actual work.
	 *
	 * Only `slug` reaches the event stream. All three go with deactivation feedback, where a bug
	 * report wants the whole picture -- see Feedback\Deactivation::site_fields().
	 *
	 * @return array{slug:string,version:string,parent:string}
	 */
	public static function theme() {

		// The only guard needed. Past it WordPress is loaded, so wp_get_theme() returns a WP_Theme
		// and its methods exist -- an is_object()/method_exists() pair here would be unreachable
		// defensiveness, which PHPStan proves rather than merely suspects.
		if ( ! function_exists( 'wp_get_theme' ) ) {
			return array(
				'slug'    => '',
				'version' => '',
				'parent'  => '',
			);
		}

		$theme = wp_get_theme();

		// parent() is the one that genuinely varies: WP_Theme for a child theme, false otherwise.
		$parent = $theme->parent();

		return array(
			'slug'    => (string) $theme->get_stylesheet(),
			'version' => (string) $theme->get( 'Version' ),
			'parent'  => $parent ? (string) $parent->get_stylesheet() : '',
		);
	}
}
