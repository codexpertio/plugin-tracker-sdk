<?php
/**
 * Tests for the deactivation-feedback modal.
 *
 * Grouped by the property each block proves, because the properties are the requirement here and
 * they are not equally obvious:
 *
 *   1. Deactivation is never blocked.
 *   2. The interception is scoped to this plugin's own row.
 *   3. Security: capability, nonce, and a reason allow-list that cannot be talked around.
 *   4. The payload carries what it should and -- the part that matters -- not what it should not.
 *   5. Free text is bounded, and never reaches the telemetry stream.
 *
 * @package Codexpert\PluginTracker\Test
 */

namespace Codexpert\PluginTracker\Test\Feedback;

use Brain\Monkey\Functions;
use Codexpert\PluginTracker\Config;
use Codexpert\PluginTracker\Consent\Gate;
use Codexpert\PluginTracker\Event;
use Codexpert\PluginTracker\Feedback\Deactivation;
use Codexpert\PluginTracker\Test\PluginTrackerTestCase;

/**
 * @covers \Codexpert\PluginTracker\Feedback\Deactivation
 */
class DeactivationTest extends PluginTrackerTestCase {

	/**
	 * A syntactically valid dashboard hash (Config::HASH_PATTERN: 32-64 lowercase hex).
	 */
	const HASH = 'a1b2c3d4a1b2c3d4a1b2c3d4a1b2c3d4';

	/**
	 * Stubs every test in this file needs, for the reason PluginTrackerTestCase documents at
	 * length: Brain Monkey patches a stubbed function PROCESS-WIDE, so once any test anywhere in
	 * the suite stubs home_url() or sanitize_textarea_field(), function_exists() starts returning
	 * true for them everywhere -- and Deactivation's function_exists() guards then call a function
	 * Brain Monkey has defined but not mocked, which throws MissingFunctionExpectations instead of
	 * falling through to the no-WordPress branch.
	 *
	 * Defaulted here rather than per-test so a test that does not care about the environment does
	 * not have to. Tests that DO care override these with their own Functions\when().
	 */
	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'home_url' )->justReturn( 'https://cx-feedback-test.example' );
		Functions\when( 'get_bloginfo' )->justReturn( '6.5.2' );
		Functions\when( 'get_locale' )->justReturn( 'en_US' );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'sanitize_textarea_field' )->alias( array( $this, 'fake_sanitize_textarea_field' ) );

		// Here rather than in the render/handle helpers, and for a reason that is easy to get wrong:
		// PHP cannot undefine a function, so once ANY test stubs wp_get_theme() the function exists
		// for the rest of the process -- which makes site_fields()'s function_exists() guard pass in
		// every later test, where Brain Monkey then throws "not defined nor mocked in this test".
		// A per-helper stub therefore breaks tests that never called the helper, depending on
		// execution order. Class-wide is the only stable answer.
		$this->stub_site_inventory();
	}

	/**
	 * A stand-in for sanitize_textarea_field(): strip tags and control characters, then trim.
	 *
	 * A method rather than a helper function in tests/php/utils/, because that directory is
	 * autoloaded as a Composer CLASSMAP -- which maps classes, not plain functions -- so a function
	 * placed there would never be loaded.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public function fake_sanitize_textarea_field( $value ) {
		$value = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $value );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- this method IS the stand-in for a WordPress sanitiser; calling wp_strip_all_tags() here would mean calling into the WordPress that this suite deliberately never loads.
		$value = strip_tags( $value );

		return trim( (string) preg_replace( '/[\\x00-\\x08\\x0B\\x0C\\x0E-\\x1F\\x7F]/', '', $value ) );
	}

	/**
	 * Base config args, plus the two fields Config now requires that the shared harness does not
	 * yet supply: `hash` and `file`.
	 *
	 * `file` points at a path that does not exist, deliberately. Config::looks_like_plugin_file()
	 * treats an unreadable path as "cannot check, do not fail construction", so no fixture file is
	 * needed, and Config::basename() derives the basename from the last two path segments -- which
	 * is what plugin_basename() would return for a real plugin at that path.
	 *
	 * @param array $overrides Fields to override.
	 * @return array
	 */
	protected function config_args( array $overrides = array() ) {
		return parent::config_args(
			array_merge(
				array(
					'hash' => self::HASH,
					'file' => '/wp-content/plugins/my-plugin/my-plugin.php',
				),
				$overrides
			)
		);
	}

	/**
	 * A Deactivation for a consumer whose author has enabled the project (consent gate 1) but whose
	 * site administrator has NOT opted into telemetry. That is the default on purpose: feedback must
	 * work in exactly that state, which is the decision the whole class docblock argues for.
	 *
	 * @param array $overrides Config overrides.
	 * @return array{0: Deactivation, 1: Config}
	 */
	private function feedback( array $overrides = array() ) {
		$config = new Config( $this->config_args( array_merge( array( 'enabled' => true ), $overrides ) ) );

		$this->assertTrue( $config->is_valid(), 'test fixture config must be valid: ' . implode( ' | ', $config->errors() ) );

		return array( new Deactivation( $config, new Gate( $config ) ), $config );
	}

	/**
	 * Everything render() reaches for. Kept in one place so a render test asserts on output rather
	 * than on which WordPress function it remembered to stub.
	 *
	 * @return void
	 */
	private function stub_render_environment() {
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'admin_url' )->alias(
			function ( $path = '' ) {
				return 'https://cx-feedback-test.example/wp-admin/' . $path;
			}
		);
		Functions\when( 'self_admin_url' )->alias(
			function ( $path = '' ) {
				return 'https://cx-feedback-test.example/wp-admin/' . $path;
			}
		);
		Functions\when( 'add_query_arg' )->alias(
			function ( $args, $url ) {
				return $url . '?' . http_build_query( $args );
			}
		);
		Functions\when( 'wp_nonce_url' )->alias(
			function ( $url, $action ) {
				return $url . '&_wpnonce=' . md5( (string) $action );
			}
		);
		Functions\when( 'wp_nonce_field' )->alias(
			function ( $action ) {
				echo '<input type="hidden" name="_wpnonce" value="' . md5( (string) $action ) . '">';
			}
		);

		// Real plugin_basename() semantics for a plugin in its own directory, which is also exactly
		// Config::basename()'s non-WordPress fallback.
		Functions\when( 'plugin_basename' )->alias(
			function ( $file ) {
				$parts = explode( '/', str_replace( '\\', '/', (string) $file ) );

				return implode( '/', array_slice( $parts, -2 ) );
			}
		);
	}

	/**
	 * A theme, a server and a couple of active plugins.
	 *
	 * Without this every schema 2 field resolves to '' or [], and a test asserting the disclosure
	 * lists what is sent would pass by listing nothing. Stubbed in both the render and the handle
	 * harnesses so neither can silently exercise an empty site.
	 *
	 * @return void
	 */
	private function stub_site_inventory() {
		$_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.41 (Ubuntu)';

		\PluginTracker_Test_Option_Store::update(
			'active_plugins',
			array( 'my-plugin/my-plugin.php', 'akismet/akismet.php' )
		);

		Functions\when( 'wp_get_theme' )->justReturn(
			new class() {
				public function get_stylesheet() {
					return 'twentytwentyfour';
				}

				public function get( $key ) {
					return 'Version' === $key ? '1.2' : '';
				}

				public function parent() {
					return false;
				}
			}
		);

		Functions\when( 'get_plugins' )->justReturn(
			array(
				'akismet/akismet.php'     => array( 'Version' => '5.3' ),
				'my-plugin/my-plugin.php' => array( 'Version' => '1.0.0' ),
			)
		);

		// Neutral default, for the same process-wide-definition reason as the rest of this method:
		// the multisite test below defines get_site_option(), which makes it exist for every later
		// test, and any of those that flip is_multisite() to true would then reach an unmocked
		// function. Tests that care override this.
		Functions\when( 'get_site_option' )->justReturn( array() );
	}

	/**
	 * Everything handle() reaches for, with the two request-terminating calls replaced by throwing
	 * stubs so the test process survives. Same substitution TrackerTest makes for
	 * handle_consent().
	 *
	 * @return void
	 */
	private function stub_handle_environment() {
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'check_admin_referer' )->justReturn( true );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'sanitize_key' )->alias(
			function ( $value ) {
				return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $value ) );
			}
		);
		Functions\when( 'wp_unslash' )->alias(
			function ( $value ) {
				return $value;
			}
		);
		Functions\when( 'self_admin_url' )->alias(
			function ( $path = '' ) {
				return 'https://cx-feedback-test.example/wp-admin/' . $path;
			}
		);
		Functions\when( 'add_query_arg' )->alias(
			function ( $args, $url ) {
				return $url . '?' . http_build_query( $args );
			}
		);
		Functions\when( 'wp_nonce_url' )->alias(
			function ( $url, $action ) {
				return $url . '&_wpnonce=' . md5( (string) $action );
			}
		);
		Functions\when( 'plugin_basename' )->alias(
			function ( $file ) {
				$parts = explode( '/', str_replace( '\\', '/', (string) $file ) );

				return implode( '/', array_slice( $parts, -2 ) );
			}
		);
	}

	/*
	 * =========================================================================================
	 * 1. DEACTIVATION IS NEVER BLOCKED
	 * =========================================================================================
	 */

	/**
	 * The no-JS path's guarantee, and the reason it holds.
	 *
	 * "Skip & Deactivate" is a plain anchor whose href is a real, nonced deactivate URL computed on
	 * the server. It is not a placeholder the JavaScript has to fill in, so it survives the JS being
	 * absent, blocked, or broken. The nonce action WordPress actually checks for this request is
	 * `deactivate-plugin_{basename}` (WP_Plugins_List_Table::single_row()), so this asserts on that
	 * exact action rather than on "a nonce is present".
	 */
	public function test_skip_and_deactivate_is_a_real_nonced_deactivate_url_not_a_placeholder() {
		$this->stub_render_environment();
		list( $feedback ) = $this->feedback();

		$url = $feedback->deactivate_url();

		$this->assertStringContainsString( 'plugins.php', $url );
		$this->assertStringContainsString( 'action=deactivate', $url );
		$this->assertStringContainsString( rawurlencode( 'my-plugin/my-plugin.php' ), $url );
		$this->assertStringContainsString(
			'_wpnonce=' . md5( 'deactivate-plugin_my-plugin/my-plugin.php' ),
			$url,
			'the nonce must be created for the action plugins.php actually verifies'
		);

		ob_start();
		$feedback->render();
		$html = ob_get_clean();

		// Compared through esc_url() -- the very function render() escapes the href with -- rather
		// than through a hand-rolled htmlspecialchars(), so the assertion tracks the renderer
		// instead of a guess about how it escapes.
		$this->assertStringContainsString(
			'href="' . esc_url( $url ) . '"',
			$html,
			'the skip control must carry that URL as a real href, so it works with no JS at all'
		);
		$this->assertStringContainsString( 'data-cx-skip="1"', $html );
	}

	/**
	 * The other half of progressive enhancement: the modal's form is a REAL form that posts to
	 * admin-post.php. If fetch() is unavailable the inline script does not intercept the submit, the
	 * native POST happens, and handle() redirects on to the deactivation.
	 *
	 * Asserted on the markup because that is what makes the fallback exist. A form with
	 * action="#" or no method would leave the no-fetch path dead.
	 */
	public function test_the_modal_form_natively_posts_to_admin_post_so_it_works_without_fetch() {
		$this->stub_render_environment();
		list( $feedback, $config ) = $this->feedback();

		ob_start();
		$feedback->render();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'method="post"', $html );
		$this->assertStringContainsString( 'admin-post.php', $html );
		$this->assertStringContainsString(
			'name="action" value="' . Deactivation::action_for( $config ) . '"',
			$html,
			'the native POST needs the admin-post action in the body, not just in the URL'
		);
	}

	/**
	 * The never-block property, proven on the one path a PHP test can reach.
	 *
	 * A transport failure must not change where the administrator ends up. wp_remote_post() is made
	 * to return a WP_Error, and handle() must still redirect to this plugin's deactivate URL.
	 */
	public function test_handle_still_redirects_to_the_deactivate_url_when_the_transmission_fails() {
		$this->stub_handle_environment();
		list( $feedback ) = $this->feedback();

		Functions\when( 'wp_remote_post' )->justReturn( array( 'fake' => 'response' ) );
		Functions\when( 'is_wp_error' )->justReturn( true );

		$redirected = $this->capture_redirect();

		$_POST['reason'] = 'broke_site';
		$_POST['note']   = 'Fatal on the checkout page.';

		try {
			$feedback->handle();
			$this->fail( 'handle() must always reach its redirect/exit tail' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'wp_safe_redirect called', $e->getMessage() );
		} finally {
			unset( $_POST['reason'], $_POST['note'] );
		}

		$this->assertStringContainsString( 'action=deactivate', $redirected->url );
		$this->assertStringContainsString( rawurlencode( 'my-plugin/my-plugin.php' ), $redirected->url );
	}

	/**
	 * Same property, harsher failure: the HTTP layer throws rather than returning an error. A
	 * Throwable from third-party code hooked into wp_remote_post() must not be the thing that leaves
	 * an administrator stranded on admin-post.php with the plugin still active.
	 */
	public function test_handle_still_redirects_when_the_transport_throws() {
		$this->stub_handle_environment();
		list( $feedback ) = $this->feedback();

		Functions\when( 'wp_remote_post' )->alias(
			function () {
				throw new \RuntimeException( 'a filter on this site exploded' );
			}
		);

		$redirected = $this->capture_redirect();

		$_POST['reason'] = 'broke_site';

		try {
			$feedback->handle();
			$this->fail( 'handle() must swallow a transport Throwable and still redirect' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'wp_safe_redirect called', $e->getMessage() );
		} finally {
			unset( $_POST['reason'] );
		}

		$this->assertStringContainsString( 'action=deactivate', $redirected->url );
	}

	/**
	 * The redirect target is derived, never accepted. A posted `redirect_to` (or any other posted
	 * URL) must be ignored, or this endpoint would be an open redirect that an attacker could aim
	 * anywhere while the administrator believed they were deactivating a plugin.
	 */
	public function test_the_redirect_target_is_derived_from_the_basename_not_taken_from_the_post_body() {
		$this->stub_handle_environment();
		list( $feedback ) = $this->feedback();

		Functions\when( 'wp_remote_post' )->justReturn( array( 'fake' => 'response' ) );
		Functions\when( 'is_wp_error' )->justReturn( true );

		$redirected = $this->capture_redirect();

		$_POST['reason']           = 'temporary';
		$_POST['redirect_to']      = 'https://evil.example/steal';
		$_POST['_wp_http_referer'] = 'https://evil.example/steal';

		try {
			$feedback->handle();
			$this->fail( 'handle() must reach its redirect' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'wp_safe_redirect called', $e->getMessage() );
		} finally {
			unset( $_POST['reason'], $_POST['redirect_to'], $_POST['_wp_http_referer'] );
		}

		$this->assertStringNotContainsString( 'evil.example', $redirected->url );
		$this->assertSame( $feedback->deactivate_url(), $redirected->url );
	}

	/*
	 * =========================================================================================
	 * 2. SCOPED TO THIS PLUGIN'S ROW ONLY
	 * =========================================================================================
	 */

	/**
	 * plugins.php lists every plugin on the site, and several may bundle this same SDK. The
	 * interception is keyed off Config::basename(), so the rendered modal must advertise ITS OWN
	 * basename and no other -- that attribute is the only thing the inline script matches links
	 * against.
	 *
	 * Two consumers are rendered into one page, as they would be on a real site, and each is
	 * checked for the presence of its own basename and the absence of the other's.
	 */
	public function test_each_consumer_advertises_only_its_own_plugin_basename() {
		$this->stub_render_environment();

		list( $mine )  = $this->feedback();
		list( $other ) = $this->feedback(
			array(
				'plugin' => 'other-plugin',
				'file'   => '/wp-content/plugins/other-plugin/other-plugin.php',
			)
		);

		ob_start();
		$mine->render();
		$mine_html = ob_get_clean();

		ob_start();
		$other->render();
		$other_html = ob_get_clean();

		// Asserted against the TARGETING surfaces specifically -- the basename attribute, the slug
		// attribute and the namespaced element id -- rather than as a blanket "the other slug
		// appears nowhere in the markup".
		//
		// That blanket form was a fair proxy until schema 2, when the disclosure began itemising
		// every active plugin: one consumer's modal now legitimately names another consumer's
		// basename, because it is listing the site's plugins and that is a plugin on the site. The
		// invariant this test is named for is unaffected -- what must not leak is the identity the
		// inline script matches Deactivate links against, and that is these three attributes.
		$this->assertStringContainsString( 'data-cx-basename="my-plugin/my-plugin.php"', $mine_html );
		$this->assertStringNotContainsString( 'data-cx-basename="other-plugin/other-plugin.php"', $mine_html );
		$this->assertStringNotContainsString( 'data-cx-tracker-feedback="other-plugin"', $mine_html );
		$this->assertStringNotContainsString( 'cx-tracker-feedback-other-plugin', $mine_html );

		$this->assertStringContainsString( 'data-cx-basename="other-plugin/other-plugin.php"', $other_html );
		$this->assertStringNotContainsString( 'data-cx-basename="my-plugin/my-plugin.php"', $other_html );
		$this->assertStringNotContainsString( 'data-cx-tracker-feedback="my-plugin"', $other_html );
		$this->assertStringNotContainsString( 'cx-tracker-feedback-my-plugin', $other_html );
	}

	/**
	 * The consequence of schema 2, stated as a test rather than left to be discovered.
	 *
	 * Two plugins from different vendors may each bundle this SDK. When one of them renders its
	 * deactivation modal, the itemised disclosure lists the site's active plugins -- which includes
	 * the other vendor's plugin. That is not a leak between copies; it is the feature, and the
	 * administrator reads the list before pressing the button. It is asserted so that anyone who
	 * finds it surprising finds this test and the reasoning with it.
	 */
	public function test_the_disclosure_lists_other_vendors_plugins_because_that_is_what_it_now_sends() {
		$this->stub_render_environment();

		list( $other ) = $this->feedback(
			array(
				'plugin' => 'other-plugin',
				'file'   => '/wp-content/plugins/other-plugin/other-plugin.php',
			)
		);

		ob_start();
		$other->render();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'akismet/akismet.php', $html );
		$this->assertStringContainsString( 'my-plugin/my-plugin.php', $html );
	}

	/**
	 * Every identifier that could collide between two bundled copies on one page is namespaced by
	 * the consumer's slug: the root element id, the script's root selector, the admin-post/ajax
	 * action, and the nonce action.
	 *
	 * The nonce action is the one that matters for security rather than tidiness -- a shared nonce
	 * action would let a nonce minted for one plugin's modal authorise a submission to another
	 * plugin's handler.
	 */
	public function test_two_consumers_get_independent_dom_ids_endpoints_and_nonce_actions() {
		$this->stub_render_environment();

		list( $mine, $mine_config )   = $this->feedback();
		list( $other, $other_config ) = $this->feedback(
			array(
				'plugin' => 'other-plugin',
				'file'   => '/wp-content/plugins/other-plugin/other-plugin.php',
			)
		);

		$this->assertNotSame( $mine->nonce_action(), $other->nonce_action() );
		$this->assertNotSame(
			Deactivation::action_for( $mine_config ),
			Deactivation::action_for( $other_config )
		);

		ob_start();
		$mine->render();
		$mine_html = ob_get_clean();

		ob_start();
		$other->render();
		$other_html = ob_get_clean();

		$this->assertStringContainsString( 'id="cx-tracker-feedback-my-plugin"', $mine_html );
		$this->assertStringContainsString( 'data-cx-tracker-feedback="my-plugin"', $mine_html );
		$this->assertStringContainsString( 'id="cx-tracker-feedback-other-plugin"', $other_html );
		$this->assertStringContainsString( 'data-cx-tracker-feedback="other-plugin"', $other_html );
	}

	/**
	 * The markup/behaviour contract version is emitted, and the script gates on it.
	 *
	 * This is what lets two bundled copies at DIFFERENT SDK versions coexist: a copy's script
	 * refuses to enhance a root whose contract it does not recognise, so an old copy never drives
	 * new markup. A root nobody enhances simply keeps its native Deactivate link, which is the
	 * safe outcome.
	 */
	public function test_the_markup_contract_version_is_emitted_and_gated_on() {
		$this->stub_render_environment();
		list( $feedback ) = $this->feedback();

		ob_start();
		$feedback->render();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'data-cx-contract="' . Deactivation::CONTRACT . '"', $html );
		$this->assertStringContainsString( "CONTRACT !== root.getAttribute( 'data-cx-contract' )", $html );
	}

	/**
	 * Nothing is rendered for a user who cannot deactivate plugins. They have no Deactivate link to
	 * intercept, so shipping them the dialog, the nonce and the disclosure would be pointless
	 * surface.
	 */
	public function test_render_emits_nothing_for_a_user_who_cannot_deactivate_plugins() {
		$this->stub_render_environment();
		Functions\when( 'current_user_can' )->justReturn( false );

		list( $feedback ) = $this->feedback();

		ob_start();
		$feedback->render();

		$this->assertSame( '', ob_get_clean() );
	}

	/**
	 * Consent gate 1 still applies. If the author never enabled the project there is nowhere to
	 * report to, so the SDK stays inert and the modal is not rendered at all.
	 */
	public function test_render_emits_nothing_when_the_author_has_not_enabled_the_project() {
		$this->stub_render_environment();

		list( $feedback ) = $this->feedback( array( 'enabled' => false ) );

		$this->assertFalse( $feedback->allowed() );

		ob_start();
		$feedback->render();

		$this->assertSame( '', ob_get_clean() );
	}

	/**
	 * The kill switch outranks the "they pressed the button" consent basis, unconditionally. A site
	 * owner or host must be able to stop ALL outbound transmission from this SDK, and CONSENT.md
	 * documents CX_TRACKER_DISABLE as absolute -- if feedback ignored it, the kill switch would be
	 * a lie.
	 *
	 * Isolated to its own process since the constant, once defined, persists for the rest of the
	 * PHP process.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_kill_switch_stops_feedback_even_though_the_admin_pressed_the_button() {
		define( 'CX_TRACKER_DISABLE', true );

		$this->stub_render_environment();
		list( $feedback ) = $this->feedback();

		$this->assertFalse( $feedback->allowed() );

		ob_start();
		$feedback->render();

		$this->assertSame( '', ob_get_clean() );
	}

	/**
	 * The cx_tracker_feedback filter is applied last, so it can only ever veto -- exactly like
	 * cx_tracker_consent in Gate::granted(). Returning true from it must not switch feedback on for
	 * a project the author never enabled.
	 */
	public function test_the_feedback_filter_can_veto_but_cannot_grant() {
		$this->stub_render_environment();

		Functions\when( 'apply_filters' )->alias(
			function ( $hook, $value ) {
				return 'cx_tracker_feedback' === $hook ? false : $value;
			}
		);

		list( $vetoed ) = $this->feedback();
		$this->assertFalse( $vetoed->allowed(), 'a filter returning false must turn feedback off' );

		Functions\when( 'apply_filters' )->alias(
			function ( $hook, $value ) {
				return 'cx_tracker_feedback' === $hook ? true : $value;
			}
		);

		list( $not_enabled ) = $this->feedback( array( 'enabled' => false ) );
		$this->assertFalse(
			$not_enabled->allowed(),
			'a filter returning true must NOT grant what consent gate 1 refused'
		);
	}

	/*
	 * =========================================================================================
	 * 3. SECURITY
	 * =========================================================================================
	 */

	/**
	 * A forged reason must never reach the payload.
	 *
	 * payload() is called DIRECTLY with hostile values, bypassing handle() entirely, because
	 * payload() is the only function that decides what goes on the wire and is therefore where the
	 * guarantee has to hold regardless of which path reached it. An unrecognised value is dropped,
	 * not repaired into something plausible, so the `reason` key is absent rather than present with
	 * a sanitised imitation.
	 *
	 * @dataProvider forged_reasons
	 * @param mixed $forged An untrusted candidate reason.
	 */
	public function test_a_forged_reason_never_reaches_the_payload( $forged ) {
		list( $feedback ) = $this->feedback();

		$payload = $feedback->payload( $forged, '' );

		$this->assertArrayNotHasKey(
			'reason',
			$payload,
			'an unrecognised reason must be dropped entirely, never sanitised into the payload'
		);
		$this->assertSame( '', Deactivation::normalize_reason( $forged ) );
	}

	/**
	 * Values an attacker, a buggy client, or a case-mangling proxy might submit.
	 *
	 * @return array<string,array<int,mixed>>
	 */
	public function forged_reasons() {
		return array(
			'not in the set'            => array( 'because_i_felt_like_it' ),
			'sql-ish'                   => array( "temporary'; DROP TABLE wp_options; --" ),
			'html'                      => array( '<script>alert(1)</script>' ),
			'wrong case'                => array( 'TEMPORARY' ),
			'valid reason with padding' => array( ' temporary ' ),
			'path traversal'            => array( '../../etc/passwd' ),
			'a known payload key'       => array( 'note' ),
			'empty string'              => array( '' ),
			'null'                      => array( null ),
			'integer'                   => array( 7 ),
			'array'                     => array( array( 'temporary' ) ),
			'boolean'                   => array( true ),
		);
	}

	/**
	 * The flip side: every member of the closed set IS accepted, so the allow-list is a filter and
	 * not an accidental blanket refusal. Driven off Event::REASONS itself, so adding a reason there
	 * cannot leave this test silently checking a stale list.
	 */
	public function test_every_reason_in_the_closed_set_is_accepted_and_transmitted() {
		list( $feedback ) = $this->feedback();

		$this->assertNotEmpty( Event::REASONS );

		foreach ( Event::REASONS as $reason ) {
			$payload = $feedback->payload( $reason, '' );

			$this->assertSame( $reason, $payload['reason'], $reason . ' is in Event::REASONS and must be transmitted' );
		}
	}

	/**
	 * The form is built from Event::REASONS, so the options offered and the values accepted are the
	 * same closed set by construction rather than by two lists agreeing.
	 */
	public function test_the_form_offers_exactly_the_closed_reason_set() {
		$this->stub_render_environment();
		list( $feedback ) = $this->feedback();

		ob_start();
		$feedback->render();
		$html = ob_get_clean();

		foreach ( Event::REASONS as $reason ) {
			$this->assertStringContainsString(
				'name="reason"',
				$html
			);
			$this->assertStringContainsString(
				'value="' . $reason . '"',
				$html,
				$reason . ' must be offered in the form'
			);
		}

		$this->assertSame(
			count( Event::REASONS ),
			substr_count( $html, 'name="reason"' ),
			'the form must offer no reason beyond the closed set'
		);
	}

	/**
	 * handle() is a state-changing, network-triggering admin endpoint. Without a capability check
	 * any authenticated user -- a subscriber, a contributor -- could make this site transmit its own
	 * address and arbitrary free text to a third party.
	 */
	public function test_handle_rejects_a_user_without_the_deactivate_plugins_capability() {
		$this->stub_handle_environment();
		list( $feedback ) = $this->feedback();

		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'wp_die' )->alias(
			function () {
				throw new \RuntimeException( 'wp_die called' );
			}
		);
		Functions\expect( 'wp_remote_post' )->never();

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'wp_die called' );

		$feedback->handle();
	}

	/**
	 * The capability checked is deactivate_plugins specifically, not manage_options.
	 *
	 * The two coincide on a single site and diverge on multisite, where a site administrator may
	 * hold manage_options without being allowed to deactivate anything. This endpoint accompanies a
	 * deactivation, so the right question is whether the user may deactivate -- asserted by
	 * granting manage_options and nothing else, and requiring the request to still be refused.
	 */
	public function test_handle_requires_deactivate_plugins_specifically_not_manage_options() {
		$this->stub_handle_environment();
		list( $feedback ) = $this->feedback();

		Functions\when( 'current_user_can' )->alias(
			function ( $capability ) {
				return 'manage_options' === $capability;
			}
		);
		Functions\when( 'wp_die' )->alias(
			function () {
				throw new \RuntimeException( 'wp_die called' );
			}
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'wp_die called' );

		$feedback->handle();
	}

	/**
	 * Without a nonce check this endpoint is a CSRF hole: any page could make a logged-in
	 * administrator's browser POST here, transmitting the site's address and attacker-chosen free
	 * text to the ingestion endpoint under that administrator's session.
	 *
	 * check_admin_referer() is stubbed to throw, standing in for its real fail-closed behaviour
	 * (wp_nonce_ays() followed by die()).
	 */
	public function test_handle_rejects_a_bad_or_missing_nonce() {
		$this->stub_handle_environment();
		list( $feedback ) = $this->feedback();

		Functions\when( 'check_admin_referer' )->alias(
			function () {
				throw new \RuntimeException( 'check_admin_referer failed' );
			}
		);
		Functions\expect( 'wp_remote_post' )->never();

		$_POST['reason'] = 'broke_site';

		try {
			$this->expectException( \RuntimeException::class );
			$this->expectExceptionMessage( 'check_admin_referer failed' );

			$feedback->handle();
		} finally {
			unset( $_POST['reason'] );
		}
	}

	/**
	 * The nonce is verified against an action scoped to this consumer's slug, and the same action
	 * is what the rendered form mints. A shared action would let a nonce from one plugin's modal
	 * authorise a submission to another plugin's handler.
	 */
	public function test_the_nonce_action_is_scoped_to_this_consumer_and_matches_the_form() {
		$this->stub_render_environment();
		list( $feedback ) = $this->feedback();

		$action = $feedback->nonce_action();

		$this->assertStringContainsString( 'my-plugin', $action );

		ob_start();
		$feedback->render();
		$html = ob_get_clean();

		$this->assertStringContainsString(
			'name="_wpnonce" value="' . md5( $action ) . '"',
			$html,
			'the form must mint a nonce for the very action handle() verifies'
		);
	}

	/**
	 * The capability check runs before the nonce check, and both run before any $_POST field is
	 * read. Proven by granting nothing and asserting that check_admin_referer() is never even
	 * reached.
	 */
	public function test_the_capability_check_runs_before_the_nonce_check() {
		$this->stub_handle_environment();
		list( $feedback ) = $this->feedback();

		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'wp_die' )->alias(
			function () {
				throw new \RuntimeException( 'wp_die called' );
			}
		);
		Functions\expect( 'check_admin_referer' )->never();

		$this->expectException( \RuntimeException::class );

		$feedback->handle();
	}

	/**
	 * Output escaping. Every value in the modal is attacker-or-author-influenced somewhere in its
	 * lifetime, and the filterable strings array is the easiest one to reach: a consumer's filter
	 * returning markup must not become an XSS vector in wp-admin.
	 */
	public function test_filtered_copy_is_escaped_on_output() {
		$this->stub_render_environment();

		Functions\when( 'apply_filters' )->alias(
			function ( $hook, $value ) {
				if ( 'cx_tracker_feedback_strings' === $hook ) {
					$value['title'] = '<script>alert(1)</script>';
				}

				return $value;
			}
		);

		list( $feedback ) = $this->feedback();

		ob_start();
		$feedback->render();
		$html = ob_get_clean();

		$this->assertStringNotContainsString( '<script>alert(1)</script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt;', $html );
	}

	/*
	 * =========================================================================================
	 * 4. THE PAYLOAD
	 * =========================================================================================
	 */

	/**
	 * THE JOIN-KEY RULE. The single most important assertion in this file.
	 *
	 * The feedback payload carries the site address. The telemetry payload carries the anonymous
	 * install id. If one feedback submission carried both, the backend could join them and
	 * de-anonymise the ENTIRE telemetry history for that install -- retroactively. The
	 * HMAC-under-a-local-salt design in Storage\Install would remain mathematically sound and
	 * completely pointless, because we would have handed over the answer.
	 *
	 * The install bearer token is excluded for the same reason: it is issued per install and
	 * resolves to one server-side, so sending it is sending the install id by another name.
	 */
	public function test_the_payload_never_carries_the_install_id_or_the_install_token() {
		list( $feedback, $config ) = $this->feedback();

		$this->seed_token( $config, 'ins_tok_this_must_not_travel' );

		$payload = $feedback->payload( 'broke_site', 'It broke.' );

		$this->assertArrayNotHasKey( 'install', $payload );
		$this->assertArrayNotHasKey( 'token', $payload );

		$encoded = json_encode( $payload );

		$this->assertStringNotContainsString( 'ins_tok_this_must_not_travel', $encoded );
		$this->assertStringNotContainsString( 'ins_', $encoded, 'no install-scoped identifier of any kind' );
	}

	/**
	 * The web server is reported as a bare product name, never as the raw header.
	 *
	 * `SERVER_SOFTWARE` reads `Apache/2.4.41 (Ubuntu)`. The version and the distribution in there
	 * are the "server hostname, OS" that docs/FEEDBACK.md refuses, so only the matched product name
	 * is transmitted -- the same reasoning that keeps `php` to major.minor.
	 *
	 * @dataProvider server_software_strings
	 *
	 * @param string $header   Raw SERVER_SOFTWARE value.
	 * @param string $expected What the payload should carry.
	 */
	public function test_the_server_is_reduced_to_a_product_name( $header, $expected ) {
		list( $feedback ) = $this->feedback();

		$_SERVER['SERVER_SOFTWARE'] = $header;

		$payload = $feedback->payload( 'other', '' );

		$this->assertSame( $expected, $payload['server'] );
	}

	/**
	 * @return array<string,array{0:string,1:string}>
	 */
	public function server_software_strings() {
		return array(
			'apache with distro'   => array( 'Apache/2.4.41 (Ubuntu)', 'apache' ),
			'nginx with version'   => array( 'nginx/1.18.0', 'nginx' ),
			// Both advertise a string naming the server they are built on, so the more specific
			// name has to win or every LiteSpeed site would be counted as Apache.
			'litespeed'            => array( 'LiteSpeed', 'litespeed' ),
			'openresty over nginx' => array( 'openresty/1.21.4.1', 'openresty' ),
			'iis'                  => array( 'Microsoft-IIS/10.0', 'iis' ),
			'unrecognised'         => array( 'SomeProprietaryServer/9', 'other' ),
			'absent'               => array( '', '' ),
		);
	}

	/**
	 * The active-plugin list is bounded, and its truncation is visible.
	 *
	 * `total_plugins` is the true count, so a list cut at PLUGINS_MAX cannot be mistaken for a
	 * complete list that happens to be short. Same reasoning as NOTE_MAX: the site decides the size
	 * of this field, so the SDK decides the ceiling.
	 */
	public function test_the_active_plugin_list_is_bounded_and_says_when_it_truncated() {
		list( $feedback ) = $this->feedback();

		$many = array();

		for ( $i = 0; $i < Deactivation::PLUGINS_MAX + 25; $i++ ) {
			$many[] = sprintf( 'plugin-%03d/plugin-%03d.php', $i, $i );
		}

		\PluginTracker_Test_Option_Store::update( 'active_plugins', $many );

		$payload = $feedback->payload( 'other', '' );

		$this->assertCount( Deactivation::PLUGINS_MAX, $payload['plugins'] );
		$this->assertSame( Deactivation::PLUGINS_MAX + 25, $payload['total_plugins'] );
	}

	/**
	 * Network-activated plugins are active on the site and absent from `active_plugins`, so reading
	 * only that option would report a multisite as running fewer plugins than it is.
	 */
	public function test_network_activated_plugins_are_included_on_multisite() {
		list( $feedback ) = $this->feedback();

		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'get_site_option' )->justReturn( array( 'network-thing/network-thing.php' => 1 ) );

		$payload = $feedback->payload( 'other', '' );

		$basenames = array_column( $payload['plugins'], 'plugin' );

		$this->assertContains( 'network-thing/network-thing.php', $basenames );
		$this->assertContains( 'akismet/akismet.php', $basenames, 'the per-site list is still included' );
	}

	/**
	 * Versions are attached where get_plugins() knows them, and the basename is reported either
	 * way. get_plugins() lives in an admin include that is not guaranteed to be loaded on the
	 * request that reaches here, and a list without versions beats no list.
	 */
	public function test_active_plugins_carry_versions_and_survive_an_unknown_one() {
		list( $feedback ) = $this->feedback();

		\PluginTracker_Test_Option_Store::update(
			'active_plugins',
			array( 'akismet/akismet.php', 'mystery/mystery.php' )
		);

		$payload = $feedback->payload( 'other', '' );

		$by_name = array_column( $payload['plugins'], 'version', 'plugin' );

		$this->assertSame( '5.3', $by_name['akismet/akismet.php'] );
		$this->assertSame( '', $by_name['mystery/mystery.php'] );
	}

	/**
	 * `collect` narrows what this copy transmits, and defaults to everything.
	 *
	 * It is a ceiling on the SDK side, not the final say: the dashboard's own selection is enforced
	 * at ingestion, because a value in the snippet is frozen the moment the author ships and cannot
	 * reach an install that already exists.
	 */
	public function test_collect_defaults_to_sending_every_optional_field() {
		list( $feedback ) = $this->feedback();

		$payload = $feedback->payload( 'other', '' );

		foreach ( array( 'wp', 'php', 'locale', 'multisite', 'server', 'theme', 'plugins' ) as $field ) {
			$this->assertArrayHasKey( $field, $payload, $field . ' must be sent by default' );
		}
	}

	/**
	 * A narrowed selection drops the fields it excludes -- including every key a multi-key field
	 * stands for, not just the one sharing its name.
	 */
	public function test_collect_drops_the_fields_it_excludes() {
		list( $feedback ) = $this->feedback( array( 'collect' => array( 'wp', 'php' ) ) );

		$payload = $feedback->payload( 'other', '' );

		$this->assertArrayHasKey( 'wp', $payload );
		$this->assertArrayHasKey( 'php', $payload );

		foreach ( array( 'locale', 'multisite', 'server', 'theme', 'theme_version', 'theme_parent', 'plugins', 'total_plugins' ) as $field ) {
			$this->assertArrayNotHasKey( $field, $payload, $field . ' must not be sent' );
		}
	}

	/**
	 * Identity survives any selection. `site` is what makes feedback a message from somebody, and
	 * `hash` is what routes it to the developer who should read it -- neither is selectable, so no
	 * configuration can produce a submission nobody can act on.
	 */
	public function test_collect_cannot_strip_the_fields_that_make_feedback_meaningful() {
		list( $feedback ) = $this->feedback( array( 'collect' => array() ) );

		$payload = $feedback->payload( 'broke_site', 'still says something' );

		$this->assertSame( 'https://cx-feedback-test.example', $payload['site'] );
		$this->assertSame( self::HASH, $payload['hash'] );
		$this->assertSame( 'broke_site', $payload['reason'] );
		$this->assertSame( 'still says something', $payload['note'] );
		$this->assertArrayHasKey( 'plugin', $payload );
		$this->assertArrayHasKey( 'plugin_version', $payload );
	}

	/**
	 * Nothing in the payload is derived from a user account. No email address, no username, no user
	 * id -- not the administrator's, not anyone's. A deactivation survey is the obvious place to
	 * reach for a reply-to address, and it is refused; see docs/FEEDBACK.md for why.
	 */
	public function test_the_payload_carries_no_user_identity() {
		list( $feedback ) = $this->feedback();

		// get_option() is deliberately NOT asserted on here: the shared harness aliases it to the
		// in-memory option store for every test, so an expectation on it would be fighting the
		// harness rather than testing this class. The two functions that could only be called to
		// obtain a user identity are asserted unreached instead.
		Functions\expect( 'wp_get_current_user' )->never();
		Functions\expect( 'get_userdata' )->never();

		$payload = $feedback->payload( 'other', 'no contact details here' );

		foreach ( array( 'email', 'admin_email', 'user', 'user_id', 'username', 'display_name' ) as $forbidden ) {
			$this->assertArrayNotHasKey( $forbidden, $payload );
		}
	}

	/**
	 * The payload carries the dashboard-issued hash and the site info the requirement asks for.
	 * The hash is public and non-secret (Config::HASH_PATTERN) and is what tells the dashboard which
	 * plugin record the feedback belongs to.
	 */
	public function test_the_payload_carries_the_hash_and_the_site_info() {
		list( $feedback ) = $this->feedback();

		Functions\when( 'home_url' )->justReturn( 'https://cx-feedback-test.example' );
		Functions\when( 'get_bloginfo' )->justReturn( '6.5.2' );
		Functions\when( 'get_locale' )->justReturn( 'de_DE' );
		Functions\when( 'is_multisite' )->justReturn( true );

		$payload = $feedback->payload( 'found_better', 'The other one has X.' );

		$this->assertSame( Deactivation::SCHEMA, $payload['schema'] );
		$this->assertSame( self::HASH, $payload['hash'] );
		$this->assertSame( 'pt_proj_abc123', $payload['project'] );
		$this->assertSame( 'https://cx-feedback-test.example', $payload['site'] );
		$this->assertSame( 'my-plugin', $payload['plugin'] );
		$this->assertSame( '1.0.0', $payload['plugin_version'] );
		$this->assertSame( '6.5.2', $payload['wp'] );
		$this->assertSame( 'de_DE', $payload['locale'] );
		$this->assertTrue( $payload['multisite'] );
		$this->assertSame( PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION, $payload['php'] );
		$this->assertSame( 'found_better', $payload['reason'] );
		$this->assertSame( 'The other one has X.', $payload['note'] );
	}

	/**
	 * Informed consent has to be informed. The modal must show what will be sent BEFORE the button
	 * exists to press, so every value the payload carries is asserted to appear in the rendered
	 * disclosure.
	 *
	 * Driven off site_fields(), which is the same method payload() builds from -- so the disclosure
	 * cannot drift away from the payload when a field is added. That shared source is the reason
	 * this test is possible at all, and it is why the method exists.
	 */
	public function test_the_modal_discloses_every_site_value_it_will_send() {
		$this->stub_render_environment();
		list( $feedback ) = $this->feedback();

		ob_start();
		$feedback->render();
		$html = ob_get_clean();

		// Driven off payload() -- what is actually SENT -- not site_fields().
		//
		// This test iterated site_fields() and was therefore weaker than both its own name and the class
		// docblock claimed: it could not see any field added to the payload outside that one method, and a
		// field had already slipped through exactly that gap. `project` was transmitted and never
		// disclosed, under a heading reading "Pressing the button below sends exactly this, and nothing
		// else", which docs/FEEDBACK.md names as a forbidden change.
		$payload = $feedback->payload( 'broke_site', 'a typed comment' );

		$this->assertNotEmpty( $payload );

		// Envelope plumbing rather than data about the site. Each is described in prose in the modal
		// instead of being itemised, so it is listed here explicitly -- an allow-list, so that a NEW
		// field is a test failure until somebody decides which side it falls on.
		$structural = array( 'schema', 'sdk', 'at', 'reason', 'note' );

		// `plugins` is a list of arrays, so the check descends into it rather than skipping it --
		// skipping would exempt the single field a reader is most likely to object to. Every
		// basename and every version has to appear in the rendered markup.
		//
		// `total_plugins` is exempt only because it is a count OF a disclosed list, not a fact the
		// list does not already show; the "and N more" line covers it when truncation applies.
		$structural[] = 'total_plugins';

		$flat = array();

		array_walk_recursive(
			$payload,
			function ( $value, $key ) use ( &$flat ) {
				$flat[] = array( $key, $value );
			}
		);

		foreach ( $flat as list( $key, $value ) ) {
			if ( in_array( $key, $structural, true ) ) {
				continue;
			}

			if ( is_bool( $value ) ) {
				// Rendered as a localised Yes/No rather than the literal "1"/"".
				continue;
			}

			if ( '' === (string) $value ) {
				// Nothing to look for. An absent theme or version is rendered as no line at all.
				continue;
			}

			$this->assertStringContainsString(
				htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ),
				$html,
				sprintf( 'the disclosure must show the %s value that will be transmitted', $key )
			);
		}

		$this->assertStringContainsString( self::HASH, $html, 'the disclosure must show the hash it sends' );
		$this->assertStringContainsString(
			'https://cx-feedback-test.example',
			$html,
			'the site address is the field most worth being explicit about, so it must be shown verbatim'
		);
	}

	/**
	 * Feedback goes to its own route, never to telemetry/events. Two payloads with two consent
	 * bases must not share a pipe, or the anonymous stream would start carrying a site address and
	 * free text.
	 */
	public function test_feedback_has_its_own_route_separate_from_the_telemetry_stream() {
		$this->assertSame( 'telemetry/feedback', Deactivation::ROUTE );
		$this->assertNotSame( 'telemetry/events', Deactivation::ROUTE );

		$this->stub_handle_environment();
		list( $feedback ) = $this->feedback();

		$requested       = new \stdClass();
		$requested->url  = '';
		$requested->body = '';

		Functions\when( 'wp_remote_post' )->alias(
			function ( $url, $args ) use ( $requested ) {
				$requested->url  = $url;
				$requested->body = isset( $args['body'] ) ? $args['body'] : '';

				return array( 'fake' => 'response' );
			}
		);
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );

		$this->capture_redirect();

		$_POST['reason'] = 'confusing';
		$_POST['note']   = 'I could not find the settings.';

		try {
			$feedback->handle();
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'wp_safe_redirect called', $e->getMessage() );
		} finally {
			unset( $_POST['reason'], $_POST['note'] );
		}

		$this->assertStringEndsWith( '/telemetry/feedback', $requested->url );
		$this->assertStringContainsString( 'I could not find the settings.', $requested->body );
	}

	/**
	 * A submission with neither a reason nor a comment is not transmitted at all. There is nothing
	 * to say, and sending the site's address to report that would be transmission without a
	 * purpose.
	 */
	public function test_an_empty_submission_transmits_nothing_but_still_deactivates() {
		$this->stub_handle_environment();
		list( $feedback ) = $this->feedback();

		Functions\expect( 'wp_remote_post' )->never();

		$redirected = $this->capture_redirect();

		$_POST['reason'] = '';
		$_POST['note']   = '   ';

		try {
			$feedback->handle();
			$this->fail( 'handle() must still redirect' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'wp_safe_redirect called', $e->getMessage() );
		} finally {
			unset( $_POST['reason'], $_POST['note'] );
		}

		$this->assertStringContainsString( 'action=deactivate', $redirected->url );
	}

	/**
	 * THE DELIBERATE DECISION, asserted. Feedback is not behind the telemetry opt-in.
	 *
	 * The site administrator here has never opted into telemetry, and then explicitly opted OUT --
	 * and a submission they are actively making, having read the disclosure, still goes through.
	 * The reasoning is in Deactivation::allowed() and docs/FEEDBACK.md: consent given at the moment
	 * of transmission, for that transmission, is a stronger basis than a general opt-in recorded
	 * earlier, and gating it would silence exactly the administrators most likely to have something
	 * worth hearing.
	 */
	public function test_feedback_does_not_require_the_telemetry_consent_gate() {
		$this->stub_handle_environment();
		list( $feedback, $config ) = $this->feedback();

		// Never asked.
		$this->assertFalse( ( new Gate( $config ) )->granted() );
		$this->assertTrue( $feedback->allowed() );

		// Explicitly declined.
		$this->seed_consent( $config, Gate::POLICY, false );
		$this->assertFalse( ( new Gate( $config ) )->granted() );
		$this->assertTrue(
			$feedback->allowed(),
			'an earlier telemetry opt-out must not veto a submission the admin is making right now'
		);

		$posted       = new \stdClass();
		$posted->body = '';

		Functions\when( 'wp_remote_post' )->alias(
			function ( $url, $args ) use ( $posted ) {
				$posted->body = isset( $args['body'] ) ? $args['body'] : '';

				return array( 'fake' => 'response' );
			}
		);
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );

		$this->capture_redirect();

		$_POST['reason'] = 'broke_site';

		try {
			$feedback->handle();
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'wp_safe_redirect called', $e->getMessage() );
		} finally {
			unset( $_POST['reason'] );
		}

		$this->assertStringContainsString( 'broke_site', $posted->body );
	}

	/*
	 * =========================================================================================
	 * 5. FREE TEXT: BOUNDED, AND NEVER ON THE TELEMETRY STREAM
	 * =========================================================================================
	 */

	/**
	 * The comment is bounded to Deactivation::NOTE_MAX characters. The bound is what makes the
	 * modal's advertised limit a fact rather than a hope, and it stops one submission becoming an
	 * unbounded upload.
	 */
	public function test_the_comment_is_bounded_to_note_max_characters() {
		list( $feedback ) = $this->feedback();

		$long = str_repeat( 'x', Deactivation::NOTE_MAX * 5 );

		$this->assertSame( Deactivation::NOTE_MAX, strlen( Deactivation::normalize_note( $long ) ) );

		$payload = $feedback->payload( 'other', $long );

		$this->assertSame( Deactivation::NOTE_MAX, strlen( $payload['note'] ) );
	}

	/**
	 * The bound is applied to the transmitted string through handle(), not only when
	 * normalize_note() is called directly. A maxlength attribute on the textarea is a client-side
	 * hint and nothing more -- a crafted POST ignores it entirely.
	 */
	public function test_the_comment_bound_survives_a_post_that_ignores_the_maxlength_attribute() {
		$this->stub_handle_environment();
		list( $feedback ) = $this->feedback();

		$posted       = new \stdClass();
		$posted->body = '';

		Functions\when( 'wp_remote_post' )->alias(
			function ( $url, $args ) use ( $posted ) {
				$posted->body = isset( $args['body'] ) ? $args['body'] : '';

				return array( 'fake' => 'response' );
			}
		);
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );

		$this->capture_redirect();

		$_POST['reason'] = 'other';
		$_POST['note']   = str_repeat( 'y', 50000 );

		try {
			$feedback->handle();
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'wp_safe_redirect called', $e->getMessage() );
		} finally {
			unset( $_POST['reason'], $_POST['note'] );
		}

		$decoded = json_decode( $posted->body, true );

		$this->assertIsArray( $decoded );
		$this->assertSame( Deactivation::NOTE_MAX, strlen( $decoded['note'] ) );
	}

	/**
	 * The bound is a CHARACTER bound, applied multibyte-safely.
	 *
	 * substr() on a byte boundary would split a multibyte character and put an invalid UTF-8
	 * sequence on the wire, which json_encode() then refuses to encode -- silently losing the whole
	 * submission rather than truncating it. Asserted by requiring the truncated value to still
	 * encode.
	 */
	public function test_the_comment_bound_is_multibyte_safe() {
		if ( ! function_exists( 'mb_substr' ) ) {
			$this->markTestSkipped( 'mbstring is unavailable, so the multibyte branch cannot be exercised here' );
		}

		$long = str_repeat( 'é', Deactivation::NOTE_MAX * 2 );
		$note = Deactivation::normalize_note( $long );

		$this->assertSame( Deactivation::NOTE_MAX, mb_strlen( $note, 'UTF-8' ) );
		$this->assertNotFalse( json_encode( $note ), 'a byte-truncated string would not encode' );
		$this->assertSame( $note, mb_convert_encoding( $note, 'UTF-8', 'UTF-8' ) );
	}

	/**
	 * Markup and control characters are stripped, and the result is trimmed. The comment is
	 * rendered nowhere by the SDK, but it is stored and displayed by the dashboard, and a bundled
	 * library should not be the thing that hands a stored-XSS payload to its own backend.
	 */
	public function test_the_comment_is_stripped_of_markup_and_control_characters() {
		$note = Deactivation::normalize_note( "  <script>alert(1)</script>Broke\x00 on\x08 save  " );

		$this->assertStringNotContainsString( '<script>', $note );
		$this->assertStringNotContainsString( "\x00", $note );
		$this->assertStringNotContainsString( "\x08", $note );
		$this->assertSame( trim( $note ), $note );
	}

	/**
	 * Non-string input cannot become a comment. A crafted POST can send note[]=a&note[]=b, which
	 * arrives as an array.
	 */
	public function test_a_non_string_comment_is_discarded() {
		list( $feedback ) = $this->feedback();

		$this->assertSame( '', Deactivation::normalize_note( array( 'a', 'b' ) ) );
		$this->assertSame( '', Deactivation::normalize_note( null ) );
		$this->assertSame( '', Deactivation::normalize_note( 42 ) );

		$this->assertArrayNotHasKey( 'note', $feedback->payload( 'other', array( 'a' ) ) );
	}

	/**
	 * Free text must never reach the telemetry `deactivation` event, and the guard that makes that
	 * impossible must not be weakened to accommodate this feature.
	 *
	 * docs/EVENTS.md drops `note` because a free-text box is the most likely place for a user to
	 * type PII and the SDK cannot inspect what they typed. Event::validate_props() enforces it. This
	 * asserts the enforcement is still there -- a regression test aimed squarely at a future change
	 * that "just adds note to the deactivation event".
	 */
	public function test_the_telemetry_deactivation_event_still_refuses_free_text() {
		$error = Event::validate_props( Event::DEACTIVATION, array( 'note' => 'anything at all' ) );

		$this->assertNotNull( $error, 'Event::validate_props() must still reject a note on a deactivation event' );
		$this->assertStringContainsString( 'note', $error );

		// Rejected by the closed-KEY-SET check, which runs before the per-key value checks -- so
		// `note` is refused for not being an allowed key at all, which is a broader refusal than the
		// note-specific message further down validate_props(). Asserted on the outcome rather than
		// on which branch produced it, so hardening that ordering later cannot break this test.
		$this->assertNotNull(
			Event::validate_props(
				Event::DEACTIVATION,
				array(
					'reason' => 'other',
					'note'   => 'x',
				)
			),
			'a note alongside a valid reason must be rejected too'
		);
		$this->assertNull(
			Event::validate_props( Event::DEACTIVATION, array( 'reason' => 'other' ) ),
			'a reason alone must still be accepted, or the telemetry hand-off could never work'
		);
	}

	/**
	 * The chosen reason is handed to the telemetry stream via an option, so
	 * Lifecycle::on_deactivate() can pick it up on the request that follows -- and the comment is
	 * NOT, because nothing destined for the anonymous stream may carry free text.
	 *
	 * Whatever is stored must be a shape Event::validate_props() would accept for a deactivation
	 * event, which is asserted here directly rather than assumed.
	 */
	public function test_only_the_reason_is_handed_to_the_telemetry_stream_never_the_comment() {
		$this->stub_handle_environment();
		list( $feedback, $config ) = $this->feedback();

		// Telemetry consent, because this write is the one thing here that IS consent-gated.
		$this->seed_consent( $config, Gate::POLICY, true );

		Functions\when( 'wp_remote_post' )->justReturn( array( 'fake' => 'response' ) );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );

		$this->capture_redirect();

		$_POST['reason'] = 'missing_feature';
		$_POST['note']   = 'It needs bulk export.';

		try {
			$feedback->handle();
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'wp_safe_redirect called', $e->getMessage() );
		} finally {
			unset( $_POST['reason'], $_POST['note'] );
		}

		$stored = $this->stored( $config, 'reason' );

		$this->assertIsArray( $stored );
		$this->assertSame( 'missing_feature', $stored['reason'] );
		$this->assertArrayNotHasKey( 'note', $stored );
		$this->assertStringNotContainsString( 'bulk export', json_encode( $stored ) );

		$this->assertNull(
			Event::validate_props( Event::DEACTIVATION, array( 'reason' => $stored['reason'] ) ),
			'what is stashed for the telemetry stream must be valid deactivation props'
		);
	}

	/**
	 * That hand-off IS consent-gated, and the asymmetry with the feedback submission is the point
	 * rather than an inconsistency.
	 *
	 * Feedback travels on its own consent (the administrator pressed the button). Anything destined
	 * for the anonymous telemetry stream is COLLECTED only with the telemetry opt-in, because
	 * CONSENT.md requires consent to precede collection and not merely transmission. So with no
	 * telemetry consent, the same submission still sends feedback and still stores nothing.
	 */
	public function test_the_telemetry_hand_off_is_consent_gated_even_though_feedback_is_not() {
		$this->stub_handle_environment();
		list( $feedback, $config ) = $this->feedback();

		$this->assertFalse( ( new Gate( $config ) )->granted() );

		$sent       = new \stdClass();
		$sent->body = '';

		Functions\when( 'wp_remote_post' )->alias(
			function ( $url, $args ) use ( $sent ) {
				$sent->body = isset( $args['body'] ) ? $args['body'] : '';

				return array( 'fake' => 'response' );
			}
		);
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );

		$this->capture_redirect();

		$_POST['reason'] = 'temporary';

		try {
			$feedback->handle();
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'wp_safe_redirect called', $e->getMessage() );
		} finally {
			unset( $_POST['reason'] );
		}

		$this->assertStringContainsString( 'temporary', $sent->body, 'feedback still goes' );
		$this->assertFalse(
			$this->stored( $config, 'reason' ),
			'nothing may be stored for the anonymous stream without the telemetry opt-in'
		);
	}

	/**
	 * The textarea advertises the same bound the server enforces. A limit shown to the user that
	 * does not match the one applied would make the modal dishonest.
	 */
	public function test_the_textarea_advertises_the_bound_the_server_enforces() {
		$this->stub_render_environment();
		list( $feedback ) = $this->feedback();

		ob_start();
		$feedback->render();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'maxlength="' . Deactivation::NOTE_MAX . '"', $html );
	}

	/*
	 * =========================================================================================
	 * HELPERS
	 * =========================================================================================
	 */

	/**
	 * Replace wp_safe_redirect() with a throwing stub that records where it was sent.
	 *
	 * The throw stands in for the exit() that follows it in finish(), which would otherwise
	 * terminate the test process -- the same substitution TrackerTest makes for handle_consent().
	 * Every assertion of interest concerns state set before that call.
	 *
	 * @return object A holder whose ->url is filled in when the redirect is attempted.
	 */
	private function capture_redirect() {
		$holder      = new \stdClass();
		$holder->url = '';

		Functions\when( 'wp_safe_redirect' )->alias(
			function ( $url ) use ( $holder ) {
				$holder->url = $url;

				throw new \RuntimeException( 'wp_safe_redirect called' );
			}
		);

		return $holder;
	}
}
