# C-TOOL-4.f — Egress proxy core (new files only) — LANDED

Branch `cc/C-TOOL-4.f`, base master `9a0c569eb`, 2 picks, tip `4bbf922b2` (cat-file'd `commit` same shell; `%ae`+`%ce` detain on both commits, zero Co-Authored-By).

## Scope delivered (design-tool.md:611-647, verbatim file list)
- `candy-code/src/Tools/Sandbox/EgressPolicy.php` — host-glob[:port] grants (default ports 80/443), matching delegated to `FetchTarget::matchesDomain(pattern, includeSubdomains:false)` grant-side semantics, resolve-ONCE via `WebFetch::resolveViaSystemDns` with the vetted address returned for dialling (no rebinding window), refuse if ANY answer `WebFetch::addressIsBlocked`, resolver/blocklist injectable.
- `candy-code/src/Tools/Sandbox/EgressProxy.php` — ReactPHP unix-socket proxy (0600 socket, 0700 dir), CONNECT + absolute-form HTTP/1.1, 403 names host and `sandboxAllowedDomains`, 400 malformed, 502 dial-fail, head cap 64KiB, ONLY the upstream dial carries a connect timeout (10s), tunnels idle-reaped (300s) with NO total bound (E646, structurally pinned by test), stdin-EOF + ppid-change + SIGTERM/SIGHUP self-exit.
- `candy-code/src/Tools/Sandbox/EgressProxyProcess.php` — launch-scoped child: `ProcessContainment::spawnSpec([PHP_BINARY,'-r',STUB])` (ProcessExecutor worker shape), JSON config frame on stdin, stderr to 0600 log beside the socket, group recorded AT SPAWN (ISS-014), closeOnExec on the parent's pipe ends, stop() = ProcessReaper group ladder + scaffold discard.
- `candy-code/src/Tools/Sandbox/EgressBridge.php` — in-jail half: binds 127.0.0.1 FIRST (kernel backlog accepts before the loop runs — pinned), forks, child `@pcntl_exec` then `ForkedChild::exitNow(127)` on exec failure only, parent relays TCP↔unix, reaps, forwards exit status.
- Tests: `candy-code/tests/Tools/Sandbox/{EgressPolicyTest,EgressProxyTest,EgressBridgeTest,EgressProxyProcessTest}.php` — **NEW FILES FOR §7**: EgressPolicyTest 17T, EgressProxyTest 8T, EgressBridgeTest 5T, EgressProxyProcessTest 5T (family total 35T/113A, green ×2 runs stable).
- Guard rosters moved IN-STEP (commit 2): `tests/Support/ReadPathCensusTest.php` (fopen SELF_LOCATED row + require PROCESS_DERIVED row mirroring Agents/ProcessExecutor + execute-path assert list entry) and `tests/Support/DescriptorInheritanceGuardTest.php` (ACCOUNTED_FOR `Tools/Sandbox/EgressProxyProcess.php::start` count 1).

## Gates (cwd=candy-code, -c phpunit.xml, env -u LINES -u COLUMNS, plain pipe, PtyRun expect-miss excluded from all filters)
- Family `EgressPolicyTest|EgressProxyTest|EgressBridgeTest|EgressProxyProcessTest`: **35T/113A OK ×2**.
- Guards `ReadPathCensusTest|DescriptorInheritanceGuardTest|ForkedChildExitConventionTest|DuplicatedTestHelperDriftTest|StderrEmitterCensusTest`: **147T/29291A OK** (deprecation noise = foreign AccessorDeprecationTest providers, fires on every filter this era).

## Root-cause fix beyond first cut (kept same-lane, src is my file)
react/stream `write()` buffers in userspace and `close()` DISCARDS the buffer — the first-cut write()+close() sent zero bytes (cross-process repro). Responses now go through `end()` (flush-then-FIN); tunnel close-propagation also `end()`; idle-reaper legs stay hard `close()` by design. `respond()` became `buildResponse()` returning the wire string.

## Deviations / honest records
- exec-failure observed status is **137**, not 127: `ForkedChild::exitNow` SIGKILLs before its exit() fallback; the test pins 137 with a comment (design's 127 is the exitNow argument, not the reaped status).
- Readiness poll lives in callers/tests (socket-file appears ≤8s), not inside start() — kept start() non-blocking.
- No config key, no docs, no wiring (C-TOOL-4.g owns `sandboxAllowedDomains` settings surface); EgressPolicy takes entries via ctor per brief.
- Helper rename `proxyPolicy()` in EgressProxyTest (DuplicatedTestHelperDrift one-token twin with EgressPolicyTest::policy) per lane-ce house precedent.

## FOREIGN base-reds found (stash-proven at 79e786867-with-stash; NOT mine, NOT touched)
- `GlobDialectDifferentialTest` figure arm: base `9a0c569eb` derives **311,360 = 560×556** while `src/Util/PathGlob.php` docblock quotes **310,245** — drift introduced by the committed Seatbelt lane (`tests/Tools/Sandbox/SeatbeltTest.php` literal `/^[A-Z][A-Z0-9_]*=/`); my 8 files contribute zero admitted literals (predicate-scan verified). PathGlob is outside my declared files → report-only; §7 gate/closeout owns the re-derive.
- `ChildStderrCaptureTest` both arms: violators/unaccounted are committed foreign files (Chat/SettingsReloadTest, Chat/MemoryOpenJournalTest, Backend/CacheReusingSummaryBackendTest; Automation/Server/Host/Protocol dirs) — zero Tools/Sandbox entries.
- `ForkedChildReaperAdoptionTest`: `Sessions/BackgroundShellRegistryTest.php` — foreign committed file.

## Law confirmations
No new skips (SuiteSkipRoster untouched). No forbidden-file commits (suite-figure.json, durations.tsv, dist, protocol trio, SERVER.md, generated.ts, plan_comparison.md, run-state.json, .opencode — verified: porcelain listed only the 8 declared paths). `git status --porcelain` EMPTY at tip. Never pushed; no composer/npm/refresh-deps run; no caliber. background=true honored (spawned subagent).
