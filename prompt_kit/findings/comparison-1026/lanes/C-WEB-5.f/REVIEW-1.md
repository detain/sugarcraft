# REVIEW-1 — lane C-WEB-5.f (server.logs tail RPC + About-modal Logs tab)
Reviewer: cc-cmp step reviewer (same-session recreation after phantom-write intercept).
Anchors: lane base 16fe158c8; commits 26ba67767 / 18f02f899 / d52e29e9a on cc/C-WEB-5.f; master tip 761d1090e.

## (1) Scope vs design
11 files touched. All 8 design-named source/test files present (LogTail.php, LogTailTest.php, ServerLogsTest.php, Serve.php, ServerMethods.php, MethodSchemas.php, AboutModal.vue, aboutModal.spec.ts). The regen trio (docs/protocol/candy-code.v1.schema.json, docs/SERVER.md, src-web/protocol/generated.ts) is NOT in the diff — sanctioned protocol-lane RESTORE law (weld regenerates). Two files beyond files.modify, both adjudicated honest and lane-disclosed: src/Hooks/BuiltIn/AuditLogReader.php (3 comment-only stale `Serve::tail()` cites renamed to LogTail — created by the lift, SymbolCitationDriftTest green) and candy-code/lang/en.php (+4 web.chrome.about.logs* chrome keys — required by the design's own reason-token→chrome-string mapping; en.php is the only locale file at this base, no locale sweep owed). No stray executable edits, no weld-only files, no TODO/stub surface.

## (2) Lift honesty (26ba67767)
`git show` diff of the removed `Serve::tail()` body vs `LogTail::tail()`: byte-identical except `$window = self::WINDOW_BYTES` replacing the literal `256 * 1024` (equal value, named-constant lift). `serve logs` call site switched to `LogTail::tail`; the CLI lstat refusal branch and `follow()` stay byte-untouched in Serve.php (own exit codes/Lang messages stay CLI-side — docblock says so). `LogTail::answer()`: generation walk covers live + `.1` + `.2` exactly matching `StateDir::LOG_FILES = 3` / `rotateLog()` stacking; planted-`.3` test proves the bound; `ErrorLog::flatten` (control runs → one space, clip to MAX_MESSAGE_BYTES=200) applied BEFORE byte-costing; newest-first budget break trims from the OLDEST end, reversed to chronological; empty-live file answers `reason: null` ≠ `no_log`; non-regular refusal via lstat (dir planted at server.log + symlink-out-of-dir both tested).

## (3) server.logs spec (18f02f899)
Registered `Scope::Admin`, no sideEffects (roster pin asserts both); MethodSchemas params `{lines?: integer(1,200)}`, result `{lines: string[] ≤200, reason: string(35) nullable}` with `{lines}` required — matches LogTail::MAX_LINES/DEFAULT_LINES literals; handler `optionalInt('lines', 1, MAX_LINES) ?? DEFAULT_LINES`, reads `$call->server->config()->stateDir` (NO path param — zero untrusted-path surface). R13 docblocks present on the LogTail CLASS and on the `logs()` handler per ruling (Admin-scope-on-wire acceptance cited to plan §6.1 R13; never-onto-the-wire law preserved for all other surfaces). lines:0 → -32602 verified at the wire; write-scope refusal -32003 'this needs the admin scope' pinned at the dispatcher's requireScope choke (ShellsInputTest precedent; fixture-wire clients are owner-scoped at open, docblock states this honestly).

## (4) About-modal (d52e29e9a)
Logs section renders ONLY under `canAdmin = computed(() => connection.scopes.includes('admin'))` — the same door as the C-WEB-5.g stop control; plain `<pre data-testid="about-logs">` with a refresh button; `loadLogs()` carries the generation latch (stale-visit answers AND errors dropped, shared rows untouched); `load()` clears logs/logsReason/logsError on every re-open; fetch is load-driven so a non-admin never sends `server.logs` at all; `no_log` token renders the chrome note (never a blank pane), refusal lands on its own row while info/health keep painting. Spec: 4 new tests incl the design-required 'hidden without admin' pin (demotes BEFORE open and asserts `wire.sent('server.logs')` is empty); file holds 16 it() = 12 pre-existing + 4 new.

## (5) Gate runs by reviewer (lane worktree, cwd=candy-code, plain pipe)
`env -u LINES -u COLUMNS vendor/bin/phpunit -c phpunit.xml --filter '(LogTailTest|ServerLogsTest|ServeArgsTest|ReadPathCensus)'` →
`Tests: 89, Assertions: 1494, Failures: 1, PHPUnit Deprecations: 2.`
`--filter '(SymbolCitationDrift|ProtocolSchemaDrift|WebStringsDrift)'` →
`Tests: 18, Assertions: 19639, Failures: 2, PHPUnit Deprecations: 2.`
VITEST NOT RE-RUN: this reviewer's permission map rejected every shape tried (relative binary, npx, env-prefixed, unpiped — 4 real attempts, all `permission.rejected`; house law forbids bash -c/eval bypass). Builder REPORT quotes 16/16 with --no-file-parallelism; spec is statically consistent (16 it(), correct frame-answering order). Recorded residual risk, NOT a lane defect.

## (6) Census attribution (duty 6)
The single ReadPathCensus F is `testTheCensusIsDerivedFromSrcAndFullyClassified` and its assertion diff names ONLY the known-foreign `MediaMethods.php` rows (file_get_contents 2-vs-1, fopen 3-vs-2; roster never minted at C-TUI-6.n weld 1784d207f — separate master fixer in flight). The lane's own flips — `Cli/Serve.php|fopen` reduced to the follow() row (2→1) and new `Server/LogTail.php|fopen` SELF_LOCATED row — produce ZERO diff entries: green in isolation. StderrEmitterCensus correctly NOT flipped (no stderr output paths moved; design's "if output paths move" clause not triggered). The 2 drift Fs in (5) are the sanctioned RESTORE-law shape: JSON schema + web-strings mirror lag src until the weld regen (builder proved both green with trio present, then restored).

## (7) Commit law (duty 7)
All three commits: `%ae` grep-c detain = 1 AND `%ce` grep-c detain = 1 (separate greps), zero Co-Authored trailers, author 'Joe Huss <detain@interserver.net>'. Lane worktree clean apart from its own untracked evidence dir; no worktree/branch hygiene issue for the welder to chase beyond normal prune.

## (8) Security lens (duty 8)
Admin scope is the only door (R13 honored on both server and client); write-scope refusal tested; flatten applied to EVERY line before emit (control bytes cannot forge frames, each line hard-clipped to 200B); answer additionally capped at 200 lines AND 32,768 joined bytes with a behavior pin that the byte cap binds and trims oldest; lstat-not-stat refusal blocks symlink escape from the state dir; the RPC exposes no path parameter (always the server's own state dir); reason travels as the machine token `no_log` — no untranslated server prose, no path disclosure beyond what Admin already holds (an admin can stop the server and read transcripts).

## Weld-carry owed (sanctioned, not lane findings)
- Regen trio: `php scripts/gen-protocol-schema.php --write` + SERVER.md docs regen + `npm run gen:protocol` (then vue-tsc — AboutModal's typed `connection.request('server.logs')` only typechecks after generated.ts carries the method; vitest does not typecheck).
- `npm run gen:strings` + `php scripts/gen-web-strings.php --write` for the 4 en.php keys; web `dist/` rebuild per the tracked-dist law (AboutModal.vue changed).
- durations.tsv rows for the 2 new test files: tests/Server/LogTailTest.php, tests/Protocol/ServerLogsTest.php.
- Foreign base dirt rides the welder/gate as usual (MediaMethods census rows, PtyRelay stderr roster cured at cd3d1aecb).

VERDICT: CLEAN
