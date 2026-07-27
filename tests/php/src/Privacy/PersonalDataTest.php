<?php
/**
 * Tests for the GDPR eraser: the part of Personal_Data that actually matters, since the SDK holds
 * no personal data and the exporter is mostly diagnostic.
 *
 * @package Codexpert\PluginTracker\Test
 */

namespace Codexpert\PluginTracker\Test\Privacy;

use Brain\Monkey\Functions;
use Codexpert\PluginTracker\Config;
use Codexpert\PluginTracker\Consent\Gate;
use Codexpert\PluginTracker\Privacy\Personal_Data;
use Codexpert\PluginTracker\Storage\Install;
use Codexpert\PluginTracker\Test\PluginTrackerTestCase;

/**
 * @covers \Codexpert\PluginTracker\Privacy\Personal_Data
 */
class PersonalDataTest extends PluginTrackerTestCase {

	/**
	 * Register Personal_Data and capture the eraser callback it installs under
	 * wp_privacy_personal_data_erasers, by intercepting add_filter() directly rather than relying
	 * on Brain Monkey's built-in (no-op by default) hooks fake.
	 *
	 * @param Config  $config  Config.
	 * @param Gate    $consent Consent gate.
	 * @param Install $install Install identity.
	 * @return callable
	 */
	private function registered_eraser( Config $config, Gate $consent, Install $install ) {
		$captured = array();
		Functions\when( 'add_filter' )->alias(
			function ( $hook, $callback ) use ( &$captured ) {
				$captured[ $hook ] = $callback;
				return true;
			}
		);

		Personal_Data::register( $config, $consent, $install );

		$this->assertArrayHasKey(
			'wp_privacy_personal_data_erasers',
			$captured,
			'precondition: Personal_Data::register() must install an eraser'
		);

		$erasers = call_user_func( $captured['wp_privacy_personal_data_erasers'], array() );
		$key     = 'cx-tracker-' . $config->plugin();

		return $erasers[ $key ]['callback'];
	}

	/**
	 * erase() must clear the queue, the consent record, AND the install salt -- and clearing the
	 * salt is the point: the derived install id must differ afterwards, so previously-reported data
	 * can no longer be correlated to this site by anyone, including us.
	 */
	public function test_erase_clears_the_queue_consent_and_salt_and_resets_the_install_id() {
		$config = $this->make_config( array( 'enabled' => true ) );
		$this->seed_consent( $config, Gate::POLICY, true );
		$this->seed_queue( $config, 3 );

		$consent = new Gate( $config );
		$install = new Install( $config );

		Functions\when( 'home_url' )->justReturn( 'https://tracker-sdk-personal-data-test.example' );

		$id_before = $install->id();

		$eraser = $this->registered_eraser( $config, $consent, $install );

		$this->assertNotSame( array(), $this->queue_for( $config )->all(), 'precondition: the queue must hold events before erasure' );
		$this->assertTrue( $consent->site_opted_in(), 'precondition: consent must be recorded before erasure' );

		$result = call_user_func( $eraser, 'someone@example.test', 1 );

		$this->assertSame( array(), $this->queue_for( $config )->all(), 'erase() must clear the queue' );
		$this->assertFalse( $consent->site_opted_in(), 'erase() must clear consent' );
		$this->assertFalse( $consent->answered(), 'erase() must forget consent entirely, not merely opt the site out' );

		$id_after = ( new Install( $config ) )->id();
		$this->assertNotSame(
			$id_before,
			$id_after,
			'erase() must reset the salt so the derived install id changes -- otherwise previously ' .
			'reported data would still be correlated to this site'
		);

		$this->assertTrue( $result['items_removed'] );
	}
}
