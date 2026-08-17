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
	 * Dashboard-issued identifier carried by the generated snippet.
	 *
	 * @var string
	 */
	private $hash = '';

	/**
	 * Absolute path to the consumer's MAIN plugin file.
	 *
	 * Required, because the SDK registers the activation and deactivation hooks itself and
	 * register_activation_hook() keys on plugin_basename( $file ). Passing anything other than the
	 * main plugin file -- an include, a class file -- produces a basename WordPress never fires, so
	 * the hooks silently never run.
	 *
	 * @var string
	 */
	private $file = '';

	/**
	 * Human-readable plugin name, for display to a site administrator.
	 *
	 * @var string
	 */
	private $name = '';

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
	 * This default is the one value here that ships INSIDE other people's plugins and cannot be
	 * corrected in place: a released consumer carries whatever host was compiled into the copy they
	 * bundled, so changing it moves only new releases and leaves every installed copy reporting to
	 * the old host. Both have to keep answering for as long as those releases are in the wild.
	 *
	 * Overridable per install via the `endpoint` argument or the `CX_TRACKER_ENDPOINT` constant, which
	 * is what staging and the test suite use. `validate()` still requires https for anything that is
	 * not a local development host, so an override cannot quietly downgrade the transport that carries
	 * the per-install token.
	 *
	 * @var string
	 */
	private $endpoint = 'https://my.plugintracker.dev/wp-json/plugin-tracker/v1';

	/**
	 * Validation errors found during construction.
	 *
	 * @var string[]
	 */
	private $errors = array();

	/**
	 * The consumer plugin's own header, parsed once.
	 *
	 * Null until read. Populated from `file`, and used for two jobs at once: deriving whatever the
	 * consumer did not pass explicitly, and confirming the path really is a main plugin file. Those were
	 * separate file reads before; the header is small and reading it twice per request for one plugin is
	 * waste that scales with the number of plugins on the site.
	 *
	 * @var array|null
	 */
	private $header = null;

	/**
	 * A non-secret project id looks like pt_proj_<hex>. Enforced so that an author who pastes
	 * their SECRET here by mistake is told, rather than shipping it to WordPress.org inside
	 * their plugin. That mistake is the single worst failure mode in this SDK's threat model
	 * (spec 10.5), so it is worth a strict pattern.
	 */
	const PROJECT_PATTERN = '/^pt_proj_[a-z0-9]{6,64}\z/';

	/**
	 * The dashboard-issued hash: hex, long enough to be unguessable as an identifier.
	 *
	 * Deliberately NOT a secret. It ships inside the consumer's plugin, and if that plugin is
	 * listed on WordPress.org the zip is published, so anything in the snippet is public by
	 * construction. The hash says WHICH plugin is reporting; the per-install token obtained at
	 * registration is what authenticates.
	 */
	const HASH_PATTERN = '/^[a-f0-9]{32,64}\z/';

	/**
	 * Consumer plugin slugs follow the WordPress.org slug shape.
	 */
	const SLUG_PATTERN = '/^[a-z0-9][a-z0-9-]{0,61}[a-z0-9]\z/';

	/**
	 * The value of `collect` meaning "every optional field, including ones added later".
	 *
	 * A literal rather than the enumerated list, so a snippet generated today does not silently
	 * exclude a field the SDK gains tomorrow.
	 */
	const COLLECT_ALL = 'all';

	/**
	 * The optional environment fields `collect` may name. Mirrors Telemetry_Collect::FIELDS server-side.
	 *
	 * Identity, timing and the event name are absent on purpose: they are what make a payload mean
	 * anything, and a switch that could turn them off would let an integration quietly produce rows
	 * nobody can interpret.
	 */
	const COLLECTABLE = array( 'wp', 'php', 'locale', 'multisite', 'server', 'theme', 'plugins' );

	/**
	 * Which optional fields this copy may send.
	 *
	 * @var string|string[]
	 */
	private $collect = self::COLLECT_ALL;

	/**
	 * Build from a consumer-supplied array.
	 *
	 * @param array $args Raw arguments as passed to Tracker::init().
	 */
	public function __construct( array $args ) {

		$this->project = isset( $args['project'] ) && is_string( $args['project'] ) ? $args['project'] : '';
		$this->plugin  = isset( $args['plugin'] ) && is_string( $args['plugin'] ) ? $args['plugin'] : '';
		$this->version = isset( $args['version'] ) && is_string( $args['version'] ) ? $args['version'] : '';
		$this->name    = isset( $args['name'] ) && is_string( $args['name'] ) ? trim( $args['name'] ) : '';
		$this->hash    = isset( $args['hash'] ) && is_string( $args['hash'] ) ? trim( $args['hash'] ) : '';
		$this->file    = isset( $args['file'] ) && is_string( $args['file'] ) ? $args['file'] : '';
		$this->enabled = ! empty( $args['enabled'] );
		$this->collect = self::normalize_collect( isset( $args['collect'] ) ? $args['collect'] : self::COLLECT_ALL );

		if ( isset( $args['endpoint'] ) && is_string( $args['endpoint'] ) && '' !== $args['endpoint'] ) {
			$this->endpoint = rtrim( $args['endpoint'], '/' );
		}

		// A site-level override, so a developer can point the SDK at a local receiver without editing
		// the generated snippet -- editing the snippet is how a wrong hash or a stale namespace ends
		// up committed. Last, so it beats the argument. Still subject to the https check below, which
		// permits localhost and .test.
		if ( defined( 'CX_TRACKER_ENDPOINT' ) && is_string( CX_TRACKER_ENDPOINT ) && '' !== CX_TRACKER_ENDPOINT ) {
			$this->endpoint = rtrim( CX_TRACKER_ENDPOINT, '/' );
		}

		// Anything the consumer did not pass is taken from the plugin header. Explicit arguments still
		// win, so an integration that passes all three keeps behaving exactly as it did.
		$this->derive_from_header();

		$this->validate();
	}

	/**
	 * Fill in slug, name and version from the plugin header.
	 *
	 * ## Why this exists
	 *
	 * `plugin`, `name` and `version` are facts WordPress already holds about the consumer's plugin, in the
	 * header of the very file they are required to pass as `file`. Asking for them again made the snippet
	 * four lines longer and introduced three ways to be wrong -- most damagingly `version`, which a
	 * consumer must remember to bump in TWO places or the SDK reports a version that is not the one
	 * running, and the `version` lifecycle event fires for a change that already happened.
	 *
	 * ## Why not get_plugin_data() unconditionally
	 *
	 * `get_plugin_data()` lives in wp-admin/includes/plugin.php, which is NOT loaded on a front-end
	 * request or during cron -- and this runs at file scope on every request. Calling it directly would
	 * fatal for most of a site's traffic.
	 *
	 * So it is used when it is already available, and `get_file_data()` otherwise: that is the
	 * wp-includes function `get_plugin_data()` itself delegates to, so both paths parse the header the
	 * same way, and neither is a hand-rolled regex over somebody's source file.
	 *
	 * Note the `false` arguments: no markup, and no translation. Translating a plugin header this early
	 * makes WordPress load the text domain just-in-time and warn on every request since 6.7 -- the same
	 * trap the snippet's `name` argument documents.
	 *
	 * @return void
	 */
	private function derive_from_header() {

		if ( '' === $this->file ) {
			return;
		}

		if ( '' !== $this->plugin && '' !== $this->name && '' !== $this->version ) {
			return;
		}

		$header = $this->header();

		if ( '' === $this->version && isset( $header['Version'] ) ) {
			$this->version = (string) $header['Version'];
		}

		if ( '' === $this->name && isset( $header['Name'] ) ) {
			$this->name = trim( (string) $header['Name'] );
		}

		if ( '' === $this->plugin ) {
			$this->plugin = $this->derive_slug();
		}
	}

	/**
	 * The slug this plugin reports under.
	 *
	 * NOT in the header -- WordPress has no slug field -- so it comes from the path, which is the same
	 * thing WordPress.org uses: the plugin's own directory name. A single-file plugin has no directory,
	 * so its filename stands in.
	 *
	 * Lowercased and stripped of anything outside the slug shape, because a directory name is whatever
	 * the author or the installer chose and may carry capitals or underscores. The result is validated
	 * against SLUG_PATTERN like any other, so an underived-able path still produces a clear error rather
	 * than a silently wrong slug.
	 *
	 * @return string
	 */
	private function derive_slug() {

		$basename = $this->basename();
		$dir      = dirname( $basename );

		$slug = ( '.' === $dir || '' === $dir ) ? basename( $basename, '.php' ) : $dir;

		$slug = strtolower( $slug );
		$slug = preg_replace( '/[^a-z0-9-]+/', '-', $slug );
		$slug = trim( (string) $slug, '-' );

		return (string) $slug;
	}

	/**
	 * The plugin header, read once.
	 *
	 * @return array
	 */
	private function header() {

		if ( null !== $this->header ) {
			return $this->header;
		}

		$this->header = array();

		if ( '' === $this->file || ! is_readable( $this->file ) ) {
			return $this->header;
		}

		// Already loaded -- an admin request, or a consumer that included it. Use WordPress's own
		// reader, with markup and translation both off.
		if ( function_exists( 'get_plugin_data' ) ) {
			$this->header = (array) get_plugin_data( $this->file, false, false );

			return $this->header;
		}

		// The front-end and cron path. get_file_data() is in wp-includes and is what get_plugin_data()
		// calls internally, so the parsing is identical -- only the surrounding conveniences differ.
		if ( function_exists( 'get_file_data' ) ) {
			$this->header = (array) get_file_data(
				$this->file,
				array(
					'Name'    => 'Plugin Name',
					'Version' => 'Version',
				),
				'plugin'
			);
		}

		return $this->header;
	}

	/**
	 * Collect validation errors.
	 *
	 * @return void
	 */
	private function validate() {

		// `project` is OPTIONAL. The dashboard-issued snippet carries `hash`, which is the
		// identifier the SDK reports under; `project` predates it and is still accepted so an
		// existing integration keeps working, but it is no longer required. Validated only when
		// supplied, so a typo is still caught rather than silently transmitted.
		if ( '' !== $this->project && 1 !== preg_match( self::PROJECT_PATTERN, $this->project ) ) {
			$this->errors[] = 'project, when supplied, must match pt_proj_<alnum>; it is public and '
				. 'non-secret. Never put a secret here -- it would be published inside your plugin.';
		}

		/*
		 * Both of these are DERIVED from the plugin header when not supplied, so reaching an error here
		 * means the header could not be read or produced nothing usable -- which a consumer can fix
		 * either by correcting the header or by passing the value explicitly. The messages say so,
		 * because "plugin must be a lowercase slug" is unhelpful advice about an argument the consumer
		 * never wrote.
		 */
		if ( 1 !== preg_match( self::SLUG_PATTERN, $this->plugin ) ) {
			$this->errors[] = 'plugin must be a lowercase slug, e.g. my-plugin. It is derived from your '
				. "plugin's directory name when not supplied, so either rename the directory or pass "
				. '`plugin` explicitly.';
		}

		if ( '' === $this->version ) {
			$this->errors[] = 'version could not be read from your plugin header. Add a `Version:` line '
				. 'to the file you passed as `file`, or pass `version` explicitly.';
		}

		if ( 1 !== preg_match( self::HASH_PATTERN, $this->hash ) ) {
			$this->errors[] = 'hash is required and must match ' . self::HASH_PATTERN
				. '. Copy it from the snippet the dashboard generated; it is a public identifier, '
				. 'not a secret, and must never be a value the dashboard told you to keep private.';
		}

		// Required, and required to be the MAIN plugin file. The SDK registers the activation and
		// deactivation hooks itself, and register_activation_hook() keys on
		// plugin_basename( $file ) -- so a wrong path here means those hooks silently never fire,
		// which is the worst kind of failure: nothing errors, data just never arrives.
		if ( '' === $this->file ) {
			$this->errors[] = 'file is required; pass __FILE__ from your main plugin file';
		} elseif ( ! $this->looks_like_plugin_file() ) {
			$this->errors[] = 'file must be the main plugin file (pass __FILE__ from the file that '
				. 'carries your plugin header), not an include or a class file';
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

		// DNS is case-insensitive and a trailing root dot is legal, so normalise before comparing.
		// Without this, `host.INTERNAL` and `host.internal.` are refused while `host.internal` is
		// allowed -- inconsistent, and it fails on a URL a developer typed rather than generated.
		$host = strtolower( rtrim( $host, '.' ) );

		// Unwrap an IPv6 literal so it can be validated as an address rather than as a name.
		if ( '[' === substr( $host, 0, 1 ) && ']' === substr( $host, -1 ) ) {
			$host = substr( $host, 1, -1 );
		}

		if ( 'localhost' === $host ) {
			return true;
		}

		// An IP literal is local only when the address itself is loopback, private or link-local.
		//
		// This branch replaced a rule that allowed ANY hostname with no dot in it, on the reasoning
		// that a single-label name cannot be a public host. That reasoning was wrong three ways, and
		// each one allowed an install token to cross the public internet in cleartext:
		//
		// - http://[2001:4860:4860::8888]/ is Google DNS; a bracketed IPv6 literal contains no dot.
		// - http://134744072/ is the integer form of 8.8.8.8, which getaddrinfo accepts.
		// - http://0x08080808/ is the hex form of the same address.
		// - http://ai/ is a real single-label public host. Several TLDs carry apex A records, so
		// "contains no dot" never meant "not routable"
		//
		// Docker and compose service names are still reachable without TLS: `host.docker.internal`
		// and any `*.internal` name match the reserved-TLD branch below, and a container address
		// matches this one. What is gone is the blanket allowance.
		if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
			// Both flags mean "reject private and reserved". A literal that FAILS that filter is
			// therefore exactly one that is not publicly routable, which is what makes it local.
			return false === filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
		}

		// .test, .local and .localhost are reserved for local use by RFC 6761/6762. .internal was
		// reserved by ICANN in 2024 for private networks and can never resolve publicly, which is
		// what Docker's host.docker.internal relies on -- so a developer pointing the SDK straight at
		// the host gateway is not refused for lacking TLS.
		return 1 === preg_match( '/\.(test|local|localhost|internal)\z/', $host );
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
	 * Human-readable plugin name, for anything an administrator reads.
	 *
	 * Falls back to a prettified slug rather than the raw slug. The consent prompt is the one piece
	 * of UI a WordPress.org reviewer reads, and showing "my-cool-plugin" there looks like a bug --
	 * but requiring a `name` argument would break every existing caller, so the fallback improves
	 * them without asking anything. Consumers should still pass `name` explicitly: no amount of
	 * prettifying turns "wp-seo-tools" into "WP SEO Tools".
	 *
	 * Display only. Identifiers -- option keys, cron hooks, nonce actions -- all key off plugin()
	 * so that renaming the display name can never orphan a site's stored state.
	 *
	 * @return string
	 */
	public function name() {

		if ( '' !== $this->name ) {
			return $this->name;
		}

		return ucwords( str_replace( array( '-', '_' ), ' ', $this->plugin() ) );
	}

	/**
	 * Dashboard-issued plugin hash from the snippet.
	 *
	 * @return string
	 */
	public function hash() {
		return $this->hash;
	}

	/**
	 * Absolute path to the consumer's main plugin file.
	 *
	 * @return string
	 */
	public function file() {
		return $this->file;
	}

	/**
	 * Plugin basename, as WordPress keys activation hooks and the plugins-list row.
	 *
	 * @return string
	 */
	public function basename() {

		if ( '' === $this->file ) {
			return '';
		}

		if ( function_exists( 'plugin_basename' ) ) {
			return plugin_basename( $this->file );
		}

		// Fallback for a non-WordPress context (tests): last two path segments.
		$parts = explode( '/', str_replace( '\\', '/', $this->file ) );
		$parts = array_slice( $parts, -2 );

		return implode( '/', $parts );
	}

	/**
	 * Does the given file plausibly carry a plugin header?
	 *
	 * A heuristic, not a guarantee -- but it catches the common mistake of passing __FILE__ from a
	 * class file, which would leave the activation hooks permanently silent.
	 *
	 * @return bool
	 */
	private function looks_like_plugin_file() {

		if ( ! is_readable( $this->file ) ) {
			// Cannot check. Do not fail construction on this -- an unreadable path during a test or
			// an odd filesystem should not disable a consumer's telemetry.
			return true;
		}

		$header = $this->header();

		// A parsed header with a Name is the same evidence the old hand-rolled regex looked for, but it
		// comes from the read this class already does for the derived fields rather than a second one.
		if ( ! empty( $header['Name'] ) ) {
			return true;
		}

		/*
		 * No Name, and neither WordPress reader was available -- which happens in a unit test that
		 * constructs Config without WordPress loaded. Fall back to looking at the file directly rather
		 * than failing a consumer whose header is fine.
		 */
		if ( ! function_exists( 'get_file_data' ) && ! function_exists( 'get_plugin_data' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading the first 8KB of a LOCAL plugin file; wp_remote_get() is for URLs and WP_Filesystem is not loaded this early.
			$head = (string) file_get_contents( $this->file, false, null, 0, 8192 );

			return 1 === preg_match( '/^[\s\*\/#@]*Plugin Name\s*:/mi', $head );
		}

		return false;
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
	 * Which optional environment fields this copy may send.
	 *
	 * Defaults to COLLECT_ALL, and the generated snippet states it explicitly so an author can see
	 * that it is a choice rather than a hidden default.
	 *
	 * **This is a ceiling, not the final say.** The dashboard's own selection is enforced on
	 * INGESTION, because a value living here is frozen the moment the author ships -- the SDK is
	 * bundled into their plugin, on sites nobody can ask to update. So narrowing this narrows what
	 * a NEW release transmits; narrowing it in the dashboard narrows what is stored for every
	 * install that already exists. Both are real, and they are not the same lever.
	 *
	 * @return string|string[] COLLECT_ALL, or a list of field names.
	 */
	public function collect() {
		return $this->collect;
	}

	/**
	 * Is this optional field permitted by the local config?
	 *
	 * @param string $field Field name.
	 * @return bool
	 */
	public function collects( $field ) {

		if ( self::COLLECT_ALL === $this->collect ) {
			return true;
		}

		return in_array( $field, $this->collect, true );
	}

	/**
	 * Reduce a `collect` argument to COLLECT_ALL or a list of known names.
	 *
	 * An unrecognised name is dropped rather than kept: this decides what is transmitted, so a typo
	 * must not be carried forward to mean something in a later version. An empty list is left empty
	 * -- "send no optional fields" is a legitimate instruction and the opposite of the default, so
	 * it must not be quietly promoted back to COLLECT_ALL.
	 *
	 * @param mixed $value Raw argument.
	 * @return string|string[]
	 */
	private static function normalize_collect( $value ) {

		if ( self::COLLECT_ALL === $value || null === $value || '' === $value ) {
			return self::COLLECT_ALL;
		}

		if ( ! is_array( $value ) ) {
			return self::COLLECT_ALL;
		}

		return array_values( array_intersect( array_map( 'strval', $value ), self::COLLECTABLE ) );
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
