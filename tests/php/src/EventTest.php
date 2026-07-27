<?php
/**
 * Tests for the closed event allow-list and payload validation.
 *
 * @package Codexpert\PluginTracker\Test
 */

namespace Codexpert\PluginTracker\Test;

use Codexpert\PluginTracker\Event;

/**
 * @covers \Codexpert\PluginTracker\Event
 */
class EventTest extends PluginTrackerTestCase {

	/**
	 * The allow-list is closed and its membership is a contract (docs/EVENTS.md). Hand-typed on
	 * purpose: asserting Event::all() against itself, or against a set built from its own
	 * constants, would pass even if a name were silently renamed or dropped.
	 */
	public function test_all_is_exactly_the_six_documented_names() {
		$this->assertSame(
			array( 'install', 'activate', 'version', 'compat', 'feature', 'deactivation' ),
			Event::all()
		);
	}

	/**
	 * Each of the six hand-typed names must be accepted individually -- not derived from all().
	 */
	public function test_is_allowed_accepts_every_known_name() {
		foreach ( array( 'install', 'activate', 'version', 'compat', 'feature', 'deactivation' ) as $name ) {
			$this->assertTrue( Event::is_allowed( $name ), "'$name' should be allow-listed" );
		}
	}

	/**
	 * Unknown names are rejected outright -- a typo must not silently become traffic.
	 */
	public function test_is_allowed_rejects_unknown_names() {
		$this->assertFalse( Event::is_allowed( 'uninstall' ) );
		$this->assertFalse( Event::is_allowed( 'activated' ) );
		$this->assertFalse( Event::is_allowed( 'Install' ) );
		$this->assertFalse( Event::is_allowed( '' ) );
	}

	/**
	 * is_allowed() must not choke on non-string input; it should simply reject it.
	 */
	public function test_is_allowed_rejects_non_string_input() {
		$this->assertFalse( Event::is_allowed( null ) );
		$this->assertFalse( Event::is_allowed( 123 ) );
		$this->assertFalse( Event::is_allowed( array( 'install' ) ) );
	}

	/**
	 * FEATURE_NAME_PATTERN is the SDK's only injection surface (docs/EVENTS.md). Accept cases.
	 */
	public function test_feature_name_pattern_accepts_valid_names() {
		$this->assertTrue( Event::is_valid_feature_name( 'my_feature' ) );
		$this->assertTrue( Event::is_valid_feature_name( 'a' ) );
		$this->assertTrue( Event::is_valid_feature_name( str_repeat( 'a', 32 ) ) );
		$this->assertTrue( Event::is_valid_feature_name( 'feature.name-v2' ) );
	}

	/**
	 * FEATURE_NAME_PATTERN reject cases, including the injection-shaped one.
	 */
	public function test_feature_name_pattern_rejects_invalid_names() {
		$this->assertFalse( Event::is_valid_feature_name( '' ) );
		$this->assertFalse( Event::is_valid_feature_name( str_repeat( 'a', 33 ) ) );
		$this->assertFalse( Event::is_valid_feature_name( 'MyFeature' ) );
		$this->assertFalse( Event::is_valid_feature_name( 'my feature' ) );
		$this->assertFalse( Event::is_valid_feature_name( 'feature;drop table events' ) );
	}

	/**
	 * A feature event needs a valid "name"; a missing or invalid one is rejected with an error
	 * string, never queued silently.
	 */
	public function test_feature_event_requires_a_valid_name() {
		$this->assertIsString( Event::validate_props( Event::FEATURE, array() ) );
		$this->assertIsString( Event::validate_props( Event::FEATURE, array( 'name' => 'Not Valid' ) ) );
		$this->assertNull( Event::validate_props( Event::FEATURE, array( 'name' => 'my_feature' ) ) );
	}

	/**
	 * "count", when present, must be a positive integer.
	 */
	public function test_feature_event_rejects_non_positive_count() {
		$this->assertIsString(
			Event::validate_props(
				Event::FEATURE,
				array(
					'name'  => 'x',
					'count' => 0,
				)
			)
		);
		$this->assertIsString(
			Event::validate_props(
				Event::FEATURE,
				array(
					'name'  => 'x',
					'count' => -1,
				)
			)
		);
		$this->assertIsString(
			Event::validate_props(
				Event::FEATURE,
				array(
					'name'  => 'x',
					'count' => '3',
				)
			)
		);
		$this->assertNull(
			Event::validate_props(
				Event::FEATURE,
				array(
					'name'  => 'x',
					'count' => 3,
				)
			)
		);
	}

	/**
	 * The single highest-value assertion in this file: a deactivation "note" must always be
	 * refused. A free-text field is the most likely place for a user to type PII, and it must
	 * never be transmittable.
	 */
	public function test_deactivation_note_is_always_refused() {
		$error = Event::validate_props( Event::DEACTIVATION, array( 'note' => 'x' ) );

		$this->assertIsString( $error );
		$this->assertNotSame( '', $error );
	}

	/**
	 * A note is refused even when paired with an otherwise-valid reason.
	 */
	public function test_deactivation_note_is_refused_even_alongside_a_valid_reason() {
		$error = Event::validate_props(
			Event::DEACTIVATION,
			array(
				'reason' => 'temporary',
				'note'   => 'the free text a user typed',
			)
		);

		$this->assertIsString( $error );
	}

	/**
	 * The deactivation survey must be dismissible: no props at all is valid.
	 */
	public function test_deactivation_with_no_props_is_valid() {
		$this->assertNull( Event::validate_props( Event::DEACTIVATION, array() ) );
	}

	/**
	 * "reason" must come from the closed set.
	 */
	public function test_deactivation_reason_must_be_from_the_closed_set() {
		$this->assertNull( Event::validate_props( Event::DEACTIVATION, array( 'reason' => 'temporary' ) ) );
		$this->assertIsString( Event::validate_props( Event::DEACTIVATION, array( 'reason' => 'it_was_ugly' ) ) );
	}

	/**
	 * version/compat events require their documented fields.
	 */
	public function test_version_event_requires_non_empty_from() {
		$this->assertIsString( Event::validate_props( Event::VERSION, array() ) );
		$this->assertIsString( Event::validate_props( Event::VERSION, array( 'from' => '' ) ) );
		$this->assertNull( Event::validate_props( Event::VERSION, array( 'from' => '1.0.0' ) ) );
	}

	public function test_compat_event_requires_what_and_from() {
		$this->assertIsString( Event::validate_props( Event::COMPAT, array( 'from' => '8.0' ) ) );
		$this->assertIsString( Event::validate_props( Event::COMPAT, array( 'what' => 'php' ) ) );
		$this->assertIsString(
			Event::validate_props(
				Event::COMPAT,
				array(
					'what' => 'ruby',
					'from' => '8.0',
				)
			)
		);
		$this->assertNull(
			Event::validate_props(
				Event::COMPAT,
				array(
					'what' => 'php',
					'from' => '8.0',
				)
			)
		);
	}
}
