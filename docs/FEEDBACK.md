# Frozen deactivation-feedback contract

**Version: `1`.** This document is the authority for what the deactivation-feedback modal transmits. It is a **second,
separate transmission** with a **different consent basis** from the anonymous telemetry stream in
[`EVENTS.md`](EVENTS.md), and it is documented separately for exactly that reason: folding it into `EVENTS.md` would
mean one document describing two things that must never be conflated.

Implemented by [`Feedback\Deactivation`](../src/Feedback/Deactivation.php). If this document and that code ever
disagree, the code is right and this file is stale.

> **Backend status: implemented.** `Plugin_Tracker\API\Telemetry::feedback()` serves this route and
> stores rows in `tracker_telemetry_deactivation_feedback`.
>
> **The join-key rule is enforced on both sides.** That table has no install column, and the route
> refuses any request carrying an `install` field or an `Authorization` header — so if a future change
> to this SDK ever regressed the payload, ingestion would reject the request rather than write the row
> that de-anonymises the stream.

---

## Why this is not an event

`EVENTS.md` makes two decisions that look like they block a deactivation survey outright:

- **the site URL is dropped** — `home_url()` is refused because it is directly identifying;
- **`note` is refused** — `Event::validate_props()` returns an error for a `note` key on a `deactivation` event,
  because a free-text box is the most likely place for a user to type PII and the SDK cannot inspect what they typed.

Both decisions are correct **for that stream** and **neither is weakened**. `Event::validate_props()` still rejects
`note`, and there is a regression test aimed squarely at anyone who later tries to "just add `note` to the deactivation
event."

The resolution is not an exception to those rules. It is the observation that telemetry and feedback are two different
things that happen to be triggered near each other:

|                     | Telemetry (`EVENTS.md`)                                | Feedback (this document)                                    |
|---------------------|--------------------------------------------------------|-------------------------------------------------------------|
| Who starts it       | The SDK, on a schedule                                 | The administrator, by pressing a button                     |
| Is anyone watching  | No — it runs on WP-Cron                                | Yes — they are reading the disclosure as they submit         |
| How often           | Repeatedly, for the life of the install                | Once, ever                                                  |
| Consent basis       | An opt-in recorded in advance (`Gate`)                 | The submission itself, made after reading what it will send  |
| Identifiability     | **Anonymous by design.** Must stay anonymous forever   | **Identified on purpose.** It is a message from a known site |
| Route               | `POST /telemetry/events`                               | `POST /telemetry/feedback`                                   |
| Queued / retried    | Yes, with a retry budget                               | **Never.** Fire and forget                                   |
| Free text           | **Refused**                                            | Accepted, bounded to 1000 characters                         |

Because the two have different consent bases and different identifiability, they get different routes and different
payloads. A shared pipe would mean the anonymous stream starting to carry a site address and free text, which is the
thing `EVENTS.md` exists to prevent.

---

## The join-key rule

**This is the most important rule in this document.** Get it wrong and the anonymity of the entire telemetry stream is
destroyed retroactively.

The feedback payload carries `site` (the site address). The telemetry payload carries `install` (an anonymous
HMAC-under-a-local-salt identifier — see [the install ID](EVENTS.md#the-anonymous-install-id)). **Neither payload may
ever carry both.**

If one feedback submission carried `site` and `install` together, the backend could join them and de-anonymise **every
telemetry row ever received from that install**, for the whole history of the stream. The HMAC design in
`Storage\Install` would remain mathematically sound and completely pointless, because we would have been handed the
answer.

Consequences, all of them enforced in `Deactivation::payload()` and asserted by tests:

- `install` is **absent** from the feedback payload.
- The per-install **bearer token is absent too**, and this route is therefore **unauthenticated**. That is deliberate
  twice over: the token resolves to an install id server-side, so sending it would be sending the install id by another
  name; and a site that never opted into telemetry has no token at all, so requiring one would silence exactly the
  administrators most likely to have something worth hearing.
- **The backend cannot correlate feedback with telemetry.** That is a feature, not a limitation to engineer around. A
  future request for "show me this install's events next to their feedback" must be refused.

Because the route is unauthenticated, **the server must rate-limit it by source and by `project`/`hash`**, exactly as
`WIRE.md` requires for `/telemetry/register`. The SDK cannot enforce that; it is recorded here because it is the
obligation that comes with an open route.

---

## Consent: this is not behind the telemetry gate

**Decision: feedback does NOT require `Gate::granted()`.** Stated plainly because it is the one place this SDK
deliberately does something other than "check the gate," and a reviewer deserves to find the reasoning rather than infer
it.

### The case for gating it

It is still data leaving the site, and the double consent gate is the SDK's whole compliance story
([`CONSENT.md`](CONSENT.md)). Consistency is worth something, and "we made an exception" is how compliance stories rot.

### The case against, which wins

1. **The gate exists for a situation this is not.** `Gate` requires consent *in advance* because passive collection
   happens when the administrator is not present, so their agreement cannot be obtained at the moment it matters.
   Feedback is the opposite in every respect: they are present, they are reading the itemised disclosure, they typed the
   message themselves, and the button says what it does. Consent given **at the moment of transmission, for that
   specific transmission** is a *stronger* basis than a general opt-in recorded months earlier, not a weaker one. This
   is why Freemius can ask for deactivation feedback without any prior telemetry opt-in.
2. **Gating it silences the wrong people.** An administrator who declined telemetry, or was never asked, is *more*
   likely to have something the developer needs to hear — and "it broke my site" is the single most valuable thing this
   SDK can carry.
3. **Gating it would be a dark pattern.** A dialog that says "agree to usage tracking before you can tell us why you
   are leaving," shown at the moment someone is trying to leave, is the kind of thing that gets a *consumer's* plugin
   pulled from WordPress.org. The modal must never become an opt-in nag.

### What still applies, and is not negotiable

| Check | Why it survives the decision |
|---|---|
| `CX_TRACKER_DISABLE` | Checked **first and unconditionally**. A site owner or host must be able to stop *all* outbound transmission from this SDK without touching plugin settings. `CONSENT.md` documents this switch as absolute — if feedback ignored it, the switch would be a lie. |
| `Config::enabled()` (consent gate 1) | If the author never enabled the project there is nowhere to report to and the SDK must be inert. Feedback is not a route around gate 1. |
| The disclosure | The modal itemises what will be sent, with **real values**, before the button exists to press. Consent that is not informed is not consent. |
| `cx_tracker_feedback` filter | Applied **last**, so reaching `true` already means every check above allowed it. A callback can only turn feedback **off**; it can never grant what gate 1 refused. |

### An earlier telemetry opt-out does not block a submission being made right now

A recorded opt-out is a decision about a *different* transmission, taken earlier. The click in front of us is both more
recent and more specific. The modal says so explicitly rather than leaving it implied: *"it is separate from usage
tracking, which is unaffected by this and stays as you set it."* A site owner who disagrees with that reading has
`CX_TRACKER_DISABLE` and the `cx_tracker_feedback` filter.

### The asymmetry that proves the rule

The chosen reason is *also* useful on the telemetry stream, where `reason` is an allow-listed field that nothing
otherwise populates. `Deactivation::handle()` therefore stashes it in an option for
`Lifecycle::on_deactivate()` to pick up — and **that write is consent-gated**, even though the feedback submission next
to it is not.

That is the point rather than an inconsistency. Anything destined for the **anonymous stream** is collected only with
the telemetry opt-in, because `CONSENT.md` requires consent to precede *collection*, not merely transmission. The
free-text comment is never stashed and never reaches that stream at all.

---

## `POST /telemetry/feedback`

Called at most once per deactivation, only when the administrator submitted something. Unauthenticated. No envelope
wrapper — feedback is a single object, not a batch, because there is nothing to batch.

```json
{
  "schema":         1,
  "sdk":            "1.0.0",
  "project":        "pt_proj_1a2b3c",
  "hash":           "a1b2c3d4a1b2c3d4a1b2c3d4a1b2c3d4",
  "at":             1769472000,
  "site":           "https://example.com",
  "plugin":         "my-plugin",
  "plugin_version": "2.4.1",
  "wp":             "6.5.2",
  "php":            "8.1",
  "locale":         "de_DE",
  "multisite":      false,
  "reason":         "broke_site",
  "note":           "Fatal on the checkout page after updating."
}
```

`reason` and `note` are **both optional and independently omitted when empty**. A submission with neither is not
transmitted at all — there is nothing to say, and sending the site's address to report that would be transmission
without a purpose.

### Field-by-field

| Field            | Type   | Keep/drop | Why                                                                                                                                                                                                                                                          |
|------------------|--------|-----------|--------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `schema`         | int    | **keep**  | This document's version, currently `1`. Independent of `Event::SCHEMA`: a different payload on a different route versions separately, so a telemetry schema bump does not falsely imply a feedback change.                                                    |
| `sdk`            | string | **keep**  | SDK semver. Which bundled copy produced this, which matters because consumers freeze whatever version they downloaded, permanently (§10.2).                                                                                                                   |
| `project`        | string | **keep**  | Public, non-secret project identifier. Says which project the feedback is for.                                                                                                                                                                               |
| `hash`           | string | **keep**  | The dashboard-issued plugin hash from the pasted snippet. Public and non-secret by construction (`Config::HASH_PATTERN`) — it ships inside the consumer's plugin, and if that plugin is on WordPress.org the zip is published. Tells the dashboard which plugin record this feedback belongs to. |
| `at`             | int    | **keep**  | Unix UTC when it was submitted. One transmission, so there is no send/occur distinction to make.                                                                                                                                                              |
| `site`           | string | **keep**  | **The field that most needs justifying, and the reason this document exists.** Dropped from the anonymous stream by `EVENTS.md` because it is identifying — and kept here because feedback *is* identified: it is a message from an administrator to a developer, and a developer who cannot tell which site said "it broke my site" cannot act on it. Kept only because the administrator sees it verbatim in the modal and presses the button anyway, and only because `install` is absent (see the join-key rule). |
| `plugin`         | string | **keep**  | The consumer's own slug, which the consumer declares.                                                                                                                                                                                                        |
| `plugin_version` | string | **keep**  | Which version they gave up on. The single most useful correlate for "did release 2.4.1 break something".                                                                                                                                                      |
| `wp`             | string | **keep**  | Same justification as `EVENTS.md`: which WordPress versions the consumer must support, and a compatibility break is a common reason to deactivate.                                                                                                            |
| `php`            | string | **keep**  | `PHP_MAJOR.PHP_MINOR` only. Patch is dropped as needless precision and fingerprint surface, exactly as on the telemetry stream.                                                                                                                               |
| `locale`         | string | **keep**  | A `confusing` reason from a site in an unsupported language is a translation problem, not a UX problem. Without the locale those two are indistinguishable.                                                                                                    |
| `multisite`      | bool   | **keep**  | `broke_site` on multisite is a different bug from `broke_site` on a single site.                                                                                                                                                                              |
| `reason`         | string | **keep**  | One of the **closed** set in `Event::REASONS`: `temporary`, `no_longer_needed`, `found_better`, `broke_site`, `confusing`, `missing_feature`, `other`. Validated server-side against that constant, never trusted from the POST body. An unrecognised value is **dropped, not repaired** — the key is omitted rather than sent with a sanitised imitation. Deliberately the same set the telemetry `deactivation` event uses, so the two never disagree about what a reason is. |
| `note`           | string | **keep**  | Free text, **bounded to 1000 characters**, stripped of markup and control characters, multibyte-safe. Accepted **only** here, **only** because the administrator typed it and pressed a button that said it would be sent. See the bounds section below.        |

### Explicitly dropped, and why each is tempting

| Dropped | Why it is refused |
|---|---|
| `install` (the anonymous install ID) | **The join-key rule.** Sending it alongside `site` would de-anonymise the entire telemetry stream for that install, retroactively. This is the single most important omission in this contract. |
| The per-install bearer token | Same reason: it resolves to an install id server-side, so it *is* the install id. Its absence is also what lets a site that never opted into telemetry send feedback at all. |
| Admin email / any email address | The obvious thing to want — Freemius collects it — and still refused. Three reasons: the administrator did not *type* it, so it is not covered by "they submitted this"; `get_option( 'admin_email' )` is PII the SDK would be volunteering on their behalf; and there is no reply mechanism in this contract, so it would be collected for a purpose that does not exist. A future schema bump **may** add a `contact` field, but only as a **separately checkboxed, typed-in** address — never one derived from the site's options. |
| Username, display name, user ID | PII, and irrelevant: the question is what went wrong with the plugin, not who was logged in. |
| IP address | PII under GDPR. Note the *server* sees the source IP on every request regardless — ingestion must not log or store it. That obligation is on the backend, recorded here because the SDK cannot enforce it. |
| Active theme, list of other active plugins | Genuinely useful for diagnosing `broke_site`, and still refused, for the same reason `EVENTS.md` refuses it: the set of active plugins is close to unique per site. Here it would be worse than on the telemetry stream, not better — combined with `site` it is a full profile of the installation. A developer who needs a conflict list should ask for it in a support ticket, where the administrator can decide what to share. |
| PHP error log, last fatal, stack trace | The most requested thing for a `broke_site` reason. Refused because an error log is unbounded, uninspectable content that routinely contains file paths, database credentials, and user data. The SDK cannot promise anything about what is in it. |
| Post/page/user counts | Business-sensitive for the site owner, and not ours. |
| Licence key, any credential | Never. |

---

## Bounds on the free text

| Bound | Value | Enforced where |
|---|---|---|
| Maximum length | **1000 characters** (`Deactivation::NOTE_MAX`) | Server-side in `normalize_note()`. The textarea's `maxlength` attribute advertises the same number, but it is a client-side hint only — a crafted POST ignores it, so the bound is applied again where it counts. |
| Markup | Stripped | `sanitize_textarea_field()`, then a control-character pass. |
| Encoding | Truncated **multibyte-safely** | `mb_substr()` where mbstring exists. A byte-boundary cut would split a multibyte character, produce invalid UTF-8, and make `wp_json_encode()` refuse the whole payload — silently losing the submission instead of shortening it. |
| Type | Non-strings discarded | A crafted `note[]=a&note[]=b` arrives as an array and becomes `''`. |

Sanitisation runs **before** truncation, so the advertised limit describes the string that is actually transmitted
rather than one that no longer exists after tags are removed.

**A bound does not make free text safe.** Nothing does — which is exactly why `EVENTS.md` refuses it on the anonymous
stream. What the bound does is keep one submission from becoming an unbounded upload, and make the modal's stated limit
a fact rather than a hope. The comment is safe *here* because of who sent it and what they were told, not because of
its length.

---

## Not queued, not retried

Unlike every telemetry event, a feedback submission is transmitted once, synchronously, and forgotten. If it fails, it
is lost.

- **Queueing it would mean persisting whatever a human typed** into `wp_options` on a site whose administrator is
  walking away. The SDK cannot inspect that text, so the only safe place for it is nowhere.
- **Retrying it would mean transmitting after the plugin has been deactivated** — after the relationship the message was
  about has ended.

A dropped message is the better failure. It also makes the never-block guarantee trivial: there is no state for a
failure to leave behind.

The outbound timeout is **5 seconds** (`Deactivation::TIMEOUT`), shorter than `Http\Transport::TIMEOUT` (8s). That one
runs on WP-Cron where nobody is waiting; this one runs in a request whose caller has already navigated away, so every
second past the first is a PHP worker held open for a response nobody will read.

---

## Deactivation is never blocked

Ranked first among the requirements, so it is structural rather than defended by careful coding. **The SDK never
renders, rewrites, wraps or server-side gates the Deactivate link.** WordPress renders it; the SDK adds a click
listener and nothing else. Every failure therefore degrades to "the link the browser already had."

| Failure | What happens |
|---|---|
| JS disabled, blocked by CSP, or never parsed | No listener is attached. The link navigates natively. |
| The inline script throws while opening the modal | The click handler's `catch` navigates to the href it captured *before* `preventDefault()`. |
| `fetch()` is unavailable | The submit is **not** intercepted, so the modal's real `<form method="post">` POSTs to `admin-post.php`, and `handle()` redirects to the deactivate URL. One extra hop, still deactivates. |
| The request hangs, 500s, or the host is unreachable | A watchdog timer, armed **before** the request is built, navigates anyway after `Deactivation::NAVIGATE_AFTER_MS` (2000 ms). The response is never awaited and never consulted. `keepalive: true` lets the POST outlive the navigation, so the message is usually still delivered. |
| Anything throws inside `handle()` | The HTTP call is wrapped in `catch ( \Throwable )`, so the method always reaches its redirect. Swallowed, but surfaced via `Notice::dev_warning()` so it is visible under `WP_DEBUG` rather than silent. |
| The user wants none of it | **"Skip & Deactivate"** is a plain `<a>` whose `href` is a real, nonced deactivate URL computed **on the server**. It works with the JavaScript entirely broken. |

**Escape and Cancel deliberately do not deactivate.** That is a user decision to abort a destructive action — the
accessible-dialog convention — and not one of the failure modes above. The Deactivate link is still sitting there,
unmodified, and can be clicked again.

The redirect target is always **derived from `Config::basename()`**, never taken from the POST body. A posted
`redirect_to` is ignored, so the endpoint cannot be turned into an open redirect that sends an administrator somewhere
else while they believe they are deactivating a plugin.

---

## Scoping: one modal per plugin row

`plugins.php` lists every plugin on the site, and several may bundle this same SDK. Everything is keyed off the
consumer's own identity:

- **Which link is intercepted** is decided by parsing each anchor's own query string and comparing its `plugin`
  parameter to `Config::basename()` — the identity WordPress itself keys the action on. Not a row selector, not a link
  id, not a slug prefix. A URL that cannot be parsed is treated as "not ours" and left native.
- **The nonce action** (`cx_tracker_feedback_{slug}`) and the **admin-post/ajax action** are per-slug, so a nonce minted
  for one plugin's modal cannot authorise a submission to another plugin's handler.
- **The DOM id and the script's root selector** are per-slug, so two copies cannot drive each other's markup.
- **A `data-cx-contract` version** gates enhancement. A copy's script refuses to touch a root whose contract it does not
  recognise, so two bundled copies at *different SDK versions* cannot drive each other's markup either. A root nobody
  enhances simply keeps its native Deactivate link — the safe outcome.
- **Nothing loads on any other screen.** The modal renders on `admin_footer-plugins.php` only; the hook name carries the
  screen's hook suffix, so the scoping is structural rather than an `is_admin()`/`pagenow` test that could be got wrong.

CSS and JS are **inlined from PHP, and duplicated per bundled copy rather than shared**. Both are deliberate:

- Inlined because `bin/build-dist.sh` ships only `find "${SRC_DIR}" -name '*.php'` plus a generated `autoload.php`, a
  generated `composer.json`, `languages/` and `LICENSE`. A `.js` or `.css` file would never reach a consumer, and an
  enqueued handle pointing at a missing file is a 404 on every `plugins.php` load.
- Duplicated because a shared, deduplicated asset would mean one copy's CSS or JS governing another copy's markup —
  precisely the cross-version coupling that `build-dist.sh` exists to eliminate.

No jQuery. It happens to be present on `plugins.php` today, but a bundled library that breaks when a site dequeues a
core script has made its consumer's problem worse, and nothing here needs it.

---

## Localisation

Copy comes from `Deactivation::strings()` and `Deactivation::reason_labels()`, both filterable, on the same mechanism as
`Consent\Notice::strings()`:

```php
add_filter( 'cx_tracker_feedback_strings', function ( $strings, $plugin ) {
	$strings['submit'] = __( 'Send &amp; deactivate', 'my-plugin' );
	return $strings;
}, 10, 2 );

add_filter( 'cx_tracker_feedback_reasons', function ( $labels, $plugin ) {
	$labels['broke_site'] = __( 'It conflicted with my theme', 'my-plugin' );
	return $labels;
}, 10, 2 );
```

The **labels** are filterable; the **reason set is not**. The form is built by iterating `Event::REASONS` and the
handler validates against `Event::REASONS`, so a consumer adding a key to the labels array cannot introduce a reason,
and removing one cannot make an offered option unsubmittable. Both filters receive the consumer's slug as a second
argument so one consumer's filter cannot affect another's.

Filtered values are escaped at the point of output, so a filter returning markup cannot become an XSS vector in
wp-admin.

---

## Accessibility

The dialog interrupts a destructive action, so it is a dialog properly:

- `role="dialog"`, `aria-modal="true"`, `aria-labelledby` on the heading and `aria-describedby` on the disclosure block,
  so the "what will be sent" list is announced as the dialog's description rather than being something a screen-reader
  user has to go looking for.
- Focus moves into the dialog on open and is **trapped** — Tab and Shift-Tab cycle within it rather than wandering back
  into the plugins table behind it.
- **Escape closes** and returns focus to the Deactivate link that opened it, aborting the deactivation.
- Three clearly distinct controls: submit-and-deactivate, skip-and-deactivate, and cancel. Skipping is exactly as easy
  as submitting, for the same reason `CONSENT.md` requires "No thanks" to be as easy as "Allow."

---

## Changing this document

Bump `schema` and add a row. The additive-only rule from `EVENTS.md` applies for the same reason: consumers freeze
whatever SDK version they downloaded, permanently, on sites we cannot reach, so ingestion must accept every payload
version ever served.

Two changes are **forbidden** rather than merely discouraged:

1. **Adding `install`, the bearer token, or anything else that links this payload to the anonymous telemetry stream.**
   See the join-key rule.
2. **Adding a field the modal does not disclose.** The disclosure is built from the same `site_fields()` the payload is
   built from, precisely so the two cannot drift; a field added outside that method would be transmitted without being
   shown, and the consent basis for this whole payload is that the administrator saw what they were sending.

And if a field *is* added, update [`readme-txt-block.md`](readme-txt-block.md) in the same change. A consumer's
WordPress.org disclosure is generated from it, and an incomplete disclosure is what gets *their* plugin pulled.
