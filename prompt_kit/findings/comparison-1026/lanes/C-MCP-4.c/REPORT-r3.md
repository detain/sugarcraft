# C-MCP-4.c — Served /mcp login + paste-back finish (round 3 re-run)

Verdict: COMPLETE. This re-run lands the legs the W27 wave-review found
never delivered by the original 2ad862baf carry (which shipped only the
AuthEntry::redirectUri binding): the served watcher, the host-command
intercept, the paste-back correlation leg, SessionHost::appendNotice,
ServerContext::pump routing, lang rows, docs, and tests.

Base: master 003cb1e4e. Deps welded: C-MCP-6.a 6ae2c9e36, C-MCP-4.b 6beef34f4.
(Rounds 1/2 records below this line are historical; see git f25a697c1.)

---

## Commits

1. `eb3a17dcc` — skeleton: `src/Host/ServedMcpLogins.php` (new watcher:
   server→sessionId, pollDue() mirrors Chat's beat translation one-for-one,
   clear() test-hygiene) + `McpAuthHostCommand::run` intercepts
   `login <server>` → McpLoginFork::arm + register watch; bare `login`
   and `login --flags` fall through to McpAuthCommand's guidance arm
   untouched.
   Files: src/Host/ServedMcpLogins.php (new), src/Host/Commands/McpAuthHostCommand.php.

2. `628a5abf3f93295fe6c51b9621c7acecfbb049dc` — the full surface (9 files,
   +597/−5, author+committer detain, zero trailers):
   - src/Support/McpLoginFork.php: `arm()` handles `--code <url>` BEFORE
     the busy check → private deliverPasteBack: constant-time address
     judging (hash_equals on scheme http / host 127.0.0.1|localhost /
     port === the slot's announced redirectPort), CR/quote/NUL rejection
     on path+query, then ONE bounded local GET to
     `'tcp://127.0.0.1:' . $expected` (literal — the pasted host is never
     dialled), 2.0 s deadline, answer drained up to 8×8192, fclose in
     finally. Every refusal names its reason and never echoes the URL;
     armed is always false on this leg. New consts
     PASTEBACK_TIMEOUT_SECONDS/PASTEBACK_ANSWER_READS.
   - lang/en.php: +9 keys (alphabetical): login-code-delivered,
     login-code-refused, login-code-reason-{shape,noslot,scheme,host,port,
     dial}, login-served-armed (the served reply tells the remote user
     exactly the `/mcp login {server} --code <that-url>` ritual).
   - src/Host/SessionHost.php: `public function appendNotice(string): void`
     wrapping appendMessageRow(Message::notice()) — durable row +
     message.created event. Hunk @@ -2095,6 +2095,19 @@ — CO-TENANT PROOF:
     outside C-AGT-4.e's claimed tick/drain region (~1198–1275).
   - src/Protocol/ServerContext.php: minimal pump() tail hunk routing
     ServedMcpLogins::pollDue() rows via hub->get(sessionId)?->appendNotice;
     rows for vanished sessions are dropped, watch retires on the child's
     final beat (children self-exit bounded → no watch lives forever).
   - docs/MCP.md §Authorization-code login: +22 lines — served fork,
     notice-event rows, paste-back ritual, constant-time loopback-only
     delivery, and the risk sentence (a client with Write scope can start
     a login child on the server host — same power as command.exec).
     Pinned literals intact: 'candy-code mcp auth login <server>'
     substr_count stays 2 (new prose uses slash forms), 'bounded by 300 s'
     untouched, '[-- --timeout N]' intact. §Server auth's seven-command
     fence/lead-in was already landed by C-MCP-4.b — no drift.
   - tests/Chat/McpLoginForkTest.php: +6 methods — delivered-to-a-REAL
     loopback listener (asserts the GET line + Host header the waiting
     child itself parses), five refusal cases each naming its reason +
     stream_select proof the listener was NEVER touched, code-judged-
     before-busy ordering discriminator, dead-listener dial-timeout (no
     hang), structural pin that the dial target is the validated loopback
     literal (substr_count "'tcp://127.0.0.1:'"===1, stream_socket_client
     ===1). GlobDialect law honored (chr(63) for '?' in the announce
     fixture; no glob-shaped literals — corpus unchanged).
   - tests/Host/McpLoginServedTest.php NEW (9T/51A): watcher beats →
     rows (url/done/failed/gone-silent/retire), register(null/'') watches
     nothing, host-command served reply shape + session aiming, bare
     guidance fall-through, `--code` fall-through, and an end-to-end pump
     test (SessionHub + real ServerContext + Loop) proving rows land as
     durable message.created notice rows, plus closed-session rows drop
     without throwing.
   - **NEW test file for the gate**: tests/Host/McpLoginServedTest.php
     (9T) — closeout owes it a scripts/parallel-tests-durations.tsv row.

## Gates (targeted only, plain pipe, cwd=candy-code -c phpunit.xml, env -u LINES -u COLUMNS)

- 'McpAuth|McpLoginFork' → Tests: 97, Assertions: 1684, OK (pre-test-add baseline).
- 'McpLoginServed' → Tests: 9, Assertions: 51, OK.
- 'McpLoginFork' → Tests: 17, Assertions: 1341, OK.
- 'ServerContext|CommandDoc' → Tests: 8, Assertions: 23, OK.
- 'SessionHost' → Tests: 81, Assertions: 396, RC=0.
- 'DocFigure|GlobDialect|Readme' → Tests: 163, Assertions: 5245 — only reds:
  the ReadmeSuiteFigureDrift staleness pair (pinned 25537 vs live 28310,
  program-wide era drift; suite-figure.json + README re-pin is closeout
  duty, forbidden to this lane).
- php tools/gen-command-docs.php --check → 'command docs: up to date' RC=0
  (COMMANDS.md needed no regen — the intercept changes no command metadata).
- 2 PHPUnit Deprecations in every run = pre-existing tree baseline.
- Never ran full suites; PtyRunToolTest never touched.

## Seams disclosed (not fixed — outside declared file set)

- Fork fd-inheritance: a test reproduced that a raw listening socket
  opened in the PARENT before McpLoginFork::arm's spawn survives into the
  child across the exec (no CLOEXEC on stream sockets via
  ProcessContainment::spawnSpec). For the login child this is benign
  (it rebinds/reads its own listener; the inherited duplicate just holds
  the port), but it is the same family the DescriptorInheritanceGuard
  rosters — worth a weld/owner glance. My dead-listener refusal test
  works around it (bind+release strictly before the spawn).
- paste-back rides the existing poll/announce flow only; no new proc_open
  anywhere in this step (client socket only) → no DescriptorInheritanceGuard
  roster row needed.
- The serve-side `--code` leg is usable from any protocol client that can
  send `/mcp login ...` text; auth for that surface is SessionHost's
  existing scope gate — the docs risk sentence names this explicitly.
