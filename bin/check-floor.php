<?php
/**
 * Assert the SDK adds nothing to a consumer's dependency tree.
 *
 * This SDK is bundled inside third-party WordPress plugins. A runtime dependency here becomes
 * theirs -- and, because the shipped copy is namespace-scoped and frozen, becomes a version they can
 * never reconcile against their own. So `require` must contain nothing but the PHP constraint and
 * ext-* extension checks, forever.
 *
 * It also covers what `config.platform.php` used to cover by accident. That pin was 7.2.5, matching
 * the distribution floor, which meant composer refused any dependency needing more than 7.2 --
 * including dev tooling that never ships. It is now 7.4 so PHPStan 2.x can run, and this script is
 * the deliberate version of the guarantee the pin was providing incidentally.
 *
 * Run: composer check-floor
 *
 * @package Codexpert\PluginTracker
 */

$manifest = __DIR__ . '/../composer.json';

if ( ! is_readable( $manifest ) ) {
	fwrite( STDERR, "check-floor: cannot read composer.json\n" );
	exit( 1 );
}

$composer = json_decode( (string) file_get_contents( $manifest ), true );

if ( ! is_array( $composer ) ) {
	fwrite( STDERR, "check-floor: composer.json is not valid JSON\n" );
	exit( 1 );
}

$require = isset( $composer['require'] ) && is_array( $composer['require'] ) ? $composer['require'] : array();
$floor   = isset( $require['php'] ) ? (string) $require['php'] : '';
$offend  = array();

foreach ( array_keys( $require ) as $package ) {
	if ( 'php' === $package || 0 === strpos( $package, 'ext-' ) ) {
		continue;
	}

	$offend[] = $package;
}

if ( ! empty( $offend ) ) {
	fwrite(
		STDERR,
		"check-floor: FAIL -- the SDK must have no runtime dependencies, found:\n  - "
		. implode( "\n  - ", $offend )
		. "\n\nA dependency here is inherited by every plugin that bundles this SDK, at a version they\n"
		. "cannot change. Move it to require-dev, or inline what you need.\n"
	);
	exit( 1 );
}

if ( '>=7.2' !== $floor ) {
	fwrite(
		STDERR,
		sprintf(
			"check-floor: FAIL -- require.php is \"%s\", expected \">=7.2\".\n\n"
			. "The floor is a distribution promise, not a preference: consumers bundle this SDK and\n"
			. "inherit its floor. Raising it silently breaks every site below the new minimum. If the\n"
			. "raise is intended, update this script, phpstan.neon (phpVersion) and phpcs.xml.dist\n"
			. "(testVersion) together, and say so in the changelog.\n",
			$floor
		)
	);
	exit( 1 );
}

printf( "check-floor: OK -- no runtime dependencies, floor is %s.\n", $floor );
exit( 0 );
