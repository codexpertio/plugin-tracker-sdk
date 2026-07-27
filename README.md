<div align="center">

# Plugin Tracker SDK

[![Version](https://img.shields.io/badge/version-1.0.0-blue.svg)](src/Tracker.php)
[![PHP](https://img.shields.io/badge/PHP-7.2%2B-777bb4.svg)](composer.json)
[![License](https://img.shields.io/badge/license-GPL%20v3%20or%20later-green.svg)](../../../plugins/plugin-tracker/license.txt)

Opt-in telemetry for WordPress plugins. Consent-gated by default, anonymous by construction.

</div>

`codexpertio/plugin-tracker-sdk` — namespace `Codexpert\PluginTracker\`. Part of the
[Plugin Tracker monorepo](../../../README.md); tracked in issue
[#40](https://github.com/codexpertio/plugin-tracker/issues/40).

> [!IMPORTANT]
> **This SDK is the only part of this project exposed to WordPress.org review.** `plugin-tracker`
> itself is private and unlisted, which exempts *it* — not the plugins that bundle this. Any
> third-party plugin embedding this SDK and listed on WP.org is reviewed against the phoning-home
> rules, and this SDK is the mechanism by which it would fail them. Every consumer inherits that
> exposure, so the compliant path is the default one and the gates are fail-closed.

## Quick start

```php
use Codexpert\PluginTracker\Tracker;
use Codexpert\PluginTracker\Event;

add_action( 'plugins_loaded', function () {
    $tracker = Tracker::init( array(
        'project' => 'pt_proj_1a2b3c',   // public, non-secret; issued by the dashboard
        'plugin'  => 'my-plugin',
        'version' => MY_PLUGIN_VERSION,
        'enabled' => true,               // consent gate 1: you, the author, enable it
    ) );

    if ( $tracker ) {
        $tracker->track( Event::FEATURE, array( 'name' => 'csv_export' ) );
    }
} );
```

`track()` only ever writes to a local queue. It never makes a network call, so it is safe in an
activation hook — a slow or unreachable endpoint can never delay or break your activation.

`init()` returns `null` on invalid config rather than throwing. Your users' sites must not
white-screen because our SDK was misconfigured; the errors surface as an admin notice when
`WP_DEBUG` is on.

## Two consent gates, and nothing moves without both

| Gate | Who | How |
|---|---|---|
| 1. Project enabled | you, the plugin author | the `enabled` config flag |
| 2. Site opt-in | the site administrator | explicit, per site, recorded with a policy version |

Plus a site-level kill switch (`CX_TRACKER_DISABLE`) and a final `cx_tracker_consent` filter.

With no consent, `track()` does not even write to the local queue. Buffering "in case consent
arrives later" would mean holding data the administrator never agreed to us holding.

Full detail: [`docs/CONSENT.md`](docs/CONSENT.md).

## What it sends

Six events — `install`, `activate`, `version`, `compat`, `feature`, `deactivation` — each carrying
an anonymous install ID, your plugin's version, the WordPress and PHP versions, the site locale,
and whether the site is multisite.

**Never** the site address, an email, a username, an IP, or any content. The active-plugin list is
refused too, despite being the most-requested telemetry field, because the set of active plugins is
close to unique per site and would re-identify a site the anonymous ID exists to protect.

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
├── Tracker.php              # the facade, and the only class you need
├── Config.php               # validated construction
├── Event.php                # the closed event allow-list
├── I18n.php                 # loads the SDK's own text domain from languages/
├── Consent/Gate.php         # the double gate
├── Consent/Notice.php       # reusable opt-in prompt
├── Storage/Queue.php        # bounded local buffer
├── Storage/Install.php      # the anonymous install ID
├── Http/Transport.php       # requests, and response-envelope resolution
├── Cron/Scheduler.php       # jittered scheduled flush
└── Privacy/Personal_Data.php # WP exporter/eraser registration

languages/plugin-tracker-sdk.pot  # sibling of src/, shipped by bin/build-dist.sh
```

`Tracker`, `Config` and `Event` sit at the root deliberately: `Tracker` is the single public entry
point, and `Config`/`Event` are the contract types every subnamespace depends on. `I18n` sits there
too, alongside them, because `Tracker::hook()` calls it directly.

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
composer dist               # build a scoped, distributable copy
composer verify-collision   # bin/verify-collision.sh -- the two-consumer collision test (see below)
```

`composer lint` and `composer analyze` are both **clean and can gate**. Unlike
`plugins/plugin-tracker`, this package has no violation backlog — keep it that way.

Tests follow the house layout (`tests/php/{bootstrap.php,src/,utils/}`, PSR-4
`Codexpert\PluginTracker\Test\`), but boot through `brain/monkey` rather than the WordPress test
suite. That is deliberate: this is a library bundled into other people's plugins, and requiring a
full WP install to run its unit tests would be a barrier for exactly the people who need to run
them.

### PHP 7.2 is a distribution decision, not a preference

This floor becomes the floor for **every plugin that bundles this SDK**, so it caps adoption. No
typed properties, no arrow functions, no `??=`.

Two tools hold it between them, and it is worth knowing which does what. `phpcs` with
`PHPCompatibility testVersion 7.2-` catches typed properties, `??=`, trailing commas in calls and
array unpacking — but **not** arrow functions, `match`, `?->` or the PHP 8 string functions, because
the locked PHPCompatibility 9.3.5 predates them. Those are caught by `composer analyze`: PHPStan
with `phpVersion: 70200` treats them as syntax errors. `config.platform.php` pins the resolver so
dev dependencies cannot drag the floor up.

So run both. Passing `composer lint` alone does not mean the floor is intact.

## Distribution and the collision problem

Not on Packagist. Registered plugin owners download a built copy from the dashboard and bundle it,
so **there is no `composer update`** — every consumer freezes the version they downloaded,
permanently, on sites we cannot reach.

That has a sharp consequence. If three plugins on one site each bundle a different version, PHP
loads whichever `vendor/autoload.php` registers first, so a plugin tested against 2.0 silently runs
against 1.0 and fatals on a missing method. Plain Composer cannot prevent this.

`composer dist` therefore emits a **namespace-scoped** copy: `Codexpert\PluginTracker\` is rewritten
to a per-version prefix (e.g. `CxTrackerSdk_v1_0_0_92521f` -- the trailing hex is a short digest of
the raw version string, appended because collapsing non-word characters alone is lossy: `1.0.0-hotfix`
and `1.0.0+hotfix` would otherwise collapse to the same prefix and reintroduce the exact version skew
this scoping exists to prevent), making each consumer's copy self-contained and requiring nothing of
them.

`composer verify-collision` runs the test this design exists to pass: it builds two scoped copies at
different versions, loads both in one PHP process the way two plugins on one site would, and asserts
they coexist with fully isolated state -- see [`bin/verify-collision.sh`](bin/verify-collision.sh).

`automattic/jetpack-autoloader` was considered and rejected. It only works if *every* consumer
adopts it, which is enforceable for plugins we control and not for third parties who downloaded a
zip — and a shared runtime that cannot be guaranteed is worse than none, because the failure is
silent version skew rather than a clean duplicate.

The cost is real: N consumers on one site means N queues and N requests, since batching across them
is no longer possible. Mitigated by small payloads, a long jittered interval, and never flushing on
a page request.

## Status

The client is complete. **The two backend endpoints it speaks to do not exist yet** —
`POST /telemetry/register` and `POST /telemetry/events` are issues #43 and #44, blocked by #30
(entitlement). [`docs/WIRE.md`](docs/WIRE.md) is the client half of that contract, written first so
the backend can implement against it.

## License

[GPL-3.0-or-later](../../../plugins/plugin-tracker/license.txt)
