<?php
/**
 * Admin notices: the opt-in prompt, deprecation warnings, and developer errors.
 *
 * @package Codexpert\PluginTracker
 */

namespace Codexpert\PluginTracker\Consent;

use Codexpert\PluginTracker\Config;

/**
 * Reusable admin notices.
 *
 * The opt-in prompt is shipped rather than left to consumers because spec 10.4 requires it: every
 * consumer hand-rolling their own consent UI means every consumer getting a chance to botch it,
 * and a botched consent flow is what gets THEIR plugin pulled from WordPress.org.
 *
 * The prompt is honest by construction -- it names what is sent, links the full field list, and
 * makes declining exactly as easy as accepting. A prompt that nags or pre-checks the box would
 * defeat the purpose.
 */
class Notice {

	/**
	 * Config.
	 *
	 * @var Config
	 */
	private $config;

	/**
	 * Consent gate.
	 *
	 * @var Gate
	 */
	private $consent;

	/**
	 * Constructor.
	 *
	 * @param Config $config  Config.
	 * @param Gate   $consent Consent gate.
	 */
	public function __construct( Config $config, Gate $consent ) {
		$this->config  = $config;
		$this->consent = $consent;
	}

	/**
	 * Render whichever notice applies.
	 *
	 * @return void
	 */
	public function render() {

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$this->render_server_notice();

		// Only ask when the author has enabled telemetry, and only once -- an admin who declined
		// is not asked again for this policy version.
		if ( ! $this->config->enabled() || $this->consent->answered() ) {
			return;
		}

		if ( defined( 'CX_TRACKER_DISABLE' ) && CX_TRACKER_DISABLE ) {
			return;
		}

		$this->render_prompt();
	}

	/**
	 * User-facing prompt copy, filterable so a consumer can localise it.
	 *
	 * The SDK ships and loads its OWN translations from its own languages/ directory (see I18n),
	 * so these strings are already translated wherever a .mo exists for the site's locale.
	 * `load_plugin_textdomain()` cannot be used for that -- it resolves against the consumer's
	 * plugin directory -- so I18n calls `load_textdomain()` with an explicit path instead.
	 *
	 * This filter is the additional override, for a consumer who wants the prompt to match their
	 * own product's wording rather than merely translate it:
	 *
	 *     add_filter( 'cx_tracker_notice_strings', function ( $s ) {
	 *         $s['allow'] = __( 'Allow', 'my-plugin' );
	 *         return $s;
	 *     } );
	 *
	 * This matters beyond tidiness: the consent prompt is the one piece of UI a WordPress.org
	 * reviewer reads, and it has to be readable in the site's language.
	 *
	 * @return array
	 */
	private function strings() {

		$defaults = array(
			'intro'    => __( 'can send anonymous usage data to help its developers decide what to build and which versions to support.', 'plugin-tracker-sdk' ),
			'sends'    => __( 'It would send: an anonymous install ID, the plugin version, your WordPress and PHP versions, your site language, whether this is a multisite, and which features are used.', 'plugin-tracker-sdk' ),
			'never'    => __( 'It never sends your site address, email address, user accounts, or any content.', 'plugin-tracker-sdk' ),
			'optional' => __( 'Nothing is sent unless you agree. You can change your mind at any time.', 'plugin-tracker-sdk' ),
			'allow'    => __( 'Allow', 'plugin-tracker-sdk' ),
			'decline'  => __( 'No thanks', 'plugin-tracker-sdk' ),
		);

		if ( ! function_exists( 'apply_filters' ) ) {
			return $defaults;
		}

		/**
		 * Filter the consent prompt copy.
		 *
		 * @param array  $defaults Default English strings.
		 * @param string $plugin   Consumer plugin slug, so one consumer's filter cannot affect another's.
		 */
		$filtered = apply_filters( 'cx_tracker_notice_strings', $defaults, $this->config->plugin() );

		if ( ! is_array( $filtered ) ) {
			return $defaults;
		}

		// Merged over the defaults so a partial filter cannot blank a string, and cast at the point
		// of use below.
		return array_merge( $defaults, $filtered );
	}

	/**
	 * The opt-in prompt.
	 *
	 * @return void
	 */
	private function render_prompt() {
		$action = admin_url( 'admin-post.php' );
		$text   = $this->strings();
		?>
		<div class="notice notice-info">
			<p>
				<strong><?php echo esc_html( $this->config->plugin() ); ?></strong>
				<?php echo esc_html( (string) $text['intro'] ); ?>
			</p>
			<p>
				<?php echo esc_html( (string) $text['sends'] ); ?>
				<strong><?php echo esc_html( (string) $text['never'] ); ?></strong>
			</p>
			<p><?php echo esc_html( (string) $text['optional'] ); ?></p>
			<form method="post" action="<?php echo esc_url( $action ); ?>" style="display:inline">
				<?php wp_nonce_field( 'cx_tracker_consent_' . $this->config->plugin() ); ?>
				<input type="hidden" name="action" value="cx_tracker_consent_<?php echo esc_attr( $this->config->plugin() ); ?>">
				<input type="hidden" name="choice" value="in">
				<?php submit_button( (string) $text['allow'], 'primary', 'submit', false ); ?>
			</form>
			<form method="post" action="<?php echo esc_url( $action ); ?>" style="display:inline">
				<?php wp_nonce_field( 'cx_tracker_consent_' . $this->config->plugin() ); ?>
				<input type="hidden" name="action" value="cx_tracker_consent_<?php echo esc_attr( $this->config->plugin() ); ?>">
				<input type="hidden" name="choice" value="out">
				<?php submit_button( (string) $text['decline'], 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * A deprecation or advisory notice supplied by the ingestion response.
	 *
	 * This is the only channel that reaches a site running a years-old bundled copy of the SDK,
	 * because there is no composer update path (spec 10.2).
	 *
	 * @return void
	 */
	private function render_server_notice() {
		$notice = get_option( $this->config->option( 'notice' ) );

		if ( ! is_array( $notice ) || empty( $notice['message'] ) ) {
			return;
		}

		if ( ! empty( $notice['until'] ) && time() > (int) $notice['until'] ) {
			delete_option( $this->config->option( 'notice' ) );
			return;
		}

		$level = isset( $notice['level'] ) && in_array( $notice['level'], array( 'error', 'warning', 'info' ), true )
			? $notice['level']
			: 'info';

		printf(
			'<div class="notice notice-%1$s"><p><strong>%2$s</strong> %3$s</p></div>',
			esc_attr( $level ),
			esc_html( $this->config->plugin() ),
			esc_html( (string) $notice['message'] )
		);
	}

	/**
	 * Store a server-supplied notice.
	 *
	 * @param Config $config Config.
	 * @param array  $notice Notice payload from the ingestion response.
	 * @return void
	 */
	public static function remember_server_notice( Config $config, array $notice ) {

		if ( empty( $notice['message'] ) || ! is_string( $notice['message'] ) ) {
			return;
		}

		update_option(
			$config->option( 'notice' ),
			array(
				// Truncated because this string is server-supplied and rendered in wp-admin. It is
				// escaped at output, but an unbounded string in an option is still not something to
				// accept from the network.
				'message' => substr( $notice['message'], 0, 500 ),
				'level'   => isset( $notice['level'] ) ? (string) $notice['level'] : 'info',
				'until'   => isset( $notice['until'] ) ? (int) $notice['until'] : 0,
			),
			false
		);
	}

	/**
	 * Surface config validation errors to a developer.
	 *
	 * Only when WP_DEBUG is on -- a misconfigured SDK is a development-time mistake, and shouting
	 * about it on a production site would be the SDK damaging its consumer.
	 *
	 * @param Config $config Invalid config.
	 * @return void
	 */
	public static function config_errors( Config $config ) {

		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		$errors = $config->errors();

		if ( ! function_exists( 'add_action' ) ) {
			return;
		}

		add_action(
			'admin_notices',
			function () use ( $errors ) {
				if ( ! current_user_can( 'manage_options' ) ) {
					return;
				}
				printf(
					'<div class="notice notice-error"><p><strong>Plugin Tracker SDK misconfigured:</strong> %s</p></div>',
					esc_html( implode( ' | ', $errors ) )
				);
			}
		);
	}

	/**
	 * Log a development-time warning.
	 *
	 * @param Config $config  Config.
	 * @param string $message Message.
	 * @return void
	 */
	public static function dev_warning( Config $config, $message ) {
		unset( $config );

		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( (string) $message );
	}

	/**
	 * Forget stored notices.
	 *
	 * @param Config $config Config.
	 * @return void
	 */
	public static function forget( Config $config ) {
		delete_option( $config->option( 'notice' ) );
	}
}
