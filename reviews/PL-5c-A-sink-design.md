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
- Event sink: `Gateway::relay()` fans host events out to `Connection` objects only. PL-5c-A adds a second kind of target, an in-process sink, behind a small interface. Each sink has its own bounded FIFO queue. Cursors stay in one place, the gateway's `cursors` table (section 3.1), so a sink keeps no copy of its own. The sink's ordering, back-pressure and overflow rules are in section 3.
- Ten wire methods: nine are routed by the same rule. `hello` is the one exception: it has no root and no `sessionId`, so it is never routed (section 4, row 1). Two of the nine need a root argument: `createSession`, which has no session yet, and `pendingPermissions`, which has no `sessionId` to route by. Their root forms go on a separate interface (section 2.6). The other routed methods find their root by `sessionId`. Refusals from the child host pass through unchanged. The failure semantics are those of section 5a, plus the door's own 20 s timer and one deliberate change: a host crash during a routed call is `workspace_failed`, not `dropped` (section 4 preamble).
- `HostServices` does not change. A new parameter on the shared interface would break every implementer, so the root forms go on `RootedHostServices`, which only the in-process door implements. The two bundles, their test fakes and `FakeHostServices` compile and behave the same without edits.
- Twelve open questions for the owner, each with a recommended default, are in section 6. None of them blocks the implementation step if the defaults are accepted.

---

## 1. Current state (what PL-5c-B does, and what it refuses)

1. `InProcessHostServices::open()` creates one `Client` with `Scope::owner()` on the primary `Dispatcher`. Every call goes to `Dispatcher::invoke($this->connected(), ...)`. No `root` param is ever set.
2. Consequence for a session on a non-primary root: `session.send`, `session.subscribe`, `session.get`, `session.cancel` and `command.exec` get `session_not_found` from the primary dispatcher. `permission.respond` gets `ask_not_found`. This is the refusal the design replaces. The class docblock (routing-limit paragraph) and the pinned test say so explicitly.
3. Events: `subscribeEvents()` listeners receive only envelopes from the primary feeds, through the in-process `Client` that `open()` registers. Events from workspace hosts never reach a bundle, because nothing on the in-process path listens to the gateway.
4. Wiring: `ServeStartup.php:261` builds the `Gateway` over the primary `Dispatcher`, and `ServeStartup.php:588` (`channelHostFactory`) builds the bundle factory from the `Dispatcher` and the loop only. The gateway is not reachable from the in-process door today.

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
- Extract the promise part of `forward()` into `public function callRoot(string $root, string $method, \stdClass $params): PromiseInterface`. It covers the `inFlight` count, the per-session subscribe generation (section 4.1), the single-settle guard, the `FORWARD_DEADLINE_SECONDS` timer (which rejects with `busy` / `host_timeout`), `hostFor()`, `callPreserving()`, `learn()`, the `session.delete` cleanup, `touch()` on the host (the only writer of the idle clock that gates recycling, Gateway.php:482), and the `root` tag on the answer. Every rejection goes through one private wrapper, `asRefusal(string $root, \Throwable $e): RpcError`, which is the rule `forward()`'s `$fail` applies today (Gateway.php:462-470): an RpcError passes through unchanged, and any other throwable, including a failed host start from `hostFor()`, becomes `RpcError` with code `Internal` and kind `workspace_failed`. `forward()` and `callRoot()` both call it, so the wire and the in-process door cannot drift apart. The answer stays a `stdClass` here, as on the wire; the door converts it (section 2.2, step 4). `forward()` keeps the Connection-only parts: writing the frame to the connection's outbox, and recording `subscribers` / `cursors` for the connection. The in-process door calls `callRoot()` and then records its own sink subscription (section 3.5).
- The refactor must leave the wire byte-identical. The existing gateway suites are the proof (section 5.1).

### 2.2 The in-process door's call path

For each routed method, `InProcessHostServices` does this, in order:

1. Build the params exactly as today (the same keys). The two root forms (`createSessionIn`, `pendingPermissionsIn`, section 2.6) add `root`.
2. `$root = $gateway->rootFor($params)`. Either way `root` is removed from the params first, as `onMessage` does (`unset($params->root)`, Gateway.php:281 for the primary path and Gateway.php:299 for a routed root). Null: call the primary dispatcher as today. Non-null: call `$gateway->callRoot($root, $method, $params)`. A `createSessionIn` or `pendingPermissionsIn` call naming the primary root therefore reaches the primary dispatcher with the same params the wire sends. `rootFor()` is not a promise-returning call: `canonicalRoot()` throws synchronously (`root_not_found`, `invalid_params`), and so can `hostFor()` inside `callRoot()` before it returns. So steps 2 and 4 both run inside the door's existing `answer()` wrapper (`InProcessHostServices.php:425`), which turns any throwable into a rejected promise. Routing refusals therefore reject the promise and never leave the method as an exception, which keeps the `HostServices` contract (every method returns a promise and rejects with the refusal). Section 5.2 pins this at the door for `createSessionIn` and `pendingPermissionsIn`.
3. No socket, no loopback dial and no JSON-RPC frame is built by the in-process door. The hop to the workspace host is the gateway's existing child socket (`WorkspaceHostClient` / `RemoteSessionHost`), which is inside the host process and is not the bundle's self-dial. R10 stays retired.
4. Answer shape. `callPreserving()` settles with a `stdClass` (`RemoteSessionHost.php`, the preserving branch), while `Dispatcher::invoke()` returns an array. `HostServices` promises `PromiseInterface<array>`, and the bundles' routers take the answer as an array (`sugar-plugins/src/Bridge/ConversationRouterCore.php`: `function (array $created)` at lines 266 and 692, `function (array $r)` at lines 539 and 574). A `stdClass` raises a `TypeError` in each of them. So after `callRoot()` has run `learn()` and the root tag on the `stdClass`, the door converts every routed answer with `json_decode(json_encode($answer, JSON_THROW_ON_ERROR), true, JsonRpc::MAX_DEPTH)`. The conversion is deep, so nested items are arrays too, and an empty object becomes `[]`. Before the conversion, the door runs on the routed `stdClass` the same encode gate it runs on a primary answer: the `too_large` frame check (computed with a null id) and the `too_deep` / `internal` depth refusals of the class docblock. A routed answer therefore meets the limits a primary answer meets, and the refusal is the `RpcError` the primary path raises (section 5.2 tests this on a routed call). The conversion belongs to the door, not `callRoot()`, so the wire is unchanged.

The class-level constraint "no frame carries these calls" in `InProcessHostServices` is then true only for the primary path. The docblock must be rewritten in PL-5c-A to say that routed calls use the gateway's child socket hop and that the in-process door still never builds a frame. That wording change is part of the implementation step. The same rewrite replaces the class docblock sentence at `InProcessHostServices.php` line 41 ("The promise is settled before the method returns, so a caller that waits on it gets the answer in the same tick"). Primary calls still settle before the method returns. A routed call settles on a later tick, when its child host answers, so a caller must not read a routed promise synchronously. Section 5.2 pins both halves.

### 2.3 The refusal being replaced

- Replaced: the in-process door's `session_not_found` (and `ask_not_found`) for a non-primary id, from the primary dispatcher.
- Kept: `session_not_found` for an id no dispatcher holds. `routeOf` returns null for an unknown id (no `sessionRoots` row), so the primary dispatcher still refuses it with the same kind and message as the wire.
- Changed: an id with a `sessionRoots` row whose host is not running. `callRoot` starts that host (`hostFor`), which is the wire's behavior. If the start fails, the caller gets `workspace_failed` (Internal). See section 6, Q1, for cold starts.

### 2.4 Wiring

- No existing signature changes. `InProcessHostServices::new()` and `InProcessHostServices::bundleFactory()` gain a trailing optional `?Gateway $gateway = null`. Every two-argument call stays valid: `InProcessHostServicesTest` (lines 55, 249, 753 and 869) and `ChannelRegistryTest` (line 184, through `Serve::channelHostFactory()`). With a null gateway the door behaves as today, and a routed id is refused by the primary dispatcher. `InProcessHostServices` is not in the census (`SessionHostSurfaceCensusTest` censuses `Serve` and `SessionHost` only). `Serve::channelHostFactory(Dispatcher, LoopInterface): Closure` is censused (lines 171 and 205) and keeps its signature. The serve's own call (ServeStartup.php:419) moves to a new hand-appended method, `Serve::channelHostFactoryRouted(Dispatcher $protocol, LoopInterface $loop, Gateway $gateway): Closure`, which passes the gateway to `bundleFactory()`. The census golden takes one hand-appended line for it, the sanctioned pattern PL-5d used, so the Serve golden moves from 36 to 37 methods and from 4 to 5 public signatures (section 5.1). The `Gateway` is created at ServeStartup.php:261. The implementation must confirm that the registry's `boot()` call, which receives the factory, runs after line 261 in every serve path, `--detach` forks included (see the comment at ServeStartup.php:259 to 262). This was not traced in this step.
- `Gateway::start()` (the recycle timer) is already called by the serve. The in-process door does not start or stop the gateway.
- `Gateway::stop()` (server shutdown) stops every workspace host. The in-process door has no close path of its own; see section 3.4.

### 2.5 Ownership and viewer rules

`admit()` (the scope law at the gateway's own door) takes a `Connection`, and the in-process door has none, so it never passes through `onMessage()` and `admit()` is not called. The in-process client is an owner (`Scope::owner()`, no scope list), which `admit()` would pass untouched anyway. The implementation must not weaken this: the in-process door must not gain a viewer principal. A test pins that the in-process door is never refused `viewer_session_denied` or `owner_only`.

### 2.6 Root-taking methods: a separate interface

- `createSession` and `pendingPermissions` are the only two methods that need a root argument (Q2). Their root forms go on a new interface, `RootedHostServices` (`sugar-plugins/src/Host/RootedHostServices.php`, one type per file), as `createSessionIn(string $root, ?string $name, ?string $permissionMode): PromiseInterface` and `pendingPermissionsIn(string $root): PromiseInterface`.
- `HostServices` keeps every signature it has today. PHP rejects an implementation that drops a parameter the interface declares, so adding a parameter to `HostServices` would break four implementers: `candy-discord/tests/Fixture/FakeServe.php`, `candy-msteams/tests/Fixture/FakeServe.php`, `sugar-plugins/tests/Support/FakeHostServices.php` and `InProcessHostServices`. The separate interface keeps the bundles and the test fakes unchanged.
- Only `InProcessHostServices` implements `RootedHostServices` in PL-5c-A. A bundle that needs a non-primary root for a new session checks `instanceof RootedHostServices` in a later step. Without the check it gets the primary root, as today.
- Routing for the root forms follows section 2.2, with `root` taken from the argument instead of the params.
- Primary answers carry `root`. A primary answer from either root form (`session.create` or `permission.pending` on the primary dispatcher) gets `root` set to the gateway's canonical primary root (`Gateway::primaryRoot()`), as a routed answer does, unless the answer already names one. With a gateway the answer therefore always names the root the session is on. With no gateway (`null`, section 2.4) no canonical root is known and the caller's root was never checked, so the primary answer carries no `root` key. The session still lands on the primary dispatcher, as today. The `root` key is never echoed from the argument. Implemented in `InProcessHostServices::primaryAnswer()` (commit 1254bf8b2; the no-gateway case is pinned by `InProcessHostServicesTest::testWithoutAGatewayARootFormAnswerNamesNoRootItDidNotVerify`).

---

## 3. The event sink

### 3.1 Why a sink is needed

`Gateway::relay()`, `send()` and `outbox()` take `Connection` objects. `subscribers`, `rootClients` and `cursors` are keyed by `Connection::id()`. An in-process listener has no `Connection`, no `Outbox` and no socket, so today the relay cannot reach it.

The change is to make relay targets an interface, and keep the `Connection` path behind it unchanged. The interface is internal to `candy-code/src/Server/Workspace/`:

- `id(): string`: the key for `subscribers`, `cursors` and `rootClients`. For in-process sinks it is a prefix that cannot collide with a connection id (for example `inproc:<n>`).
- `isOpen(): bool`: for an in-process sink, "the client is still connected" (section 4.1).
- `accepts(?string $sessionId): bool`: false when the target has stopped that session (section 3.3). `Connection` always returns true. `relay()` asks it before the cursor check, so a refused target moves no cursor.
- `viewerSessions(): ?array`: always null for an in-process sink (an owner).
- `deliver(array $message, string $text, ?string $coalesceKey, bool $ephemeral, ?int $seq, ?string $sessionId): void`: the one method `relay()` calls per target, with the whole notification (`jsonrpc`, `method`, `params`) as `$message`. `Connection`'s implementation is the current `send()` / `pushEphemeral()` logic, moved unchanged. The in-process sink's implementation is sections 3.2 (rule 7) and 3.3.

`relay()`'s checks run in this order today: the durable cursor check and cursor write (Gateway.php:717 to 721), then `isOpen()` (Gateway.php:723), then the viewer pin, then send or queue. `accepts()` goes ahead of the cursor check, so a refused target moves no cursor. The other checks keep their order. Only the final delivery call and that one check change. `Connection::accepts()` returns true, so its path is unchanged. The implementation must keep `relay()`'s output for `Connection` targets byte-identical, which the gateway suites check (section 5.1).

### 3.2 Ordering

Rules, in priority order:

1. Per host, events reach the gateway in the order the host wrote them (one socket per host). The sink preserves that order.
2. For each session, delivery order equals seq order. A durable event with `seq <= cursor` is never delivered twice. The cursor is the gateway's `cursors[sinkId][sessionId]` entry, the same table the wire uses, advanced at queue time (section 3.3).
3. The sink's queue is FIFO. A drain never re-enters itself (the `delivering` / `draining` flags of `InProcessHostServices`, reused). A listener that calls back into the host (for example `sendTurn` from inside a listener) queues its own events behind the ones already owed. A primary-root call is answered in the same tick, as today. A routed call (section 2.2) is answered when its child host replies, on a later tick, so a listener must not read a routed answer synchronously.
4. Across sessions or across roots, no order is promised. This is the wire's rule too (each connection's frames come from several hosts).
5. Shedding never reorders. A shed live-only event is removed from its position, and no later event jumps ahead of an earlier durable one.
6. The overflow notice is inserted at the position of the first event it replaces. The listener therefore sees every event up to the cut, then the notice, then nothing for that session until it resubscribes.
7. Shape. The in-process sink delivers what `EventEnvelope::toArray()` gives for the same event, which is what primary-feed listeners get today, plus `root`. The sink takes `params` from the notification (`relay()` already sets `root` there, Gateway.php:709) and drops `jsonrpc` and `method`. Empty `data` is `[]`, not the `stdClass` that `toArray()` builds. The sink applies the conversion `drain()` applies to primary-feed listeners today. `drain()` in `candy-code/src/Host/InProcessHostServices.php` replaces an empty `stdClass` `data` with `[]` before every listener runs, so primary listeners already get `[]`. Rule 7 is as written, and this step reopens the sub-point that declined it: the sink and `drain()` share that check, so one listener sees one shape from either path, and `is_array($envelope['data'])` gives the same answer whether the event was routed or not. A bundle keys its routing by `sessionId` and ignores extra keys (see Q3).

### 3.3 Back-pressure

Proposed bounds. The numbers are recommendations, chosen for parity with existing bounds, and they are open for the owner (Q4).

- Queue bound: 2048 envelopes, the same as `SessionFeed::MAX_PRELIVE_BUFFER`, and a 1 MiB live shed trigger on encoded envelope bytes, the same number as `Outbox::SOFT_WATERMARK_BYTES`. The number matches, the behavior does not: the wire coalesces deltas above 1 MiB and drops newly arriving ephemerals only above `Outbox::HARD_WATERMARK_BYTES` (16 MiB). The sink sheds live events at 1 MiB, so a routed listener loses deltas that a wire client on the same backlog keeps. That is a deliberate deviation, and Q4 asks the owner to keep it or match the wire.
- Live-only events (`pushEphemeral`-class: deltas, no `seq`): when the queue is over a bound, coalesce by key (`sessionId|type|turnId`, the wire's key), then drop the oldest live-only event. Every live-only shed is reported, as the wire reports its drops (`Outbox::pushEphemeral`, with the overflow notice). The sink queues one `server.overflow {sessionId, dropped: <n>, action: "resubscribe"}` per session, placed at the position of the first event it sheds. Later sheds for that session, before the notice is delivered, raise its `dropped` count and add no second notice. A live shed does not stop the session: deltas keep flowing, and the durable backlog is untouched. `dropped` counts every envelope for that session removed from the queue undelivered. A live shed removes only live events, so its notice counts only those. Durable overflow (below) also counts the live deltas queued with the durable events it removes. `dropped` is therefore not a count of durable losses: a durable loss is recovered by resubscribing from the cursor, and a listener that needs the durable count must use the seq values it received. The notice takes the reserved slot described below, so it is never shed. This keeps the plan's "drop nothing silently" rule (`plan_plugin.md`, Phase 5, open decision 2) as far as a bounded queue allows: the listener is always told that it lost something.
- Durable events (with `seq`): never dropped silently. When a durable event would exceed a bound after live-only shedding, the sink drops that session's queued events from its first undelivered durable event onward, counts them as `dropped` (every removed envelope, live deltas included, as the live-only bullet defines it), enqueues one `server.overflow {sessionId, dropped: <n>, action: "resubscribe"}` envelope in their place, and stops delivering that session until the listener resubscribes. Other sessions keep flowing.
- The overflow notice is always accepted (it reserves one slot above the bound), so the listener always learns about a loss.
- Cursor, one rule. During normal flow it moves at queue time, as on the wire (section 3.2, rule 2), so a re-relayed seq is never queued twice. On overflow of a session, the cursor is reset to the last durable seq the listener actually received, not the last seq queued. The sink tracks that seq as it delivers and writes it back through `Gateway::rewindCursor(string $targetId, string $sessionId, int $seq)`. The door sets a subscribe's cursor through `Gateway::setTargetCursor(string $targetId, string $sessionId, int $seq)`. Both are plain writes to the same `cursors` table, and the gateway stays its one owner. This is what makes resubscribe recover exactly: the listener's `cursor()` closure (see `subscribeSession`) reports what it applied, and the gateway resumes from there. Section 5.2 pins this.
- A stopped session (after overflow) is refused by `accepts()` before the cursor check. Its events are discarded, the cursor does not move, and nothing for that session is delivered until the listener resubscribes. Other sessions are unaffected.
- A resubscribe clears the stop and removes that session's undelivered entries from the queue before the cursor is set (section 3.5). The host's replay from `afterSeq` supplies them again, or the answer's `reset` flag says the buffer no longer holds them, as on the wire. Without the purge, a replay from a lower cursor would queue the same seqs a second time.
- Delivery is budgeted per loop tick. `drain()` today empties its whole queue in one synchronous loop, so a 2048-envelope backlog behind a 5 ms listener would hold the serve loop for about 10 s, and every wire client would stall with it, because the bundles run in the serve process. Under the sink, each tick delivers at most `DELIVERY_BUDGET` envelopes (default 64, Q12), then re-arms itself with `futureTick` while its queue is not empty. Socket reads and the primary dispatcher's answers run between ticks. The bounds above protect memory: the queue fills only while the sink is behind, because `relay()` runs on socket reads.

### 3.4 What a dropped subscriber sees

| Case | What the listener gets | Source |
|---|---|---|
| Bundle calls `disconnect()` | Events it was already owed, all of them, delivered synchronously inside `disconnect()`: the per-tick budget (section 3.3) is off for that one call, so a backlog above the budget is delivered in full before the call returns. A call made from inside a sink listener cannot re-enter the running sink drain, so it clears the remaining sink queue (the per-sink FIFO of section 3.3), and the sink drain checks that the client is still open before each listener call, so the listeners after the caller in that envelope's loop are skipped too and no sink event reaches a listener after the client is gone. A call made from a primary-feed listener finds the sink drain idle, so it delivers the owed sink events as the outside call does. This rule covers the sink queue only. The shared `drain()` queue of the primary feeds is not cleared by this step (review finding 4): its behavior is unchanged, so the outer loop still hands its queued primary envelopes to the listeners after `disconnect()` removes the client, as it does today. Then nothing. No notice (the caller initiated it). Its sink subscriptions are removed. | `InProcessHostServices::disconnect()` (today: drain, then remove the client). New: `detachSink()`, which mirrors `onClose()` (`cursors`, `rootClients`, `subscribers`). |
| Sink queue overflow (section 3.3) | Events up to the cut, then `server.overflow {sessionId, dropped, action: resubscribe}`. Nothing for that session until it resubscribes. | New, in the sink. Same shape as the wire's overflow notice. |
| Live-only shed (section 3.3) | Every event in order. One `server.overflow {sessionId, dropped, action: resubscribe}` for the shed live events, at the first shed position. Deltas keep flowing. | New, in the sink. Same shape as the wire's notice. |
| Host crash, or `workspace.close` / idle retire | `server.overflow {sessionId, dropped: 0, action: resubscribe}` for each followed session, then the subscription is cleared. The next call for that root starts a fresh host. | `Gateway::lost()`. Delivered to sinks through `deliver()`. |
| Last subscriber of a session leaves | Nothing further for that session. The gateway sends `session.unsubscribe` upstream. | `dropSubscriber()`. Same as the wire. |
| Listener throws | Logged as `in-process event listener failed`. Other listeners still hear the event. | Existing rule in `InProcessHostServices::drain()`. |
| Server stop (`Gateway::stop()`) | `server.overflow {sessionId, dropped: 0, action: resubscribe}` for each followed session, delivered during the stop. `Gateway::stop()` calls each host's `stop()`, whose exit listener calls `lost()` while `hosts` still holds the host (it is cleared only after the loop, Gateway.php:197 to 208). A resubscribe made during the stop is refused `busy` with `draining`, and the bundle logs its follow failure mid-shutdown. This is the recorded behavior, and the wire's followers get the same notice. | `Gateway::stop()` through `lost()`. Q8 decides whether to change it. |
| Re-follow after a notice | The bundle calls `subscribeSession` with its `cursor()` closure, and the gateway resumes from the applied seq. | Existing `subscribeSession` contract (cursor read on every call). |

**Unfollow, a deliberate divergence from the primary path (review continuation, 2026-11-19).** `InProcessEventSink::unfollow()` purges the session's owed, undelivered events, and the primary path does not. A primary `session.unsubscribe` removes the feed, but the envelopes already in the primary feed's shared queue still reach the listeners, because that queue is not filtered by session. The routed purge is kept on purpose: an unfollow is final, and no owed routed event reaches a listener after it. The divergence is documented on the class docblock and on `unfollow()`, and pinned by `InProcessEventSinkTest::testUnfollowDropsTheOwedEventsAsTheDocumentedRoutedDivergence`. It is latent: no bundle calls `unsubscribeSession()` yet, so no shipped path reaches it.

### 3.5 Subscriptions made through the sink

- `subscribeSession` on a routed session: `callRoot()` answers the subscribe; then the door records the sink as a subscriber of that session, and sets the sink's cursor to `afterSeq` (when given) or the answer's `throughSeq`, through `Gateway::setTargetCursor`. It records nothing when the door has already timed out the call or the client has disconnected (section 4.1). The cursor rule is the wire's. Before the cursor is set, the sink clears any stop on that session and purges its undelivered entries; that purge is the sink's addition (section 3.3).
- `subscribeEvents` listeners stay per-client, as today. The sink is per in-process client, and it is registered in `rootClients[root]` when the client first uses a root, as `forward()` does for a connection. That is how server-scope events of a root reach it.
- `followedSessions()` returns the union of the primary feeds (as today) and the routed sessions this sink subscribes to, in the order it subscribed. `connected()` is unchanged.
- Last-subscriber rule: `unsubscribeSession` on a routed session removes the sink's subscription, and when it was the last one, sends `session.unsubscribe` upstream (the same as `forward()`).

### 3.6 Re-entrancy with the primary feeds

Primary feeds keep delivering through the existing in-process `Client` callback (`enqueue`). Routed events go through the sink's own queue. Both queues drain on the same loop, with separate queues, so a primary event can never reorder a routed one, and neither can reorder the other's session. A session has one home (primary or one root), so no session is ever in both queues. Q7 covers the one exception the code allows.

---

## 4. The ten inventoried wire methods on a non-primary root

Columns: how the root is chosen (`rootFor`), what a non-primary success looks like, what refusals pass through unchanged, and what changes from the 5a semantics. Row numbers match the 5a table. Timeouts: the door carries its own per-call timer, `InProcessHostServices::CALL_TIMEOUT_SECONDS = 20.0`, the value the bundles had from `ServeBridge` (`scripts/channel-bridge/src/Candy/ServeBridge.php:68`, the only ServeBridge left after PL-5d). It wraps every call the door makes, routed and primary alike. On expiry it rejects with `RpcError::of(ErrorCode::Busy, '<method> got no answer in 20s', 'timeout')`, the kind the bundles see today (5a rows 2 to 10). It must be an RpcError, not a plain RuntimeException (review finding 1): `ServeError::fromHost` in `candy-discord` and `candy-msteams` passes a `RefusedCall` through with its kind (RpcError implements it) and maps any other throwable to kind `internal`, so a plain exception would reach the bundles as `internal`. `Busy` is the code the gateway already uses for an unanswered host call (`host_timeout`), and it makes the wire's `retryable` flag true. The choice is the RpcError route. The other choice, changing `fromHost` in both bundles to map `timeout`, is declined: it edits two bundles in a design step, and the RpcError route needs no bundle edit. The gateway's 300 s forward deadline still applies inside the gateway and only frees its bookkeeping. The gateway's single-settle guard does not cover the door, which has its own settled flag. A late answer is handled by the rules in section 4.1. Every routed row resolves to the array shape of section 2.2, step 4. The door's encode gate (`too_large`, `too_deep`, `internal`, section 2.2, step 4) applies to every routed row, so a routed answer is refused exactly as a primary answer would be. Refusals raised by the gateway before the host is reached pass through unchanged on every routed row: `busy` with `draining` once the server is stopping, and `busy` with `too_many_workspaces` when the host limit (`MAX_HOSTS`) is reached, both from `hostFor()`. The bundle sees `busy`, and its retry rules do not change (Q9). Before PL-5c-A the in-process path had no timeout at all, so this is a new bound on the door, not a carried-over one.

Host crash during a routed call (review finding 2, decided here): a routed call in flight when its child host dies rejects with the close reason `RemoteSessionHost` raises (a plain RuntimeException). `callRoot()` wraps it through `asRefusal()` (section 2.1) to kind `workspace_failed`. So the door's routed rows change from the 5a kind `dropped` (the old loopback socket close, which no longer exists in-process) to `workspace_failed`. The change is deliberate. It is the answer a connection client of the gateway already gets for the same crash (`forward()`'s `$fail`), so the bundle sees one kind on both doors. The primary path has no host process to crash, so its rows keep their 5a kinds. Section 5.2 pins the change.

| # | Method (HostServices) | Root chosen by | Non-primary success | Refusals that pass through unchanged | What changes from 5a |
|---|---|---|---|---|---|
| 2 | `subscribeSession(sessionId, afterSeq, cursor)` | `sessionId` via `sessionRoots` (no `root` param) | Answer as an array (section 2.2, step 4), with `root` set. Sink subscribed; cursor set (section 3.5). | `session_not_found` when no row (from primary). `workspace_failed` from a failed host start. `busy` from `hostFor()` (`draining`, `too_many_workspaces`). `timeout` after 20 s (the door's timer, section 4 preamble). The gateway's `busy host_timeout` at 300 s reaches the door only after that, and is dropped. | Before: `session_not_found`. After: a routed follow. Re-follow after host loss uses the same `cursor` closure, unchanged. |
| 3 | `unsubscribeSession(sessionId)` | `sessionId` via `sessionRoots` | `null`. Sink removed; upstream unsubscribe when last. | None raised to the caller. Upstream failure is best-effort, as in `dropSubscriber()`. | Before: `null` after a no-op. After: the sink is removed too. Still no caller in any bundle today. |
| 4 | `sendTurn(sessionId, text, delivery, idempotencyKey, images, documents)` | `sessionId` via `sessionRoots` | The host's `session.send` answer, with `root`. | `session_not_found`, `too_many_turns` (busy, `retryAfterMs`), `refused` (-32009), `invalid_params` (image or document params), `busy` draining, `timeout` after 20 s (the door's timer). | Media refusal retry (5a row 4) unchanged: one retry without attachments. Retry on `busy` or `workspace_failed` is NOT added (Q9): see the note below. |
| 5 | `createSession(?string $name, ?string $permissionMode)` on `HostServices` (signature kept, returns `id`). Root form: `RootedHostServices::createSessionIn(string $root, ?string $name, ?string $permissionMode)` (section 2.6) | The `root` argument of `createSessionIn` when given (through `canonicalRoot`). `createSession()` always uses the primary root. | The `session.create` answer as an array (section 2.2, step 4), with `id` and `root`. The id is bound to the root through `learn()`, so later calls find it by `sessionId` alone. | `root_not_found` (NotFound, `root` not a directory), `invalid_params` (`root` not a string). `busy` from `hostFor()` (section 4 preamble). Missing-`id` RuntimeException (5a). | `createSession()` keeps its signature and its behavior. The root form answers the same way as the primary call, with `root` set. Without a root, behavior is unchanged. |
| 6 | `respondPermission(sessionId, askId, reply, note)` | `sessionId` via `sessionRoots` | The `permission.respond` answer. | `ask_expired` (NotFound), `ask_not_found`, `already_resolved` (Conflict; the core's substring match still works because the message is unchanged), `remember_refused` (Forbidden), `UnsupportedInServer` (-32030). | Before: `ask_not_found` on a routed session. After: the child host answers. The relayed-question path (`respondRelayed` on the host) is the host's own. |
| 7 | `cancelSession(sessionId, mode)` | `sessionId` via `sessionRoots` | The `session.cancel` answer. | `session_not_found`, `busy` draining. | Before: `session_not_found`. After: the turn is cancelled on the child host. |
| 8 | `sessionSnapshot(sessionId, limit)` | `sessionId` via `sessionRoots` | The `session.get` answer, with `root`. | `session_not_found`. | Before: `session_not_found`. After: routed. M's raw-message display (5a row 8) is unchanged. |
| 9 | `execCommand(sessionId, name, args)` | `sessionId` via `sessionRoots` | The `command.exec` answer. | `session_not_found`, `UnsupportedInServer` (-32030), `command_refused` (Conflict), busy draining. | Before: `session_not_found`. After: routed. Empty `args` is still an absent key (PL-bridge-args, unchanged). |
| 10 | `pendingPermissions(root)` | `pendingPermissionsIn(root)` when a root is given (through `canonicalRoot`), otherwise `pendingPermissions()` | The `permission.pending` answer for that root's host, with `root` set on the top-level result, as `forward()` does for every method except `session.list`. Items get no per-item `root`: the wire tags items only for session lists (`learn()`, Gateway.php:563), and permission items carry `sessionId`, not `id`. | `root_not_found` for a bad `root`. | New `RootedHostServices::pendingPermissionsIn(string $root)` (Q2, Q11). Without a root, the primary answer is unchanged. The wire method has no `sessionId` to route by, so this is the only method whose routing needs a root argument. |

Non-method rows, unchanged in behavior:

- Row 1 `hello()`: the in-process client's own hello. It is never routed (no root), and the in-process door never sends `root` on it. Routing has no effect on it.
- Row 13 `connect()` / `disconnect()`, row 14 `isConnected()`, row 15 `followedSessions()` (extended, section 3.5), row 16 `onReady` (unchanged, no caller): see sections 3.4 and 3.5.
- Row 11 `subscribeEvents()` and row 12 `subscribeFollowResults()`: the event sink (section 3) carries the routed events. Follow results stay unfired, as today.

Note on turn retries (row 4). A host runs its own `IdempotencyCache` (TTL 300 s), in its own process. After a host restart, the cache is empty, so an idempotency key does not protect a turn that was accepted by the old host. The bundle does not retry a `session.send` on `busy` or `workspace_failed` today, and this design does not add one (Q9). The implementation must add a test that a routed `sendTurn` failure with `workspace_failed` is not replayed by the door. That the cache is per process follows from the host being a separate child process. It was not traced inside `IdempotencyCache` in this step; the implementation step must confirm it before relying on it.

### 4.1 Late answers, disconnects and compensation

The door's timer and the gateway's deadline run separately. When the door times out, the gateway's call keeps running, and its answer can still land. The gateway's own bookkeeping for that answer still runs, because the host's state is the truth (`learn()` binds the ids it names). The door's bookkeeping does not run. It runs only when the door has not settled the call and the client is still connected. The door settles once, with its own flag, so a late answer never resolves the bundle's promise again.

- A late `session.subscribe` (a cold start can take up to 30 s, `START_TIMEOUT_SECONDS`, against the door's 20 s): the door records no sink. Without a compensating `session.unsubscribe`, the host keeps a subscription the gateway does not track, but the unsubscribe is sent only when no other call owns that subscription. The gateway keeps a generation per session id (`subscribeGen[sessionId]`): `callRoot()` bumps it on every `session.subscribe` it forwards, from a connection or from the door, and the door records the generation it sent under. A late answer compensates only when (a) its generation is still the latest for that session, so no later subscribe is in flight anywhere on the gateway, and (b) the gateway's `subscribers` table records no subscriber for that session. If either fails, a later call or a recorded subscriber owns the host subscription, and the late answer compensates nothing. The check is session-wide, not per sink: a per-sink counter misses a subscribe in flight from another sink or connection, and the compensating unsubscribe would drop that subscription, since the host's unsubscribe is per session (the same rule as `dropSubscriber()`). Without (a), the compensating unsubscribe would reach the host after a later subscribe and cancel it. That is the failure this rule prevents.
- A late `session.create` or `session.fork`: the gateway has bound the id, and the bundle never received it. A retry would create a second session. The door sends a compensating `session.delete` for that id, the cleanup `forward()` already runs for a delete.
- A `subscribeSession` answered after `disconnect()`: the door records nothing, so nothing is queued for that client. The compensating `session.unsubscribe` follows the same two conditions, (a) and (b), as the late-subscribe bullet above.
- A late answer to any other routed method is dropped after the gateway's bookkeeping. A turn that ran is reported by the host's own events, and the bundle's retry rules are Q9.
- An in-process sink's `isOpen()` is "the client is still connected" (section 3.1). After `disconnect()`, `relay()` skips the sink, so no event is queued for a dead client.

---

## 5. Test plan (for the implementation step, PL-5c-A-impl)

Run candy-code through `scripts/parallel-tests.sh` (K=24, scratch `--out`), as the owner's rule requires, and run the single-file suites once with output to a scratch file and stdin from `/dev/null`. The implementation must not run any suite serially to look at its output a second time.

### 5.1 Existing suites that must pass unchanged (wire parity)

- `candy-code/tests/Server/GatewayTrustRoutingTest.php`, `GatewayScopeEnforcementTest.php`, `GatewayOutboxTest.php`, `GatewayForwardDeadlineTest.php`, `GatewayRecycleLoopClockTest.php`, `DirBrowseTest.php`, `WorkspaceHostTest.php`: these pin the wire behavior that the `callRoot` refactor and the `relay()` target change must not move.
- `candy-code/tests/Host/InProcessHostServicesTest.php`: all tests except the one pinned routing-limit test (lines 518 to 550), which is replaced by 5.2.
- `candy-code/tests/Host/SessionHostSurfaceCensusTest.php`: passes with one hand-appended golden line for `Serve::channelHostFactoryRouted` (section 2.4) and no other change to either golden.
- `candy-discord` and `candy-msteams` suites: no bundle edits in 5c-A, so they must pass unchanged.
- `sugar-plugins` suite: `HostServices` is unchanged. The `RootedHostServices` interface is new and additive, and the one test added there (section 5.2) is test-only.

### 5.2 New and changed tests in candy-code

`candy-code/tests/Server/GatewayInProcessRoutingTest.php` (new):
- `rootFor()` returns null for the primary root, for a `root` that is the primary root after `canonicalRoot`, and for an unbound `sessionId`.
- `rootFor()` returns the root for a bound `sessionId` and for an explicit `root` param; a non-directory `root` throws `root_not_found`; a non-string `root` throws `invalid_params`.
- `callRoot()` answers with `root` set; `inFlight` returns to zero after answer, failure and deadline; the deadline rejects with `busy` / `host_timeout` exactly once.
- A cold start (no running host) starts it within `START_TIMEOUT_SECONDS`; a start failure rejects with `workspace_failed`.

`candy-code/tests/Host/InProcessHostServicesTest.php` (changed):
- Replace the pinned routing-limit test with: each of the nine routed methods (section 0) on a non-primary session returns the routed answer (use a real workspace host, as `WorkspaceHostTest` does, to prove the socket hop).
- An id no dispatcher holds still gets `session_not_found` from the door, and the same wire refusal.
- `permission.respond` with an unknown `askId` on a routed session gets `ask_not_found` from the child host (not from the door).
- `pendingPermissions()` with no root answers from the primary, unchanged. `pendingPermissionsIn(root)` answers from that root's host, with the top-level `root` set and no per-item `root`.
- `createSessionIn(root)` answers with `root` set and binds the id to that root, so a later `sendTurn` on it needs no root argument.
- Routing refusals reject, they do not throw (section 2.2, step 2, review finding 3): `createSessionIn('/no/such/dir', null, null)` and `pendingPermissionsIn('/no/such/dir')` each return a promise that rejects with a `RefusedCall` of kind `root_not_found`. The test calls the method inside a `try` and asserts that no exception left it, so a synchronous throw from `rootFor()` or `callRoot()` fails the test.
- A call that outlives `CALL_TIMEOUT_SECONDS` (routed or primary) rejects exactly once with an `RpcError` of kind `timeout` (code `Busy`). The test asserts `instanceof RefusedCall` and `refusalKind() === 'timeout'`, the input `ServeError::fromHost` reads (section 4 preamble, finding 1). Its late answer is dropped, and the gateway's `inFlight` returns to zero when that answer lands.
- A `sendTurn` failure with `workspace_failed` is not replayed (section 4 note).
- A host killed during a routed `sendTurn` rejects the door's promise once, with kind `workspace_failed` and not `dropped` (section 4 preamble, finding 2). The next call for that root starts a fresh host.
- A routed answer passes the encode gate (section 2.2, step 4): a `session.get` on a non-primary root whose answer the primary door refuses `too_large` is refused `too_large` on the routed call, and a `too_deep` answer is refused `too_deep`.
- Settling (section 2.2): a primary call's promise is settled when the method returns, and a routed call's promise is settled on a later tick. The class docblock sentence at line 41 is rewritten to match.
- The door never receives `viewer_session_denied` or `owner_only` (section 2.5).

`candy-code/tests/Server/GatewayEventSinkTest.php` (new):
- Per-session delivery order equals seq order, with two sinks subscribed to two sessions of one host.
- A second sink subscribing to the same session gets the replay from its cursor, and no durable event is delivered twice to either sink.
- Live-only deltas are coalesced by key while the queue is over its bound; no durable event is dropped by shedding.
- Durable overflow: the listener sees every event up to the cut, then exactly one `server.overflow` with `dropped` equal to the count dropped, then nothing for that session. With 3 durable and 2 live envelopes removed after the cut, `dropped` is 5, as section 3.3 defines it.
- After overflow, the sink's cursor equals the last delivered seq, not the last queued seq; a resubscribe with that cursor resumes with no gap and no duplicate.
- Another session on the same sink keeps flowing during one session's overflow.
- Host crash (`lost()`) sends `resubscribe` to each routed sink's followed session, and the next call starts a fresh host.
- Server stop (`Gateway::stop()`): one `resubscribe` notice per followed session, delivered during the stop. A resubscribe made then is refused `busy` with `draining`, and the sink's cursor is unchanged (section 3.4).
- `disconnect()` delivers the owed events and then nothing; the sink is removed from `subscribers`, `cursors` and `rootClients`; the last subscriber's leave sends `session.unsubscribe` upstream.
- A listener that calls `sendTurn` from inside a callback: its event is delivered after the owed ones, the outer drain is not re-entered, and no order is changed.
- A throwing listener is logged and the other listeners still receive the event.
- Server-scope events of a root reach a sink that used that root, and no sink that never used it.
- Shape: a routed event reaches a listener with the `toArray()` keys plus `root`, and empty `data` is `[]` (section 3.2, rule 7). A routed listener and a primary listener receive identical arrays for the same event, apart from `root`.
- Resubscribe purge: a sink that still holds undelivered entries for a session, and resubscribes from a lower cursor, delivers each seq once (seq 1 and 2 queued but not drained, then a re-follow replays them).
- Stopped session: after overflow, events for that session are refused by `accepts()` before the cursor check, so they are discarded, the cursor does not move, and a resubscribe resumes with no gap and no duplicate.
- Routed answers are arrays: `createSessionIn`, `sessionSnapshot` and `pendingPermissionsIn` resolve to arrays with the primary method's key set, so the router's `function (array $created)` raises no `TypeError` (section 2.2, step 4). Add this test to `InProcessHostServicesTest`.
- Gateway refusals: `busy` with `draining` after `Gateway::stop()`, and `busy` with `too_many_workspaces` on the ninth root, each reach the bundle as `busy` with that kind.
- Late answers (section 4.1): a late `session.subscribe` with no later subscribe in flight and no recorded subscriber triggers exactly one compensating `session.unsubscribe` and records no sink. Overlap: sub1 times out, sub2 is sent for the same session, sub1 answers late, then sub2 answers. No compensating unsubscribe is sent after sub2's answer, sub2's subscription is the sink's, and its events arrive. A late subscribe while another sink or connection records the session sends no compensation. A late `session.create` triggers a compensating `session.delete`, and a bundle retry then creates exactly one session. A `subscribeSession` answered after `disconnect()` triggers a compensating unsubscribe, and the sink's `isOpen()` is false from then on.
- Live-only shed: one `server.overflow` per session at the first shed position, later sheds before delivery raise its `dropped` count and add no second notice, the session keeps flowing, and no durable event is shed.
- Budget: a 2048-envelope backlog behind a stalled listener delivers at most `DELIVERY_BUDGET` envelopes per tick, and a wire request made meanwhile is answered before the backlog is empty. The same budget holds for the primary queue (Q12).
- Disconnect with a backlog above the budget (section 3.4): a 2048-envelope backlog behind a stalled listener, then `disconnect()` from outside a listener: every owed envelope is delivered inside the call, and none after it returns. `disconnect()` called from inside a sink listener clears the remaining sink queue and skips the later listeners of the same envelope, so no sink listener call reaches the closed client; `disconnect()` called from a primary-feed listener delivers the owed sink events as the outside call does. The primary `drain()` queue is not covered by this test; its behavior is unchanged (section 3.4, finding 4).
- Idle clock: a routed call calls `touch()`, so `recycleIdle()` does not retire that root while the call is recent.

- `sugar-plugins/tests/Bridge/` (test only, no source change): a routed ask is answerable through the event path. The `permission.requested` event fills `askSession`, so `answerByAskId()` routes by `sessionId`. The same ask must stay unanswered when the primary-only fallback poll (`permission.pending`, no root) returns nothing. That pins the Q11 gap instead of leaving it silent.

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
- Default: a separate optional interface, `RootedHostServices` (section 2.6), with `createSessionIn(string $root, ...)` and `pendingPermissionsIn(string $root)`, implemented only by `InProcessHostServices`. `HostServices` keeps its current signatures, so no bundle or test fake changes. The primary root stays the default through `createSession()` and `pendingPermissions()`.

Q3. Does a delivered event carry `root` for a routed session?
- Default: yes. The gateway already adds `root` to relayed notifications, and the sink delivers the `toArray()` shape plus `root` (section 3.2, rule 7). Additive: the bundles' router keys by `sessionId`.

Q4. Sink bounds. Are 2048 envelopes and 1 MiB the right queue bounds for a routed sink, and is a 1 MiB live shed the right trigger?
- Default: 2048 envelopes (parity with `SessionFeed::MAX_PRELIVE_BUFFER`), and the 1 MiB live shed as written in section 3.3. That shed is a deliberate deviation, not parity with the wire: `Outbox` coalesces deltas above 1 MiB and drops newly arriving ephemerals only above 16 MiB (`HARD_WATERMARK_BYTES`). The owner chooses between keeping the 1 MiB shed (routed listeners lose deltas that wire clients keep at the same backlog) and matching the wire (coalesce at 1 MiB, shed at 16 MiB). Both are constants, so either choice is one edit.

Q5. Overflow granularity: when a durable event does not fit, does the sink stop only the affected session, or the whole sink?
- Default: the affected session only (section 3.3). Other sessions keep flowing, and the wire already behaves per session.

Q6. Where does the routing seam live? Expose `routeOf` as a public `rootFor`, or move routing into its own class?
- Default: add public `Gateway::rootFor()` and `Gateway::callRoot()` (section 2.1). A separate class adds a second owner of `sessionRoots`, which the gateway's own docblock warns against. Smallest change.

Q7. A session id that both the primary dispatcher and a child host hold. Today the wire routes by the gateway (`sessionRoots` is never filled with primary ids), and in-process routing would do the same.
- Default: match the wire, and add a test that no primary session id is ever bound in `sessionRoots`. Session ids are generated, so a collision is not expected; the test makes the assumption explicit.

Q8. Server stop: should in-process listeners be told the host went away?
- Default: keep the recorded behavior (section 3.4, server stop row). `Gateway::stop()` sends each followed session a `resubscribe` notice through `lost()` before it clears `hosts`, and the wire's followers get the same notice. A bundle's resubscribe during the stop is refused `busy` with `draining`. Changing this means clearing `hosts` before the hosts stop, which changes the wire's shutdown behavior, so it is an owner call and not part of 5c-A.

Q9. Retries for routed turns: should the door retry a `session.send` that fails `busy` (host timeout) or `workspace_failed`?
- Default: no. The bundle keeps its current rules (one media-refusal retry only). The child host's idempotency cache is per process, so a retry can run a turn twice after a host restart.

Q10. Gateway forward deadline (300 s) versus the bundle's per-call timeout (20 s).
- Default: keep both. The door's 20 s timer (section 4 preamble) is what the bundle sees. The gateway's 300 s deadline only frees the gateway's bookkeeping, and its late answer is dropped.

Q11. `pendingPermissions` across roots: answer for the given root only, or fan out to every running host?
- Default: the given root only (or the primary when none is given). A fan-out needs a second round trip per running host, and the bundle only asks about the root of a session it is serving. Routed asks are answered through the event path: `ConversationRouterCore::presentApproval` fills `askSession` from the `permission.requested` event, so `answerByAskId()` routes by `sessionId` and never reaches its fallback. The fallback poll (`permission.pending` with no root, `sugar-plugins/src/Bridge/ConversationRouterCore.php:573`) sees primary asks only, so a routed ask whose event was never presented stays unanswerable through it. This is a known gap for 5c-A. Closing it means the fallback passing a root, which is a bundle change and out of scope here. Section 5.2 pins the event path.

Q12. Delivery budget per tick. How many envelopes may one tick deliver?
- Default: 64 per tick (`DELIVERY_BUDGET`), for the sink and for the primary queue in `drain()`, re-armed with `futureTick` while the queue is not empty. Order is unchanged. Only timing moves for primary listeners, which is the one behavior change this step makes outside routing. Section 3.3 gives the reason.

---

## 7. What this step does not change

- No code, no test, no composer file, no doc under `docs/`. This file is the only change.
- The wire protocol and external clients are unchanged (plan_plugin.md, O3 option B: the wire stays).
- The bundles, `scripts/channel-bridge` and `sugar-serve-protocol` are unchanged. PL-5e (plugins drop `sugar-serve-protocol`) is already on master.
- No publish, no issue, no PR, no push. The impl stage does not publish anything either.

## 8. Next step

PL-5c-A-impl, after the owner answers Q1 to Q12 (or accepts the defaults). Its brief is section 2 (routing), section 3 (sink), section 4 (the ten methods) and section 5 (tests). Recommended order inside the impl step: (1) the `callRoot` / `rootFor` refactor with no behavior change and the gateway suites green; (2) the sink interface with `Connection` as the only implementation, suites green; (3) the in-process sink and the routed calls; (4) the replaced pinned test and the new suites.

## 9. Review-fix record (round 3)

Each finding and the section that now answers it. No finding was declined.
- Late subscribe answer cancelling a retried subscribe: section 4.1 (generation and subscriber conditions), section 5.2 overlap test.
- Signature drift and census: section 2.4 and section 5.1 (trailing optional gateway, one hand-appended census line, no existing signature changed).
- Routed answers and the encode gate: section 2.2 step 4, section 4 preamble, section 5.2.
- Settled-before-return contract: section 2.2 step 3, section 3.2 rule 3, section 5.2.
- Budget and disconnect: section 3.3, section 3.4 disconnect row, section 5.2.
- Server stop notice: section 3.4 server stop row, Q8, section 5.2.
- Live shed bound versus Outbox: section 3.3, Q4.
- The `dropped` count: section 3.3 live-only and durable bullets.
- Empty `data` shape (rule 7): section 3.2, sub-point reopened; `drain()` already converts it.
- "All ten routed": section 0 (nine routed, `hello` not).
- Order of checks in `relay()`: section 3.1.

## 10. Review-fix record (round 4, bounded continuation)

Each open finding of the last round and where it is answered. No finding was declined.
- (HIGH) Door timeout kind: section 4 preamble. The door rejects with `RpcError` `Busy`, kind `timeout`, so `ServeError::fromHost` keeps the kind. Test in section 5.2 (timeout test).
- (MEDIUM) `callRoot()` wrapping and in-flight host crash: section 2.1 (`asRefusal()`, shared with `forward()`), section 4 preamble (crash maps to `workspace_failed`, a deliberate change from `dropped`). Test in section 5.2 (host-crash test).
- (LOW) `createSession` signature in row 5: section 4 table row 5 now shows `createSession(?string $name, ?string $permissionMode)` and the `RootedHostServices::createSessionIn` root form.
- (LOW) Disconnect clear rule names the sink queue: section 3.4 disconnect row and section 5.2 disconnect test. The primary `drain()` queue is unchanged.
- (review check, round 5) The disconnect clear rule is keyed on a sink listener, not any listener, and the sink drain skips the remaining listeners of the in-flight envelope once the client is closed, so "no sink event reaches a listener after the client is gone" holds. Section 3.4 disconnect row and section 5.2 disconnect test.
- (round 5, doc drift, verified against the source) Section 0 no longer says the failure semantics are unchanged: the routed host-crash change is named there too (section 4 preamble). Section 2.2 step 2 strips `root` on the primary path as well as the routed path (Gateway.php:299). The `channelHostFactory` citation in section 2.4 is ServeStartup.php:588, not 590. The round-4 fixes (door timeout kind, host-crash `workspace_failed`, the row 5 signature, the sink-queue clear rule) were already in the file and were re-checked against the source, not re-applied.

## 11. Review-fix record (round 6)

- (MEDIUM, review finding 3) Routing refusals escaping the door as a synchronous throw: section 2.2 step 2 now says `rootFor()` and the routed call run inside the door's `answer()` wrapper, so `root_not_found` and `invalid_params` reject the promise. Test in section 5.2 (`createSessionIn` and `pendingPermissionsIn` with a non-directory root reject with `root_not_found` and do not throw).

## 12. Review-fix record (impl review, round 1)

Each finding of the implementation review and where it is answered. No finding was declined.
- (LOW, re-arm rationale) Section 3.3 and Q12 name a `futureTick` re-arm. The built re-arm of a sink tick is a zero-delay timer (`addTimer(0.0)`, `InProcessEventSink::schedule()`), and so is the primary feed's (`InProcessHostServices::tick()`). The first tick stays a future tick. The vendored `react/event-loop` `FutureTickQueue::tick()` runs only the callbacks counted when it starts, so a future tick queued from a tick waits for the next loop iteration. Both re-arms pace one budget per iteration. The docblocks that said a future tick would drain the backlog in one iteration were wrong and are corrected. Delivery order and budget are unchanged.
- (LOW, disconnect from a sink listener) Section 3.4 disconnect row. Test `testADisconnectFromASinkListenerSkipsTheLaterListenersOfThatEnvelope` in `candy-code/tests/Host/InProcessRoutedTest.php`.
- (LOW, server-scope fan-out) Section 3.5 (`useRoot`). Test `testServerScopeEventsReachOnlyTheSinksOfTheRootsTheyUsed`.
- (LOW, compensation with a recorded subscriber) Section 4.1, the subscriber condition (b). Test `testALateSubscribeLeavesAnotherSinksSubscriptionInPlace`.
- (LOW, Q11 gap) Section 5.2, the `sugar-plugins` bullet. Test `testARoutedAskNoEventPresentedStaysUnansweredWhenThePrimaryPollIsEmpty` in `sugar-plugins/tests/Bridge/ConversationRouterCoreTest.php`.
- (finding 1, write the cursor back on every delivery) CLOSED, no code change. The review asked the sink to write the gateway cursor back on each delivery. Section 3.3's queue-time rule stays, because a write-back on delivery would weaken the duplicate guard in `Gateway::relay()` (`seq <= cursor`). The overflow path is reachable: durable events that fill the queue to `InProcessEventSink::QUEUE_BOUND` with no live entry left to shed overflow the session (`InProcessEventSink::deliver()`, after `shedOldestLive()` fails), and the cut is the session's first undelivered durable entry. No write-back on delivery is needed because `InProcessEventSink::overflow()` rewinds the gateway cursor to the session's last delivered seq (`rewindCursor()`, `InProcessEventSink.php:337`). That rewind is what keeps the cursor correct after an overflow, so it must stay and must not be treated as dead code. `InProcessEventSink::pump()` is unchanged. The overflow window is pinned by `GatewayEventSinkTest::testTheOverflowWindowCursorEqualsTheLastDeliveredSeq`, and `GatewayEventSinkTest::testTheSinkCursorFollowsWhatIsQueuedAndRewindsToWhatWasDeliveredOnOverflow` pins the rewind itself: after an overflow the cursor equals the last delivered seq.

## 13. Review-fix record (impl review, continuation)

Each open finding of the implementation review and where it is answered. No finding was declined.
- (finding 1, durable overflow) Section 3.3 durable bullet: a durable event sheds queued live deltas first, while the queue is at the bound and a delta is left, and overflows the session only after that. Code: `InProcessEventSink::deliver()`. Test: `InProcessEventSinkTest::testADurableEventShedsAQueuedDeltaBeforeItOverflowsTheSession`.
- (finding 2, unfollow) Section 3.4, the unfollow paragraph. Default taken: keep the purge, and document the divergence from the primary path. Test: `InProcessEventSinkTest::testUnfollowDropsTheOwedEventsAsTheDocumentedRoutedDivergence`.
- (finding 3, encode gate) The routed conversion `InProcessHostServices::wire()` is driven directly, since an end-to-end routed answer past the ceiling is not practical. Tests: `InProcessRoutedTest::testARoutedAnswerPastTheFrameCeilingIsRefusedTooLarge` and `testARoutedAnswerNestedPastTheWireDecodeDepthIsRefusedTooDeep`.
- (finding 4, gateway sink tests) New `candy-code/tests/Server/GatewayEventSinkTest.php`: stop() resubscribe notices and the refused resubscribe, lost() notices to sinks, accepts() before the cursor check, the sink cursor and overflow rules, the primary tick() budget (`testThePrimaryFeedDeliversAtMostTheBudgetPerTick`), and the seeded interleaving property (section 5.3, fixed seeds).
- (finding 5, door tests) Viewer and owner refusal never reach the door (section 2.5): `InProcessRoutedTest::testTheDoorIsNeverRefusedViewerSessionDeniedOrOwnerOnly`. A failed host start rejects `workspace_failed`: `testAFailedHostStartRejectsTheRoutedCallWorkspaceFailed`. A cold call starts the host: `testAColdCallStartsTheRootsHostAndAnswersThroughIt`. A sendTurn from inside a listener: `testASendTurnFromInsideAListenerQueuesItsEventBehindTheOwedOnes`.
- Harness notes: the gateway tests use a fake WorkspaceHostClient (no child process) whose RPC stub is closed without a socket, so stop() runs its exit listeners the way a real stop does. The sink's pump is reached by reflection, as the existing sink test does.
