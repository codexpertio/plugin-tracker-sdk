<?php
/**
 * Autoloader for a bundled copy of the Plugin Tracker SDK.
 *
 * Committed rather than generated. bin/build-dist.sh used to emit this file as part of producing a
 * namespace-scoped artifact; the scoping was dropped, and with it the build. The download is now the
 * tag's own source archive, which GitHub produces for every release -- so anything a consumer needs
 * has to be IN the repository, and this file is the one thing the integration snippet requires.
 *
 * ## This file RETURNS the Tracker class name. Do not hard-code it.
 *
 *     $tracker = require __DIR__ . '/plugin-tracker-sdk/autoload.php';
 *     $tracker::init( array( ... ) );
 *
 * The name no longer changes between versions, so this is no longer load-bearing the way it was when
 * the namespace carried the version. It is kept because every snippet already generated is written
 * against it, and because it costs nothing: a consumer who never names a class cannot name a stale
 * one, whatever we do to the namespace later.
 *
 * Use `require`, NOT `require_once`. A repeated `require_once` returns `true` rather than the file's
 * return value, so `$tracker` would be a boolean and the next line a fatal. Repeating a plain
 * `require` is safe here: the registration below is guarded and this file has no other side effects.
 *
 * ## What bundling two different versions on one site now costs
 *
 * Stated plainly because dropping the namespace rewrite is what made it true. Every bundled copy
 * declares the same classes under the same namespace, and PHP class declarations are global and not
 * versioned -- so on a site running two plugins that each bundle this SDK, whichever autoloader
 * registers first serves BOTH. A plugin bundling 1.3.0 can therefore be handed 1.2.0's classes and
 * call a method that does not exist yet.
 *
 * The guard constant below is deliberately unversioned, which is what makes that outcome consistent
 * rather than random: the second copy registers nothing instead of racing the first. This is the
 * same exposure the Composer route has always had, and it is now the same for both routes.
 *
 * @package Codexpert\PluginTracker
 */

// Guarded so requiring this file repeatedly -- which the contract above invites -- registers one
// autoloader rather than N. `define()` rather than `const` because this is conditional.
if ( ! defined( 'CX_TRACKER_SDK_LOADED' ) ) {
	define( 'CX_TRACKER_SDK_LOADED', true );

	spl_autoload_register(
		function ( $fqcn ) {
			$prefix = 'Codexpert\\PluginTracker\\';
			$len    = strlen( $prefix );

			if ( 0 !== strncmp( $prefix, $fqcn, $len ) ) {
				return;
			}

			$relative = substr( $fqcn, $len );
			$file     = __DIR__ . '/src/' . str_replace( '\\', '/', $relative ) . '.php';

			if ( is_file( $file ) ) {
				require $file;
			}
		}
	);
}

// The return value IS the integration contract. Keep it last, and keep it unconditional: an early
// return above would hand the consumer `true` and turn the line after their require into a fatal.
return 'Codexpert\\PluginTracker\\Tracker';
