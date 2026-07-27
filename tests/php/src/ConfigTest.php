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
}
