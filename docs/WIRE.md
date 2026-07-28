# Wire contract

What the SDK sends and how it interprets what comes back. Namespace `plugin-tracker/v1`.

> **Backend status: implemented.** `Plugin_Tracker\API\Telemetry` in `plugins/plugin-tracker` serves
> all three routes, and `Plugin_Tracker\Bootstrap\Installer` creates the four tables PLANS.md §12
> names. This is the server half of issue #40 (telemetry SDK + ingestion + consent).
>
> An earlier revision of this document attributed ingestion to issues #43 and #44. That was wrong:
> #43 is the background-jobs framework and #44 is the v2 namespace. Ingestion is #40.
>
> **These routes stay on `plugin-tracker/v1` permanently.** #44 puts new endpoints on v2, and these
> are the exception because the client half already shipped: a consumer bundles this SDK into their
> plugin and freezes it at whatever version they downloaded, on sites nobody can ask to update
> (§10.2). A v2 alias may be added; v1 cannot be retired, and ingestion must accept every payload
> version ever served.

## Credential model, and the conflict it resolves

Issue #40 says *"Issue key: secret shown once · store hash"* → *"SDK in author's plugin"*. Spec §10.5 says embedding a
secret in the download is a **rejected** design, because the author bundles our artifact into their plugin, and if that
plugin is listed on WordPress.org the zip is published and anyone can read the key out of it.

**Both are satisfiable, and this is how.** The author's secret exists and is shown once — but it never ships. It
authenticates the *author*, in the dashboard. What ships is a non-secret `hash` baked into the generated snippet
(see [`SNIPPET.md`](SNIPPET.md)), and each install exchanges it for its own token:

| Credential                                | Secret? | Where it lives                                       | Scope                                           |
|--------------------------------------------|---------|------------------------------------------------------|-------------------------------------------------|
| Author project secret                     | **yes** | Dashboard only, hash stored server-side, shown once  | Provisioning and rotation, never in a plugin    |
| Plugin hash (`Config::HASH_PATTERN`, hex) | no      | Baked into the generated snippet; readable by anyone | Says which plugin reports. Useless alone.       |
| Install token                             | **yes** | Generated per install, stored in that site's options | Authenticates ingestion for exactly one install |

**`project` (`pt_proj_…`) is a legacy, optional identifier.** It predates the snippet, and `Config` still accepts
and validates it (`Config::PROJECT_PATTERN`) so an integration built before `hash` existed keeps working -- but it
is never part of what the SDK transmits. `hash` is the identifier on the wire.

Why this is strictly better than shipping the secret, in the terms #40 uses:

- **Revocation** — revoking one install's token blocks that install. Revoking a shipped secret breaks every site running
  the plugin at once.
- **Rate limiting** — per install, so one abusive site cannot exhaust the project's budget.
- **Leak blast radius** — a token read off one site cannot be replayed for another. A leaked shipped secret is valid
  everywhere, forever, and is already public.
- **Rotation** — #40 wants a short overlap then old-invalid. Rotating the *project* means new installs get tokens under
  the new epoch while existing tokens stay valid through the overlap; no consumer has to ship an update, which is
  impossible to coordinate anyway.

## `POST /telemetry/register`

Called once per install, **after** both consent gates pass. Idempotent.

Unauthenticated — this is the call that obtains the credential. It must therefore be rate-limited by source and by
`hash` on the server.

```json
{
  "schema":  1,
  "sdk":     "1.0.0",
  "hash":    "a1b2c3d4a1b2c3d4a1b2c3d4a1b2c3d4",
  "install": "ins_9f8e7d6c5b4a39281706f5e4d3c2b1a0",
  "plugin":  "my-plugin",
  "consent": { "policy": 1, "at": 1769472000 }
}
```

`consent.policy` records **which** consent text the admin agreed to, so a later policy change is detectable rather than
assumed. `consent.at` is when they agreed.

Success — `200`:

```json
{ "success": true, "data": { "token": "ins_tok_…", "flush_interval": 86400 } }
```

The SDK stores `token` and honours a server-supplied `flush_interval` if present, which lets the backend widen intervals
under load without a consumer update.

**Idempotency:** re-registering the same `install` for the same `hash` must return a valid token rather than
erroring. Registration re-runs whenever the SDK finds itself with consent but no token, so it must be safe to call
repeatedly.

**Fail closed:** no token means no transmission. Events continue to queue locally, capped, and the SDK retries
registration on the next scheduled run. It must never block, delay, or break the consumer's activation path.

## `POST /telemetry/events`

```
Authorization: Bearer <install token>
Content-Type: application/json
```

Body is the envelope from [`EVENTS.md`](EVENTS.md). Max 50 events, max 64 KB encoded.

Success — `200`:

```json
{
  "success": true,
  "data": {
    "accepted": 12,
    "rejected": 0,
    "notice": { "level": "warning", "message": "SDK 1.0.0 is deprecated…", "until": 1790000000 }
  }
}
```

`notice` is the deprecation channel §10.2 requires. It is the **only** way to reach a site running a two-year-old
bundled copy, since there is no `composer update`. When present the SDK surfaces it to the plugin owner in wp-admin and
stops re-showing it after `until`.

Partial acceptance is expected and normal: ingestion applies the event allow-list per event, so
`accepted` + `rejected` may both be non-zero. **The SDK treats a partial accept as success and discards the batch** —
retrying a batch containing one disallowed event would retry forever.

## Response parsing — the trap

> **Spec §10.6 is wrong on this point and following it would cause a silent bug.** It says to key
> off `success` rather than HTTP status. That is correct for anything the route callback decides, and
> **wrong for every authentication failure** — the case the SDK cares about most.

The backend has **two** response shapes, not one:

**Application shape** — anything inside the callback, via `Rest::response_error()`
(`app/Traits/Rest.php:42-56`, which calls WP core's `wp_send_json_error`):

```json
{ "success": false, "data": { "message": "…" } }
```

**WordPress core shape** — a `permission_callback` returning false never reaches the callback.
`WP_REST_Server` converts it to a `WP_Error` and serialises it itself:

```json
{ "code": "rest_forbidden", "message": "…", "data": { "status": 401 } }
```

**There is no `success` key.** A client keying only off `success` reads a 401 as "no `success:true`, so… something" and
cannot distinguish "my token is revoked" from "the server is broken" — so it retries a revoked token forever instead of
stopping and re-registering.

`Http\Transport` therefore resolves in this order:

1. Transport-level `WP_Error` (DNS, timeout) → retryable.
2. HTTP **401/403** → **auth failure**, whatever the body says. Discard the stored token, stop sending, re-register on
   the next run. Not retryable with this token.
3. HTTP **429** → back off, honour `Retry-After`, keep the batch.
4. `success === true` → accepted.
5. `success === false` → rejected by the application. Not retryable; the batch is malformed and retrying cannot fix it.
6. A body with `code` and `message` but no `success` → core-shape error. Map by
   `data.status`.
7. Anything else, including unparseable JSON → treat as a retryable server fault, capped by the retry budget.

Rule of thumb: **status decides auth and rate limiting; `success` decides application outcome.**
Neither alone is sufficient.

## Retry and backoff

- Retryable failures only: transport errors, 429, 5xx.
- Exponential backoff with **full jitter**, base **60s**, doubling per attempt. Max **6 attempts**,
  after which the batch is dropped and counted. Because there are only 6 attempts the largest window
  actually used is **960s** (60 x 2^4), so `RETRY_CAP` (21600s / 6h) is unreachable by exponential
  growth and exists solely to clamp a server-supplied `Retry-After`.
- Full jitter means a random point in `[1, window]`, not the window itself. It is required, not
  cosmetic: without it every site that installed the plugin on the same day retries in lockstep and
  the backend sees a thundering herd on recovery.
- **The SDK never retries inside a page request.** Retries happen on the scheduled job. Because the
  normal interval is a day, a retryable failure schedules a *short* backoff rather than waiting for
  the next full interval -- otherwise a transient blip would cost a day.

### `Retry-After`

Honoured on a 429, and **capped at 21600s** like any other backoff, so one bad header cannot park a
site's queue for days.

Read from the HTTP `Retry-After` header first, falling back to `data.retry_after` in the body: the
header is the standard (RFC 9110), and the body field is accepted because this backend's convention
is to put everything in the JSON envelope and a proxy may strip headers.

**Only the delta-seconds form is honoured.** The HTTP-date form is legal but is not parsed -- a
mis-parsed date could yield a delay of years, and silently parking a queue forever is worse than
ignoring the hint and using normal backoff.

### What consumes the budget, and what does not

| Result | Batch | Retry budget | Counted as dropped |
|---|---|---|---|
| `OK` | discarded (accepted) | **reset** | no |
| `RETRY` / `RATE` | kept | +1, dropped at 6 | only on the 6th |
| `AUTH` (401/403) | **kept** | **untouched** | no |
| `PERMANENT` | dropped | reset | **yes** |

Two rows are deliberate and easy to get wrong:

- **An auth failure consumes no budget and drops nothing.** A revoked or rotated token is a
  credential problem, not a delivery problem, and the queued events are still good. Counting it
  would throw away valid data because of a key rotation. The token is discarded and re-registration
  runs on a short backoff, not a full interval -- a rotation should not cost a day. If
  re-registration then fails, the full interval applies, so a project disabled server-side does not
  become a tight loop.
- **A permanent rejection is counted as dropped**, exactly like budget exhaustion. Both are events
  that existed and will never arrive. Counting only budget exhaustion would make a server rejecting
  malformed batches look like no data loss at all.

The dropped counter is local and never transmitted -- reporting it would need the very transport
that just failed. It exists so a developer asking "why is there no data" can find the answer on the
site itself.

## What the SDK must never do

- Send anything before both consent gates pass.
- Send during activation, deactivation, or any front-end page load.
- Fatal, warn, or block if the endpoint is unreachable, slow, or returns nonsense.
- Depend on Action Scheduler. `plugin-tracker` requires it, but a third-party consumer may not have it, so the SDK uses
  WP-Cron only.
- Retain a token after a 401/403.
