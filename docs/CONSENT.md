# The double consent gate

**This is the SDK's whole compliance story.** Nothing is transmitted unless BOTH gates below pass.
The gate logic lives in [`Consent\Gate`](../src/Consent/Gate.php); the reusable opt-in UI lives in
[`Consent\Notice`](../src/Consent/Notice.php). This document describes what that code does — if the
two ever disagree, the code is right and this file is stale.

The SDK is the only part of this project exposed to WordPress.org review. A consumer bundles this
code into their own plugin and lists that plugin on WP.org; the reviewer is reading *this* consent
logic, under *that* plugin's name. Every plugin that adopts the SDK inherits this exposure, which is
why the gate is fail-closed at every step rather than merely "off by default."

## Gate 1: author project-enable

The plugin author passes `enabled` in the array given to `Tracker::init()`:

```php
Tracker::init( array(
	'hash'    => 'a1b2c3d4a1b2c3d4a1b2c3d4a1b2c3d4', // dashboard-issued, public; from the pasted snippet
	'plugin'  => 'my-plugin',
	'name'    => 'My Plugin',   // shown in the prompt; omit and the slug is prettified
	'version' => '2.4.1',
	'file'    => __FILE__,      // required: the MAIN plugin file -- see docs/SNIPPET.md
	'enabled' => true, // Gate 1
) );
```

In practice a consumer does not type this call by hand -- the dashboard generates it as part of the pasted
snippet described in [`SNIPPET.md`](SNIPPET.md), and `hash`/`file` are validated as required by `Config`. It is
shown here only to name gate 1 explicitly.

This is a decision made once, by the author, at build time — it says "this project is allowed to
telemeter at all." It is checked via `Config::enabled()`. If it is false (or omitted), the gate
never proceeds to ask the site administrator anything: `Gate::granted()` returns `false` and
`Notice` never renders the opt-in prompt (`Notice::render()` bails out early when
`! $this->config->enabled()`).

## Gate 2: site-admin opt-in

Even with the author's project enabled, nothing is sent until *this site's* administrator explicitly
agrees. The agreement is recorded per site, per plugin slug, in an option keyed by
`Config::option( 'consent' )`, as:

```php
array( 'opted_in' => true, 'policy' => Gate::POLICY, 'at' => time() )
```

`Gate::site_opted_in()` requires all of:

- `opted_in` is truthy, and
- the stored `policy` matches the **current** `Gate::POLICY` constant.

### Policy history

Bumping `Gate::POLICY` is not free: it re-prompts every site that had already agreed. So each bump is
recorded here with what changed and why it was material.

| Version | Change |
|---|---|
| 1 | The original wording. |
| 2 | States that this is a **beta programme** and that what is collected may change. Issue #40 is "consent (opt-in beta)" and PLANS.md §11.E calls it "Telemetry beta" with "supported beta events" — so the event set, the retention window and the endpoints are all still subject to change, which is material to the decision an administrator is being asked to make. Bumped while the SDK was still unreleased, deliberately: the re-prompt cost nothing then and would have cost every consumer's users a fresh prompt afterwards. |

### Why an old policy version doesn't carry forward

`Gate::POLICY` is a version number for the consent *text* — what the admin was actually told before
they clicked "Allow." If that wording changes materially, an agreement recorded under the old
version is bumped to no longer count, and `site_opted_in()` returns `false` again even though
`opted_in` is still `true` in the stored record.

This is deliberate, not a bug: an admin who agreed to policy version 1 agreed to what version 1
*said*. If version 2 says something different, the admin agreed to different terms than the ones
now in force — they have not agreed to *this* policy, so treating the old record as current consent
would be transmitting on the strength of an agreement that was never actually made. The SDK asks
again instead of assuming.

An opt-out record is versioned the same way (`opt_out()` also stamps the current `POLICY`), so
`Gate::answered()` — "has the admin been asked, either way?" — is also policy-scoped, and `Notice`
correctly re-prompts under a new policy rather than treating "declined under the old wording" as
"declined under the new one."

### An explicit opt-out also resets the anonymous install ID

`Gate::opt_out()` itself only records the decision. The cleanup that actually happens when an admin
clicks "No thanks" lives in `Tracker::handle_consent()`, and it goes further than the consent record:
it unschedules the flush, clears the local queue, discards the stored token -- **and calls
`Install::forget()`**, which deletes the local salt the anonymous install ID is derived from (see
[`EVENTS.md`](EVENTS.md#the-anonymous-install-id)).

That means state does **not** survive an explicit opt-out. Consequence, accepted deliberately: a
site that opts out and later opts back in gets a **new** install ID, so a site that cycles consent
counts more than once on the dashboard. The alternative -- keeping a live identifier on a site that
explicitly said no -- was judged the worse trade: nothing should remain that could be correlated to
data already reported, once an admin has declined.

## The `CX_TRACKER_DISABLE` kill switch

```php
define( 'CX_TRACKER_DISABLE', true );
```

Checked first, unconditionally, before either consent gate. A site owner or host must be able to
stop all telemetry — for every plugin bundling this SDK on that install — without touching any
plugin's settings. If it's defined and truthy, `Gate::granted()` returns `false` immediately, and
`Notice::render()` also refuses to show the opt-in prompt while it's set.

## The `cx_tracker_consent` filter

```php
/**
 * @param bool   $granted Whether transmission is permitted.
 * @param string $plugin  Consumer plugin slug.
 */
apply_filters( 'cx_tracker_consent', true, $plugin_slug );
```

This filter is applied **last** in `Gate::granted()`, after the kill switch and both consent gates
have already passed. That ordering is the whole design point: by the time the filter runs, `true`
is only ever reached because every prior check already allowed it. A filter callback can therefore
only turn the result to `false` — it can never turn a `false` result (kill switch set, author not
enabled, admin not opted in) into `true`. It is a final veto for site owners and hosts, not a way
to grant consent the admin never gave.

## `Tracker::track()` fails closed

```php
public function track( $name, array $props = array() ) {
	if ( ! $this->consent->granted() ) {
		return false;
	}
	// ...
}
```

Without consent, `track()` returns immediately — it does not write anything to the local queue,
let alone attempt to send. This holds even though queuing alone never touches the network.

**Why buffering "for later" would be wrong.** It might seem safer to queue events locally while
consent is pending, and only send them once (if) the admin agrees. It is not safer — it is a
violation on its own: it means the SDK is already holding data about the site (event names,
timestamps, environment facts) that the administrator never agreed to it holding. Consent has to
precede collection, not just transmission. A site that never opts in must have zero footprint from
this SDK beyond the schema-less presence of the code itself.

`Tracker::flush()` re-checks consent independently, for the same reason from the other direction:
an admin can opt out *after* events were already queued (with consent, at the time), and that later
decision must win. On a lost consent check, `flush()` clears the queue and unschedules the cron job
rather than sending what's already sitting there.

## The reusable opt-in notice (`Consent\Notice`)

The SDK ships a ready-made `admin_notices` prompt (`Notice::render_prompt()`) rather than leaving
each consumer to build their own. The reasoning: every consumer who hand-rolls a consent UI is a
chance to get it wrong — a pre-checked box, vague wording, a "no thanks" that's harder to find than
"allow" — and a botched consent flow is what gets *that consumer's* plugin pulled from
WordPress.org, not the SDK's. Shipping one honest, correct implementation means every consumer gets
it right by default.

The shipped prompt:

- names the categories of data that would be sent (install ID, plugin version, WP/PHP versions,
  locale, multisite flag, feature usage) and explicitly states what is never sent (site address,
  email, user accounts, content);
- is only shown when the author has enabled the project (`Config::enabled()`) and the admin hasn't
  already answered for the current policy version (`Gate::answered()`);
- is suppressed entirely when `CX_TRACKER_DISABLE` is set;
- offers "Allow" and "No thanks" as two equally-weighted submit buttons in the same form area — no
  pre-selection, no nagging on every subsequent page load once answered.

`Notice` also renders two unrelated but similarly "ship it once" concerns: a server-supplied
deprecation/advisory message (the only channel that reaches a site running a years-old bundled copy
of the SDK), and a `WP_DEBUG`-only developer warning when `Config` fails validation.

## The deactivation-feedback modal is a separate consent basis

`Tracker::hook()` also registers [`Feedback\Deactivation`](../src/Feedback/Deactivation.php), which shows a
dialog on this plugin's row in `plugins.php` asking why an administrator is deactivating. That dialog is **not**
gated by `Gate::granted()` -- it does not require gate 2, and does not even require the site to have ever been
asked. Its consent basis is the submission itself: the administrator reads an itemised disclosure of exactly what
will be sent and presses a button, which is contemporaneous, informed consent for that one transmission --
stronger than a general opt-in recorded months earlier, not weaker.

Two things from this document still apply to it: `CX_TRACKER_DISABLE` wins unconditionally over the modal too,
and gate 1 (`Config::enabled()`) still governs whether it can appear at all -- if the author never enabled the
project there is nowhere to report to. Full reasoning and the exact payload live in
[`FEEDBACK.md`](FEEDBACK.md); do not assume anything said above about gate 2 or `Notice` applies to it.

## What a consumer must do to be WordPress.org compliant

1. Pass `enabled` deliberately (gate 1) — do not default it to `true` in a way that makes gate 2 the
   only real gate.
2. Do nothing else for the opt-in UI — `Tracker::hook()` wires `Notice::render()` into
   `admin_notices` and the `admin_post_cx_tracker_consent_{slug}` handler automatically once
   `Tracker::init()` is called on an admin request. The deactivation-feedback modal is wired the same
   automatic way (`admin_footer-plugins.php` and its submit handler) -- see the section above and
   [`FEEDBACK.md`](FEEDBACK.md).
3. **Disclose the external service in `readme.txt`.** WordPress.org requires plugins that transmit
   data to a third-party service to declare it in the "External services" section of `readme.txt`,
   with what is sent, when, and a link to the service's terms. See
   [`readme-txt-block.md`](readme-txt-block.md) for a copy-paste block sourced from
   [`EVENTS.md`](EVENTS.md)'s field list.
4. Not bypass the gate — e.g. by calling `track()` before `Tracker::init()` has registered the
   consent hooks, or by wrapping `CX_TRACKER_DISABLE` in application logic that could unset it.

Because the SDK is the only part of this project a WP.org reviewer ever sees, everything above is
not a suggestion — it is the difference between a consumer's plugin passing review and being
rejected or pulled for an undisclosed external service.
