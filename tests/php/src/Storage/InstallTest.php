<?php
/**
 * Tests for the anonymous install identifier.
 *
 * @package Codexpert\PluginTracker\Test
 */

namespace Codexpert\PluginTracker\Test\Storage;

use Brain\Monkey\Functions;
use Codexpert\PluginTracker\Storage\Install;
use Codexpert\PluginTracker\Test\PluginTrackerTestCase;

/**
 * @covers \Codexpert\PluginTracker\Storage\Install
 */
class InstallTest extends PluginTrackerTestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'home_url' )->justReturn( 'https://my-super-secret-site.example.com' );
	}

	/**
	 * The id is stable across repeated calls for the same site (same salt, same site key).
	 */
	public function test_id_is_stable_across_repeated_calls() {
		$install = new Install( $this->make_config() );

		$this->assertSame( $install->id(), $install->id() );
	}

	/**
	 * Changing the salt (forget() deletes it, causing regeneration) must change the id, even
	 * though home_url() -- the other HMAC input -- stays the same.
	 */
	public function test_id_changes_when_the_salt_changes() {
		$install = new Install( $this->make_config() );

		$before = $install->id();
		$install->forget();
		$after = $install->id();

		$this->assertNotSame( $before, $after );
	}

	/**
	 * The id is non-empty and carries the documented "ins_" + 32 lowercase-hex-char shape.
	 */
	public function test_id_is_non_empty_and_prefixed() {
		$install = new Install( $this->make_config() );
		$id      = $install->id();

		$this->assertNotSame( '', $id );
		$this->assertStringStartsWith( 'ins_', $id );
		$this->assertRegExp( '/^ins_[0-9a-f]{32}$/', $id );
	}

	/**
	 * The single most important Install invariant: the raw home_url() must never appear anywhere
	 * in the id, in whole or in any recognisable substring, because that would make the "hash
	 * cannot be reversed to a site URL" claim in docs/EVENTS.md false.
	 */
	public function test_home_url_never_appears_in_the_id() {
		$install = new Install( $this->make_config() );
		$id      = $install->id();

		$this->assertStringNotContainsString( 'my-super-secret-site.example.com', $id );
		$this->assertStringNotContainsString( 'my-super-secret-site', $id );
		$this->assertStringNotContainsString( 'example.com', $id );
		$this->assertStringNotContainsString( 'https', $id );
	}

	/**
	 * Two consumers (different plugin slugs, hence different option keys and different salts) on
	 * the same site get different ids for the same home_url().
	 */
	public function test_ids_differ_between_two_consumers_on_the_same_site() {
		$install_a = new Install( $this->make_config( array( 'plugin' => 'plugin-a' ) ) );
		$install_b = new Install( $this->make_config( array( 'plugin' => 'plugin-b' ) ) );

		$this->assertNotSame( $install_a->id(), $install_b->id() );
	}
}
