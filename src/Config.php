<?php
/**
 * Validated construction of the SDK's configuration.
 *
 * @package Codexpert\PluginTracker
 */

namespace Codexpert\PluginTracker;

/**
 * Immutable, validated config.
 *
 * Construction validates rather than trusting, because a misconfigured SDK inside someone
 * else's plugin must fail visibly at development time instead of quietly never reporting.
 * `is_valid()` is checked by Tracker::init(), which no-ops on invalid config.
 */
class Config {

	/**
	 * Public, non-secret project identifier issued by the dashboard.
	 *
	 * @var string
	 */
	private $project = '';

	/**
	 * The consumer's own plugin slug.
	 *
	 * @var string
	 */
	private $plugin = '';

	/**
	 * The consumer's own plugin version.
	 *
	 * @var string
	 */
	private $version = '';

	/**
	 * Whether the author has enabled telemetry for this project (consent gate 1).
	 *
	 * @var bool
	 */
	private $enabled = false;

	/**
	 * Ingestion base URL. Overridable for staging and for tests.
	 *
	 * @var string
	 */
	private $endpoint = 'https://app.plugintracker.dev/wp-json/plugin-tracker/v1';

	/**
	 * Validation errors found during construction.
	 *
	 * @var string[]
	 */
	private $errors = array();

	/**
	 * A non-secret project id looks like pt_proj_<hex>. Enforced so that an author who pastes
	 * their SECRET here by mistake is told, rather than shipping it to WordPress.org inside
	 * their plugin. That mistake is the single worst failure mode in this SDK's threat model
	 * (spec 10.5), so it is worth a strict pattern.
	 */
	const PROJECT_PATTERN = '/^pt_proj_[a-z0-9]{6,64}\z/';

	/**
	 * Consumer plugin slugs follow the WordPress.org slug shape.
	 */
	const SLUG_PATTERN = '/^[a-z0-9][a-z0-9-]{0,61}[a-z0-9]\z/';

	/**
	 * Build from a consumer-supplied array.
	 *
	 * @param array $args Raw arguments as passed to Tracker::init().
	 */
	public function __construct( array $args ) {

		$this->project = isset( $args['project'] ) && is_string( $args['project'] ) ? $args['project'] : '';
		$this->plugin  = isset( $args['plugin'] ) && is_string( $args['plugin'] ) ? $args['plugin'] : '';
		$this->version = isset( $args['version'] ) && is_string( $args['version'] ) ? $args['version'] : '';
		$this->enabled = ! empty( $args['enabled'] );

		if ( isset( $args['endpoint'] ) && is_string( $args['endpoint'] ) && '' !== $args['endpoint'] ) {
			$this->endpoint = rtrim( $args['endpoint'], '/' );
		}

		$this->validate();
	}

	/**
	 * Collect validation errors.
	 *
	 * @return void
	 */
	private function validate() {

		if ( 1 !== preg_match( self::PROJECT_PATTERN, $this->project ) ) {
			$this->errors[] = 'project must match pt_proj_<alnum>; it is public and non-secret. '
				. 'Never put your project SECRET here -- it would be published inside your plugin.';
		}

		if ( 1 !== preg_match( self::SLUG_PATTERN, $this->plugin ) ) {
			$this->errors[] = 'plugin must be a lowercase slug, e.g. my-plugin';
		}

		if ( '' === $this->version ) {
			$this->errors[] = 'version is required';
		}

		// https is required. Telemetry over http would expose the install token in transit, and
		// the token is the whole credential. A local endpoint is allowed for development only.
		if ( 0 !== strpos( $this->endpoint, 'https://' ) && ! $this->is_local_endpoint() ) {
			$this->errors[] = 'endpoint must be https';
		}
	}

	/**
	 * Is the endpoint a local development host?
	 *
	 * @return bool
	 */
	private function is_local_endpoint() {
		// wp_parse_url() where available: parse_url()'s output has varied across PHP versions.
		// Guarded rather than assumed, because this is a library and a consumer could construct
		// Config before WordPress has finished loading.
		if ( function_exists( 'wp_parse_url' ) ) {
			$host = (string) wp_parse_url( $this->endpoint, PHP_URL_HOST );
		} else {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- guarded fallback; wp_parse_url() is unavailable here by definition.
			$host = (string) parse_url( $this->endpoint, PHP_URL_HOST );
		}

		if ( '' === $host ) {
			return false;
		}

		if ( 'localhost' === $host || '127.0.0.1' === $host || '::1' === $host ) {
			return true;
		}

		return (bool) preg_match( '/\.(test|local|localhost)$/', $host );
	}

	/**
	 * Is this config usable?
	 *
	 * @return bool
	 */
	public function is_valid() {
		return empty( $this->errors );
	}

	/**
	 * Validation errors, if any.
	 *
	 * @return string[]
	 */
	public function errors() {
		return $this->errors;
	}

	/**
	 * Project identifier.
	 *
	 * @return string
	 */
	public function project() {
		return $this->project;
	}

	/**
	 * Consumer plugin slug.
	 *
	 * @return string
	 */
	public function plugin() {
		return $this->plugin;
	}

	/**
	 * Consumer plugin version.
	 *
	 * @return string
	 */
	public function version() {
		return $this->version;
	}

	/**
	 * Has the author enabled telemetry for this project? (Consent gate 1.)
	 *
	 * @return bool
	 */
	public function enabled() {
		return $this->enabled;
	}

	/**
	 * Ingestion base URL, no trailing slash.
	 *
	 * @return string
	 */
	public function endpoint() {
		return $this->endpoint;
	}

	/**
	 * Per-consumer option-name prefix.
	 *
	 * Namespaced by plugin slug so that two consumers on one site never share state. That
	 * matters because scoped copies of this SDK cannot see each other (spec 10.3) -- if they
	 * shared an option key they would silently overwrite each other's tokens and queues.
	 *
	 * @param string $key Suffix.
	 * @return string
	 */
	public function option( $key ) {
		return 'cx_tracker_' . str_replace( '-', '_', $this->plugin ) . '_' . $key;
	}
}
