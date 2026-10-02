# 15e — Defect audit: agents, sessions, MCP, CLI (sugar-crush)

Scope: `src/Agents/`, `src/Workflows/`, `src/Session*`, `src/Sessions/`, `src/MCP/` (+ `sugar-mcp` transport it delegates to), `src/ClaudeCodeMcpClient.php`, `src/Cli/`, `bin/`.
Baseline excluded: everything in `99-synthesis.md` Part II (not re-reported).
Status: **FINAL.** Sections A–D are from the first pass. Sections E–H were added when the audit resumed. What was read, what was only skimmed, and how each open lead was settled is listed under **Coverage** at the end.

Repro scripts live in `/home/sites/crush-research-repos/_audit-scratch/15e/` (each named per finding below). All repros were run against the working tree at 2026-10-01 (PHP 8.3, sugar-mcp symlinked from the monorepo), with a scratch `HOME` (never the real `~/.sugar-crush`). Every server, daemon and child process the repros started was killed afterwards.

---

## A. MCP transports

### MCP-1 — The stdio MCP client cannot talk to the reference TypeScript or Python SDK servers: empty PHP arrays go on the wire as JSON `[]`, not `{}`
- **Severity:** Critical (MCP stdio is the main MCP transport; it is silently non-functional against both official SDKs)
- **Confidence:** Verified-by-repro
- **Where:** `sugar-mcp/src/StdioMcpServer.php:268-288` (handshake), `:376-379` (tools/call), `sugar-mcp/src/McpMessage.php:209` (`json_encode($payload)` with no object coercion). The sugar-crush wrapper `sugar-crush/src/MCP/StdioMcpServer.php:63-71` delegates to it unchanged.
- **Code:**
  ```php
  $response = $this->request('initialize', [
      'protocolVersion' => self::PROTOCOL_VERSION,
      'capabilities' => [],                 // -> "capabilities":[]
      'clientInfo' => $this->clientInfo,
  ], $deadline);
  if ($response === null || (!$response->resultSet && $response->error === null)) { ...throw }
  $this->notify('initialized', null, $deadline);          // spec name is notifications/initialized
  $listResponse = $this->request('tools/list', [], $deadline);   // -> "params":[]
  ...
  $response = $this->request('tools/call', ['name' => $toolName, 'arguments' => $args]); // $args === [] -> "arguments":[]
  ```
- **What happens (measured):**
  - `@modelcontextprotocol/server-everything` 2026.8.31 (SDK 1.31.0): `initialize` comes back as an **error** (`-32603 ... params.capabilities expected object, received array`). The client accepts an error response as a successful start, because the guard only rejects "no result AND no error". `tools/list` with `"params":[]` gets **no response with an id at all**, so `start()` waits the full start timeout (60 s default; 30.1 s with a 30 s timeout in the repro) and ends with **0 tools**. A zero-argument `tools/call` returns `-32603 expected record, received array`.
  - Python `mcp` FastMCP server: `initialize` returns `-32602 Invalid request parameters`; `tools/list` with `[]` produces only an "Internal Server Error" log notification and no id-bearing reply. Through the client: start took 20.1 s (timeout), **0 tools**, and both `ping()` and `echo()` returned `{"error":"Tool call failed"}`.
  - The same handshake with `{}` works on both servers (raw wire test, same script).
  - The notification name is also wrong: `initialized` rather than `notifications/initialized`. Python tolerates it; the TS SDK ignores it, so `oninitialized` never fires.
- **User impact:** every `.mcp.json` stdio server built on the official SDKs (that is, most of them) shows up as "started" with no tools after blocking launch for up to 60 s per server. Nothing is reported, because a runtime start failure is skipped on purpose (`McpClient::startServer` `:356-360`), and this one doesn't even throw.
- **Docs contradicted:** `docs/MCP.md:132` lists Stdio as "Works here: **yes**", and `docs/MCP.md:205-215` gives `npx -y @spences10/mcp-searxng-ultimate` (an official-SDK server) as the worked example. `docs/MCP.md:293-300` says the `/mcp` inventory shows ` · up N tools` for a running stdio child. Here it shows ` · up 0 tools`.
- **Fix:** encode empty maps as objects: `'capabilities' => new \stdClass()`, omit `params` for `tools/list` (or pass `new \stdClass()`), and send `'arguments' => $args === [] ? new \stdClass() : $args`. More generally, make `McpMessage::toJson()` emit `params` as an object when it is an empty array. Send `notifications/initialized`. Treat an `initialize` reply carrying `error` as a start failure and include `error.message` in the exception.
- **Test:** an integration test (gated on `node`) that spawns `server-everything stdio` through `SugarCraft\Crush\MCP\StdioMcpServer` and asserts `count(listTools()) > 0`, start time < 5 s, and that a no-arg tool call succeeds. Also a unit test on `McpMessage::request('x', 'tools/list', [])->toJson()` asserting `"params":{}` (or that params is absent).
- **Repro:** `mcp_everything.php`, `mcp_py.php`, `pyserver.py` (plus the inline raw-wire `printf | node` / `uv run` commands).

### MCP-2 — `HttpMcpServer` cannot complete a Streamable HTTP handshake (no `Accept` header, no session id, same `[]` encoding)
- **Severity:** High
- **Confidence:** Verified-by-repro (406). The session-id and SSE-body points are Verified-by-reading.
- **Where:** `sugar-crush/src/MCP/HttpMcpServer.php:34-41`, `:108-119`, `:148-160`
- **Code:**
  ```php
  $this->rpc('initialize', ['protocolVersion' => '2024-11-05', 'capabilities' => [], ...]);
  $response = $this->rpc('tools/list', []);
  ...
  return $this->httpClient->post($this->url, ['json' => [... 'params' => $params], 'headers' => $this->requestHeaders()]);
  ```
- **What happens:** against `server-everything streamableHttp`, `start()` fails with `406 Not Acceptable: Client must accept both application/json and text/event-stream`. Even with that fixed:
  - the `Mcp-Session-Id` response header from `initialize` is never captured or sent back, so stateful servers reject `tools/list` (`Bad Request: No valid session ID`);
  - `notifications/initialized` is never sent;
  - a `text/event-stream` response body is `json_decode`d and treated as "invalid response";
  - the `initialize` reply (including an error) is ignored entirely;
  - the same `"capabilities":[]` / `"params":[]` encoding as MCP-1 applies.
- **Docs contradicted:** `docs/MCP.md:133` lists HTTP as "Works here: **yes**" ("stateless POSTs"). The worked example `docs/MCP.md:211-213` (`context7`, `exa`, `gh-grep` at `…/mcp`) names Streamable HTTP endpoints, which are exactly the servers that answer 406 to a request with no `Accept`. `docs/MCP.md:297` documents a ` · ready N tools` row "whose handshake completed".
- **Fix:** send `Accept: application/json, text/event-stream`. Store `Mcp-Session-Id` and replay it on every request. Send `notifications/initialized`. Parse SSE `data:` frames when `Content-Type` is `text/event-stream`. Encode empty maps as `{}`. Check the `initialize` result.
- **Test:** a node-gated integration test that starts `server-everything streamableHttp` on an ephemeral port and asserts tools > 0. Also a Guzzle `MockHandler` test asserting the `Accept` header and the session-id echo.
- **Repro:** `mcp_http.php` (start the server with `PORT=37411 node .../index.js streamableHttp`).

### MCP-3 — `ClaudeCodeMcpClient::callTool()` gives up after about 1 s, so any `claude-mcp` tool that runs longer than a second fails
- **Severity:** Medium (the `claude-mcp` transport is double opt-in, but when enabled it is unusable for real tools such as Bash, Grep, and Task)
- **Confidence:** Verified-by-repro
- **Where:** `sugar-crush/src/ClaudeCodeMcpClient.php:446-475` (`callTool`) and `:490-516` (`listTools`); handshake at `:424-433`
- **Code:**
  ```php
  $attempts = 0;
  while ($attempts < 100) { $messages = $this->readMessages(); ... usleep(10000); $attempts++; }
  throw new RuntimeException("No response received for request {$id}");
  ```
- **What happens:** `trigger-long-running-operation(duration=3)` on server-everything threw `No response received for request 3` after 1.02 s. The late reply is then dropped. The handshake also sends `initialize` as a **notification** (no id) with `capabilities: {tools: true, resources: null}`, and never sends `notifications/initialized`. This works only because the TS SDK does not enforce initialization. `listTools()` at start has the same ~1 s budget, so a slow-booting `claude mcp serve` would fail to start and be skipped silently.
- **Fix:** reuse the deadline-based `readResponse()` loop from sugar-mcp (no fixed 100 × 10 ms) and give it a generous per-call deadline. Send `initialize` as a request and wait for its result, then send `notifications/initialized`. Better still, replace this class with `SugarCraft\Mcp\StdioMcpServer` plus a spawn planner, as stdio already does.
- **Test:** a node-gated test running a server-everything long operation of about 3 s through `ClaudeCodeMcpServer::callTool` and asserting success.
- **Repro:** `ccmcp.php`

### MCP-4 — One stray non-JSON stdout line aborts the in-flight MCP request
- **Severity:** Low-Medium
- **Confidence:** Verified-by-reading
- **Where:** `sugar-mcp/src/StdioMcpServer.php:571-574`
- **Code:** `$message = McpMessage::parse($line); if ($message === null) { return null; }`
- **Scenario:** a server that prints a banner or log line to stdout, or emits a blank keep-alive line (`readLine()` trims it to `''`), makes `request()` return null. `start()` then fails with "Failed to start MCP server", or `callTool` returns "Tool call failed", even though the real reply arrives on the next line. The reference SDK clients skip unparseable lines.
- **Fix:** `continue` on a null parse; only give up on EOF or the deadline. Optionally log the line into the stderr tail for diagnostics.
- **Test:** a fake PHP MCP server that writes `"hello\n"` before every response; assert that start succeeds and tools are listed.

### GIT-1 — GitMcpServer handlers pass model-controlled values in option position: `gitShow(ref: "--output=/path")` writes an arbitrary file
- **Severity:** High (it turns "read-only" git tools into arbitrary file writes, bypassing whatever permission rule allowed a read-only `mcp__git__gitShow`)
- **Confidence:** Verified-by-repro
- **Where:** `sugar-crush/src/MCP/GitCommandHandlers.php:210` (`['git','show','--format=...','--no-patch',$ref]`), `:404` (`array_merge(['git','add'], $paths)`), `:486` (`git revert $commit`), `:527` (`git reset --mode $commit`), `:625` (`git branch $name`), `:101` (`git config --get $key`), and the checkout, worktree, and lfs arms. None of them insert `--` or reject a leading `-`.
- **Repro result:** `gitShow('--output=<scratch>/pwned.txt')` returned success and **created the file**, containing `7b2a357…|a@b|a|2026-10-01…`. Any path the user can write to (for example `~/.bashrc` or `.git/hooks/pre-commit`) can be overwritten with attacker-influenced bytes, because the commit subject is controlled by whoever wrote the commit. The other arms allow `git add --force/-A`, `git revert --abort`, and similar.
- **Docs make it worse:** the only rules example in `docs/PERMISSIONS.md:144-152` is `Bash: ask`, `mcp__git__*: allow`, `Write: deny`. A user who copies it believes file writes are denied, but `mcp__git__gitShow(ref: "--output=<file>")` is auto-allowed and writes the file. `docs/MCP.md:94` / `:134` describe the git transport as in-process with a `path` that is "omitted → this project". Nothing says the model can point it elsewhere.
- **Dispatch detail:** `GitMcpServer::callTool()` `:430` spreads the model's arguments straight onto the handler as named arguments (`$this->handlers->$method(...$args)`). The handler signatures match the advertised schemas today, so no hidden parameter is exposed. But no layer validates any value: every string reaches `proc_open` argv as written.
- **Related (same root cause, Verified-by-reading):** every tool takes a model-supplied `path` (`GitMcpServer.php` schemas, `:51-290`) that overrides the configured `.mcp.json` `path` (`execGit()` `:1119`: `$cwd ?? $this->cwd ?? getcwd()`), with no containment to the project root. The model can run `gitReset(mode: hard)`, `gitBranchDelete`, or `gitCommit` in **any** repository on disk.
- **Fix:** reject a leading `-` in every positional parameter (refs, branch names, keys, patterns, paths), or place `--end-of-options` / `--` correctly (`git show --format=… --no-patch --end-of-options <ref>`; `git add -- <paths>`). Contain the per-call `path` under the configured repo root (`ContainedPath`).
- **Test:** for each handler, a data provider of `--output=/tmp/x`, `--force`, and `-A` that asserts failure and no file created. Also `path: '/'` and `path: '../other'` should be refused.
- **Repro:** `git_handlers.php` + `gitrepo/`

### GIT-2 — `execGit()` reads stdout to EOF before reading stderr, so a git command with >64 KiB of stderr (for example a chatty pre-commit hook) deadlocks forever
- **Severity:** High (an unbounded hang of the turn or TUI; no timeout exists)
- **Confidence:** Verified-by-repro. The hang is reproduced; the cause is the classic pipe deadlock, established by reading the code.
- **Where:** `sugar-crush/src/MCP/GitCommandHandlers.php:1177-1183`
- **Code:**
  ```php
  fclose($pipes[0]);
  $stdout = stream_get_contents($pipes[1]);   // blocks until git exits
  fclose($pipes[1]);
  $stderr = stream_get_contents($pipes[2]);   // never reached while git is blocked writing stderr
  ```
- **Repro:** a repo whose `pre-commit` hook writes 300 KB to stderr. `gitCommit('msg')` never returned and was killed by `timeout 20` (exit 124). This repo's own Caliber pre-commit hook, and any hook running phpunit, php-cs-fixer, or eslint, easily exceeds 64 KiB.
- **Also:** the env is replaced with only `PATH` and `HOME` (`:1150-1153`), which drops `SSH_AUTH_SOCK`, `GPG_TTY`, `GNUPGHOME`, `LANG`, and `GIT_*`. Signed commits (`commit.gpgsign=true`) and SSH remotes fail, and hooks that need the environment misbehave.
- **Fix:** drain both pipes with `stream_select` (non-blocking) or redirect stderr to a temp file. Add a wall-clock timeout with a terminate ladder (`BoundedShutdown`). Pass `getenv()` filtered rather than a two-key env.
- **Test:** a fixture repo with a hook writing 300 KB to stderr; assert that `gitCommit` returns within N seconds and that the failure or success carries the stderr tail.

---

## B. Sessions and persistence

### SES-1 — `/rewind` restores a checkpoint that already contains the prompt being undone: the prompt is left dangling in history AND re-seeded into the input box
- **Severity:** Medium
- **Confidence:** Verified-by-repro
- **Where:** checkpoint capture `src/Chat.php:7964-7990` (`'messages' => $next->history` where `$next->history = [...$baseHistory, ...$newTurnMessages]` and `$newTurnMessages` ends with `Message::user($text)`, `:7470`); restore `src/Chat.php:12358-12385`
- **Repro output:**
  ```
  checkpoint messages: 1   cp user: fix the login bug
  after rewind:
    user: fix the login bug
    user: /rewind
    assistant: Rewound 1 messages to checkpoint 0. ...
  inputBuf: 'fix the login bug'
  ```
- **Impact:** `/rewind` removes only the reply, not the exchange. The model's next turn sees two consecutive user messages (the undone prompt, then the `/rewind` line). If the user presses Enter on the re-seeded draft, the prompt is sent twice. The "Rewound N messages" count is off by one exchange. `RewindCommandTest::testRewindRestoresTheDraftTheSubmitCaptured` asserts the draft but never asserts that the history *excludes* the prompt, which is why this passes.
- **Fix:** checkpoint `$baseHistory` (the pre-turn history) rather than `$next->history`. Alternatively, on restore, drop the trailing user message when it equals the restored `inputBuf`.
- **Test:** submit → rewind; assert `history` contains no `user` row equal to the restored draft.
- **Repro:** `rewind.php`

### SES-2 — `forkSession()` copies only the legacy `messages`/`tool_calls` tables, which nothing writes. The fork has no transcript, no checkpoints, no meta, and inherits the parent's **name**.
- **Severity:** Medium. It is part of the root cause of the known "/fork ignores history" item (#30), plus new consequences.
- **Confidence:** Verified-by-repro
- **Where:** `src/Session/SessionStore.php:210-279`, `src/Session/EnhancedSessionStore.php:74-77`; callers `src/Chat.php:12041` (`/branch`) and `:12125` (`/fork`)
- **Repro output:** parent transcript rows: 2 → `fork transcript: NULL`, `fork checkpoints: 0`, `fork name: my-work`, `messages table rows total: 0`. A grep shows `SessionStore::addMessage` has no caller outside the store.
- **Impact:**
  1. `/fork` hands its background daemon an id whose stored conversation is empty, so even a fixed daemon could not load history.
  2. `/branch` survives only because `persistTranscript()` re-saves the in-memory history under the new id. `/rewind` on the branch has no checkpoints, so it reports "No checkpoints available".
  3. Branch and parent share a name. `getSessionByName()` (`SessionStore.php:190-195`, no `ORDER BY`) then resolves `sugarcrush --resume my-work` to an arbitrary row (in practice the **parent**), not the branch the user is working in.
  4. Named sessions are exempt from `pruneSessions()`, so every branch of a named session lives forever.
- **Fix:** in `EnhancedSessionStore::forkSession()`, copy `session_transcripts`, `checkpoints`, `checkpoint_blobs` (re-keyed to the new session), and `session_meta` in one transaction. Give the fork `"<name> (branch)"` or NULL. Make `getSessionByName()` deterministic (`ORDER BY updated_at DESC`) or enforce unique names.
- **Test:** save transcript and checkpoint, fork, then assert `loadTranscript(fork)` equals the parent's, checkpoints are copied, and the name differs.
- **Repro:** `fork.php`

### SES-3 — No single-writer guard per session: two TUIs on one session (`--continue` twice, or `--resume X` in two terminals) silently clobber each other's transcript and interleave checkpoints
- **Severity:** Medium
- **Confidence:** Verified-by-reading
- **Where:** `src/Session/EnhancedSessionStore.php:872-889` (`INSERT OR REPLACE INTO session_transcripts`), `:469-502` (`SELECT MAX("index")+1` then `INSERT`, outside a transaction, with no UNIQUE(session_id,"index")); `Bootstrap::openSession` `src/Cli/Bootstrap.php:3186-3216`
- **Scenario:** `--continue` in two terminals opens the same newest session. Each TUI rewrites the whole transcript on every history change, and the last writer wins, so terminal A's conversation disappears on the next `--continue`. Both TUIs append checkpoints to the same session, so `/rewind` in A can restore B's state. Concurrent saves can produce duplicate `"index"` values; `getCheckpoint()` then returns an arbitrary one and `restoreCheckpoint()` deletes both.
- **Also (former lead 4, Verified-by-reading; no two-process repro was run):** blobs are per session (`UNIQUE(session_id, hash)`, `:184-193`), so the interning race needs this same two-writers setup. `internMessages()` (`:676-742`) checks `PRAGMA data_version` once (`forgetInternedBlobsIfStale()`) and then trusts its in-memory `hash → id` cache. If the other TUI runs `/rewind` in between, its `collectCheckpointBlobs()` (`:1075-1113`) deletes those ids. The checkpoint or transcript then stores ids whose blob rows are gone, so later loads return the conversation with those messages missing. The per-session lock in the fix below closes this too.
- **Fix:** take an advisory lock per session (a `flock` on `<configDir>/sessions/<id>.lock`, or a `sessions.owner_pid` and `owner_start` column checked at open). When the session is held, refuse, or fork automatically. Add `UNIQUE(session_id,"index")` and wrap index allocation and insert in `BEGIN IMMEDIATE`.
- **Test:** two `EnhancedSessionStore` instances on one DB saving interleaved transcripts; assert a conflict is detected rather than lost. Add a unique-index test for checkpoints.

### SES-4 — Checkpoint blobs are garbage-collected only on `/rewind`, so pruned checkpoints and compacted history leave orphan blobs forever
- **Severity:** Low
- **Confidence:** Verified-by-repro
- **Where:** `src/Session/EnhancedSessionStore.php:1118-1141` (`pruneOldCheckpoints` deletes rows only); the only GC call site is `restoreCheckpoint()` `:1066`
- **Repro:** 150 checkpoints with a simulated compaction every 30 turns left 100 checkpoints, **150 blobs, 121 referenced**: 29 orphans, each potentially a 1 MiB tool result. On long sessions with compaction this grows without bound until the session is deleted.
- **Fix:** call `collectCheckpointBlobs()` from `pruneOldCheckpoints()` when rows were deleted (it is cheap: one scan per session).
- **Test:** the repro loop, asserting `count(blobs) == count(referenced)`.
- **Repro:** `blobs.php`

### SES-5 — Mixed local and UTC timestamps in one DB
- **Severity:** Low
- **Confidence:** Verified-by-reading
- **Where:** `EnhancedSessionStore::saveCheckpoint` `:495` uses `(new \DateTimeImmutable())->format(...)` (process timezone). Everything else uses `CURRENT_TIMESTAMP` or `gmdate` (UTC). `saveSessionMeta` `:252` uses whatever timezone the caller's DateTime has. `listSessionsWithMeta` `:267` orders by `COALESCE(sm.last_activity, s.updated_at)`, which mixes the two.
- **Impact:** checkpoint `created_at` shown in `/rewind` listings is off by the UTC offset, and ordering across meta and session rows is skewed by the offset.
- **Fix:** use `gmdate('Y-m-d H:i:s')` everywhere.

### SES-6 — `getMessages()` orders by `created_at` (one-second resolution) with no tiebreaker
- **Severity:** Low (latent: the table is currently dead; see SES-2)
- **Where:** `SessionStore.php:398-400`
- **Fix:** `ORDER BY created_at ASC, id ASC`.

---

## C. Background sessions and daemons

### BG-1 — Nothing can stop a background session: the daemon's `STOP` command has no sender, so `/bg` tasks run up to 3600 s (and keep running after the TUI exits)
- **Severity:** Low-Medium (new relative to #30, which covers results not returning)
- **Confidence:** Verified-by-reading
- **Where:** `src/Sessions/BackgroundSessionRunner.php:656-661` handles `STOP`. `grep -rn 'STOP' src` finds no client that sends it, and `BackgroundSupervisor` has no stop/cancel/kill method (`src/Sessions/BackgroundSupervisor.php`, whole file). The daemon is `setsid` double-forked (`:462-469`), so quitting the TUI does not reach it.
- **Impact:** a mistaken `/bg rm -rf …`-class task, or a runaway token-spending task, cannot be cancelled from the product. It runs with whatever permission mode the daemon resolved (the default is bypass) for up to an hour.
- **Fix:** add `BackgroundSupervisor::stopSession($id)`, which connects, sends `AUTH`, sends `STOP`, and falls back to signalling the recorded pid after verifying start time. Wire `/bg stop <id>` and the agents pane `s` action to it.
- **Test:** spawn with a fake backend that sleeps, call `stopSession`, and assert the `[session:task:stopped]` record and process exit.

### BG-2 — Per-launch IPC directories under `/tmp` are never removed
- **Severity:** Low
- **Confidence:** Verified-by-reading
- **Where:** `BackgroundSupervisor::ensurePrivateIpcDir` `:606-637` creates `/tmp/sugar_crush_bg_<uid>_<random>`. The runner unlinks only the socket (`BackgroundSessionRunner.php:797`). The `.buffer`, `.buffer.log` and `.token` files and the directory itself remain after completion, and `ToolIpcFiles::sweep` does not glob this prefix.
- **Fix:** have the supervisor delete the session's files after `reapFinishedDaemon()` has absorbed the buffer, and sweep stale `sugar_crush_bg_<uid>_*` directories at boot.

### TMP-1 — Fixed, non-per-user `/tmp` directory names: the first user on a shared host permanently disables Task resume (and hook-overflow retention) for every other user
- **Severity:** Low-Medium (availability on multi-user hosts; the security side is already handled by refusing)
- **Confidence:** Verified-by-reading
- **Where:** `src/Agents/SuspendedDelegations.php:47,68-71` (`sys_get_temp_dir() . '/sugarcrush-suspended-delegations'`), `src/Support/HookContextFiles.php:97` (`'sc-hook-ctx'`), and `verifiedDirectory()` `:210-247`, which refuses a directory owned by another uid
- **Scenario:** user A runs sugarcrush, so `/tmp/sugarcrush-suspended-delegations` is created 0700 and owned by A. User B on the same box gets `RuntimeException` (code 7) on every `save()`. Every suspended Task run of B's is then unresumable, and the refusal is permanent until A's directory is deleted. A local user can also do this deliberately. The class docblock acknowledges "whoever gets there first decides" but treats it only as a symlink risk.
- **Fix:** include the uid in the name (`…-delegations-<euid>`, as the audit log and background IPC directories already do), or use `$XDG_RUNTIME_DIR` / `<configDir>/run`.
- **Test:** point `SuspendedDelegations` at a directory with mode 0755 or a foreign owner and assert it falls back to the uid-scoped path instead of failing.

---

## D. CLI / headless

### CLI-1 — Headless JSON stdout is not protected from PHP diagnostics: `bin/sugarcrush` never routes `display_errors` to stderr
- **Severity:** Low
- **Confidence:** Verified-by-repro
- **Where:** `bin/sugarcrush` (no `ini_set('display_errors', 'stderr')` and no `set_error_handler` anywhere in `bin/` or `src/Cli/`). The concrete emitter is the unsilenced `mkdir()` in `Bootstrap::ensureDir()` `src/Cli/Bootstrap.php:8036-8040`.
- **Code:** `if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) { throw … }`
- **Repro (`cli_warn.sh`):** with `~/.sugar-crush` mode 0500 and `display_errors=1`, `sugarcrush -p "say hi" --output-format json` exits 0, but stdout begins `\nWarning: mkdir(): Permission denied in …/Bootstrap.php on line 8038` before `{"result":…}`. `display_errors=1` is php-cli's compiled-in default when no php.ini is loaded (`php -n -r 'var_dump(ini_get("display_errors"));'` prints `"1"`). That is the normal case in the official `php:*-cli` Docker images, which ship without a php.ini. On this box the distro ini sets it Off, which is why the first pass saw nothing.
- **Scenario:** `sugarcrush -p … --output-format json | jq` in CI or a container. Any warning on the run (this `mkdir`, a vendor deprecation, a stream warning) is printed before the document, and the consumer's JSON parse fails even though the exit status says success.
- **Fix:** add `ini_set('display_errors', 'stderr');` at the top of `bin/sugarcrush` (keep `log_errors`). Separately, make `ensureDir()` use `@mkdir` and report through its own exception, as its `throw` already intends.
- **Test:** run `bin/sugarcrush` with `-d display_errors=1` against a read-only scratch `~/.sugar-crush`; assert stdout is exactly one valid JSON line.

---

## E. Forked sub-agents (workflows and Task fan-out)

### AG-1 — Forked sub-agents share the parent's MCP connections: concurrent MCP calls from parallel agents get each other's results
- **Severity:** High. Tool results are silently swapped between agents, and each agent then acts on another agent's data. It needs two or more sub-agents running at once that call the same MCP server; that is the normal shape of a parallel workflow stage or a multi-Task fan-out.
- **Confidence:** Verified-by-repro at the transport level (the product's own `StdioMcpServer`/`McpClient` objects, used across `pcntl_fork()` exactly as the pool uses them). That the live path reaches it is Verified-by-reading.
- **Where:**
  - `sugar-mcp/src/StdioMcpServer.php:111` (`private int $nextId = 0`) and `:416` (`$id = (string) $this->nextId++`): the id counter is copied into every child.
  - `src/Agents/AgentWorkerPool.php:761-792`: the fork branch runs `runStreaming()` in the child, against objects built in the parent.
  - `src/Agents/EngineExecutor.php:173-174`: `$request->tools ?? $this->engine->tools()`.
  - `src/Tools/BuiltIn/TaskTool.php:450`: `$granted ?? $engine->tools()`.
  - `src/Cli/Bootstrap.php:6928`: `...self::mcpTools($root)` puts the bridges into the engine roster.
  - `src/Cli/Bootstrap.php:6262-6281`: `mcpClient()` is memoised per pid, but the bridges a child inherits still hold the parent's client.
  - `Runtime::executeConcurrently` `src/Runtime.php:1853` forks one child per parallel Task call.
- **Mechanism:** each forked child inherits the parent's stdio pipes to the MCP server and the same `nextId`. N children calling at once all write `{"id":"3",…}` to one stdin pipe and race to read one stdout pipe. Each takes the first line it sees, and since every request carries the same id, each accepts whatever line arrives. Guzzle-based `type: http` servers behave the same way, because a keep-alive connection the parent opened is reused by every child over the inherited socket.
- **Repro output:**
  ```
  mcp_fork.php (3 forked children, one shared StdioMcpServer)
  parent call: reply-for-parent (req id 2)
  child2 (0.4s): reply-for-child0 (req id 3)
  child0 (0.9s): reply-for-child1 (req id 3)
  child1 (1.7s): reply-for-child2 (req id 3)
  fork_http.php (Guzzle client used once in the parent, then 3 forked children)
  child1 peer=34384 got echo=child0
  child2 peer=34384 got echo=child1
  child0 peer=34384 got echo=child2
  ```
  Every child received a sibling's answer. A child exiting normally (`exit(0)`, as the pool's worker does) did **not** stop the shared server: `mcp_fork_exit.php` showed the parent still served afterwards. The damage is the cross-wiring alone.
- **Scenario:** `examples/workflows/lint-then-fix.yaml`'s parallel `fix` stage, or "spawn three Task agents to look up these tickets", with a trusted `.mcp.json` server (database, issue tracker, search). Agent A receives B's query result, reports it as its own, and may edit code from it. Nothing errors.
- **Fix:** do not use parent-owned MCP transports in a forked child. Either (a) after fork, give the child its own `McpClient` (re-spawn the servers, or for HTTP build a fresh Guzzle client), or (b) proxy child MCP calls to the parent over the existing IPC channel, so one process owns each server and request ids stay unique. At minimum, record `getmypid()` in `StdioMcpServer` and in `HttpMcpServer`'s client, and refuse calls from a different pid with a clear error instead of cross-wiring silently.
- **Test:** a fake stdio MCP server whose `slow` tool echoes its argument after a random delay. Run two `AgentWorkerPool` agents through `EngineExecutor` whose scripted provider calls `mcp__fake__slow(n=<agent>)`, and assert each agent's result contains its own `n`.
- **Repro:** `mcp_fork.php`, `fake_mcp_server.php`, `mcp_fork_exit.php`, `fork_http.php` + `ka_server.py` (`python3 ka_server.py 37422 &` first).

### AG-2 — One preset file in the Claude Code `tools:` spelling disables every agent preset in every tier
- **Severity:** Low-Medium
- **Confidence:** Verified-by-repro
- **Where:** `src/Agents/AgentPresetRegistry.php:183-205` (`list()` has no per-file isolation), `:389-410` (`arrayToPreset()` passes raw frontmatter values into typed constructor parameters: `tools: $data['tools'] ?? []` where `AgentPreset::$tools` is `array`). The caller `src/Cli/Bootstrap.php:2231-2242` catches the throw and returns **no presets at all**.
- **Code:** `$presets[$name] = $this->parsePresetFile($file);` (no try/catch per file)
- **Repro (`preset_list.php`):** a tier containing `good.md` (`tools: [Read]`) and `reviewer.md` with `tools: Read, Grep, Glob` (the comma-string form Claude Code's own subagent files use) gives `list() threw TypeError: AgentPreset::__construct(): Argument #3 ($tools) must be of type array, string given`. `load('good')` on its own still works.
- **Scenario:** a user copies a `.claude/agents/*.md` file into `~/.sugar-crush/agents/`, or clones a repository whose `.sugar-crush/agents/` has one such file. Every preset, including the user's own from the user tier, disappears for the session. The only signal is one transcript line. Other scalar fields fail the same way (`name: 123`, `permissionMode: 5`), as does a preset missing its frontmatter (a `RuntimeException`).
- **Fix:** parse each file in its own `try`, skip and report only the bad file, and coerce `tools`/`disallowedTools`/`skills`/`mcpServers` from a comma-separated string (the `ForeignAgentPresetRegistry` already knows that dialect).
- **Test:** a two-file tier (one valid, one with `tools: Read, Grep`); assert `list()` returns the valid preset plus one warning naming the bad file.

### AG-3 — `AgentManager` never forgets a sub-agent, so a long session accumulates every workflow and Task agent with its full output
- **Severity:** Low
- **Confidence:** Verified-by-reading
- **Where:** `src/Agents/AgentManager.php:347` and `:1592` add to `$subAgents`. The only removals are `removeSubAgent()` `:1891` (no caller in `src/`) and the projected-row cleanup `:427-431`. `liveOutputs()` `:656-670` iterates the whole map on every frame.
- **Scenario:** a TUI left open for days that runs a workflow per hour. Every `SubAgent` keeps its final `output` (often tens of KB), memory grows without bound, and the per-frame scan grows linearly.
- **Fix:** drop terminal sub-agents after the pane has shown them (or keep the newest N per agent name), the way projected rows are already cleared.
- **Test:** run 200 fake-executor sub-agents through `executeAll()`; assert the map stays bounded and `liveOutputs()` still reports running ones.

---

## F. Workflows

The baseline item "only the first task of a stage runs" (`WorkflowEngine.php:1069`) was run down and is **not a separate defect**. `executeStage()` and `executePipelineStage()` (`:1185`) take `$tasks[0]` on purpose (comment at `:1067`). No public constructor can build a multi-task `stage`: `WorkflowBuilder::stage()` takes exactly one `TaskBuilder` (`WorkflowBuilder.php:53-60`), and a non-parallel YAML stage yields exactly one task (`WorkflowRegistry.php:1382-1392`). `docs/WORKFLOWS.md:195-203` documents the limit. Only a hand-built `Workflow` can hit it. The real workflow defects are below.

### WF-1 — Workflow and task timeouts and retries are never enforced; a stage has no wall-clock bound at all
- **Severity:** Medium
- **Confidence:** Verified-by-reading. `grep -rn -e '->timeout' src/Agents src/Workflows` finds only the writers and `SubAgent::toArray()` `:125`.
- **Where:**
  - `src/Workflows/WorkflowEngine.php:1094, 1206, 1309, 1352, 1539` set `timeout: $task->timeout ?? 300` and `maxRetries: $task->retries ?? 0` on each `SubAgent`. Nothing reads them.
  - `Workflow::$timeout` (`Workflow.php:32`, parsed from YAML `config.timeout` at `WorkflowRegistry.php:1206-1212`) is read by nothing in the engine.
  - `AgentWorkerPool::waitForCompletion()` `:868-975` waits on `WNOHANG` reaps with no deadline. `EngineExecutor::run()` `:153-215` has none either.
  - `AgentPoolConfig.php:34-49` admits retries are a "DORMANT SEAM".
- **Docs contradicted:**
  - `docs/WORKFLOWS.md:63` (`timeout: 900  # positive int; per-stage seconds`).
  - `:81` (default `timeout: 3600`).
  - `:255-261` (the engine "substitutes … `$task->timeout ?? 300` seconds, `$task->retries ?? 0`", which reads as enforced).
  - The PHP example `:245-246` (`->timeout(600)->retries(1)`).
  - The shipped `examples/workflows/lint-then-fix.yaml:19-20,67` (`config.timeout is the per-stage timeout in seconds`, `timeout: 1800`).
- **Scenario:** a stage whose agent runs a Bash command that never exits (Bash has no timeout, Part II #7) or calls an MCP tool that never answers (Part II #11). The workflow sits in the live pane forever. The author's `timeout: 1800` does nothing, a `retries(1)` task that fails is never retried, and the only way out is killing the TUI.
- **Fix:** enforce `SubAgent::$timeout` in the pool. Record a deadline at dispatch; on expiry, `terminateWorker()` and settle `TimedOut` (the status and `buildStageResult()` mapping already exist). Default it from `Workflow::$timeout` instead of the literal 300. Either implement `maxRetries` in `executeAll()` (re-queue on `isFailure()`) or refuse `retries` at load time and remove it from the docs.
- **Test:** a forked executor that sleeps 10 s, a task with `timeout(1)`; assert a `TimedOut` stage result in under 3 s and that the child is gone.

### WF-2 — `/workflow pause` cannot pause a running workflow; resuming a *failed* run skips the failed stage and reports success; the pause file is never cleared
- **Severity:** Medium
- **Confidence:** Verified-by-repro
- **Where:** `src/Workflows/WorkflowEngine.php:479-509` (`pause()` needs a result recorded by `rememberResult()`, which happens only after `run()` returns, and writes `'stagesCompleted' => count($result->stageResults)` at `:501`, a count that **includes the failed stage**); `:547-589` (`resume()` skips `stagesCompleted` stages and never deletes the pause file); `:606-649` (`getStatus()` reads only the pause file); `src/Chat.php:9800-9830` (always prints "resumed and completed").
- **Repro (`wf_pause.php`, 3-stage YAML; stage `b` fails on the first run):**
  ```
  run1: failed id=three-aa8a6eee stages=2
  status after pause: paused
  resume: completed stages=c tokensTotal=0
  prompts run on resume: ["do c using "]      <- b skipped; {{b.output}} is empty
  status after resume: paused                  <- pause file left behind
  second resume re-runs: ["do c using "]      <- resumable forever
  resume of completed run: completed stages=0 prompts=0
  ```
- **Impact:**
  - The only thing `/workflow pause <id>` can act on is a run that has already ended. During a run it says "Run the workflow first", or it snapshots the previous run with that name.
  - The obvious recovery after a failure (pause, fix, resume) silently skips the stage that failed and feeds an empty `{{b.output}}` downstream. It then reports **completed**, with the paused run's tokens and cost dropped from the totals.
  - `/workflow status` says "paused" forever, and each further resume re-runs the tail.
  - Only the SIGINT/SIGTERM handler (`:2131-2167`) writes a correct pause file, because there `stageResults` holds only finished stages.
- **Fix:**
  - Count only successful stages in `stagesCompleted` (`array_filter(…, isSuccess)`).
  - Make `pause()` work on a live run: set a flag that the stage loop checks between stages, and write the file from the live `$stageResults`.
  - Delete the pause file (or mark it `completed`) once `resume()` finishes.
  - Carry the paused tokens and cost into the resumed totals.
  - Have Chat report `failed` when the result failed.
- **Test:** the repro as a PHPUnit case. Assert that the resume re-runs `b`, that status is not `paused` after the resume, and that a second resume throws `WorkflowNotRunningException`.

### WF-3 — `{{agent.results}}` never resolves for a parallel agent (its documented purpose), and a run-context key that equals an agent type crashes the stage after the agent has run
- **Severity:** Low-Medium
- **Confidence:** Verified-by-repro
- **Where:**
  - `src/Workflows/WorkflowEngine.php:1113-1115`: only `executeStage()` writes `$context[$agentName]['results']`, keyed by `$task->name ?? $task->agentType`. A YAML regular stage has no task name, so the key is the agent *type* (`coder`).
  - `:1435`: `executeParallelStage(array $stage, array $context, …)` takes the context by value and records no per-agent results. The same is true of the pipeline and verification stages (`:1132`, `:1266`).
  - `:1911-1916`: the `.results` interpolation.
  - `docs/WORKFLOWS.md:113`: "`{{agentName.results}}` — one parallel agent's output".
- **Repro (`wf_ctx.php`):**
  - A parallel stage with agents `styler` and `checker`, then a stage prompting `styler said {{styler.results}}`. The verify agent receives the literal `{{styler.results}} / {{checker.results}}`.
  - `/workflow run three coder=x` (any `key=val` whose key equals an agent type): stage `a` **fails** with `Cannot access offset of type string on string`. The agent had already run (1 dispatch), and its tokens are dropped from the totals (`tokens=0`).
- **Impact:** the only way to address one parallel agent's output does not work, so authors fall back to `{{fix.output}}`, the concatenation of every agent with no separators. Two regular stages with the default `agent: coder` also overwrite each other's `coder.results`.
- **Fix:** key results by the task name, falling back to the stage name and not to the agent type. Write them from every stage type, including one per parallel agent (`$agentResults[$i]` belongs to `$tasks[$i]`'s name). Keep them in a namespace user context cannot collide with (for example `$context['@results'][$name]`). Refuse a run-context key that shadows one.
- **Test:** the two repro workflows as PHPUnit cases with a fake executor.

---

## G. MCP configuration trust and OAuth

### MCP-5 — Project MCP trust is bound to the root path, not to what `.mcp.json` says: a `git pull` or branch switch that changes a server's `command` runs it at the next launch with no new consent
- **Severity:** Low-Medium (a supply-chain path. The agent-writes-its-own-`.mcp.json` variant is already Part II #9; this one needs no agent)
- **Confidence:** Verified-by-reading
- **Where:** `src/Cli/Bootstrap.php:5808-5895` (`mcpConfigDecision()`: trusted iff the canonical root is listed in `trustedProjectMcp`). A content digest exists (`mcpConfigDigest()` `:6073-6081`, stored at `:6330-6332`), but it only drives the in-session drift notice `mcpConfigChangedSinceLaunch()` `:6047-6061`. It is never compared with anything recorded when trust was granted. Docs: `docs/MCP.md:28-33`, `:49-54`.
- **Scenario:** a user trusts `~/src/proj` for a harmless `npx some-mcp`. A later `git pull` (a teammate's commit, a compromised dependency bump PR, or checking out a contributor's branch to review it) changes `.mcp.json` to `{"command":"sh","args":["-c","curl …|sh"]}`. On the next `sugarcrush` launch in that root, the command runs at startup in every permission mode, and before any tool call. Claude Code re-asks per server when `.mcp.json` changes. Here nothing does.
- **Fix:** store the trusted digest (or a per-server `command+args+env` fingerprint) next to the root in `trustedProjectMcp`. When it differs, refuse to start the changed servers and print the diff, plus a one-line re-trust command.
- **Test:** trust a root, change one server's `args`, rebuild the client; assert that server is refused, the unchanged ones start, and the refusal names the server.

### MCP-6 — `sugarcrush mcp auth login` cannot log in to an MCP server whose URL has a path (nearly all of them): discovery appends `/.well-known/…` after the path, and the registration endpoint cannot be supplied by hand
- **Severity:** Low-Medium (the login half is unusable for path-bearing endpoints. The HTTP transport it serves is broken independently by MCP-2)
- **Confidence:** Verified-by-repro (against `mcp.notion.com`, discovery only; nothing was registered)
- **Where:** `src/MCP/OAuthLoopbackFlow.php:108` (`$wellKnown = rtrim($serverUrl, '/') . '/.well-known/oauth-authorization-server'`), `:121` (fails when `registration_endpoint` is missing; the positional operands override only the token and authorize URLs). The same construction appears in `src/Commands/McpAuthCommand.php:251`. `McpAuthCommand::fetchOAuthMetadata()` `:375-410` never checks the HTTP status.
- **Repro (`oauth_discovery.php`):** `login('https://mcp.notion.com/mcp', <token-url>, <authorize-url>)` returns `✗ OAuth endpoints could not be discovered … exit code: 1`, even with both overrides passed. `curl`: `https://mcp.notion.com/.well-known/oauth-authorization-server` → 200, but `…/mcp/.well-known/oauth-authorization-server` → 401.
- **Why it matters:** RFC 8414 §3 inserts the well-known segment *between* host and path. The MCP authorization spec discards the path, or discovers via `/.well-known/oauth-protected-resource`. The stored key must equal the `.mcp.json` `url` exactly for the bearer to attach (`docs/MCP.md:393-402`), so the workaround of logging in with the bare origin stores the token under a key that never matches.
- **Fix:** build discovery URLs per RFC 8414 (origin + `/.well-known/oauth-authorization-server` + path, then the origin-only form), and try RFC 9728 protected-resource metadata first. Treat a non-2xx response as "not found". Accept a `registration-url` override as well.
- **Test:** a `metadataFetcher` stub that answers only `https://h/.well-known/oauth-authorization-server`; assert `login('https://h/mcp', …)` reaches registration.

### MCP-7 — The OAuth credential store writes back a stale per-process cache: a long-lived TUI erases tokens that another process logged in
- **Severity:** Low-Medium
- **Confidence:** Verified-by-repro
- **Where:** `src/MCP/OAuthClientRegistration.php:279-306` (`loadAuth()` caches the whole file for the life of the object), `:267-272` (`saveAuth()` = cached map + one entry, then a full rewrite), `:556-575` (`writeAuthFile()`: `file_put_contents(…, LOCK_EX)` with no read-modify-write under the lock, and not atomic; a torn write turns into `loadAuth() === []`, which silently drops every credential).
- **Repro (`oauth_store.php`):**
  ```
  on disk after login: https://y/mcp,https://x/mcp     <- second process logged into x
  on disk after TUI refresh: https://y/mcp             <- TUI refreshed y from its stale cache; x is gone
  ```
- **Scenario:** a TUI is open with an `http` MCP server whose token it refreshes (`validAuthFor()` → `getValidAuth()`). In another shell the user runs `sugarcrush mcp auth login <other-server>`. The next refresh in the TUI rewrites the file without the new entry, and the login silently disappears.
- **Fix:** under an exclusive `flock` on a sidecar lock file, re-read the file, merge the one key, and write through a temp file + `rename()` (the repo already has `AtomicJsonFile`). Clear `authCache` after each write. Create the file with mode 0600 rather than `chmod` after the write.
- **Test:** the repro as a PHPUnit case. Assert both keys survive.

### MCP-8 — An expired login with no refresh token "re-registers" by storing the client id as the access token, which never expires; one failed token fetch leaves that bogus bearer in place permanently
- **Severity:** Low-Medium
- **Confidence:** Verified-by-repro (Guzzle `MockHandler`)
- **Where:** `src/MCP/OAuthClientRegistration.php:327-359`:
  - `updateRegistration()` `:448-472` sends a `PUT` with no body to the **registration endpoint**. RFC 7592 updates go to the per-client `registration_client_uri`, with the full metadata.
  - A new `AuthEntry` with `accessToken: $registered['clientId']`, `expiresAt: null` is **saved** (`:341-350`) *before* `fetchToken()` runs a `client_credentials` grant. A client registered for the authorization-code flow (what `mcp auth login` creates) is normally refused that grant.
  - `fetchToken()` `:131` also rejects any token response without `expires_in`, which RFC 6749 makes optional.
- **Repro output:** `getValidAuth threw: HTTP request to https://as/token failed: Client error …`, then a fresh instance reads `stored after failure: accessToken=cid2 expiresAt=NULL isExpired=false`.
- **Impact:** from then on, every request to that server sends `Authorization: Bearer <client_id>` (`isExpired()` is false for a null expiry, `AuthEntry.php:63-66`), so the refresh path never runs again. The server answers 401 forever until the user finds and deletes `~/.local/share/sugar-crush/mcp-auth.json`.
- **Fix:** never persist before the token exchange succeeds. For an entry with no refresh token, send the user back to `mcp auth login` instead of guessing a grant. Use `registration_client_uri` for RFC 7592 updates. Accept a missing `expires_in` (treat it as unknown and refresh on 401).
- **Test:** the repro, asserting that after the failure the stored entry is unchanged (still expired) and that the error tells the user to re-run login.

---

## H. CLI and docs

### CLI-2 — `sugarcrush <dir>` ignores a bare directory name, and every non-path positional word (a prompt typed without `-p`) is dropped silently
- **Severity:** Low-Medium (the TUI opens rooted in the wrong project, with that project's trust, `.mcp.json` and presets, and nothing says so)
- **Confidence:** Verified-by-repro
- **Where:** `src/Cli/ArgvParser.php:585-592` (a positional becomes the root only if `looksLikePath()`), `:688-691` (`$s[0] === '/' || $s[0] === '.' || strpos($s, '/') !== false`). Every other positional is collected (`:530`) and never read. Help: `src/Cli/Help.php:43` (`sugarcrush <dir>   Start the TUI rooted at <dir>`).
- **Repro (`argv.php`):** `sugarcrush fix the login bug` and `sugarcrush src` both parse to `root=NULL prompt=NULL`, no `usageError` and no unknown flags. The TUI starts in the cwd. `-p hi -- extra` drops `extra`. `--root --model x` takes `--model` as the root (the only value-taking flag without the `looksLikeFlag` guard that `--config`/`--model`/`--permission-mode` have).
- **Fix:** treat a positional that `is_dir()` as the root. Refuse (exit 2) any other leftover positional, with a hint about `-p "<prompt>"`. Alternatively, follow Claude Code and use leftover words as the TUI's initial prompt. Give `--root` the same flag-shaped-value guard as its siblings.
- **Test:** table cases (`src`, `fix the bug`, `--root --model x`) asserting root resolution or a usage error.

### DOC-1 — Smaller doc/code drift found while cross-checking
- **Severity:** Low
- **Confidence:** Verified-by-reading
- **Items:**
  1. `docs/TROUBLESHOOTING.md:136-139` says an unknown `type` makes startup "ordering-dependent: servers listed after it were never reached". `McpClient::startServers()` `src/MCP/McpClient.php:116-146` attempts every entry, collects failures, and throws once at the end. `docs/MCP.md:136` describes this correctly. The troubleshooting advice ("move the bad entry") is stale.
  2. `docs/WORKFLOWS.md:63,81,245-246,255-261` and `examples/workflows/lint-then-fix.yaml:19-20`: timeout and retries semantics that do not exist (WF-1).
  3. `docs/WORKFLOWS.md:113`: `{{agentName.results}}` for parallel agents (WF-3).
  4. `docs/MCP.md:132-133,297`: Stdio/HTTP marked working (MCP-1, MCP-2). `docs/PERMISSIONS.md:144-152` pairs `mcp__git__*: allow` with `Write: deny` (GIT-1).
- **Fix:** correct each one alongside its finding. Item 1 stands alone: delete the ordering paragraph.

---

## Summary table (sorted by severity)

| ID | Severity | Confidence | Title |
|---|---|---|---|
| MCP-1 | Critical | Verified-by-repro | stdio MCP sends `[]` for empty maps; reference TS/Python SDK servers start with 0 tools after a 60 s block, and no-arg tools fail |
| AG-1 | High | Verified-by-repro | Forked sub-agents (parallel workflow stages, Task fan-out) share the parent's MCP pipes, id counter and keep-alive sockets; concurrent MCP calls return each other's results |
| MCP-2 | High | Verified-by-repro | Streamable HTTP MCP: 406 (no Accept), no Mcp-Session-Id, no SSE parsing |
| GIT-1 | High | Verified-by-repro | Git MCP handlers allow option injection (`gitShow --output=` writes arbitrary files) and an uncontained per-call `path` |
| GIT-2 | High | Verified-by-repro | `execGit` stdout-then-stderr read deadlocks on more than 64 KiB of stderr (hook output); no timeout; env stripped |
| MCP-3 | Medium | Verified-by-repro | `ClaudeCodeMcpClient` gives up after ~1 s per call; initialize sent as a notification |
| SES-1 | Medium | Verified-by-repro | `/rewind` keeps the undone prompt in history and in the input box |
| SES-2 | Medium | Verified-by-repro | `forkSession` copies dead tables (empty fork, no checkpoints, duplicate name; `--resume name` opens the parent) |
| SES-3 | Medium | Verified-by-reading | No per-session writer lock; two TUIs clobber the transcript; checkpoint index and blob-intern races |
| WF-1 | Medium | Verified-by-reading | Workflow/task `timeout` and `retries` are never enforced; stages have no wall-clock bound |
| WF-2 | Medium | Verified-by-repro | `/workflow pause` only snapshots finished runs; resume skips the failed stage and reports "completed"; pause file never cleared |
| MCP-4 | Low-Med | Verified-by-reading | A stray non-JSON stdout line aborts the MCP request |
| BG-1 | Low-Med | Verified-by-reading | Background sessions cannot be stopped (STOP has no sender) |
| TMP-1 | Low-Med | Verified-by-reading | Fixed shared `/tmp` directory names; the first user disables Task resume for others |
| WF-3 | Low-Med | Verified-by-repro | `{{agent.results}}` never resolves for parallel agents; a context key equal to an agent type crashes the stage |
| AG-2 | Low-Med | Verified-by-repro | One Claude-style preset (`tools: Read, Grep`) disables all presets in every tier |
| MCP-5 | Low-Med | Verified-by-reading | Project MCP trust is path-bound, not content-bound; a pulled `.mcp.json` change runs new commands without consent |
| MCP-6 | Low-Med | Verified-by-repro | `mcp auth login` discovery breaks on path-bearing server URLs; registration URL not overridable |
| MCP-7 | Low-Med | Verified-by-repro | OAuth store stale-cache write-back erases another process's credentials; non-atomic write |
| MCP-8 | Low-Med | Verified-by-repro | Failed re-registration persists `client_id` as a never-expiring bearer token |
| CLI-2 | Low-Med | Verified-by-repro | `sugarcrush <dir>` ignores bare directory names; non-path positionals silently dropped |
| SES-4 | Low | Verified-by-repro | Checkpoint blobs GC'd only on /rewind; orphans accumulate |
| SES-5 | Low | Verified-by-reading | Mixed local/UTC timestamps |
| SES-6 | Low | Verified-by-reading | `getMessages` ordering tiebreak |
| BG-2 | Low | Verified-by-reading | Background IPC directories never cleaned |
| CLI-1 | Low | Verified-by-repro | `display_errors` not routed to stderr; a `mkdir()` warning precedes the headless JSON document |
| AG-3 | Low | Verified-by-reading | `AgentManager` never forgets sub-agents; unbounded growth plus a per-frame scan |
| DOC-1 | Low | Verified-by-reading | Doc drift: TROUBLESHOOTING startup ordering, plus doc halves of WF-1/WF-3/MCP-1/MCP-2/GIT-1 |

---

## Coverage

**Read end to end:**
- `src/Session/EnhancedSessionStore.php`, `src/Session/SessionStore.php`
- `src/Sessions/BackgroundSupervisor.php`, `src/Sessions/BackgroundSessionRunner.php`
- `src/Agents/SuspendedDelegations.php`, `src/Agents/EngineExecutor.php`, `src/Agents/AgentPresetRegistry.php` (parse/list/load)
- `src/MCP/McpClient.php`, `src/MCP/StdioMcpServer.php`, `sugar-mcp/src/StdioMcpServer.php`, `sugar-mcp/src/McpMessage.php`, `src/MCP/McpRouter.php`, `src/MCP/McpAuthStore.php`, `src/MCP/OAuthPkce.php`, `src/MCP/OAuthLoopbackFlow.php`, `src/MCP/OAuthClientRegistration.php` (storage, refresh and re-registration paths)
- `src/Cli/NonInteractive.php`, `src/Cli/HeadlessPermissionPrompt.php`, `src/Cli/ArgvParser.php`, `bin/sugarcrush`
- `src/Workflows/WorkflowEngine.php`: stage loop `:878-1040`, all four stage executors `:1051-1665`, run/pause/resume/status `:400-760`, interpolation, ids and interrupt handlers `:1900-2258`
- `src/Workflows/WorkflowRegistry.php`: YAML parsing `:1086-1470`
- `src/Workflows/WorkflowBuilder.php`

**Read in part:**
- `src/Agents/AgentWorkerPool.php`: `executeAll`/`executeOne`/`cancel*`/`startAgent`/`runStreaming`/`waitForCompletion`/`idle`, result and progress files, and `makeResultDirPath`/`ensureResultDir` `:378-1020`, `:1197-1430`, `:1700-1707`.
- `src/Agents/AgentManager.php`: `executeAll`/`drain`/`settleAbandoned` and batch grant/prompt resolution `:1581-1860`, plus the `$subAgents` writers.
- `src/Agents/ProcessExecutor.php`: `spawnWorker()` `:576-735` only. It is the no-provider fallback that refuses; the live path is `EngineExecutor`. Its 5 s ready loop busy-spins on a non-blocking `fgets` (a CPU hot loop of at most 5 s). Not filed.
- `src/MCP/GitMcpServer.php`: schemas and `callTool()` dispatch `:290-455`. `src/MCP/GitCommandHandlers.php`: `:620-1100` checked for argv shapes only; every arm shares GIT-1's missing `--` (worktree add/remove also take arbitrary on-disk paths).
- `src/Cli/Bootstrap.php`: `workflowEngine()` `:1453-1540`, `agentManager()` `:1718-1775`, preset loading `:2215-2245`, session open/find `:3150-3216`, MCP trust/decision/digest/client/tools `:5759-6081`, `:6262-6340`, `:6470-6520`, `tools()`/`unfilteredTools()` around `:6900-6930`, `sessionStore()`/retention `:7349-7495`, `ensureDir()` `:8036-8040`.
- `src/Cli/Subcommands.php`: `session list|delete`, `mcp import`, `mcp auth` dispatch.
- `src/Tools/McpToolBridge.php`: `name()`, `sanitize()`, `execute()`.
- `src/ClaudeCodeMcpClient.php`: `:340-520`, `:753-830`.
- `src/Chat.php` workflow handlers `:9454-9830` and the session handlers listed in SES-1/SES-2.
- `src/Diagnostics/RuntimeNoticeSink.php` (code only).
- Docs: `docs/MCP.md` (transports, trust, auth, serving), `docs/WORKFLOWS.md` (whole), `docs/TROUBLESHOOTING.md` MCP section, `docs/PERMISSIONS.md` rules section, `docs/ARCHITECTURE.md` grep for agents/MCP/workflow claims.

**Not audited, with reason:**
- `src/LSP/*`: dormant per the baseline; no live caller.
- `src/Share/*`: a known stub (Part II #35).
- `src/Agents/WorktreeManager.php`, `PathJail.php`, `PathJailConfig.php`, `TeamManager.php`, `Mailbox.php`, `Team*.php`: nothing outside `src/Agents/` constructs them (`grep 'new Mailbox|new TeamManager|new PathJail'`), so they are dormant (baseline §2.6).
- `AgentManager::executeSubAgent()` `:678-940`: no `src/` caller (also noted at `src/Renderer.php:166-167`).
- `ForeignAgentPresetRegistry.php`: function list only.
- `src/Events/*`: not reached.
- `McpForeignTranslate.php`: used only by `mcp import`, which prints and writes nothing.

**Leads from the checkpoint, settled:**
1. *Only the first task of a stage runs* (`WorkflowEngine.php:1069`): **dropped as a defect.** It is intentional and documented, and no builder or YAML path can produce a multi-task `stage` (see the section F preamble). The real workflow defects are WF-1, WF-2 and WF-3.
2. *`/tmp/sc_pool_<pid>_*` predictable name* (`AgentWorkerPool.php:1368`): **dropped.** The suffix is `bin2hex(random_bytes(8))` (`:1360-1366`), the directory is created 0700 (`:1380-1393`), and files inside are named by sha256 of the agent id.
3. *`callToolByName` first-match across servers*: **dropped.** `McpToolBridge::execute()` `:397-410` calls `McpClient::callTool($serverName, …)` by server, and `callToolByName()` has no caller in `src/` (the bridge docblock `:366-379` records the earlier mis-routing as fixed). The sanitiser is injective (`_XX` escapes), so two servers cannot share a wire name. *Unconfirmed side note:* the escape doubles `_` into `_5F`, and no 64-character cap is applied to wire names. A long `server__tool` pair can exceed the limit OpenAI-style APIs enforce. The default SGLang path does not enforce it, so this was not tested.
4. *Blob intern TOCTOU*: **folded into SES-3.** Blobs are per session, so the race needs the two-writers setup SES-3 already describes.
5. *Daemon inherits the TUI's fds*: **mechanism confirmed, not filed.** `fdleak.php` shows PHP `proc_open` passes every non-CLOEXEC `fopen`/socket fd to the child (SQLite's fd is CLOEXEC and was not inherited). The `/bg` daemon therefore holds copies of TUI sockets and pipes, including the `RuntimeNoticeSink` socketpair and MCP stdio pipes. I could not show user-visible harm: the TUI's `stopMcpServers()` still terminates servers explicitly on exit. The same class is filed in 15a (B3) for the Task fork path.
6. *ESC injection through `HeadlessPermissionPrompt::question()`*: **dropped.** Arguments go through `json_encode`, which always escapes C0 controls (ESC becomes `\u001b`). The built-in Ask message is `"Allow {$toolName} to run? …"` (`PermissionGateHook.php:137-139`), and tool names are registry names (MCP names are sanitised to `[A-Za-z0-9_-]`). A ScriptHook's ask text is operator-authored. Residual, untested: `JSON_UNESCAPED_UNICODE` emits UTF-8 C1 controls (U+009B) raw, and some terminals honour them.
7. *`--resume ''` / prefix match* (`Bootstrap::findSession` `:3150-3162`): **dropped.** `--resume ''` and `--resume=` become "no target", which opens the picker (`ArgvParser.php:497-518`). Prefix matching requires a unique match, and an ambiguous prefix reports "no stored session" (wording only). Name resolution's non-determinism is already in SES-2.
8. *Daemon permission mode*: **dropped (by design).** `BackgroundSessionRunner::backend()` `:580-611` passes `consolePermissionPrompt=true`. With stdin `/dev/null`, every Ask is refused with the full refusal text written to the daemon's log (`BackgroundSupervisor.php:254-258` routes stderr to `<ipc>.buffer.log`). The permission mode is whatever the config resolves, which defaults to bypass (Part II #1). The stop gap is BG-1.

**CLI-1:** confirmed. It was Suspected and is now Verified-by-repro.

**Repro scripts and fixtures** (all in `/home/sites/crush-research-repos/_audit-scratch/15e/`):

| Script(s) | Finding | What it shows |
|---|---|---|
| `rewind.php` | SES-1 | |
| `fork.php` | SES-2 | |
| `blobs.php` | SES-4 | |
| `mcp_everything.php`, `pyserver.py`, `mcp_py.php` | MCP-1 | |
| `mcp_http.php` | MCP-2 | |
| `ccmcp.php` | MCP-3 | |
| `git_handlers.php` + `gitrepo/` | GIT-1, GIT-2 | Recreate `gitrepo/` before re-running GIT-2 |
| `mcp_fork.php`, `fake_mcp_server.php`, `mcp_fork_exit.php`, `fork_http.php`, `ka_server.py` | AG-1 | |
| `preset_list.php` + `presets/` | AG-2 | |
| `wf_pause.php`, `wf_ctx.php` + `wf/home/.sugar-crush/workflows/{three,fan}.yaml` | WF-2, WF-3 | |
| `oauth_discovery.php` | MCP-6 | Network: discovery GET to mcp.notion.com only |
| `oauth_store.php` | MCP-7, MCP-8 | |
| `argv.php` | CLI-2 | |
| `cli_warn.sh` | CLI-1 | |
| `fdleak.php` | lead 5 | |

`ps` after the last run showed no leftover node, python, php, MCP or daemon processes.
