# C-TOOL-6.l — REVIEW-1
LANE=/home/sites/cc-cmp-C-TOOL-6.l branch cc/C-TOOL-6.l base 843dff284 tip b623ca99b (re-derived on disk; porcelain 0)
Picks: d4f4c989a server (ShellsMethods/Schema-MethodSchemas/Schema-Definitions/Protocol-ServerContext/tests ShellsMethodsTest) · 9874d426b ShellsInputTest · 26d3c92e9 interactive-flag row (ShellsMethods.php row + Definitions.php + ShellsMethodsTest key roster — brief narrative said the roster flip rode d4f4c989a; disk wins) · b623ca99b web pane+lang trio. 16 files +1489/−10 exact. Authors %ae/%ce 4/4 detain, zero trailers. Builder REPORT.md 4582B, line 1 STATUS: COMPLETE.

VERDICT: CLEAN (0 CRITICAL / 0 MAJOR / 0 step-local MINOR / 3 NIT — none blocking weld; NITs are narrative/semantic notes below)

## Q1 INPUT VALIDATION — CLEAN
- MAX_TEXT_BYTES=4096; INPUT_KEYS = 11-key ordered subset of PtyRun::KEY_TOKENS (Enter,Esc,Tab,Backspace,Up,Down,Left,Right,C-c,C-d,C-z) — each verified ∈ the CURRENT welded table (incl C-z=\x1a). No out-of-subset key.
- Composition text . KEY_TOKENS[key] (ShellsMethods.php typeBytes); bad key refuses pre-composition (shell_input_key).
- Resolver trace: registry(soft:false) → SessionMethods::sessionId owner → registry->send($id,$bytes,'human',$owner) — SAME owner pattern as welded shells.output/stop arms; no new auth path. Foreign == unknown (identical sentence → shell_not_found; probe-resistance preserved). Refusal map traced line-by-line vs registry sentences: not-interactive→shell_not_interactive; finished→shell_settled; capped→text_too_long; FIFO-open/not-reading→shell_relay_unready; catchall shell_input_refused. No path admits unvalidated bytes to a live fifo; no foreign-session shell addressable.
- 4097 boundary pins LIVE: schema path invalid_params (Params::optionalString cap) + composed 4096+Enter=4097→text_too_long with exact registry message; deletion-reason: removing either cap leg falls bytes to shell_input_refused → pins red. EXEC-confirmed via ShellsInputTest run (below).
- Lone-CR vs CRLF (deviation-4): both-direction pins honest and discriminating ('phase1\rphase2'→rewrite vs 'one\r\ntwo'→newline) — matches pty reality.
- Read-scope EXEC (deviation-3): requireScope choke tested directly; comment honestly discloses Dispatcher::onOpen grants owner scope to every wire connection (welded behavior) — posture clean.
- NIT-1: {text:''} lands invalid_params (non-empty-string) rather than shell_input_empty — refusal either way, no leak, semantics noted only. NIT-2 (lead-side): brief pick-narrative misattributes roster flip pick. NIT-3 (lead-side): builder claimed 505A combined; my measurement 504A (see gates; my figures rule, not a lane defect).

## Q2 AUDIT LINE — CLEAN
One line per call in finally (incl refusals, bytes=0, empty fields); shape only: AUDIT_FORMAT session/shell/client/principal/bytes=%d. auditField strips NUL/CR/LF→'?' + clips 128 (single physical line). ServerContext.php:180 null-sink no-op pinned. Added-line grep: ZERO text/bytes interpolation into logLine and zero new sinks (proc_open/fopen/error_log/file_get_contents/shell_exec absent from all added lines). 1/2/3-count pins EXEC-green with random_bytes marker asserted absent.

## Q3 TRIO + WELD-CARRY — CONFIRMED
- Per-pick git show --name-only ×4: ZERO touches to docs/protocol/candy-code.v1.schema.json, docs/SERVER.md, src-web/protocol/generated.ts, candy-code-web/dist. Full-span diff on all four = 0 lines. Sanctioned lang pair (web-strings.en.json + strings.en.generated.ts + en.php, 2 keys each) rode b623ca99b legitimately.
- ProtocolSchemaDriftTest staleness arm IS RED AT TIP BY DESIGN (regen-verify-RESTORE model, 6.i precedent); its failure diff shows exactly the missing shells.input params/result block.
- SERVER-MANUAL-SECTION.md confirmed ABSENT on disk ⇒ UNOWED; +1 SERVER.md line plausible as one generated method-table row.
- Drift figures for welder: schema ~+68/0, SERVER.md +1/0 (generated row only), generated.ts ~+22/0, dist REBUILD OWED (src-web moved). +68/+22 exact counts UNCONFIRMED-BY-RUN (lane-side regen forbidden; builder-derived, shape plausible).

## Q4 §1.8 PER-CLASS — CLEAN
- Byte-consistency law pinned live (spec name==schema key, enum===INPUT_KEYS order, ⊆ KEY_TOKENS, maxLength===MAX_TEXT_BYTES) — 5.d dormantRoster precedent shape.
- Web/lang pair guards EXEC: 553-key dupscan class guards green (runs below). Definitions.php + en.php outside design files.modify = deviation-1, DoD-FORCED minimal (schema row needs `interactive` field; pane strings need lang keys) — judged acceptable, not scope creep. interactive-flag row rides 26d3c92e9 with Definitions.php (locality ✓).
- ReadPathCensus: anchored run green, no new sinks. GlobDialect: added-line scan ZERO glob-shaped literals (every added '?'-bearing literal contains spaces → non-admitting); the corpus-figure arm is BASE-INHERITED debt (310,245 docblock vs 561×557 derived in that run) — adjudicate-only, cure NONE, NEITHER number asserted anywhere in this lane's fixtures.
- DTH: BARE DuplicatedTestHelperDriftTest green (helper renames worked). TreeWideGuardRoster BARE green. One-type-per-file holds; word-numbers derived live (no glued figures added).

## Q5 GATES — MY FIGURES (cwd=candy-code -c phpunit.xml, env -u LINES -u COLUMNS, plain pipe, anchored full stems)
- `(ProtocolSchemaDriftTest|ShellsMethodsTest|ShellsInputTest)::` → Tests: 45, Assertions: 504, Failures: 1 — the 1F is ProtocolSchemaDriftTest.php:39 staleness arm (by-design, welder regen). ShellsInputTest alone: **Tests: 31, Assertions: 144, Failures: 0, Skipped: 0** — OK (live pty/FIFO echo legs — text+Enter, 6 keys, C-d settle→shell_settled — all RAN here, no unrostered skips).
- `(WebStringsTest|WebStringsDriftTest)::` → Tests: 9, Assertions: 588 — OK. `(CliLangCatalogueTest|LangParityTest)::` → Tests: 15, Assertions: 4256 — OK.
- BARE TreeWideGuardRosterTest:: → Tests: 17, Assertions: 3989 — OK. BARE DuplicatedTestHelperDriftTest:: → Tests: 14, Assertions: 204 — OK.
- `(ReadPathCensusTest|GlobDialectDifferentialTest)::` → Tests: 32, Assertions: 1137, Failures: 1 (base-inherited GlobDialect corpus figure, named-not-cured).
- vitest SERIAL (`--no-file-parallelism`, absolute .bin, pre-seeded node_modules, no npm ci): 3 files → **Tests 32 passed (32)** — ansi.spec + processesSection.spec + shellsInput.spec (8 new) — builder 32/32 reproduced byte-exact.
- vue-tsc: **UNVERIFIED-BY-RUN** (permission-map denial; claim 0 errors credited, not contradicted — TS surfaces were exercised by vitest transform + pair guards). `php scripts/gen-protocol-schema.php --check`: **UNVERIFIED-BY-RUN** (map denial); staleness shape independently visible in the phpunit drift-arm failure diff.
- E646: zero server-side timers/blanket timeouts in the diff; client polling setInterval 750ms tail / 3000ms list only. Exclusions honored (PtyRunToolTest expect-miss, RegistryTest shrink, ForkedChildReaperAdoption); no full suite run.

## Q6 INVENTORY + WELD-READINESS
16 paths = design-declared + disclosed deviations 1-4 (Definitions/en.php pair; read-scope pin shape; pick-order roster flip; lone-CR fix) — all judged above. Zero weakened legs (10 deletions: README wrap, roster +interactive, class-docblock reword). No residue; no '**Refused:**'-shaped literals; sentinels random/uniqid-shaped.
WELD-ELIGIBLE: **YES**.

## §7 LIST (welder duties — never fixed here)
1. durations.tsv: NEW row `candy-code/tests/Protocol/ShellsInputTest.php` at my live **31 cases** (16 methods); note vitest 8-test file shellsInput.spec.ts (JS side, no tsv row).
2. Suite carry: disclose **+31T-class** candy-code enumeration delta; do NOT touch suite-figure.json here — re-pin at battery (my targeted counts are NOT suite figures).
3. Trio regen + dist rebuild OWED (6.i precedent): schema ~+68/0, SERVER.md +1/0 generated row only (NO manual section — SERVER-MANUAL-SECTION.md correctly absent), generated.ts ~+22/0, dist rebuild on pre-seeded node_modules (NEVER npm ci). PRESERVE 6.d `## Windows` prose + 6.i manual para BYTE-INTACT through regen — prove by slice-check. ProtocolSchemaDrift must go GREEN at regen.
4. Collision unions vs landed 6.a/6.d/6.h/6.i/6.k BY TEXT (README rows, en.php spans, ProcessesSection) — conflict = STOP. PRESERVE Bash.php:535 double-quote. en.php span unions with sibling keys.
5. WARN next welder: 6.g trio canvas carry (6.g still in review loop; its lane owns generated-file restoration).
6. Foreign-red roster (adjudicate-only, cure NONE): PtyRelay StderrEmitterCensus 2F (6.j debt 75174ea40) · LaunchContextDelegatesTest tests/Cli:34 (df106a84c) · ForeignAgentPresetWiringTest PermissionMode · MenuBarLabelDerivationTest base-inherited · GlobDialect corpus-figure (this run) · suite-figure staleness pair — never touch. SeamCallSiteCensusTest/TurnRunnerTest DO NOT exist (do not filter for them).
7. Pairing `BashSandboxTest|BubblewrapHardeningTest` co-run: base carries db1441a61 ⇒ GREEN expected; red at weld = STOP-report, never cure.

STATS: builder REPORT.md 4582B (unchanged, read-only law); REVIEW-1.md bytes = see read-back below.
