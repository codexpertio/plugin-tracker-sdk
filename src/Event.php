<?php
/**
 * The closed event allow-list and payload validation.
 *
 * @package Codexpert\PluginTracker
 */

namespace Codexpert\PluginTracker;

/**
 * Event names and validation.
 *
 * The allow-list is closed on purpose. An unknown name is dropped locally rather than queued,
 * so a typo cannot silently become traffic that ingestion then rejects. See docs/EVENTS.md.
 *
 * PHP 7.2 floor: no typed properties, no arrow functions, no null-coalescing assignment. The
 * floor is a distribution decision -- it becomes the floor for every plugin that bundles this
 * SDK -- so it is enforced by CI, not by preference.
 */
class Event {

	/**
	 * Payload contract version. Bump only for additive changes; see docs/EVENTS.md.
	 *
	 * 2 (SDK 1.2.0) added `server` and `theme` to the common fields every event carries.
	 *
	 * Additive, so ingestion accepts a schema-1 envelope unchanged -- which it has to, because a
	 * bundled SDK is frozen inside a published plugin and cannot be upgraded remotely. Installs will
	 * be sending schema 1 for as long as those releases are in the wild, and the two fields simply
	 * arrive empty for them.
	 */
	const SCHEMA = 2;

	const INSTALL      = 'install';
	const ACTIVATE     = 'activate';
	const VERSION      = 'version';
	const COMPAT       = 'compat';
	const FEATURE      = 'feature';
	const DEACTIVATION = 'deactivation';

	/**
	 * Feature names are developer-chosen constants, never user input.
	 *
	 * Constrained rather than sanitised: a consumer passing user input here would turn this
	 * field into a channel for arbitrary personal data, which would put THEIR plugin in breach
	 * of the WordPress.org rules this SDK exists to keep them inside. Rejecting makes the
	 * mistake visible in development; sanitising would hide it in production.
	 */
	const FEATURE_NAME_PATTERN = '/^[a-z0-9_.-]{1,32}\z/';

	/**
	 * Version-ish values (`from` on version/compat events) must look like a version.
	 *
	 * Bounded deliberately. Without a charset this field accepts any non-empty string, and a
	 * consumer passing home_url() into it would put the site address on the wire through a
	 * documented-valid field -- defeating every drop decision in docs/EVENTS.md and falsifying
	 * the WordPress.org disclosure the consumer published from docs/readme-txt-block.md.
	 * Must start with a digit, so a URL or an email cannot match.
	 */
	const VERSION_PATTERN = '/^[0-9][0-9A-Za-z._+\-]{0,31}\z/';

	/**
	 * Extra keys each event may carry, beyond the common environment fields.
	 *
	 * The key SET is closed, not just the values. Validating only known keys while letting
	 * unknown ones through means the payload is effectively open: anything a consumer adds is
	 * transmitted, and "no PII, ever" becomes unenforceable. See docs/EVENTS.md.
	 *
	 * @return array<string,string[]>
	 */
	public static function extra_keys() {
		return array(
			self::INSTALL      => array(),
			self::ACTIVATE     => array(),
			self::VERSION      => array( 'from' ),
			self::COMPAT       => array( 'what', 'from' ),
			self::FEATURE      => array( 'name', 'count' ),
			self::DEACTIVATION => array( 'reason' ),
		);
	}

	/**
	 * Closed set of deactivation reasons. Free text is not accepted -- see docs/EVENTS.md for
	 * why `note` is deliberately absent.
	 */
	const REASONS = array(
		'temporary',
		'no_longer_needed',
		'found_better',
		'broke_site',
		'confusing',
		'missing_feature',
		'other',
	);

	/**
	 * Every allowed event name.
	 *
	 * @return string[]
	 */
	public static function all() {
		return array(
			self::INSTALL,
			self::ACTIVATE,
			self::VERSION,
			self::COMPAT,
			self::FEATURE,
			self::DEACTIVATION,
		);
	}

	/**
	 * Is this an allow-listed event name?
	 *
	 * @param mixed $name Candidate name.
	 * @return bool
	 */
	public static function is_allowed( $name ) {
		return is_string( $name ) && in_array( $name, self::all(), true );
	}

	/**
	 * Is this an acceptable feature name?
	 *
	 * @param mixed $name Candidate feature name.
	 * @return bool
	 */
	public static function is_valid_feature_name( $name ) {
		return is_string( $name ) && 1 === preg_match( self::FEATURE_NAME_PATTERN, $name );
	}

	/**
	 * Does this look like a version string?
	 *
	 * @param mixed $value Candidate.
	 * @return bool
	 */
	public static function is_valid_version( $value ) {
		return is_string( $value ) && 1 === preg_match( self::VERSION_PATTERN, $value );
	}

	/**
	 * Is this an allow-listed deactivation reason?
	 *
	 * @param mixed $reason Candidate reason.
	 * @return bool
	 */
	public static function is_valid_reason( $reason ) {
		return is_string( $reason ) && in_array( $reason, self::REASONS, true );
	}

	/**
	 * Validate the event-specific properties of a payload.
	 *
	 * Returns an error string rather than throwing, because this runs inside a consumer's
	 * request and must never surface as a fatal. The caller drops the event and may log.
	 *
	 * @param string $name  Event name (assumed already allow-listed).
	 * @param array  $props Event-specific properties.
	 * @return string|null Error message, or null when valid.
	 */
	public static function validate_props( $name, array $props ) {

		// Unknown keys are rejected before anything else. This is the gate that keeps the payload
		// closed; without it, per-key value checks below only constrain the keys they know about.
		$allowed = self::extra_keys();
		$allowed = isset( $allowed[ $name ] ) ? $allowed[ $name ] : array();
		$unknown = array_diff( array_keys( $props ), $allowed );

		if ( ! empty( $unknown ) ) {
			return sprintf(
				'%s events accept only [%s]; got unexpected [%s]. Event payloads are a closed set -- '
					. 'see docs/EVENTS.md.',
				$name,
				implode( ', ', $allowed ),
				implode( ', ', $unknown )
			);
		}

		if ( self::FEATURE === $name ) {
			if ( ! isset( $props['name'] ) || ! self::is_valid_feature_name( $props['name'] ) ) {
				return 'feature events require a "name" matching ' . self::FEATURE_NAME_PATTERN;
			}
			if ( isset( $props['count'] ) && ( ! is_int( $props['count'] ) || $props['count'] < 1 ) ) {
				return 'feature "count" must be a positive integer';
			}
			return null;
		}

		if ( self::VERSION === $name ) {
			if ( ! isset( $props['from'] ) || ! self::is_valid_version( $props['from'] ) ) {
				return 'version events require "from" to look like a version (' . self::VERSION_PATTERN . ')';
			}
			return null;
		}

		if ( self::COMPAT === $name ) {
			if ( ! isset( $props['what'] ) || ! in_array( $props['what'], array( 'wp', 'php' ), true ) ) {
				return 'compat events require "what" of "wp" or "php"';
			}
			if ( ! isset( $props['from'] ) || ! self::is_valid_version( $props['from'] ) ) {
				return 'compat events require "from" to look like a version (' . self::VERSION_PATTERN . ')';
			}
			return null;
		}

		if ( self::DEACTIVATION === $name ) {
			// The reason is optional -- the survey must be dismissible -- but if present it must
			// be from the closed set.
			if ( isset( $props['reason'] ) && ! self::is_valid_reason( $props['reason'] ) ) {
				return 'deactivation "reason" must be one of: ' . implode( ', ', self::REASONS );
			}
			if ( isset( $props['note'] ) ) {
				return 'deactivation "note" is not accepted; free text is never transmitted';
			}
			return null;
		}

		return null;
	}
}
