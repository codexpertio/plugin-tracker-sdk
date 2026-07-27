<?php
/**
 * PHPUnit bootstrap.
 *
 * Standalone on purpose: this suite never loads WordPress. Every WordPress function the SDK
 * touches is mocked per-test with brain/monkey (see tests/php/src/PluginTrackerTestCase.php); this
 * file only wires the Composer autoloader, which resolves all three of:
 *
 *   - the SDK's own PSR-4 namespace  Codexpert\PluginTracker\  -> src/          (autoload)
 *   - the suite's PSR-4 namespace    Codexpert\PluginTracker\Test\ -> tests/php/src/ (autoload-dev)
 *   - the un-namespaced global helpers under tests/php/utils/, via an autoload-dev classmap
 *     entry (they carry no namespace by design -- see tests/php/utils/OptionStore.php).
 *
 * @package Codexpert\PluginTracker\Test
 */

$autoload = __DIR__ . '/../../vendor/autoload.php';

if ( ! file_exists( $autoload ) ) {
	fwrite( STDERR, "vendor/autoload.php is missing. Run `composer install` first.\n" );
	exit( 1 );
}

require $autoload;
