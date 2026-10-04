# Server mode (`sugarcrush serve`)

`sugarcrush serve` runs an HTTP + WebSocket server that a browser (the
[`sugar-crush-web`](https://github.com/sugarcraft/sugar-crush-web) UI) or a
script drives sugar-crush through. It runs in the foreground until `Ctrl+C` or
`SIGTERM`, on the same ReactPHP loop the engine's forked turns use, and serves
one project root — the current directory or `--root`.

This page covers the **transport**: binding, authentication, the WebSocket
endpoint and the security model. The `sugarcrush.v1` method and event roster
(sessions, turns, permission answers) lands with the protocol; until then a
WebSocket request is answered with a well-formed JSON-RPC
`-32601` / `data.kind: "not_implemented"` error (see [WebSocket](#websocket)).
Background mode and `serve status|stop|logs|url|token` are not there yet
either: today the server is a foreground process.

## Starting it

```sh
sugarcrush serve                       # 127.0.0.1:7420
sugarcrush serve --port 0              # any free port; the startup lines say which
sugarcrush --root ~/src/app serve      # serve another project
sugarcrush serve --output-format json  # one JSON document with the URL on stdout
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
| — | `server.allowedHosts` | Host names (or `host:port`) answered beside the loopback names — a reverse proxy's public name. |
| — | `server.trustedProxies` | IPs or CIDRs whose `X-Forwarded-For` / `X-Forwarded-Proto` are believed. |
| — | `server.allowBypass` | `true` is the same as `--allow-bypass`. |

Every `server.*` key is read from `~/.sugar-crush/config.json` only — never from
a project file, because a cloned repository must not be able to open a port,
widen the origins a browser may drive the agent from, or hand out the bypass
modes. A non-loopback `server.host` still needs `--allow-remote` on each launch.

### Refusals and exit codes

`serve` refuses to start, at exit `2` before binding anything, when:

- an operand follows the verb, or a flag value is malformed (a port that is not
  `0`–`65535`, an origin that is not `http(s)://host[:port]`);
- `--host` is not loopback and `--allow-remote` was not given;
- the permission mode is `bypass-permissions` or `dont-ask` and neither
  `--allow-bypass` nor `server.allowBypass` permits it;
- it runs as root without `--allow-root`;
- `ext-pcntl`, `ext-posix` or `ext-ffi` is missing (or FFI is disabled by
  `ffi.enable`) — see [Why pcntl, posix and FFI](#why-pcntl-posix-and-ffi);
- the state directory is unsafe (a symlink, someone else's, or readable by
  group or world), or `SUGARCRUSH_SERVER_TOKEN` is shorter than 32 characters.

A port that is already taken is exit `1`: it ran, failed, and may succeed once
the port is free. Under `--output-format json` that failure is still one
document on stdout, carried like `doctor`'s failing report — as the answer, not
as a new `error.type`: `{"result": {"listening": false, "address": "…",
"reason": "…"}}`. A refusal is the usual `usage` error document. `sugarcrush doctor` reports the three extensions as its
`server mode` check (a `WARN`, never a `FAIL` — the TUI does not need them).

## Authentication

Every request that can read or change anything carries a credential — **on
loopback too**. Anything that can send a prompt can run code as you, and
127.0.0.1 is reachable by every other account on the machine and, by DNS
rebinding or a cross-site WebSocket, by any page your browser opens.

**The owner token.** 32 random bytes (64 hex characters), minted on the first
`serve` into `<state dir>/token` — mode `0600` in a `0700` directory this user
owns. `SUGARCRUSH_SERVER_TOKEN` replaces it. It is compared with `hash_equals`
and never printed.

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

Messages are JSON-RPC 2.0. Until the `sugarcrush.v1` methods land, every request
is answered

```json
{"jsonrpc":"2.0","id":"c1","error":{"code":-32601,"message":"method not found: …","data":{"kind":"not_implemented"}}}
```

text that is not JSON gets `-32700` (`parse_error`), a non-object or a missing
`"jsonrpc":"2.0"` gets `-32600` (`invalid_request`), and a notification (no
`id`) gets no answer.

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
  and `dont-ask` are refused — at startup and, with the protocol, over the wire
  — unless `--allow-bypass` / `server.allowBypass`.
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
