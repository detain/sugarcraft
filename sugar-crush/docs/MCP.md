# MCP servers

SugarCrush reads one Model Context Protocol config file — `.mcp.json` at the
project root, the same filename and the same `mcpServers` key Claude Code uses —
and exposes each tool a configured server advertises to the model as an ordinary
tool.

Nothing starts unless you have opted this project in. That gate is the first
thing to understand, because starting an MCP server *is* code execution and it
happens at launch, before any tool call and in every permission mode.

---

## The trust gate

`Bootstrap::mcpConfigDecision()` is the one discovery path — `mcpClient()`,
`mcpServerInventory()` and `sugarcrush mcp list` all come through it, so the
listing can never report a verdict the launch does not act on. It returns one of
four statuses:

| Status | Meaning | What happens |
|---|---|---|
| `absent` | no `.mcp.json` at the root | nothing; one `stat()` and out |
| `outside-tree` | present, but it resolves outside the checkout | refused, reported at launch |
| `untrusted` | present and contained, but this root is not trusted | not started, reported at launch |
| `trusted` | present, contained, and this root is listed | servers may start |

To opt in, add the canonical project root to `trustedProjectMcp` in
`~/.sugar-crush/config.json`:

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

**There is no user-level `.mcp.json`.** Adding one would mean choosing a
precedence between a file the repository picks and a file you pick, for a config
whose entries `proc_open()` arbitrary commands. Its absence is a decision.

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
      "startTimeout": 120
    },
    "issues": {
      "type": "http",
      "url": "https://mcp.example.com/sse",
      "headers": { "Authorization": "${ISSUES_TOKEN:-}" }
    },
    "repo": {
      "type": "git",
      "path": "/home/you/src/myproject"
    }
  }
}
```

Three types, and they are the three `McpClient::startServer()` constructs:

| `type` | Class | Keys |
|---|---|---|
| `stdio` (the default when `type` is absent) | `StdioMcpServer` | `command`, `args`, `env`, `startTimeout` |
| `http` | `HttpMcpServer` | `url`, `headers` |
| `git` | `GitMcpServer` | `path` (omitted → this project) |

Any other `type` **throws**, and that throw is ordering-dependent: servers
listed *earlier* in the file are already up, servers listed *after* the bad
entry are never reached. The throw is caught in `Bootstrap::mcpClient()`,
reported through `error_log()`, and the launch continues with fewer tools rather
than dying over a live TUI.

### Supported transports

The MCP specification names more transports than this port builds. The honest
census, table first:

| Transport | Works here | Config it takes | How it runs |
|---|---|---|---|
| Stdio | yes | `command`, `args` (+ optional `env`, `startTimeout`) | spawned child, JSON-RPC over pipes |
| HTTP | yes | `url` (+ optional `headers`) | stateless POSTs; a stored OAuth bearer attaches per request, and a static `Authorization` you set in the config wins over the store |
| Git | yes | `path` (omit → this project) | in-process — no transport, no child, no socket |
| SSE | **no** | — | spec-named and not implemented: `"type": "sse"` falls to the factory's default arm and throws `Unknown MCP server type: sse` — a config-error report, deferred until every OTHER entry has been attempted, so one `sse` entry costs only its own server |

The `sse` row is written because the failure used to be silent-fast: before
`startServer()`'s guard was widened, that one unrecognized entry disabled
every server listed after it in the same file, with no report at all. An
`http` entry whose URL happens to end in `/sse` is unaffected — the transport
is the `type`, never the URL.

`startTimeout` is **seconds, per server, and bounds the handshake only** — a
`tools/call` is unbounded. Only a positive number is honoured; a string, `0` or
a negative falls back to `StdioMcpServer::DEFAULT_START_TIMEOUT_SECONDS`, which
is `60.0`. A hand-edited config must not be able to turn the bound off by
accident.

### `${VAR}` interpolation — exactly where it works

`McpClient::resolveEnv()` expands `${VAR}` and `${VAR:-default}`, and it is
applied to **two keys only**: a `stdio` server's `env` map and an `http`
server's `headers` map. It is *not* applied to `command`, `args`, `url` or
`path`.

The pattern is anchored (`/^\$\{(.*?)(?::-(.*))?\}$/`), so the placeholder must
be the **whole value**. `"Bearer ${TOKEN}"` is passed through literally, with
the braces intact — write `"${BEARER_HEADER}"` and put the whole header in the
variable instead.

An unset variable with no `:-default` resolves to the empty string. Note the
resolution is `getenv($name) ?: $default`, so a variable set to `0` or to the
empty string also takes the default.

This is a *different* mechanism from the `${VAR}` expansion `ProviderFactory`
performs on provider config — see [`ENVIRONMENT.md`](ENVIRONMENT.md#variables-read-from-any-config-file).

---

## Listing without starting

```sh
sugarcrush mcp list
sugarcrush mcp list --output-format json
```

`Bootstrap::mcpServerInventory()` contains **no `proc_open()`**, and that is the
entire design constraint. Routing a listing through `mcpClient()` would mean
`sugarcrush mcp list` launches every program the repository names — the exact
act the trust gate exists to make deliberate, performed by the command an
operator runs precisely *because* they do not trust the file yet.

The consequence, stated because it is a real limit: the listing reflects the
**config, not liveness**. Nothing here can tell you a declared server would fail
to start; finding that out means starting it.

`sugarcrush doctor` reports the same verdict as one `mcp config` line: `OK` for
absent or trusted, `WARN` for untrusted, `FAIL` for out-of-tree or undecodable.

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
| ` · ready N tools` | an `http` server whose handshake completed |
| ` · ready (in-process) N tools` | the in-process `git` transport, up |
| ` · not up` | declared, but this process never started it — a failed `start()` is skipped silently at launch, and this is where that skip surfaces |
| ` · state unknown` | a transport the snapshot could not classify — unknown stays unknown |

The block closes with `Live in this process: K of M declared (other sessions'
servers are not visible here)`. The scope is honest: the readout is per
process, so a second sugar-crush session or a sub-agent worker keeps its own
servers to itself. A server this process started that the config no longer
declares gets its own line (`Started but no longer declared: name (N tools)`)
rather than vanishing from the count.

A live session also digests `.mcp.json` at the instant it memoises its client.
The verdict rides inside the liveness block above, so it renders only while
this process has at least one started server to report — a session that
started no servers shows no liveness block, hence no verdict line even after
its config drifts. While that block is rendering and the file's bytes no
longer match the digest taken at launch, the panel adds one line:
`Config: changed since launch — restart sugar-crush to apply (reload is not
implemented)`. That is a DETECTION, not a fix, and the fix was weighed and
declined (E703 option β): re-reading the file in-session would relaunch
servers under a root grant the process already holds, which re-arms exactly
the prompt-injection → `proc_open()` path the once-per-process freeze closes —
the trusted-roots list is read **once per process and frozen** above, and the
digest keeps that law whole by telling you when it has gone stale rather than
silently honouring new bytes under an old decision. And the panel stays
display-only, the E689 prohibition on inventing a second persistence seam
standing: writing the grant has exactly one home, and neither a digest row
nor a button belongs to it. Restart is your decision, not the panel's.

On an untrusted or absent root the whole block is suppressed even if a map
arrives — the panel will not hand back, through "what this process started",
the same roster the discovery gate withholds. And in a cold process (the
one-shot `sugarcrush mcp list`, or any session that started nothing) there is
no memo to read and the panel renders exactly as it did before this readout
existed — byte for byte.

---

## What the model sees

`Bootstrap::mcpTools()` wraps each advertised tool in an `McpToolBridge`
(`src/Tools/McpToolBridge.php`) and appends it to the tool list. Bridge names
follow the `mcp__<server>__<tool>` convention, which is what permission rule
patterns like `mcp__git__*` match — see [`PERMISSIONS.md`](PERMISSIONS.md).

The client is built `unrestricted: true`, which is the opposite of what it looks
like. `McpClient::listTools()` fails *closed* without an `AgentPreset`, and the
main agent has no preset — that mechanism is *meant* to scope sub-agents, and
the client-side arm still has no production caller: per-preset `mcpServers`
allowlists are enforced one layer up instead, at grant-resolution, where the
preset actually is known — `AgentManager::resolveGrantedTools()` consults
`McpRouter::serverAllowed` (the router's own membership law) and drops bridges
whose server the preset does not name, so the narrowed roster is what the
sub-agent's provider request advertises (E696). An empty or absent list is
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
in five of the six permission modes and diverges under `plan`, where `Bash` is
allowed for exploration and every `mcp__*` name is denied as a write tool — i.e.
in the conservative direction.

No `denyPatterns` are passed on this path, deliberately: `McpClient` consults
them only through `router()`, which only the `AgentPreset` arm reaches, so they
would be inert here. Deny patterns belong to the sub-agent path — whose
allowlist half is now enforced at grant-resolution (above); the deny half is
still minted and unwired, because no settings producer feeds it (E696).

---

## Server auth (`/mcp`)

`src/Commands/McpAuthCommand.php` is a separate, in-chat surface with three
sub-commands, backed by `McpAuthStore` and `OAuthClientRegistration`:

```
/mcp list                                        show registered servers + auth status
/mcp add <server> [registration-url] [token-url] trigger OAuth registration
/mcp remove <server>                             drop stored credentials
```

This store maps **server URLs to auth entries**. It is independent of
`.mcp.json` — registering auth for a URL does not declare a server, and
declaring an `http` server does not register auth for it. It is no longer
inert: when an `http` server's `url` in `.mcp.json` exactly matches one of
its stored keys, every JSON-RPC request to that server carries the stored
access token as a bearer `Authorization` header. Before attaching, the entry
goes through `OAuthClientRegistration::getValidAuth()`, so a token inside its
expiry buffer is refreshed against the endpoints saved with it and the
rotated token is persisted. An `Authorization` header you configured in
`.mcp.json` yourself (after env resolution) wins — the store is then not
consulted for that server at all. The key match is exact-string: change the
server URL in your config and you must re-run `mcp auth add` for the new URL.
Credentials registered mid-session take effect at the next launch, the same
stance under which the server list itself is frozen per launch.

## Serving MCP

`src/MCP/` also contains the *server* halves — `McpServer`, `GitMcpServer` with
`GitCommandHandlers`, `HttpMcpServer`, `StdioMcpServer`. `GitMcpServer` is
reachable as a `type: git` entry in your own `.mcp.json`, i.e. SugarCrush
serving git operations to itself. There is no `sugarcrush serve` subcommand.
`sugarcrush --help` lists exactly five under its **Subcommands** heading —
`doctor`, `models`, `session list|delete`, `mcp list`,
`completion bash|zsh|fish` — and those five are the ones that answer and exit
without a provider, an API key or a terminal.

Five is the subcommand count, not the count of bare words argv treats specially:
`run` is a sixth (the `$arg === 'run'` arm in `Cli\ArgvParser`,
`sugarcrush run "<prompt>"` in the help's Usage block), but it is an alias for
`-p` and therefore a turn of conversation rather than a question about the
install.

## See also

- [`PERMISSIONS.md`](PERMISSIONS.md) — `mcp__*` rule patterns, the other three
  `trustedProject*` keys.
- [`TROUBLESHOOTING.md`](TROUBLESHOOTING.md) — "my MCP tools are missing".
