# candy-msteams — program spec of record (bundle excerpt)

**Provenance.** The channel-bundle program law lived in `code-chat.md` §§7–12 at the
codechat program root — an untracked working file that has since been deleted with
that program's checkout. This document is the bundle's local spec of record: each
section below restates, from the implementation's own binding docblocks, the rule
the code honors at its cited sites. It is an excerpt scoped to what this bundle
implements; the full program history (research, rulings, review trail) is not
reproducible from this repo and is host scope. Cite as `docs/SPEC.md §N.N`.
Source: `code-chat.md` §§7–12.

## §2.3 — Redaction roster and session grammar

Configured secrets (app passwords, tenant ids, the serve token) plus
runtime-issued pairing codes are redacted from everything the bundle displays
or logs. Serve session names created on a user's behalf carry the session-name
grammar stamp, so a channel-created session is recognizable at the serve
surface.

## §6.4 — Inbound text is untrusted

Text arriving from the platform is untrusted input: it never executes as
command grammar, and it rides the prompt fence like any other payload.

## §7.1 — State files are private at birth (D4)

The bridge state store (the SessionMap SQLite ledger of bindings, reply-address
refs and the inbound dedupe ledger) opens 0600 in the user tier. The rule
extends past the main file: in WAL mode SQLite creates the `db-wal`/`db-shm`
sidecars at the first write in the shape of the process UMASK, and they carry
the very rows the main file is kept private for until checkpointed away — so
the constructor re-asserts 0600 on every piece that exists, fresh or reopened
(cc-review-1111 register B-52).

## §7.2 — Trust-tier placement

The DM policy door and the approved-user roster are credential-shaped state:
they live in the 0600 user-tier channels store, never in the repository tier
(`config.json`), so a cloned repository can never widen a channel.

## §7.3 — DM pairing (shared law, restated verbatim)

Pairing codes are minted by `/pair`, are single-use, and expire on a fifteen
minute window. The notice carrying a code is the code's ONE legitimate
surface (redaction keeps an in-place roster exception at mint time); the
approved roster is the user-tier allowlist behind the DM door. Pairing is an
approver's act: the roster that answers tool asks owns admission. A message
whose author is our own bot is never admitted as a peer.

## §7.4 — Bot-loop floor

The bot's own posts are ALWAYS ignored; other bots are governed by
`access.allowBots` (`true` admits them, the default mentions-only posture
drops posts addressed to somebody else so the transcript carries no
addressing noise).

## §7.5 — Approval buttons are single-use

A presented approval retires the moment it is answered — an already-answered
ask can never be clicked a second time into a second verdict.

## §7.6 — The shared command roster

Chat commands are one identical vocabulary in both channel bundles. Commands
the platform serves natively (or not at all) stay out of the roster.

## §7.7 — Per-platform chunk ceilings

The face declares the chunk ceiling its platform tolerates; composition is
the shared law (§9.4), sizing is the platform's own.

## §7.8 — Media budget

Attachment bytes ride an explicit down-select budget; overflow is disclosed,
never silently dropped.

## §7.9 — Approval presentation posture

`approvals.enabled: false` falls back to the same-chat `/approve` text (the
ask stays answerable, only the buttons go away); `target` restricts buttons
to dm or channel conversations; `sessionFilter`/`agentFilter` are regex
doors; `approvers` is the answer roster re-checked on EVERY answer, never
only in the card.

## §7.11 — Multi-account

One face per account under the same platform: conversation keys carry the
account segment, routing is `<platform>:<account>` with a bare-platform
fallback for keys whose account lost its face, and `accounts.<id>` config
rows override everything above per account.

## §8 — The Teams transport

The `sugarcraft/candy-msteams` channel root: Microsoft Teams dialogs over Bot
Framework activities, webhook-driven, replies out over REST.

## §8.1 — Endpoints and the trust door

The OAuth token root and the JWKS URL resolve from settings with environment
overrides (`MSTEAMS_AUTH_BASE`, `MSTEAMS_JWKS_URL`); empty means the Microsoft
defaults. The daemon's `/channels/msteams/…` mount is the only inbound door,
and there is NO platform dial to start — the webhook IS the connection. Bot
Framework JWT validation terminates inside the bundle (issuer, audience,
lifetime, signing keys), and with a tenant configured an activity whose
tenant does not match is refused. Nothing reaches the router unsigned.

## §8.2 — Credential placement

The Azure Bot `appId`/`appPassword`/`tenantId` triple lives only in the 0600
channels store. The default account additionally honors `MSTEAMS_APP_ID`,
`MSTEAMS_APP_PASSWORD` and `MSTEAMS_TENANT_ID` — bundle-side reads, default
account only, and the store wins.

## §8.3 — Groups blocked by default

Group rows are keyed by Teams conversation id; NO row means the group or
channel is not served. Where served, `requireMention` (on by default) gates
turns, `groupPolicy: open` is the explicit opt-in, and the `degraded` posture
(operator declares the `ChannelMessage.Read.Group` RSC was declined) forces
mention-only even under `open`.

## §8.4 — The adaptive-card approval dialect

An approval becomes an adaptive card carrying the ask, the summary and the
button row; retiring a card REPLACES it with a plain one-line card (Teams
cannot delete). Cards are pure payload builders so the shape law is testable
offline.

## §8.5 — Message shape and the fast-ack law

Teams takes its chunk caps from config (`outbound.textChunkLimit`,
`outbound.maxLinesPerMessage`). The webhook answer is NOT the answer to the
model: the activity gets its fast ack, and the reply arrives later over
proactive REST.

## §8.6 — Replies ride the activity's serviceUrl

Outbound traffic goes to the conversation reference the INBOUND activity
carried — HTTPS on a Bot Framework host, validated per request. Teams replies
expire, so the durable `last_conversation` row is the re-key: proactive
traffic reads the stored reference, never a stale one.

## §8.7 — Media v1 scope

Platform-hosted attachment bytes only: HTTPS on the Bot Framework media
hosts, ≤4 images + ≤2 PDFs under `media.maxMb`, bound while streaming. A
Graph/SharePoint content url is refused with a disclosed note — delegated
consent is the P3.5 file-consent leg — never a silent drop, never a dial.
Inline attachment bytes in an activity are not v1.

## §9.1 — Key grammar

Every conversation the bridge binds carries
`<platform>:<account>:<channel-or-thread-id>`; personal chats resolve through
the `dmScope` ladder (`dm:main`, `dm:<peer>`, `dm:<chan>:<peer>`,
`dm:acc:<chan>:<peer>`). The grammar is documented for this bundle since P1.

## §9.2 — The queue vocabulary

`steer` — a mention while the turn runs piggybacks on it; `interrupt` — a
mention while busy cancels then sends; `collect` — plain queued text
coalesces behind the debounce gate (default window 500 ms, cap 20 prompts);
`queue` — the default: it waits its turn.

## §9.3 — The one rule

An incoming message is a prompt; an outgoing event is a reply. Delivery
choice maps idle → `queue`, busy + mention → `steer`, busy without mention →
`queue`.

## §9.4 — Shared outbound composition

All platform text arrives ALREADY chunked at the face; merges mid-stream are
forbidden — the serve media caps allow no surprise reassembly.

## §9.5 — The timer law

A throw escaping inside a periodic callback must not kill the loop: every
timer body is guarded, and housekeeping (binding prune, 6 h cadence over a
7-day keep) rides that guard.

## §9.6 — Approval ask lifecycle

The card-side window of the co-TTL pair (see §11.6): an unanswered ask
expires after thirty minutes and its card retires; the verdict vocabulary is
`once | always | reject` (`deny` aliases `reject`).

## §11.6 — The co-TTL ruling

An approval ask and its host-side pending permission share ONE horizon
(1800 s): the card window and the core's `permission.pending` ceiling expire
together, so no card can outlive the verdict it offers, and an aged ask
refuses with its reason.
