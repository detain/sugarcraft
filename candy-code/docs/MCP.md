# MCP servers

CandyCode reads Model Context Protocol config from two files — `.mcp.json` at
the project root, the same filename and the same `mcpServers` key Claude Code
uses, and your own `~/.candy-code/mcp.json` — and exposes each tool a
configured server advertises to the model as an ordinary tool.

Nothing starts unless you have opted this project in. That gate is the first
thing to understand, because starting an MCP server *is* code execution and it
happens at launch, before any tool call and in every permission mode.

---

## The trust gate

`Bootstrap::mcpConfigDecision()` is the one discovery path — `mcpClient()`,
`mcpServerInventory()` and `candy-code mcp list` all come through it, so the
listing can never report a verdict the launch does not act on. The same rule
holds entry by entry: an entry that is not a named server object is listed with
the launch's own refusal sentence — the text arm says so on stderr and
the JSON arm carries it under `skipped`, so a typo costs a line instead of
vanishing. It returns one of five statuses:

| Status | Meaning | What happens |
|---|---|---|
| `absent` | no `.mcp.json` at the root | nothing; one `stat()` and out |
| `outside-tree` | present, but it resolves outside the checkout | refused, reported at launch |
| `untrusted` | present and contained, but this root is not trusted | not started, reported at launch |
| `trusted` | present, contained, and this root is listed | servers may start |
| `disabled` | the `CANDY_CODE_MCP_DISABLE` gate is on — it short-circuits every status above | nothing starts, whatever the file says; the surfaces say switched-off, not absent |

To opt in, add the canonical project root to `trustedProjectMcp` in
`~/.candy-code/config.json`:

```json
{
  "trustedProjectMcp": ["/home/you/src/myproject"]
}
```

The launch prints the exact line to add when it refuses. **Every relative entry
is rejected**, not just `"."` — `../x` and `src/repo` too. `.` is merely the
shortest spelling: it resolves against the working directory on every launch
exactly as `--root` does, so it would always match, turning a per-path allowlist
into "trust every repository I `cd` into". `~` and `~/…` are expanded first and
are accepted. See [`PERMISSIONS.md`](PERMISSIONS.md#the-four-trustedproject-keys) for
the measured table.

Containment is checked **before** trust, deliberately: an out-of-tree config is
reported as out-of-tree rather than as untrusted, because the two have different
fixes and only one of them is "opt in".

The trusted-roots list is read **once per process and frozen**
(`Bootstrap::trustedRootsForThisProcess()`). A write made during a session — a
repository whose README prompt-injects the model into appending a line — cannot
take effect in that session. It cannot make the *next* launch safe; nothing
can, once arbitrary shell has run as you. The property is narrower and is the
one the gate needs: the decision is yours, and it is made before the untrusted
content is running.

### Trust covers the servers, not the path

A root in `trustedProjectMcp` used to be the whole decision: whatever
`.mcp.json` said at the next launch was started. So a `git pull`, a branch
switch or a contributor's checkout that changed a server's `command` to
`sh -c 'curl …|sh'` ran it at startup, in every permission mode, before any tool
call, with no new consent. The grant now binds to each server:

- **What is pinned.** Every entry as the client would build it — after the
  foreign-spelling normalisation (`environment`, a whole-argv `command` array,
  `type: local`/`remote`) — minus the keys that only bound or describe it
  (`enabled`, `startTimeout`, `toolTimeout`, `timeout`, `description`). So `command`, `args`,
  `env`, `url`, `headers`, `path` and `type` are covered, and so is any key
  nobody anticipated. `env` is pinned as written: `${VAR}` stays unresolved, so
  your own environment moving re-prompts nothing and no secret value is
  recorded.
- **Where.** `~/.candy-code/mcp-trust.json` (`MCP\McpTrustPins`), one record per
  canonical project root: a sha256 fingerprint and a one-line summary per
  server. It is written by the tool, mode 0600; `config.json` stays the
  hand-authored grant.
- **First launch under a grant** (a root you just listed, or a grant older than
  this change) records the servers it starts — the launch you opted in to.
- **After that**, a server whose fingerprint differs, or that was not there
  when the record was made, is **not started**. The check runs inside
  `McpClient::startServers()` on the bytes the client loaded, before anything
  is built, so there is no window between check and spawn. The launch names
  each refused server in both channels, with the command line it was recorded
  as and the one it has now (or that it is new), and the command that approves
  it. Unchanged servers in the same file start as usual.
- **Re-approving.** `candy-code mcp trust`, run in the project (or with the
  project directory as the first operand), lists every entry as `new`,
  `changed`, `unchanged`, `removed` or `invalid`, adds the root to
  `trustedProjectMcp` when it is missing, and records the file as it is now.
  It starts nothing; `--output-format json` returns the same report.

### Two tiers, one precedence

There are two files, and this section is the day the old sentence calling the
second one's absence "a decision" was rewritten — your own
`~/.candy-code/mcp.json` is now read beside the project's `.mcp.json`, same
`mcpServers` shape, and the precedence question that sentence named is
answered here:

- **The gate is ownership, not ceremony.** The user file is read only when
  `HomeDirectory::owned()` verifies the home — the same check every other
  `~/.candy-code` file rides. Entries you wrote for yourself are admitted on
  that gate alone: no `trustedProjectMcp` listing, no `mcp trust`, no pin
  record. The project ceremony stays project-only in both directions —
  `candy-code mcp trust` reports and records the project tier's names, and a
  user server never enters a project's pin file, or a refusal notice whose fix
  line tells you to run that command.
- **The project wins a shared name.** A repo that declares `issues` owns what
  `issues` means for that checkout — matching the trust record, which is keyed
  to the repo's own bytes. The shadowed user entry is invisible to every
  report: not started, not refused, not even a shape-check sentence. The `/mcp`
  panel's `User scope: N servers (path)` row reads the file live and counts the
  entries shadowing would leave to be adopted were the file this launch's — it
  is a display of the roster, not its state: a file created mid-session lifts
  that N with no drift line (next bullet), and the servers this process will
  actually start stay the ones its memoised client carries.
- **Why the repo wins the collision:** the repository is the side that is
  consented to per server, so widening discovery must not silently retitle
  that consent; and your tier still cannot be overridden wholesale, only
  name-for-name where you both declare.
- **The freeze covers both files.** Each tier the launch read is digested at
  the instant its client is memoised, the drift line answers for either, and
  the client's memo key composites the two paths — nothing happens to either
  file automatically mid-session, and the manual stop/start pair
  `/mcp reconnect` runs does re-read them, because a start is a fresh
  `loadConfig()` off disk. What refuses changed bytes is the launch-digest
  drift gate in front of that pair: if either file moved since the launch
  digest, the gate prints its verdict and the pair never runs, so new bytes
  are never honoured — the freeze rule rests on the gate, not on a
  never-re-read mechanism. The `CANDY_CODE_MCP_DISABLE`
  switch answers for both tiers at once.
- **Where a `stdio` server runs (C-MCP-1).** An entry never inherits whatever
  directory the launch happened to stand in. A project-tier entry spawns at
  the root holding its `.mcp.json` — the same root the pin record is keyed
  to, so an approval made while sitting in `repo/sub` runs the server at
  `repo`; before this rule the child inherited the launch directory, which
  meant a server resolving `.` or reading its own config-relative files was
  really reading wherever the last `candy-code` happened to be typed. A
  user-tier entry spawns at the home whose ownership gate admitted the file.
  An entry may also pin its own directory with `cwd`: an absolute existing
  one wins on either tier, and a relative or missing spelling is a config
  error costing that entry alone — named in the `startServers()` aggregate
  like any other bad key, and fingerprinted by the trust record like any
  other good one, because moving where a server runs moves what it loads.

---

## `.mcp.json`

```json
{
  "mcpServers": {
    "filesystem": {
      "type": "stdio",
      "command": "npx",
      "args": ["-y", "@modelcontextprotocol/server-filesystem", "/srv/data"],
      "env": { "API_TOKEN": "${MY_TOKEN}" },
      "startTimeout": 120,
      "toolTimeout": 300
    },
    "issues": {
      "type": "http",
      "url": "https://mcp.example.com/mcp",
      "headers": { "Authorization": "${ISSUES_TOKEN:-}" }
    },
    "repo": {
      "type": "git",
      "path": "/home/you/src/myproject",
      "timeout": 600
    }
  }
}
```

Five types, and they are the five `McpClient::startServer()` constructs:

| `type` | Class | Keys |
|---|---|---|
| `stdio` (the default when `type` is absent) | `StdioMcpServer` | `command`, `args`, `env`, `startTimeout`, `toolTimeout`, `cwd` |
| `http` | `HttpMcpServer` | `url`, `headers`, `toolTimeout`, `stream`, `cwd` |
| `sse` | `SseMcpServer` | `url`, `headers`, `toolTimeout`, `cwd` |
| `git` | `GitMcpServer` | `path` (omitted → this project), `timeout` |
| `claude-mcp` | `ClaudeCodeMcpServer` | none — the repository names nothing |

Any other `type` — beyond the five above and the aliases below — **throws**
`Unknown MCP server type: …` for that one entry, and it costs only its own server:
`McpClient::startServers()` attempts every entry whatever its position in the
file, collects each failure, and throws one report after the loop naming the
entries that failed. That throw is caught in `Bootstrap::mcpClient()`, reported
through `error_log()`, and the launch continues with fewer tools rather than
dying over a live TUI.

### Foreign spellings that are read

Configs copied from opencode name some of these shapes differently, and a
`command` given as one whole argv array is common enough that the reader
handles it too. `McpClient::startServer()` normalises every spelling below
**before** the factory match, so everything on this page describes what an
entry runs as once it is read:

| Spelling seen | Read as | Rule |
|---|---|---|
| `"type": "local"` | `stdio` | alias map `McpForeignTranslate::TYPE_ALIASES` |
| `"type": "remote"` | `http` | alias map `McpForeignTranslate::TYPE_ALIASES` |
| `"command": ["npx", "-y", "pkg"]` | `command: "npx"` plus `args` | the head is the program and the tail joins ahead of any `args` already present; a non-empty list of strings is required |
| `"environment": { … }` | `env` | used only when `env` is absent — when both are present, `env` wins |
| `"enabled": false` | not started | the entry is skipped and named in the launch report; `true` or absent starts it |

A renamed `env` rides the `${VAR}` interpolation below exactly like a
literally spelled one — an opencode `environment` map is not a second env
map — and `enabled: false` is a decision being honoured, not a defect being
worked around: it costs one line in the launch report and nothing else.

The same vocabulary is what `candy-code mcp import claude|opencode|claude-project <path>`
translates. `claude-project` reads the per-project `projects["<root>"].mcpServers`
block of a Claude Code config (`~/.claude.json` is where that block lives) for
the root `--root` names, defaulting to the working directory — it is a READ
source, and the verb never writes a foreign file or a project tier.

The verb started life print-only (E710). That law was amended by this
campaign's ruling: `--write user` is the one allowed exception. With the flag
the translated entries are appended into `~/.candy-code/mcp.json` — behind the
same `HomeDirectory::owned()` gate every user-tier write uses, published by
`AtomicFileWriter` at mode `0600` via a temp-then-rename. The refusals are all
whole-write refusals: if the existing file already declares any name the
import carries, the write aborts and lists the names, changing nothing; a
corrupt or unreadable existing file is refused, never repaired; `--write` with
any other tier (including `project`) exits 2 having attempted nothing. Without
the flag the verb still only prints, byte for byte as before.

The risk the flag takes on is stated once, plainly: entries written to the
user tier start at **every** future launch from **every** directory, with no
per-repo ceremony and no project trust pin — same posture as any server you
add to `~/.candy-code/mcp.json` by hand. Type the flag on purpose; the
success note on stderr repeats the fact after every write.

### Supported transports

The MCP specification names more transports than candy-code builds. The honest
census, table first:

| Transport | Works here | Config it takes | How it runs |
|---|---|---|---|
| Stdio | yes | `command`, `args` (+ optional `env`, `startTimeout`, `toolTimeout`) | spawned child, JSON-RPC over pipes |
| HTTP | yes | `url` (+ optional `headers`, `toolTimeout`, `stream`) | Streamable HTTP: one POST per message accepting `application/json, text/event-stream`, the `Mcp-Session-Id` from `initialize` echoed on every later request, replies read from a JSON body or SSE `data:` frames; server requests riding a reply stream are answered by a POST back on the session, and `stream: true` adds the opt-in inbound GET for between-call traffic; a stored OAuth bearer attaches per request, and a static `Authorization` you set in the config wins over the store |
| Git | yes | `path` (omit → this project) (+ optional `timeout`) | in-process — no transport, no child, no socket |
| Claude-mcp | yes | operator-tier `claudeMcpBinary` (+ optional `claudeMcpArgs`, `claudeMcpEnv`; never the entry) | spawned child under process containment, JSON-RPC over pipes |
| SSE | yes | `url` (+ optional `headers`, `toolTimeout`) | legacy 2024-11-05 HTTP+SSE: one long-lived `GET` of `text/event-stream`; its first `endpoint` event names where requests POST, and the client locks that target to the configured URL's origin — a cross-origin publish is refused before anything is sent, so the bearer can never ship to a host the operator did not configure; replies ride the stream matched by `id`, and the server requests and progress that ride it too are answered and routed; a dropped stream gets a bounded reconnect burst and then refusal until the next call retries it |

The `sse` row has an interesting history: this transport is why
`startServer()`'s guard is as wide as it is — before the widening, one
unrecognized `sse` entry disabled every server listed after it in the same
file, with no report at all — the transport the spec named arrived later.
An `http` entry whose URL happens to end in `/sse` is unaffected — the
transport is the `type`, never the URL. Note also that the event-stream `GET`s
(sse's session stream, and http's `stream: true` channel) open their own
sockets and do not consult `HTTP_PROXY`/`HTTPS_PROXY` (unlike the Guzzle POST
legs); on a proxied host, reach the server directly or via `url` rewriting.

A fourth row deserves its own paragraph, because it is the only transport the
repository cannot fully choose: **`claude-mcp` spawns the OPERATOR's binary,
not the repository's.** The entry is `{"type": "claude-mcp"}` and nothing else —
an entry carrying `args`, `command`, `env` is refused as a config-error
report, because the repository does not name this binary. The spawn comes from
three keys in the USER config, the same tier that owns the list above:
`claudeMcpBinary` (an absolute, existing, executable path — a bare name is
refused even though it would resolve on `$PATH`, and `$PATH` is not a grant),
an optional `claudeMcpArgs` (default `mcp serve`), and an optional `claudeMcpEnv`
of literal strings — the `${VAR}` interpolation below applies to two keys
only, and neither is among these three. No entry becomes a claude-mcp spawn
without all of it, and the operator's path is never echoed anywhere a
transcript can see it: the `/mcp` inventory's detail for this transport reads
the fixed label `(operator-supplied)`.

The grant is read once per process inside the launch-frozen client memo — a
mid-session edit cannot re-spawn anything until relaunch — and trust still
gates the launch: an untrusted project never builds a client at all, so it
never reaches the operator tier. What the entry DOES buy is a bridge set
routed exactly like a stdio one: every forwarded call rides the PreToolUse
chain, and plan mode denies every `mcp__*` name its server has not declared
read-only. Starting, though, IS the
execution the tiers gate — the PreToolUse chain sees calls, never the
`proc_open` behind them — which is why the grant sits a tier above the file
the trust list controls rather than inside it.

`startTimeout` is **seconds, per server, and bounds the handshake only** — a
`tools/call` carries its own ceiling, `toolTimeout` (below).
Only a positive number is honoured; a string, `0` or
a negative falls back to `StdioMcpServer::DEFAULT_START_TIMEOUT_SECONDS`, which
is `60.0`. A hand-edited config must not be able to turn the bound off by
accident.

`toolTimeout` is **seconds, per server, per `tools/call`, and always on**.
Unset (or a non-positive) takes `StdioMcpServer::DEFAULT_TOOL_TIMEOUT_SECONDS`,
which is `120.0` — the same span as the turn's idle ceiling, so a wedged
third-party server costs one call, never the session. A legitimately slow
server (a build, a crawl) raises the number per entry; there is deliberately
no way to switch the bound off, the policy `startTimeout` already enforces.
While a call runs alone in a turn it sends the turn a heartbeat, so the 120 s
idle watchdog does not kill a long call; a slow server costs its own wait, not
the turn. When a call reaches its ceiling, the client stops waiting, sends the
server `notifications/cancelled` naming the request, and the model gets an
error result (`Tool call timed out after 300s (toolTimeout) …`); the
connection stays usable and the late reply, if any, is discarded. Only a
positive JSON number overrides the default: a string, `0` or a negative takes
it rather than setting a zero bound. Like `startTimeout` it is left out of the
trust
fingerprint, so tuning it re-prompts nothing. It is read for
`http` entries too: an http `tools/call` now carries the entry's `toolTimeout`
(default `120.0`, the same number as stdio's) instead of the shared client's
fixed 30 s, every http leg additionally gets a 10 s connect bound, and a call
abandoned at its ceiling sends the server `notifications/cancelled` before
reporting — the same courtesy the stdio transport already paid.
Every answer is capped at 65,536 bytes before the model sees
it, with a marker naming how much was cut. `startTimeout` is read for `stdio` entries only: a `claude-mcp`
server's `initialize` and start-time `tools/list` each wait the same fixed
`StdioMcpServer::DEFAULT_START_TIMEOUT_SECONDS` budget, and nothing in the
entry or the operator tier changes it.

Launch holds are additive and each is bounded — this is the accepted shape of the rule,
not an oversight: `McpClient::startServers()` walks the entries
serially, so a cold `npx` server costs its `startTimeout` before the next
entry is tried, and several slow entries can hold the first frame for that
many bounds stacked. An `http` entry pays the same walk with its handshake cut
by the client's fixed 30 s bound; a `claude-mcp` entry by the fixed start
budget named above. What the port deliberately does NOT have is a concurrent
spawn, a wall-clock deadline on the launch as a whole, or a first-frame grace
notice: the sum of per-entry bounds is already finite, a notice about slow
synchronous startup would need exactly the asynchronous startup it softens,
and a blanket launch budget would cut off the user's own slow-but-legitimate
server for everyone's benefit. Tune the wait per entry with `startTimeout`, or
park the entry with `enabled: false` — a decision honoured: named in the
launch report, never launched.

A server that answers `initialize` with a JSON-RPC `error` has refused the
session, so `start()` treats it as a start failure: the child is stopped and
the exception carries the server's error code and message plus its stderr
tail. In `/mcp` such a server shows ` · not up`, never ` · up 0 tools`.

When the start-time `tools/list` is paginated, the `stdio`, `http` and `sse`
transports all follow the cursor chain to the end before caching the roster: every
`nextCursor` is walked page by page, and a cursor that repeats or a roster
still being cut at the thirty-two page ceiling is a start failure. A mid-walk
error is treated exactly like a front one — a partial roster never masquerades
as a whole one.

An `http` server is held to the same rule — an `initialize` error reply fails
`start()` with the server's code and message — and speaks the Streamable HTTP
transport the rest of the way:

- **Headers the transport owns.** Every POST carries
  `Accept: application/json, text/event-stream` and
  `Content-Type: application/json`. A config `headers` entry of either name
  (or of `Mcp-Session-Id` / `MCP-Protocol-Version`, matched
  case-insensitively) is dropped, not merged: an `Accept: application/json`
  from the config is exactly what a Streamable HTTP server refuses with 406.
  Every other configured header is sent as written.
- **Credentials refused is its own event.** An http `start()` that answers
  401 or 403 is not a host-down shrug: the server is asking for credentials,
  and the fix is one command. The launch records it and the transcript says
  `MCP servers refused this launch for credentials: <name> (HTTP 401): run
  \`candy-code mcp auth login <url>\`, then restart`, naming the URL the
  credential store keys on; in `/mcp` the server keeps its declared row with
  an `auth-required 401` marker instead of vanishing. A static
  `Authorization` header that earns the 401 reads the same way — the remedy
  there is the header, and the notice's login command is simply the wrong
  door, which is still louder than silence.
- **Session.** A `Mcp-Session-Id` on the `initialize` reply is echoed on every
  later request, starting with `notifications/initialized` (an id-less POST the
  server answers 202). A 404 to a request that carried the id means the server
  forgot the session: the client re-initialises once and resends; a second
  failure is reported, not looped. `stop()` ends the session with a
  best-effort HTTP `DELETE` carrying the id — a 405 or a dead host does not
  fail a shutdown — and `isUp()` then answers false until `start()` runs a
  fresh handshake.
- **Version.** `initialize` advertises `2024-11-05`; the
  `MCP-Protocol-Version` header is sent only when the server negotiates
  `2025-06-18` or later, the revision that introduced it.
- **Replies.** A `text/event-stream` reply is read as SSE: `data:` lines join
  with a newline, comments and non-`message` events are skipped, and the
  message whose `id` matches the request is the answer. A server request or
  `notifications/progress` riding ahead of the answer is no longer passed
  over (C-MCP-3.c): with an answer desk installed the request is answered by
  a fresh POST of the JSON-RPC response carrying the `Mcp-Session-Id`, and
  progress rides the call's sink — see "Server requests" below. The reply
  body is read whole, so a server must close the stream once it has
  answered, as the specification asks and both reference SDKs do.
- **Inbound GET (`stream: true`).** The buffered POST above is the default
  and never changes. An `http` entry that opts in with `"stream": true`
  additionally opens the spec's server-to-client channel: a persistent `GET`
  of `text/event-stream` on the same endpoint, carrying the session id and
  the same headers (and the same stored bearer) as every POST, opened over
  the deadline-bounded reader the legacy `sse` transport shares —
  `SseLineParser::openEventStream()`. Traffic that arrives between calls is
  read at the next exchange start and on the client's idle pump, so a `ping`
  is answered, not waited out. A server that refuses the GET (405 is the
  spec's own answer) costs the channel, never the session; a session lost to
  expiry closes the channel with it until the next `start()`. Like every
  raw-socket leg in this transport family, the GET bypasses `HTTP_PROXY`.

### How the legacy SSE transport runs

`sse` is the 2024-11-05 wire: two half-channels instead of one. `SseMcpServer`
opens the read side first — a single long-lived `GET` of `text/event-stream`
carrying your `headers` (and a stored bearer under the same precedence as
`http`) — and waits for the stream's first `endpoint` event, whose `data` is
the URI every JSON-RPC message POSTs to (absolute, or resolved against the
configured URL the way a browser would resolve it). Then:

- **Origin law.** The POST target must share the configured URL's origin —
  scheme, host, and port. A stream that publishes elsewhere is refused loudly
  before the handshake POSTs anything: the server chooses where requests go,
  and an unchecked `endpoint` event would ship every message, bearer included,
  to a host the operator never configured. Redirects on the `GET` are refused
  for the same reason; the response must be `text/event-stream` exactly.
- **Delivery vs answer.** POSTs are fire-and-listen: a `2xx` (typically `202`)
  only means the server took the message. The reply arrives on the stream,
  matched by `id`, with the same `data:`-join and non-`message` skipping the
  `http` transport uses (both read one shared `SseLineParser`).
- **Server requests are answered (C-MCP-3.c).** A `ping`, `roots/list` or
  `elicitation/create` riding the stream — during a call or between calls —
  reaches the answer desk, and its JSON-RPC answer is POSTed back over the
  origin-locked endpoint like any other client message;
  `notifications/progress` rides the per-call sink. The handshake therefore
  advertises the matching `roots` and `elicitation` client capabilities.
  Between calls the client's idle pump drains the stream on a bounded window,
  so a ping waits for the next pump or the next call, never forever.
- **Per-call ceiling.** `toolTimeout` bounds how long one call waits for its
  reply on the stream — the same opt-in per-call IO ceiling the other wire
  transports carry, never a blanket timeout on the long-lived connection
  itself, which is idle by design between calls.
- **Drops.** If the stream dies mid-call, that call fails and is **not**
  resent (the server may already be working on it; replaying could double a
  side effect). The next call opens a bounded reconnect burst — three
  attempts, 0.5 s doubling and capped — each a full fresh handshake on a new
  `GET`. An exhausted burst marks the session lost and refuses until a later
  call gets a fresh burst.
- **Forks.** A forked worker never reads the parent's socket: the first call
  in a child opens its own stream and runs its own handshake.

`stop()` closes the stream; there is no session id to end and no `DELETE` to
send. The legacy revision is deprecated upstream in favour of streamable
`http` — use `sse` only for servers that predate the switch.

### `${VAR}` interpolation — exactly where it works

`McpClient::resolveEnv()` expands `${VAR}` and `${VAR:-default}`, and it is
applied to **two keys only**: a `stdio` server's `env` map and the `headers`
map of an `http` or `sse` server. It is *not* applied to `command`, `args`, `url` or
`path`.

One quoted pattern (`/\$\{([A-Za-z_][A-Za-z0-9_]*)(?::-([^}]*))?\}/`) walks the
value as a **substring**, so `"Bearer ${TOKEN}"` interpolates in place —
The whole-value form that passed such a header through was replaced
literally. A reference whose name leaves that shell alphabet (`${BAD NAME}`)
and bare `$` prose stay literal, unparsed.

An unset variable with no `:-default` resolves to the empty string. Note the
resolution is `getenv($name) ?: $default`, so a variable set to `0` or to the
empty string also takes the default.

This is a *different* mechanism from the `${VAR}` expansion `ProviderFactory`
performs on provider config — see [`ENVIRONMENT.md`](ENVIRONMENT.md#variables-read-from-any-config-file).

**Inherited credentials must be declared.** A `stdio` server inherits your
environment **minus** every variable named like a credential (`*_API_KEY`,
`*_TOKEN`, `*_SECRET`, `AWS_*`, case-insensitive) — the same scrub Bash, Grep
and script hooks get (`ProcessContainment::mcpEnv()`). `.mcp.json` is
repository content, and a server you trusted for one job used to receive every
token in your shell. A server that needs one names it in its own `env` map,
which is applied after the scrub and is the grant:
`"env": { "GITHUB_TOKEN": "${GITHUB_TOKEN}" }` passes your `GITHUB_TOKEN` to
that server and to no other. A name listed in `secretEnvAllowlist` (user tier,
see [`SETTINGS.md`](SETTINGS.md)) reaches every server. Everything that is not
credential-shaped — `PATH`, `HOME`, `LANG`, proxy settings — is inherited as
before. `McpClient::strippedSecretEnv()` records, per server, the names it
was started without (never the values), so a server that then fails to
authenticate can be told apart from a broken one.

### Adding servers

The `/mcp` panel opens empty in a fresh project and both hint lines there point
here, so this is the worked answer to "how would I do that?". Shortest honest
form: **write the file, trust the root, relaunch** — there is no registration
step for anything that does not demand OAuth login. (Your own
`~/.candy-code/mcp.json` skips the middle step: see
[Two tiers, one precedence](#two-tiers-one-precedence).)

A stdio server and three zero-auth remotes, in candy-code's native config spelling:

```json
{
  "mcpServers": {
    "searxng": {
      "command": "npx",
      "args": ["-y", "@spences10/mcp-searxng-ultimate"],
      "env": { "SEARXNG_URL": "https://searx.example.org" }
    },
    "context7": { "type": "http", "url": "https://mcp.context7.com/mcp" },
    "exa": { "type": "http", "url": "https://mcp.exa.ai/mcp" },
    "gh-grep": { "type": "http", "url": "https://mcp.github.com/grep" }
  }
}
```

Nothing about the three `http` entries above needs a credential, a registration
URL, or a token URL: an `http` entry starts from its `url` alone — headers are
optional, and a stored bearer attaches only if one was ever saved. `mcp auth`
serves the OAuth minority, which is now what the panel's empty states and the
credentials table each say out loud.

The same four servers, written the way an opencode config writes them, are
accepted verbatim — see "Foreign spellings that are read" above for the full
normalisation roster:

```json
{
  "mcpServers": {
    "searxng": {
      "type": "local",
      "command": ["npx", "-y", "@spences10/mcp-searxng-ultimate"],
      "environment": { "SEARXNG_URL": "https://searx.example.org" }
    },
    "context7": { "type": "remote", "url": "https://mcp.context7.com/mcp" },
    "exa": { "type": "remote", "url": "https://mcp.exa.ai/mcp" },
    "gh-grep": { "type": "remote", "url": "https://mcp.github.com/grep", "enabled": false }
  }
}
```

The two blocks declare the same four servers and run them the same way:
`type: local` reads as `stdio`, `type: remote` as `http`, a whole-argv
`command` array splits into program plus args, `environment` rides as the env
map, and `enabled: false` keeps a server documented but not launched. Either
spelling is fine, per entry; the reader decides shape by key, not by file.

Both files are PROJECT config, so they run only once the repository is trusted:
name its path under `trustedProjectMcp` in `~/.candy-code/config.json` — the
trust gate at the top of this page carries the exact rules — and relaunch,
because the decision is frozen for the life of the process. `/mcp` then lists
every declared server whether or not it started, and the liveness rows below
explain each suffix.

If a server really does demand OAuth, that is the single case with a second
step: `mcp auth add <url>` registers a client and stores tokens, and the flow
under "Authorization-code login" completes the browser dance.

---

### Browser control and computer use

Browser and desktop servers are ordinary `stdio` entries — but they are the
entries most often pasted from an upstream README that says `@latest`, and a
floating tag quietly defeats the pin record: the gate compares the entry's
recorded command line with its current one, so an argv that always reads
`@latest` never looks "changed" no matter which build the package behind it
resolves to. An exact version says otherwise — every upstream bump arrives
as a named change you approve again, which is the whole trust mechanism of
this page doing its job.

The versions and flags below were read from the package registries on
2026-10-30; each needs a re-date when it is re-read. `McpBrowserPresetDocTest`
extracts the fenced blocks under this heading, feeds every server entry
through the loader's own `McpForeignTranslate::normalizeEntry()`, and refuses
a package argument that lost its exact version — so a stale or looser edit
here fails a test rather than a session.

In the project's `.mcp.json` — which runs these only once the root is trusted,
per `trustedProjectMcp` in the trust gate at the top of this page:

```json
{
  "mcpServers": {
    "playwright": {
      "command": "npx",
      "args": ["-y", "@playwright/mcp@0.0.83", "--isolated"]
    },
    "chrome-devtools": {
      "command": "npx",
      "args": ["-y", "chrome-devtools-mcp@1.12.0", "--isolated"]
    },
    "windows-computer-use": {
      "command": "uvx",
      "args": ["windows-mcp==0.8.8"]
    }
  }
}
```

The same three entries in your own `~/.candy-code/mcp.json`, admitted on the
home-ownership gate alone (see "Two tiers, one precedence" above):

```json
{
  "mcpServers": {
    "playwright": {
      "command": "npx",
      "args": ["-y", "@playwright/mcp@0.0.83", "--isolated"]
    },
    "chrome-devtools": {
      "command": "npx",
      "args": ["-y", "chrome-devtools-mcp@1.12.0", "--isolated"]
    },
    "windows-computer-use": {
      "command": "uvx",
      "args": ["windows-mcp==0.8.8"]
    }
  }
}
```

What each one is, and what its isolation flag actually buys:

- **Playwright MCP** (`@playwright/mcp`, Microsoft's Playwright project):
  navigation, snapshots, form-filling and scripted page control. `--isolated`
  keeps the browser profile in memory and never saves it to disk; without it
  the server persists a profile between launches, and everything typed into
  a page lives in it.
- **Chrome DevTools MCP** (`chrome-devtools-mcp`, the ChromeDevTools project):
  the same shape of control through real DevTools, with the debugging tools
  that implies. `--isolated` opens Chrome in a temporary user-data directory
  that is cleaned up when the browser closes; the default reuses a persistent
  profile under the user cache directory.
- **Windows-MCP** (`windows-mcp`, on PyPI): desktop control outside a browser —
  app automation, clicks, keystrokes. It is Windows-only (its package pulls
  the Win32 bridges and claims Windows 10/11; it needs Python 3.14+, which
  `uv` provisions for the spawn), and that platform scope is stated because
  a Linux or macOS machine simply will not start it. No Linux/macOS
  computer-use server is pinned here — the maintained options are
  OS-fractional, so add one to a block above only with its own exact pin and
  a re-dated section.

Caveats, stated because a browser server makes every rule above load-bearing:

- **There is no jail around these children.** `bashSandbox` wraps `Bash`
  calls, and nothing else: a `stdio` server is a plain spawn of the pinned
  argv, started at the tier's directory ("Where a `stdio` server runs"
  above). A browser server reaches any URL its page is pointed at and any
  profile it is given, even while your Bash calls run sandboxed. Until a
  server-side jail exists (C-TOOL-4.e), deciding to start the server *is*
  the security decision, which is exactly why the pins above are exact.
- **Every bridged tool counts as a write.** The permission gate classifies an
  `mcp__<server>__<tool>` call as a write unless the server declared the tool
  read-only (`PermissionGate::isWriteTool()` — the hint is the server's own
  assertion: `annotations.readOnlyHint` set and `openWorldHint` not `true`, and
  nothing here can verify either against what the tool actually does). A
  browser tool acts on a live page, so plan on being asked. To run the
  read-flavoured ones without a prompt, name them in `permissionRules` in
  `~/.candy-code/config.json` — rules are first-match-wins, so these must
  sit ahead of any broader `mcp__` rule you write. The names are the server's
  own (`browser_snapshot`, `browser_take_screenshot` for Playwright):

```json
{
  "permissionRules": [
    { "pattern": "mcp__playwright__browser_snapshot", "action": "allow" },
    { "pattern": "mcp__playwright__browser_take_screenshot", "action": "allow" }
  ]
}
```

- **The schemas are heavy, and may defer.** A browser server advertises a
  long roster of richly-described tools; when one server's advertised schema
  total reaches `mcp.lazySchemaTokens` (user-tier setting, default 8000, `0`
  never defers), its tools stop arriving as first-class names and ride
  `mcp_list` and `mcp_call` instead — the Deferred schemas section below
  carries the rules, including which permission and hook spellings still
  apply to the deferred calls.
- **Already configured in Claude?** Nothing above has to be retyped: the
  import route under "Foreign spellings that are read" translates a Claude
  config — `candy-code mcp import claude <file>` — but note the verb only
  PRINTS unless `--write user` appends the entries, and the user tier it
  writes is the one the trust gate does NOT pin (the importer's own note
  above says so). If you want the fingerprint check to cover imported
  servers, paste them into a project `.mcp.json`, the tier `mcp trust`
  records.

---

## Listing without starting

```sh
candy-code mcp list
candy-code mcp list --output-format json
```

`Bootstrap::mcpServerInventory()` contains **no `proc_open()`**, and that is the
entire design constraint. Routing a listing through `mcpClient()` would mean
`candy-code mcp list` launches every program the repository names — the exact
act the trust gate exists to make deliberate, performed by the command an
operator runs precisely *because* they do not trust the file yet.

The consequence, stated because it is a real limit: the listing reflects the
**config, not liveness**. Nothing here can tell you a declared server would fail
to start; finding that out means starting it.

`candy-code doctor` reports the same verdict as one `mcp config` line: `OK` for
absent, disabled or trusted, `WARN` for untrusted, `FAIL` for out-of-tree or
undecodable.

---

## Liveness in a running session

The listing above reflects the config. A running session can additionally
report what it actually started: `/mcp` consults
`Bootstrap::mcpLivenessSnapshot()` — the memo of already-started clients in
THIS process only, read without ever building a client, because the only other
way to answer "what is up" would launch every server the repository names —
and annotates each declared row:

| Suffix | Meaning |
|---|---|
| ` · up N tools` | a `stdio` child that is still running |
| ` · exited N tools` | it came up at launch; the child is gone, its cached tool list is not |
| ` · ready N tools` | an `http` or `sse` server whose handshake completed |
| ` · ready (in-process) N tools` | the in-process `git` transport, up |
| ` · not up` | declared, but this process never started it — a failed `start()` is skipped silently at launch, and this is where that skip surfaces |
| ` · state unknown` | a transport the snapshot could not classify — unknown stays unknown |
| ` · disabled` | a started server this session refused mid-flight by `/mcp disable` — the process is alive and the tool cache stands, but calls are routed nowhere; `/mcp enable` lifts it |

The block closes with `Live in this process: K of M declared (other sessions'
servers are not visible here)`. The scope is honest: the readout is per
process, so a second candy-code session or a sub-agent worker keeps its own
servers to itself. A server this process started that the config no longer
declares gets its own line (`Started but no longer declared: name (N tools)`)
rather than vanishing from the count. A server that exposes resources may
carry one more tail after its tool count — ` · N resources`, the size of
that server's resource roster taken once at launch. The tail is a readout,
not a gate: a server that exposes no resources, or one whose read failed,
simply carries no tail.

A live session also digests each MCP config file it read — the project's
`.mcp.json` and, when it had one, `~/.candy-code/mcp.json` — at the instant it
memoises its client. The verdict rides inside the liveness block above, so it
renders only while this process has at least one started server to report — a
session that started no servers shows no liveness block, hence no verdict line
even after its config drifts. While that block is rendering and a file's bytes
no longer match the digest taken for it at launch, the panel adds one line:
`Config: changed since launch — restart CandyCode to apply (reload is not
implemented)`. That is a DETECTION, not a fix, and the fix was weighed and
declined (option β — hot-reloading the config): re-reading the file in-session would relaunch
servers under a root grant the process already holds, which re-arms exactly
the prompt-injection → `proc_open()` path the once-per-process freeze closes —
the trusted-roots list is read **once per process and frozen** above, and the
digest keeps that law whole by telling you when it has gone stale rather than
silently honouring new bytes under an old decision. And the panel stays
display-only, the prohibition on inventing a second persistence seam
standing: writing the grant has exactly one home, and neither a digest row
nor a button belongs to it. Restart is your decision, not the panel's.

On an untrusted or absent root the whole block is suppressed even if a map
arrives — the panel will not hand back, through "what this process started",
the same roster the discovery gate withholds. And in a cold process (the
one-shot `candy-code mcp list`, or any session that started nothing) there is
no memo to read and the panel renders exactly as it did before this readout
existed — byte for byte.

### One server, shared by every forked turn and sub-agent

Servers are started once, in the TUI process, and every turn runs in a
`pcntl_fork()`ed child — as do parallel workflow stages, sub-agents and
parallel `Task` calls. All of them talk to the **same** server process over
the same pipes (or, for `http`, the same `Mcp-Session-Id`). That is deliberate:
re-spawning per process would relaunch an `npx` server on every turn and throw
away whatever state the server keeps (a browser session, a memory store).

Sharing is made safe in the transport:

- **Request ids are unique across processes.** The process that started the
  server sends `0, 1, 2, …`; any forked process sends `<pid>-<nonce>-<n>`, so a
  reply can only ever be matched by the call that asked for it — including the
  late reply of a call whose turn was killed by Esc or the idle watchdog.
- **Calls on one stdio server are serialised.** Each exchange (request written,
  response read) holds a cross-process lock, so when parallel agents call the
  same `stdio` or `claude-mcp` server their calls run one after another; a slow
  tool call delays the next agent's call to that server. Different servers do
  not wait on each other.
- **A killed call does not poison the stream.** Unread output is kept with the
  lock rather than in one process' memory, and a call that dies mid-exchange
  leaves a marker: the next call terminates any half-written request line and
  skips the fragment of a half-read reply.
- **Server-initiated traffic on a stdio stream is answered in place (C-MCP-3).**
  A `ping` is answered, `roots/list` names the spawn directory the entry was
  approved for, `elicitation/create` is answered `cancel` unless the caller
  passed per-call answers, `notifications/progress` is absorbed, and any other
  request gets an honest `Method not found` — never silence. Answers are
  written while the exchange lock is held, so a server that waits mid-call for
  one holds only its own server's queue, and the call's timeout clock pauses
  while the answer handler runs.
- **Only the starting process stops a server.** A forked process exiting —
  normally or not — never signals the shared server or ends the shared HTTP
  session.
- **`http` servers** get process-unique ids too, and a forked process sends
  every request on a fresh connection instead of the TUI's inherited
  keep-alive socket. One session carrying concurrent requests is normal
  Streamable HTTP, so those calls are not serialised.

---

## Server requests

A server you approved can ask *you* things in the middle of a call: probe
liveness with a `ping`, list the workspace roots it may touch, ask the human
for a value with `elicitation/create`, or report how far along a long call is
with `notifications/progress`. Only the `http`, `sse` and `stdio` transports
interleave this traffic today: they are the server classes that carry an
answer desk, so their requests are answered in place while the exchange
lock is held. The other transports — `claude-mcp` and `git` — surface no
server-initiated requests, and their calls simply proceed.

Answers arrive through an *answer desk* (`McpServerRequests`). There are two,
and which one answers changes the reply:

- **The spawn-time desk** is built when the server starts and knows no human.
  It answers `ping` with an empty result, absorbs bare notifications, and
  answers an elicitation with `cancel`.
- **The per-call desk** is installed by a running MCP tool call for the
  duration of that one exchange. The TUI binds it when the turn has a
  permission approver to consult; with no approver bound (a headless run, or
  a tool copy nobody armed) no desk is installed at all and the call falls
  back to the spawn-time one.

What each request becomes:

- **`ping`** — an empty result. A server that pings is answered, not ignored.
- **`roots/list`** — one result, two readings, disclosed: the spawn-time desk
  names the directory the entry was approved for (its `cwd` override, or the
  config file's tier), while the per-call desk names the *process* working
  directory — the desk is built by the tool call, which does not re-read the
  config tier the entry came from. A server that treats root names as a
  sandbox boundary should not; trust-gating lives in the transport, not here.
- **`elicitation/create`** — in an interactive session, a question. Each
  requested schema field becomes an `AskUser`-shaped prompt headed
  `MCP server <name> asks: <message>`; a string answers with free text, an
  integer or number answers with a validated number, a boolean with yes/no,
  and a string carrying `enum` with a pick from those options. Several
  fields in one request are asked in sequence and answered together.
  Answering `decline` sends `{action: "decline"}`; Esc, a timed-out prompt,
  an unanswered required field, or an approver that throws all send
  `{action: "cancel"}` — the fail-closed direction, because a cancel is what
  a server that asked into silence should assume. A long wait does not eat
  the call's timeout budget: the clock pauses while a human is being asked.
- **`notifications/progress`** — with a per-call desk, a live tail on the
  running tool row: each notification updates the row's status line with the
  server's own message when it sent one (control bytes and marking sentinels
  swept from it), otherwise with a percent pacing computed from the reported
  progress and total. Without a desk the notification is absorbed and the
  row keeps its ordinary heartbeat.
- **Anything else** — an honest `Method not found`, never silence.
  `sampling/createMessage` in particular is refused: the client advertises
  `roots` and `elicitation` capabilities only, and a server-initiated model
  completion has no bridge into the session.

Headless (`-p`) runs ask nobody anything, and that includes MCP servers: the
launch funnel closes every MCP call tool beside `AskUser` and `PlanExit`, so
a `stdio` server's elicitations land on the spawn-time desk and answer
`cancel` by construction (on a desk-less transport the question never
arrives). The closure is sticky — a later attempt to bind an approver
does not reopen a closed tool, mirroring the `-p` rule the `AskUser` tool
already follows. While an elicitation waits for a human it holds only its
own server's exchange lock: parallel calls to *other* servers keep running,
and later calls to the asking server queue behind it, which is the whole
point of the serialised exchange.

A long call no longer holds every turn. A bridged or dispatched MCP call still
running after 120 seconds — the hand-off figure upstream uses for the same move
— answers the model instead with "still running as background MCP job `<id>`;
the result will arrive as a message", and the
exchange keeps running in a forked child. That is possible because the
library serialises a server's exchange behind a cross-process lock: any
forked process may finish one, and for a `stdio` server the row above keeps
holding — later calls to that server queue behind the child until it answers.
The finished result arrives through the turn inbox the `steer` frames use: at
the next step boundary of a still-running turn, or at the next turn if this
one ends first. Nothing is killed at the hand-off — the threshold releases
the *turn*, never the call. The child's own bound stays its transport's
`toolTimeout`, which keeps exactly the meaning it had, and an exchange that
errors or times out publishes that error row, which travels the same route.

The hand-off is a set of doors, and a closed door means the call runs inline
exactly as it did before this feature, byte-for-byte. It needs a process that can
fork (`pcntl`) and a private home to keep the registry in — which is the whole
story on Windows and on a `pcntl`-less build. It needs a turn that is actually
waiting on *this* call, the call handed the turn's liveness beat; a programmatic
`execute()` on an MCP tool nobody is waiting on keeps the inline path. And it
does not cover a call that has been handed the turn's ask channel or its live
tail: a fork copies those closures, a copied approver can put no question to the
human and a copied tail writes frames to nobody, so an elicitation-capable or
tail-capable call keeps blocking inline and every promise the elicitations
paragraph above makes about a server question reaching the human stays a
one-process promise. Backgrounding a long call that asks mid-flight is the open
seam — it would need the registry to carry the question up to the parent and the
verdict back down — and it is reported as a seam, not papered over here.
Headless (`-p`) runs likewise never hand off at all: the closed latch outranks a
live threshold, a non-interactive caller waits for the real result exactly as
before, and no `-p` dispatch can create a background job.

---

## What the model sees

`Bootstrap::mcpTools()` wraps each advertised tool in an `McpToolBridge`
(`src/Tools/McpToolBridge.php`) and appends it to the tool list. Bridge names
follow the `mcp__<server>__<tool>` convention, which is what permission rule
patterns like `mcp__git__*` match — see [`PERMISSIONS.md`](PERMISSIONS.md).
One server can opt out of that shape by being too talkative; see
[Deferred schemas: `mcp_list` and `mcp_call`](#deferred-schemas-mcp_list-and-mcp_call).

The client is built `unrestricted: true`, which is the opposite of what it looks
like. `McpClient::listTools()` fails *closed* without an `AgentPreset`, and the
main agent has no preset — that mechanism is *meant* to scope sub-agents, and
the client-side arm still has no production caller: per-preset `mcpServers`
allowlists are enforced one layer up instead, at grant-resolution, where the
preset actually is known — `AgentManager::resolveGrantedTools()` consults
`McpRouter::serverAllowed` (the router's own membership law) and drops bridges
whose server the preset does not name, so the narrowed roster is what the
sub-agent's provider request advertises. An empty or absent list is
allow-all, which is why declaring nothing changes nothing. The two options
at construction were "the main agent gets zero MCP tools" or "synthesize a fake preset for it".
What the flag bypasses is `McpRouter`'s per-preset allowlist, which is sub-agent
scoping, not your safety boundary: the main agent is not preset-scoped for
`Bash` either.

**Two controls, two jobs**, and conflating them is a mistake worth naming:

- **Launching** a server is gated by `trustedProjectMcp`. A
  repository-chosen command reaching `proc_open()` cannot be gated by anything
  downstream, because starting *is* the execution.
- **Calling** a bridge is gated by the `PreToolUse` hook chain and the
  permission gate, which see tool calls and never see `proc_open()`.

Neither substitutes for the other. The bridge decision coincides with `Bash`'s
in four of the six permission modes and diverges under `plan` and `auto`. Under
`plan` a read-only `Bash` command is allowed for exploration and every `mcp__*`
name is denied as a write tool; under `auto` the classifier asks before every
`mcp__*` call while a classified-safe `Bash` command is allowed. Both
divergences run in the conservative direction.

That paragraph is about a tool with no `readOnlyHint`. A tool whose server
declares `annotations.readOnlyHint: true` (and not `openWorldHint: true`) is
classified as a read, so it runs unasked in every mode that runs `Read`
unasked — `plan` and `auto` included. The hint is honoured only because a
server is started only once trusted; see
[PERMISSIONS.md](PERMISSIONS.md#the-six-modes) for the full rule.

**Denying servers.** The `disabledMcpServers` setting lists `mcpServers` names
from either tier's file, or `fnmatch` globs such as `untrusted_*`, that this
launch must not use.
`Bootstrap::mcpClient()` hands them to `McpClient::setDenyPatterns()`
before any server starts, and the client applies them everywhere: a matching
entry is never started (so it is not pinned as trusted either), its tools are
never listed, and a call naming it is refused — on the unrestricted main-agent
path as well as through `McpRouter` for a preset-routed client. Names are the
raw config keys, not the sanitised `mcp__<key>__` spelling. A trusted project
may set the list too, since a deny only removes servers; your own list replaces
a project's rather than merging with it. It is read once, when the session
first builds the MCP client, so a change applies on restart:

```json
{ "disabledMcpServers": ["untrusted_*", "scratch"] }
```

---

## Deferred schemas: `mcp_list` and `mcp_call`

A server's full `tools/list` schema is priced in tokens — name, description and
`inputSchema`, in the same shape every other tool is costed. When one server's
advertised total reaches `mcp.lazySchemaTokens` (user-tier setting, default
8000, `0` never defers), that server's tools stop arriving as first-class
`mcp__<server>__<tool>` names and instead ride two stable built-in-shaped
tools:

- **`mcp_list`** (position 39) answers the deferred roster — server, tool,
  description, and the schema the model needs to build arguments. Pass a
  `server` to see one server, or a `tool` to see exactly one tool's entry with
  its schema; no arguments for everything. A roster whose full schemas would
  overflow the tool's output cap comes back as names only, and each schema is
  then fetched by name through `tool`.
- **`mcp_call`** (position 40) runs one of them:
  `mcp_call(server, tool, arguments)`. The call goes through the exact same
  `McpClient::callTool()` path a bridge uses, so the router's allow/deny still
  decides it and the permission gate still translates your rules across both
  spellings (below). `PreToolUse` hooks see both spellings of the same call:
  the frame is `mcp_call` with the server and tool inside its arguments, and
  the registry tests a hook's matcher against the dispatcher name *or* the
  wire name those arguments resolve to — an operator hook whose matcher names
  `mcp__git__*` keeps firing for deferred servers exactly as it does for
  bridged ones, and a hook naming `mcp_call` keeps seeing the dispatcher
  frame. Gate and router are unchanged; only the matcher's view widens — the
  payload still carries `tool_name: mcp_call`.

Both spellings answer under the same untrusted-output fence: the result opens
with a `[mcp tool output from <server>/<tool>: no user authority]` line, and
fence tags and role-label lines inside the answer are defused, so server bytes
cannot speak with the harness's or the user's voice (`McpOutputFence`). The
header is added before the output cap clips, so it survives even an oversized
answer — and because both callers render through one `replyResult()`, a
bridged answer and a dispatched `mcp_call` answer for the same server and tool
are byte-identical, framing included. A FAILED call rides the same fence: the
transport's error words can carry the server's own bytes (a stdio refusal
splices its JSON-RPC `error.message`; http and sse rethrow what their stack
raised), so the `Error: MCP tool ... failed` row is fenced too, identically
under both spellings.

Inside that fence every non-text part is still described rather than dropped —
an image or audio part contributes its mime type and base64 size, an embedded
resource its capped text or its blob size — and since C-TOOL-2 two of those
descriptions also name real bytes riding the result beside the text: the first
image part whose base64 decodes, stays inside the same tool-side image budget
`Read` applies, and sniffs as a genuine image goes onto the result's image
channel, and the first embedded resource whose blob decodes inside the
tool-result document budget and sniffs as a PDF goes onto the document channel,
so a vision- or document-capable wire attaches them as actual image and
document blocks while the model still reads the size lines. Exactly one image
is carried; the carrying part's line says so, and later image parts keep the
plain "not shown" line. No bytes are ever dumped into the text itself, so a
non-capable wire loses nothing but the channel. Audio stays description-only:
there is no audio channel on a tool result. And because one `replyResult()`
serves both spellings, a bridged `mcp__server__tool` call and a dispatched
`mcp_call` carry the same bytes off the same reply.

Three properties make this safe to turn on by default:

**Nothing changes until something is deferred.** Servers under the floor, and
any server whose `.mcp.json` entry carries `"eager": true` (also accepted:
`"1"`, `"true"`, `"yes"`, `"on"`), keep their first-class bridges
byte-identically. The common small `.mcp.json` sees zero difference — same
names, same schemas, same order. And the pair itself is only built when at
least one tool was actually deferred; a launch with nothing to defer never
meets `mcp_list` at all.

**The decision is per server, not per config.** A fat server pays its own
floor; a small one is not taxed for a noisy neighbour.

**The roster is frozen at launch, like everything else MCP.** The split is
computed once, on the first MCP tool build of the session, and memoised —
mid-session edits to `mcp.json` change the *next* launch, not this one (the
the same freeze law, applied to the dispatcher). `mcp_call` refuses any name not
on the frozen list, even a server added minutes ago, and points at `mcp_list`
to discover the real names.

Permission rules speak both spellings. A restrictive rule written against a
wire name — `Deny mcp__git__drop_table`, `mcp__git__*`, `mcp__*` — binds the
dispatched call, and a rule written against `mcp_call` binds first-class
bridges, because after the deferral they are one capability. Grants translate
narrowly: an exact argument-free `Allow mcp__git__push` follows its tool into
the args form, because there the arguments spell exactly the name the user
granted; a glob grant, an argument-scoped grant, and `Allow mcp_call` itself
cover only what they name — a permissive reading is never re-derived from the
arguments. The gate classifies a
dispatched call by the *named tool's* `readOnlyHint` — a deferred read-only
tool still runs unasked under `auto`, an unhinted one still asks — so moving a
server over the floor cannot silently re-classify its tools. See
[PERMISSIONS.md](PERMISSIONS.md) for the roster this parity covers.

```json
{
  "mcpServers": {
    "notes": { "type": "stdio", "command": "notes-mcp" },
    "monorepo": {
      "type": "stdio",
      "command": "huge-tools-mcp",
      "eager": true
    }
  }
}
```

Here `notes` is priced under the floor and keeps first-class tools; `huge-tools-mcp`
would be deferred, but its `eager: true` opts it back in.

---

## Server auth (`/mcp`)

`src/Commands/McpAuthCommand.php` is a separate, in-chat surface with seven
sub-commands, backed by `McpAuthStore` and `OAuthClientRegistration`:

```
/mcp list                                        show registered servers + auth status
/mcp add <server> [registration-url] [token-url] trigger OAuth registration
/mcp remove <server>                             drop stored credentials
/mcp login <server>                              fork a real login into a background child
/mcp reconnect                                   restart servers admitted this launch
/mcp enable <server> [--persist]                 lift a session refusal, in this process
/mcp disable <server> [--persist]                refuse a declared server for this session
```

This store maps **server URLs to auth entries**. It is independent of
`.mcp.json` — registering auth for a URL does not declare a server, and
declaring an `http` server does not register auth for it. It is no longer
inert: when an `http` server's `url` in `.mcp.json` exactly matches one of
its stored keys, every JSON-RPC request to that server carries the stored
access token as a bearer `Authorization` header. Before attaching, the entry
goes through `OAuthClientRegistration::validAuth()`, so a token inside its
expiry buffer is refreshed against the endpoints saved with it and the
rotated token is persisted. An `Authorization` header you configured in
`.mcp.json` yourself (after env resolution) wins — the store is then not
consulted for that server at all. The key match is exact-string: change the
server URL in your config and you must re-run `mcp auth add` for the new URL.
Credentials registered mid-session take effect at the next launch, the same
stance under which the server list itself is frozen per launch.

The last two verbs route rather than authenticate. `/mcp disable <server>`
refuses further calls to one exact declared server for the rest of the
session; `/mcp enable <server>` lifts that refusal again. The refusal lives
beside the launch deny patterns, never inside them: the settings list
`disabledMcpServers` is fnmatch glob vocabulary frozen at launch, while the
session set is exact names decided by a human mid-session — and a name that
carries metacharacters is refused `--persist` rather than smuggled into the
settings as a wildcard that could cover servers the command never named.
The model-facing tool roster is not re-derived (the same cache law governs
`reconnect`): a disabled server's tools stay listed, calls answer with a
refusal that names the fix, and because per-agent handles fork at dispatch,
the toggle takes effect from the NEXT turn, never one already running.
`--persist` mirrors the decision into the user settings list so the refusal
also greets the next launch. The server process keeps running — refusal is
routing, not teardown — and the panel shows it with the ` · disabled` suffix
below.

### Authorization-code login (`candy-code mcp auth login`)

In chat, `/mcp login <server>` forks the flow below into a detached child: the
authorize URL lands in the transcript, the TUI keeps painting, and stored tokens
attach at the next launch (the live session memoized its store at start). A bare
`/mcp login` prints the shell command for you to run yourself. From a plain shell:

```
candy-code mcp auth login <server> [token-url] [authorize-url] [registration-url] [-- --timeout N]
```

It is the OAuth 2.0 authorization-code flow with PKCE, `S256` only. Step by
step: it discovers the authorization server's metadata and reads
`registration_endpoint` (a server without dynamic registration cannot be used
with this command — `mcp auth add` stays the door for those), `token_endpoint`
and `authorization_endpoint`; the positional overrides fill in or replace any
of the three, and with all three given nothing is fetched. Discovery inserts
the well-known segment between the origin and the server's path, never after
it: for `https://h/mcp` it first asks for RFC 9728 protected-resource metadata
(`https://h/.well-known/oauth-protected-resource/mcp`, then
`https://h/.well-known/oauth-protected-resource`) and, if that names an
authorization server, that issuer's RFC 8414 document; otherwise it asks for
`https://h/.well-known/oauth-authorization-server/mcp`, then
`https://h/.well-known/oauth-authorization-server`. The first JSON object that
carries an endpoint wins; an error status or an error body counts as "not
here", so the next form is tried. `mcp auth add` discovers through the same
order. Credentials are still stored under the server URL exactly as you passed
it, which is what the bearer match needs. It binds an ephemeral loopback listener on `127.0.0.1` only
(never a wildcard interface), registers the client with that exact
`http://127.0.0.1:<port>/callback` redirect URI (RFC 7591: the client
metadata is the top-level JSON body, and only `client_id` is required back —
a server without RFC 7592 management simply leaves the registration
un-updatable), prints the authorization URL
— which carries only public values: the client id, the state, and the SHA-256
challenge, never the verifier — and waits for the browser to bounce back.
The callback is judged on GET method, exact `/callback` path, and a
`hash_equals()` state comparison, and the browser is answered with a static
page that echoes nothing of the request. The code is then exchanged with the
verifier, and the entry is stored in exactly the shape `mcp auth add` writes
— including the endpoint carries, so refresh works from the next launch.
The entry also records the exact callback binding it was registered under.
The request-attachment path never consults that record (it holds only the
server URL, no binding to declare); a flow that presents a different loopback
binding than the stored one is answered with no credentials — the stored
value is the authority, because it is the one the authorization server still
remembers, and only a re-login changes it. A row that recorded no binding —
every `mcp auth add` row, and every login stored before this field existed —
constrains no flow at all.
The wait is bounded by 300 s; `--timeout` (as a verb operand after the `--`
separator) changes the budget. It bounds a human leg, not a provider request,
which is why it is a plain socket deadline. An entry that arrives without a
refresh token is served as-is until it expires; after that, requests to the
server fail with an error asking you to re-run
`candy-code mcp auth login <server>`. Nothing is re-registered or written
behind your back — the store only changes after a token exchange succeeds,
so a failed refresh leaves the stored entry as it was. A token response
without `expires_in` (the field is optional in OAuth 2.0) is stored with an
unknown expiry and sent until you log in again: there is no deadline to
refresh ahead of, and a server's 401 does not trigger a refresh yet.

A served session runs the same fork on the server host. `/mcp login
<server>` typed into `candy-code serve` arms the child there and the
follow-up rows arrive over your subscription as notice events: the link
once the child prints it, then the closing row. Your browser is a different
machine than the child's loopback listener, so the browser's redirect cannot
land on it — when it shows a connection-error page, copy that page's address
and send `/mcp login <server> --code <that-url>`. The pasted address is
judged against the callback port the child itself announced: plain `http`,
the host `127.0.0.1` or `localhost`, that exact port, each comparison
constant-time; a passing address receives one bounded local request through
which the waiting login finishes the exchange itself — the code rides
through untouched for the child to verify. Anything failing a check is
refused with its reason named and nothing dialed, and no refusal echoes the
URL, because the URL carries a live authorization code.

Stating the risk plainly: a client holding the `Write` scope can start a
login child on the server host and drive it until credentials land in the
server's own store — the same power `command.exec` already carries, which is
why both verbs sit behind that scope. The only address the login ever dials
is the child's own announced loopback port; a pasted host, scheme, or port
other than the child's is refused before any socket is opened.

## Resources as `@mcp:` mentions

A turn can pull a named resource out of a running MCP server with the
keyword mention `@mcp:<server>:<uri>`. The second colon is load-bearing:
the form is a keyword leg, not a path, so `@mcp:notes` on its own is not a
mention at all — it reads as a file path. Resolution happens at submit
time, bounded like every other keyword leg, and a resolved mention rides
the resumed transcript as a saved attachment.

Parity across all three surfaces (dated 2026-10-28): a `serve` session
and a headless `-p` run arm their mention scanner with the same MCP
reader the TUI uses, so the form resolves wherever a client is open for
the project — and where none is, the scanner's one-line refusal names the
mention instead of silently dropping it.

The bytes are untrusted. A fetched resource is attached inside the same
framed context block other fetched content uses, opening with a header
that names the server and the resource and tells the model to treat it as
data to read, never as instructions to follow. Text resources only: a
resource whose contents report binary is refused as a whole, a resource
with no readable text is refused, and a very large one is clipped to the
first 256 KiB with a notice saying so. When the mention cannot be served —
no client is open in this run, the server is unknown or not allowed for
this agent, or the read failed — the transcript shows a one-line refusal
naming the mention and the reason, and the rest of the turn is sent. The
keyword legs share one per-prompt cap: at most five of them attach to a
single prompt.

A client of the `serve` protocol can see which resources a running
session's servers offer without touching them: `mcp.resources` answers
the launch-frozen roster — per allowed server, each resource's uri, name,
optional mime type and a bounded description — in one read that starts no
server and asks none a second question. Without a memoised client for the
session's root it answers `not-started` with an empty list, so a popup can
ask speculatively. These rows are display data, never enforcement: the
strict per-call read above remains the live gate.

## Prompts as slash commands

A server that exposes prompts contributes slash commands. At construction —
once, before the first keystroke — the session asks each allowed server for
its prompt roster and maps every prompt to a command named
`/mcp__<server>__<prompt>`. Each side of the name goes through the same
sanitizer that scopes MCP tool names, so a prompt called `deploy app`
arrives as `/mcp__srv__deploy_20app`, and a name collision inside one
launch keeps the first roster row it saw. The roster is frozen with the
session: a server that adds prompts mid-session is picked up at the next
launch, never between keystrokes.

These commands live beside the file-based ones under one precedence rule:
a project command file beats a user command file beats an MCP prompt of
the same name. The prompt body is fetched at submission, not at launch —
the `prompts/get` exchange happens when the command is run, riding the
ordinary MCP request deadline rather than any separate clock. A prompt
whose fetch fails, or whose messages carry anything but text, expands to
nothing: the line returns to the input with a refusal notice instead of
sending an empty turn. Positional arguments on the command line map onto
the prompt's declared arguments in order, required first.

A served session keeps the same rule for its own command table: the host
merges the prompt roster under the file rows at construction, and
materialises the fetched body at admission — on the forked route inside
the child that parks the expansion, so the round trip is paid there. A
failed fetch leaves a visible refusal in the transcript instead of
admitting an empty expansion. The `serve` protocol answers from that same
table: `command.list` lists prompt rows beside file rows and marks them
`source: "mcp"`, and `command.exec` admits them, so the web popup's `/`
menu can show the session's MCP prompts with no browser-side MCP
knowledge at all.

A fetched prompt body is third-party text, not user intent: its inline
shell forms are refused outright, whatever this project trusts — a
`trustedProjectCommands` entry vouches for the checkout's own files, and a
body fetched over the wire is not one of them (a per-origin opt-in for this
refusal is the planned follow-up). The rest of the prompt is sent, and the
notice left in a refused form's place names the wire as where the text
came from.

## Serving MCP

`McpServer` is the interface for any tool provider a session can call, and
most of its implementations are CLIENT transports: `StdioMcpServer`,
`HttpMcpServer` and `SseMcpServer` talk to servers some OTHER program hosts. What CandyCode
serves itself is `GitMcpServer` (with `GitCommandHandlers`) — pure-PHP git
tools that run in-process. It
is reachable as a `type: git` entry in your own `.mcp.json`, i.e. CandyCode
serving git operations to itself. `candy-code serve` is not one of them: it is
the WebSocket server the web UI drives ([SERVER.md](SERVER.md)), and it speaks
no MCP of its own. The verb that does is `candy-code mcp serve`: the stdio host
in `MCP\Serve\McpServeHost`, one JSON-RPC frame per line in and out until stdin
closes. It answers `initialize` and `tools/list` with the roster
`MCP\Serve\McpServeRoster` builds — Read, Glob, Grep, Ls, RepoMap, the read-only
tools classified for this purpose — and every call runs jailed to the launch's
root, through the same permission gate a headless turn answers to and the same
`PreToolUse` hook chain the TUI builds, so the containment rules the git server
spells out below are not the only floor under it. A client spawns it the way it
spawns any stdio server:

```json
{
  "mcpServers": {
    "candy-code": {
      "command": "candy-code",
      "args": ["mcp", "serve", "--root", "/path/to/project"]
    }
  }
}
```

Every git tool argument comes from the model, so the git server bounds what
the model can aim it at:

- **`path` is contained to the configured root.** The root is the entry's
  `path`, or this project when that is omitted. A per-call `path` is resolved
  against that root, and anything that does not resolve inside it is refused
  before git runs: `/`, `../other`, a symlink pointing out, or a directory
  that does not exist. The model cannot point the tools at another repository
  on disk.
- **Values are never parsed as git options.** A ref, commit, branch name,
  config key, checkout target, worktree path, LFS pattern or git-flow name
  that starts with `-` is refused. Branch names must also pass
  `git check-ref-format --branch`. Where git honours one, the argv also
  carries `--end-of-options` or `--` before the value. Files given to
  `gitAdd` and `gitBlame` go after `--`, so a file named `-A` is matched as a
  path and never read as a flag.
- **`gitWorktreeAdd` creates worktrees beside the root or below it.** The new
  worktree's parent directory must already exist inside the directory that
  holds the root, so `../myproject-feature` works and `/elsewhere/wt` is
  refused. `gitWorktreeRemove` is not bounded the same way because git only
  removes worktrees that this repository has already registered.
- **Tool calls may only use the parameters the tool declares.** Unknown or
  positional arguments are refused.

Git itself, hooks included, runs under three guarantees:

- **Each call is bounded.** A git command, including the hooks `gitCommit`
  runs, gets 300 seconds unless the entry's `timeout` says otherwise: set it
  (in seconds) higher for a repository whose pre-commit hook runs a long test
  suite, or lower to hear about a hung hook sooner. Only a positive number is
  read; anything else — `0`, a negative, `"10m"` — keeps the 300-second
  default, so the config cannot switch the bound off. When the bound runs
  out, git and every process its hooks started are killed, and the call fails with an error that says it timed out
  and includes the end of git's stderr. Hook output never stalls a call,
  whatever its size. A failure keeps the last 64 KiB of stderr.
- **Git inherits your environment.** `SSH_AUTH_SOCK`, `GNUPGHOME`, `LANG` and
  your `GIT_*` settings reach git and its hooks, so SSH remotes and signed
  commits keep working. Credential prompts are switched off
  (`GIT_TERMINAL_PROMPT=0`), because no terminal is attached to answer them.
  A prompt fails with a message on stderr instead of hanging. `GPG_TTY` is
  dropped, as it is for every child process CandyCode starts. gpg therefore
  needs a cached passphrase or a graphical pinentry.
- **Your environment cannot redirect git to another repository.**
  `GIT_DIR`, `GIT_WORK_TREE`, `GIT_INDEX_FILE` and the other variables that
  `git rev-parse --local-env-vars` lists are removed. Every call runs against
  the contained root.

`candy-code --help` lists exactly fourteen under its **Subcommands** heading —
`doctor`, `import claude`, `login <provider>`, `models`, `session list|show|rename|delete|pin|unpin|archive|unarchive`, `mcp list|trust|import|serve`, `lsp install|list`, `serve`,
`self-update`, `attach`, `acp`, `bundles list|install`, `completion bash|zsh|fish`, `usage [7|30|all]` — and none of those but `attach`
and `acp` needs a provider, an API key or a terminal; `login` answers with the
criterion's own tool or a written key; eleven answer and exit, and
`serve` runs until it is stopped; `mcp serve` is the same kind of long-runner,
nested one word inside the `mcp` verb. `self-update` exits too, but it is the one that
rewrites the running file: a phar-only swap verified against the release sidecar.
`attach` runs the TUI on a session of a
running `serve`, whose turns run there; `acp` runs turns for an editor that
started it, speaking the Agent Client Protocol on stdio.

Fourteen is the subcommand count, not the count of bare words argv treats specially:
`run` is a fifteenth (the `$arg === 'run'` arm in `Cli\ArgvParser`,
`candy-code run "<prompt>"` in the help's Usage block), but it is an alias for
`-p` and therefore a turn of conversation rather than a question about the
install.

## See also

- [`PERMISSIONS.md`](PERMISSIONS.md) — `mcp__*` rule patterns, the other three
  `trustedProject*` keys.
- [`TROUBLESHOOTING.md`](TROUBLESHOOTING.md) — "my MCP tools are missing".
- [`SETTINGS.md`](SETTINGS.md) — `mcpResultCapBytes` and the `claudeMcp*` keys.
- The [README](../README.md#documentation-index) — every other page.
