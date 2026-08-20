<div align="center">

# Plugin Tracker SDK

[![Version](https://img.shields.io/badge/version-1.3.1-blue.svg)](src/Tracker.php)
[![PHP](https://img.shields.io/badge/PHP-7.2%2B-777bb4.svg)](composer.json)
[![License](https://img.shields.io/badge/license-GPL%20v3%20or%20later-green.svg)](LICENSE)

Opt-in telemetry for WordPress plugins. Consent-gated by default, anonymous by construction.

</div>

`codexpertio/plugin-tracker-sdk` — namespace `Codexpert\PluginTracker\`. Developed inside the Plugin
Tracker monorepo, which is private, and mirrored to this repository; issue references such as #40
below point into that private tracker and will 404 for anyone outside it. Kept anyway, because they
are the only record of why a decision was taken.

> [!IMPORTANT]
> **This SDK is the only part of this project exposed to WordPress.org review.** `plugin-tracker`
> itself is private and unlisted, which exempts *it* — not the plugins that bundle this. Any
> third-party plugin embedding this SDK and listed on WP.org is reviewed against the phoning-home
> rules, and this SDK is the mechanism by which it would fail them. Every consumer inherits that
> exposure, so the compliant path is the default one and the gates are fail-closed.

## Installing it

Two routes, and since the namespace rewrite was dropped **they end at the same class name**. That is
new: a scoped download used to resolve `CxTrackerSdk_v<version>_<digest>\Tracker` while Composer
resolved `Codexpert\PluginTracker\Tracker`, and pasting the wrong snippet was a fatal on activation.
Identical code, identical namespace, either way now.

```bash
composer require codexpertio/plugin-tracker-sdk:^1.2
```

Pin the constraint — `:^1.2` — for anything shipped to sites you do not control. Composer falls back
to the default branch when a constraint cannot resolve, and `dev-master` inside a distributed plugin
is a moving target on somebody else's site.

One caveat if you go checking: `packagist.org/packages/codexpertio/plugin-tracker-sdk.json` reports
`crawledAt: null` and lists only `dev-*` branches, which reads as though nothing is published. It is
not the endpoint Composer uses. `repo.packagist.org/p2/...` lists `v1.2.0`, and a real
`composer require codexpertio/plugin-tracker-sdk:^1.2` locks it. The repository is connected to
Packagist through its GitHub integration and picks up pushed tags on its own, which is why the
release workflow does not notify it.

The alternative is the **release asset**, unzipped beside your main plugin file:

```
https://github.com/codexpertio/plugin-tracker-sdk/releases/latest/download/plugin-tracker-sdk.zip
```

It carries its own `autoload.php`, so it needs no Composer, and it unpacks to `plugin-tracker-sdk/`
so the snippet's require path resolves without renaming anything — see
[what the download contains](#what-the-download-contains).

Neither route protects you from another plugin bundling a different version of this SDK; that is
what [Distribution and the collision problem](#distribution-and-the-collision-problem) is about, and
it is now the same trade whichever route you take.

The dashboard's snippet generator has a switch for which route you took. The two snippets differ only
in how they reach `autoload.php`, not in what they call.

## Quick start

There is no hand-written bootstrap. The dashboard generates a code snippet and the plugin author pastes
it into their **main plugin file**, above their own plugin's bootstrap. That snippet is the entire
integration -- no hooks to register, no `track()` calls for lifecycle events, no settings screen to build.
Full detail, and why every part of it is shaped the way it is: [`docs/SNIPPET.md`](docs/SNIPPET.md).

Shown here for the **downloaded** copy. Note what it does *not* contain: a version, or a class name.
That mattered more when the namespace carried the version and a stale snippet meant `Class … not
found` on an author's users' sites after an upgrade. The namespace is fixed now, so hard-coding the
class name would work — `autoload.php` still returns it, and the snippet still uses what it returns,
because an author who never names a class cannot name a stale one whatever we change later.

```php
if ( ! function_exists( 'my_plugin_tracker' ) ) {

	/**
	 * @return object|null The SDK's Tracker, or null when the SDK is not present.
	 */
	function my_plugin_tracker() {
		global $my_plugin_tracker;

		if ( ! isset( $my_plugin_tracker ) ) {
			$my_plugin_tracker = false;

			// Ask the SDK for its own class name rather than naming it here.
			$tracker_autoload = __DIR__ . '/plugin-tracker-sdk/autoload.php';
			$tracker_class    = is_file( $tracker_autoload ) ? require $tracker_autoload : '';

			// Every way the SDK can be missing or broken ends up here as a no-op instead of a
			// fatal. A telemetry SDK must never be the reason a site goes down.
			if ( is_string( $tracker_class ) && class_exists( $tracker_class ) ) {
				$my_plugin_tracker = $tracker_class::init(
					array(
						'hash'    => '4f3c2a1b9e8d7c6b5a4f3e2d1c0b9a87', // dashboard-issued, public
						'file'    => __FILE__, // the MAIN plugin file, see docs/SNIPPET.md
						'enabled' => true,     // consent gate 1: you, the author, enable it
					)
				);
			}
		}

		return $my_plugin_tracker ? $my_plugin_tracker : null;
	}

	my_plugin_tracker();
}

// ... your own plugin's bootstrap follows.
```

On **Composer** the shape differs in two places: `vendor/autoload.php` is required above the guard,
and `$tracker_class` is assigned the SDK's own unscoped `Codexpert\PluginTracker\Tracker` outright,
since nothing rewrites it on that path. [`docs/SNIPPET.md`](docs/SNIPPET.md) has both in full.

The moment `init()` succeeds, the SDK registers its own `register_activation_hook()`,
`register_deactivation_hook()` and `init` listeners, and raises `install`, `activate`, `version`,
`compat` and `deactivation` itself.

`file` must be the plugin's own main file, and it does two jobs. It is what
`register_activation_hook()` keys on -- pass a class file or an include and the computed basename is
one WordPress never fires, so those hooks silently never run: nothing errors, data just never
arrives. It is also where the SDK **reads the plugin's slug, name and version from**, so those are
not arguments you pass or remember to keep in sync. See [docs/SNIPPET.md](docs/SNIPPET.md#identity)
for how each is derived and when you would still override one.

The only thing left to call yourself is a named feature, because only you know what your features are:

```php
$tracker = my_plugin_tracker();

if ( $tracker ) {
	// 'feature' as a bare string, so this line does not name the SDK's namespace either.
	$tracker->track( 'feature', array( 'name' => 'csv_export' ) );
}
```

`track()` only ever writes to a local queue. It never makes a network call, so it is safe anywhere --
including from an activation hook.

`init()` returns `null` on invalid config rather than throwing. Your users' sites must not
white-screen because our SDK was misconfigured; the errors surface as an admin notice when
`WP_DEBUG` is on.

Deactivating shows a feedback dialog on this plugin's row in `plugins.php`, asking why -- a separate
consent basis, on a separate route, that never blocks deactivation. Full detail:
[`docs/FEEDBACK.md`](docs/FEEDBACK.md).

## Two consent gates, and nothing moves without both

| Gate | Who | How |
|---|---|---|
| 1. Project enabled | you, the plugin author | the `enabled` config flag |
| 2. Site opt-in | the site administrator | explicit, per site, recorded with a policy version |

Plus a site-level kill switch (`CX_TRACKER_DISABLE`) and a final `cx_tracker_consent` filter.

With no consent, `track()` does not even write to the local queue. Buffering "in case consent
arrives later" would mean holding data the administrator never agreed to us holding.

Pass `name`. It is what the consent prompt shows a site administrator, and the prompt is the one
piece of UI a WordPress.org reviewer reads — "my-plugin can send anonymous usage data" reads like a
bug. Omit it and the SDK prettifies the slug, which is presentable but never quite right: no
transformation turns `wp-seo-tools` into "WP SEO Tools". `plugin` remains the identifier behind every
option key, cron hook and nonce, so the display name can change freely without orphaning stored
state.

Full detail: [`docs/CONSENT.md`](docs/CONSENT.md).

The deactivation-feedback modal (below) is deliberately **not** behind either gate -- it has its own,
separate consent basis. See [`docs/FEEDBACK.md`](docs/FEEDBACK.md).

## What it sends

Six events — `install`, `activate`, `version`, `compat`, `feature`, `deactivation` — each carrying
an anonymous install ID, your plugin's version, the WordPress and PHP versions, the site locale,
whether the site is multisite, and — **added in 1.2.0, which is why `schema` went to 2** — the web
server's name and the active theme's slug.

Both of those last two are bounded readings rather than raw environment, and the boundary is the
point. `Environment::server()` matches `SERVER_SOFTWARE` against a closed list and returns a literal
from it, so `Apache/2.4.41 (Ubuntu)` transmits as `apache` and the version and distribution never
leave the site. `theme` is the stylesheet **slug** only; its version and parent go with deactivation
feedback, where a bug report wants the whole picture.

**Five of the six are automatic.** [`Lifecycle`](src/Lifecycle.php) fires `install` and `activate` from
the `register_activation_hook()` it registers itself, `deactivation` from the `register_deactivation_hook()`
next to it, and `version`/`compat` from an `init` listener that detects drift. **`feature` is the only one
you call yourself** -- `$tracker->track( Event::FEATURE, [...] )` -- because only you know what your
plugin's features are. Full detail per event: [`docs/EVENTS.md`](docs/EVENTS.md).

**Never**, *on this stream*: the site address, an email, a username, an IP, or any content. The
active-plugin list is refused here too, despite being the most-requested telemetry field, because
the set of active plugins is close to unique per site and would re-identify a site the anonymous ID
exists to protect.

The scoping matters and is not pedantry. The **deactivation-feedback** payload is a different
payload on a different route with its own consent basis, and it *does* carry the site address, the
theme's version and parent, and the active-plugin list — because it is identified by design, the administrator
reads an itemised list of exactly those values in the dialog, and they press the button anyway. It
still never carries an email, a username, or the anonymous install ID; that last omission is the
join-key rule, and it is what stops the two streams from being correlatable. See
[`docs/FEEDBACK.md`](docs/FEEDBACK.md) for the full field-by-field contract and for why the
plugin-list decision went the other way there.

The install ID is `hash_hmac( 'sha256', home_url(), $local_salt )` where the salt is generated on
the site and never transmitted — so it cannot be reversed back to a site URL, including by us.
Hashing the URL alone would be reversible in practice, since the set of WordPress site URLs is
enumerable.

Every field carries an explicit keep/drop decision in [`docs/EVENTS.md`](docs/EVENTS.md), which is
the frozen contract. [`docs/readme-txt-block.md`](docs/readme-txt-block.md) is a paste-ready
`readme.txt` "External services" block for your own plugin — the section WP.org reviewers look for.

## Layout

```
src/
├── Tracker.php               # the facade, and the only class you need
├── Config.php                # validated construction
├── Event.php                 # the closed event allow-list
├── Lifecycle.php             # registers activation/deactivation/init; fires the five automatic events
├── I18n.php                  # loads the SDK's own text domain from languages/
├── Consent/Gate.php          # the double gate
├── Consent/Notice.php        # reusable opt-in prompt
├── Feedback/Deactivation.php # the deactivation-feedback modal, a separate consent basis
├── Storage/Queue.php         # bounded local buffer
├── Storage/Install.php       # the anonymous install ID
├── Http/Transport.php        # requests, and response-envelope resolution
├── Cron/Scheduler.php        # jittered scheduled flush
└── Privacy/Personal_Data.php # WP exporter/eraser registration

views/                        # every subdirectory mirrors the namespace segment it serves
├── consent/
│   ├── prompt.php            # the opt-in admin notice
│   └── server-notice.php     # advisory/deprecation notice from the ingestion response
└── feedback/
    ├── modal.php             # the dialog's scoped stylesheet and markup
    └── behaviour.php         # the inline script that intercepts the Deactivate link

languages/plugin-tracker-sdk.pot  # sibling of src/, kept in the release archive
```

`views/` sits outside `src/` because `src/` is the PSR-4 class tree and these files declare no
class. **`src/` emits no markup at all** — a rule worth stating as a rule, because "no *substantial*
markup" is an argument every time and this is checkable (`grep -rl '?>' src/` returns nothing). They
are included through `__DIR__ . '/../../views/<segment>/'`,
which only resolves in a distributed copy because `.gitattributes` does **not** `export-ignore`
`views/`, so the archive keeps it at the same depth relative to `src/`. Adding a directory of
templates and then export-ignoring it produces an archive that loads, passes every other check, and
then fatals on a consumer's `plugins.php` — so the build has a dedicated verification step (VERIFY
#4) that resolves every `__DIR__`-relative include against the artifact and fails if one is
missing.

The views are deliberately **not** overridable: no filter selects their path. The itemised
disclosure in `modal.php` is generated from the same `site_fields()` the payload transmits, and a
test asserts every transmitted value appears there — an override seam would let a consumer ship a
dialog that under-discloses. Wording and translation are served by the
`cx_tracker_feedback_strings` filter instead.

`Tracker`, `Config` and `Event` sit at the root deliberately: `Tracker` is the single public entry
point, and `Config`/`Event` are the contract types every subnamespace depends on. `I18n` and
`Lifecycle` sit there too, alongside them: `Tracker::hook()` calls `I18n` directly, and composes
`Lifecycle` as the object that registers the activation/deactivation/init hooks and raises `install`,
`activate`, `version` and `compat` on the consumer's behalf.

## Translations

The SDK ships and loads its own `plugin-tracker-sdk` text domain from its own `languages/`
directory via `load_textdomain()` -- `load_plugin_textdomain()` cannot be used here, because that
helper resolves against the *consumer's* plugin directory, and this SDK has no plugin header of
its own. `I18n::load()` is called before anything user-facing renders, so the consent prompt
(`Consent\Notice`) is translated on first paint wherever a `.mo` exists for the site's locale. A
consumer can still override individual strings -- e.g. to match their own product's wording rather
than merely translate it -- through the `cx_tracker_notice_strings` filter (see
[`docs/CONSENT.md`](docs/CONSENT.md)). Run `composer makepot` to regenerate
`languages/plugin-tracker-sdk.pot`.

## Development

```bash
composer install
composer test              # PHPUnit via brain/monkey -- no WordPress install needed
composer test-f <filter>   # single test
composer lint               # phpcs, WPCS + PHPCompatibility 7.2-
composer lint:fix           # phpcbf
composer analyze            # PHPStan level 5
composer makepot            # regenerate languages/plugin-tracker-sdk.pot
```

`composer lint` and `composer analyze` are both **clean and can gate**. Unlike
`plugins/plugin-tracker`, this package has no violation backlog — keep it that way.

### What the download contains

```
plugin-tracker-sdk.zip        a release asset, always this name
  plugin-tracker-sdk/         always this folder
    autoload.php  src/  views/  languages/  composer.json  LICENSE  README.md
```

Fetch the newest one from a fixed address — no tag in it, no API call to resolve one:

```
https://github.com/codexpertio/plugin-tracker-sdk/releases/latest/download/plugin-tracker-sdk.zip
```

Unzip it beside your main plugin file and the snippet's
`require __DIR__ . '/plugin-tracker-sdk/autoload.php'` resolves as written. **No renaming.**

The asset exists for exactly those two reasons. GitHub's own source archive for a tag carries
identical bytes — `git archive` produces both, and `.gitattributes` trims both the same way — but its
root folder is named for the repository and tag (`plugin-tracker-sdk-1.2.0/`), and it cannot be
fetched from a stable URL, because `/releases/latest/download/` serves uploaded assets only. The
release workflow repackages the same tree with `--prefix=plugin-tracker-sdk/` to fix both.

## Distribution and the collision problem

The download is a **tag's own source archive**, produced by GitHub for every release. There is no
build step and no uploaded asset: `.gitattributes` trims the archive to the runtime files, and
`git archive` is what backs both a GitHub source zip and a Composer `--prefer-dist` install — so
both routes now ship identical bytes, which they did not before.

`autoload.php` is committed for that reason. It used to be generated during the build, and an
archive without it is one no consumer can integrate: the dashboard's snippet requires that file and
calls the class name it returns. The release workflow asserts it is present rather than leaving it
to be discovered by whoever unzips first.

### What was given up

This package used to publish a **namespace-scoped** copy: `Codexpert\PluginTracker\` rewritten to a
per-version prefix such as `CxTrackerSdk_v1_2_0_9f7703`, so that each consumer's bundled copy was
self-contained. That was dropped, and the problem it solved did not go away.

Most consumers bundle a downloaded copy, so **there is no `composer update`** for them — each one
freezes the version it downloaded, permanently, on sites we cannot reach. If three plugins on one
site each bundle a different version, every copy now declares the same classes under the same
namespace, and PHP loads whichever autoloader registers first. A plugin tested against 2.0 can
therefore run against 1.0 and fatal on a missing method.

That exposure is no longer a difference between the two routes: a Composer install always had the
unscoped namespace, and the download now matches it. [`autoload.php`](autoload.php) states the
consequence at the point somebody reads it, and its guard constant is deliberately unversioned so
the second copy registers nothing rather than racing the first — making the outcome consistent
instead of dependent on load order.

`automattic/jetpack-autoloader` was considered and rejected before, and the reasoning still holds:
it only works if *every* consumer adopts it, which is enforceable for plugins we control and not for
third parties who downloaded a zip — and a shared runtime that cannot be guaranteed is worse than
none, because the failure is silent version skew rather than a clean duplicate.

The per-consumer cost is unchanged: N consumers on one site means N queues and N requests, since
batching across them is not possible. Mitigated by small payloads, a long jittered interval, and
never flushing on a page request.

## Status

Client and server are both complete. `Plugin_Tracker\API\Telemetry` in `plugins/plugin-tracker` serves
`POST /telemetry/register`, `POST /telemetry/events` and `POST /telemetry/feedback` — the server half of
issue #40. [`docs/WIRE.md`](docs/WIRE.md) is the contract they share.

Ingestion refuses a payload whose `hash` has no enabled project, so a project has to exist before a
site can report. The dashboard creates one at `/plugins/{slug}/telemetry`; WP-CLI is the equivalent
from the shell, and the only route to `rotate`:

```bash
wp pt telemetry create --plugin=my-plugin   # prints the public hash and the author secret, once
wp pt telemetry list                        # projects, with install/event/feedback counts
wp pt telemetry rotate <id>                 # new secret and token epoch, same public hash
wp pt telemetry revoke <id>                 # refuse new data, keep what arrived
```

The rest of the pipeline is built too: the dashboard reports with per-project export and delete
(#41, `API\Telemetry_Owner`), and projects counting against a plan's telemetry allowance
(#30, `Helpers\Entitlement`).

## License

[GPL-3.0-or-later](LICENSE)
