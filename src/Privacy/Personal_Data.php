<?php
/**
 * GDPR exporter and eraser registration.
 *
 * @package Codexpert\PluginTracker
 */

namespace Codexpert\PluginTracker\Privacy;

use Codexpert\PluginTracker\Config;
use Codexpert\PluginTracker\Consent\Gate;
use Codexpert\PluginTracker\Consent\Notice;
use Codexpert\PluginTracker\Storage\Install;
use Codexpert\PluginTracker\Storage\Queue;
use Codexpert\PluginTracker\Cron\Scheduler;

/**
 * Wires the SDK into WordPress's personal-data tooling.
 *
 * Required by spec 10.4. Worth being precise about what is actually exportable here: the SDK
 * stores no personal data, by design. The install ID is an HMAC under a salt that never leaves the
 * site, so it is not linkable to a person even by us.
 *
 * What the exporter therefore returns is the *site's* telemetry state -- whether consent was given
 * and when, and which anonymous install ID represents this site. That is the honest answer to "what
 * do you hold about me", and it is more useful than an empty response, because it lets an admin see
 * exactly what the SDK is doing.
 *
 * The eraser is the part that matters: deleting the salt changes the derived install ID, so
 * previously-reported data can no longer be correlated to this site by anyone, including us.
 */
class Personal_Data {

	/**
	 * Register the exporter and eraser.
	 *
	 * @param Config  $config  Config.
	 * @param Gate    $consent Consent gate.
	 * @param Install $install Install identity.
	 * @return void
	 */
	public static function register( Config $config, Gate $consent, Install $install ) {

		if ( ! function_exists( 'add_filter' ) ) {
			return;
		}

		$slug = $config->plugin();

		add_filter(
			'wp_privacy_personal_data_exporters',
			function ( $exporters ) use ( $config, $consent, $install, $slug ) {
				$exporters[ 'cx-tracker-' . $slug ] = array(
					/* translators: %s: the consumer plugin's display name. */
					'exporter_friendly_name' => sprintf( __( 'Usage data (%s)', 'plugin-tracker-sdk' ), $config->name() ),
					'callback'               => function ( $email, $page = 1 ) use ( $config, $consent, $install ) {
						return self::export( $config, $consent, $install, $email, $page );
					},
				);
				return $exporters;
			}
		);

		add_filter(
			'wp_privacy_personal_data_erasers',
			function ( $erasers ) use ( $config, $consent, $install, $slug ) {
				$erasers[ 'cx-tracker-' . $slug ] = array(
					/* translators: %s: the consumer plugin's display name. */
					'eraser_friendly_name' => sprintf( __( 'Usage data (%s)', 'plugin-tracker-sdk' ), $config->name() ),
					'callback'             => function ( $email, $page = 1 ) use ( $config, $consent, $install ) {
						return self::erase( $config, $consent, $install, $email, $page );
					},
				);
				return $erasers;
			}
		);
	}

	/**
	 * Export this site's telemetry state.
	 *
	 * @param Config  $config  Config.
	 * @param Gate    $consent Consent gate.
	 * @param Install $install Install identity.
	 * @param string  $email   Requester email, unused -- see the class docblock.
	 * @param int     $page    Page number.
	 * @return array
	 */
	private static function export( Config $config, Gate $consent, Install $install, $email, $page ) {
		unset( $email );

		if ( 1 < (int) $page ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$record = $consent->record();

		$items = array(
			array(
				'group_id'    => 'cx-tracker',
				'group_label' => 'Anonymous usage data',
				'item_id'     => 'cx-tracker-' . $config->plugin(),
				'data'        => array(
					array(
						'name'  => 'Plugin',
						'value' => $config->name(),
					),
					array(
						'name'  => 'Usage data sharing',
						'value' => $consent->site_opted_in() ? 'Enabled' : 'Disabled',
					),
					array(
						'name'  => 'Anonymous install ID',
						'value' => $install->id(),
					),
					array(
						'name'  => 'Consented at',
						'value' => empty( $record['at'] ) ? 'Never' : gmdate( 'c', (int) $record['at'] ),
					),
					array(
						'name'  => 'Personal data held',
						'value' => 'None. The install ID is a one-way hash salted with a value that never leaves this site, '
							. 'and no email address, username, IP address or site address is ever transmitted.',
					),
				),
			),
		);

		return array(
			'data' => $items,
			'done' => true,
		);
	}

	/**
	 * Erase this site's telemetry state.
	 *
	 * @param Config  $config  Config.
	 * @param Gate    $consent Consent gate.
	 * @param Install $install Install identity.
	 * @param string  $email   Requester email, unused.
	 * @param int     $page    Page number.
	 * @return array
	 */
	private static function erase( Config $config, Gate $consent, Install $install, $email, $page ) {
		unset( $email );

		if ( 1 < (int) $page ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}

		$queue = new Queue( $config );
		$queue->clear();

		// Cancel the scheduled flush too. Without this an erasure leaves a live cron event behind:
		// it would self-heal on its next run, because consent is gone by then and flush() clears
		// and unschedules -- but leaving a scheduled job pointing at erased state is not what
		// "erased" should mean, and it is a needless wakeup on the site.
		$scheduler = new Scheduler( $config );
		$scheduler->unschedule();

		// Order matters: forget the salt LAST. The install ID is derived from it, so anything that
		// wants to reference the old ID must do so before this point.
		$consent->forget();
		delete_option( $config->option( 'token' ) );
		delete_option( $config->option( 'interval' ) );
		Notice::forget( $config );
		$install->forget();

		return array(
			'items_removed'  => true,
			'items_retained' => false,
			'messages'       => array(
				'Local usage-data state was deleted and the anonymous install ID was reset, so any '
				. 'previously reported data can no longer be correlated with this site.',
			),
			'done'           => true,
		);
	}
}
