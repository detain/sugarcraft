# C-WEB-5.f — server.logs tail RPC + About-modal Logs tab

BASE 16fe158c8, branch cc/C-WEB-5.f, worktree /home/sites/cc-cmp-C-WEB-5.f.

## Step 0 (done) — grounding
- Design block read (design-web.md + plan §4 :4084). §6 ruling R13: owner ACCEPTED Admin-scope web log tail on the wire (§6.1 row "R13 (remote / GitHub)": "... Admin-scope web log tail and a confirmed server.shutdown" — ALL allowed; steps C-WEB-5.*). Docblock trust prose will say: lines reach the wire ONLY behind Scope::Admin, by owner ruling.
- Live anchors (symbol-noted): Serve::tail() at Serve.php:1222-1224 (fopen/stream_get_contents last-N-lines windowed read), Serve::logs() at :838-874 (lstat regular-file refusal at :851-853, LOG_TAIL_LINES=100 at :126), follow() :1251 stays CLI-side.
- ErrorLog::flatten (src/Server/ErrorLog.php:39) caps a line at MAX_MESSAGE_BYTES=200, control bytes out.
- Rotation: StateDir::LOG_FILE='server.log', LOG_FILES=3 generations (.1,.2), rotateLog() at StateDir.php:219.
- Protocol: ServerMethods.php registers specs with Scope::Admin (server.shutdown :48 pattern); Dispatcher enforces -32003 at :348 requireScope; fixture clients are owner-scoped, so the admin-door test follows ShellsInputTest.php:241 precedent (EXEC requireScope at the dispatcher's choke against a limited Client) to pin the write-scope refusal.
- trio law: regen schema/SERVER.md/generated.ts locally for guards, RESTORE before commit (weld regenerates).

## Landed commits
(none yet)

## Step 1 — Lift (committed as commit 1)
- NEW src/Server/LogTail.php: tail() lifted verbatim from Serve::tail (window const),
  answer() = rotation walk (live + .1/.2 via StateDir::LOG_FILES) + ErrorLog::flatten
  + line cap (MAX_LINES=200) + 32 KiB byte cap keeping NEWEST, reason 'no_log' when
  the live server.log is absent or non-regular (lstat, never followed).
  R13 trust docblock: Admin scope is the only door, per owner ruling §6.1.
- Serve.php: import LogTail; logs() calls LogTail::tail; Serve::tail() deleted.
  Refusal branch + follow() byte-untouched.
- ReadPathCensusTest IN-STEP: 'Cli/Serve.php|fopen' reduced to the follow() row;
  NEW 'Server/LogTail.php|fopen' row (exactly one fopen in the file).
- Doc-carry (deviation, files outside declared set — comments only, created by the move):
  src/Hooks/BuiltIn/AuditLogReader.php three stale `Serve::tail()` prose cites renamed to
  LogTail::tail()/answer() (symbol-citation truth; SymbolCitationDriftTest green).
- NEW tests/Server/LogTailTest.php 7T/15A green (rotation boundary, non-regular refused
  (dir + symlink), flatten/clip, generation coverage .3 never read, empty≠no_log, clamp).
- Gates (FQCN filters, cwd=candy-code, plain pipe): LogTailTest 7/7; ServeArgsTest
  46T green; ReadPathCensusTest 19T with 1 FAIL — proven IDENTICAL at pristine base via
  stash round-trip: FOREIGN red, src/Protocol/Methods/MediaMethods.php live sink counts
  (file_get_contents 2 vs roster 1, fopen 3 vs 2) — roster rows owed by the C-TUI-6.n
  media weld lineage, not minted in base; my Serve→LogTail flip is diff-clean in the arm.
  StderrEmitterCensusTest 95T/24054A with 2 FAILS — also proven IDENTICAL at pristine
  base: FOREIGN reds (src/Background/PtyRelay.php 9 fwrite(STDERR) sites unrostered +
  BinCandyCodeAutoloadGuardTest 'thirty-eight' numeral vs live 47). NO stderr flip came
  from this lane (logs() echo paths + follow untouched) — expected outcome for the
  design's "if output paths move" clause. Foreign roster additions above are NOT in the
  accepted roster — escalate to welder as base dirt, do not chase here.
- New test files for durations mint: candy-code/tests/Server/LogTailTest.php.

## Step-2: server.logs admin-scoped tail RPC (commit 2)
- src/Protocol/Methods/ServerMethods.php: server.logs spec (Scope::Admin, no sideEffects flag) registered right after server.shutdown; handler reads $params->optionalInt('lines',1,LogTail::MAX_LINES) ?? DEFAULT_LINES and answers LogTail::answer(config()->stateDir, lines). Docblock carries the R13 ruling verbatim-intent + machine-token no-localization law.
- src/Protocol/Schema/MethodSchemas.php: params {lines?: int 1..200}, result {lines: string[] (≤200), reason: string(35)|null} — literals, no LogTail import in this hub file.
- tests/Protocol/ServerLogsTest.php (NEW, 6T/19A green, exact FQCN filter): roster gate, write-scoped requireScope choke -32003 'this needs the admin scope', fresh-fixture no_log empty answer, flattened/clipped lines, byte-cap trims oldest keeps newest, lines:0 → -32602.
- Trio regen-verify-RESTORE: php scripts/gen-protocol-schema.php --write + npm run gen:protocol; ProtocolSchemaDriftTest|ServerProtocolDocumentationDriftTest 13T/362A GREEN with trio present; JsonRpcTest 11T/41A green; then git checkout -- the three trio files — git status shows them clean (welder regenerates once per wave).

## Step-3: About-modal Logs tab behind admin scope (commit 3)
- candy-code/lang/en.php: 4 chrome keys under web.chrome.about.* (logs, logs_empty, logs_no_log, logs_refresh), alphabetical after 'loading'. en.php is the only locale file at this base — no community-locale sweep owed.
- candy-code-web/src-web/components/AboutModal.vue: Logs section gated on the SAME computed canAdmin as the C-WEB-5.g stop control; load() clears logs/logsReason/logsError with the other per-visit resets and chains `if (canAdmin.value) void loadLogs()` after info/health settle; loadLogs() carries the generation latch (stale-visit answers AND errors dropped, shared-row untouched); refusal lands on about-logs-error, no_log renders the chrome note, never a blank pre.
- candy-code-web/src-web/components/__tests__/aboutModal.spec.ts: new describe, 4 tests — hidden-without-admin (demote BEFORE open; about-logs/about-logs-refresh absent and wire.sent('server.logs') EMPTY — the fetch is load-driven so this is the "client never sends it" pin the design asks for), admin sees tail + refresh resends (sent length 2), no_log reason row, refusal -32003 stays on its own row (about-info/about-health still painted, about-error absent). Vitest 16/16 green (12 pre-existing + 4 new), --no-file-parallelism.
- Spec-order gotcha (fixed in-lane): load() only asks server.logs after info/health settle; tests must answer those frames + flush BEFORE answering/clicking/failing the tail frame.
- Strings mirrors regen-verify-RESTORE: php scripts/gen-web-strings.php --write + npm run gen:strings → WebStringsDriftTest 2T/5A GREEN with mirrors present → git checkout -- docs/protocol/web-strings.en.json + src-web/lib/strings.en.generated.ts (git status clean — welder regenerates in the weld).
- Generated.ts NOT committed per protocol-trio law: AboutModal.vue's connection.request('server.logs') type-awaits the welder's gen:protocol (vitest doesn't typecheck; lane-accepted weld-closure state).

## Deviations
- AuditLogReader.php: comment-only carries (3 stale Serve::tail() cites → LogTail) rode commit 1 — created by the lift, outside files.modify, reported.
- FOREIGN base reds (proven identical at pristine 16fe158c8 with lane changes stashed; NOT caused by this lane; not in the accepted roster — for the welder/gate): (a) ReadPathCensusTest::testTheCensusIsDerivedFromSrcAndFullyClassified 1F — MediaMethods.php file_get_contents 2vs1 + fopen 3vs2 (rows never minted at the C-TUI-6.n audio.transcribe weld 1784d207f); (b) StderrEmitterCensusTest 2F — PtyRelay.php 9 fwrite(STDERR) sites unrostered + autoload-guard 'thirty-eight' numeral stale vs 47. My census flips are diff-clean in both arms.
- New test files for the durations TSV mint (gate duty): candy-code/tests/Server/LogTailTest.php, candy-code/tests/Protocol/ServerLogsTest.php.
