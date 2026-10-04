# Server mode (`sugarcrush serve`)

`sugarcrush serve` runs an HTTP + WebSocket server that a browser (the
[`sugar-crush-web`](https://github.com/sugarcraft/sugar-crush-web) UI) or a
script drives sugar-crush through. It runs in the foreground until `Ctrl+C` or
`SIGTERM` — or in the background with `--detach` — on the same ReactPHP loop
the engine's forked turns use, and serves one project root — the current
directory or `--root`.

This page covers the **transport** — binding, authentication, the WebSocket
endpoint and the security model — and the **`sugarcrush.v1` protocol** spoken
over it: sessions, turns, events, permission answers
([The `sugarcrush.v1` protocol](#the-sugarcrushv1-protocol)).
[Background mode](#background-mode) and the verbs that manage a running server
(`serve status|stop|logs|url|token`) are below.

## Starting it

```sh
sugarcrush serve                       # 127.0.0.1:7420
sugarcrush serve --port 0              # any free port; the startup lines say which
sugarcrush --root ~/src/app serve      # serve another project
sugarcrush serve --output-format json  # one JSON document with the URL on stdout
sugarcrush serve --detach              # in the background; prints the URL and pid, then returns
```

On start it prints, on stderr, the address, the project root, the permission
mode its sessions start in, where the web UI is served from, and a **sign-in
URL**:

```
sugarcrush serve: listening on http://127.0.0.1:7420 (pid 4242)
  root:            /home/me/src/app
  permission mode: default
  web UI:          /…/vendor/sugarcraft/sugar-crush-web/dist
  sign in:         http://127.0.0.1:7420/#code=3f9c…
                   (one-time code, valid 120 s; Ctrl+C stops the server)
```

Open the sign-in URL in a browser on the same machine. The code rides in the URL
**fragment**, so it never reaches a request line, a server log, a proxy or a
`Referer`; the page posts it to `/api/login` and receives a session cookie.

| Flag | Default | Meaning |
|---|---|---|
| `--host <ip>` | `127.0.0.1` | Address to bind. `localhost` and `::1` are loopback too. Anything else is refused without `--allow-remote`. |
| `--port <n>` | `7420` | Port to bind; `0` picks a free one. |
| `--allow-remote` | off | Permit a non-loopback `--host`. Prints a warning box: there is no built-in TLS. |
| `--allowed-origin <list>` | none | Comma-separated extra browser origins (`http(s)://host[:port]`) accepted beside the server's own. A repeat keeps the last value. |
| `--web-root <dir>` | the installed `sugarcraft/sugar-crush-web` build | Serve the UI from `<dir>`. |
| `--no-web` | off | API and WebSocket only. |
| `--allow-bypass` | off | Let sessions run in `bypass-permissions` or `dont-ask`. |
| `--allow-root` | off | Permit running as root. |
| `--detach` | off | Run in the background ([Background mode](#background-mode)). |
| `--parent-pid <pid>` | none | Stop when process `<pid>` exits ([Parent-pid watchdog](#parent-pid-watchdog)). |
| `--permission-mode <mode>` | `default` | The global flag: the mode server sessions start in. |

The flags belong to the verb: `sugarcrush --port 1 serve` is an unknown option
(exit `2`), exactly like `session`'s flags.

Each setting resolves **flag → environment variable → user config key
→ default**; the highest source that is set wins outright, and lists are not
merged across sources. The permission mode resolves `--permission-mode` →
`SUGARCRUSH_PERMISSION_MODE` → `default`; the persisted `permissionMode` key is
the TUI's and does not apply here.

| Variable | Settings key | Meaning |
|---|---|---|
| `SUGARCRUSH_SERVER_HOST` | `server.host` | Bind address. |
| `SUGARCRUSH_SERVER_PORT` | `server.port` | Bind port. |
| `SUGARCRUSH_SERVER_ALLOWED_ORIGINS` | `server.allowedOrigins` | Extra origins (a comma-separated string; the key is a JSON list). |
| `SUGARCRUSH_SERVER_WEB_ROOT` | — | UI directory. |
| `SUGARCRUSH_SERVER_TOKEN` | — | The owner token, instead of the stored one (≥ 32 characters; for containers). |
| `SUGARCRUSH_SERVER_DIR` | — | State directory, default `~/.sugar-crush/server`. |
| `SUGARCRUSH_SERVER_PARENT_PID` | — | The parent pid to watch, like `--parent-pid`. |
| — | `server.allowedHosts` | Host names (or `host:port`) answered beside the loopback names — a reverse proxy's public name. |
| — | `server.trustedProxies` | IPs or CIDRs whose `X-Forwarded-For` / `X-Forwarded-Proto` are believed. |
| — | `server.allowBypass` | `true` is the same as `--allow-bypass`. |

Every `server.*` key is read from `~/.sugar-crush/config.json` only — never from
a project file, because a cloned repository must not be able to open a port,
widen the origins a browser may drive the agent from, or hand out the bypass
modes. A non-loopback `server.host` still needs `--allow-remote` on each launch.

### Refusals and exit codes

`serve` refuses to start, at exit `2` before binding anything, when:

- an operand follows the verb that is not one of `status`, `stop`, `logs`,
  `url` or `token`, a flag belongs to a different action (`serve stop
  --detach`), or a flag value is malformed (a port that is not `0`–`65535`, an
  origin that is not `http(s)://host[:port]`, a `--parent-pid` that is not a
  pid);
- `--host` is not loopback and `--allow-remote` was not given;
- the permission mode is `bypass-permissions` or `dont-ask` and neither
  `--allow-bypass` nor `server.allowBypass` permits it;
- it runs as root without `--allow-root`;
- `ext-pcntl`, `ext-posix` or `ext-ffi` is missing (or FFI is disabled by
  `ffi.enable`) — see [Why pcntl, posix and FFI](#why-pcntl-posix-and-ffi);
- the state directory is unsafe (a symlink, someone else's, or not exactly
  `0700`), or `SUGARCRUSH_SERVER_TOKEN` is shorter than 32 characters;
- `--parent-pid` names a process that is not running.

A port that is already taken is exit `1`: it ran, failed, and may succeed once
the port is free. So is a second server on the same state directory — the
first one holds its lock — and a `--detach` whose background server failed to
bind (its reason is printed; the detail is in `server.log`). Under `--output-format json` that failure is still one
document on stdout, carried like `doctor`'s failing report — as the answer, not
as a new `error.type`: `{"result": {"listening": false, "address": "…",
"reason": "…"}}`. A refusal is the usual `usage` error document. `sugarcrush doctor` reports the three extensions as its
`server mode` check (a `WARN`, never a `FAIL` — the TUI does not need them).

## Background mode

```sh
sugarcrush serve --detach        # background: prints the URL + pid once bound, exits 0
sugarcrush serve status          # pid, URL, root, uptime, and whether /api/health answers
sugarcrush serve stop [--force]  # SIGTERM, up to 30 s to drain, then SIGKILL (--force: SIGKILL now)
sugarcrush serve logs [-f]       # the end of server.log; -f follows it until the server stops
sugarcrush serve url             # a sign-in URL with a fresh one-time code
sugarcrush serve token [--rotate]
```

`--detach` daemonizes the way background (`/bg`) sessions do — the same
sequence, in one place (`Support\Daemonize`): `umask 077`, fork, `setsid()`,
fork again, so the server has no controlling terminal and outlives the shell
that started it. Its stdin is `/dev/null` and its stdout and stderr append to
`server.log`. The command you ran waits until the server has bound its port and
then prints the same startup lines a foreground server does (one JSON document
under `--output-format json`, with `"detached": true` and the `log` path), so
the URL it prints is one that already answers. The sign-in code itself is never
written to the log.

**The state directory** (`~/.sugar-crush/server/`, or `SUGARCRUSH_SERVER_DIR`)
is `0700` — refused if it is a symlink, someone else's, or any other mode — and
holds:

| File | What |
|---|---|
| `token` | the owner token, `0600` |
| `server.json` | the discovery record: `{pid, procStartTime, version, protocol: {min, max}, url, host, port, root, startedAt, detached, log}`, `0600`, replaced atomically. Never the token. |
| `server.lock` | the singleton lock: `flock()` held for the server's whole life, so the kernel releases it however the server dies |
| `control.sock` | the local socket `serve url` asks the running server on; every request carries the owner token |
| `server.log` | a detached server's stdout and stderr, rotated past 10 MiB into `server.log.1` and `server.log.2` |

One server runs per state directory: a second `serve` finds the lock held and
exits `1`, naming the running one. A foreground server keeps the same record,
lock and control socket, so `status`, `stop` and `url` work on it too.

**A record is checked, not believed.** `status` and `stop` act on the pid in
`server.json` only while that pid is still the process that started at the
recorded `procStartTime` (`/proc/<pid>/stat`), so a pid the kernel has handed
to something else is never signalled. A record that fails the check — a server
that was SIGKILLed cannot remove its own — is reported as stale, and `stop`
removes it.

**Stopping.** `SIGTERM` or `SIGINT` (what `serve stop` and `Ctrl+C` send)
drains the server: it stops accepting, closes every WebSocket with `1001`,
removes `server.json` and `control.sock` and releases the lock. `serve stop`
waits up to 30 s for that, then sends `SIGKILL`; `--force` skips straight to
`SIGKILL`. `SIGCHLD` is never handled — the turn children are reaped by the
engine's own sweep.

| Verb | Exit `0` | Exit `1` |
|---|---|---|
| `serve status` | a server runs (`health` is `ok`, or `unreachable` when it does not answer `/api/health`) | none runs (a stale record is reported as such) |
| `serve stop` | it stopped | none was running, or it did not exit |
| `serve logs` | the log was printed | there is no `server.log` (only a detached server writes one) |
| `serve url` | a fresh sign-in URL was printed | no server runs, or it refused the token |
| `serve token` | the token was printed (`--rotate`: replaced first) | — (refused at `2` while `SUGARCRUSH_SERVER_TOKEN` is set) |

Under `--output-format json` each prints one `{"result": …}` document — the
"no server" answers included, as results rather than errors; `logs -f` streams
text and does not combine with it. The management verbs read the state
directory without creating it.

### Parent-pid watchdog

`--parent-pid <pid>` (or `SUGARCRUSH_SERVER_PARENT_PID`) makes the server stop,
exactly as `SIGTERM` would, once that process has exited — for an editor or a
TUI that starts a private server and may crash without stopping it. The parent
is checked every second by pid **and** start time, so a recycled pid reads as
gone; a pid that is not running at startup is refused. It combines with
`--detach`.

### systemd

Under a service manager run the server in the **foreground** — the manager is
the supervisor, and `--detach` would only hide the process from it.
[`examples/sugarcrush.service`](examples/sugarcrush.service) is a user unit:

```sh
cp docs/examples/sugarcrush.service ~/.config/systemd/user/
systemctl --user daemon-reload
systemctl --user enable --now sugarcrush
journalctl --user -u sugarcrush -f   # the server's log; sugarcrush serve url for a sign-in link
```

## Authentication

Every request that can read or change anything carries a credential — **on
loopback too**. Anything that can send a prompt can run code as you, and
127.0.0.1 is reachable by every other account on the machine and, by DNS
rebinding or a cross-site WebSocket, by any page your browser opens.

**The owner token.** 32 random bytes (64 hex characters), minted on the first
`serve` into `<state dir>/token` — mode `0600` in a `0700` directory this user
owns. `SUGARCRUSH_SERVER_TOKEN` replaces it. It is compared with `hash_equals`
and never printed by `serve` itself; `sugarcrush serve token` prints it on
request for a script that needs a bearer credential, and `serve token
--rotate` replaces it (a running server keeps accepting the old one until it
restarts).

**Browsers** never hold the token:

1. `serve` prints a sign-in URL with a **one-time login code** (single use,
   valid 120 s) in its fragment.
2. The page posts `{"code": "…"}` to `POST /api/login` and receives an
   **`HttpOnly`, `SameSite=Strict`** cookie, `sugarcrush_session` (7-day sliding
   lifetime; `Secure` when a trusted proxy reports HTTPS). Posting
   `{"token": "…"}` works too.
3. Before each WebSocket connect the page asks `POST /api/ticket` (cookie
   only) for a **single-use ticket** valid 30 s and bound to its cookie
   session, and opens `/ws?ticket=…`.

Sessions live in memory: restarting the server signs every browser out, and the
token file is the only durable credential.

**Scripts and tools** authenticate directly:

- HTTP: `Authorization: Bearer <token>`;
- WebSocket: the same header, or — for a client that cannot set headers —
  offer the subprotocol `sugarcrush.auth.<token>` beside `sugarcrush.v1`. The
  101 echoes `sugarcrush.v1` only, never the token entry.

**Rate limiting.** Ten wrong credentials from one address within a minute lock
that address out for five minutes (`429` with `Retry-After`). Behind a trusted
proxy the address is the right-most untrusted `X-Forwarded-For` hop.

### HTTP endpoints

| Endpoint | Auth | Answer |
|---|---|---|
| `GET /api/health` | none | `{"ok": true, "protocol": 1}` — nothing else, so a prober learns neither the version nor the root |
| `POST /api/login` | none (rate limited) | `{"code"}` or `{"token"}` → `{"ok": true}` + the session cookie; wrong → `401` |
| `POST /api/ticket` | cookie | `{"ticket", "expiresInSeconds": 30}`; a bearer client gets `400` (it needs no ticket) |
| `POST /api/logout` | any | ends the cookie session and clears the cookie |
| `GET /ws` | ticket, bearer or token subprotocol — never the cookie alone, which a browser attaches by itself | the WebSocket upgrade |
| anything else | none | the web UI's files (`GET`/`HEAD` only) |

Refusals are JSON: `{"error": {"kind": "…", "message": "…"}}`. Clients branch on
`kind` (`unauthorized`, `rate_limited`, `host_refused`, `origin_refused`,
`subprotocol_required`, `not_found`, …), never on the message.

## WebSocket

`GET /ws` with subprotocol **`sugarcrush.v1`** — required: an upgrade that does
not offer it is refused `426` before upgrading. Text frames only, UTF-8 JSON.

| Limit | Value | Past it |
|---|---|---|
| Client message (all fragments) | 1 MiB | close `1009` |
| Binary message | not accepted | close `1003` |
| Invalid UTF-8, bad masking, bad opcode | — | close `1002` / `1007` |
| A handler error | — | close `1011` |
| Server stopping | — | close `1001` |

Pings are answered with pongs; a client close is echoed. `permessage-deflate`
is off: it costs latency and CPU, and compressing secrets beside
attacker-influenced text is a known oracle.

Messages are JSON-RPC 2.0 — [the `sugarcrush.v1` protocol](#the-sugarcrushv1-protocol)
below.

## The `sugarcrush.v1` protocol

Every message is a JSON-RPC 2.0 object. A client sends **requests** (with an
`id`, answered) and **notifications** (without one, never answered); the
server answers and sends **events** — notifications whose method is `event`.
The server never sends a request of its own: a permission question is an
event, and the answer is a request any client may make.

```json
{"jsonrpc":"2.0","id":"c1-42","method":"session.send","params":{"sessionId":"a1b2…","text":"run the tests"}}
{"jsonrpc":"2.0","id":"c1-42","result":{"admitted":"started","turnId":"t_9f…","messageId":"…"}}
{"jsonrpc":"2.0","method":"event","params":{"sessionId":"a1b2…","seq":812,"type":"tool.started","ts":1790000000123,"turnId":"t_9f…","durable":true,"data":{…}}}
```

### Handshake

The first request on a socket must be `server.hello`:

```json
{"jsonrpc":"2.0","id":"h","method":"server.hello","params":{
  "minProtocol":1,"maxProtocol":1,
  "client":{"name":"my-tool","version":"0.1.0"},
  "caps":[],
  "resume":{"<sessionId>":<lastSeq>}}}
```

Its answer names the protocol (`1`), the server (`version`, `connectionId`,
`root`, `pid`), the `features` — every method and event type this server
supports, generated from the code that serves them — the `limits` below, the
`principal` and its `scopes`, the `defaults` (the permission mode new sessions
start in), and, for each session in `resume`, how that subscription was
resumed. Anything other than `server.hello` before it is answered `-32002`
(`not_initialized`) and the socket is closed with `4002`; a message over
64 KiB before it closes the socket with `1009`. A client must ignore event
types and fields it does not know: within protocol `1` changes are additive
only.

| Limit (`limits` key) | Value |
|---|---|
| `maxClientFrameBytes` | 1 MiB |
| `maxServerFrameBytes` | 4 MiB — a larger answer is refused `too_large`; a tool output past 256 KiB arrives truncated, and `tool.output` reads the rest |
| `maxInflight` | 64 |
| `tickIntervalMs` | 15000 — a `server.tick` event this often |
| `softBufferedBytes` / `maxBufferedBytes` | 1 MiB / 16 MiB (see [Backpressure](#backpressure)) |
| `maxSubscribersPerSession` | 50 |

A client may send 50 requests a second, with bursts of 200; past that it is
answered `-32011` (`rate_limited`, `retryAfterMs`).

### Errors

An error's `data.kind` is the machine-readable reason; branch on it, never on
the message. Nothing a client sent is echoed back except the request `id`,
which is returned exactly as sent (string or integer).

| Code | Meaning |
|---|---|
| `-32700` | parse error |
| `-32600` | invalid request (also `unsupported_protocol`) |
| `-32601` | method not found |
| `-32602` | invalid params |
| `-32002` | not initialized — `server.hello` first |
| `-32003` | forbidden (scope, or a write the server does not allow remotely) |
| `-32004` | not found (`session_not_found`, `ask_not_found`, …) |
| `-32009` | conflict (`already_resolved`, `session_locked`, `refused`, …) |
| `-32010` | busy, retryable (`too_many_turns`, `turn_running`, `draining`, …) |
| `-32011` | rate limited, retryable |
| `-32020` | permission mode refused |
| `-32030` | unsupported in server (`ui_only`, `todo_unavailable`, …) |
| `-32099` | internal — the cause is in the server's log, never in the answer |

Every method that changes something takes an optional `idempotencyKey` (up to
64 characters): a retry with the same key gets the original answer instead of
doing the thing twice — a prompt resent after a dropped connection is not a
second turn. Answers are kept five minutes, at most 1,000.

### Methods

Scope `read` is enough for everything that only looks; the owner holds all four
scopes (`read`, `write`, `approve`, `admin`). "Idempotent" marks the methods
that accept an `idempotencyKey`.

| Method | Scope | Idempotent | What it does |
|---|---|---|---|
| `agents.list` | read |  | The agents a turn can delegate to. |
| `agents.subtree` | read |  | The sub-agents a session's turns have delegated to, with their latest activity. |
| `bg.list` | read |  | The background sessions this server supervises. |
| `bg.output` | read |  | A background session's output from an offset. |
| `bg.stop` | write | yes | Stop a background session. |
| `client.viewing` | read |  | Say which sessions this client shows, and which is in front. |
| `command.exec` | write | yes | Run a slash command in a session (command files; built-ins once they run headless). |
| `command.list` | read |  | The slash commands a session knows, and where each runs. |
| `files.changed` | read |  | The workspace's changed and untracked files. |
| `files.diff` | read |  | The workspace's uncommitted changes to tracked files, as a unified diff. |
| `files.read` | read |  | A file under the project root, read-only and size-capped. |
| `memory.add` | write | yes | Add a note. |
| `memory.delete` | write | yes | Delete a note. |
| `memory.edit` | write | yes | Replace a note's text. |
| `memory.list` | read |  | The notes of one memory scope. |
| `memory.search` | read |  | Notes matching a query, best first, across every scope. |
| `permission.pending` | read |  | The questions still open, for one session or all open sessions. |
| `permission.respond` | approve | yes | Answer an open permission question; the first answer wins. |
| `permission.rules` | read |  | The effective permission mode and rules, read-only. |
| `server.health` | read |  | Liveness and load. |
| `server.hello` | read |  | The handshake: protocol version, features, limits; optionally resume subscriptions. |
| `server.info` | read |  | What this server offers: providers, agents, commands, tools, permission modes. |
| `server.shutdown` | admin | yes | Stop the server. |
| `session.cancel` | write | yes | Cancel the running turn (hard, or soft at the next step boundary). |
| `session.close` | write | yes | Release a session (refused while a turn runs unless force). |
| `session.create` | write | yes | Create a session and open it. |
| `session.delete` | write | yes | Delete a session and its history. |
| `session.dequeue` | write | yes | Remove a queued prompt. |
| `session.export` | read |  | A session's transcript as markdown, json or text. |
| `session.fork` | write | yes | Branch a session into a new one carrying its transcript. |
| `session.get` | read |  | A session's snapshot: rows, status, queue, open questions, usage. |
| `session.list` | read |  | The workspace's sessions, newest activity first, paged. |
| `session.queue` | read |  | The prompts queued behind the running turn. |
| `session.rename` | write | yes | Rename a session. |
| `session.send` | write | yes | Send a prompt: start a turn, or queue / steer / interrupt the running one. |
| `session.setMode` | write | yes | The permission mode the session's next turns run in. |
| `session.subscribe` | read |  | Follow a session's events from a cursor (replay) or from a snapshot. |
| `session.unsubscribe` | read |  | Stop following a session. |
| `settings.get` | read |  | Effective values and where each came from, or one tier's file; secrets masked. |
| `settings.schema` | read |  | Every setting: type, default, help, and whether a client may write it. |
| `settings.set` | admin | yes | Write an allowlisted setting to the user tier or a trusted project. |
| `todo.get` | read |  | A session's todo list (reserved; answers todo_unavailable until sessions keep one). |
| `tool.output` | read |  | A finished tool call's full output, from an offset. |

A session is opened on first use and holds the same lock a terminal session
takes, so a session open in a `sugarcrush` TUI is refused `session_locked`
rather than written by two processes. `server.maxOpenSessions` (32) bounds the
sessions open at once — the least recently used idle one is released past it,
never one with a turn running.

### Events

**Durable** events of a session are written to its event log before they are
sent, carry a `seq` that is gap-free and increasing per session, and are what a
reconnecting client replays. **Live** events (deltas, ticks) carry no `seq`, and
a client never advances its cursor on one: every live stream is bracketed by
durable events, so a dropped delta is repaired by the durable
`assistant.completed`. A delta's `data.offset` is its byte offset into the part
(`data.partId`), so a gap is visible. Server-scope events carry
`sessionId: null` and no `seq`: there is no server log, so a client that missed
one re-reads what it describes (`session.list`).

| Type | Scope | Kind | What it says |
|---|---|---|---|
| `assistant.completed` | session | durable | The reply, complete; repairs any delta a client dropped. |
| `assistant.delta` | session | live | Streamed reply text. |
| `assistant.narration` | session | live | The tail of the reply so far, at most every 2 s, for narration subscriptions. |
| `compaction.completed` | session | durable | The history was compacted before a turn. |
| `message.created` | session | durable | A transcript row was added: the prompt, a notice, a summary. |
| `permission.requested` | session | durable | A tool call is waiting for an answer (permission.respond). |
| `permission.resolved` | session | durable | A question was answered or cancelled. |
| `reasoning.delta` | session | live | Streamed reasoning text. |
| `server.overflow` | server | live | This client fell behind; ephemeral events were dropped — resubscribe. |
| `server.shutdown` | server | live | The server is stopping. |
| `server.tick` | server | live | Liveness, every tickIntervalMs. |
| `session.created` | server | live | A session was created. |
| `session.deleted` | server | live | A session was deleted. |
| `session.status` | session | durable | The session became idle, busy or waiting_permission. |
| `session.updated` | server | live | A session was renamed or its mode changed. |
| `spend_cap.breached` | session | durable | The session spend cap stopped the turn. |
| `subagent.finished` | session | durable | A delegated sub-agent finished. |
| `subagent.progress` | session | live | A delegated sub-agent made progress. |
| `subagent.started` | session | durable | A delegated sub-agent started. |
| `tool.finished` | session | durable | A tool call finished (content capped; tool.output has the rest). |
| `tool.started` | session | durable | A tool call started. |
| `turn.completed` | session | durable | A turn ended, with its stopReason. |
| `turn.dequeued` | session | durable | A queued prompt left the queue (sent or removed). |
| `turn.queued` | session | durable | A prompt was queued behind the running turn. |
| `turn.started` | session | durable | A turn began. |
| `turn.steered` | session | durable | A steering message was handed to the running turn. |
| `turn.step` | session | live | The running turn reached a step boundary. |
| `usage.updated` | session | durable | Token and cost usage changed. |

### Sending, queueing, steering, cancelling

`session.send` answers how the prompt was **admitted**; the prompt's own row
arrives as the durable `message.created`. While a turn runs, `delivery`
decides what a new prompt does:

- `queue` (the default) waits behind the turn — `admitted: "queued"` with a
  `queueId`, and a `turn.queued` event; `session.queue` lists the queue and
  `session.dequeue` takes an entry out (`turn.dequeued`);
- `steer` hands the text to the running turn at its next step —
  `admitted: "steered"` with a `steerId`, and a `turn.steered` event. It is
  also held in the queue, so it is never lost: if the turn ends before reading
  it, it goes out as the next turn; if the turn read it, it leaves the queue;
- `interrupt` cancels the running turn and sends the prompt now.

Idle, a prompt starts a turn (`admitted: "started"`, with its `turnId`), or is
parked behind a hook or command file that runs in a child
(`admitted: "pending"`). A prompt the session refuses — the spend cap, a
built-in slash command, an empty command expansion — is `-32009` `refused`
with the reason. `server.maxConcurrentTurns` (4) caps the turns running at
once across sessions: one more is refused `busy` (`too_many_turns`), which is
retryable.

`session.cancel` stops the running turn: `mode: "hard"` (the default) at once,
the way Esc Esc does; `mode: "soft"` at the next step boundary, letting the
tool in flight finish. `clearQueue: true` also drops the queue.
`session.status` follows the session: `idle`, `busy`, `waiting_permission`.

Built-in slash commands still run in the terminal UI only: `command.list` lists
them with `runsIn: "client"`, and `command.exec` refuses them `-32030`
(`ui_only`). A project's or your own command files (`.sugar-crush/commands/`)
run on the server exactly as typing `/name args` would.

### Permissions over the wire

A question a turn's gate or hook asks is a durable `permission.requested`
event: every client following the session sees it. Any client holding the
`approve` scope may answer with `permission.respond {sessionId, askId, reply:
"once"|"always"|"reject", note?, cascade?, remember?}`. **The first valid
answer wins**; a later one is refused `-32009` `already_resolved`, with the
winning answer in `data.resolved`. The answer reaches exactly the call that was
asked about — the `askId` is a hash of the call's id, tool and arguments.

- `always` is remembered for the session, for every later turn, and also
  answers the session's other open questions about the same tool (listed in
  the answer's `cascaded`); a question only a hook asked is put every time.
- `reject` with `cascade: true` rejects every other open question of the
  session and stops the turn at its next step.
- `remember: "project"` is refused — permission rules are user-tier only —
  and `remember: "user"` is not offered over the wire; add a rule to
  `~/.sugar-crush/settings.json` instead.
- A question still open when its turn ends — cancelled, failed, or settled —
  is resolved `cancelled`.
- `server.askTimeoutSeconds` refuses a question nobody answered in time. It is
  unset by default: a question waits for as long as the turn does.

A client that reconnects is handed every question still open
(`pendingAsks` in its subscribe or resume answer), whatever its cursor, and
`permission.pending` lists them for one session or all. See
[`PERMISSIONS.md`](PERMISSIONS.md#ask-needs-somewhere-to-ask).

Sessions start in the server's permission mode (`default` unless the server was
started with another); `session.create` and `session.setMode` may choose any
mode except `bypass-permissions` and `dont-ask`, which are refused `-32020`
unless the server runs with `--allow-bypass`.

### Following a session: subscribe, replay, resync

`session.subscribe {sessionId, afterSeq?, mode?}` follows a session:

- with `afterSeq` the log can still serve, the answer is
  `{fromSeq, throughSeq, pendingAsks}`, then every durable event after
  `afterSeq` is sent in order (200 per loop turn), then the live stream;
- with no `afterSeq`, or one the log can no longer serve (older than the
  oldest event kept — 20,000 per session — or ahead of the log), the answer is
  `{reset: true, snapshot, throughSeq, pendingAsks}`: the transcript rows,
  status, permission mode, queue, open questions and sub-agents, and the live
  stream follows from `throughSeq`.

The subscription is registered **before** the catch-up is read, so an event
logged meanwhile is delivered once, after the catch-up, never twice and never
missed. A client's cursor is the highest gap-free `seq` it holds; reconnecting,
it passes `resume: {sessionId: cursor}` to `server.hello` (or subscribes again)
and gets exactly what it missed. Across a server restart the `seq`s continue —
the log is in the session database — and the new `connectionId` in `hello`
tells the client to resubscribe. `mode: "narration"` sends no deltas, only an
`assistant.narration` tail at most every 2 s plus every durable event — for
tiles and background panes.

### Backpressure

Each connection has an outbox; a turn never waits on a client. The socket is
handed only what it will take, and the rest waits in the outbox:

- past **1 MiB** waiting, queued deltas of one part are merged (lossless), and
  the client's subscriptions to every session but the one `client.viewing`
  names as in front drop to narration;
- past **16 MiB**, every waiting live event is dropped for one
  `server.overflow` event (`{dropped, action: "resubscribe"}`). Durable events
  are never dropped: if the outbox is still past 16 MiB ten seconds later, the
  socket is closed with **`1013`** and the client resubscribes from its
  cursor.

### Settings over the wire

`settings.schema` describes every setting from the same schema the TUI editor
and [`SETTINGS.md`](SETTINGS.md) are built from, with `sensitive` and
`writableRemotely` flags. `settings.get` answers the effective values and where
each came from (or one tier's file with `scope: "user"|"project"`); a secret
travels as `"********"`. `settings.set` (scope `admin`) writes only keys whose
risk class is cosmetic, tuning or narrowing — never anything that runs a
command, pulls files into prompts, spends money, grants trust, changes
permissions or the server's own binding, holds a secret, or is owned by a live
command (`theme` is `/theme`'s, `provider` is `/model`'s) — and only to your
config or a trusted project's local file, through the same writer and refusals
as the TUI editor.

## Security model

- **Loopback by default.** `127.0.0.1` only; any other bind needs
  `--allow-remote` and gets a warning box.
- **`Host` check (DNS rebinding).** Only `127.0.0.1`, `localhost`, `[::1]` (any
  port), the bind address itself when it is not loopback, and
  `server.allowedHosts` are answered; anything else is `421`.
- **`Origin` check (cross-site WebSocket hijacking, CSRF).** On every upgrade
  and every non-`GET` request a present `Origin` must be the server's own
  (`http://` or `https://` plus the validated `Host`) or in
  `server.allowedOrigins`; anything else — `null` included — is `403`. A
  **missing** `Origin` is allowed only with the bearer token or the token
  subprotocol: the cookie and a ticket, the two credentials a browser carries
  by itself, are refused without one. A cookie is also refused on
  `Sec-Fetch-Site: cross-site`.
- **No CORS.** The UI is same-origin; every response carries
  `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`,
  `Referrer-Policy: no-referrer` and a `default-src 'self'` content security
  policy.
- **Logs** record the method, path and status of each request and WebSocket
  open/close — never a query string (a ticket rides there), a header, a body or
  a prompt.
- **Permission modes.** Server sessions start in `default` (they ask), as the
  TUI does — not in the `bypass-permissions` that `-p` and background sessions
  keep, because a server has a client to answer an ask. `bypass-permissions`
  and `dont-ask` are refused — at startup and over the wire (`-32020`) —
  unless `--allow-bypass` / `server.allowBypass`.
- **Process hardening.** Refuses root without `--allow-root`; runs under
  `umask 077`; the request body is capped at 64 KiB and 32 requests may be in
  flight at once; JSON is decoded with a depth limit of 64.

### Why pcntl, posix and FFI

Every turn runs in a `pcntl_fork()` child. Without pcntl the engine falls back
to running the turn on the loop itself, which on a server would stall every
session and every socket for the length of the turn — so `serve` refuses
instead.

A forked child inherits **every** descriptor the server holds: the listening
socket and every browser connection. Held there, the listener keeps the port
bound after the server closes it (a restart fails with "Address already in
use") and keeps accepting connections into a backlog nobody reads, so a client
of a stopped server hangs instead of being refused. So the server **registers**
its listener and each accepted socket, and the first thing a turn child does is
close exactly those with libc `close(2)` through FFI — PHP has no
close-by-number. It closes nothing else: the child legitimately uses inherited
pipes (stdio MCP servers) and provider sockets. The listener and every accepted
socket are also marked close-on-exec, so no tool process ever inherits them.
Without FFI there is no way to do this, and the leak would be silent — hence the
refusal.

## Behind a reverse proxy (TLS)

There is no built-in TLS in v1. To reach the server from another machine, keep
it on loopback and put a TLS proxy in front of it — Caddy, nginx or
`tailscale serve`. With nginx:

```nginx
location / {
    proxy_pass http://127.0.0.1:7420;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
    # A turn can legitimately run for many minutes: never cut a quiet socket.
    proxy_read_timeout 3600s;
}
```

and in `~/.sugar-crush/config.json`:

```json
{
    "server.allowedHosts": ["agent.example.com"],
    "server.trustedProxies": ["127.0.0.1"]
}
```

`server.trustedProxies` is what lets the server believe the proxy's
`X-Forwarded-Proto: https` (so the cookie gets `Secure`) and its
`X-Forwarded-For` (so the rate limiter keys on the real client). The browser's
origin is then `https://agent.example.com`, which matches the server's own
origin through the forwarded `Host` and needs no `--allowed-origin`.

## Windows

`serve` needs pcntl and posix, which Windows PHP does not have; it refuses to
start there. WSL works.
