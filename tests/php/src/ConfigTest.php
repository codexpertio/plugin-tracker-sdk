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
			'localhost'            => array( 'http://localhost:8888/v1' ),
			'localhost uppercase'  => array( 'http://LOCALHOST:8888/v1' ),
			'ipv4 loopback'        => array( 'http://127.0.0.1:8888/v1' ),
			'ipv6 loopback'        => array( 'http://[::1]:8888/v1' ),
			'docker host gateway'  => array( 'http://host.docker.internal:8888/v1' ),
			'trailing root dot'    => array( 'http://host.docker.internal.:8888/v1' ),
			'uppercase reserved'   => array( 'http://HOST.DOCKER.INTERNAL/v1' ),
			'dot test'             => array( 'http://my-site.test/v1' ),
			'docker bridge'        => array( 'http://172.17.0.1:8888/v1' ),
			'private class a'      => array( 'http://10.0.0.5/v1' ),
			'private class c'      => array( 'http://192.168.1.10/v1' ),
			'link local'           => array( 'http://169.254.1.1/v1' ),
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
			'ipv6 literal, public'   => array( 'http://[2001:4860:4860::8888]/v1' ),
			'integer form of 8.8.8.8' => array( 'http://134744072/v1' ),
			'hex form of 8.8.8.8'    => array( 'http://0x08080808/v1' ),
			'public ipv4'            => array( 'http://8.8.8.8/v1' ),
			'single label public tld' => array( 'http://ai/v1' ),
			'compose service name'   => array( 'http://tests-wordpress/v1' ),
			'documentation range'    => array( 'http://192.0.2.10/v1' ),
			'plain public host'      => array( 'http://tracker.example.com/v1' ),
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

	/**
	 * A missing plugin slug is invalid.
	 */
	public function test_rejects_missing_plugin_slug() {
		$config = $this->make_config( array( 'plugin' => '' ) );

		$this->assertFalse( $config->is_valid() );
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
}
