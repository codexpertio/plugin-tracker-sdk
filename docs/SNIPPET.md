# The generated snippet

The dashboard hands a plugin author one block to paste into their **main plugin file**, above their
own bootstrap. That block is the entire integration: no hooks to register, no events to call, no
settings screen to build.

## Two routes, two snippets

The SDK ships **both** as a downloadable zip and as a Composer package, and the snippet is not the
same for the two. They differ in how the Tracker class is reached and in what that class is called;
the dashboard has a switch and generates the matching one. An author who pastes the other one gets a
fatal at activation, not a degradation, so this document covers both.

> [!IMPORTANT]
> **Order matters in this file, and not for readability.** `bin/build-dist.sh` learns what folder
> the zip must unpack to by grepping this document for the first `__DIR__`-relative autoload path
> and taking the directory out of it. The download snippet below is that first occurrence.
>
> Anything written above it in that same shape captures the build instead — the Composer variant's
> own autoload line qualifies, and so does an example that merely quotes the pattern. The zip then
> unpacks to whatever that line named, the snippet's require resolves to nothing, and the author's
> site fatals on activation. This note is deliberately worded to avoid matching it.
>
> Keep the download snippet first, and re-run `composer dist` after editing this file.

## What the dashboard emits — downloaded zip

```php
/**
 * Plugin Tracker SDK.
 *
 * Generated for "My Plugin" -- paste this ABOVE your own plugin's bootstrap.
 * Do not edit the hash.
 */
if ( ! function_exists( 'my_plugin_tracker' ) ) {

	/**
	 * @return object|null The SDK's Tracker, or null when the SDK is not present.
	 */
	function my_plugin_tracker() {
		global $my_plugin_tracker;

		if ( ! isset( $my_plugin_tracker ) ) {
			$my_plugin_tracker = false;

			// Ask the SDK for its own class name rather than naming it here: the download
			// scopes its namespace per version, so upgrading it would break a hard-coded name.
			$tracker_autoload = __DIR__ . '/plugin-tracker-sdk/autoload.php';
			$tracker_class    = is_file( $tracker_autoload ) ? require $tracker_autoload : '';

			// Every way the SDK can be missing or broken -- not shipped, half-extracted, renamed
			// folder -- ends up here as a no-op instead of a fatal. A telemetry SDK must never be
			// the reason a site goes down, so do not remove this check.
			if ( is_string( $tracker_class ) && class_exists( $tracker_class ) ) {
				$my_plugin_tracker = $tracker_class::init(
					array(
						'hash'    => '4f3c2a1b9e8d7c6b5a4f3e2d1c0b9a87',
						'file'    => __FILE__,
						'enabled' => true,
						// Which optional fields this release sends. Editing it changes your NEXT
						// release only -- what is kept for installs already published is decided by
						// the collection settings on the dashboard.
						'collect' => 'all',
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

## What the dashboard emits — Composer

Two differences, both in the generated code rather than in anything the author writes.

Composer's autoloader is required **above** the `function_exists` guard, where the SDK's own example
plugin puts it. Inside, it would be skipped exactly when the guard does its job — a second copy of
the plugin, a hand-duplicated file — and it is a plugin-wide concern rather than part of the
accessor.

And the class is named outright. Nothing rewrites the namespace on this path, so there is no artifact
to ask: the SDK's own unscoped `Tracker` is a fixed name that cannot go stale on upgrade.

**That name is deliberately not written out below.** This file is copied into the scoped artifact,
and `bin/build-dist.sh` refuses to ship an artifact containing any occurrence of the unscoped
namespace — a check worth keeping, because a downloaded copy is exactly where that name must never
appear: pasting it there is the activation fatal this whole design exists to avoid. The repository
README carries the literal, where it is safe.

```php
// Composer's autoloader, which is what loads the SDK. Leave this out if your
// plugin already requires it -- require_once will not load it twice either way.
require_once __DIR__ . '/vendor/autoload.php';

if ( ! function_exists( 'my_plugin_tracker' ) ) {

	function my_plugin_tracker() {
		global $my_plugin_tracker;

		if ( ! isset( $my_plugin_tracker ) ) {
			$my_plugin_tracker = false;

			// Composer resolves this name directly -- it is not rewritten on that path.
			// The generator writes the SDK's unscoped Tracker FQCN here; see the README.
			$tracker_class = '\…\Tracker';

			if ( is_string( $tracker_class ) && class_exists( $tracker_class ) ) {
				$my_plugin_tracker = $tracker_class::init( /* ... as above ... */ );
			}
		}

		return $my_plugin_tracker ? $my_plugin_tracker : null;
	}

	my_plugin_tracker();
}
```

The `class_exists()` guard survives on this route too, and is not redundant there: a consumer can
ship without running `composer install`, and the failure has to stay a no-op rather than a fatal.

## Why each part is shaped that way

**`function_exists()` around the whole block.** Two plugins by the same author can each carry a
snippet, and a snippet may survive into a copy of the plugin a user duplicated by hand. The guard
makes the block idempotent at the file level.

**Nothing in this block names the SDK version, and that is deliberate.** The scoped namespace
contains the version (`CxTrackerSdk_v<version>_<digest>`), so it changes on every SDK upgrade. An
earlier form of this snippet wrote that name into the author's plugin file, which made upgrading a
two-step operation with no warning if you did only the first: drop in the new folder, forget the
snippet, and the plugin fatals on activation with `Class "CxTrackerSdk_v<the version they upgraded
FROM>…\Tracker" not found` — on the author's users' sites, from an upgrade that looked complete. The
name in that error is the *old* one, which is the tell: the folder moved on and the snippet did not.
`autoload.php` returns
the class name instead, so replacing the folder is the whole upgrade.

**The `is_file()` / `is_string()` / `class_exists()` guards, and the `null` return.** A telemetry SDK
must never be the reason a site goes down. These make every way the SDK can be absent or broken —
not shipped in the zip, half-extracted, wrong folder name, a build that stopped returning the class
name — a silent no-op rather than a fatal. The author's plugin keeps working and simply reports
nothing, which is the correct trade for a component nobody installed the plugin *for*.

That is also why the block still runs when the SDK is missing: `my_plugin_tracker()` stays callable
and returns `null`, so an author who calls it from a settings page does not have to guard every call
site. **Do not remove these checks** to tidy the snippet — each one stands for a way a real
download goes wrong.

**`require`, not `require_once`.** The return value is the integration contract, and a repeated
`require_once` returns `true` instead of the file's return value — which would make
`$my_plugin_tracker_class` a boolean and the next line a fatal. Requiring the file twice is safe: it
guards its own autoloader registration and has no other side effects.

**A function wrapping a global, not a bare call.** Call it anywhere in the plugin — from a settings
page, a CLI command, another class — and you get the same instance. Do **not** call
`Tracker::init()` a second time yourself, and do not construct the SDK's classes directly. That is
what the global is for.

`init()` is idempotent per plugin slug regardless (it returns the cached instance), so a second call
is harmless rather than dangerous — but the function-plus-global pattern is the one to document and
the one to copy, because it also gives the author somewhere to reach the instance from.

**`__FILE__`, and it must be the main plugin file.** The SDK registers the activation and
deactivation hooks itself, and `register_activation_hook()` keys on `plugin_basename( $file )`.
Passed an include or a class file, it computes a basename WordPress never fires — the hooks silently
never run, nothing errors, and data simply never arrives. `Config` therefore checks that the file
carries a `Plugin Name:` header and refuses the config otherwise.

<a id="identity"></a>

## Identity comes from the plugin header

`plugin`, `name` and `version` are **not** arguments. WordPress already knows all three — they are in
the header of the very file passed as `file` — so the SDK reads them there:

| Value | Where it comes from | Example |
|---|---|---|
| `version` | The header's `Version:` line | `Version: 1.4.2` → `1.4.2` |
| `name` | The header's `Plugin Name:` line | `Plugin Name: My Plugin` → `My Plugin` |
| `plugin` (slug) | The plugin's own directory name, lowercased and slugified; for a single-file plugin, its filename | `plugins/my-plugin/my-plugin.php` → `my-plugin` |

There is no `Slug:` header in WordPress, so the slug comes from the path — which is the same thing
WordPress.org keys on, and therefore the same answer an author would have typed.

Version was the argument that most deserved to go. It had to be bumped in *two* places on every
release, and forgetting the second meant the SDK reported a version that was not the one running —
and fired the `version` lifecycle event late, for an upgrade that had already happened.

**Reading the header is done the way WordPress does it**, never with a regex over the author's
source. `get_plugin_data()` is used when it is already loaded, and `get_file_data()` — the
`wp-includes` function `get_plugin_data()` itself delegates to — otherwise. That fallback matters:
`get_plugin_data()` lives in `wp-admin/includes/plugin.php`, which is *not* loaded on a front-end
request or during cron, and this snippet runs at file scope on every request. Translation is
explicitly off, because translating a header this early makes WordPress load the text domain
just-in-time and warn on every request since 6.7.

**You can still pass any of the three explicitly, and an explicit value always wins.** Two reasons to:
the reporting slug must differ from the directory name (a directory a user renamed, or a plugin whose
WordPress.org slug does not match its folder), or the plugin is distributed in a shape where the
header is not the source of truth. Every already-shipped integration that passes all three keeps
working unchanged — derivation is a fallback, never an override.

**Above the author's own bootstrap.** So the activation hook is registered before anything else can
short-circuit, and so `init` listeners are in place for the first request.

**The namespace is scoped, and the prefix is part of the download.** The artifact the dashboard
issues has its namespace rewritten to a per-version prefix
(`CxTrackerSdk_v<version>_<digest>`), because several plugins on one site may each bundle a
different SDK version and PHP loads whichever autoloader registers first. The generated snippet must
name the prefix of the artifact it ships with. Do not hand-edit either.

**`hash` is public, not secret.** It says which plugin is reporting and is useless on its own. It
ships inside the author's plugin, so if that plugin is listed on WordPress.org the zip is published
and anyone can read it. Nothing in the snippet may ever be a value the dashboard told the author to
keep private; authentication is a per-install token obtained after consent, never a shipped
credential.

**`enabled` is consent gate 1** — the author's own switch. Gate 2 is the site administrator's
explicit opt-in. Both must pass before anything is transmitted. See [`CONSENT.md`](CONSENT.md).

## What the SDK does with it

Everything, without further involvement from the author:

| Hook | What the SDK does |
|---|---|
| `register_activation_hook` | `install` on the first activation ever, `activate` on every one |
| `register_deactivation_hook` | `deactivation`, carrying the reason the modal collected if there is one — and sends it in the same request, because nothing later can |
| `init` | Detects a plugin-version change (`version`) and a WP or PHP change (`compat`) |
| `admin_notices` | The consent prompt, until the administrator answers it — or later, if `consent_after` says so |
| `admin_footer-plugins.php` | The deactivation-feedback modal, on this plugin's row only |
| A scheduled job | The jittered flush; never on a page request, except the deactivation above |

Version and compat are detected on `init` rather than on activation because a plugin updated in
place — the normal case — never fires the activation hook again. An integration relying on
activation alone would never see an upgrade.

## `consent_after` — asking later

Optional, and absent from the snippet unless the author set a delay on the dashboard. It postpones
the opt-in prompt by a number of **seconds**, counted from the site's own activation:

```php
'consent_after' => 172800, // 2 days
```

Seconds, with the author's phrasing in the comment. The dashboard is where the duration is written
as an amount and a unit — 2 days, 6 hours, 3 weeks — and it multiplies once, here. This end has no
use for the phrasing and every use for a single number it can subtract from `time()`.

Asking on a site's first day, before the plugin has done anything for anyone, is how a prompt gets
dismissed without being read. A delay trades a smaller number of answers for better ones.

**Unlike every other setting on that screen, this one reaches the release that carries it and no
further.** The collection settings are enforced when data arrives, so changing them applies to
installs already published; this prompt runs before the site has agreed to be in touch at all, so
there is no request for the server to apply anything to. Changing it does nothing to copies already
out there — only to the next release.

Three things it deliberately will not do:

- **It cannot silence the prompt.** Values above five years are clamped to five years, and anything
  unreadable — `'30 days'`, `true`, a negative — resolves to 0, which asks immediately. A delay
  nobody can wait out is a consent prompt that does not exist, shipped under a plugin that says it
  collects consent. To not ask, pass `'enabled' => false`, which is honest about what it does.
- **It does not restart.** The activation stamp is written once, so toggling the plugin does not push
  the question further away each time.
- **It does not apply where it cannot be measured.** A site that was already running the plugin when
  this SDK arrived has no activation to count from, and is asked at the next opportunity rather than
  never.

## What the author should not do

- Do not call `Tracker::init()` more than once. Use the function.
- Do not construct `Config`, `Queue`, `Transport` or any other SDK class directly. They are
  internal, and the scoped build may rename or move them between versions.
- Do not register your own activation/deactivation hooks *for telemetry*. The SDK has them. Your own
  hooks for your own purposes are unaffected.
- Do not call `flush()` from a page request. It refuses outside WP-Cron and WP-CLI for a reason.
  The SDK makes exactly one exception for itself, on deactivation, because that event has no later
  chance to be sent -- see [`EVENTS.md`](EVENTS.md#deactivation).
- Do not edit the hash or the namespace prefix.
