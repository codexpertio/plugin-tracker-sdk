# Frozen event and payload contract

**Version: `1`.** This document is the authority for what the SDK transmits. Spec §10.4 makes it an acceptance
criterion, not documentation: every field below carries an explicit keep/drop decision, because the SDK is the only part
of this project exposed to WordPress.org review and every plugin adopting it inherits that exposure.

Source of truth for the event list: [issue #40](https://github.com/codexpertio/plugin-tracker/issues/40)
— *"Events: install/activate/version/compat/named-feature/deactivation"*.

> **Correction on the record.** Earlier project notes, including spec §10.6, describe "3 events".
> That was wrong. Issue #40 names **six**, and #40 is authoritative. Anything still saying three is
> stale.

## Ground rules

1. **Nothing is transmitted without both consent gates** (author enable + site-admin opt-in). See
   [`CONSENT.md`](CONSENT.md).
2. **The allow-list is closed, and so is the key set.** `Event::is_allowed()` rejects any name not
   in the table below, and `Event::validate_props()` rejects any *key* not listed for that event.
   Both matter: validating only known keys while letting unknown ones through leaves the payload
   effectively open and "no PII, ever" unenforceable. Environment fields are merged last, so a
   consumer cannot override them either.
3. **No PII, ever.** No email addresses, no usernames, no IP addresses, no site URLs, no post content. The install
   identifier is a one-way hash (below). This is a hard constraint, not a default.
4. **Additive changes only.** Consumers freeze whatever SDK version they downloaded, permanently, on sites we cannot
   reach (§10.2). Ingestion must accept **every** payload version ever served. Adding an optional field is allowed;
   renaming, repurposing or removing one is not.

## The envelope

Every request carries this envelope. `schema` is what lets ingestion stay backward compatible forever.

| Field     | Type   | Keep/drop | Why                                                                                                                                             |
|-----------|--------|-----------|-------------------------------------------------------------------------------------------------------------------------------------------------|
| `schema`  | int    | **keep**  | Payload contract version. Currently `2`. Ingestion dispatches on this. **`1` must keep being accepted** — see below. |
| `sdk`     | string | **keep**  | SDK semver, e.g. `1.2.0`. Records which version a site reports with, so "this version is dead" becomes evidence rather than assumption (§10.2). |
| `hash`    | string | **keep**  | Dashboard-issued, public, non-secret plugin identifier from the pasted snippet (`Config::HASH_PATTERN`, `/^[a-f0-9]{32,64}\z/`). Says which plugin is reporting. Useless on its own. |
| `install` | string | **keep**  | Anonymous install ID — see below.                                                                                                               |
| `sent_at` | int    | **keep**  | Unix UTC timestamp of transmission.                                                                                                             |
| `events`  | array  | **keep**  | Batch of event objects.                                                                                                                         |

**`project` is legacy and optional, and it is not on the wire.** `Config` still accepts a `project` argument (`Config::PROJECT_PATTERN`, `pt_proj_<alnum>`) for integrations built before `hash` existed, so an older integration keeps validating -- but `Tracker::envelope()` and the registration call in `Tracker::register()` both key off `hash` only. A site running an older integration that still passes `project` is not broken by this: `Config` accepts the argument, it is simply never transmitted.

Authentication is **not** in the envelope — it is a per-install bearer token in the
`Authorization` header. See [`WIRE.md`](WIRE.md).

## The anonymous install ID

```
install = 'ins_' . substr( hash_hmac( 'sha256', home_url(), $local_salt ), 0, 32 )
```

- `$local_salt` is 32 random bytes generated **on the site**, at first use, stored in the site's own options, and never
  transmitted.
- Because the salt never leaves the site, the hash **cannot be reversed or rainbow-tabled back to a site URL** even by
  us. Hashing the URL alone would be reversible in practice — the set of WordPress site URLs is enumerable — which is
  why the HMAC and the local salt are both required.
- It is stable for the lifetime of the install, so counts are meaningful; it changes if the salt is deleted, which is
  the intended effect of a privacy erasure request.

**Decision: `home_url()` itself is dropped.** It is the obvious thing to send and it is identifying. Ingestion never
learns which site reported.

## Common event fields

Every event object carries these. All are environment facts, none identify a person.

| Field            | Type   | Example      | Keep/drop | Why                                                                                   |
|------------------|--------|--------------|-----------|---------------------------------------------------------------------------------------|
| `event`          | string | `activate`   | **keep**  | Allow-listed name.                                                                    |
| `at`             | int    | `1769472000` | **keep**  | Unix UTC when it occurred, not when it was sent. Events are batched, so these differ. |
| `plugin`         | string | `my-plugin`  | **keep**  | The consumer's own slug, which the consumer declares.                                 |
| `plugin_version` | string | `2.4.1`      | **keep**  | Adoption of the consumer's own releases.                                              |
| `wp`             | string | `6.5.2`      | **keep**  | Which WordPress versions the consumer must support. Requested by #40 (§4.7).          |
| `php`            | string | `8.1`        | **keep**  | `PHP_MAJOR.PHP_MINOR` only — patch is dropped as needless precision.                  |
| `locale`         | string | `de_DE`      | **keep**  | Where translation effort pays off. Requested by #40.                                  |
| `multisite`      | bool   | `false`      | **keep**  | Whether to test multisite. Requested by #40.                                          |
| `server`         | string | `nginx`      | **keep**  | Web server product only, from a closed list. Added in SDK 1.2.0 (`schema` 2).         |
| `theme`          | string | `astra`      | **keep**  | Active theme's slug only. Added in SDK 1.2.0 (`schema` 2).                            |

**Explicitly dropped**, and why each is tempting:

| Dropped                                    | Why it is refused                                                                                                                                                                                                           |
|--------------------------------------------|-----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `home_url()` / `site_url()`                | Directly identifying. The hashed install ID covers counting.                                                                                                                                                                |
| Admin email, any user email                | PII. Never needed for any question the dashboard asks.                                                                                                                                                                      |
| Username, display name, user ID            | PII.                                                                                                                                                                                                                        |
| IP address                                 | PII under GDPR. Note the *server* sees the source IP on every request regardless — ingestion must not log or store it. That obligation is on the backend, recorded here because the SDK cannot enforce it.                  |
| List of other active plugins               | The single most requested telemetry field and still a refusal **here**. It is a fingerprint: the set of active plugins is close to unique per site, so it re-identifies a site that the hashed install ID was designed to anonymise. Note that `FEEDBACK.md` schema 2 *does* send it — on the identified feedback payload, where the site has already named itself and the administrator read a disclosure listing it. That reversal is scoped to that payload and does not apply to this stream, where the refusal is what makes the install ID mean anything. |
| Theme version, parent theme                | The slug is sent; these two are not. They answer a debugging question, not a composition one, so they go with deactivation feedback where a bug report wants the whole picture — and each extra field narrows the crowd a site hides in.                                                                                                                                          |
| Server hostname, OS, MySQL version, server version | `server` reports the product — `nginx`, `apache` — and stops there. `SERVER_SOFTWARE` reads `Apache/2.4.41 (Ubuntu)`, and the version and distribution in it are exactly this row: needless precision that adds fingerprint surface, dropped for the same reason as the PHP patch level. |
| Post/page counts, user counts              | Business-sensitive for the site owner, and not ours.                                                                                                                                                                        |
| Exact PHP patch version                    | Needless precision; major.minor answers the compatibility question.                                                                                                                                                         |

### What `collect` governs, and what it cannot

`Config::COLLECTABLE` names `server`, `theme` and `plugins` alongside `wp`, `php`, `locale` and
`multisite`, and it defaults to `'all'`. Two of those three now mean something on this stream and one
still does not, so it is worth being exact — the loose reading has produced a real bug in each
direction already.

**`collect` is a filter, not a source.** `Tracker::common_fields()` assembles the fields and then
*removes* any collectable one the consumer switched off. It cannot add a field that is not built.

- **`wp`, `php`, `locale`, `multisite`, `server`, `theme`** are built on every event, so switching
  any of them off genuinely stops it being transmitted.
- **`plugins` is never built here**, so it is a no-op on this stream — there is nothing to remove.
  It is in the constant because the constant mirrors `Telemetry_Collect::FIELDS` on the dashboard,
  whose one selection governs both payloads, and `FEEDBACK.md`'s schema-2 row *does* carry it.
  Switching `plugins` off narrows what is kept from a deactivation-feedback submission. It cannot
  narrow this stream, because this stream never sent it.

So the promise a consumer can make in their `readme.txt` about the usage stream is: no site address,
no email, no username, no IP, no plugin list — unconditionally, whatever their dashboard settings
say. The active theme's slug and the web server's name **are** sent from SDK 1.2.0 unless the author
switches them off, which is a change from 1.1.0 and is why `schema` went to 2.

### Why the theme was added and the plugin list was not

They were one row in this table until 1.2.0 and it read as one decision. It was two.

A set of forty active plugin slugs is close to unique per site: it re-identifies an install that the
hashed ID exists to anonymise, and no amount of consent copy makes that stream anonymous again. One
theme slug is a single value from a distribution whose head is enormous — a large share of WordPress
sites run one of a few dozen themes — so it narrows a site to a crowd of millions rather than to
itself. That is a difference in kind, not in degree, which is why the answer differs.

`server` is the same shape of argument: the answer comes from a closed list of eight words and the
raw header never leaves `Environment::server()`, so what is transmitted is "nginx", not a build
string that names a host.

Both were already being collected before 1.2.0 — `Feedback\Deactivation` has sent them since 1.0.0 —
and both were already disclosed. What changed is the lane, and the reason it changed is that the
dashboard's composition panels ask "what does our install base run on", which the feedback lane
cannot answer: one site reaches it at most once, and only when its administrator chooses to write a
message on deactivation. The panels were rendering blank off a near-empty table.

The dashboard's integration guide got the old rule wrong in the other direction once
(codexpertio/plugin-tracker#166), telling authors the usage stream sent the active theme and plugin
list unless they switched them off. Half of that is now true and half is still wrong, which is a
worse trap than the original — the guide must say the plugin list is never sent here.

## The six events

> **The SDK raises five of these six itself.** [`Lifecycle`](../src/Lifecycle.php) is registered automatically by `Tracker::hook()` the moment `Tracker::init()` succeeds: it calls `register_activation_hook()` (fires `install` and `activate`), `register_deactivation_hook()` (fires `deactivation`), and hooks `init` at priority 20 (fires `version` and `compat` on drift). A consumer following [`SNIPPET.md`](SNIPPET.md) registers no hook and calls no `track()` for any of these five. **Only `feature` is raised by the consumer** — the SDK cannot know what a plugin's own features are, so that one call is theirs to make. `plugins/plugin-tracker-sdk-example` shows a real plugin wired this way.

### `install`

Raised automatically by `Lifecycle::on_activate()`, via `register_activation_hook()`, on the first activation this install has ever recorded. Distinguished by a stored marker (`Config::option( 'installed' )`), not by "is the version option empty", so a consumer clearing their own options cannot make a site look new. Fires once per install ID.

Common fields only.

**The marker records a fact, not an attempt, and that distinction is the whole event.** Telemetry
consent does not exist when the activation hook fires on a genuine first install -- the opt-in
notice renders on the *next* page load -- so `track()` declines and queues nothing. A marker written
before that answer, as it once was, claimed the install had been reported while every later
activation saw the marker and fired `activate` alone. `install` was emitted exactly once, at the one
moment it could never be delivered, and a fully consented site could sit at zero installs forever.

So the marker is written only when the event was actually recorded, and `Lifecycle::on_consent()`
backfills at the moment consent is granted. "The next activation will report it" is not an answer
for a plugin somebody installs once and leaves running: there is no next activation. Consequence,
accepted: a site that consents late reports its install late. That is later than the truth and the
earliest point at which we were permitted to observe it.

### `activate`

Raised automatically by `Lifecycle::on_activate()`, via `register_activation_hook()`, on every activation -- including the one that also fires `install`. May fire repeatedly over an install's life (deactivate → reactivate).

Common fields only.

### `version`

Raised automatically by `Lifecycle::on_init()`, hooked at `init` priority 20, when the consumer's plugin version changed from a previously-seen value — an upgrade or a downgrade. Detected on `init` rather than at activation, because a plugin updated in place — the normal case — never fires the activation hook again; an integration relying on activation alone would never see an upgrade.

| Extra field | Type   | Keep/drop | Why                                                                                             |
|-------------|--------|-----------|-------------------------------------------------------------------------------------------------|
| `from`      | string | **keep**  | The previous `plugin_version`. Makes upgrade paths visible, which a bare version number cannot. |

`plugin_version` carries the new value, so `from` → `plugin_version` is the transition.

### `compat`

Raised automatically by the same `Lifecycle::on_init()` check as `version`: the environment crossed a threshold the consumer cares about — a WordPress or PHP version change.

| Extra field | Type   | Keep/drop | Why                              |
|-------------|--------|-----------|----------------------------------|
| `what`      | string | **keep**  | `wp` or `php`. Which axis moved. |
| `from`      | string | **keep**  | Previous value of that axis.     |

### `feature`

A named feature was used. **The only one of the six the consumer raises themselves**, by calling `Tracker::track( Event::FEATURE, [...] )` directly -- the SDK cannot know what a plugin's own features are. It is also the only event carrying consumer-supplied strings, and therefore the only injection risk in the contract.

| Extra field | Type   | Keep/drop | Why                                                                                               |
|-------------|--------|-----------|---------------------------------------------------------------------------------------------------|
| `name`      | string | **keep**  | Feature identifier. **Constrained to `^[a-z0-9_.-]{1,32}$`** and rejected otherwise.              |
| `count`     | int    | **keep**  | Times used, **supplied by the consumer**. The SDK does not aggregate: every `track()` call is one queued event. To instrument a hot path, count locally and report periodically with `count`. |

> `name` is constrained deliberately. A consumer passing `$_POST['whatever']` as a feature name
> would otherwise turn this field into a channel for arbitrary user data — which would put *their*
> plugin in breach of the WP.org rules the SDK exists to keep them inside. The SDK rejects rather
> than sanitises, so the mistake is visible in development instead of silent in production.
>
> `name` is a developer-chosen constant. It must never be derived from user input.

### `deactivation`

Raised automatically by `Lifecycle::on_deactivate()`, via `register_deactivation_hook()`. Carries an optional `reason`, populated only when the deactivation-feedback modal collected one *and* the site has telemetry consent -- see [`FEEDBACK.md`](FEEDBACK.md) for the separate, unauthenticated route the modal itself uses for the free-text comment, which never reaches this stream (#40, §4.7).

| Extra field | Type   | Keep/drop | Why                                                                                                                                                                                                                                   |
|-------------|--------|-----------|---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `reason`    | string | **keep**  | One of a **closed** set: `temporary`, `no_longer_needed`, `found_better`, `broke_site`, `confusing`, `missing_feature`, `other`. Free text is not accepted.                                                                           |
| `note`      | —      | **DROP**  | A free-text box is the single most likely place for a user to type PII, and the SDK cannot inspect what they typed. If a consumer wants free-text feedback they must collect and store it themselves, under their own privacy policy. |

The deactivation survey must be dismissible and must never block deactivation.

**This is the one event sent synchronously, from the page request that deactivates the plugin.**
Every other event waits for the scheduled flush. This one cannot: `flush()` is a WP-Cron callback
attached in `Tracker::hook()`, which runs only while the plugin is active, so deactivating queues
the event and removes its only sender in the same breath. WP-Cron then fires the orphaned hook on
some later request -- `wp-cron.php` calls `wp_unschedule_event()` *before* `do_action_ref_array()`,
so the event is deleted with nothing listening -- and nothing re-arms it. Left to the schedule,
`deactivation` is not delayed; it never arrives.

So `Tracker::flush_on_deactivation()` sends it there and then, and only when something is actually
queued. The cost is up to two blocking requests at `Transport::TIMEOUT` on a terminal, one-time
admin action. A `spawn_cron()` loopback was rejected: `deactivate_plugins()` writes the shortened
`active_plugins` option *after* firing this hook, so the loopback normally loses that race,
bootstraps without the plugin, registers no callback, and WP-Cron deletes the event anyway.

## Batching and size limits

| Bound | Value | Applies to |
|---|---|---|
| `MAX_BATCH` | 50 events | one request |
| `MAX_BYTES` | 64 KB encoded | one request |
| `MAX_PENDING` | 200 events | the pending queue, oldest dropped first |
| `MAX_QUEUE_BYTES` | 256 KB encoded | the pending queue, oldest dropped first |
| `MAX_EVENT_BYTES` | 16 KB encoded | a single event, **rejected at `track()`** |

Two of these are less obvious than they look:

- **A batch over `MAX_BYTES` is trimmed, not split.** The overflow stays queued for the next
  scheduled flush, which may be a day later. Nothing is lost, but nothing is sent sooner either.
- **`MAX_PENDING` alone is not a bound on bytes.** 200 large events measured over 11 MB in one
  `wp_options` row, re-read and re-written on every push, so `MAX_QUEUE_BYTES` bounds the other
  axis. And an event larger than `MAX_BYTES` could never be sent at all, so it would wedge the queue
  permanently and silently; `MAX_EVENT_BYTES` rejects it up front and `track()` returns false.

- Flush happens on a scheduled job with jitter, **never during a page request** (§10.3), with the
  single documented exception of `deactivation` above. N consumers on one site means N
  independent queues, so the SDK must not be what site owners blame for slowness.

## Changing this document

Bump `schema` and add a row. Do not edit an existing field's meaning — some site somewhere is still sending version 1,
and will be for years.

### Schema history

| `schema` | SDK     | Change |
|----------|---------|--------|
| 1        | 1.0.0   | The original envelope and common fields. |
| 2        | 1.2.0   | Added `server` and `theme`. Additive only; no existing field changed meaning. |

**Version 1 is not deprecated and cannot be.** A built SDK artifact is frozen inside whatever plugin
bundled it, so every 1.0.0 and 1.1.0 copy in the wild keeps sending schema 1 for as long as that
release is installed — which is years, and is not something a consumer upgrade cycle fixes, because
the consumer has to ship a new version for their users to get a new SDK. Ingestion therefore treats
`server` and `theme` as optional: a missing key is stored as an empty value, never as a rejected
event.

The composition panels render an absent value as a `(none)` bucket, which deliberately merges two
different facts — "this site's SDK predates the field" and "this author switched the field off". The
wire cannot tell them apart, because a 1.1.0 artifact and a 1.2.0 artifact with `collect` narrowed
send the identical payload: no key. Both mean "not known", so one bucket is the honest answer; what
would be dishonest is reading `(none)` as a server or theme that exists and was measured.
