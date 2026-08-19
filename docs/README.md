# Plugin Tracker SDK — documentation

Six documents. They are not layers of the same explanation: each one is the authority for something
different, and where two disagree, the one named below is right.

| Document | Authority for | Read it when |
|---|---|---|
| [`SNIPPET.md`](SNIPPET.md) | the integration snippet, both routes | you are integrating, or changing what the dashboard generates |
| [`CONSENT.md`](CONSENT.md) | the two gates, and the filters around them | you are asking "can it send yet?" |
| [`EVENTS.md`](EVENTS.md) | the usage payload — **the frozen contract** | you want to know what is transmitted, or to add a field |
| [`FEEDBACK.md`](FEEDBACK.md) | the deactivation payload, a separate route and consent basis | anything about the deactivation dialog |
| [`WIRE.md`](WIRE.md) | the three HTTP routes, auth, and responses | you are working on either side of the network |
| [`readme-txt-block.md`](readme-txt-block.md) | your own plugin's WP.org disclosure | before you submit to WordPress.org |

## The distinction that catches people

**`EVENTS.md` and `FEEDBACK.md` describe different payloads on different routes with different
consent bases, and they answer the same questions differently on purpose.** The usage stream is
anonymous by construction and refuses the site address and the active-plugin list. The feedback
submission is identified by design, and carries both — the administrator reads an itemised list of
exactly those values in the dialog and presses the button anyway.

So "does the SDK send the plugin list?" has no single answer, and neither document is wrong. Read
the one that matches the payload you mean. The two are kept non-correlatable: the feedback
submission never carries the anonymous install ID, which is what would join them.

Each also versions its own `schema` independently, so a bump in one does not imply a change in the
other.

## If you are only reading one

- **Integrating a plugin** — `SNIPPET.md`, then `readme-txt-block.md` before you ship.
- **Reviewing what this collects** — `EVENTS.md`, then `FEEDBACK.md`. Both, or neither: the
  catalogue and the emitter have disagreed before, and reading half of it is how a correction
  shipped a false claim for one release.
- **Working on the backend** — `WIRE.md`.

## A note on where you are reading this

If this copy is inside an unzipped SDK folder in a plugin, it is the **downloaded, namespace-scoped**
build, and every class name in it is scoped per version. The Composer package resolves the SDK's own
unscoped namespace instead. `SNIPPET.md` covers both and says which is which; the repository README
carries the literal class names, which deliberately do not appear in a shipped artifact.
