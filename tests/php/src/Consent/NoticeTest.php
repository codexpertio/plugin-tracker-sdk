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

		Functions\stubEscapeFunctions();
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
}
