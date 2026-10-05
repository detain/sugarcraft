# Server mode (`sugarcrush serve`)

`sugarcrush serve` runs an HTTP + WebSocket server that a browser (the
[`sugar-crush-web`](https://github.com/sugarcraft/sugar-crush-web) UI), a
terminal ([`sugarcrush attach`](#attaching-a-terminal-sugarcrush-attach)) or a
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
sugarcrush serve stop [--force]  # SIGTERM, time to drain, then SIGKILL (--force: SIGKILL now)
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
| `control.sock` | the local socket `serve url` and `serve token --rotate` reach the running server on; every request carries the owner token |
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

**Stopping.** `SIGTERM` or `SIGINT` (what `serve stop` and `Ctrl+C` send) —
or a client's `server.shutdown` — drains the server. First it admits no new
turn or session (`busy` / `draining`), tells every client how long it will
wait (`server.shutdown` with `graceSeconds`), and waits for the turns already
running to settle, up to `server.drainSeconds` (10 s; `0` skips the wait); a
second signal stops it at once. Then it closes every WebSocket with `1001`,
cancels any turn still running and releases every session, removes
`server.json` and `control.sock` and releases the lock. `serve stop` waits 30
s for that — or the configured drain plus 5 s, when that is longer — then
sends `SIGKILL`; `--force` skips straight to `SIGKILL`. `SIGCHLD` is never handled — the turn children are reaped by the
engine's own sweep.

| Verb | Exit `0` | Exit `1` |
|---|---|---|
| `serve status` | a server runs (`health` is `ok`, or `unreachable` when it does not answer `/api/health`) | none runs (a stale record is reported as such) |
| `serve stop` | it stopped | none was running, or it did not exit |
| `serve logs` | the log was printed | there is no `server.log` (only a detached server writes one) |
| `serve url` | a fresh sign-in URL was printed | no server runs, or it refused the token |
| `serve token` | the token was printed (`--rotate`: replaced first, and a running server reloads it) | — (refused at `2` while `SUGARCRUSH_SERVER_TOKEN` is set) |

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
--rotate` replaces it. A running server is told at once over its control
socket: it re-reads the file and **signs every client out** — every browser
cookie, unspent ticket and login code is revoked and every open WebSocket is
closed with `4001` — so the old token stops working now, not at the next
restart (`--output-format json` reports `serverReloaded`). A server that
cannot be reached keeps the old token until it restarts, and `serve token`
says so.

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
start in, and the `delivery` a client's composer should pick for a prompt sent
while a turn runs — the `queueMode` setting, `steer` unless set, which is what
the TUI's Enter does mid-turn; a `session.send` that names no `delivery`
still queues), and, for each session in `resume`, how that subscription was
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
| `-32001` | unauthorized — reserved: a socket authenticates at the upgrade, so no request meets it today |
| `-32002` | not initialized — `server.hello` first |
| `-32003` | forbidden (scope, or a write the server does not allow remotely) |
| `-32004` | not found (`session_not_found`, `ask_not_found`, …) |
| `-32009` | conflict (`already_resolved`, `session_locked`, `refused`, `command_refused`, …) |
| `-32010` | busy, retryable (`too_many_turns`, `turn_running`, `draining`, …) |
| `-32011` | rate limited, retryable |
| `-32020` | permission mode refused |
| `-32030` | unsupported in server (`ui_only`, `remember_user_unavailable`, …) |
| `-32099` | internal — the cause is in the server's log, never in the answer |

Every method that changes something takes an optional `idempotencyKey` (up to
64 characters): a retry with the same key gets the original answer instead of
doing the thing twice — a prompt resent after a dropped connection is not a
second turn. Answers are kept five minutes, at most 1,000.

### Methods

Scope `read` is enough for everything that only looks; the owner holds all four
scopes (`read`, `write`, `approve`, `admin`). "Idempotent" marks the methods
that accept an `idempotencyKey`. Every request's params are checked against
the method's schema before it runs — a field of the wrong type, or a missing
required one, is `-32602` naming the field. The full schema of every params,
result and event `data` is [`protocol/sugarcrush.v1.schema.json`](protocol/sugarcrush.v1.schema.json);
it and the two tables below are generated from the code
(`php scripts/gen-protocol-schema.php --write`), never edited by hand.

<!-- protocol:methods:begin (generated by scripts/gen-protocol-schema.php — do not edit) -->
| Method | Scope | Idempotent | Required params | What it does |
|---|---|---|---|---|
| `agents.control` | write | yes | `sessionId`, `agentId`, `verb` | Cancel, pause, resume or background a delegated run. |
| `agents.list` | read |  | — | The agents a turn can delegate to. |
| `agents.message` | write | yes | `sessionId`, `agentId`, `text` | Message a delegated run: into its mailbox while it runs, as a follow-up once it finished. |
| `agents.subtree` | read |  | `sessionId` | The sub-agents a session's turns have delegated to, with their latest activity. |
| `agents.transcript` | read |  | `sessionId`, `agentId` | A delegated run's own transcript, read from a byte offset. |
| `bg.inject` | write | yes | `bgId`, `sessionId` | Send a settled background session's result into a session as a prompt. |
| `bg.list` | read |  | — | The background sessions this server supervises, running and settled. |
| `bg.output` | read |  | `bgId` | A background session's output from an offset. |
| `bg.spawn` | write | yes | `task` | Start a background session on a task, as /bg does. |
| `bg.stop` | write | yes | `bgId` | Stop a background session. |
| `client.viewing` | read |  | — | Say which sessions this client shows, and which is in front. |
| `command.exec` | write | yes | `sessionId`, `name` | Run a slash command in a session (command files, and the built-ins that run headless). |
| `command.list` | read |  | — | The slash commands a session knows, and where each runs. |
| `files.changed` | read |  | — | The workspace's changed and untracked files. |
| `files.diff` | read |  | — | The workspace's uncommitted changes to tracked files, as a unified diff. |
| `files.read` | read |  | `path` | A file under the project root, read-only and size-capped. |
| `memory.add` | write | yes | `content` | Add a note. |
| `memory.delete` | write | yes | `id` | Delete a note. |
| `memory.edit` | write | yes | `id`, `content` | Replace a note's text. |
| `memory.list` | read |  | — | The notes of one memory scope. |
| `memory.search` | read |  | `query` | Notes matching a query, best first, across every scope. |
| `permission.pending` | read |  | — | The questions still open, for one session or all open sessions. |
| `permission.respond` | approve | yes | `sessionId`, `askId`, `reply` | Answer an open permission question; the first answer wins. |
| `permission.rules` | read |  | — | The effective permission mode and rules, read-only. |
| `server.health` | read |  | — | Liveness and load. |
| `server.hello` | read |  | — | The handshake: protocol version, features, limits; optionally resume subscriptions. |
| `server.info` | read |  | — | What this server offers: providers, agents, commands, tools, permission modes. |
| `server.shutdown` | admin | yes | — | Stop the server, draining running turns first. |
| `session.cancel` | write | yes | `sessionId` | Cancel the running turn (hard, or soft at the next step boundary). |
| `session.close` | write | yes | `sessionId` | Release a session (refused while a turn runs unless force). |
| `session.create` | write | yes | — | Create a session and open it. |
| `session.delete` | write | yes | `sessionId` | Delete a session and its history. |
| `session.dequeue` | write | yes | `sessionId`, `queueId` | Remove a queued prompt. |
| `session.export` | read |  | `sessionId` | A session's transcript as markdown, json or text. |
| `session.fork` | write | yes | `sessionId` | Branch a session into a new one carrying its transcript. |
| `session.get` | read |  | `sessionId` | A session's snapshot: rows, status, queue, open questions, usage. |
| `session.list` | read |  | — | The workspace's sessions, newest activity first, paged. |
| `session.queue` | read |  | `sessionId` | The prompts queued behind the running turn. |
| `session.rename` | write | yes | `sessionId`, `name` | Rename a session. |
| `session.send` | write | yes | `sessionId`, `text` | Send a prompt: start a turn, or queue / steer / interrupt the running one. |
| `session.setMode` | write | yes | `sessionId`, `permissionMode` | The permission mode the session's next turns run in. |
| `session.subscribe` | read |  | `sessionId` | Follow a session's events from a cursor (replay) or from a snapshot. |
| `session.unsubscribe` | read |  | `sessionId` | Stop following a session. |
| `settings.get` | read |  | — | Effective values and where each came from, or one tier's file; secrets masked. |
| `settings.preview` | admin |  | — | What a save would write — the target file's diff, when each change applies, and what blocks it — without writing. |
| `settings.schema` | read |  | — | Every setting: type, default, help, apply mode, and whether a client may write it; and the tiers a save can target. |
| `settings.set` | admin | yes | — | Write allowlisted settings to the user tier or a trusted project's local or shared file, in one write. |
| `todo.get` | read |  | `sessionId` | A session's todo list, as its Todo tool last wrote it. |
| `tool.output` | read |  | `sessionId`, `toolCallId` | A finished tool call's full output, from an offset. |
| `workflow.list` | read |  | — | The workflows /workflow run can start. |
| `workflow.pause` | write | yes | `workflowId` | Pause a workflow run before its next stage. |
| `workflow.resume` | write | yes | `sessionId`, `workflowId` | Resume a paused workflow run in a session. |
| `workflow.run` | write | yes | `sessionId`, `name` | Run a workflow in a session, as /workflow run does: it occupies the session's turn. |
| `workflow.runs` | read |  | `sessionId` | The workflow runs a session's transcript holds: /workflow reports and Workflow tool calls. |
| `workflow.status` | read |  | `workflowId` | A workflow run's status. |
| `workspace.close` | write | yes | `root` | Stop a workspace host; refused while one of its turns runs, unless force. |
| `workspace.list` | read |  | — | The project roots this server serves: its own, and each running workspace host. |
| `workspace.open` | write | yes | `root` | Start the workspace host for another project root, or find it running. |
<!-- protocol:methods:end -->

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
one re-reads what it describes (`session.list`, `permission.pending`).

<!-- protocol:events:begin (generated by scripts/gen-protocol-schema.php — do not edit) -->
| Type | Scope | Kind | What it says |
|---|---|---|---|
| `assistant.completed` | session | durable | The reply, complete; repairs any delta a client dropped. |
| `assistant.delta` | session | live | Streamed reply text. |
| `assistant.narration` | session | live | The tail of the reply so far, at most every 2 s, for narration subscriptions. |
| `bg.completed` | server | live | A background session settled; bg.output reads its answer, bg.inject sends it to a session. |
| `bg.started` | server | live | A background session started, or one an earlier server or TUI left running was re-adopted. |
| `bg.status` | server | live | A background session's status changed (running, stalled, …). |
| `compaction.completed` | session | durable | The history was compacted before a turn. |
| `message.created` | session | durable | A transcript row was added: the prompt, a notice, a summary. |
| `permission.asked` | server | live | A question was put in an open session: permission.requested with its sessionId, for every client, following the session or not. |
| `permission.requested` | session | durable | A tool call is waiting for an answer (permission.respond). |
| `permission.resolved` | session | durable | A question was answered or cancelled. |
| `permission.settled` | server | live | A question of an open session was answered or cancelled (permission.resolved, for every client). |
| `reasoning.delta` | session | live | Streamed reasoning text. |
| `server.overflow` | server | live | This client fell behind; ephemeral events were dropped — resubscribe. |
| `server.shutdown` | server | live | The server is stopping. |
| `server.tick` | server | live | Liveness, every tickIntervalMs. |
| `session.created` | server | live | A session was created. |
| `session.deleted` | server | live | A session was deleted. |
| `session.status` | session | durable | The session became idle, busy or waiting_permission. |
| `session.updated` | server | live | A session was renamed, its mode changed, or its status changed (idle, busy, waiting_permission). |
| `spend_cap.breached` | session | durable | The session spend cap stopped the turn. |
| `subagent.finished` | session | durable | A delegated sub-agent finished. |
| `subagent.progress` | session | live | A delegated sub-agent made progress. |
| `subagent.started` | session | durable | A delegated sub-agent started. |
| `todo.updated` | session | durable | A Todo call rewrote the session's todo list; carries the whole list. |
| `tool.finished` | session | durable | A tool call finished (content capped; tool.output has the rest). |
| `tool.started` | session | durable | A tool call started. |
| `turn.completed` | session | durable | A turn ended, with its stopReason. |
| `turn.dequeued` | session | durable | A queued prompt left the queue (sent or removed). |
| `turn.queued` | session | durable | A prompt was queued behind the running turn. |
| `turn.started` | session | durable | A turn began. |
| `turn.steered` | session | durable | A steering message was handed to the running turn. |
| `turn.step` | session | live | The running turn reached a step boundary. |
| `usage.updated` | session | durable | Token and cost usage changed. |
<!-- protocol:events:end -->

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

A built-in slash command whose body lives on the host (`Host\Commands` —
`/clear`, `/rewind`, `/memory`, `/permissions`, `/workflow`, …) runs on the
server: `command.list` lists it with `runsIn: "server"`, and `command.exec`
answers the `rows` it appended to the transcript and the `effects` it applied.
It is refused `-32009` (`command_refused`, with the reason) while a turn holds
the session, `/workflow pause|status` inside its own run excepted. The
screen-only ones (`/theme`, `/pane`, the pickers) and those whose logic is
still the TUI's are listed `runsIn: "client"` and refused `-32030`
(`ui_only`). A project's or your own command files (`.sugar-crush/commands/`)
run on the server exactly as typing `/name args` would.

`todo.get` answers the session's todo list as its `Todo` tool last wrote it:
`{items: [{content, status}]}`, empty when the agent has kept none.

### Permissions over the wire

A question a turn's gate or hook asks is a durable `permission.requested`
event: every client following the session sees it. Any client holding the
`approve` scope may answer with `permission.respond {sessionId, askId, reply:
"once"|"always"|"reject", note?, cascade?, remember?}`. **The first valid
answer wins**; a later one is refused `-32009` `already_resolved`, with the
winning answer in `data.resolved`. The answer reaches exactly the call that was
asked about — the `askId` is a hash of the call's id, tool and arguments.

- `always` is remembered for the session, for every later turn, and also
  answers the session's other open questions that what it remembered covers
  (listed in the answer's `cascaded`) — the same scope as the TUI's `a` + `y`
  ([`PERMISSIONS.md`](PERMISSIONS.md)): `always` on `git status` answers an
  open `git status --short`, never an open `git push`. A question that grant
  covers arriving later in the same turn is answered without being put. A
  question only a hook asked is put every time.
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
`permission.pending` lists them for one session or all.

Every client also hears a question put in **any** open session, following it
or not: the server-scope `permission.asked` carries the question and its
`sessionId`, and `permission.settled` (`{sessionId, askId, reply?,
cancelled?}`) its answer or cancellation — so a dashboard watching one session
learns of a question in another the moment it is put. Each status change
(`idle`, `busy`, `waiting_permission`) likewise reaches every client as a
`session.updated` carrying the session's summary. See
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

A client showing several sessions at once need not choose the mode per
subscription: `client.viewing {sessionIds, foreground, narrate: true}` narrates
every session it follows except the one in front (the answer lists them as
`narrated`), and moving `foreground` switches them without resubscribing. A
tail names the byte `offset` into the reply where it starts and holds only
what streamed since the last tool call; a session brought to the front is
handed its tail at once, and the deltas that follow continue at
`offset` + the tail's length — no gap, no repeat.

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
and [`SETTINGS.md`](SETTINGS.md) are built from — type, default, choices,
help, when a change applies, the environment variable or flag that can lock
it, `sensitive`, and `writableRemotely` with the reason when it is not — plus
the tiers a save can target (your config, and a trusted project's
`settings.local.json` and committed `settings.json`) and whether each can be
written now. `settings.get`
answers every key's effective value with its provenance — which layer won, from
which file, which layers it shadows, and the environment or flag lock — and the
files behind those layers (or one tier's file with
`scope: "user"|"project"|"project-shared"`).
A secret never travels: a secret-typed key, any key at any depth whose name
says it is a credential (`apiKey`, `token`, `Authorization`…), and the userinfo
of a URL read as `"********"`.

`settings.set` (scope `admin`) writes only keys whose risk class is cosmetic,
tuning or narrowing — never anything that runs a command, pulls files into
prompts, spends money, grants trust, changes permissions or the server's own
binding, holds a secret, or is owned by a live command (`theme` is `/theme`'s,
`provider` is `/model`'s) — and only to your config (`scope: "user"`) or a
trusted project's local file (`"project"`) or committed shared file
(`"project-shared"`, `.sugar-crush/settings.json`, whose preview notes that
everyone who clones the repository and trusts it gets the values), through the
same writer and refusals as the TUI editor. It takes
one key (`key` + `value`, or `key` + `reset`) or a whole change set (`set` and
`unset`), and writes all of it or none of it; a reset deletes the key rather
than writing its default. `settings.preview` (also `admin`) answers what that
save would do without writing: the target file, a unified diff of its JSON,
when each change applies, what refuses it, and the precedence notes — that the
value now overrides your `settings.json`, or that a higher layer still outranks
the file. There is no "this session only" tier over the wire: in a server it
would be state every hosted session shares.

The web UI's **Settings** page is generated from `settings.schema`: one form,
grouped as the TUI editor groups it, each field badged with when it applies and
showing where its value comes from. A field a client may not write, a field the
environment or a flag locks, and — on either project tier — a key a project
may not set are shown disabled with the reason. Changes are staged, previewed as a
diff, and saved in one write; a reset is staged the same way.

### Background sessions

The workspace's background (`/bg`) sessions are reachable over the wire too.
`bg.list` lists them — running ones and the ones that have settled, so their
answers stay reachable — and `bg.output` reads one's output from an offset.
`bg.spawn` starts one on a task the way `/bg` does (scope `write`: it runs
code, like `session.send`), as the agent you name or the one `/bg` would pick,
in the served root; it answers once the daemon has authenticated its
handshake. `bg.stop` stops one. `bg.inject` sends a settled session's report —
its task, outcome, answer and stats, the same report the TUI hands its agent —
into a session as a prompt, admitted like `session.send` (`delivery` included);
the report's `@path` tokens stay text, because they are the background model's
words, not a request to attach a file. A session that has not settled is
refused `bg_not_settled`.

**A starting server re-adopts what an earlier one left.** A background daemon
outlives whatever started it, so on start the server adopts the daemons an
earlier server — or a TUI of the same project — left running for its root,
exactly as a relaunched TUI does; one whose owner is still running stays that
owner's. From then on it polls them every 2 s and broadcasts what moved, as
server-scope events: `bg.started` when one starts or is re-adopted
(`adopted: true`), `bg.status` when one's status changes, and `bg.completed`
when one settles, after which `bg.output` reads its answer and `bg.inject`
delivers it. A daemon that finished while no server was running is reported on
the first poll. A client that connects later reads `bg.list`.

### Sub-agents, workflows, memory and todos

**Delegated runs.** Every run a `Task` call starts announces itself on the
session's `subagent.*` events — `started` and `finished` durable, `progress`
live — each carrying the run's latest beat: its id, agent name, the parent
`Task` call (`parentCallId`) and, when nested, the delegating run
(`parentAgentId`), its step, tokens, cost, recent tool calls and, once
finished, its `outcome`, `error` and `resumeId`. `agents.subtree` folds them
into one row per run — from the session's event log as well as what the
server heard live, so runs that finished before this server started are still
there; the task and description only `started` names survive the later beats.
`agents.transcript` reads a run's own transcript (the log the TUI's Agent View
tails) a page at a time from a byte offset, whole lines only, every string
scrubbed of terminal escapes and control bytes.

**Talking to a run.** `agents.message` puts a line from you into a running
run's mailbox, signed with the server's launch key, which the run reads at its
next step boundary — the TUI Agent View composer's route. `agents.control`
asks it to `cancel`, `pause` or `resume` the same way, or — the Agent View's
`Ctrl+X b` — to `background` itself: the run is promoted to a background
session and keeps going there, off this turn. A run that has finished
has nobody left to read a mailbox: a message (or `resume`) continues it
instead, as a follow-up of the same conversation under the same preset (it
needs the engine backend and a run that kept a `resumeId`), and its beats
arrive as the session's `subagent.*` events. A run the session never
delegated is `agent_not_found`; one finished run that cannot be continued is
`not_resumable`; `cancel`/`pause`/`background` on a finished one is
`agent_finished`, and `background` on a nested run (one another run
delegated) is `agent_nested`.

**Workflows.** `workflow.list` names the workflows `/workflow run` can start
(`available: false` when the workspace has no engine). `workflow.run`
(`{sessionId, name, vars}`) and `workflow.resume` run exactly as typing the
command does: the run occupies the session's turn, its sub-agents show live,
and its report lands as the turn's reply; a turn already running refuses it
(`command_refused`). Because `/workflow run` splits its arguments on
whitespace, each `vars` value is a single word. `workflow.pause` and
`workflow.status` answer at once, mid-run too. `workflow.runs` lists the runs
the session's transcript holds — the `/workflow` reports and the model's own
`Workflow` tool calls, the latter while they run.

**Memory and todos.** `memory.list|search|add|edit|delete` are `/memory` over
the wire, per scope (`user`, `project`, `agent`). `todo.get` answers the
session's todo list as its `Todo` tool last wrote it, and every `Todo` call
that changes the list is broadcast as the durable `todo.updated` (the whole
list), so a client follows it live and a reconnecting one replays it.

## More than one project root (workspace hosts)

A server serves the root it was started on; its sessions, settings, trust
answers, hooks, skills and MCP servers are that root's. That is not a policy
but a property of the process: `Cli\Bootstrap` keeps a launch's root in
process statics (the project the settings layers read, the frozen trust
lists, the MCP clients started once per launch). So a second root runs in a
**process of its own** — a workspace host — and the gateway
(`Server\Workspace\Gateway`) in front of the protocol routes to it:

- **Its own methods.** `workspace.list` → `{items: [{root, primary, running,
  pid}]}`; `workspace.open {root}` starts that root's host (or finds it
  running); `workspace.close {root, force?}` stops it, refused (`-32010`,
  `turn_running`) while one of its turns runs unless `force`. The server's own
  root is `primary` and closes only with the server. A `root` that is not a
  directory is `-32004` `root_not_found`; at most eight hosts run at once
  (`too_many_workspaces`).
- **Routing.** A request whose params name another `root` goes to that
  root's host — `session.create {root}`, `session.list {root}` — and so does
  any request naming a `sessionId` a host answered for. Everything else is the
  server's own. The `root` param is the gateway's; the hosts never see it.
  Answers and events relayed from a host carry `root`.
- **Following a session** works as on the server's own root: the gateway is
  the host's one client and fans its events out to the clients that
  subscribed through it, keeping a cursor per client, so a second subscriber's
  replay never reaches the first twice. The last one to leave unsubscribes
  upstream.
- **A host that dies** (crash, kill, lost socket) is announced to every client
  following one of its sessions as `server.overflow {sessionId, dropped: 0,
  action: "resubscribe"}`; resubscribing starts a fresh host, which opens the
  session from its store. A host that nobody follows and that runs no turn is
  stopped after 15 minutes idle, which bounds a long-running PHP process's
  memory.

The host is spawned the way `/bg` spawns a session daemon: an argv (`php -r`
with the entry point, nothing secret on it), in its own process group, with the
root, a per-spawn token and the server's session settings (permission mode,
`--allow-bypass`, the session and turn caps, the question timeout) on its
stdin. The gateway binds a UNIX listener in `<state dir>/workspaces` (0700)
only after the spawn, so no child inherits it; the child connects back and
must send the token first, and from then on the socket carries the same
`sugarcrush.v1` JSON-RPC, WebSocket-framed. The child stops when its stdin
closes — the gateway let go, or died — and is otherwise reaped with the usual
TERM-then-KILL ladder over its group, its turns with it. Its output goes to
`<state dir>/workspaces/workspace-<hash>.log`.

## Attaching a terminal (`sugarcrush attach`)

```sh
sugarcrush attach                      # a new session on the running server
sugarcrush attach 3fa9                 # an existing one: id, name or unique id prefix
sugarcrush attach --url http://127.0.0.1:7420 my-session
```

`attach` runs the usual full-screen TUI as one more client of a running
server, over the same `sugarcrush.v1` WebSocket the web UI uses. It finds the
server through the discovery record `serve` keeps in its state directory (the
one `serve status` reads; `--url` names another), authenticates as a bearer
client with the owner token (`SUGARCRUSH_SERVER_TOKEN`, else the state
directory's `token` file — read, never created), says `server.hello`, and
follows one session: `session.create` without an argument, else the session
whose id or name is the argument, or whose id starts with it (resolved with
`session.list` on the server). It subscribes from a snapshot, so the
transcript on screen is the server's.

What runs where:

- **Turns run on the server.** Enter is `session.send`; the reply streams back
  from the session's events (`assistant.delta`, `reasoning.delta`), tool rows
  from `tool.started` / `tool.finished`, the status bar's step and bill from
  `turn.step` / `usage.updated`. Tools run in the server's root under the
  session's permission mode, not this terminal's.
- **Its questions come up here.** A `permission.requested` for the turn opens
  the usual approval modal; the answer is `permission.respond`, and an answer
  given first by another client (a browser on the same session) takes the
  modal down as `permission.resolved` arrives.
- **Esc Esc** is a hard `session.cancel` of that turn (or takes the prompt out
  of the queue while it still waits there); a soft cancel is a soft one. A
  steer typed mid-turn is sent as the next prompt.
- **Nothing is saved locally.** The server holds the session's single-writer
  lock and writes its transcript; the attached window neither takes the lock
  nor writes the session store, and makes no local title call (the server
  titles its own sessions; a one-shot completion sent through the attachment
  is refused).
- **Quitting detaches.** A turn still running goes on, its events in the
  session's log for whoever subscribes next.

The other way round: a plain `sugarcrush --resume <id>` (or `-c`) whose session
the server holds opens it read-only, as for any other holder, and the notice
names the server and the `sugarcrush attach <id>` that would drive it instead.

`attach` exits `1` when it ran and could not attach — no server running, no
token, the upgrade refused, no such session — saying why on stderr, and `2` for
a usage error (an extra operand, a flag other than `--url`, a `--url` that is
not an `http(s)://` or `ws(s)://` address, `--output-format json`).

What an attached window does NOT yet route to the server: slash commands
(`/compact` included) run locally on the window's own copy of the transcript,
and a turn another client starts on the same session shows up only when the
window next attaches.

## Editors (`sugarcrush acp`)

`sugarcrush acp` makes sugar-crush an [Agent Client
Protocol](https://agentclientprotocol.com) agent: an editor that hosts agents
over stdio — Zed, JetBrains IDEs, Neovim through an ACP plugin — starts it as a
child process and speaks newline-delimited JSON-RPC 2.0 on its stdin and
stdout. It needs no server, port or token: the editor *is* the client. In Zed,
for example:

```json
{
  "agent_servers": {
    "SugarCrush": { "command": "sugarcrush", "args": ["acp"] }
  }
}
```

Every session runs through the same `Host\SessionHub` and `SessionHost` as
`serve`'s, on the same ReactPHP loop, so a turn the editor starts is the turn
the TUI would run: same provider, tools, hooks, permission gate, transcript
and session store (an `acp` session shows up in `session list` and can be
reopened in the TUI with `--resume`).

| ACP | sugar-crush |
|---|---|
| `initialize` | protocol version `1`; `loadSession: true`; `promptCapabilities.embeddedContext: true`; no auth methods |
| `authenticate` | accepted, nothing to do — the editor started this process as its own user |
| `session/new {cwd}` | a new session in `cwd` — the project root. The first session fixes the root for the process; a session naming another directory is refused (start another `sugarcrush acp`). `mcpServers` is not used: the project's own `.mcp.json` applies, under its trust gate |
| `session/load {sessionId, cwd}` | the stored session is opened (taking its lock, as `--resume` would) and its transcript replayed as `session/update`s — what was typed, said and thought, and each tool call with its outcome — before the answer |
| `session/prompt` | the prompt is submitted to the session; answered with `stopReason` once the session is idle again |
| `session/cancel` | the turn is cancelled at once — its child stopped, its running tool rows marked interrupted, its open permission questions settled — and the prompt answered `cancelled` |
| `session/set_mode {modeId}` | the session's permission mode for its next turns; `session/new` and `session/load` advertise the six modes (`default`, `accept-edits`, `plan`, `auto`, `dont-ask`, `bypass-permissions`) with the one in force |
| `session/update` (agent → editor) | `agent_message_chunk` ← `assistant.delta`, `agent_thought_chunk` ← `reasoning.delta`, `tool_call` ← `tool.started`, `tool_call_update` ← `tool.finished` (an edit's change as `{type: "diff", path, oldText, newText}`, one per changed region), `plan` ← `todo.updated` |
| `session/request_permission` (agent → editor) | every permission question a turn asks; see below |

**Prompts.** Text blocks are sent as written. A linked file (`resource_link`)
becomes an `@path` mention, resolved and attached as a typed one is; an
embedded resource — an editor's unsaved buffer — is sent as a `<file path>`
block carrying the editor's text. A built-in slash command runs as it does
over `serve`, its output sent back as message chunks; one only a screen can run
(`/help`, say) is answered with a `refusal` saying so.

**Stop reasons.** `end_turn`, `max_tokens` (the provider's length stop),
`max_turn_requests` (the step budget, or the spend cap), `cancelled`, and
`refusal` for a prompt the session would not take (the spend cap reached, an
empty prompt) — its reason is sent as a message chunk first. A turn that fails
answers the prompt with a JSON-RPC error carrying the failure.

**Permissions.** A question becomes a `session/request_permission` request
naming the tool call (its title, kind, file locations and arguments) with one
option per answer the question offers: `allow_once`, `allow_always` (only when
the permission gate alone asked — as in the TUI, an `always` is remembered for
the rest of the session) and `reject_once`. A `cancelled` outcome, an error
answer or an option the question did not offer all reject. Nothing blocks while
a question is open: the turn's child waits on its own channel and every other
message is still read. Sessions start in the permission mode the launch
resolves (`--permission-mode`, `SUGARCRUSH_PERMISSION_MODE`, the
`permissionMode` key), else `default`, so writes and shell commands ask.

**Ids are echoed exactly.** ACP clients send integer ids and JSON-RPC requires
a response to carry the id as sent, so every message is read with
`McpMessage::parsePreservingId()` (decision D12; MCP's own traffic is
unchanged).

**Stdout is the protocol and nothing else.** Anything PHP would print is
redirected to stderr, which an editor shows as the agent's log; launch
warnings go there too. Closing stdin — the editor quitting or dropping the
agent — closes every session (a running turn is cancelled, its lock released)
and exits `0`; so do `SIGINT` and `SIGTERM`. `acp` exits `2` for an operand or
`--output-format json`.

**Diffs.** An `Edit` or `Write` result carries a unified diff
(`ToolResult::$diff`); `ToolResult::diffTextsOf()` turns each of its hunks into
the before and after text of that region, which is what an ACP `diff` holds —
so an editor shows the change in its own diff view. A region that created the
file has a null `oldText`.

What `acp` does not use: the editor's `fs/*` and `terminal/*` methods (the
agent's own tools read and write the project, behind its own permission gate
and checkpoints), image and audio prompt blocks, and the unstable
`session/list`, `session/resume` and config-option methods.

## Web UI

The browser UI is the [`sugar-crush-web`](https://github.com/sugarcraft/sugar-crush-web)
package: a pre-built Vue bundle that `serve` hands out on the same port as the
API and the WebSocket, so there is nothing else to run. The server looks for it
in this order and names what it found on the `web UI:` startup line:

1. `--web-root <dir>` (or `SUGARCRUSH_SERVER_WEB_ROOT`);
2. the installed package (`composer require sugarcraft/sugar-crush-web`; it is
   a `suggest`, not a dependency, so headless installs stay lean);
3. neither: API and WebSocket only (also what `--no-web` asks for).

Open the sign-in URL and the page signs in, connects and shows:

- **Sessions** in a sidebar — newest activity first, a filter, a status dot,
  and a count of the questions each one is waiting on (also in the tab title,
  and as an opt-in desktop notification — for a question, or a turn that
  finished — when that session is not on screen).
- **The transcript** of the open session, virtualised so a long session
  scrolls like a short one: your prompts, the replies as Markdown streaming in
  (sanitised — neither a reply nor a file a tool read can put markup or script
  on the page; fenced code is coloured by a small highlighter fetched the first
  time one appears), the model's reasoning in a fold, and a card per tool call —
  name, key argument, duration and outcome, collapsed once it succeeded; open,
  it shows the arguments, the output (in full on request when the event was
  capped) and an edit's diff with line numbers. A refused call is marked with
  its reason.
- **Permission questions** as cards with *Allow once*, *Always (this
  session)*, *Reject* and *Reject & stop* (`y` / `a` / `n` on a focused card),
  and a note field: its text goes with a rejection as feedback the agent
  reads, and — on a question the agent asked itself (`AskUser`, `PlanExit`) —
  with *Allow once* too, as your answer in your own words. Every tab and client following the session shows the same question; the
  first answer wins and closes it everywhere.
- **The composer**: Enter sends, Shift+Enter is a new line. While a turn runs a
  prompt is queued by default — or steers the turn, or interrupts it — and the
  queue is listed above the box, each entry removable. *Stop* (or Esc Esc)
  cancels the turn. `/` completes the slash commands that run here: a built-in
  the server runs goes through `command.exec`, a command file is sent as a
  prompt, and a screen-only built-in says it runs only in the terminal UI.
- **A status bar**: what the session is doing (and the step), context used,
  spend, the model, and the permission mode — changeable from there.
- **Tabs and a grid** for watching several sessions at once. Every session
  opened stays a tab (Alt+1…9), with its status and open questions; the grid
  tiles the open tabs, up to 3×3, each live with its status, step and spend,
  the last few events, its questions and a line to prompt or steer it. The
  focused tile streams; the others are narrated (`client.viewing` with
  `narrate`) and catch up at once when focused.
- **An approvals drawer** with every question open in any session of the
  server, answerable in place. It hears a question the moment it is put
  (`permission.asked`), whether or not the page follows that session.
- **Panels** beside the session (the *panels* button, or a panel's name in
  the palette): **Agents** — the tree of the runs the session delegated, live,
  nested under the run or `Task` call that started them, each opening into an
  agent view with the run's own transcript, a box to message it and *pause*,
  *resume* and *cancel*; the same tree hangs under each `Task` card in the
  transcript. **Todo** — the session's todo list, as the agent keeps it.
  **Background** — the workspace's `/bg` sessions with their status and
  output, started, stopped or sent into the session from there. **Workflows**
  — the workflows to run (with `key=value` context) and the session's runs,
  `/workflow` and `Workflow` tool alike, pausable while they run.
  **Memory** — the notes of each scope, searchable, to add, edit or delete.
- **A command palette** (Ctrl+K / ⌘K): jump to a session, run or start a
  slash command, open a panel, start a session, change the theme.

The page keeps one WebSocket. When it drops, the page retries — after 0.5 s,
then doubling to at most 15 s, each wait jittered — with a fresh ticket, and
resumes every session it follows from the last event it holds, so nothing that
happened meanwhile is missed or shown twice; a reload is never needed. A
restarted server signs the browser out (sign-ins live in memory) and the page
asks again. A server that comes back as a different version offers a reload.

**Developing the UI** (`sugar-crush-web/`, Node 24): `npm run dev` serves it
from Vite with hot reload and proxies `/ws` and `/api` to `127.0.0.1:7420`;
start that server with `--allowed-origin http://localhost:5173` so it accepts
the dev page's origin. The end-to-end suite (`npm run e2e`) drives the built UI
in Chromium against a real `serve` on the offline echo provider. With no model,
a prompt whose lines read `::tool <Name> <json-object>` makes the echo provider
call that tool — through the permission gate like a model's call — and then
report what it returned:

```text
::tool Write {"file_path": "notes.txt", "content": "alpha\nbeta\n"}
```

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

## See also

- [`sugar-crush-web`](https://github.com/sugarcraft/sugar-crush-web) — the
  browser UI's own README: its panels, its keyboard, and developing it.
- [`protocol/sugarcrush.v1.schema.json`](protocol/sugarcrush.v1.schema.json) —
  the generated JSON Schema of every method and event.
- [`SETTINGS.md`](SETTINGS.md) — the `server.*` keys.
- [`PERMISSIONS.md`](PERMISSIONS.md) — the modes a served session runs under.
- [`AGENTS.md`](AGENTS.md) — the sub-agents the Agents panel shows.
- The [README](../README.md#documentation-index) — every other page.
