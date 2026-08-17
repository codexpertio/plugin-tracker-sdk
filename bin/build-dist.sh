#!/usr/bin/env bash
#
# build-dist.sh -- produce a namespace-scoped, self-contained copy of the SDK under dist/.
#
# THE PROBLEM THIS SOLVES
# ------------------------------------------------------------------------------------------
# This SDK is not distributed via Packagist. It is downloaded as a zip from a dashboard by
# registered plugin owners and bundled directly into their own plugins. That means a single
# WordPress site can end up running several independent copies of this SDK at different
# versions -- one per bundling plugin -- all declaring the SAME class names under the SAME
# namespace (Codexpert\PluginTracker). PHP class declarations are global and not versioned:
# whichever copy's autoloader (or plain `require`) registers first on a given request wins,
# and every other bundled copy silently reuses that FIRST copy's classes instead of its own.
# A plugin built against SDK 2.0 can therefore run against a stray 1.0 copy loaded earlier by
# an unrelated plugin, and fatal on a method that only exists in 2.0 -- silently, in
# production, with no error at either plugin's own install step.
#
# Renaming the namespace at build time (what php-scoper / brianhenryie/strauss are for) makes
# every version's classes live at a different, non-colliding FQCN, so N bundled copies can
# coexist in one PHP process the way N different libraries would.
#
# WHY A HAND-ROLLED REWRITE INSTEAD OF php-scoper OR strauss
# ------------------------------------------------------------------------------------------
# Neither tool is a dependency of this package (checked: `composer show` finds them on
# Packagist but neither is installed under vendor/, and vendor/bin has no `php-scoper` or
# `strauss` binary). Both tools exist primarily to rewrite a VENDORED DEPENDENCY TREE -- they
# walk composer.lock, prefix every third-party package's namespace, and fix up every call
# site that referenced it. This package's composer.json requires nothing but `php` at
# runtime (`"require": {"php": ">=7.2"}`) -- there is no vendored dependency tree to scope,
# only this SDK's own 11 files under src/, whose namespaces all sit under a single root
# (Codexpert\PluginTracker), with zero dynamic class-name construction (`::class`, `new $var`,
# `call_user_func`, `class_exists`, `is_a`, `get_class` -- grep confirms none in src/).
#
# NOTE, because this used to say otherwise: src/ is NOT flat and is NOT a single namespace any
# more. It is organised into sub-namespaces -- ...\Consent, ...\Storage, ...\Http, ...\Cron,
# ...\Privacy -- alongside the three root classes (Tracker, Config, Event), and the sub-namespaced
# classes import from the root, so there ARE `use Codexpert\PluginTracker\...;` statements now (19
# of them). A single literal str_replace remains correct and remains TOTAL, for one specific
# reason: "Codexpert\PluginTracker" is a STRICT PREFIX of every namespace declared in src/, and
# str_replace on a prefix is prefix-preserving. All three forms therefore rewrite correctly in the
# same single pass:
#
#     namespace Codexpert\PluginTracker;          ->  namespace <PREFIX>;
#     namespace Codexpert\PluginTracker\Storage;  ->  namespace <PREFIX>\Storage;
#     use       Codexpert\PluginTracker\Config;   ->  use       <PREFIX>\Config;
#
# Verified mechanically, not assumed: 43 occurrences total -- 11 `namespace` declarations (4 root
# + 7 sub), 19 `use` imports, 10 `@package` docblock tags. Every single occurrence is followed by
# '\', ';' or end-of-line, i.e. none of them is a longer identifier that merely starts with the
# same characters (there is no `Codexpert\PluginTrackerSomething`). That is what makes the prefix
# rewrite unambiguous, and the exact-count assertion below is what keeps it honest: this script
# does not hard-code 39, it counts the source first and then asserts it replaced exactly that many.
# A full AST-rewriting tool such as php-scoper is built to safely
# handle the cases THIS package does not have (dynamic strings, nested vendor trees, global
# function/constant prefixing). Adding that dependency and its own maintenance burden to
# scope a single, static, already-fully-qualified namespace string would be disproportionate.
#
# LIMITATION, stated plainly: this is a textual rewrite, not a semantic one. It is safe here
# specifically because the properties above were verified, not assumed. `use` imports and
# sub-namespaces ARE handled correctly, because both are prefix matches. What this script would
# NOT catch is a `::class` reference on a dynamic name, or any class-name string ASSEMBLED at
# runtime from the vendor namespace, e.g. `"Codexpert" . "\\PluginTracker"` or a FQCN built from
# config. The self-checks further down catch the syntactic and load-time symptoms of a bad
# rewrite -- VERIFY #1 greps for any surviving unscoped reference, VERIFY #2 runs `php -l` on
# every generated file, and VERIFY #3 actually LOADS every scoped class through the generated
# autoloader and constructs one from each sub-namespace -- but none of them can catch a name
# assembled at runtime. If src/ ever grows dependencies of its own, or such dynamic
# construction, switch to php-scoper.
#
# WHY NOT automattic/jetpack-autoloader
# ------------------------------------------------------------------------------------------
# Jetpack's autoloader solves the same collision by having every participating plugin defer
# to whichever bundled copy declares the HIGHEST version, instead of whichever loads first.
# It only works, though, if every plugin on the site uses it -- a single third-party plugin
# that bundles this SDK via a plain `require 'vendor/autoload.php'` (which is exactly what a
# zip downloaded from a dashboard will do, since we cannot enforce anything about how it is
# integrated) opts the whole site back into first-loaded-wins for every OTHER copy too. A
# shared runtime that cannot be guaranteed present is worse than no shared runtime, because it
# converts a loud failure (two classes, easy to spot) into a silent one (one class, wrong
# version, works until it does not). Namespace scoping has no such all-or-nothing requirement:
# each scoped copy is fully self-contained and correct regardless of what any other plugin on
# the site does.
#
# PREFIX STRATEGY: per-version (deterministic), not per-download (random)
# ------------------------------------------------------------------------------------------
# The prefix defaults to a value DERIVED FROM THE SDK VERSION (e.g. CxTrackerSdk_v1_0_0), not
# a random per-invocation string (e.g. CxTrackerSdk_a1b2c3), for three reasons:
#
#   1. Idempotency (required by this script's own spec). A build script that produces
#      different output every time it runs on the same input cannot be idempotent by
#      definition. A random prefix would mean running this script twice for the same SDK
#      version yields two different, non-reproducible dist/ trees.
#
#   2. "Two consumers who downloaded the same version should be able to coexist." With a
#      version-derived prefix, two independent downloads of SDK 1.0.0 produce BYTE-IDENTICAL
#      scoped output: same prefix, same rewritten source. If both end up loaded on one site,
#      whichever plugin's autoloader registers first "wins" -- but since the code is
#      identical, this is not a collision at all, just one of the two copies going unused.
#      There is no missing-method fatal, because there is nothing different between them. A
#      random per-download prefix would also avoid a fatal (each copy would be distinct and
#      isolated) but at the cost of loading duplicate, byte-identical code twice per site for
#      no benefit -- strictly worse, never better.
#
#   3. "A consumer upgrading should not collide with their own older copy." Version-derived
#      prefixes make this automatic: SDK 1.0.0 and 1.1.0 differ in version, so they differ in
#      prefix, so they occupy different namespaces even if both copies briefly exist on disk
#      during a plugin update. No coordination or cleanup step is required for correctness.
#
# An explicit override is still accepted as $1, for a dashboard operator who wants a
# per-download build tag for support/traceability instead of (or in addition to) the version
# (e.g. to tell two support tickets on the same version apart). The default, though, is the
# deterministic, version-derived prefix, because correctness here must not depend on remembering
# to pass a flag.
#
# WHY autoload.php INSTEAD OF (OR ALONGSIDE) A CONSUMABLE composer.json
# ------------------------------------------------------------------------------------------
# The intended consumer is a WordPress plugin author who downloaded a zip from a dashboard --
# many such plugins have no Composer tooling of their own at all, and those that do would need
# a path repository entry plus a `composer require` step just to use a handful of files. A
# single `require __DIR__ . '/plugin-tracker-sdk/autoload.php';` needs nothing else installed
# and works identically whether or not the consumer's own plugin uses Composer. A
# `composer.json` is still emitted alongside it (PSR-4, matching the scoped namespace) for the
# minority of consumers who do prefer to wire it in via Composer, but autoload.php is the
# primary, recommended integration path for this SDK's actual audience.
#
# ------------------------------------------------------------------------------------------

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
cd "${ROOT_DIR}"

SRC_DIR="${ROOT_DIR}/src"
VIEWS_DIR="${ROOT_DIR}/views"
DIST_DIR="${ROOT_DIR}/dist"

# Every directory whose *.php files are copied into the artifact, each preserved at the same path
# relative to the package root so the relative includes between them still resolve.
#
# views/ is here because Feedback\Deactivation::render() includes it as
# `__DIR__ . '/../../views/...'`. That path is only correct in the artifact because this list puts
# views/ next to src/ there too. **Removing an entry from this list does not fail the build.** It
# produces an artifact that loads, passes every verification below, and then fatals on a consumer's
# plugins.php the first time somebody clicks Deactivate -- which is why VERIFY #4 exists.
SOURCE_ROOTS=( "${SRC_DIR}" "${VIEWS_DIR}" )

# The root namespace every namespace in this SDK's src/ tree sits UNDER -- not the only one it
# has. Rewritten as a PREFIX, which is what lets one literal string cover the three root classes
# and every sub-namespace (...\Consent, ...\Storage, ...\Http, ...\Cron, ...\Privacy) in a single
# pass. See the block comment above for why that is total and unambiguous here.
OLD_NAMESPACE='Codexpert\PluginTracker'

for root in "${SOURCE_ROOTS[@]}"; do
	if [[ ! -d "${root}" ]]; then
		echo "error: source root ${root} does not exist" >&2
		exit 1
	fi
done

# ---------------------------------------------------------------------------------------------
# Determine the SDK version straight from the source of truth (Tracker::VERSION), not from
# composer.json, which deliberately carries no "version" key (this package is not tagged via
# Packagist).
# ---------------------------------------------------------------------------------------------
TRACKER_FILE="${SRC_DIR}/Tracker.php"
if [[ ! -f "${TRACKER_FILE}" ]]; then
	echo "error: ${TRACKER_FILE} not found; cannot determine SDK version" >&2
	exit 1
fi

SDK_VERSION="$(grep -oE "const VERSION = '[^']+'" "${TRACKER_FILE}" | grep -oE "[0-9][0-9A-Za-z.+_-]*" || true)"

if [[ -z "${SDK_VERSION}" ]]; then
	echo "error: could not extract Tracker::VERSION from ${TRACKER_FILE}" >&2
	exit 1
fi

# ---------------------------------------------------------------------------------------------
# Resolve the prefix: explicit override ($1) if given, otherwise derive deterministically from
# the SDK version. See the "PREFIX STRATEGY" comment above for why derived-by-default.
# ---------------------------------------------------------------------------------------------
sanitize_for_namespace() {
	# A PHP namespace segment may contain only [A-Za-z0-9_] and must not start with a digit, so
	# every non-word run in a version string collapses to a single underscore.
	#
	# Collapsing alone is LOSSY, and lossy is not acceptable here. "1.0.0-hotfix" and
	# "1.0.0+hotfix" are different releases carrying different code, and both collapse to
	# "1_0_0_hotfix" -- so two distinct versions would share a namespace, and PHP would load
	# whichever registered first. That is exactly the version-skew this whole script exists to
	# prevent, reintroduced by the naming step.
	#
	# A short digest of the RAW version string is therefore appended. It is deterministic (so the
	# build stays idempotent and two consumers on the same version still converge on one prefix)
	# and it is injective in practice (so two different versions cannot collide).
	local raw="$1"
	local collapsed
	local digest

	collapsed="$(printf '%s' "${raw}" | sed -E 's/[^A-Za-z0-9]+/_/g')"

	if command -v sha256sum >/dev/null 2>&1; then
		digest="$(printf '%s' "${raw}" | sha256sum | cut -c1-6)"
	elif command -v shasum >/dev/null 2>&1; then
		digest="$(printf '%s' "${raw}" | shasum -a 256 | cut -c1-6)"
	else
		echo "error: need sha256sum or shasum to derive a collision-free namespace prefix" >&2
		exit 1
	fi

	printf '%s_%s' "${collapsed}" "${digest}"
}

PREFIX="${1:-}"
if [[ -z "${PREFIX}" ]]; then
	PREFIX="CxTrackerSdk_v$(sanitize_for_namespace "${SDK_VERSION}")"
fi

if [[ ! "${PREFIX}" =~ ^[A-Za-z_][A-Za-z0-9_]*$ ]]; then
	echo "error: prefix '${PREFIX}' is not a valid single PHP namespace segment" \
		"(must match ^[A-Za-z_][A-Za-z0-9_]*\$)" >&2
	exit 1
fi

NEW_NAMESPACE="${PREFIX}"
OUT_DIR="${DIST_DIR}/${PREFIX}"
OUT_SRC_DIR="${OUT_DIR}/src"

echo "==> SDK version:      ${SDK_VERSION}"
echo "==> Scoped namespace: ${NEW_NAMESPACE}   (was: ${OLD_NAMESPACE})"
echo "==> Output directory: ${OUT_DIR}"

# ---------------------------------------------------------------------------------------------
# VERIFY #0: the distribution PHP floor, BEFORE anything is built.
#
# VERIFY #2 runs `php -l` and VERIFY #3 loads the classes -- both on whatever PHP the build box
# happens to have, which is currently 8.5. A `match`, an arrow function or a `?->` in src/ passes
# both of those and then fatals on a consumer running 7.2. The only thing in this repo that knows
# about the floor is phpstan.neon (phpVersion: 70200), and the artifact producer was not running it.
#
# So it runs here, and a failure aborts the build rather than shipping something that cannot load.
# Skipped with a loud warning when phpstan is absent (a consumer building from a dist tarball has no
# dev dependencies), because refusing to build at all in that case would be worse.
# ---------------------------------------------------------------------------------------------
if [[ -x "${ROOT_DIR}/vendor/bin/phpstan" ]]; then
	echo "==> Verifying the documented PHP floor (phpstan.neon phpVersion) before building..."

	if ! ( cd "${ROOT_DIR}" && vendor/bin/phpstan analyse --no-progress --memory-limit=1G >/dev/null 2>&1 ); then
		echo "error: static analysis fails against the distribution PHP floor -- refusing to build." >&2
		echo "       Run 'composer analyze' to see what would break on a consumer's older PHP." >&2
		exit 1
	fi

	echo "    floor check passed"
else
	echo "==> WARNING: phpstan not installed, skipping the PHP floor check." >&2
	echo "             The artifact may contain syntax newer than the documented floor." >&2
fi

# ---------------------------------------------------------------------------------------------
# Idempotent: wipe only this prefix's own output directory before regenerating it. Re-running
# the script with the same (or auto-derived, same-version) arguments reproduces byte-identical
# output; it does not touch any other version's build that might already sit under dist/.
# ---------------------------------------------------------------------------------------------
rm -rf "${OUT_DIR}"
mkdir -p "${OUT_SRC_DIR}"

# ---------------------------------------------------------------------------------------------
# Self-check baseline: count occurrences of the unscoped namespace in the ORIGINAL source
# before rewriting, so the rewrite step below can assert it replaced exactly that many -- not
# "some", not "at least one". A silent partial rewrite (e.g. because a file was skipped) would
# otherwise pass a naive "did it run" check while still leaking the unscoped namespace.
# ---------------------------------------------------------------------------------------------
EXPECTED_TOTAL="$(grep -rF -o "${OLD_NAMESPACE}" "${SOURCE_ROOTS[@]}" | wc -l | tr -d ' ')"
echo "==> Expecting to rewrite ${EXPECTED_TOTAL} occurrence(s) of the '${OLD_NAMESPACE}' prefix"
echo "    across: ${SOURCE_ROOTS[*]#"${ROOT_DIR}"/}"
echo "    (namespace declarations, use imports and @package tags -- all prefix matches)"

if [[ "${EXPECTED_TOTAL}" -eq 0 ]]; then
	echo "error: found zero occurrences of '${OLD_NAMESPACE}' in the source roots -- refusing to" \
		"build an empty/no-op scoped copy; something is wrong with SOURCE_ROOTS or OLD_NAMESPACE" >&2
	exit 1
fi

# ---------------------------------------------------------------------------------------------
# Rewrite. Plain str_replace() on the exact namespace string, run through PHP rather than sed,
# specifically to avoid backslash-escaping mistakes in a shell regex (the namespace separator
# IS a backslash) -- str_replace() does no regex interpretation of either its search or
# replacement string, so there is nothing to escape incorrectly.
# ---------------------------------------------------------------------------------------------
ACTUAL_TOTAL=0
for root in "${SOURCE_ROOTS[@]}"; do
	while IFS= read -r -d '' file; do
		# Relative to the PACKAGE root, not to the source root, so src/Feedback/Deactivation.php and
		# views/feedback/modal.php land at the same depth relative to each other in the artifact as
		# they are here. That is what keeps the `__DIR__ . '/../../views/...'` includes resolving.
		rel="${file#"${ROOT_DIR}"/}"
		dest="${OUT_DIR}/${rel}"
		mkdir -p "$(dirname "${dest}")"

		count="$(php -r '
			list(, $old, $new, $src, $dst) = $argv;
			$code = file_get_contents($src);
			if (false === $code) {
				fwrite(STDERR, "read failed: $src\n");
				exit(1);
			}
			$n = 0;
			$code = str_replace($old, $new, $code, $n);
			if (false === file_put_contents($dst, $code)) {
				fwrite(STDERR, "write failed: $dst\n");
				exit(1);
			}
			echo $n;
		' -- "${OLD_NAMESPACE}" "${NEW_NAMESPACE}" "${file}" "${dest}")"

		echo "    ${rel}: ${count} occurrence(s) rewritten"
		ACTUAL_TOTAL=$((ACTUAL_TOTAL + count))
	done < <(find "${root}" -name '*.php' -print0)
done

if [[ "${ACTUAL_TOTAL}" -ne "${EXPECTED_TOTAL}" ]]; then
	echo "error: rewrote ${ACTUAL_TOTAL} occurrence(s) but expected ${EXPECTED_TOTAL} -- aborting" >&2
	exit 1
fi
echo "==> Rewrote ${ACTUAL_TOTAL} occurrence(s) as expected"

# ---------------------------------------------------------------------------------------------
# Emit autoload.php -- the primary integration path. See the block comment above for why this,
# rather than requiring Composer, is the right default for this SDK's actual consumers.
# ---------------------------------------------------------------------------------------------
cat > "${OUT_DIR}/autoload.php" <<'PHPEOF'
<?php
/**
 * Autoloader for a namespace-scoped, self-contained copy of the Plugin Tracker SDK.
 *
 * GENERATED by bin/build-dist.sh from codexpertio/plugin-tracker-sdk __CX_SDK_VERSION__.
 * Do not edit by hand -- re-run the build script instead.
 *
 * Why this exists instead of `composer require`-ing the SDK directly: this package is
 * downloaded from a dashboard, not installed via Packagist, and is namespace-scoped at build
 * time so that multiple plugins on one site can each bundle their own copy -- possibly at
 * different versions -- without their classes colliding. See bin/build-dist.sh for the full
 * reasoning.
 *
 * Usage from a consumer plugin, no Composer required:
 *
 *     require __DIR__ . '/vendor/plugin-tracker-sdk/autoload.php';
 *
 *     __CX_PREFIX__\Tracker::init( array(
 *         'project' => 'pt_proj_...',
 *         'plugin'  => 'my-plugin',
 *         'version' => '1.0.0',
 *         'file'    => __FILE__,
 *         'enabled' => true,
 *     ) );
 */

spl_autoload_register(
	function ( $class ) {
		$prefix = '__CX_PREFIX__\\';
		$len    = strlen( $prefix );

		if ( 0 !== strncmp( $prefix, $class, $len ) ) {
			return;
		}

		$relative = substr( $class, $len );
		$file     = __DIR__ . '/src/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_file( $file ) ) {
			require $file;
		}
	}
);
PHPEOF
sed -i \
	-e "s/__CX_PREFIX__/${NEW_NAMESPACE}/g" \
	-e "s/__CX_SDK_VERSION__/${SDK_VERSION}/g" \
	"${OUT_DIR}/autoload.php"

# ---------------------------------------------------------------------------------------------
# Emit composer.json -- secondary path, for consumers who do use Composer (e.g. via a path
# repository) and would rather `composer require` than hand-manage a `require`.
# ---------------------------------------------------------------------------------------------
# Translations ship with the artifact. I18n resolves languages/ relative to src/'s parent, so a
# scoped copy without this directory would silently fall back to English -- and the consent prompt
# is the one piece of UI a WordPress.org reviewer reads.
if [ -d "${ROOT_DIR}/languages" ]; then
	mkdir -p "${OUT_DIR}/languages"
	# .pot for translators, .mo for runtime. .po sources are not shipped.
	find "${ROOT_DIR}/languages" -maxdepth 1 -type f \( -name '*.pot' -o -name '*.mo' \) \
		-exec cp {} "${OUT_DIR}/languages/" \;
else
	echo "error: ${ROOT_DIR}/languages is missing; refusing to build an untranslatable artifact" >&2
	exit 1
fi

# The artifact is GPL-licensed code handed to a third party as a zip, so it has to carry its
# license text. Without this the distributed copy asserts GPL-3.0-or-later in composer.json while
# shipping no license at all.
if [ -f "${ROOT_DIR}/LICENSE" ]; then
	cp "${ROOT_DIR}/LICENSE" "${OUT_DIR}/LICENSE"
else
	echo "error: ${ROOT_DIR}/LICENSE is missing; refusing to build an unlicensed artifact" >&2
	exit 1
fi

# ---------------------------------------------------------------------------------------------
# The consumer-facing documents ship with the artifact.
# ---------------------------------------------------------------------------------------------
# readme-txt-block.md is the reason this section exists. It is the copy-paste WordPress.org
# disclosure block -- the one document here written FOR the consumer rather than for whoever
# maintains this package -- and the dashboard's integration guide sent authors to it as a path
# inside their download. It was not in the download. WordPress.org requires a plugin that
# transmits to a third-party service to declare it, and an undisclosed external service is one of
# the commoner reasons a plugin is pulled, so the one obligation whose consequence lands on the
# author pointed at a file they did not have.
#
# The whole directory ships rather than that one file, and the extra ~90K of markdown is worth it
# for a specific reason: every one of these answers a question a consumer or a WP.org reviewer
# actually asks of THEIR plugin, not of ours. CONSENT.md is the consent story a reviewer is
# reading under the consumer's name; FEEDBACK.md and EVENTS.md are what the disclosure block is
# enumerating, so an author who wants to check the block against the code has the code's own
# description beside it; WIRE.md says where the data goes. Splitting them would mean deciding
# which half of a disclosure an author is allowed to verify.
#
# Not optional. A missing docs/ is a build failure rather than a warning, for the same reason the
# missing-LICENSE and missing-languages branches above are: a silently incomplete artifact is
# indistinguishable from a complete one until somebody needs the part that is absent.
if [ -d "${ROOT_DIR}/docs" ]; then
	mkdir -p "${OUT_DIR}/docs"
	find "${ROOT_DIR}/docs" -maxdepth 1 -type f -name '*.md' -exec cp {} "${OUT_DIR}/docs/" \;
else
	echo "error: ${ROOT_DIR}/docs is missing; refusing to build an artifact with no disclosure block" >&2
	exit 1
fi

# The one file the integration guide names by path. Asserted separately from the directory copy
# above, because a docs/ that exists but has lost this file is the failure that matters and the
# directory check would pass straight through it.
if [ ! -f "${OUT_DIR}/docs/readme-txt-block.md" ]; then
	echo "error: docs/readme-txt-block.md did not reach the artifact; it is what an author is" \
		"told to open to write their WordPress.org disclosure" >&2
	exit 1
fi

cat > "${OUT_DIR}/composer.json" <<'JSONEOF'
{
    "name": "codexpertio/plugin-tracker-sdk-scoped",
    "description": "Namespace-scoped build of codexpertio/plugin-tracker-sdk __CX_SDK_VERSION__, generated by bin/build-dist.sh. Not published to Packagist; provided for consumers who prefer Composer autoloading (e.g. via a path repository) over the bundled autoload.php.",
    "type": "library",
    "license": "GPL-3.0-or-later",
    "autoload": {
        "psr-4": {
            "__CX_PREFIX__\\": "src/"
        }
    },
    "require": {
        "php": ">=7.2"
    }
}
JSONEOF
sed -i \
	-e "s/__CX_PREFIX__/${NEW_NAMESPACE}/g" \
	-e "s/__CX_SDK_VERSION__/${SDK_VERSION}/g" \
	"${OUT_DIR}/composer.json"

if ! php -r 'json_decode(file_get_contents($argv[1]), true) !== null || exit(1);' -- "${OUT_DIR}/composer.json"; then
	echo "error: generated composer.json is not valid JSON" >&2
	exit 1
fi

# ---------------------------------------------------------------------------------------------
# VERIFY #1: zero occurrences of the original, unscoped namespace anywhere in the output.
# grep -F: fixed-string match, so the literal backslash in OLD_NAMESPACE needs no regex
# escaping. `if grep ...; then fail; fi` is deliberate -- under `set -e`, a command used
# directly as an `if` condition is exempt from errexit, so grep finding nothing (exit 1) does
# not abort the script; only finding a match (exit 0, the failure case here) does.
# ---------------------------------------------------------------------------------------------
echo "==> Verifying zero occurrences of the unscoped namespace remain..."
if grep -rFl "${OLD_NAMESPACE}" "${OUT_DIR}"; then
	echo "error: the unscoped namespace '${OLD_NAMESPACE}' still appears in the file(s) listed" \
		"above under ${OUT_DIR} -- scoping is incomplete" >&2
	exit 1
fi
echo "    OK: '${OLD_NAMESPACE}' does not appear anywhere under ${OUT_DIR}"

# ---------------------------------------------------------------------------------------------
# VERIFY #2: every generated PHP file parses cleanly.
# ---------------------------------------------------------------------------------------------
echo "==> Verifying every generated PHP file passes php -l..."
LINT_FAILED=0
while IFS= read -r -d '' file; do
	if ! lint_out="$(php -l "${file}" 2>&1)"; then
		echo "    LINT FAILED: ${file}" >&2
		echo "${lint_out}" >&2
		LINT_FAILED=1
	fi
done < <(find "${OUT_DIR}" -name '*.php' -print0)

if [[ "${LINT_FAILED}" -ne 0 ]]; then
	echo "error: php -l failed for one or more generated files (see above)" >&2
	exit 1
fi
echo "    OK: every generated .php file passes php -l"

# ---------------------------------------------------------------------------------------------
# VERIFY #4: every `include __DIR__ . '...'` target actually exists in the artifact.
#
# The one failure mode none of the other checks can see. A view that was not copied leaves the
# artifact scoped (VERIFY #1), parsing (VERIFY #2) and loading (VERIFY #3) perfectly, because
# nothing in a load proof calls render(). The build would report success and the SDK would fatal
# on a consumer's plugins.php the first time somebody clicked Deactivate.
#
# Resolved the same way PHP will resolve it: relative to the including file's own directory.
# ---------------------------------------------------------------------------------------------
echo "==> Verifying every __DIR__-relative include resolves inside the artifact..."
INCLUDE_MISSING=0
while IFS= read -r -d '' file; do
	while IFS= read -r target; do
		resolved="$(cd "$(dirname "${file}")" && printf '%s' "$(realpath -m "${target}")")"

		if [[ ! -f "${resolved}" ]]; then
			echo "    MISSING: ${file#"${OUT_DIR}"/} includes '${target}' -> ${resolved}" >&2
			INCLUDE_MISSING=1
		fi
	# Comment lines are dropped first. The generated autoload.php documents its own integration in
	# a docblock -- `*     require __DIR__ . '/vendor/plugin-tracker-sdk/autoload.php';` -- which is
	# an instruction to the consumer about THEIR tree, not an include in ours, and matching it made
	# this check fail on a correct artifact.
	done < <(grep -vE "^[[:space:]]*(\*|//|#)" "${file}" \
		| grep -oE "(include|require)(_once)? __DIR__ \. '[^']+'" \
		| sed -E "s/.*__DIR__ \. '([^']+)'.*/.\1/")
done < <(find "${OUT_DIR}" -name '*.php' -print0)

if [[ "${INCLUDE_MISSING}" -ne 0 ]]; then
	echo "error: an included file was not shipped into the artifact (see above). If you added a" \
		"directory of templates or partials, add it to SOURCE_ROOTS near the top of this script." >&2
	exit 1
fi
echo "    OK: every __DIR__-relative include resolves to a file that shipped"

# ---------------------------------------------------------------------------------------------
# VERIFY #3: end-to-end load proof.
#
# Requiring ONLY the generated autoload.php -- no Composer, no WordPress, which is exactly what a
# bundling consumer does -- resolve every scoped class through the generated PSR-4 autoloader and
# construct one object from EACH sub-namespace.
#
# This is the check that actually catches a broken rewrite. VERIFY #1 proves the old string is
# gone and VERIFY #2 proves each file parses, but a file can be free of the old string AND
# syntactically valid while still being unloadable: a dropped directory level, or a `use` import
# rewritten inconsistently with the `namespace` it belongs to, produces exactly that. Constructing
# the objects goes one step further than class_exists() -- the constructor type hints
# (Consent\Notice takes Config + Gate, Privacy\Personal_Data::register takes Config + Gate +
# Install) are resolved against the SCOPED FQCNs at call time, so a cross-namespace `use` that did
# not survive the rewrite fails here with a TypeError instead of shipping.
#
# A silently broken scoped copy is a production fatal inside somebody else's plugin, which is the
# one failure mode this whole script exists to prevent. It gets a real test.
# ---------------------------------------------------------------------------------------------
echo "==> Verifying the scoped copy actually loads (end-to-end)..."

PROOF_FILE="$(mktemp "${TMPDIR:-/tmp}/cx-sdk-load-proof-XXXXXX.php")"
trap 'rm -f "${PROOF_FILE}"' EXIT

cat > "${PROOF_FILE}" <<'PROOFEOF'
<?php
/**
 * Load proof for a scoped SDK build. Requires nothing but the generated autoload.php.
 */

require __DIR__ . '/autoload.php';

$prefix   = '__CX_PREFIX__';
$expected = '__CX_SDK_VERSION__';
$fail     = 0;

function proof_ok( $msg ) {
	echo "    OK   $msg\n";
}

function proof_fail( $msg ) {
	fwrite( STDERR, "    FAIL $msg\n" );
	return 1;
}

// 1. Every class resolves through the generated PSR-4 autoloader, including one per subdirectory.
$classes = array(
	'Tracker',
	'Config',
	'Event',
	'Consent\\Gate',
	'Consent\\Notice',
	'Storage\\Queue',
	'I18n',
	'Feedback\\Deactivation',
	'Lifecycle',
	'Storage\\Install',
	'Http\\Transport',
	'Cron\\Scheduler',
	'Privacy\\Personal_Data',
);

foreach ( $classes as $relative ) {
	$fqcn = $prefix . '\\' . $relative;
	if ( class_exists( $fqcn ) ) {
		proof_ok( "autoloaded  $fqcn" );
	} else {
		$fail = proof_fail( "cannot autoload  $fqcn" );
	}
}

if ( $fail ) {
	exit( 1 );
}

// 2. The unscoped namespace must NOT be reachable from this copy -- that is the entire point of
//    scoping. If it is, the rewrite did not actually happen.
if ( class_exists( 'Codexpert\\PluginTracker\\Tracker' ) ) {
	$fail = proof_fail( 'unscoped Codexpert\\PluginTracker\\Tracker is loadable; scoping did not take effect' );
} else {
	proof_ok( 'unscoped Codexpert\\PluginTracker\\* is NOT loadable' );
}

// 3. The version constant survived the rewrite intact.
$tracker_class = $prefix . '\\Tracker';
$actual        = constant( $tracker_class . '::VERSION' );

if ( $expected === $actual ) {
	proof_ok( "Tracker::VERSION === '$expected'" );
} else {
	$fail = proof_fail( "Tracker::VERSION is '$actual', expected '$expected'" );
}

// 3b. I18n must resolve languages/ INSIDE the scoped copy. If build-dist.sh ever stops shipping
//     languages/, or the copy is laid out differently, the SDK silently falls back to English --
//     and the consent prompt is the one piece of UI a WordPress.org reviewer reads.
$i18n_class = $prefix . '\\I18n';
$lang_dir   = $i18n_class::languages_dir();

if ( is_dir( $lang_dir ) && is_readable( $lang_dir . 'plugin-tracker-sdk.pot' ) ) {
	proof_ok( 'I18n::languages_dir() resolves inside the scoped copy and the .pot is present' );
} else {
	$fail = proof_fail( "I18n::languages_dir() gave '$lang_dir' with no readable .pot" );
}

// 4. Construct one object from each sub-namespace. This resolves the cross-namespace `use`
//    imports through real constructor type hints, not just class_exists().
$config_class = $prefix . '\\Config';
// Config validates that `file` is a real MAIN plugin file, because the SDK registers the activation
// and deactivation hooks itself and a wrong path leaves them silently never firing. This proof
// therefore needs a file that actually carries a plugin header -- this script does not.
$fake_plugin = tempnam( sys_get_temp_dir(), 'cx-proof-plugin-' ) . '.php';
file_put_contents( $fake_plugin, "<?php\n/**\n * Plugin Name: Scoped Load Proof\n */\n" );

$config       = new $config_class(
	array(
		'project'  => 'pt_proj_loadproof',
		'plugin'   => 'scoped-load-proof',
		'version'  => '1.0.0',
		// 32 hex characters, matching Config::HASH_PATTERN.
		'hash'     => 'abcdef0123456789abcdef0123456789',
		'file'     => $fake_plugin,
		'enabled'  => true,
		'endpoint' => 'https://ingest.example.test/wp-json/plugin-tracker/v1',
	)
);

if ( $config->is_valid() ) {
	proof_ok( 'constructed  ' . $config_class . ' (valid)' );
} else {
	$fail = proof_fail( $config_class . ' rejected a valid config: ' . implode( ' | ', $config->errors() ) );
}

$gate_class = $prefix . '\\Consent\\Gate';
$gate       = new $gate_class( $config );
proof_ok( 'constructed  ' . $gate_class . ' (Config type hint resolved across namespaces)' );

// Notice's constructor takes BOTH Config and Gate, i.e. two imports from two other namespaces.
$notice_class = $prefix . '\\Consent\\Notice';
new $notice_class( $config, $gate );
proof_ok( 'constructed  ' . $notice_class . ' (Config + Gate type hints resolved)' );

foreach ( array( 'Storage\\Queue', 'Storage\\Install', 'Http\\Transport', 'Cron\\Scheduler' ) as $relative ) {
	$class = $prefix . '\\' . $relative;
	new $class( $config );
	proof_ok( 'constructed  ' . $class );
}

// Personal_Data::register() type-hints Config + Gate + Install. It returns early because
// add_filter() is undefined outside WordPress, which is exactly the no-WP path we want to prove
// is reachable without fataling.
$install_class = $prefix . '\\Storage\\Install';
$privacy_class = $prefix . '\\Privacy\\Personal_Data';
$privacy_class::register( $config, $gate, new $install_class( $config ) );
proof_ok( 'called       ' . $privacy_class . '::register() (Config + Gate + Install type hints resolved)' );

// 5. A behavioural spot-check, so the proof is not purely structural.
$event_class = $prefix . '\\Event';
if ( $event_class::is_allowed( 'install' ) && ! $event_class::is_allowed( 'not-an-event' ) ) {
	proof_ok( 'behaviour    ' . $event_class . '::is_allowed() still enforces the allow-list' );
} else {
	$fail = proof_fail( $event_class . '::is_allowed() misbehaved after scoping' );
}

@unlink( $fake_plugin );

exit( $fail ? 1 : 0 );
PROOFEOF

sed -i \
	-e "s/__CX_PREFIX__/${NEW_NAMESPACE}/g" \
	-e "s/__CX_SDK_VERSION__/${SDK_VERSION}/g" \
	"${PROOF_FILE}"

# Run it from inside OUT_DIR so the proof's `require __DIR__ . '/autoload.php'` resolves to the
# generated autoloader and nothing else. -d error_reporting=-1 so any notice/warning is visible
# rather than swallowed.
cp "${PROOF_FILE}" "${OUT_DIR}/.load-proof.php"
if ! php -d error_reporting=-1 -d display_errors=1 "${OUT_DIR}/.load-proof.php"; then
	rm -f "${OUT_DIR}/.load-proof.php"
	echo "error: the scoped copy under ${OUT_DIR} does not load correctly (see above)" >&2
	exit 1
fi
rm -f "${OUT_DIR}/.load-proof.php"
echo "    OK: the scoped copy loads and works with autoload.php alone"

echo
echo "==> Build complete: ${OUT_DIR}"
if command -v tree >/dev/null 2>&1; then
	tree "${OUT_DIR}"
else
	find "${OUT_DIR}" -type f | sort
fi
