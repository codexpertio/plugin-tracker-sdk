<?php
/**
 * Tests for the admin notices: the server-supplied notice's escaping, the truncation bound applied
 * when it is stored, and the three independent gates that guard the opt-in prompt.
 *
 * @package Codexpert\PluginTracker\Test
 */

namespace Codexpert\PluginTracker\Test\Consent;

use Brain\Monkey\Functions;
use Codexpert\PluginTracker\Consent\Gate;
use Codexpert\PluginTracker\Consent\Notice;
use Codexpert\PluginTracker\Test\PluginTrackerTestCase;

/**
 * @covers \Codexpert\PluginTracker\Consent\Notice
 */
class NoticeTest extends PluginTrackerTestCase {

	/**
	 * render_server_notice() prints a server-supplied string straight into wp-admin. It must be
	 * escaped, not trusted, because the string arrives over the network.
	 */
	public function test_render_server_notice_escapes_a_malicious_server_supplied_message() {
		$config  = $this->make_config( array( 'enabled' => false ) );
		$consent = new Gate( $config );
		$notice  = new Notice( $config, $consent );

		$this->stub_prompt_dependencies();
		Functions\when( 'current_user_can' )->justReturn( true );

		$payload = '<script>alert(1)</script><img src=x onerror=alert(2)>';
		Notice::remember_server_notice( $config, array( 'message' => $payload ) );

		ob_start();
		$notice->render();
		$output = ob_get_clean();

		$this->assertStringNotContainsString( '<script>', $output, 'a raw <script> tag must never reach the output' );
		$this->assertStringNotContainsString( '<img', $output, 'a raw <img> tag must never reach the output' );
		$this->assertStringContainsString(
			htmlspecialchars( $payload, ENT_QUOTES, 'UTF-8' ),
			$output,
			'the message must appear, but only in its escaped form'
		);
	}

	/**
	 * remember_server_notice() bounds what it stores to 500 characters. The bound matters even
	 * though the value is escaped at output -- an unbounded server-supplied string sitting in an
	 * option row is not something to accept from the network regardless.
	 */
	public function test_remember_server_notice_truncates_the_message_to_500_characters() {
		$config = $this->make_config();
		$long   = str_repeat( 'a', 5000 );

		Notice::remember_server_notice( $config, array( 'message' => $long ) );

		$stored = $this->stored( $config, 'notice' );

		$this->assertIsArray( $stored );
		$this->assertSame( 500, strlen( $stored['message'] ), 'a 5,000-char message must be stored truncated to 500 chars' );
		$this->assertSame( str_repeat( 'a', 500 ), $stored['message'] );
	}

	/**
	 * Stub everything render_prompt() needs to run to completion, so a mutation that lets it run
	 * produces real, inspectable HTML instead of a fatal from an unrelated missing stub.
	 *
	 * @return void
	 */
	private function stub_prompt_dependencies() {
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Functions\when( 'admin_url' )->justReturn( 'https://tracker-sdk-notice-test.example/wp-admin/admin-post.php' );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'submit_button' )->alias(
			function ( $text = '' ) {
				echo '<button>' . $text . '</button>';
			}
		);
	}

	/**
	 * Gate 1: the prompt must not render when the author has not enabled telemetry, independent of
	 * whether the admin has answered or the kill switch is set.
	 */
	public function test_render_suppresses_the_prompt_when_author_has_not_enabled() {
		$config  = $this->make_config( array( 'enabled' => false ) );
		$consent = new Gate( $config );
		$notice  = new Notice( $config, $consent );

		$this->stub_prompt_dependencies();
		Functions\when( 'current_user_can' )->justReturn( true );

		ob_start();
		$notice->render();
		$output = ob_get_clean();

		$this->assertStringNotContainsString( 'can send anonymous usage data', $output );
	}

	/**
	 * The "already answered" gate: an admin who has already answered for the current policy version
	 * (here: opted OUT, which still counts as answered) must not be asked again.
	 */
	public function test_render_suppresses_the_prompt_when_admin_already_answered() {
		$config = $this->make_config( array( 'enabled' => true ) );
		$this->seed_consent( $config, Gate::POLICY, false );
		$consent = new Gate( $config );
		$notice  = new Notice( $config, $consent );

		$this->stub_prompt_dependencies();
		Functions\when( 'current_user_can' )->justReturn( true );

		ob_start();
		$notice->render();
		$output = ob_get_clean();

		$this->assertStringNotContainsString( 'can send anonymous usage data', $output );
	}

	/**
	 * The CX_TRACKER_DISABLE kill switch suppresses the prompt even when both other gates would
	 * otherwise allow it. Isolated to its own process since the constant, once defined, persists
	 * for the rest of the PHP process.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_render_suppresses_the_prompt_when_the_kill_switch_is_set() {
		define( 'CX_TRACKER_DISABLE', true );

		$config  = $this->make_config( array( 'enabled' => true ) );
		$consent = new Gate( $config );
		$notice  = new Notice( $config, $consent );

		$this->stub_prompt_dependencies();
		Functions\when( 'current_user_can' )->justReturn( true );

		ob_start();
		$notice->render();
		$output = ob_get_clean();

		$this->assertStringNotContainsString( 'can send anonymous usage data', $output );
	}

	/**
	 * The positive case: with the author enabled, the admin not yet answered, and no kill switch,
	 * the prompt must actually render. Without this, a mutation that suppresses the prompt
	 * unconditionally would look identical to the three gated cases above.
	 */
	public function test_render_shows_the_prompt_when_no_gate_blocks_it() {
		$config  = $this->make_config( array( 'enabled' => true ) );
		$consent = new Gate( $config );
		$notice  = new Notice( $config, $consent );

		$this->stub_prompt_dependencies();
		Functions\when( 'current_user_can' )->justReturn( true );

		ob_start();
		$notice->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'can send anonymous usage data', $output );
	}

	/**
	 * The prompt must say this is a beta, and say what that means.
	 *
	 * Issue #40 is "consent (opt-in beta)" and PLANS.md §11.E calls the programme a beta with "supported
	 * beta events" -- so the event set, the retention window and the endpoints may still change. An
	 * administrator being asked to share data should be told that, and for a while the notice did not say
	 * it at all.
	 *
	 * Its own test rather than one more assertion in the string sweep, because this is a compliance
	 * statement rather than a default value: if somebody trims the copy, this should fail with a reason
	 * attached.
	 */
	public function test_the_prompt_discloses_that_the_programme_is_a_beta() {
		$config  = $this->make_config( array( 'enabled' => true ) );
		$consent = new Gate( $config );
		$notice  = new Notice( $config, $consent );

		$this->stub_prompt_dependencies();
		Functions\when( 'current_user_can' )->justReturn( true );

		ob_start();
		$notice->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'beta', $output, 'the prompt must say the programme is a beta' );
		$this->assertStringContainsString(
			'what is collected may change',
			$output,
			'and must say what being a beta means for the reader, not just carry the label'
		);
	}

	/**
	 * The policy version must move whenever that disclosure changes.
	 *
	 * Gate::POLICY is what makes a stale agreement stop counting. Adding the beta statement was a
	 * material wording change, so a site that agreed under policy 1 has not agreed to this text -- and
	 * must be asked again.
	 */
	public function test_a_consent_recorded_under_the_pre_beta_policy_no_longer_counts() {
		$config  = $this->make_config( array( 'enabled' => true ) );
		$consent = new Gate( $config );

		// Policy 1 is the wording that did not mention the beta.
		$this->seed_consent( $config, 1, true );

		$this->assertFalse(
			$consent->granted(),
			'an agreement to the pre-beta wording must not count as consent to the current wording'
		);
	}

	/**
	 * The prompt copy is filterable (cx_tracker_notice_strings) so a consumer can localise it in
	 * their own text domain -- see the docblock on Notice::strings(). With no filter registered
	 * (PluginTrackerTestCase::stub_filters() defaults apply_filters() to a plain pass-through, the
	 * same as a real site with nothing hooked to this filter), every default string must render.
	 */
	public function test_render_shows_every_default_string_when_no_filter_is_registered() {
		$config  = $this->make_config( array( 'enabled' => true ) );
		$consent = new Gate( $config );
		$notice  = new Notice( $config, $consent );

		$this->stub_prompt_dependencies();
		Functions\when( 'current_user_can' )->justReturn( true );

		ob_start();
		$notice->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'can send anonymous usage data', $output, 'default intro string' );
		$this->assertStringContainsString( 'anonymous install ID', $output, 'default sends string' );
		$this->assertStringContainsString( 'It never sends your site address', $output, 'default never string' );
		$this->assertStringContainsString( 'Nothing is sent unless you agree', $output, 'default optional string' );
		$this->assertStringContainsString( 'This is a beta programme', $output, 'default beta string' );
		$this->assertStringContainsString( '<button>Allow</button>', $output, 'default allow button label' );
		$this->assertStringContainsString( '<button>No thanks</button>', $output, 'default decline button label' );
	}

	/**
	 * A consumer's filter that overrides a single key (here: 'allow') must change only that string
	 * -- Notice::strings() merges the filtered array OVER the defaults precisely so a partial
	 * override cannot blank out, or otherwise affect, the keys it does not mention.
	 */
	public function test_filter_overriding_one_key_changes_only_that_string() {
		$config  = $this->make_config( array( 'enabled' => true ) );
		$consent = new Gate( $config );
		$notice  = new Notice( $config, $consent );

		$this->stub_prompt_dependencies();
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'apply_filters' )->alias(
			function ( $hook, $value ) {
				if ( 'cx_tracker_notice_strings' === $hook ) {
					$value['allow'] = 'Sure, go ahead';
				}
				return $value;
			}
		);

		ob_start();
		$notice->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( '<button>Sure, go ahead</button>', $output, 'the overridden key must use the filtered string' );
		$this->assertStringContainsString( '<button>No thanks</button>', $output, 'a key the filter did not mention must keep its default' );
		$this->assertStringContainsString( 'can send anonymous usage data', $output, 'the intro string is untouched by a filter that only overrides allow' );
	}

	/**
	 * A filter that misbehaves and returns something other than an array (e.g. a consumer's
	 * callback has a bug, or returns false/null by mistake) must be ignored entirely, falling back
	 * to the defaults -- rather than fataling on array_merge() or rendering nothing.
	 */
	public function test_filter_returning_a_non_array_is_ignored_and_defaults_still_render() {
		$config  = $this->make_config( array( 'enabled' => true ) );
		$consent = new Gate( $config );
		$notice  = new Notice( $config, $consent );

		$this->stub_prompt_dependencies();
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'apply_filters' )->alias(
			function ( $hook, $value ) {
				if ( 'cx_tracker_notice_strings' === $hook ) {
					return 'not-an-array';
				}
				return $value;
			}
		);

		ob_start();
		$notice->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( '<button>Allow</button>', $output, 'a non-array filter return must fall back to the default allow string' );
		$this->assertStringContainsString( '<button>No thanks</button>', $output, 'a non-array filter return must fall back to the default decline string' );
		$this->assertStringContainsString( 'can send anonymous usage data', $output, 'a non-array filter return must fall back to the default intro string' );
	}

	/**
	 * The important case: cx_tracker_notice_strings hands a THIRD PARTY's callback direct control
	 * over text that Notice::render_prompt() echoes into wp-admin. A malicious (or merely buggy)
	 * filter returning markup must not become an XSS vector -- the SDK's own esc_html() call around
	 * $text['intro'] in render_prompt() must still escape it, exactly as it already does for the
	 * server-supplied notice message (see the analogous
	 * test_render_server_notice_escapes_a_malicious_server_supplied_message() above).
	 */
	public function test_filter_returning_a_malicious_string_is_still_escaped_on_output() {
		$config  = $this->make_config( array( 'enabled' => true ) );
		$consent = new Gate( $config );
		$notice  = new Notice( $config, $consent );

		$this->stub_prompt_dependencies();
		Functions\when( 'current_user_can' )->justReturn( true );

		$payload = '<script>alert(1)</script><img src=x onerror=alert(2)>';
		Functions\when( 'apply_filters' )->alias(
			function ( $hook, $value ) use ( $payload ) {
				if ( 'cx_tracker_notice_strings' === $hook ) {
					$value['intro'] = $payload;
				}
				return $value;
			}
		);

		ob_start();
		$notice->render();
		$output = ob_get_clean();

		$this->assertStringNotContainsString( '<script>', $output, 'a raw <script> tag from a filter must never reach the output' );
		$this->assertStringNotContainsString( '<img', $output, 'a raw <img> tag from a filter must never reach the output' );
		$this->assertStringContainsString(
			htmlspecialchars( $payload, ENT_QUOTES, 'UTF-8' ),
			$output,
			'the filtered string must still render, but only in its escaped form'
		);
	}

	/*
	|--------------------------------------------------------------------------
	| Gate 4: the author's delay
	|--------------------------------------------------------------------------
	|
	| The weakest of the gates on purpose -- it postpones the question, it never withdraws it. These
	| are mostly about the ways "postpone" turns into "never", each of which ships a plugin that says
	| consent is collected and never asks for it.
	*/

	/**
	 * @param int      $wait      Seconds the author configured.
	 * @param int|null $activated Activation stamp to seed, or null to leave the site unstamped.
	 * @return string The rendered notice output.
	 */
	private function render_with_delay( $wait, $activated = null ) {
		$config = $this->make_config(
			array(
				'enabled'       => true,
				'consent_after' => $wait,
			)
		);

		if ( null !== $activated ) {
			\PluginTracker_Test_Option_Store::update( $config->option( 'activated' ), $activated );
		}

		$notice = new Notice( $config, new Gate( $config ) );

		$this->stub_prompt_dependencies();
		Functions\when( 'current_user_can' )->justReturn( true );

		ob_start();
		$notice->render();

		return ob_get_clean();
	}

	public function test_the_prompt_waits_while_the_authors_delay_is_still_running() {
		$output = $this->render_with_delay( 7 * 86400, time() - ( 2 * 86400 ) );

		$this->assertStringNotContainsString( 'can send anonymous usage data', $output );
	}

	public function test_the_prompt_appears_once_the_delay_has_elapsed() {
		$output = $this->render_with_delay( 7 * 86400, time() - ( 8 * 86400 ) );

		$this->assertStringContainsString( 'can send anonymous usage data', $output );
	}

	/**
	 * The boundary belongs to the admin, not to the delay: on the day it expires the question is due.
	 */
	public function test_the_prompt_appears_the_moment_the_delay_expires() {
		$output = $this->render_with_delay( 7 * 86400, time() - ( 7 * 86400 ) );

		$this->assertStringContainsString( 'can send anonymous usage data', $output );
	}

	/**
	 * A plugin that was already active when this SDK arrived has no activation to count from. Reading
	 * that as "not due yet" would leave every existing install of an established plugin silently
	 * unasked forever -- which is most of the installs there are.
	 */
	public function test_a_site_with_no_activation_stamp_is_asked_now_rather_than_never() {
		$output = $this->render_with_delay( 30 * 86400 );

		$this->assertStringContainsString( 'can send anonymous usage data', $output );
	}

	/**
	 * A restored backup, a corrected timezone or a host's NTP catching up can put the stamp in the
	 * future. That must not become a delay nobody can wait out.
	 */
	public function test_a_stamp_in_the_future_does_not_postpone_the_prompt_indefinitely() {
		$output = $this->render_with_delay( 7 * 86400, time() + ( 400 * 86400 ) );

		$this->assertStringContainsString( 'can send anonymous usage data', $output );
	}

	/**
	 * Sub-day delays are the reason this is seconds rather than days. "6 hours" is a real answer for a
	 * plugin somebody configures in one sitting, and a day-granular field could not express it.
	 */
	public function test_a_delay_shorter_than_a_day_is_honoured() {
		$this->assertStringNotContainsString(
			'can send anonymous usage data',
			$this->render_with_delay( 6 * 3600, time() - 3600 ),
			'an hour into a six-hour delay, the prompt must still be waiting'
		);

		$this->assertStringContainsString(
			'can send anonymous usage data',
			$this->render_with_delay( 6 * 3600, time() - ( 7 * 3600 ) )
		);
	}

	/**
	 * Zero is what a snippet generated before the floor existed still carries. Config raises it to
	 * CONSENT_AFTER_MIN, so the prompt waits one second rather than landing on the page load that
	 * activated the plugin.
	 */
	public function test_a_zero_delay_waits_the_minimum_rather_than_asking_at_once() {
		$this->assertStringNotContainsString(
			'can send anonymous usage data',
			$this->render_with_delay( 0, time() ),
			'zero must not mean "ask on this very page load"'
		);

		$this->assertStringContainsString(
			'can send anonymous usage data',
			$this->render_with_delay( 0, time() - 5 )
		);
	}

	/*
	|--------------------------------------------------------------------------
	| The server-supplied notice
	|--------------------------------------------------------------------------
	|
	| It is the one string this SDK renders that neither the consumer nor the site administrator
	| wrote, and until recently it was also the one nothing could take back.
	*/

	/**
	 * @param array $notice    Payload as an ingestion response would carry it.
	 * @param array $overrides Config overrides.
	 * @return array{0: \Codexpert\PluginTracker\Config, 1: Gate, 2: Notice}
	 */
	private function make_server_notice( array $notice, array $overrides = array() ) {
		$config  = $this->make_config( $overrides );
		$consent = new Gate( $config );

		$this->stub_prompt_dependencies();
		Functions\when( 'current_user_can' )->justReturn( true );

		Notice::remember_server_notice( $config, $notice );

		return array( $config, $consent, new Notice( $config, $consent ) );
	}

	/**
	 * @param Notice $notice Notice.
	 * @return string
	 */
	private function render( Notice $notice ) {
		ob_start();
		$notice->render();

		return ob_get_clean();
	}

	/**
	 * A response that does not say when the message stops being true used to store `until => 0`,
	 * which `! empty()` read as "no expiry" -- so the message outlived the release it warned about
	 * and every install kept showing it with nothing able to retract it.
	 */
	public function test_a_notice_with_no_expiry_is_given_one() {
		list( $config, , $notice ) = $this->make_server_notice( array( 'message' => 'DEPRECATION' ) );

		$stored = $this->stored( $config, 'notice' );

		$this->assertGreaterThan( time(), $stored['until'], 'an absent expiry must become a real one' );
		$this->assertLessThanOrEqual( time() + Notice::NOTICE_TTL, $stored['until'] );
		$this->assertStringContainsString( 'DEPRECATION', $this->render( $notice ) );
	}

	/**
	 * `(int) '2030-01-01'` is 2030, a 1970 timestamp -- so a human-readable date used to expire the
	 * notice on its first render rather than in 2030. A cast is not a check.
	 */
	public function test_an_unreadable_expiry_does_not_silently_become_1970() {
		list( $config, , $notice ) = $this->make_server_notice(
			array(
				'message' => 'ENDS SOMEDAY',
				'until'   => '2030-01-01',
			)
		);

		$this->assertGreaterThan( time(), $this->stored( $config, 'notice' )['until'] );
		$this->assertStringContainsString( 'ENDS SOMEDAY', $this->render( $notice ) );
	}

	/**
	 * A timestamp the server actually wrote is honoured as written, including one already past. That
	 * is the server saying "do not show this", and it is not ours to extend.
	 */
	public function test_an_expiry_in_the_past_drops_the_notice() {
		list( $config, , $notice ) = $this->make_server_notice(
			array(
				'message' => 'STALE',
				'until'   => time() - 60,
			)
		);

		$this->assertStringNotContainsString( 'STALE', $this->render( $notice ) );
		$this->assertFalse( $this->stored( $config, 'notice' ), 'an expired notice is deleted, not merely hidden' );
	}

	/**
	 * A row written before notices carried an expiry must be backfilled rather than deleted --
	 * comparing `time()` against a stored 0 would wipe it on the first admin load after an upgrade.
	 */
	public function test_a_legacy_row_without_an_expiry_is_backfilled() {
		$config = $this->make_config();

		$this->stub_prompt_dependencies();
		Functions\when( 'current_user_can' )->justReturn( true );

		\PluginTracker_Test_Option_Store::update(
			$config->option( 'notice' ),
			array(
				'message' => 'FROM AN OLDER SDK',
				'level'   => 'warning',
				'until'   => 0,
			)
		);

		$notice = new Notice( $config, new Gate( $config ) );

		$this->assertStringContainsString( 'FROM AN OLDER SDK', $this->render( $notice ) );
		$this->assertGreaterThan( time(), $this->stored( $config, 'notice' )['until'] );
	}

	/**
	 * Dismissal is keyed to the message, so silencing one does not swallow whatever the server says
	 * next.
	 */
	public function test_dismissal_hides_one_message_and_not_the_next() {
		list( $config, , $notice ) = $this->make_server_notice( array( 'message' => 'FIRST' ) );

		Notice::dismiss( $config );

		$this->assertStringNotContainsString( 'FIRST', $this->render( $notice ) );

		Notice::remember_server_notice( $config, array( 'message' => 'SECOND' ) );

		$this->assertStringContainsString( 'SECOND', $this->render( $notice ) );
	}

	/**
	 * The kill switch is a site owner's "stop, entirely". It used to be checked AFTER the server
	 * notice had already printed.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_kill_switch_silences_the_server_notice_too() {
		define( 'CX_TRACKER_DISABLE', true );

		list( , , $notice ) = $this->make_server_notice( array( 'message' => 'STILL SHOUTING' ) );

		$this->assertStringNotContainsString( 'STILL SHOUTING', $this->render( $notice ) );
	}

	/**
	 * An opt-out takes the whole conversation with it. A declining site never calls again, so a
	 * message left behind is one nothing can ever retract.
	 */
	public function test_an_opt_out_deletes_the_server_notice() {
		list( $config, $consent, $notice ) = $this->make_server_notice(
			array( 'message' => 'SERVER SAYS HELLO' ),
			array( 'enabled' => true )
		);

		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_unschedule_event' )->justReturn( true );
		Functions\when( 'wp_clear_scheduled_hook' )->justReturn( true );

		$consent->opt_out();

		$this->assertFalse( $this->stored( $config, 'notice' ) );
		$this->assertStringNotContainsString( 'SERVER SAYS HELLO', $this->render( $notice ) );
	}

	/**
	 * The filter is the only way to keep the SDK and move its UI. Without it a consumer rendering
	 * consent on their own settings screen had CX_TRACKER_DISABLE, which also stops telemetry.
	 */
	public function test_the_show_filter_can_suppress_each_notice_independently() {
		list( , , $notice ) = $this->make_server_notice(
			array( 'message' => 'SERVER SAYS HELLO' ),
			array( 'enabled' => true )
		);

		Functions\when( 'apply_filters' )->alias(
			function ( $hook, $value, $which = '' ) {
				return 'cx_tracker_show_notice' === $hook ? 'server' !== $which : $value;
			}
		);

		$output = $this->render( $notice );

		$this->assertStringNotContainsString( 'SERVER SAYS HELLO', $output, 'the server notice is suppressed' );
		$this->assertStringContainsString( 'can send anonymous usage data', $output, 'the prompt is not' );
	}

	/**
	 * Network admin gets the server notice and NOT the prompt: consent is stored per blog, so there
	 * is no per-site answer for a network screen to collect.
	 */
	public function test_network_admin_gets_the_server_notice_but_not_the_prompt() {
		list( , , $notice ) = $this->make_server_notice(
			array( 'message' => 'SERVER SAYS HELLO' ),
			array( 'enabled' => true )
		);

		ob_start();
		$notice->render_network();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'SERVER SAYS HELLO', $output );
		$this->assertStringNotContainsString( 'can send anonymous usage data', $output );
	}
}
