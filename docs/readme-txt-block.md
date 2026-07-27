# `readme.txt` "External services" block

WordPress.org requires every plugin that contacts a third-party service to disclose it in the
`== External services ==` section of `readme.txt`. A plugin that bundles this SDK does exactly
that — once an admin opts in — so this section is not optional. **Its absence is what gets a
plugin rejected at initial review, or pulled later if a reviewer finds an undisclosed call.**

The field list below is copied from the tables in [`EVENTS.md`](EVENTS.md), not paraphrased, so
that this disclosure and the frozen payload contract cannot drift apart. If you change what your
own plugin sends via `Tracker::track()`, re-check this block against `EVENTS.md` first.

> **Backend status caveat (not part of the text to paste):** as of this writing, [`WIRE.md`](WIRE.md)
> documents the `/telemetry/register` and `/telemetry/events` routes as a contract awaiting its
> server implementation (blocked on issues #43/#44). Confirm the endpoint is actually live before
> you publish a plugin that claims to contact it.

---

## Copy this into your plugin's `readme.txt`

Replace every `[PLACEHOLDER]` before publishing. See the notes below the block.

```text
== External services ==

This plugin uses the Plugin Tracker service to send anonymous usage data, but only after you
explicitly agree — see "Opt-in" below. No data is sent before you opt in, and you can opt out at
any time from the notice this plugin shows in wp-admin.

Endpoint contacted: https://app.plugintracker.dev/wp-json/plugin-tracker/v1
(Two routes under this base: POST /telemetry/register, called once per site after you opt in, and
POST /telemetry/events, called on a periodic background schedule.)

Data transmitted:

* An anonymous, one-way-hashed install identifier (`install`). It is generated from a random value
  stored only on your site and cannot be reversed back to your site's address.
* SDK/payload metadata sent with every request: the payload schema version (`schema`), the SDK
  version (`sdk`), and this plugin's public, non-secret project identifier (`project`).
* With every event: the event name (`event`, one of `install`, `activate`, `version`, `compat`,
  `feature`, `deactivation`), the time it occurred (`at`), this plugin's slug (`plugin`) and version
  (`plugin_version`), your WordPress version (`wp`), your PHP version to major.minor precision only
  (`php`), your site's locale (`locale`), and whether your site is a multisite (`multisite`).
* Depending on the event: the previous plugin version on an upgrade/downgrade (`from`); which of
  WordPress or PHP changed and its previous value (`what`, `from`) on a compatibility check; a
  developer-defined feature name and optional use count (`name`, `count`) on feature-usage events;
  or an optional deactivation reason chosen from a fixed list — temporary, no_longer_needed,
  found_better, broke_site, confusing, missing_feature, other (`reason`) — on deactivation. No
  free-text feedback is ever transmitted.

This plugin does NOT send: your site address, admin or user email addresses, usernames, user IDs,
IP addresses, your list of active plugins or themes, post/page content, or post/page/user counts.

When it is sent: registration happens once, only after you opt in. Event data is queued locally and
sent in batches on a periodic background job (WP-Cron) — never during a page load, and never during
plugin activation or deactivation.

Opt-in: this plugin will not send anything until you explicitly agree via the notice it displays in
wp-admin. You can withdraw consent at any time from that same notice; no further data is sent once
you do, and previously queued (but not yet sent) data is discarded.

This service is provided by [SERVICE PROVIDER NAME]. [Terms of Service]([TERMS_OF_SERVICE_URL]) and
[Privacy Policy]([PRIVACY_POLICY_URL]).
```

## Notes for whoever pastes this in

- Replace `[SERVICE PROVIDER NAME]`, `[TERMS_OF_SERVICE_URL]`, and `[PRIVACY_POLICY_URL]` with the
  actual provider name and links for the Plugin Tracker service you're reporting to. Do not publish
  the placeholders as-is — WordPress.org reviewers check that these links resolve.
- If your `Tracker::init()` call passes a custom `endpoint` (overriding the SDK default), update the
  "Endpoint contacted" line to match — the disclosure must name the host actually contacted, not the
  SDK's default.
- If you track fewer than all six events, you may trim the "Depending on the event" bullets to only
  the events your plugin actually calls `track()` with — but never add fields your plugin doesn't
  send, and never remove a field it does.
- Keep this section in sync with [`EVENTS.md`](EVENTS.md). If a future SDK version adds an optional
  field (schema bump), add it here too; `EVENTS.md`'s additive-only rule means old disclosures stay
  true, but new ones should stay complete.
