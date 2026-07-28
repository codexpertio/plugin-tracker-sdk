<?php
/**
 * The deactivation-feedback modal: a separately-consented message to the plugin developer.
 *
 * @package Codexpert\PluginTracker
 */

namespace Codexpert\PluginTracker\Feedback;

use Codexpert\PluginTracker\Config;
use Codexpert\PluginTracker\Consent\Gate;
use Codexpert\PluginTracker\Consent\Notice;
use Codexpert\PluginTracker\Event;
use Codexpert\PluginTracker\Tracker;

/**
 * Intercepts the Deactivate link on THIS plugin's row in plugins.php, asks why, and then lets the
 * deactivation proceed.
 *
 * ---------------------------------------------------------------------------------------------
 * WHY THIS IS NOT AN EVENT ON THE TELEMETRY STREAM
 * ---------------------------------------------------------------------------------------------
 * docs/EVENTS.md drops the site URL and refuses free text outright, and Event::validate_props()
 * enforces the second by rejecting a `note` key on a deactivation event. Both decisions are
 * correct FOR THAT STREAM and neither is weakened here. Feedback is a different thing with a
 * different consent basis, so it travels on a different route with a different payload, documented
 * separately in docs/FEEDBACK.md:
 *
 *   Telemetry  -- passive, automatic, recurring, collected while nobody is watching. The admin is
 *                 not present at collection time, so it MUST be opt-in in advance, and it must be
 *                 anonymous because an anonymous stream cannot be misused later.
 *   Feedback   -- one active, foreground, admin-initiated submission. The admin reads exactly what
 *                 will be sent, types the message themselves, and presses a button. That is
 *                 contemporaneous, specific, informed consent for that one transmission.
 *
 * This class therefore does NOT require Gate::granted(). See allowed() for what it does still
 * require and why each of those is not negotiable. That is a deliberate decision, not an oversight;
 * the reasoning, including the case against it, is in allowed() and in docs/FEEDBACK.md.
 *
 * ---------------------------------------------------------------------------------------------
 * THE JOIN-KEY RULE -- the most important line in this file
 * ---------------------------------------------------------------------------------------------
 * The feedback payload carries the site address. The telemetry payload carries an anonymous
 * install id. Neither payload may EVER carry both, and the feedback payload must never carry the
 * install id or the install token.
 *
 * If one feedback submission carried `site` and `install` together, the backend could join them and
 * de-anonymise every telemetry row ever received from that install -- retroactively, for the whole
 * history of the stream. The HMAC-under-a-local-salt design in Storage\Install would still be
 * mathematically sound and completely pointless, because we would have been handed the answer.
 * `install` and the bearer token are omitted from payload() for exactly this reason, and their
 * absence is asserted by a test. Do not add either.
 *
 * ---------------------------------------------------------------------------------------------
 * DEACTIVATION IS NEVER BLOCKED
 * ---------------------------------------------------------------------------------------------
 * Ranked first among the requirements, so it is built structurally rather than defended by careful
 * coding. This class never renders the Deactivate link, never rewrites its href, and never
 * server-side gates it. WordPress renders that link; we add a click listener to it and nothing
 * else. Every failure mode therefore degrades to "the link the browser already had":
 *
 *   - JS disabled, blocked by CSP, or never parsed  -> no listener, the link navigates natively.
 *   - JS throws while opening the modal             -> the click handler's catch navigates to the
 *                                                      href it captured before preventDefault().
 *   - fetch() absent                                -> submit is NOT intercepted, so the modal's
 *                                                      real <form> POSTs to admin-post.php, and
 *                                                      handle() redirects to the deactivate URL.
 *   - The request hangs, 500s, or the host is down  -> a watchdog timer, armed BEFORE the request
 *                                                      is built, navigates anyway. The response is
 *                                                      never awaited and never consulted.
 *   - The user wants none of it                     -> "Skip & Deactivate" is a plain <a> whose
 *                                                      href is a real, nonced deactivate URL,
 *                                                      computed server-side. It works with the JS
 *                                                      entirely broken.
 *
 * Escape and Cancel deliberately do NOT deactivate. That is a user decision to abort a destructive
 * action, which is what the accessible-dialog convention requires, and is not one of the failure
 * modes above -- the Deactivate link is still sitting there, unmodified.
 *
 * PHP 7.2 floor, like the rest of src/: no arrow functions, no typed properties, no match.
 */
class Deactivation {

	/**
	 * Feedback payload contract version. Independent of Event::SCHEMA -- this is a different
	 * payload on a different route and the two version separately. See docs/FEEDBACK.md.
	 */
	const SCHEMA = 1;

	/**
	 * Route under the endpoint namespace. Deliberately NOT telemetry/events.
	 */
	const ROUTE = 'telemetry/feedback';

	/**
	 * Hard ceiling on the free-text comment, in characters.
	 *
	 * Bounded because it is the one field in this SDK that carries whatever a human decided to
	 * type. A bound does not make free text safe -- nothing does, which is why EVENTS.md refuses it
	 * on the anonymous stream -- but it does keep one submission from becoming an unbounded upload,
	 * and it makes the promise in the modal ("up to N characters") a fact rather than a hope.
	 */
	const NOTE_MAX = 1000;

	/**
	 * Outbound request timeout, seconds.
	 *
	 * Shorter than Http\Transport::TIMEOUT (8s). That one runs on WP-Cron where nobody is waiting;
	 * this one runs in an admin-ajax request whose caller has already navigated away, so every
	 * second past the first is a PHP worker held open for a response nobody will read.
	 */
	const TIMEOUT = 5;

	/**
	 * Markup/behaviour contract between the modal element and the inline JS.
	 *
	 * Bumped when the data attributes or the DOM shape change. The JS refuses to enhance a root
	 * element whose contract it does not recognise, which is what makes it safe for two bundled
	 * copies at DIFFERENT SDK versions to coexist on one plugins.php: an old copy's JS will not
	 * touch a new copy's markup, and an unenhanced row simply keeps its native Deactivate link.
	 */
	const CONTRACT = 1;

	/**
	 * How long the browser waits for the submit request before navigating anyway, milliseconds.
	 *
	 * This is the number that makes "the request must not gate the redirect" true. It is a ceiling,
	 * not a delay: navigation happens as soon as the request settles, or at this deadline,
	 * whichever comes first.
	 */
	const NAVIGATE_AFTER_MS = 2000;

	/**
	 * Config.
	 *
	 * @var Config
	 */
	private $config;

	/**
	 * Consent gate. Used only to decide whether the chosen reason may be recorded for the
	 * TELEMETRY stream -- never to decide whether feedback may be submitted. See allowed().
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
	 * Register the listeners.
	 *
	 * Two hooks, deliberately asymmetric:
	 *
	 *   - The modal renders on `admin_footer-plugins.php` ONLY. The hook name carries the screen's
	 *     hook suffix, so this is scoped by construction rather than by an is_admin()/pagenow test
	 *     that could be got wrong. Nothing is loaded on any other admin screen, and nothing at all
	 *     on the front end.
	 *   - The submit handler registers on both `admin_post_` (the no-JS form fallback) and
	 *     `wp_ajax_` (the enhanced path), pointing at ONE callback. Both are separate admin
	 *     requests that never render plugins.php, so they cannot be folded into the hook above.
	 *
	 * Both action names are suffixed with the consumer's own slug, so two plugins bundling this SDK
	 * on one site get two independent endpoints and cannot receive each other's submissions.
	 *
	 * @param Config $config  Config.
	 * @param Gate   $consent Consent gate.
	 * @return void
	 */
	public static function register( Config $config, Gate $consent ) {

		if ( ! function_exists( 'add_action' ) ) {
			return;
		}

		$feedback = new self( $config, $consent );
		$action   = self::action_for( $config );

		add_action( 'admin_footer-plugins.php', array( $feedback, 'render' ) );
		add_action( 'admin_post_' . $action, array( $feedback, 'handle' ) );
		add_action( 'wp_ajax_' . $action, array( $feedback, 'handle' ) );
	}

	/**
	 * The admin-post/ajax action name for a consumer.
	 *
	 * @param Config $config Config.
	 * @return string
	 */
	public static function action_for( Config $config ) {
		return 'cx_tracker_feedback_' . str_replace( '-', '_', $config->plugin() );
	}

	/**
	 * The nonce action for this consumer's submissions.
	 *
	 * @return string
	 */
	public function nonce_action() {
		return 'cx_tracker_feedback_' . $this->config->plugin();
	}

	/**
	 * May feedback be transmitted at all?
	 *
	 * THE DECISION, stated plainly: feedback does NOT require Gate::granted(). It is not behind the
	 * telemetry opt-in.
	 *
	 * The case for putting it behind the gate is that it is still data leaving the site, and the
	 * gate is the SDK's whole compliance story. The case against, which wins:
	 *
	 *   1. The gate exists because passive collection happens when the administrator is not
	 *      present, so their agreement has to be obtained in advance. Feedback is the opposite
	 *      situation in every respect -- they are present, they are reading the disclosure, they
	 *      typed the message, and they pressed a button labelled with what it does. Consent given
	 *      at the moment of transmission, for that specific transmission, is a STRONGER basis than
	 *      a general opt-in recorded months earlier, not a weaker one. This is why Freemius can ask
	 *      for deactivation feedback without any prior telemetry opt-in.
	 *   2. Gating it would silence exactly the wrong people. An admin who declined telemetry, or
	 *      was never asked, is more likely -- not less -- to have something the developer needs to
	 *      hear, and "it broke my site" is the single most valuable thing this SDK can carry.
	 *   3. Gating it would turn the modal into an opt-in nag at the worst possible moment. A
	 *      dialog that says "agree to usage tracking before you can tell us why you are leaving"
	 *      is a dark pattern, and it is the kind of thing that gets a CONSUMER's plugin pulled.
	 *
	 * Three limits survive that decision, because without them "they clicked submit" would not be
	 * enough:
	 *
	 *   - CX_TRACKER_DISABLE wins unconditionally, checked first. A site owner or host must be able
	 *     to stop ALL outbound transmission from this SDK without touching plugin settings. If
	 *     feedback ignored the kill switch, the kill switch would be a lie -- and CONSENT.md
	 *     documents it as absolute.
	 *   - Config::enabled() (consent gate 1, the author's project-enable) still applies. If the
	 *     author never turned the project on there is no project to report to, and the SDK must be
	 *     inert. Feedback is not a way around gate 1.
	 *   - The modal must show what will be sent, itemised, with real values, BEFORE the button
	 *     exists to press. Consent that is not informed is not consent, so render() builds that
	 *     disclosure from the same site_fields() that payload() sends -- not from a hand-written
	 *     list that could drift away from it.
	 *
	 * A recorded telemetry OPT-OUT deliberately does not block a submission the same administrator
	 * is actively making right now: it is a decision about a different transmission, taken earlier,
	 * and the click in front of us is both more recent and more specific. The modal says so
	 * explicitly rather than leaving it implied. A site owner who disagrees has the kill switch and
	 * the filter below.
	 *
	 * @return bool
	 */
	public function allowed() {

		// Site-level kill switch, first and unconditional -- same position and same reasoning as
		// Gate::granted().
		if ( defined( 'CX_TRACKER_DISABLE' ) && CX_TRACKER_DISABLE ) {
			return false;
		}

		// Consent gate 1: the author enabled this project.
		if ( ! $this->config->enabled() ) {
			return false;
		}

		/**
		 * Final veto for site owners and hosts.
		 *
		 * Applied last, so -- exactly as with `cx_tracker_consent` -- reaching `true` already
		 * means every check above allowed it, and a callback can only ever turn feedback OFF. It
		 * is deliberately not a way to switch feedback on where the author never enabled it.
		 *
		 * @param bool   $allowed Whether feedback may be transmitted.
		 * @param string $plugin  Consumer plugin slug, so one consumer's filter cannot affect another's.
		 */
		if ( function_exists( 'apply_filters' ) ) {
			return (bool) apply_filters( 'cx_tracker_feedback', true, $this->config->plugin() );
		}

		return true;
	}

	/**
	 * Reduce a posted reason to an allow-listed one, or to ''.
	 *
	 * Never sanitises a value into the set -- an unrecognised reason becomes '' and is then omitted
	 * from the payload entirely, rather than being repaired into something plausible. '' is also
	 * the legitimate answer for an administrator who typed a comment without picking a reason, so
	 * "empty" and "forged" collapse to the same harmless outcome and neither reaches the wire.
	 *
	 * @param mixed $reason Posted value. Untrusted.
	 * @return string An Event::REASONS member, or ''.
	 */
	public static function normalize_reason( $reason ) {

		if ( ! is_string( $reason ) ) {
			return '';
		}

		return Event::is_valid_reason( $reason ) ? $reason : '';
	}

	/**
	 * Sanitise and bound a posted comment.
	 *
	 * Order matters. Sanitising first and truncating second means the bound applies to what will
	 * actually be transmitted; truncating first could cut inside a tag that sanitisation was about
	 * to remove anyway, and would make the advertised limit a lie about a different string.
	 *
	 * @param mixed $note Posted value. Untrusted.
	 * @return string
	 */
	public static function normalize_note( $note ) {

		if ( ! is_string( $note ) ) {
			return '';
		}

		if ( function_exists( 'sanitize_textarea_field' ) ) {
			$note = (string) sanitize_textarea_field( $note );
		} else {
			// Only reachable outside WordPress -- the load proof in bin/build-dist.sh constructs
			// SDK objects with no WP loaded at all. Strips tags and control characters so the bound
			// below is applied to plain text on either path, rather than silently skipping
			// sanitisation when the WP helper is missing.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- wp_strip_all_tags() is unavailable in this branch by definition.
			$note = (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', strip_tags( $note ) );
		}

		$note = trim( $note );

		if ( '' === $note ) {
			return '';
		}

		return self::truncate( $note, self::NOTE_MAX );
	}

	/**
	 * Truncate to a character count, multibyte-safe where mbstring is available.
	 *
	 * Multibyte safety is not optional here. mbstring is not guaranteed on a WordPress host, and
	 * substr() on a byte boundary would cut a multibyte character in half and put an invalid UTF-8
	 * sequence on the wire -- which wp_json_encode() then refuses to encode, silently losing the
	 * whole submission. Guarded rather than assumed.
	 *
	 * @param string $value  Value.
	 * @param int    $length Maximum characters.
	 * @return string
	 */
	private static function truncate( $value, $length ) {

		if ( function_exists( 'mb_substr' ) ) {
			return (string) mb_substr( $value, 0, $length, 'UTF-8' );
		}

		return substr( $value, 0, $length );
	}

	/**
	 * The environment and identity fields shared by the payload and the disclosure.
	 *
	 * ONE source for both, on purpose. The requirement is that the modal show plainly what will be
	 * sent; a hand-written list in the markup would satisfy that on the day it was written and
	 * quietly stop being true the first time a field was added to the payload. Because both read
	 * this method, "the disclosure lists everything that is sent" is a property a test can assert,
	 * and it is asserted.
	 *
	 * @return array<string,mixed>
	 */
	public function site_fields() {
		return array(
			'site'           => function_exists( 'home_url' ) ? (string) home_url() : '',
			'plugin'         => $this->config->plugin(),
			'plugin_version' => $this->config->version(),
			'wp'             => function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'version' ) : '',
			'php'            => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
			'locale'         => function_exists( 'get_locale' ) ? (string) get_locale() : '',
			'multisite'      => function_exists( 'is_multisite' ) ? (bool) is_multisite() : false,
		);
	}

	/**
	 * Build the outbound feedback payload.
	 *
	 * Re-normalises both submitted values even though handle() already did. This is the only
	 * function that decides what goes on the wire, so it is the right place for the guarantee to
	 * live: a forged reason cannot be transmitted no matter which path reached here, and a test
	 * calls this method directly with a forged value to prove it.
	 *
	 * Absent by design, and asserted absent by a test:
	 *
	 *   - `install`, the anonymous install id  -- the join-key rule; see the class docblock.
	 *   - the install bearer token             -- same reason. This route is unauthenticated
	 *                                             precisely so that a site which never opted into
	 *                                             telemetry (and therefore has no token) can still
	 *                                             send feedback. Attaching a token where one
	 *                                             happened to exist would make the payload
	 *                                             linkable for opted-in sites and not for others,
	 *                                             which is the worst of both.
	 *   - any email address or user identity   -- see docs/FEEDBACK.md; nothing here is derived
	 *                                             from a user account.
	 *
	 * @param mixed $reason Posted reason. Untrusted.
	 * @param mixed $note   Posted comment. Untrusted.
	 * @return array<string,mixed>
	 */
	public function payload( $reason, $note ) {
		$payload = array(
			'schema' => self::SCHEMA,
			'sdk'    => Tracker::VERSION,
			'hash'   => $this->config->hash(),
			'at'     => time(),
		);

		$payload = array_merge( $payload, $this->site_fields() );

		// `project` is legacy and optional, so it is included ONLY when the consumer actually set it.
		// Transmitting an empty string on every request is worse than omitting the key: it looks like
		// data, and it invites ingestion to treat a field that is almost always blank as meaningful.
		if ( '' !== $this->config->project() ) {
			$payload['project'] = $this->config->project();
		}
		$reason = self::normalize_reason( $reason );

		if ( '' !== $reason ) {
			$payload['reason'] = $reason;
		}

		$note = self::normalize_note( $note );

		if ( '' !== $note ) {
			$payload['note'] = $note;
		}

		return $payload;
	}

	/**
	 * Handle a submission, then let the deactivation proceed.
	 *
	 * Reached by two routes with one behaviour: admin-ajax.php (enhanced) and admin-post.php (the
	 * no-JS form fallback). Ordering here is the security contract:
	 *
	 *   1. Capability, before anything else is read.
	 *   2. Nonce, via check_admin_referer(), which fails closed by calling wp_die() itself.
	 *   3. Only then is $_POST touched, and every field is normalised through a validator that
	 *      cannot pass an unrecognised value through.
	 *
	 * @return void
	 */
	public function handle() {

		// deactivate_plugins, not manage_options: this endpoint exists to accompany a
		// deactivation, so the right question is "may this user deactivate plugins at all". On a
		// single site the two coincide; on multisite they do not, and a user who cannot deactivate
		// has no business submitting a deactivation reason.
		if ( ! current_user_can( 'deactivate_plugins' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'plugin-tracker-sdk' ), '', array( 'response' => 403 ) );
		}

		// Fails closed on its own (wp_nonce_ays() + die()). Scoped to this consumer's slug, so one
		// plugin's nonce cannot authorise another plugin's submission.
		check_admin_referer( $this->nonce_action() );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by check_admin_referer() immediately above.
		$reason = isset( $_POST['reason'] ) ? self::normalize_reason( sanitize_key( wp_unslash( $_POST['reason'] ) ) ) : '';
		// sanitize_textarea_field() here AND inside normalize_note(): it is idempotent, so the
		// duplication is free, and it keeps the sanitising call visible at the point the
		// superglobal is read instead of hidden one call away. normalize_note() owns the length
		// bound, which is the part sanitisation does not give us.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by check_admin_referer() immediately above.
		$note = isset( $_POST['note'] ) ? self::normalize_note( sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) ) : '';

		// The chosen reason is ALSO useful on the telemetry stream, where `reason` is an
		// allow-listed field (docs/EVENTS.md) that nothing currently populates -- Lifecycle
		// queues the deactivation event with no reason, because it fires after this request has
		// finished. Stashing it here lets Lifecycle::on_deactivate() pick it up.
		//
		// This write IS consent-gated, and the asymmetry with the feedback submission above is the
		// point rather than an inconsistency: anything destined for the anonymous telemetry stream
		// is collected only with the telemetry opt-in, because CONSENT.md requires consent to
		// precede collection, not merely transmission. The free-text note is never stored here and
		// never reaches that stream.
		if ( '' !== $reason && $this->consent->granted() ) {
			update_option(
				$this->config->option( 'reason' ),
				array(
					'reason' => $reason,
					'at'     => time(),
				),
				false
			);
		}

		$sent = false;

		if ( ( '' !== $reason || '' !== $note ) && $this->allowed() ) {
			// Wrapped because this method's contract is that it ALWAYS reaches finish(). Anything
			// escaping the HTTP call -- a filter throwing, a fatal in a wp_remote_post filter on
			// the consumer's site, an out-of-memory on a huge response -- would otherwise strand
			// the administrator on admin-post.php with their plugin still active, which is the
			// exact failure this class is built to make impossible.
			//
			// \Throwable, not \Exception: a TypeError from third-party code hooked into
			// wp_remote_post is an Error, and it must not be the thing that blocks a deactivation.
			// Swallowed rather than rethrown, but surfaced through dev_warning() so it is visible
			// under WP_DEBUG instead of silent -- losing one feedback submission is acceptable,
			// losing it invisibly is not.
			try {
				$sent = $this->send( $this->payload( $reason, $note ) );
			} catch ( \Throwable $e ) {
				$sent = false;
				Notice::dev_warning( $this->config, 'tracker: feedback submission failed: ' . $e->getMessage() );
			}
		}

		$this->finish( $sent );
	}

	/**
	 * Respond, and on the no-JS path send the browser on to the real deactivation.
	 *
	 * The AJAX branch returns a result the enhanced client does not wait for and does not act on;
	 * it exists so the endpoint is inspectable and so a failure is visible in a network log rather
	 * than silent.
	 *
	 * @param bool $sent Whether the transmission succeeded.
	 * @return void
	 */
	private function finish( $sent ) {

		// if/else rather than an early return: wp_send_json_success() terminates the request
		// itself, so a `return` after it is unreachable and PHPStan says so. Branching keeps both
		// tails visible and keeps the method's contract -- it always ends the request one way or
		// the other -- readable rather than implied.
		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			wp_send_json_success( array( 'sent' => (bool) $sent ) );
		} else {
			// Computed from Config::basename(), never from a posted value. A redirect target taken
			// from $_POST would be an open redirect, and there is nothing to gain by trusting one:
			// the only legitimate destination is this plugin's own deactivate URL, and we can
			// derive it.
			wp_safe_redirect( $this->deactivate_url() );
			exit;
		}
	}

	/**
	 * Transmit one feedback submission. Fire and forget.
	 *
	 * Never queued and never retried, unlike every telemetry event. Two reasons, both deliberate:
	 *
	 *   - Queueing means persisting whatever a human typed into wp_options on a site whose
	 *     administrator is in the middle of walking away. The SDK cannot inspect that text, so the
	 *     only safe place for it is nowhere.
	 *   - Retrying means transmitting after the plugin has been deactivated -- after the
	 *     relationship the message was about has ended. A dropped message is the better failure.
	 *
	 * A failure here is therefore final, and that is fine: it also makes the never-block property
	 * trivial, because there is no state for a failure to leave behind.
	 *
	 * @param array $payload Payload from payload().
	 * @return bool Whether the endpoint accepted it.
	 */
	private function send( array $payload ) {

		if ( ! function_exists( 'wp_remote_post' ) ) {
			return false;
		}

		$response = wp_remote_post(
			$this->config->endpoint() . '/' . self::ROUTE,
			array(
				'timeout'     => self::TIMEOUT,
				'redirection' => 0,
				'blocking'    => true,
				'headers'     => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'user-agent'  => 'PluginTrackerSDK/' . Tracker::VERSION,
				'body'        => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		return (int) wp_remote_retrieve_response_code( $response ) < 300;
	}

	/**
	 * A real, nonced deactivate URL for this plugin, built the way WordPress builds it.
	 *
	 * This is the "Skip & Deactivate" href and the no-JS redirect target. It is a genuine link, not
	 * a placeholder the JS has to repair: the nonce action WordPress checks is
	 * `deactivate-plugin_{basename}` (WP_Plugins_List_Table::single_row()), so a URL built with
	 * that action is accepted by plugins.php exactly as the row's own link is.
	 *
	 * self_admin_url() rather than admin_url(), so a network-activated plugin resolves to the
	 * network plugins screen, which is the only screen its Deactivate link appears on.
	 *
	 * @return string
	 */
	public function deactivate_url() {

		$basename = $this->config->basename();

		if ( '' === $basename || ! function_exists( 'wp_nonce_url' ) ) {
			return '';
		}

		$base = function_exists( 'self_admin_url' ) ? self_admin_url( 'plugins.php' ) : admin_url( 'plugins.php' );

		$url = add_query_arg(
			array(
				'action' => 'deactivate',
				'plugin' => $basename,
			),
			$base
		);

		return (string) wp_nonce_url( $url, 'deactivate-plugin_' . $basename );
	}

	/**
	 * User-facing copy, filterable so a consumer can localise or rebrand it.
	 *
	 * Same mechanism and same reasoning as Consent\Notice::strings(): the SDK ships and loads its
	 * own text domain from its own languages/ directory (see I18n), so these are already translated
	 * wherever a .mo exists, and this filter is the additional override for a consumer who wants
	 * the wording to match their own product:
	 *
	 *     add_filter( 'cx_tracker_feedback_strings', function ( $s ) {
	 *         $s['submit'] = __( 'Send &amp; deactivate', 'my-plugin' );
	 *         return $s;
	 *     } );
	 *
	 * @return array<string,string>
	 */
	public function strings() {

		$defaults = array(
			'title'              => __( 'Before you go, what went wrong?', 'plugin-tracker-sdk' ),
			'intro'              => __( 'This goes straight to the developers, and it is the only thing that tells them what to fix. Answering is optional.', 'plugin-tracker-sdk' ),
			'reason_legend'      => __( 'Why are you deactivating?', 'plugin-tracker-sdk' ),
			'note_label'         => __( 'Anything else you want to tell them? (optional)', 'plugin-tracker-sdk' ),
			'note_hint'          => __( 'Please do not include passwords, licence keys, or anyone\'s personal details.', 'plugin-tracker-sdk' ),
			'disclosure_summary' => __( 'This is sent to the plugin\'s developer, not to WordPress.org, and only because you pressed the button.', 'plugin-tracker-sdk' ),
			'disclosure_more'    => __( 'Click here to see exactly what is sent', 'plugin-tracker-sdk' ),
			'disclosure_title'   => __( 'Pressing the button below sends exactly this, and nothing else:', 'plugin-tracker-sdk' ),
			'disclosure_note'    => __( 'Your comment, exactly as you typed it', 'plugin-tracker-sdk' ),
			'disclosure_none'    => __( 'It does not send your email address, your username, your other plugins, or the anonymous usage-tracking ID. It is sent once, because you pressed the button, and it is separate from usage tracking, which is unaffected by this and stays as you set it.', 'plugin-tracker-sdk' ),
			'submit'             => __( 'Send feedback &amp; deactivate', 'plugin-tracker-sdk' ),
			'skip'               => __( 'Skip &amp; Deactivate', 'plugin-tracker-sdk' ),
			'cancel'             => __( 'Cancel, keep it active', 'plugin-tracker-sdk' ),
			'close'              => __( 'Close', 'plugin-tracker-sdk' ),
			'sending'            => __( 'Sending&hellip;', 'plugin-tracker-sdk' ),
			'field_site'         => __( 'Site address', 'plugin-tracker-sdk' ),
			'field_plugin'       => __( 'Plugin and version', 'plugin-tracker-sdk' ),
			'field_wp'           => __( 'WordPress version', 'plugin-tracker-sdk' ),
			'field_php'          => __( 'PHP version', 'plugin-tracker-sdk' ),
			'field_locale'       => __( 'Site language', 'plugin-tracker-sdk' ),
			'field_multisite'    => __( 'Multisite', 'plugin-tracker-sdk' ),
			'field_hash'         => __( 'Plugin identifier', 'plugin-tracker-sdk' ),
			'field_reason'       => __( 'The reason you pick above', 'plugin-tracker-sdk' ),
			'yes'                => __( 'Yes', 'plugin-tracker-sdk' ),
			'no'                 => __( 'No', 'plugin-tracker-sdk' ),
		);

		if ( ! function_exists( 'apply_filters' ) ) {
			return $defaults;
		}

		/**
		 * Filter the deactivation-feedback copy.
		 *
		 * @param array  $defaults Default English strings.
		 * @param string $plugin   Consumer plugin slug, so one consumer's filter cannot affect another's.
		 */
		$filtered = apply_filters( 'cx_tracker_feedback_strings', $defaults, $this->config->plugin() );

		if ( ! is_array( $filtered ) ) {
			return $defaults;
		}

		// Merged over the defaults so a partial filter cannot blank a string, and every value is
		// cast and escaped at the point of use.
		return array_merge( $defaults, $filtered );
	}

	/**
	 * One follow-up prompt per reason.
	 *
	 * A generic "anything else?" gets generic answers. Naming the thing you actually want to know is
	 * what turns a survey into something a developer can act on, which is the point of asking at all.
	 *
	 * A sibling of reason_labels() rather than an entry in strings(): strings() is a flat, filterable
	 * string map, and burying a nested array in it makes both its type and its filter contract murky.
	 *
	 * @return array<string,string>
	 */
	public function reason_prompts() {
		$prompts = array(
			'temporary'        => __( 'Anything the developer should know before you come back?', 'plugin-tracker-sdk' ),
			'no_longer_needed' => __( 'What changed?', 'plugin-tracker-sdk' ),
			'found_better'     => __( 'Which plugin did you move to, and what does it do better?', 'plugin-tracker-sdk' ),
			'broke_site'       => __( 'What broke? This is the single most useful thing you can tell the developer.', 'plugin-tracker-sdk' ),
			'confusing'        => __( 'What were you trying to do?', 'plugin-tracker-sdk' ),
			'missing_feature'  => __( 'Which feature were you looking for?', 'plugin-tracker-sdk' ),
			'other'            => __( 'What happened?', 'plugin-tracker-sdk' ),
		);

		if ( ! function_exists( 'apply_filters' ) ) {
			return $prompts;
		}

		/**
		 * Filter the per-reason follow-up prompts.
		 *
		 * @param array  $prompts Reason slug => prompt.
		 * @param string $plugin  Consumer plugin slug, so one consumer cannot affect another's.
		 */
		$filtered = apply_filters( 'cx_tracker_feedback_prompts', $prompts, $this->config->plugin() );

		return is_array( $filtered ) ? array_merge( $prompts, $filtered ) : $prompts;
	}

	/**
	 * Human-readable labels for the closed reason set.
	 *
	 * Keyed by the Event::REASONS members, and rendered by iterating REASONS rather than this
	 * array, so a reason without a label degrades to its raw key instead of vanishing from the
	 * form -- and a label without a reason can never introduce one.
	 *
	 * @return array<string,string>
	 */
	public function reason_labels() {

		$labels = array(
			'temporary'        => __( 'It is only temporary — I will turn it back on', 'plugin-tracker-sdk' ),
			'no_longer_needed' => __( 'I no longer need it', 'plugin-tracker-sdk' ),
			'found_better'     => __( 'I found a better plugin', 'plugin-tracker-sdk' ),
			'broke_site'       => __( 'It broke my site', 'plugin-tracker-sdk' ),
			'confusing'        => __( 'I could not work out how to use it', 'plugin-tracker-sdk' ),
			'missing_feature'  => __( 'It is missing a feature I need', 'plugin-tracker-sdk' ),
			'other'            => __( 'Something else', 'plugin-tracker-sdk' ),
		);

		if ( ! function_exists( 'apply_filters' ) ) {
			return $labels;
		}

		/**
		 * Filter the reason labels.
		 *
		 * Labels only. The reason SET is Event::REASONS and is not filterable -- a consumer adding
		 * a key here cannot add a reason, because the form is built from REASONS and the handler
		 * validates against REASONS.
		 *
		 * @param array  $labels Default English labels, keyed by reason.
		 * @param string $plugin Consumer plugin slug.
		 */
		$filtered = apply_filters( 'cx_tracker_feedback_reasons', $labels, $this->config->plugin() );

		return is_array( $filtered ) ? array_merge( $labels, $filtered ) : $labels;
	}

	/**
	 * Render the modal, its styles and its behaviour into the plugins.php footer.
	 *
	 * Renders the DIALOG only. It does not render, wrap, move or rewrite the Deactivate link --
	 * WordPress owns that link and it stays exactly as WordPress emitted it, which is what makes
	 * every JS failure mode degrade to a working deactivation.
	 *
	 * @return void
	 */
	public function render() {

		// No point showing a dialog about a link this user cannot see. Also means an editor or
		// author browsing an admin screen never receives the markup at all.
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'deactivate_plugins' ) ) {
			return;
		}

		if ( ! $this->allowed() ) {
			return;
		}

		$basename = $this->config->basename();

		if ( '' === $basename ) {
			return;
		}

		$slug     = $this->config->plugin();
		$id       = 'cx-tracker-feedback-' . $slug;
		$text     = $this->strings();
		$labels   = $this->reason_labels();
		$fields   = $this->site_fields();
		$prompts  = $this->reason_prompts();
		$endpoint = admin_url( 'admin-ajax.php' );
		$fallback = admin_url( 'admin-post.php' );
		$action   = self::action_for( $this->config );
		?>
		<style id="<?php echo esc_attr( $id ); ?>-css">
		<?php echo esc_html( $this->css( $id ) ); ?>
		</style>
		<div
			id="<?php echo esc_attr( $id ); ?>"
			class="cx-tracker-feedback"
			data-cx-tracker-feedback="<?php echo esc_attr( $slug ); ?>"
			data-cx-contract="<?php echo esc_attr( (string) self::CONTRACT ); ?>"
			data-cx-basename="<?php echo esc_attr( $basename ); ?>"
			data-cx-endpoint="<?php echo esc_url( $endpoint ); ?>"
			data-cx-timeout="<?php echo esc_attr( (string) self::NAVIGATE_AFTER_MS ); ?>"
			data-cx-sending="<?php echo esc_attr( (string) $text['sending'] ); ?>"
			hidden>
			<div class="cx-tracker-feedback__veil" data-cx-close="1"></div>
			<div
				class="cx-tracker-feedback__dialog"
				role="dialog"
				aria-modal="true"
				aria-labelledby="<?php echo esc_attr( $id ); ?>-title"
				aria-describedby="<?php echo esc_attr( $id ); ?>-disclosure">
				<h2 class="cx-tracker-feedback__title" id="<?php echo esc_attr( $id ); ?>-title">
					<?php echo esc_html( (string) $text['title'] ); ?>
				</h2>
				<p class="cx-tracker-feedback__intro"><?php echo esc_html( (string) $text['intro'] ); ?></p>

				<form
					class="cx-tracker-feedback__form"
					method="post"
					action="<?php echo esc_url( $fallback ); ?>">

					<fieldset class="cx-tracker-feedback__reasons">
						<legend><?php echo esc_html( (string) $text['reason_legend'] ); ?></legend>
						<?php foreach ( Event::REASONS as $reason ) : ?>
							<?php $input_id = $id . '-reason-' . str_replace( '_', '-', $reason ); ?>
							<label class="cx-tracker-feedback__reason" for="<?php echo esc_attr( $input_id ); ?>">
								<input
									type="radio"
									id="<?php echo esc_attr( $input_id ); ?>"
									name="reason"
									value="<?php echo esc_attr( $reason ); ?>"
									data-cx-reason
									data-cx-prompt="<?php echo esc_attr( isset( $prompts[ $reason ] ) ? (string) $prompts[ $reason ] : (string) $text['note_label'] ); ?>">
								<span><?php echo esc_html( isset( $labels[ $reason ] ) ? (string) $labels[ $reason ] : $reason ); ?></span>
							</label>
						<?php endforeach; ?>
					</fieldset>

					<?php /* Visible by default on purpose: the JS hides it on load and reveals it once a reason is chosen, so a scripting-disabled browser still gets a usable comment box rather than none. */ ?>
					<p class="cx-tracker-feedback__note" data-cx-note>
						<label for="<?php echo esc_attr( $id ); ?>-note" data-cx-note-label>
							<?php echo esc_html( (string) $text['note_label'] ); ?>
						</label>
						<textarea
							id="<?php echo esc_attr( $id ); ?>-note"
							name="note"
							rows="3"
							maxlength="<?php echo esc_attr( (string) self::NOTE_MAX ); ?>"></textarea>
						<span class="cx-tracker-feedback__hint"><?php echo esc_html( (string) $text['note_hint'] ); ?></span>
					</p>

					<div class="cx-tracker-feedback__disclosure" id="<?php echo esc_attr( $id ); ?>-disclosure">
						<p class="cx-tracker-feedback__disclosure-summary">
							<?php echo esc_html( (string) $text['disclosure_summary'] ); ?>
						</p>
						<?php /* A <details> element, not JS: it works with scripting disabled, is keyboard operable and announced as a disclosure by screen readers for free. The itemised list stays in the DOM either way, so aria-describedby still resolves and nothing is hidden from assistive tech that ignores the toggle. */ ?>
						<details class="cx-tracker-feedback__details">
							<summary class="cx-tracker-feedback__details-toggle">
								<?php echo esc_html( (string) $text['disclosure_more'] ); ?>
							</summary>
							<ul>
								<li><?php echo esc_html( (string) $text['field_site'] ); ?>: <code><?php echo esc_html( (string) $fields['site'] ); ?></code></li>
								<li><?php echo esc_html( (string) $text['field_plugin'] ); ?>: <code><?php echo esc_html( (string) $fields['plugin'] ); ?> <?php echo esc_html( (string) $fields['plugin_version'] ); ?></code></li>
								<li><?php echo esc_html( (string) $text['field_wp'] ); ?>: <code><?php echo esc_html( (string) $fields['wp'] ); ?></code></li>
								<li><?php echo esc_html( (string) $text['field_php'] ); ?>: <code><?php echo esc_html( (string) $fields['php'] ); ?></code></li>
								<li><?php echo esc_html( (string) $text['field_locale'] ); ?>: <code><?php echo esc_html( (string) $fields['locale'] ); ?></code></li>
								<li><?php echo esc_html( (string) $text['field_multisite'] ); ?>: <code><?php echo esc_html( $fields['multisite'] ? (string) $text['yes'] : (string) $text['no'] ); ?></code></li>
								<li><?php echo esc_html( (string) $text['field_hash'] ); ?>: <code><?php echo esc_html( $this->config->hash() ); ?></code></li>
								<li><?php echo esc_html( (string) $text['field_reason'] ); ?></li>
								<li><?php echo esc_html( (string) $text['disclosure_note'] ); ?></li>
							</ul>
							<p><?php echo esc_html( (string) $text['disclosure_none'] ); ?></p>
						</details>
					</div>

					<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>">
					<?php wp_nonce_field( $this->nonce_action() ); ?>

					<p class="cx-tracker-feedback__actions">
						<button type="submit" class="button button-primary cx-tracker-feedback__submit">
							<?php echo esc_html( (string) $text['submit'] ); ?>
						</button>
						<a class="button cx-tracker-feedback__skip" href="<?php echo esc_url( $this->deactivate_url() ); ?>" data-cx-skip="1">
							<?php echo esc_html( (string) $text['skip'] ); ?>
						</a>
						<button type="button" class="button-link cx-tracker-feedback__cancel" data-cx-close="1">
							<?php echo esc_html( (string) $text['cancel'] ); ?>
						</button>
					</p>
				</form>
			</div>
		</div>
		<script id="<?php echo esc_attr( $id ); ?>-js">
		<?php
		/*
		 * Inlined rather than enqueued from a .js file, and that is a distribution fact rather
		 * than a preference. bin/build-dist.sh copies `find "${SRC_DIR}" -name '*.php'` into the
		 * scoped artifact, plus a generated autoload.php, a generated composer.json, languages/
		 * and LICENSE -- nothing else, and no asset directory. A .js or .css file would therefore
		 * never reach a consumer, and an enqueued handle pointing at a missing file is a 404 on
		 * every plugins.php load. Inline is the only form that survives the build.
		 *
		 * No jQuery. It happens to be present on plugins.php today, but a bundled library that
		 * breaks when a site dequeues a core script has made its consumer's problem worse, and
		 * nothing here needs it.
		 *
		 * The ONLY interpolation is the consumer's own slug, and it is inert by construction:
		 * Config::SLUG_PATTERN restricts it to ^[a-z0-9][a-z0-9-]{0,61}[a-z0-9]$, so it cannot
		 * contain a quote, a backslash, or an angle bracket, and Config rejects the whole config
		 * otherwise. esc_js() is applied anyway. Everything else the script needs is read from the
		 * data attributes above, each escaped individually, which is why there is no JSON blob
		 * echoed into a script tag here.
		 */
		?>
		( function () {
			'use strict';

			var SLUG     = '<?php echo esc_js( $slug ); ?>';
			var CONTRACT = '<?php echo esc_js( (string) self::CONTRACT ); ?>';

			var root = document.querySelector( '[data-cx-tracker-feedback="' + SLUG + '"]' );

			// Refuse markup this build does not understand. Two bundled copies at different SDK
			// versions both run their own script; each enhances only a root whose contract it
			// recognises, and an unenhanced root just means the native Deactivate link is used.
			if ( ! root || CONTRACT !== root.getAttribute( 'data-cx-contract' ) ) {
				return;
			}

			if ( '1' === root.getAttribute( 'data-cx-ready' ) ) {
				return;
			}

			var basename = root.getAttribute( 'data-cx-basename' ) || '';
			var endpoint = root.getAttribute( 'data-cx-endpoint' ) || '';
			var deadline = parseInt( root.getAttribute( 'data-cx-timeout' ), 10 ) || 2000;
			var sending  = root.getAttribute( 'data-cx-sending' ) || '';

			if ( ! basename || ! endpoint ) {
				return;
			}

			var dialog  = root.querySelector( '.cx-tracker-feedback__dialog' );
			var note    = root.querySelector( '[data-cx-note]' );
			var noteLbl = root.querySelector( '[data-cx-note-label]' );
			var radios  = root.querySelectorAll( '[data-cx-reason]' );

			// Conditional comment box, hidden only once JS is running so no-JS keeps the box.
			if ( note ) {
				note.hidden = true;

				for ( var r = 0; r < radios.length; r++ ) {
					radios[ r ].addEventListener( 'change', function ( event ) {
						var prompt = event.target.getAttribute( 'data-cx-prompt' );

						if ( noteLbl && prompt ) {
							noteLbl.textContent = prompt;
						}

						note.hidden = false;
					} );
				}
			}

			var form    = root.querySelector( '.cx-tracker-feedback__form' );
			var skip    = root.querySelector( '.cx-tracker-feedback__skip' );
			var submit  = root.querySelector( '.cx-tracker-feedback__submit' );

			if ( ! dialog || ! form || ! skip || ! submit ) {
				return;
			}

			// Is this anchor OUR row's Deactivate link?
			//
			// Decided by parsing the href's own query string and comparing `plugin` to our exact
			// basename -- not by a row selector, a link id, or a slug prefix. plugins.php lists
			// every plugin on the site and several may bundle this same SDK, so the test has to be
			// the identity WordPress itself keys the action on. A URL we cannot parse is treated as
			// "not ours", which leaves it native.
			function ours( link ) {
				var href = link.getAttribute( 'href' );
				var url;

				if ( ! href ) {
					return false;
				}

				try {
					url = new URL( href, window.location.href );
				} catch ( e ) {
					return false;
				}

				if ( ! url.searchParams || 'deactivate' !== url.searchParams.get( 'action' ) ) {
					return false;
				}

				return basename === url.searchParams.get( 'plugin' );
			}

			var links = [];
			var all   = document.querySelectorAll( 'a[href*="deactivate"]' );
			var i;

			for ( i = 0; i < all.length; i++ ) {
				if ( ours( all[ i ] ) ) {
					links.push( all[ i ] );
				}
			}

			// Our plugin is not on this page (another status filter, another paged view, or it is
			// not active). Nothing to enhance; every other plugin's link is left completely alone.
			if ( ! links.length ) {
				return;
			}

			root.setAttribute( 'data-cx-ready', '1' );

			var target   = '';
			var opener   = null;
			var leaving  = false;
			var focusSel = 'a[href], button:not([disabled]), input:not([disabled]), textarea:not([disabled]), select:not([disabled])';

			function focusable() {
				return dialog.querySelectorAll( focusSel );
			}

			function onKey( event ) {
				var items;
				var first;
				var last;

				if ( 'Escape' === event.key || 'Esc' === event.key || 27 === event.keyCode ) {
					event.preventDefault();
					close();
					return;
				}

				if ( 'Tab' !== event.key && 9 !== event.keyCode ) {
					return;
				}

				items = focusable();

				if ( ! items.length ) {
					return;
				}

				first = items[ 0 ];
				last  = items[ items.length - 1 ];

				// A real trap, not just an initial focus: this dialog interrupts a destructive
				// action, so Tab must not wander back into the plugins table behind it.
				if ( event.shiftKey && document.activeElement === first ) {
					event.preventDefault();
					last.focus();
				} else if ( ! event.shiftKey && document.activeElement === last ) {
					event.preventDefault();
					first.focus();
				}
			}

			function open( href, link ) {
				target = href;
				opener = link;

				// Upgrade the skip href from the server-computed URL to the exact link the user
				// clicked, so skipping returns them to the same filter and page of the list. The
				// server-computed value is already a working deactivate URL, so this is an
				// improvement on a fallback, never a repair of a broken one.
				skip.setAttribute( 'href', href );

				root.hidden = false;
				root.classList.add( 'is-open' );
				document.addEventListener( 'keydown', onKey, true );

				var items = focusable();

				if ( items.length ) {
					items[ 0 ].focus();
				}
			}

			function close() {
				root.classList.remove( 'is-open' );
				root.hidden = true;
				document.removeEventListener( 'keydown', onKey, true );

				// Escape and Cancel abort the deactivation and return focus to where it was. The
				// link is untouched, so the user can click it again.
				if ( opener && opener.focus ) {
					opener.focus();
				}
			}

			function go() {
				if ( leaving ) {
					return;
				}

				leaving = true;
				window.location.href = target;
			}

			for ( i = 0; i < links.length; i++ ) {
				links[ i ].addEventListener( 'click', function ( event ) {
					var href = this.getAttribute( 'href' );

					// Let the browser own modified clicks: ctrl/cmd-click means "new tab", and
					// hijacking that would be both wrong and surprising.
					if ( event.defaultPrevented || 0 !== event.button || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey ) {
						return;
					}

					event.preventDefault();

					// Everything after preventDefault() has taken responsibility for a navigation
					// the browser was about to perform, so it hands that responsibility back on
					// any failure rather than leaving the administrator stuck.
					try {
						open( href, this );
					} catch ( e ) {
						target  = href;
						leaving = false;
						go();
					}
				} );
			}

			var closers = root.querySelectorAll( '[data-cx-close]' );

			for ( i = 0; i < closers.length; i++ ) {
				closers[ i ].addEventListener( 'click', function ( event ) {
					event.preventDefault();
					close();
				} );
			}

			form.addEventListener( 'submit', function ( event ) {
				var data;

				// No fetch() means no way to POST without leaving the page, so the native form
				// submission is allowed to happen: it posts to admin-post.php, and the handler
				// redirects to the deactivate URL. Slower, one extra hop, still deactivates.
				if ( ! window.fetch ) {
					return;
				}

				event.preventDefault();

				submit.disabled = true;

				if ( sending ) {
					submit.textContent = sending;
				}

				// Armed BEFORE the request is constructed, so navigation is guaranteed even if
				// building the request throws. This is the line that makes the redirect
				// ungated by the network: it fires at the deadline no matter what the request is
				// doing, and the request is never awaited or inspected.
				window.setTimeout( go, deadline );

				try {
					data = new FormData( form );

					// keepalive lets the POST outlive the navigation that is about to happen, so
					// the message is still delivered even though nothing waits for it.
					window.fetch( endpoint, {
						method: 'POST',
						body: data,
						credentials: 'same-origin',
						keepalive: true
					} ).then( go, go );
				} catch ( e ) {
					go();
				}
			} );
		} )();
		</script>
		<?php
	}

	/**
	 * The modal's styles.
	 *
	 * Returned as a string rather than written inline so that render() emits it through
	 * esc_html(), which is what keeps a stylesheet from being a place markup can hide. It is
	 * static text with the element id substituted, and the id is derived from a
	 * Config::SLUG_PATTERN slug.
	 *
	 * Scoped under this consumer's own element id so two bundled copies on one plugins.php cannot
	 * restyle each other. Duplication between copies is accepted deliberately: a shared, deduped
	 * stylesheet would mean one copy's CSS governing another copy's markup, which is precisely the
	 * cross-version coupling bin/build-dist.sh exists to eliminate.
	 *
	 * @param string $id Root element id.
	 * @return string
	 */
	private function css( $id ) {

		$selector = '#' . $id;

		$css = '
%1$s { position: fixed; inset: 0; z-index: 100100; display: flex; align-items: center; justify-content: center; padding: 20px; }
%1$s[hidden] { display: none; }
%1$s .cx-tracker-feedback__veil { position: absolute; inset: 0; background: rgba( 0, 0, 0, 0.7 ); }
%1$s .cx-tracker-feedback__dialog { position: relative; max-width: 560px; width: 100%%; max-height: 90vh; overflow-y: auto; background: #fff; color: #1d2327; border-radius: 4px; box-shadow: 0 3px 30px rgba( 0, 0, 0, 0.3 ); padding: 24px; }
%1$s .cx-tracker-feedback__title { margin: 0 0 8px; font-size: 1.3em; line-height: 1.4; color: #1d2327; }
%1$s .cx-tracker-feedback__disclosure-summary { margin: 0 0 6px; color: #50575e; }
%1$s .cx-tracker-feedback__details { margin: 0 0 8px; }
%1$s .cx-tracker-feedback__details-toggle { cursor: pointer; color: #2271b1; text-decoration: underline; }
%1$s .cx-tracker-feedback__details-toggle:focus-visible { outline: 2px solid #2271b1; outline-offset: 2px; }
%1$s .cx-tracker-feedback__intro { margin: 0 0 16px; color: #50575e; }
%1$s .cx-tracker-feedback__reasons { border: 0; margin: 0 0 16px; padding: 0; }
%1$s .cx-tracker-feedback__reasons legend { font-weight: 600; padding: 0 0 8px; }
%1$s .cx-tracker-feedback__reason { display: flex; align-items: flex-start; gap: 8px; padding: 4px 0; cursor: pointer; }
%1$s .cx-tracker-feedback__note { margin: 0 0 16px; }
%1$s .cx-tracker-feedback__note label { display: block; font-weight: 600; margin-bottom: 4px; }
%1$s .cx-tracker-feedback__dialog textarea { width: 100%%; box-sizing: border-box; }
%1$s .cx-tracker-feedback__note textarea { width: 100%%; }
%1$s .cx-tracker-feedback__hint { display: block; margin-top: 4px; color: #646970; font-size: 0.9em; }
%1$s .cx-tracker-feedback__disclosure { background: #f6f7f7; border-left: 4px solid #72aee6; padding: 12px 16px; margin: 0 0 16px; font-size: 0.9em; }
%1$s .cx-tracker-feedback__disclosure p { margin: 0 0 8px; }
%1$s .cx-tracker-feedback__disclosure p:last-child { margin-bottom: 0; }
%1$s .cx-tracker-feedback__disclosure ul { margin: 0 0 8px; padding-left: 18px; list-style: disc; }
%1$s .cx-tracker-feedback__disclosure code { word-break: break-all; background: transparent; padding: 0; }
%1$s .cx-tracker-feedback__actions { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; margin: 0; }
%1$s .cx-tracker-feedback__dialog :focus { outline: 2px solid #2271b1; outline-offset: 1px; }
@media ( prefers-color-scheme: dark ) {
%1$s .cx-tracker-feedback__dialog { background: #1d2327; color: #f0f0f1; }
%1$s .cx-tracker-feedback__title { color: #f0f0f1; }
%1$s .cx-tracker-feedback__intro,
%1$s .cx-tracker-feedback__disclosure-summary,
%1$s .cx-tracker-feedback__hint { color: #c3c4c7; }
%1$s .cx-tracker-feedback__details-toggle { color: #72aee6; }
%1$s .cx-tracker-feedback__disclosure code { background: #2c3338; color: #f0f0f1; }
%1$s .cx-tracker-feedback__dialog fieldset,
%1$s .cx-tracker-feedback__dialog legend { color: #f0f0f1; border-color: #3c434a; }
%1$s .cx-tracker-feedback__reason { color: #f0f0f1; }
%1$s .cx-tracker-feedback__note label { color: #f0f0f1; }
%1$s .cx-tracker-feedback__dialog textarea { background: #2c3338; color: #f0f0f1; border-color: #3c434a; }
%1$s .cx-tracker-feedback__dialog textarea:focus { border-color: #72aee6; box-shadow: 0 0 0 1px #72aee6; }
%1$s .cx-tracker-feedback__dialog input[type=radio] { border-color: #3c434a; background: #2c3338; }
%1$s .cx-tracker-feedback__dialog input[type=radio]:checked::before { background-color: #72aee6; }
%1$s .cx-tracker-feedback__disclosure { background: #2c3338; }
}
';

		return sprintf( $css, $selector );
	}
}
