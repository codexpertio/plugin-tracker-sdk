<?php
/**
 * Tests for validated Config construction.
 *
 * @package Codexpert\PluginTracker\Test
 */

namespace Codexpert\PluginTracker\Test;

use Codexpert\PluginTracker\Config;

/**
 * @covers \Codexpert\PluginTracker\Config
 */
class ConfigTest extends PluginTrackerTestCase {

	/**
	 * A well-formed project id, https endpoint, slug and version together validate.
	 */
	public function test_valid_args_produce_a_valid_config() {
		$config = $this->make_config();

		$this->assertTrue( $config->is_valid() );
		$this->assertSame( array(), $config->errors() );
	}

	/**
	 * A project id that looks like a pasted SECRET rather than the public, non-secret
	 * `pt_proj_<hex>` identifier must be rejected. This is spec 10.5's worst failure mode: an
	 * author accidentally shipping their secret inside their published plugin.
	 */
	public function test_rejects_a_secret_shaped_project_id() {
		/*
		 * The product's OWN secret prefix (Telemetry_Provisioning::SECRET_PREFIX), not a third
		 * party's. It was an `sk_live_...` string, which tested the same branch -- PROJECT_PATTERN
		 * rejects anything that is not `pt_proj_*`, so the prefix was arbitrary -- while being worse
		 * in two ways: it modelled a paste this product can never issue, and it matches a real
		 * payment provider's live-key format, so GitHub push protection blocks the commit for
		 * everyone who clones or forks this repository.
		 *
		 * `pt_sec_` is the value an author actually holds, which makes this the real worst case:
		 * pasting their author secret where the public project id belongs.
		 */
		$looks_like_a_secret = 'pt_sec_8f2a9c31e6b74d0f9a1c2e3f4a5b6c7d8e9f0a1b2c3d4e5f6a7b8c9d0e1f2a3b';

		$config = $this->make_config( array( 'project' => $looks_like_a_secret ) );

		$this->assertFalse( $config->is_valid() );
		$this->assertNotEmpty( $config->errors() );
	}

	/**
	 * A project id missing the pt_proj_ prefix entirely -- e.g. a bare random token -- is
	 * likewise rejected, not just secrets with a recognisable prefix.
	 */
	public function test_rejects_a_bare_random_project_id() {
		$config = $this->make_config( array( 'project' => '8f2a9c31e6b74d0f9a1c2e3f4a5b6c7d' ) );

		$this->assertFalse( $config->is_valid() );
	}

	/**
	 * A valid pt_proj_ id is accepted.
	 */
	public function test_accepts_a_well_formed_project_id() {
		$config = $this->make_config( array( 'project' => 'pt_proj_9f8e7d6c5b4a' ) );

		$this->assertTrue( $config->is_valid() );
	}

	/**
	 * http:// is rejected: telemetry over plaintext would expose the install token in transit.
	 */
	public function test_rejects_http_endpoint() {
		$config = $this->make_config( array( 'endpoint' => 'http://ingest.example.com/wp-json/plugin-tracker/v1' ) );

		$this->assertFalse( $config->is_valid() );
	}

	/**
	 * https:// is accepted.
	 */
	public function test_accepts_https_endpoint() {
		$config = $this->make_config( array( 'endpoint' => 'https://ingest.example.com/wp-json/plugin-tracker/v1' ) );

		$this->assertTrue( $config->is_valid() );
	}

	/**
	 * localhost is a documented development carve-out, even without https.
	 */
	public function test_accepts_http_localhost_endpoint() {
		$config = $this->make_config( array( 'endpoint' => 'http://localhost:8888/wp-json/plugin-tracker/v1' ) );

		$this->assertTrue( $config->is_valid() );
	}

	/**
	 * A .test TLD is the other documented development carve-out.
	 */
	public function test_accepts_http_dot_test_endpoint() {
		$config = $this->make_config( array( 'endpoint' => 'http://my-site.test/wp-json/plugin-tracker/v1' ) );

		$this->assertTrue( $config->is_valid() );
	}

	/**
	 * .internal is ICANN-reserved for private networks, and is how a container reaches the host
	 * gateway (host.docker.internal) to talk to a sibling wp-env site's published port.
	 */
	public function test_accepts_http_dot_internal_endpoint() {
		$config = $this->make_config( array( 'endpoint' => 'http://host.docker.internal:8888/wp-json/plugin-tracker/v1' ) );

		$this->assertTrue( $config->is_valid() );
	}

	/**
	 * The carve-out is anchored to the end of the host, so a public host that merely contains a
	 * reserved label is still refused.
	 */
	public function test_rejects_http_host_with_internal_label_not_at_end() {
		$config = $this->make_config( array( 'endpoint' => 'http://internal.example.com/wp-json/plugin-tracker/v1' ) );

		$this->assertFalse( $config->is_valid() );
	}

	/**
	 * Hosts that must be treated as local, over plain http.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function local_host_provider() {
		return array(
			'localhost'           => array( 'http://localhost:8888/v1' ),
			'localhost uppercase' => array( 'http://LOCALHOST:8888/v1' ),
			'ipv4 loopback'       => array( 'http://127.0.0.1:8888/v1' ),
			'ipv6 loopback'       => array( 'http://[::1]:8888/v1' ),
			'docker host gateway' => array( 'http://host.docker.internal:8888/v1' ),
			'trailing root dot'   => array( 'http://host.docker.internal.:8888/v1' ),
			'uppercase reserved'  => array( 'http://HOST.DOCKER.INTERNAL/v1' ),
			'dot test'            => array( 'http://my-site.test/v1' ),
			'docker bridge'       => array( 'http://172.17.0.1:8888/v1' ),
			'private class a'     => array( 'http://10.0.0.5/v1' ),
			'private class c'     => array( 'http://192.168.1.10/v1' ),
			'link local'          => array( 'http://169.254.1.1/v1' ),
		);
	}

	/**
	 * @dataProvider local_host_provider
	 *
	 * @param string $endpoint Endpoint to accept.
	 */
	public function test_accepts_http_for_local_hosts( $endpoint ) {
		$this->assertTrue( $this->make_config( array( 'endpoint' => $endpoint ) )->is_valid(), $endpoint );
	}

	/**
	 * Hosts that are publicly routable and must NOT get the plain-http carve-out.
	 *
	 * Every entry here passed the guard under the old "a hostname with no dot cannot be public" rule.
	 * They are the reason that rule is gone: each one would have carried an install token in cleartext
	 * across the internet.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function public_host_provider() {
		return array(
			'ipv6 literal, public'    => array( 'http://[2001:4860:4860::8888]/v1' ),
			'integer form of 8.8.8.8' => array( 'http://134744072/v1' ),
			'hex form of 8.8.8.8'     => array( 'http://0x08080808/v1' ),
			'public ipv4'             => array( 'http://8.8.8.8/v1' ),
			'single label public tld' => array( 'http://ai/v1' ),
			'compose service name'    => array( 'http://tests-wordpress/v1' ),
			'documentation range'     => array( 'http://192.0.2.10/v1' ),
			'plain public host'       => array( 'http://tracker.example.com/v1' ),
		);
	}

	/**
	 * @dataProvider public_host_provider
	 *
	 * @param string $endpoint Endpoint to refuse.
	 */
	public function test_refuses_http_for_publicly_routable_hosts( $endpoint ) {
		$this->assertFalse( $this->make_config( array( 'endpoint' => $endpoint ) )->is_valid(), $endpoint );
	}

	/**
	 * https is never affected by the local-host question.
	 */
	public function test_accepts_https_for_a_host_that_would_be_refused_over_http() {
		$config = $this->make_config( array( 'endpoint' => 'https://tests-wordpress/v1' ) );

		$this->assertTrue( $config->is_valid() );
	}

	/**
	 * A non-local http endpoint stays rejected even when it merely resembles a dev host (guards
	 * against an overly loose local-host regex).
	 */
	public function test_rejects_http_production_looking_host() {
		$config = $this->make_config( array( 'endpoint' => 'http://tracker.example.com/wp-json/plugin-tracker/v1' ) );

		$this->assertFalse( $config->is_valid() );
	}

	/*
	 * Deriving plugin data from the header.
	 *
	 * `plugin`, `name` and `version` are facts WordPress already holds in the header of the file the
	 * consumer must pass as `file`. Asking for them again made the snippet longer and gave three more
	 * ways to be wrong -- most damagingly `version`, which had to be bumped in two places or the SDK
	 * reported a version that was not the one running.
	 */

	/**
	 * An omitted slug is DERIVED from the plugin's directory, not rejected.
	 *
	 * The directory name is what WordPress.org uses as a slug, so it is the same answer a consumer would
	 * have typed. No file read is needed for this one -- the path alone carries it.
	 */
	public function test_derives_the_slug_from_the_plugin_directory() {
		$config = $this->make_config( array( 'plugin' => '' ) );

		$this->assertTrue( $config->is_valid(), implode( ' | ', $config->errors() ) );
		$this->assertSame( 'my-plugin', $config->plugin() );
	}

	/**
	 * A single-file plugin has no directory, so its filename stands in.
	 *
	 * Path-only: no file is read for a slug, because WordPress has no slug HEADER -- the directory
	 * name is the slug, which is the same thing WordPress.org keys on.
	 */
	public function test_derives_the_slug_from_a_single_file_plugin_name() {
		$config = $this->make_config(
			array(
				'plugin' => '',
				'file'   => '/wp-content/plugins/hello-dolly.php',
			)
		);

		$this->assertTrue( $config->is_valid(), implode( ' | ', $config->errors() ) );
		$this->assertSame( 'hello-dolly', $config->plugin() );
	}

	/**
	 * A directory whose name is not slug-shaped is normalised rather than passed through.
	 *
	 * A directory name is whatever the author or the installer chose, so it may carry capitals or
	 * underscores; the slug it produces still has to satisfy SLUG_PATTERN.
	 */
	public function test_normalises_a_slug_derived_from_an_awkward_directory() {
		$config = $this->make_config(
			array(
				'plugin' => '',
				'file'   => '/wp-content/plugins/Acme_Widgets/acme.php',
			)
		);

		$this->assertTrue( $config->is_valid(), implode( ' | ', $config->errors() ) );
		$this->assertSame( 'acme-widgets', $config->plugin() );
	}

	/**
	 * Name and version come out of the header when the file can actually be read.
	 */
	public function test_derives_name_and_version_from_the_plugin_header() {
		$config = $this->make_config(
			array(
				// Pinned, because this fixture deliberately does NOT live under a plugins directory --
				// slug derivation is covered by the path-only tests above.
				'plugin'  => 'acme-widgets',
				'name'    => '',
				'version' => '',
				'file'    => self::fixture( 'acme-widgets.php' ),
			)
		);

		$this->assertTrue( $config->is_valid(), implode( ' | ', $config->errors() ) );
		$this->assertSame( 'Acme Widgets', $config->name() );
		$this->assertSame( '2.4.1', $config->version() );
	}

	/**
	 * An explicit argument still wins.
	 *
	 * This is what keeps every already-shipped integration working: the SDK is bundled and frozen inside
	 * third-party plugins, all of which pass these three explicitly. Derivation is a fallback, never an
	 * override.
	 */
	public function test_explicit_arguments_beat_the_header() {
		$config = $this->make_config(
			array(
				'plugin'  => 'chosen-slug',
				'name'    => 'Chosen Name',
				'version' => '9.9.9',
				'file'    => self::fixture( 'acme-widgets.php' ),
			)
		);

		$this->assertSame( 'chosen-slug', $config->plugin() );
		$this->assertSame( 'Chosen Name', $config->name() );
		$this->assertSame( '9.9.9', $config->version() );
	}

	/**
	 * A file with no readable header still fails, and says what to do about it.
	 *
	 * Derivation must not turn a real misconfiguration into a silent success -- a consumer who passed a
	 * class file instead of their main plugin file needs to hear so.
	 */
	public function test_still_reports_a_version_it_cannot_derive() {
		$config = $this->make_config( array( 'version' => '' ) );

		$this->assertFalse( $config->is_valid() );
		$this->assertStringContainsString( 'Version:', implode( ' ', $config->errors() ) );
	}

	/**
	 * Absolute path to a fixture plugin file.
	 *
	 * @param string $name File name.
	 * @return string
	 */
	private static function fixture( $name ) {
		return dirname( __DIR__ ) . '/fixtures/' . $name;
	}

	/**
	 * A missing version is invalid.
	 */
	public function test_rejects_missing_version() {
		$config = $this->make_config( array( 'version' => '' ) );

		$this->assertFalse( $config->is_valid() );
	}

	/**
	 * The consent prompt is the one piece of UI a WordPress.org reviewer reads, so it must not show
	 * a kebab-case slug. An explicit name wins; absent one, the slug is prettified rather than shown
	 * raw, so a consumer who passes nothing still gets something presentable.
	 */
	public function test_name_prefers_an_explicit_value_and_otherwise_prettifies_the_slug() {
		$explicit = new Config(
			array(
				'project' => 'pt_proj_abc123',
				'plugin'  => 'plugin-tracker-sdk-example',
				'name'    => 'Plugin Tracker SDK Example',
				'version' => '1.0.0',
			)
		);
		$this->assertSame( 'Plugin Tracker SDK Example', $explicit->name() );

		$implicit = new Config(
			array(
				'project' => 'pt_proj_abc123',
				'plugin'  => 'plugin-tracker-sdk-example',
				'version' => '1.0.0',
			)
		);
		$this->assertSame( 'Plugin Tracker Sdk Example', $implicit->name() );
		$this->assertNotSame( $implicit->plugin(), $implicit->name(), 'the raw slug must never be the display name' );
	}

	/**
	 * name() is display only. Every identifier keys off plugin(), so changing the display name must
	 * never orphan a site's stored options or its scheduled flush.
	 */
	public function test_the_display_name_does_not_affect_any_identifier() {
		$args = array(
			'project' => 'pt_proj_abc123',
			'plugin'  => 'my-plugin',
			'version' => '1.0.0',
		);

		$without = new Config( $args );
		$with    = new Config( array_merge( $args, array( 'name' => 'Something Else Entirely' ) ) );

		$this->assertSame( $without->option( 'queue' ), $with->option( 'queue' ) );
		$this->assertSame( $without->plugin(), $with->plugin() );
	}

	/*
	|--------------------------------------------------------------------------
	| consent_after
	|--------------------------------------------------------------------------
	*/

	/**
	 * The default is the behaviour of every release shipped before the argument existed, so a snippet
	 * that has never heard of it keeps asking exactly when it always did.
	 */
	public function test_consent_after_defaults_to_asking_immediately() {
		$this->assertSame( 0, $this->make_config()->consent_after() );
	}

	public function test_consent_after_keeps_a_plain_number_of_seconds() {
		$config = $this->make_config( array( 'consent_after' => 172800 ) );

		$this->assertSame( 172800, $config->consent_after() );
	}

	/**
	 * The snippet is PHP an author can edit, so this arrives however they wrote it. A numeric string
	 * is the same intent as the number.
	 */
	public function test_consent_after_accepts_the_value_as_a_string() {
		$config = $this->make_config( array( 'consent_after' => '1209600' ) );

		$this->assertSame( 1209600, $config->consent_after() );
	}

	/**
	 * A cast is not a check: `(int) '30 days'` is 30, which read as seconds is half a minute -- an
	 * immediate prompt dressed as a delay. Unreadable values ask sooner, never later.
	 */
	public function test_an_unreadable_delay_asks_immediately() {
		foreach ( array( '30 days', 'soon', true, null, array(), -5 ) as $value ) {
			$config = $this->make_config( array( 'consent_after' => $value ) );

			$this->assertSame( 0, $config->consent_after(), 'an unreadable delay must not postpone the prompt' );
		}
	}

	/**
	 * A hand-edited snippet is the case this bounds. Past five years the argument stops meaning "ask
	 * later" and starts meaning "do not ask", which is a consent prompt that does not exist shipped
	 * under a plugin claiming to collect consent. `'enabled' => false` is the honest way to say that.
	 */
	public function test_an_unbounded_delay_is_clamped_rather_than_honoured() {
		$config = $this->make_config( array( 'consent_after' => 99999999999 ) );

		$this->assertSame( 157680000, $config->consent_after() );
	}
}
