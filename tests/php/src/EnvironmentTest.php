<?php
/**
 * Tests for the environment readings both transmission lanes share.
 *
 * The point of this class is that neither reading is a raw value: `server()` answers a question from
 * a closed list, and `theme()` answers three named strings. So what is worth testing is not "does it
 * return something" but the two properties the privacy claim rests on -- that a raw header cannot get
 * out, and that an absent WordPress is answered rather than fatal.
 *
 * ## What is not covered here
 *
 * `theme()`'s `function_exists( 'wp_get_theme' )` guard -- the pre-WordPress case -- cannot be
 * exercised in this suite. PluginTrackerTestCase stubs wp_get_theme() for every test, and Brain
 * Monkey defines a real function to do it; PHP cannot then undefine it, so the guard's false branch
 * is unreachable from the moment the harness boots. Writing a test that appeared to cover it would
 * mean stubbing the function to return null, which takes the OTHER branch and fatals on `->parent()`
 * -- proving the opposite of what it claimed. Left uncovered and stated, rather than faked.
 *
 * @package Codexpert\PluginTracker\Test
 */

namespace Codexpert\PluginTracker\Test;

use Brain\Monkey\Functions;
use Codexpert\PluginTracker\Environment;

/**
 * @covers \Codexpert\PluginTracker\Environment
 */
class EnvironmentTest extends PluginTrackerTestCase {

	/**
	 * The real strings these servers actually advertise, mapped to the one word we report.
	 *
	 * Written as the full header rather than the bare name on purpose: `Apache/2.4.41 (Ubuntu)` is
	 * what the function receives in production, and the distro and patch level in it are exactly
	 * what must not survive. A test passing only `apache` would prove nothing about that.
	 */
	public function server_headers() {
		return array(
			'apache'                 => array( 'Apache/2.4.41 (Ubuntu)', 'apache' ),
			'nginx'                  => array( 'nginx/1.18.0', 'nginx' ),
			'caddy'                  => array( 'Caddy', 'caddy' ),
			'lighttpd'               => array( 'lighttpd/1.4.55', 'lighttpd' ),
			'iis'                    => array( 'Microsoft-IIS/10.0', 'iis' ),
			'unrecognised is other'  => array( 'SomeProprietaryServer/9', 'other' ),
			'absent header is empty' => array( null, '' ),
		);
	}

	/**
	 * @dataProvider server_headers
	 *
	 * @param string|null $header   SERVER_SOFTWARE value, or null to unset it entirely.
	 * @param string      $expected The one word we report.
	 * @return void
	 */
	public function test_server_reports_the_product_and_nothing_else( $header, $expected ) {
		if ( null === $header ) {
			unset( $_SERVER['SERVER_SOFTWARE'] );
		} else {
			$_SERVER['SERVER_SOFTWARE'] = $header;
		}

		$this->assertSame( $expected, Environment::server() );
	}

	/**
	 * The two that are built on, or in front of, another server and say so in their own header.
	 *
	 * LiteSpeed's string can read `LiteSpeed` alone but OpenResty's is literally `openresty/1.21.4.1`
	 * while identifying as nginx in other contexts, and a LiteSpeed install fronting Apache reports
	 * both names. Order in the match list is what decides these, so they are asserted separately from
	 * the plain cases above -- reordering that list is the mistake this catches.
	 */
	public function test_the_more_specific_server_name_wins_over_the_one_it_emulates() {
		$_SERVER['SERVER_SOFTWARE'] = 'LiteSpeed/6.0 Apache/2.4.41';
		$this->assertSame( 'litespeed', Environment::server() );

		$_SERVER['SERVER_SOFTWARE'] = 'openresty/1.21.4.1 nginx';
		$this->assertSame( 'openresty', Environment::server() );
	}

	/**
	 * The property the whole closed list exists for.
	 *
	 * Whatever the header says, the answer is one of a fixed set of words. Asserting membership
	 * rather than a specific value is what makes this bind for inputs nobody thought of: a future
	 * edit that fell through to returning `$raw` would pass every case above that it still matched,
	 * and fail here.
	 */
	public function test_no_header_can_produce_an_answer_outside_the_closed_list() {
		$allowed = array( '', 'litespeed', 'openresty', 'nginx', 'apache', 'caddy', 'lighttpd', 'iis', 'other' );

		$headers = array(
			'Apache/2.4.41 (Ubuntu) OpenSSL/1.1.1f mod_fcgid/2.3.9',
			'nginx/1.25.3 (internal build 7, host web-04.prod.example.com)',
			'',
			'../../etc/passwd',
			str_repeat( 'x', 4096 ),
			'<script>alert(1)</script>',
		);

		foreach ( $headers as $header ) {
			$_SERVER['SERVER_SOFTWARE'] = $header;

			$answer = Environment::server();

			$this->assertContains( $answer, $allowed, sprintf( '%s leaked a value that is not in the closed list', $header ) );

			// '' is the one header that legitimately equals its own answer -- absent in, absent out.
			if ( '' !== $header ) {
				$this->assertNotSame( $header, $answer, 'the raw header must never be the answer' );
			}
		}
	}

	/**
	 * A child theme names its parent; that is the case `parent` exists for.
	 */
	public function test_theme_names_the_parent_of_a_child_theme() {
		Functions\when( 'wp_get_theme' )->justReturn(
			new class() {
				public function get_stylesheet() {
					return 'my-child';
				}

				public function get( $key ) {
					return 'Version' === $key ? '2.1' : '';
				}

				public function parent() {
					return new class() {
						public function get_stylesheet() {
							return 'twentytwentyfour';
						}
					};
				}
			}
		);

		$this->assertSame(
			array(
				'slug'    => 'my-child',
				'version' => '2.1',
				'parent'  => 'twentytwentyfour',
			),
			Environment::theme()
		);
	}

	/**
	 * A parent theme reports an empty parent, not `false` and not the theme's own slug.
	 *
	 * The empty string matters downstream: these values are written to VARCHAR columns and compared
	 * as strings, so a stray `false` would arrive as `''` in one place and `0` in another.
	 */
	public function test_a_theme_with_no_parent_reports_an_empty_parent() {
		$theme = Environment::theme();

		$this->assertSame( '', $theme['parent'] );
		$this->assertIsString( $theme['slug'] );
	}
}
