# The generated snippet

The dashboard hands a plugin author one block to paste into their **main plugin file**, above their
own bootstrap. That block is the entire integration: no hooks to register, no events to call, no
settings screen to build.

## What the dashboard emits

```php
/**
 * Plugin Tracker SDK.
 *
 * Generated for "My Plugin" -- paste this ABOVE your own plugin's bootstrap.
 * Do not edit the hash.
 */
if ( ! function_exists( 'my_plugin_tracker' ) ) {

	require_once __DIR__ . '/plugin-tracker-sdk/autoload.php';

	/**
	 * @return \CxTrackerSdk_v1_0_0_92521f\Tracker|null
	 */
	function my_plugin_tracker() {
		global $my_plugin_tracker;

		if ( ! isset( $my_plugin_tracker ) ) {
			$my_plugin_tracker = \CxTrackerSdk_v1_0_0_92521f\Tracker::init(
				array(
					'hash'    => '4f3c2a1b9e8d7c6b5a4f3e2d1c0b9a87',
					'file'    => __FILE__,
					'enabled' => true,
				)
			);
		}

		return $my_plugin_tracker;
	}

	my_plugin_tracker();
}

// ... your own plugin's bootstrap follows.
```

## Why each part is shaped that way

**`function_exists()` around the whole block.** Two plugins by the same author can each carry a
snippet, and a snippet may survive into a copy of the plugin a user duplicated by hand. The guard
makes the block idempotent at the file level.

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
| `register_deactivation_hook` | `deactivation`, carrying the reason the modal collected if there is one |
| `init` | Detects a plugin-version change (`version`) and a WP or PHP change (`compat`) |
| `admin_notices` | The consent prompt, until the administrator answers it |
| `admin_footer-plugins.php` | The deactivation-feedback modal, on this plugin's row only |
| A scheduled job | The jittered flush; never on a page request |

Version and compat are detected on `init` rather than on activation because a plugin updated in
place — the normal case — never fires the activation hook again. An integration relying on
activation alone would never see an upgrade.

## What the author should not do

- Do not call `Tracker::init()` more than once. Use the function.
- Do not construct `Config`, `Queue`, `Transport` or any other SDK class directly. They are
  internal, and the scoped build may rename or move them between versions.
- Do not register your own activation/deactivation hooks *for telemetry*. The SDK has them. Your own
  hooks for your own purposes are unaffected.
- Do not call `flush()` from a page request. It refuses outside WP-Cron and WP-CLI for a reason.
- Do not edit the hash or the namespace prefix.
