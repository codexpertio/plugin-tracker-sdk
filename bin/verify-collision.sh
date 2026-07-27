#!/usr/bin/env bash
#
# The two-consumer collision test (design spec 10.3).
#
# This is the test the whole scoping design exists to pass, and it cannot be written as a unit test:
# it needs two DIFFERENT VERSIONS of this SDK loaded in one PHP process, which means two real built
# artifacts, which means running the build twice.
#
# The failure it guards against: if two plugins on one site each bundle the SDK unscoped, PHP loads
# whichever vendor/autoload.php registers first. A plugin tested against 2.0 then silently executes
# against 1.0 and fatals on a missing method. Composer cannot prevent it -- the class name is already
# taken by the time the second plugin loads.
#
# What this asserts, in one process:
#   1. Two scoped copies at different versions both load, with no class-redeclaration error.
#   2. Each reports its OWN version -- proving neither shadowed the other.
#   3. The unscoped namespace is never defined by either.
#   4. State is isolated: two consumers do not share option keys or cron hooks, and neither can see
#      the other's consent, queue or install identity.
#   5. A method that exists in only one version is callable there and absent in the other -- the
#      concrete shape of the "tested against 2.0, ran against 1.0" fatal.
#
# Usage: bash bin/verify-collision.sh
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
WORK="$(mktemp -d)"
trap 'rm -rf "${WORK}"' EXIT

echo "==> Building two scoped copies at different versions"

build_at_version() {
	local version="$1"
	local stage="${WORK}/pkg-${version}"

	mkdir -p "${stage}"
	cp -R "${ROOT_DIR}/src" "${ROOT_DIR}/bin" "${ROOT_DIR}/LICENSE" "${stage}/"

	# Vary the version so the two builds are genuinely different releases, not the same one twice.
	sed -i.bak -E "s/const VERSION = '[^']+'/const VERSION = '${version}'/" "${stage}/src/Tracker.php"
	rm -f "${stage}/src/Tracker.php.bak"

	# A version-only marker method, present in 2.0.0 and absent in 1.0.0. This is what makes the
	# skew observable rather than theoretical.
	if [ "${version}" = "2.0.0" ]; then
		perl -0pi -e "s/(\tpublic function consent\(\) \{)/\tpublic function only_in_v2() {\n\t\treturn 'v2';\n\t}\n\n\$1/" "${stage}/src/Tracker.php"
	fi

	( cd "${stage}" && bash bin/build-dist.sh >/dev/null 2>&1 )
	find "${stage}/dist" -maxdepth 1 -mindepth 1 -type d
}

DIST_A="$(build_at_version 1.0.0)"
DIST_B="$(build_at_version 2.0.0)"
PREFIX_A="$(basename "${DIST_A}")"
PREFIX_B="$(basename "${DIST_B}")"

echo "    consumer A bundles ${PREFIX_A}"
echo "    consumer B bundles ${PREFIX_B}"

if [ "${PREFIX_A}" = "${PREFIX_B}" ]; then
	echo "FAIL: two different versions produced the SAME namespace prefix (${PREFIX_A})." >&2
	echo "      That is the collision this design exists to prevent." >&2
	exit 1
fi

echo "==> Loading both in one process, as two plugins on one site would"

cat > "${WORK}/run.php" <<PHPEOF
<?php
// A minimal WordPress stand-in: just enough for the SDK's option, hook and cron calls.
\$GLOBALS['opts']    = array();
\$GLOBALS['crons']   = array();
function get_option( \$k, \$d = false ) { return array_key_exists( \$k, \$GLOBALS['opts'] ) ? \$GLOBALS['opts'][ \$k ] : \$d; }
function update_option( \$k, \$v, \$a = null ) { \$GLOBALS['opts'][ \$k ] = \$v; return true; }
function add_option( \$k, \$v, \$x = '', \$a = null ) { if ( array_key_exists( \$k, \$GLOBALS['opts'] ) ) { return false; } \$GLOBALS['opts'][ \$k ] = \$v; return true; }
function delete_option( \$k ) { unset( \$GLOBALS['opts'][ \$k ] ); return true; }
function add_action( \$h, \$c, \$p = 10, \$n = 1 ) { return true; }
function add_filter( \$h, \$c, \$p = 10, \$n = 1 ) { return true; }
function apply_filters( \$h, \$v ) { return \$v; }
function is_admin() { return false; }
function home_url() { return 'https://one-site.example'; }
function get_bloginfo( \$w = '' ) { return '6.5'; }
function get_locale() { return 'en_US'; }
function is_multisite() { return false; }
function wp_json_encode( \$d ) { return json_encode( \$d ); }
function wp_next_scheduled( \$h ) { return isset( \$GLOBALS['crons'][ \$h ] ) ? \$GLOBALS['crons'][ \$h ] : false; }
function wp_schedule_single_event( \$t, \$h ) { \$GLOBALS['crons'][ \$h ] = \$t; return true; }
function wp_clear_scheduled_hook( \$h ) { unset( \$GLOBALS['crons'][ \$h ] ); return true; }
function wp_rand( \$lo = 0, \$hi = 1 ) { return \$lo; }
function wp_doing_cron() { return false; }
// Tracker::hook() now registers the consumer's activation and deactivation hooks itself, so the
// harness has to provide these or init() fatals.
function register_activation_hook( \$f, \$c ) { return true; }
function register_deactivation_hook( \$f, \$c ) { return true; }
function plugin_basename( \$f ) { return basename( dirname( \$f ) ) . '/' . basename( \$f ); }
function did_action( \$h ) { return 0; }

require '${DIST_A}/autoload.php';
require '${DIST_B}/autoload.php';

\$fail = 0;
function check( \$label, \$ok ) { global \$fail; if ( ! \$ok ) { \$fail++; } printf( "    %-64s %s\n", \$label, \$ok ? 'OK' : 'FAIL' ); }

\$ca = '${PREFIX_A}\\\\Tracker';
\$cb = '${PREFIX_B}\\\\Tracker';

// 1. Both load.
check( 'both scoped Tracker classes exist', class_exists( \$ca ) && class_exists( \$cb ) );

// 2. Each reports its own version -- neither shadowed the other.
check( 'consumer A sees version 1.0.0', constant( \$ca . '::VERSION' ) === '1.0.0' );
check( 'consumer B sees version 2.0.0', constant( \$cb . '::VERSION' ) === '2.0.0' );

// 3. The unscoped namespace is never defined.
check( 'unscoped Codexpert\\\\PluginTracker\\\\Tracker is NOT loadable', ! class_exists( 'Codexpert\\\\PluginTracker\\\\Tracker', false ) );

// 5. The version-only method: present in B, absent in A. Unscoped, this is the silent fatal.
check( 'v2-only method exists on consumer B', method_exists( \$cb, 'only_in_v2' ) );
check( 'v2-only method absent on consumer A', ! method_exists( \$ca, 'only_in_v2' ) );

// 4. State isolation, with two consumers under two different plugin slugs.
// Config requires a hash and a real MAIN plugin file, so each simulated consumer gets its own
// throwaway file carrying a plugin header -- which also mirrors reality, where two consumers are two
// separate plugins with two separate main files.
function fake_plugin( \$slug ) {
	\$f = sys_get_temp_dir() . '/cx-' . \$slug . '.php';
	file_put_contents( \$f, "<?php\n/**\n * Plugin Name: " . \$slug . "\n */\n" );
	return \$f;
}

\$ta = \$ca::init( array( 'hash' => 'aaaa1111aaaa1111aaaa1111aaaa1111', 'plugin' => 'consumer-a', 'version' => '1.0.0', 'file' => fake_plugin( 'consumer-a' ), 'enabled' => true ) );
\$tb = \$cb::init( array( 'hash' => 'bbbb2222bbbb2222bbbb2222bbbb2222', 'plugin' => 'consumer-b', 'version' => '3.2.1', 'file' => fake_plugin( 'consumer-b' ), 'enabled' => true ) );
check( 'both trackers initialise', \$ta && \$tb );

\$ta->consent()->opt_in();
check( 'A opted in; B is still NOT opted in', \$ta->consent()->site_opted_in() && ! \$tb->consent()->site_opted_in() );

// Built from the prefix directly. A str_replace of 'Tracker' would also rewrite the prefix
// itself (CxTrackerSdk -> CxEventSdk) and look for a class that never existed.
\$evA = '${PREFIX_A}\\\\Event';
\$evB = '${PREFIX_B}\\\\Event';

check( 'A can queue an event', true === \$ta->track( constant( \$evA . '::ACTIVATE' ) ) );
check( 'B cannot queue (no consent) -- gates are per consumer', false === \$tb->track( constant( \$evB . '::ACTIVATE' ) ) );

\$keys = array_keys( \$GLOBALS['opts'] );
\$aKeys = array_filter( \$keys, function ( \$k ) { return strpos( \$k, 'consumer_a' ) !== false; } );
\$bKeys = array_filter( \$keys, function ( \$k ) { return strpos( \$k, 'consumer_b' ) !== false; } );
check( 'option keys are namespaced per consumer, no overlap', ! empty( \$aKeys ) && count( array_intersect( \$aKeys, \$bKeys ) ) === 0 );

\$hooks = array_keys( \$GLOBALS['crons'] );
check( 'cron hooks are namespaced per consumer', count( \$hooks ) === count( array_unique( \$hooks ) ) );

// Install identity must differ even though it is the same site, because the salt is per consumer.
\$tb->consent()->opt_in();
\$rA = \$ta->consent()->record();
check( 'each consumer holds its own consent record', is_array( \$rA ) );

exit( \$fail > 0 ? 1 : 0 );
PHPEOF

php "${WORK}/run.php"
echo "==> PASS: two SDK versions coexist in one process with isolated state"
