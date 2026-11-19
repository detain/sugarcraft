# PL-5c-A design: non-primary routing and the event sink

Status: design only, for owner review. No code or test changes. Step PL-5c-A-design, branch `tmp/pl-5c-a-design`.
Plan reference: `plan_plugin.md`, section "Phase 5 (later, not detailed): Option B", step 5c-A, and the 5a inventory table in the same section.
Owner decision 2026-11-19: the sink design is written and reviewed as its own step before the 5c-A implementation.

Sources read for this design (all paths relative to the repo root):
- `candy-code/src/Server/Workspace/Gateway.php`: `onMessage` (the `routeOf` call), `routeOf` (private), `canonicalRoot`, `touchSession`, `bindSession`, `learn`, `forward`, `relay`, `send`/`outbox`, `lost`, `retire`, `dropSubscriber`, `recycleIdle`, the class docblock.
- `candy-code/src/Server/Workspace/WorkspaceHostClient.php`: `START_TIMEOUT_SECONDS` (line 52), `rpc()`, `touch()`, `onExit()`.
- `candy-code/src/Host/InProcessHostServices.php`: the routing-limit paragraph in the class docblock, `enqueue`, `drain`, every method, `subscribeEvents`, `followedSessions`.
- `candy-code/src/Cli/Serve/ServeStartup.php`: `Gateway::new` (line 261), `channelHostFactory` (line 590).
- `candy-code/tests/Host/InProcessHostServicesTest.php`: the test that pins the current refusal (lines 518 to 550).
- `sugar-plugins/src/Host/HostServices.php`: the contract and its docblock.
- `candy-code/src/Protocol/SessionFeed.php` (`MAX_PRELIVE_BUFFER = 2048`, line 134), `candy-code/src/Server/Ws/Outbox.php` (`SOFT_WATERMARK_BYTES` 1 MiB, `HARD_WATERMARK_BYTES` 16 MiB, `pushEphemeral`).
- `plan_plugin.md` 5a table (rows 1 to 16) for the bundle-side failure semantics.

---

## 0. Summary

- Today the in-process door (`InProcessHostServices`, PL-5c-B) serves the primary root only. It never sends `root`, and it never calls `Gateway::routeOf`. A session that the gateway bound to another root is refused with `session_not_found` by the primary dispatcher. That refusal is pinned by `InProcessHostServicesTest` (lines 518 to 550). PL-5c-A replaces it with routing.
- Routing: the in-process door asks the gateway which root a call belongs to, using the same rule the wire uses (`root` param first, then the `sessionId` row in `sessionRoots`). A null answer keeps today's primary-dispatcher path. A root answer goes to that root's workspace host over the existing child socket hop, through the same bookkeeping `forward()` uses.
- Event sink: `Gateway::relay()` fans host events out to `Connection` objects only. PL-5c-A adds a second kind of target, an in-process sink, behind a small interface. Each sink keeps its own cursor, and its own bounded FIFO queue. The sink's ordering, back-pressure and overflow rules are in section 3.
- Ten wire methods: all ten are routed by the same rule. Only `pendingPermissions` needs a signature addition (an optional root), because the wire method has no `sessionId` to route by. Refusals from the child host pass through unchanged. The failure semantics stay those of section 5a (section 4 here).
- The `HostServices` changes are additive and optional (`?string $root = null`), so the two bundles compile and behave the same without edits.
- Eleven open questions for the owner, each with a recommended default, are in section 6. None of them blocks the implementation step if the defaults are accepted.

---

## 1. Current state (what PL-5c-B does, and what it refuses)

1. `InProcessHostServices::open()` creates one `Client` with `Scope::owner()` on the primary `Dispatcher`. Every call goes to `Dispatcher::invoke($this->connected(), ...)`. No `root` param is ever set.
2. Consequence for a session on a non-primary root: `session.send`, `session.subscribe`, `session.get`, `session.cancel` and `command.exec` get `session_not_found` from the primary dispatcher. `permission.respond` gets `ask_not_found`. This is the refusal the design replaces. The class docblock (routing-limit paragraph) and the pinned test say so explicitly.
3. Events: `subscribeEvents()` listeners receive only envelopes from the primary feeds, through the in-process `Client` that `open()` registers. Events from workspace hosts never reach a bundle, because nothing on the in-process path listens to the gateway.
4. Wiring: `ServeStartup.php:261` builds the `Gateway` over the primary `Dispatcher`, and `ServeStartup.php:590` (`channelHostFactory`) builds the bundle factory from the `Dispatcher` and the loop only. The gateway is not reachable from the in-process door today.

What the gateway already does on the wire (the behavior the in-process door must match, not reinvent):

- `onMessage` (Gateway.php, around line 273): a request whose method is not a gateway method calls `routeOf($params)`. A null root goes to the primary dispatcher with the `root` key removed. A root goes to `forward()`, after the greeted check and `admit()`.
- `routeOf` (Gateway.php:378): if `params->root` is set, `canonicalRoot()` it (invalid_params for a non-string, `root_not_found` for a non-directory). If it equals the primary root, return null. Otherwise return the root. With no `root`, a string `sessionId` goes through `touchSession()`, which returns the `sessionRoots` row (or null when no row exists). Anything else returns null.
- `forward()`: marks the client as a user of the root (`rootClients`), handles the last-subscriber rule for `session.unsubscribe`, increments `inFlight`, arms the 300 s `FORWARD_DEADLINE_SECONDS` timer, awaits `hostFor($root)` (which starts the host on demand, with a 30 s `START_TIMEOUT_SECONDS`), calls `rpc()->callPreserving()`, then `learn()`s session ids from `session.create`, `session.fork` and `session.list` answers, records `subscribers` and `cursors` for `session.subscribe`, and sets `result->root` on the answer.
- `relay()`: the host's `event` notifications. A session event goes to `subscribers[sessionId]`, a server-scope event goes to `rootClients[root]`. Durable events are de-duplicated per client by `cursors[connId][sessionId]`. Live-only events are queued through `Outbox::pushEphemeral` with a coalesce key (`sessionId|type|turnId` for deltas). `root` is added to the notification params.
- `lost()`: when a host dies or is retired, each followed session's subscribers get `server.overflow {sessionId, dropped: 0, action: resubscribe}`, and the subscriber map for that session is cleared.
- `dropSubscriber()`: removes one subscriber, and when it was the last one, sends `session.unsubscribe` upstream.

---

## 2. Routing: non-primary roots through `Gateway::routeOf`

### 2.1 The seam

`routeOf` is private. The in-process door needs the same decision, so PL-5c-A exposes it and does not copy it:

- Add `public function rootFor(\stdClass $params): ?string`, which delegates to `routeOf()`. Keep `routeOf()` private. The public method is the only new surface on `Gateway` for routing.
- Extract the promise part of `forward()` into `public function callRoot(string $root, string $method, \stdClass $params): PromiseInterface`. It covers the `inFlight` count, the single-settle guard, the `FORWARD_DEADLINE_SECONDS` timer (which rejects with `busy` / `host_timeout`), `hostFor()`, `callPreserving()`, `learn()`, the `session.delete` cleanup, and the `root` tag on the answer. `forward()` keeps the Connection-only parts: writing the frame to the connection's outbox, and recording `subscribers` / `cursors` for the connection. The in-process door calls `callRoot()` and then records its own sink subscription (section 3.5).
- The refactor must leave the wire byte-identical. The existing gateway suites are the proof (section 5.1).

### 2.2 The in-process door's call path

For each routed method, `InProcessHostServices` does this, in order:

1. Build the params exactly as today (the same keys), plus `root` when the method carries one (`createSession`, `pendingPermissions`; see section 4).
2. `$root = $gateway->rootFor($params)`. Null: call the primary dispatcher as today. Non-null: call `$gateway->callRoot($root, $method, $params)`, with `root` removed from the params first, as `onMessage` does (`unset($params->root)`).
3. No socket, no loopback dial and no JSON-RPC frame is built by the in-process door. The hop to the workspace host is the gateway's existing child socket (`WorkspaceHostClient` / `RemoteSessionHost`), which is inside the host process and is not the bundle's self-dial. R10 stays retired.

The class-level constraint "no frame carries these calls" in `InProcessHostServices` is then true only for the primary path. The docblock must be rewritten in PL-5c-A to say that routed calls use the gateway's child socket hop and that the in-process door still never builds a frame. That wording change is part of the implementation step.

### 2.3 The refusal being replaced

- Replaced: the in-process door's `session_not_found` (and `ask_not_found`) for a non-primary id, from the primary dispatcher.
- Kept: `session_not_found` for an id no dispatcher holds. `routeOf` returns null for an unknown id (no `sessionRoots` row), so the primary dispatcher still refuses it with the same kind and message as the wire.
- Changed: an id with a `sessionRoots` row whose host is not running. `callRoot` starts that host (`hostFor`), which is the wire's behavior. If the start fails, the caller gets `workspace_failed` (Internal). See section 6, Q1, for cold starts.

### 2.4 Wiring

- `channelHostFactory` (ServeStartup.php:590) and `InProcessHostServices::bundleFactory()` take the `Gateway` as well as the `Dispatcher` and the loop. The `Gateway` is created at ServeStartup.php:261. The implementation must confirm that the registry's `boot()` call, which receives the factory, runs after line 261 in every serve path, `--detach` forks included (see the comment at ServeStartup.php:259 to 262). This was not traced in this step.
- `Gateway::start()` (the recycle timer) is already called by the serve. The in-process door does not start or stop the gateway.
- `Gateway::stop()` (server shutdown) stops every workspace host. The in-process door has no close path of its own; see section 3.4.

### 2.5 Ownership and viewer rules

`admit()` (the scope law at the gateway's own door) takes a `Connection`, and the in-process door has none, so it never passes through `onMessage()` and `admit()` is not called. The in-process client is an owner (`Scope::owner()`, no scope list), which `admit()` would pass untouched anyway. The implementation must not weaken this: the in-process door must not gain a viewer principal. A test pins that the in-process door is never refused `viewer_session_denied` or `owner_only`.

---

## 3. The event sink

### 3.1 Why a sink is needed

`Gateway::relay()`, `send()` and `outbox()` take `Connection` objects. `subscribers`, `rootClients` and `cursors` are keyed by `Connection::id()`. An in-process listener has no `Connection`, no `Outbox` and no socket, so today the relay cannot reach it.

The change is to make relay targets an interface, and keep the `Connection` path behind it unchanged. The interface is internal to `candy-code/src/Server/Workspace/`:

- `id(): string`: the key for `subscribers`, `cursors` and `rootClients`. For in-process sinks it is a prefix that cannot collide with a connection id (for example `inproc:<n>`).
- `isOpen(): bool`.
- `viewerSessions(): ?array`: always null for an in-process sink (an owner).
- `deliver(array $message, string $text, ?string $coalesceKey, bool $ephemeral, ?int $seq, ?string $sessionId): void`: the one method `relay()` calls per target. `Connection`'s implementation is the current `send()` / `pushEphemeral()` logic, moved unchanged. The in-process sink's implementation is section 3.3.

`relay()` keeps its existing order of checks (cursor, open, viewer pin, then send or queue). Only the final delivery call changes. The implementation must keep `relay()`'s output for `Connection` targets byte-identical, which the gateway suites check (section 5.1).

### 3.2 Ordering

Rules, in priority order:

1. Per host, events reach the gateway in the order the host wrote them (one socket per host). The sink preserves that order.
2. For each session, delivery order equals seq order. A durable event with `seq <= cursor` is never delivered twice. The sink's cursor is the same `cursors[sinkId][sessionId]` value the wire uses.
3. The sink's queue is FIFO. A drain never re-enters itself (the `delivering` / `draining` flags of `InProcessHostServices`, reused). A listener that calls back into the host (for example `sendTurn` from inside a listener) queues its own events behind the ones already owed. Its call is answered in the same tick, as today.
4. Across sessions or across roots, no order is promised. This is the wire's rule too (each connection's frames come from several hosts).
5. Shedding never reorders. A shed live-only event is removed from its position, and no later event jumps ahead of an earlier durable one.
6. The overflow notice is inserted at the position of the first event it replaces. The listener therefore sees every event up to the cut, then the notice, then nothing for that session until it resubscribes.
7. Every delivered envelope carries `root` (the gateway adds it to the notification params today). It is additive: a bundle keys its routing by `sessionId` and ignores extra keys (see Q3).

### 3.3 Back-pressure

Proposed bounds. The numbers are recommendations, chosen for parity with existing bounds, and they are open for the owner (Q4).

- Queue bound: 2048 envelopes, the same as `SessionFeed::MAX_PRELIVE_BUFFER`, and 1 MiB of encoded envelope bytes, the same as `Outbox::SOFT_WATERMARK_BYTES`.
- Live-only events (`pushEphemeral`-class: deltas, no `seq`): when the queue is over a bound, coalesce by key (`sessionId|type|turnId`, the wire's key), then drop the oldest live-only event. A dropped live-only event is not counted as a durable loss.
- Durable events (with `seq`): never dropped silently. When a durable event would exceed a bound after live-only shedding, the sink drops that session's queued events from its first undelivered durable event onward, counts them as `dropped`, enqueues one `server.overflow {sessionId, dropped: <n>, action: "resubscribe"}` envelope in their place, and stops delivering that session until the listener resubscribes. Other sessions keep flowing.
- The overflow notice is always accepted (it reserves one slot above the bound), so the listener always learns about a loss.
- The sink's cursor for an overflowed session is set to the last durable seq it actually delivered, not the last seq it queued. This is the rule that makes resubscribe recover exactly: the listener's `cursor()` closure (see `subscribeSession`) reports what it applied, and the gateway resumes from there. A test pins this (section 5.3).
- A listener that is slow to return does not block the host: delivery runs on the loop (`futureTick`), so the host's socket keeps being read. The bound above is what protects memory when a listener stalls.

### 3.4 What a dropped subscriber sees

| Case | What the listener gets | Source |
|---|---|---|
| Bundle calls `disconnect()` | Events it was already owed, delivered first. Then nothing. No notice (the caller initiated it). Its sink subscriptions are removed. | `InProcessHostServices::disconnect()` (today: drain, then remove the client). New: `detachSink()`, which mirrors `onClose()` (`cursors`, `rootClients`, `subscribers`). |
| Sink queue overflow (section 3.3) | Events up to the cut, then `server.overflow {sessionId, dropped, action: resubscribe}`. Nothing for that session until it resubscribes. | New, in the sink. Same shape as the wire's overflow notice. |
| Host crash, or `workspace.close` / idle retire | `server.overflow {sessionId, dropped: 0, action: resubscribe}` for each followed session, then the subscription is cleared. The next call for that root starts a fresh host. | `Gateway::lost()`. Delivered to sinks through `deliver()`. |
| Last subscriber of a session leaves | Nothing further for that session. The gateway sends `session.unsubscribe` upstream. | `dropSubscriber()`. Same as the wire. |
| Listener throws | Logged as `in-process event listener failed`. Other listeners still hear the event. | Existing rule in `InProcessHostServices::drain()`. |
| Server stop (`Gateway::stop()`) | Nothing. | See Q8. In-process bundles run in the serve process, so they stop with it. |
| Re-follow after a notice | The bundle calls `subscribeSession` with its `cursor()` closure, and the gateway resumes from the applied seq. | Existing `subscribeSession` contract (cursor read on every call). |

### 3.5 Subscriptions made through the sink

- `subscribeSession` on a routed session: `callRoot()` answers the subscribe; then the door records the sink as a subscriber of that session, and sets the sink's cursor to `afterSeq` (when given) or the answer's `throughSeq`. This is the wire's rule for the Connection path.
- `subscribeEvents` listeners stay per-client, as today. The sink is per in-process client, and it is registered in `rootClients[root]` when the client first uses a root, as `forward()` does for a connection. That is how server-scope events of a root reach it.
- `followedSessions()` returns the union of the primary feeds (as today) and the routed sessions this sink subscribes to, in the order it subscribed. `connected()` is unchanged.
- Last-subscriber rule: `unsubscribeSession` on a routed session removes the sink's subscription, and when it was the last one, sends `session.unsubscribe` upstream (the same as `forward()`).

### 3.6 Re-entrancy with the primary feeds

Primary feeds keep delivering through the existing in-process `Client` callback (`enqueue`). Routed events go through the sink's own queue. Both queues drain on the same loop, with separate queues, so a primary event can never reorder a routed one, and neither can reorder the other's session. A session has one home (primary or one root), so no session is ever in both queues. Q7 covers the one exception the code allows.

---

## 4. The ten inventoried wire methods on a non-primary root

Columns: how the root is chosen (`rootFor`), what a non-primary success looks like, what refusals pass through unchanged, and what changes from the 5a semantics. Row numbers match the 5a table. Timeouts: the bundle keeps its 20 s per-call timeout (5a); the gateway's 300 s forward deadline still applies inside the gateway, and it is the bundle's timeout that the bundle sees first.

| # | Method (HostServices) | Root chosen by | Non-primary success | Refusals that pass through unchanged | What changes from 5a |
|---|---|---|---|---|---|
| 2 | `subscribeSession(sessionId, afterSeq, cursor)` | `sessionId` via `sessionRoots` (no `root` param) | Answer as the host gave it, with `root` set. Sink subscribed; cursor set (section 3.5). | `session_not_found` when no row (from primary). `workspace_failed` from a failed host start. `busy host_timeout` after 300 s. | Before: `session_not_found`. After: a routed follow. Re-follow after host loss uses the same `cursor` closure, unchanged. |
| 3 | `unsubscribeSession(sessionId)` | `sessionId` via `sessionRoots` | `null`. Sink removed; upstream unsubscribe when last. | None raised to the caller. Upstream failure is best-effort, as in `dropSubscriber()`. | Before: `null` after a no-op. After: the sink is removed too. Still no caller in any bundle today. |
| 4 | `sendTurn(sessionId, text, delivery, idempotencyKey, images, documents)` | `sessionId` via `sessionRoots` | The host's `session.send` answer, with `root`. | `session_not_found`, `too_many_turns` (busy, `retryAfterMs`), `refused` (-32009), `invalid_params` (image or document params), `busy` draining, `busy host_timeout`. | Media refusal retry (5a row 4) unchanged: one retry without attachments. Retry on `busy` or `workspace_failed` is NOT added (Q9): see the note below. |
| 5 | `createSession(name, permissionMode, root)` | `root` param when set (through `canonicalRoot`), otherwise primary | The `session.create` answer with `id`, `root`. The id is bound to the root through `learn()`, so later calls find it by `sessionId` alone. | `root_not_found` (NotFound, `root` not a directory), `invalid_params` (`root` not a string). Missing-`id` RuntimeException (5a). | New optional `?string $root` (Q2). Before: `root` was not accepted. Without `root`, behavior is unchanged. |
| 6 | `respondPermission(sessionId, askId, reply, note)` | `sessionId` via `sessionRoots` | The `permission.respond` answer. | `ask_expired` (NotFound), `ask_not_found`, `already_resolved` (Conflict; the core's substring match still works because the message is unchanged), `remember_refused` (Forbidden), `UnsupportedInServer` (-32030). | Before: `ask_not_found` on a routed session. After: the child host answers. The relayed-question path (`respondRelayed` on the host) is the host's own. |
| 7 | `cancelSession(sessionId, mode)` | `sessionId` via `sessionRoots` | The `session.cancel` answer. | `session_not_found`, `busy` draining. | Before: `session_not_found`. After: the turn is cancelled on the child host. |
| 8 | `sessionSnapshot(sessionId, limit)` | `sessionId` via `sessionRoots` | The `session.get` answer, with `root`. | `session_not_found`. | Before: `session_not_found`. After: routed. M's raw-message display (5a row 8) is unchanged. |
| 9 | `execCommand(sessionId, name, args)` | `sessionId` via `sessionRoots` | The `command.exec` answer. | `session_not_found`, `UnsupportedInServer` (-32030), `command_refused` (Conflict), busy draining. | Before: `session_not_found`. After: routed. Empty `args` is still an absent key (PL-bridge-args, unchanged). |
| 10 | `pendingPermissions(root)` | `root` param when set (through `canonicalRoot`), otherwise primary | The `permission.pending` answer for that root's host, with `root` set on each item. | `root_not_found` for a bad `root`. | New optional `?string $root` (Q11). Without a root, the primary answer is unchanged. The wire method has no `sessionId` to route by, so this is the only method whose routing needs a new parameter. |

Non-method rows, unchanged in behavior:

- Row 1 `hello()`: the in-process client's own hello. It is never routed (no root), and the in-process door never sends `root` on it. Routing has no effect on it.
- Row 13 `connect()` / `disconnect()`, row 14 `isConnected()`, row 15 `followedSessions()` (extended, section 3.5), row 16 `onReady` (unchanged, no caller): see sections 3.4 and 3.5.
- Row 11 `subscribeEvents()` and row 12 `subscribeFollowResults()`: the event sink (section 3) carries the routed events. Follow results stay unfired, as today.

Note on turn retries (row 4). A host runs its own `IdempotencyCache` (TTL 300 s), in its own process. After a host restart, the cache is empty, so an idempotency key does not protect a turn that was accepted by the old host. The bundle does not retry a `session.send` on `busy` or `workspace_failed` today, and this design does not add one (Q9). The implementation must add a test that a routed `sendTurn` failure with `workspace_failed` is not replayed by the door. That the cache is per process follows from the host being a separate child process. It was not traced inside `IdempotencyCache` in this step; the implementation step must confirm it before relying on it.

---

## 5. Test plan (for the implementation step, PL-5c-A-impl)

Run candy-code through `scripts/parallel-tests.sh` (K=24, scratch `--out`), as the owner's rule requires, and run the single-file suites once with output to a scratch file and stdin from `/dev/null`. The implementation must not run any suite serially to look at its output a second time.

### 5.1 Existing suites that must pass unchanged (wire parity)

- `candy-code/tests/Server/GatewayTrustRoutingTest.php`, `GatewayScopeEnforcementTest.php`, `GatewayOutboxTest.php`, `GatewayForwardDeadlineTest.php`, `GatewayRecycleLoopClockTest.php`, `DirBrowseTest.php`, `WorkspaceHostTest.php`: these pin the wire behavior that the `callRoot` refactor and the `relay()` target change must not move.
- `candy-code/tests/Host/InProcessHostServicesTest.php`: all tests except the one pinned routing-limit test (lines 518 to 550), which is replaced by 5.2.
- `candy-discord` and `candy-msteams` suites: no bundle edits in 5c-A, so they must pass unchanged.
- `sugar-plugins` suite: the `HostServices` change is additive and optional.

### 5.2 New and changed tests in candy-code

`candy-code/tests/Server/GatewayInProcessRoutingTest.php` (new):
- `rootFor()` returns null for the primary root, for a `root` that is the primary root after `canonicalRoot`, and for an unbound `sessionId`.
- `rootFor()` returns the root for a bound `sessionId` and for an explicit `root` param; a non-directory `root` throws `root_not_found`; a non-string `root` throws `invalid_params`.
- `callRoot()` answers with `root` set; `inFlight` returns to zero after answer, failure and deadline; the deadline rejects with `busy` / `host_timeout` exactly once.
- A cold start (no running host) starts it within `START_TIMEOUT_SECONDS`; a start failure rejects with `workspace_failed`.

`candy-code/tests/Host/InProcessHostServicesTest.php` (changed):
- Replace the pinned routing-limit test with: each of the ten methods on a non-primary session returns the routed answer (use a real workspace host, as `WorkspaceHostTest` does, to prove the socket hop).
- An id no dispatcher holds still gets `session_not_found` from the door, and the same wire refusal.
- `permission.respond` with an unknown `askId` on a routed session gets `ask_not_found` from the child host (not from the door).
- `pendingPermissions()` with no root answers from the primary, unchanged. With a root it answers from that root's host.
- A `sendTurn` failure with `workspace_failed` is not replayed (section 4 note).
- The door never receives `viewer_session_denied` or `owner_only` (section 2.5).

`candy-code/tests/Server/GatewayEventSinkTest.php` (new):
- Per-session delivery order equals seq order, with two sinks subscribed to two sessions of one host.
- A second sink subscribing to the same session gets the replay from its cursor, and no durable event is delivered twice to either sink.
- Live-only deltas are coalesced by key while the queue is over its bound; no durable event is dropped by shedding.
- Durable overflow: the listener sees every event up to the cut, then exactly one `server.overflow` with `dropped` equal to the count dropped, then nothing for that session.
- After overflow, the sink's cursor equals the last delivered seq, not the last queued seq; a resubscribe with that cursor resumes with no gap and no duplicate.
- Another session on the same sink keeps flowing during one session's overflow.
- Host crash (`lost()`) sends `resubscribe` to each routed sink's followed session, and the next call starts a fresh host.
- `disconnect()` delivers the owed events and then nothing; the sink is removed from `subscribers`, `cursors` and `rootClients`; the last subscriber's leave sends `session.unsubscribe` upstream.
- A listener that calls `sendTurn` from inside a callback: its event is delivered after the owed ones, the outer drain is not re-entered, and no order is changed.
- A throwing listener is logged and the other listeners still receive the event.
- Server-scope events of a root reach a sink that used that root, and no sink that never used it.

### 5.3 Ordering and back-pressure property checks

- Property test, seeded: random interleavings of events from two hosts and three sessions, with random listener stalls, must keep per-session seq order and never deliver a durable seq twice. Use a fixed seed list in the test, not random at run time.

### 5.4 Real-process test

- One test starts a real workspace host (the `WorkspaceHostTest` pattern) and runs `sendTurn`, `subscribeSession` and a host crash through the door. This proves the child socket hop. It is the slowest test; if it is too slow for a shard it goes in `scripts/parallel-tests-durations.tsv` with its measured duration.

### 5.5 Repo gates

- `candy-code/tests/Config/Support/suite-figure.json` must be refreshed for the new test files, and each new test file must be listed in `scripts/parallel-tests-durations.tsv` (CLAUDE.md, CI gotcha). Drift figures are accepted locally per the owner's current rule (figure drift is not a blocker), but the implementation must still add the entries.
- No candy-code docs roster changes are expected (no new tool, command, env var or key binding). Confirm with `candy-code/tests/Commands/KeyBindingDriftTest.php` and `candy-code/tests/Config/EnvRosterDriftTest.php`.
- `php tools/check-one-type-per-file.php`: the sink interface and the in-process sink class each go in their own file.
- `php tools/check-child-lifetimes.php`: the sink adds no `proc_open`, but the child-socket path does not change. Run it anyway.
- `php tools/check-path-repos.php --no-lib-path-repos`: must exit 0.

---

## 6. Open questions for the owner (each with a recommended default)

Q1. Cold start. May a call routed to a root whose host is not running start that host? The wire does (up to 30 s start, `START_TIMEOUT_SECONDS`), but the bundle's per-call timeout is 20 s.
- Default: yes, same as the wire. A start that outlasts the bundle's 20 s surfaces as the bundle's timeout, and the host keeps starting in the background, so the next call finds it running. The 20 s timeout is not raised.

Q2. How does a bundle name a root for a call that has no session yet? Only `createSession` and `pendingPermissions` need it, because every other call finds its root by `sessionId`.
- Default: add `?string $root = null` to those two methods on `HostServices` (additive, optional). Null means the primary root, so no bundle changes.

Q3. Does a delivered event carry `root` for a routed session?
- Default: yes. The gateway already adds `root` to relayed notifications, and the sink delivers the same shape. Additive: the bundles' router keys by `sessionId`.

Q4. Sink bounds. Are 2048 envelopes and 1 MiB the right queue bounds for a routed sink?
- Default: yes (parity with `SessionFeed::MAX_PRELIVE_BUFFER` and `Outbox::SOFT_WATERMARK_BYTES`). Both are constants, so a later change is one edit.

Q5. Overflow granularity: when a durable event does not fit, does the sink stop only the affected session, or the whole sink?
- Default: the affected session only (section 3.3). Other sessions keep flowing, and the wire already behaves per session.

Q6. Where does the routing seam live? Expose `routeOf` as a public `rootFor`, or move routing into its own class?
- Default: add public `Gateway::rootFor()` and `Gateway::callRoot()` (section 2.1). A separate class adds a second owner of `sessionRoots`, which the gateway's own docblock warns against. Smallest change.

Q7. A session id that both the primary dispatcher and a child host hold. Today the wire routes by the gateway (`sessionRoots` is never filled with primary ids), and in-process routing would do the same.
- Default: match the wire, and add a test that no primary session id is ever bound in `sessionRoots`. Session ids are generated, so a collision is not expected; the test makes the assumption explicit.

Q8. Server stop: should in-process listeners be told the host went away?
- Default: no notice. In-process bundles run inside the serve process, so server stop ends them too. The wire's socket close is the only "dropped" signal, and there is no socket here.

Q9. Retries for routed turns: should the door retry a `session.send` that fails `busy` (host timeout) or `workspace_failed`?
- Default: no. The bundle keeps its current rules (one media-refusal retry only). The child host's idempotency cache is per process, so a retry can run a turn twice after a host restart.

Q10. Gateway forward deadline (300 s) versus the bundle's per-call timeout (20 s).
- Default: keep both. The bundle's 20 s wins for the bundle. The gateway's deadline only frees the gateway's bookkeeping.

Q11. `pendingPermissions` across roots: answer for the given root only, or fan out to every running host?
- Default: the given root only (or the primary when none is given). A fan-out needs a second round trip per running host, and the bundle only asks about the root of a session it is serving.

---

## 7. What this step does not change

- No code, no test, no composer file, no doc under `docs/`. This file is the only change.
- The wire protocol and external clients are unchanged (plan_plugin.md, O3 option B: the wire stays).
- The bundles, `scripts/channel-bridge` and `sugar-serve-protocol` are unchanged. PL-5e (plugins drop `sugar-serve-protocol`) is already on master.
- No publish, no issue, no PR, no push. The impl stage does not publish anything either.

## 8. Next step

PL-5c-A-impl, after the owner answers Q1 to Q11 (or accepts the defaults). Its brief is section 2 (routing), section 3 (sink), section 4 (the ten methods) and section 5 (tests). Recommended order inside the impl step: (1) the `callRoot` / `rootFor` refactor with no behavior change and the gateway suites green; (2) the sink interface with `Connection` as the only implementation, suites green; (3) the in-process sink and the routed calls; (4) the replaced pinned test and the new suites.
