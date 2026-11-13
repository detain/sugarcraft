# candy-discord — program spec of record (bundle excerpt)

**Provenance.** The channel-bundle program law lived in `code-chat.md` §§7–12 at the
codechat program root — an untracked working file that has since been deleted with
that program's checkout. This document is the bundle's local spec of record: each
section below restates, from the implementation's own binding docblocks, the rule
the code honors at its cited sites. It is an excerpt scoped to what this bundle
implements; the full program history (research, rulings, review trail) is not
reproducible from this repo and is host scope. Cite as `docs/SPEC.md §N.N`.
Source: `code-chat.md` §§7–12.

## §2.3 — Redaction and session grammar

Configured secrets are redacted from everything the bridge displays or logs.
Serve session names created on a user's behalf carry the session-name grammar
stamp, so a channel-created session is recognizable at the serve surface.

## §6.4 — Inbound text is untrusted

Text arriving from a chat platform is untrusted input: it never executes as
command grammar and it rides the prompt fence like any other payload.

## §7 — The channel face

A bundle face is one account's front door: gateway dispatches in, REST out.
The face never grows a server surface of its own; the host process owns serving.

## §7.1 — Endpoints and the messageContent posture

The API root and gateway URL resolve from settings with environment overrides
(`DISCORD_API_URL`, `DISCORD_GATEWAY_URL`); the version lives in the URL root.
When the `messageContent` intent is declined at configuration or discovered-refused
by Discord (the 4013/4014 ladder), the face starts — and stays — in the degraded
mention-only posture, forced EVERYWHERE with no per-guild override.

## §7.2 — Gateway v10 and token custody

The gateway session is v10 over WSS: hello → identify → heartbeat → dispatch,
with resume. The `?v=10&encoding=json` pair is a contract; the READY payload's
version field is not trusted. Bot tokens live in the 0600 channel store; the
environment fallback applies to the default account only.

## §7.3 — DM pairing

Pairing is the default DM gate: an unpaired peer receives a pairing code and
never reaches the model. Codes are eight characters drawn from an unambiguous
alphabet (no `0/O/1/I`),
live one hour, at most three are pending per peer, and at most one reminder
leaves per hour. An approver redeems a code with `/pair grant <code>`; the
approved allowlist lands in the user-tier store file, never in config.json.
Codes are redaction secrets the moment they are minted. The pairing surface is
account-local: a code answers only at the account that issued it, and webhook
or integration posts are never treated as a human peer.

## §7.4 — Bot-loop floor and the guild door

Our own messages are ALWAYS ignored. `allowBots` is three-state: `true` admits
bots, `false` refuses them, `"mentions"` admits a bot only when it addressed
us. Other-mention tokens are stripped so the transcript carries no addressing
noise. Guilds follow the `groupPolicy` allowlist with per-guild
`requireMention` (default true), `allowChannels`, and `ignoreOtherMentions`.

## §7.5 — Threads, ephemerality, single-use cards

One thread, one session: a thread's channel id IS the conversation key.
Outbound replies carry `message_reference` so they stay inside the thread that
asked. The idle-hours archive sweep proves thread-ness with a GET before its
PATCH. Approval cards are single-use: answering retires or disables the
presented prompt, and the expiry timer retires it when nobody answers.
Interaction answers are ephemeral by default.

## §7.6 — Command vocabulary

Native slash commands deploy with a global PUT on boot (owner ruling D7=7a),
AND every slash word keeps a text fallback that works through the degraded
window and for hand-typed commands.

## §7.7 — Limits and rate posture

A Discord 429 surfaces as a `RestException` with its Retry-After logged; the
bundle never sleeps or retries in-band. Outbound text is chunked under the
declared 2000 UTF-16 units / 17 lines before it reaches the face. Embeds are
suppressed when configured, and `allowed_mentions.parse = []` so model output
cannot ping humans.

## §7.8 — Media intake

At most four images and two PDFs ride a message, capped by declared AND
actually-fetched bytes. Downloads reserve against an account-wide batch budget,
media rides settled batches (nothing is fetched before admission), and every
skip is disclosed to the asker, names-only.

## §7.9 — Approval presentation

Render-once: the bridge's presented-asks gate is upstream of the face. When an
approver roster is configured it is stamped onto each button as
`allowed_users` — a courtesy filter; the router-side check is the door. With
an empty roster any paired user in the asking conversation may answer
once/deny and `always` is refused. The text fallback line is never a silent
bypass. The card's answer window self-clamps to the core expiry ceiling
(§11.6).

## §7.10 — Slash deployment lifecycle

The slash roster deploys on the face's boot lifecycle, and disabling or
removing a face walks the deployment back.

## §7.11 — Multi-account

Accounts are discovered from the store; `accounts.<id>.*` override rows win
over the shared configuration. The account id rides the conversation key, so
two accounts on one instance never share a keyspace.

## §9.1 — Key grammar

Every bound conversation carries the key
`<platform>:<account>:<channel-or-thread-id>`. DMs follow the `dmScope`
ladder (default: per peer).

## §9.2 — Queue vocabulary

Arrival uses one vocabulary — `steer`, `queue`, `collect`, `interrupt`. The
collect gate debounces a 500 ms window capped at 20 items, the window opens
when the FIRST item arrives, and only plain text merges.

## §9.3 — One rule per platform

A session's next turn is delivered to whichever platform its conversation
belongs to; the busy×mention matrix maps onto the §9.2 vocabulary.

## §9.4 — Shared composition

All platform faces receive text ALREADY chunked under the face's declared
ceiling. Redaction rides the outbound door. Serve media caps forbid surprise
merges.

## §9.5 — Log and echo hygiene

Every log line is content-free: event name plus scalars, never message
bodies. Outbound surfaces never repeat a secret the channel does not already
own — the pairing confirmation does not echo the code.

## §9.6 — Approval binding

The clicker's identity comes from the authenticated interaction itself and is
filtered against the configured approver roster before anything is answered.

## §11 — Ship form

P2: the bundle is trusted code running INSIDE the candy-code host process via
a channel entrypoint — not a standalone server (program ruling D2, no
composer install step for the host).

## §11.6 — The co-TTL pair

The core refuses an open approval at `PendingAsk::EXPIRY_SECONDS` (1800 s)
from `askedAt`; a channel card's advertised window follows that ceiling —
silently in the safe direction, loudly refusing when a configured window
would lie about how long an answer stays valid.
