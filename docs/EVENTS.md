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
| `schema`  | int    | **keep**  | Payload contract version. Currently `1`. Ingestion dispatches on this.                                                                          |
| `sdk`     | string | **keep**  | SDK semver, e.g. `1.0.0`. Records which version a site reports with, so "this version is dead" becomes evidence rather than assumption (§10.2). |
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

**Explicitly dropped**, and why each is tempting:

| Dropped                                    | Why it is refused                                                                                                                                                                                                           |
|--------------------------------------------|-----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `home_url()` / `site_url()`                | Directly identifying. The hashed install ID covers counting.                                                                                                                                                                |
| Admin email, any user email                | PII. Never needed for any question the dashboard asks.                                                                                                                                                                      |
| Username, display name, user ID            | PII.                                                                                                                                                                                                                        |
| IP address                                 | PII under GDPR. Note the *server* sees the source IP on every request regardless — ingestion must not log or store it. That obligation is on the backend, recorded here because the SDK cannot enforce it.                  |
| Active theme, list of other active plugins | The single most requested telemetry field and still a refusal **here**. It is a fingerprint: the set of active plugins is close to unique per site, so it re-identifies a site that the hashed install ID was designed to anonymise. Note that `FEEDBACK.md` schema 2 *does* send these — on the identified feedback payload, where the site has already named itself and the administrator read a disclosure listing them. That reversal is scoped to that payload and does not apply to this stream, where the refusal is what makes the install ID mean anything. |
| Server hostname, OS, MySQL version         | Not needed for any stated question, and adds fingerprint surface.                                                                                                                                                           |
| Post/page counts, user counts              | Business-sensitive for the site owner, and not ours.                                                                                                                                                                        |
| Exact PHP patch version                    | Needless precision; major.minor answers the compatibility question.                                                                                                                                                         |

### `collect` does not make the refused fields available

`Config::COLLECTABLE` names `server`, `theme` and `plugins` alongside `wp`, `php`, `locale` and
`multisite`, and it defaults to `'all'`. Read on its own that looks like the refusal above being
walked back — switch them on and the theme and plugin list are transmitted. They are not, and it is
worth being explicit about why, because the reading is a natural one and it has already produced a
real bug.

`collect` is a filter, not a source. `Tracker::common_fields()` assembles seven fields and then
*removes* any collectable one the consumer switched off; it never builds `server`, `theme` or
`plugins` at all, so those three are a no-op on this stream — there is nothing there to remove.
They are in the constant because it mirrors `Telemetry_Collect::FIELDS` on the dashboard, whose
selection governs both payloads, and `FEEDBACK.md`'s schema-2 row *does* carry them. Switching
`plugins` off narrows what is kept from a deactivation-feedback submission. It cannot narrow this
stream, because this stream never sent them.

So the promise a consumer can make in their `readme.txt` about the usage stream is unconditional and
does not depend on their dashboard settings: no site address, no email, no username, no IP, no theme,
no plugin list. The settings are what narrows the feedback payload, which is disclosed separately
because it is identified — see [`FEEDBACK.md`](FEEDBACK.md) and the block in
[`readme-txt-block.md`](readme-txt-block.md).

The dashboard's integration guide got this wrong in the other direction once (codexpertio/plugin-tracker#166),
telling authors the usage stream sends the active theme and plugin list unless they switch them off.
It reached the guide because the catalogue was read without reading the emitter. This section is
here so the next reader does not have to find `common_fields()` to know.

## The six events

> **The SDK raises five of these six itself.** [`Lifecycle`](../src/Lifecycle.php) is registered automatically by `Tracker::hook()` the moment `Tracker::init()` succeeds: it calls `register_activation_hook()` (fires `install` and `activate`), `register_deactivation_hook()` (fires `deactivation`), and hooks `init` at priority 20 (fires `version` and `compat` on drift). A consumer following [`SNIPPET.md`](SNIPPET.md) registers no hook and calls no `track()` for any of these five. **Only `feature` is raised by the consumer** — the SDK cannot know what a plugin's own features are, so that one call is theirs to make. `plugins/plugin-tracker-sdk-example` shows a real plugin wired this way.

### `install`

Raised automatically by `Lifecycle::on_activate()`, via `register_activation_hook()`, on the first activation this install has ever recorded. Distinguished by a stored marker (`Config::option( 'installed' )`), not by "is the version option empty", so a consumer clearing their own options cannot make a site look new. Fires once per install ID.

Common fields only.

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

- Flush happens on a scheduled job with jitter, **never during a page request** (§10.3). N consumers on one site means N
  independent queues, so the SDK must not be what site owners blame for slowness.

## Changing this document

Bump `schema` and add a row. Do not edit an existing field's meaning — some site somewhere is still sending version 1,
and will be for years.
