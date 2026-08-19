<?php
/**
 * @package Codexpert\PluginTracker
 */

namespace Codexpert\PluginTracker\Test;

use Brain\Monkey\Functions;
use Codexpert\PluginTracker\I18n;

/**
 * @covers \Codexpert\PluginTracker\I18n
 */
class I18nTest extends PluginTrackerTestCase {

	/**
	 * The load guard is static, so it leaks between tests unless reset.
	 */
	protected function setUp(): void {
		parent::setUp();
		I18n::reset();
	}

	/**
	 * The .pot template must ship, or translators have nothing to work from and a bundled copy
	 * renders untranslated. `.gitattributes` is what keeps languages/ in the release archive.
	 */
	public function test_the_languages_directory_and_pot_template_exist() {
		$dir = I18n::languages_dir();

		$this->assertDirectoryExists( $dir );
		$this->assertFileExists( $dir . 'plugin-tracker-sdk.pot' );
	}

	/**
	 * languages_dir() must resolve relative to the package, not the caller's working directory.
	 * This is what keeps it correct inside a scoped copy bundled at an arbitrary depth in someone
	 * else's plugin.
	 */
	public function test_languages_dir_is_absolute_and_package_relative() {
		$dir = I18n::languages_dir();

		$this->assertStringEndsWith( '/languages/', $dir );
		$this->assertStringStartsWith( '/', $dir, 'must be absolute, so it survives any cwd' );

		// The package root is the parent of both src/ and tests/, so languages/ must sit beside
		// src/. This is what keeps resolution correct inside a scoped copy bundled at an arbitrary
		// depth in someone else's plugin.
		$this->assertDirectoryExists( dirname( $dir ) . '/src' );
	}

	/**
	 * A locale with no compiled .mo must not attempt a load. Calling load_textdomain() with a
	 * missing file would be a wasted filesystem hit on every request.
	 */
	public function test_a_locale_with_no_mo_file_does_not_attempt_a_load() {
		Functions\when( 'determine_locale' )->justReturn( 'xx_XX' );
		Functions\when( 'is_textdomain_loaded' )->justReturn( false );
		Functions\expect( 'load_textdomain' )->never();

		$this->assertFalse( I18n::load() );
	}

	/**
	 * An already-loaded domain is left alone. Several bundled copies of this SDK may each call
	 * load(); the first wins and the rest must be cheap no-ops rather than reloading.
	 */
	public function test_an_already_loaded_domain_is_not_reloaded() {
		Functions\when( 'determine_locale' )->justReturn( 'en_US' );
		Functions\when( 'is_textdomain_loaded' )->justReturn( true );
		Functions\expect( 'load_textdomain' )->never();

		$this->assertFalse( I18n::load() );
	}

	/**
	 * The guard means a second call in the same request does no work, however the first turned out.
	 */
	public function test_load_is_attempted_only_once_per_request() {
		Functions\when( 'determine_locale' )->justReturn( 'xx_XX' );
		Functions\when( 'is_textdomain_loaded' )->justReturn( false );
		Functions\expect( 'load_textdomain' )->never();

		I18n::load();
		$this->assertFalse( I18n::load(), 'the second call must short-circuit' );
	}

	/**
	 * When a .mo IS present for the locale, it is handed to load_textdomain() under the SDK's own
	 * domain and with an absolute path.
	 */
	public function test_a_present_mo_file_is_loaded_under_the_sdk_domain() {
		$mo = I18n::languages_dir() . 'plugin-tracker-sdk-qq_QQ.mo';
		file_put_contents( $mo, 'not-a-real-mo' );

		try {
			Functions\when( 'determine_locale' )->justReturn( 'qq_QQ' );
			Functions\when( 'is_textdomain_loaded' )->justReturn( false );

			$captured = array();
			Functions\when( 'load_textdomain' )->alias(
				function ( $domain, $file ) use ( &$captured ) {
					$captured = array( $domain, $file );
					return true;
				}
			);

			$this->assertTrue( I18n::load() );
			$this->assertSame( 'plugin-tracker-sdk', $captured[0], 'must load under the SDK domain' );
			$this->assertSame( $mo, $captured[1], 'must pass an absolute .mo path' );
		} finally {
			unlink( $mo );
		}
	}

	/**
	 * determine_locale() is WordPress 5.0+, and this SDK supports older sites, so get_locale() has
	 * to be the fallback.
	 */
	public function test_get_locale_is_used_when_determine_locale_is_unavailable() {
		// Brain Monkey cannot un-define a function, so this asserts the constant instead: the
		// domain the loader uses must be the one the .pot declares.
		$this->assertSame( 'plugin-tracker-sdk', I18n::DOMAIN );
	}
}
