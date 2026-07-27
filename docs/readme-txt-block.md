# `readme.txt` "External services" block

WordPress.org requires every plugin that contacts a third-party service to disclose it in the
`== External services ==` section of `readme.txt`. A plugin that bundles this SDK does exactly
that — once an admin opts in, and again if an admin submits deactivation feedback — so this section
is not optional. **Its absence is what gets a plugin rejected at initial review, or pulled later if
a reviewer finds an undisclosed call.**

> **There are TWO transmissions, not one, and they are not the same.** This is the single most
> common way for a disclosure generated from this file to end up inaccurate. The SDK contacts the
> service in two unrelated situations, with **different data, different timing, and different
> consent**:
>
> | | Telemetry | Deactivation feedback |
> |---|---|---|
> | Route | `POST /telemetry/register`, `POST /telemetry/events` | `POST /telemetry/feedback` |
> | Triggered by | A background WP-Cron job | The admin pressing a button in a dialog |
> | How often | Repeatedly, for the life of the install | At most once per deactivation |
> | Consent | Requires the **opt-in** | Requires the **submission itself** — it works whether or not the admin ever opted into telemetry |
> | Site address | **Never sent** | **Sent**, and shown to the admin before they submit |
> | Free text | **Never sent** | **Sent** (up to 1000 characters, typed by the admin) |
>
> A disclosure that describes only the telemetry stream is **inaccurate for any plugin that ships
> the feedback modal**, because it claims the plugin never sends the site address or free text, and
> it does — with the admin's consent, on a different route. That is a material misstatement about
> what leaves the site, which is exactly what reviewers look for.
>
> If your plugin genuinely never shows the modal (you set `enabled` to false, or you filter
> `cx_tracker_feedback` to `false` unconditionally), delete the feedback paragraphs. Otherwise keep
> them.

The field lists below are copied from the tables in [`EVENTS.md`](EVENTS.md) and
[`FEEDBACK.md`](FEEDBACK.md), not paraphrased, so that this disclosure and the two frozen payload
contracts cannot drift apart. If you change what your own plugin sends via `Tracker::track()`,
re-check this block against those documents first.

> **Backend status caveat (not part of the text to paste):** as of this writing, [`WIRE.md`](WIRE.md)
> documents the `/telemetry/register` and `/telemetry/events` routes as a contract awaiting its
> server implementation (blocked on issues #43/#44), and [`FEEDBACK.md`](FEEDBACK.md) documents
> `/telemetry/feedback` the same way. Confirm each endpoint is actually live before you publish a
> plugin that claims to contact it — and do not disclose a route your build never calls.

---

## Copy this into your plugin's `readme.txt`

Replace every `[PLACEHOLDER]` before publishing. See the notes below the block.

```text
== External services ==

This plugin uses the Plugin Tracker service in two separate ways. Neither happens without your
agreement, and they are described separately below because they send different things.

Endpoint contacted: https://app.plugintracker.dev/wp-json/plugin-tracker/v1

= 1. Anonymous usage data (only after you opt in) =

This plugin can send anonymous usage data, but only after you explicitly agree — see "Opt-in"
below. No data is sent before you opt in, and you can opt out at any time from the notice this
plugin shows in wp-admin.

Routes used: POST /telemetry/register, called once per site after you opt in, and
POST /telemetry/events, called on a periodic background schedule.

Data transmitted:

* An anonymous, one-way-hashed install identifier (`install`). It is generated from a random value
  stored only on your site and cannot be reversed back to your site's address.
* SDK/payload metadata sent with every request: the payload schema version (`schema`), the SDK
  version (`sdk`), and this plugin's public, non-secret plugin identifier from the pasted snippet
  (`hash`).
* With every event: the event name (`event`, one of `install`, `activate`, `version`, `compat`,
  `feature`, `deactivation`), the time it occurred (`at`), this plugin's slug (`plugin`) and version
  (`plugin_version`), your WordPress version (`wp`), your PHP version to major.minor precision only
  (`php`), your site's locale (`locale`), and whether your site is a multisite (`multisite`).
* Depending on the event: the previous plugin version on an upgrade/downgrade (`from`); which of
  WordPress or PHP changed and its previous value (`what`, `from`) on a compatibility check; a
  developer-defined feature name and optional use count (`name`, `count`) on feature-usage events;
  or an optional deactivation reason chosen from a fixed list — temporary, no_longer_needed,
  found_better, broke_site, confusing, missing_feature, other (`reason`) — on deactivation.

This usage data does NOT include: your site address, admin or user email addresses, usernames, user
IDs, IP addresses, your list of active plugins or themes, post/page content, post/page/user counts,
or any free-text feedback.

When it is sent: registration happens once, only after you opt in. Event data is queued locally and
sent in batches on a periodic background job (WP-Cron) — never during a page load, and never during
plugin activation or deactivation.

= 2. Deactivation feedback (only if you fill in the form and press Send) =

When you deactivate this plugin, it shows a dialog asking why. Answering is entirely optional: the
dialog has a "Skip & Deactivate" button, a "Cancel" button, and closes with the Escape key, and
deactivation always works whether or not you answer. Nothing is sent unless you press the send
button.

This is a separate transmission from the usage data above. It is sent because you pressed the
button, so it does NOT depend on the opt-in described in section 1 — it works whether you opted in,
opted out, or were never asked. It is sent once and is never retried or stored for later.

Route used: POST /telemetry/feedback, called at most once, at the moment you press send.

Data transmitted:

* Your site's address (`site`). This IS sent with feedback, unlike the anonymous usage data above,
  so the developers can tell which site the report came from. The dialog shows you your site address
  before you send.
* The reason you selected, from a fixed list — temporary, no_longer_needed, found_better,
  broke_site, confusing, missing_feature, other (`reason`).
* Your free-text comment, exactly as you typed it, up to 1000 characters (`note`). It is only sent
  if you type one.
* This plugin's public, non-secret identifiers (`project`, `hash`), its slug (`plugin`) and version
  (`plugin_version`), the payload schema version (`schema`), the SDK version (`sdk`), and the time
  you submitted (`at`).
* Your WordPress version (`wp`), your PHP version to major.minor precision only (`php`), your site's
  locale (`locale`), and whether your site is a multisite (`multisite`).

Feedback does NOT include: your email address or anyone else's, usernames, user IDs, IP addresses,
your list of active plugins or themes, post/page content, post/page/user counts, error logs, licence
keys, or the anonymous install identifier described in section 1. The anonymous install identifier is
deliberately left out so that this feedback cannot be linked back to the anonymous usage data.

Before you send, the dialog lists every value above, with your actual site address and versions
filled in, so you can see exactly what will be sent.

= Opt-in and control =

Opt-in: this plugin will not send any usage data (section 1) until you explicitly agree via the
notice it displays in wp-admin. You can withdraw consent at any time from that same notice; no
further usage data is sent once you do, and previously queued (but not yet sent) data is discarded.
Feedback (section 2) is never sent unless you fill in the dialog and press send.

Turning everything off: adding `define( 'CX_TRACKER_DISABLE', true );` to your `wp-config.php` stops
both transmissions completely, for every plugin on the site that uses this service, regardless of any
other setting.

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
- **Do not delete section 2 unless the modal genuinely cannot appear in your build.** It appears
  whenever `enabled` is true and the current user can deactivate plugins. If you suppress it with
  `add_filter( 'cx_tracker_feedback', '__return_false' )`, delete section 2 and the sentence about
  it in "Opt-in and control". If you don't suppress it, keeping section 2 is not optional: your
  plugin sends your users' site address and their typed comments to a third party, and a disclosure
  that omits that is inaccurate.
- **Section 2 must keep saying that feedback does not depend on the opt-in.** It is tempting to
  simplify the two sections into one "only after you opt in" paragraph. Doing so would make the
  disclosure wrong, because feedback is sent on the strength of the send button and works for a user
  who declined telemetry. A reviewer who tests that path and finds a request the readme said could
  not happen has found an undisclosed external call.
- If you track fewer than all six events, you may trim the "Depending on the event" bullets in
  section 1 to only the events your plugin actually calls `track()` with — but never add fields your
  plugin doesn't send, and never remove a field it does.
- Keep this section in sync with [`EVENTS.md`](EVENTS.md) **and** [`FEEDBACK.md`](FEEDBACK.md). If a
  future SDK version adds an optional field to either payload (schema bump), add it here too; the
  additive-only rule in both documents means old disclosures stay true, but new ones should stay
  complete.
