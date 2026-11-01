# C-TUI-6.n — WELD record (welder, 2026-11-01)

## Preflight (evidence re-stat by welder, from the FILES)
- Echo `STEP=C-TUI-6.n ROLE=welder` stood. Sole master writer; NO push/fetch/pull performed.
- REPORT.md **11,628 B** — line 3 single honest `STATUS: COMPLETE — 3 picks on cc/C-TUI-6.n...`; no lag line.
- REVIEW-1.md **12,169 B** — line 3 verbatim: `VERDICT: FINDINGS — 0 CRITICAL / 0 MAJOR / 1 MINOR (step-local) / 2 NIT; WELD-ELIGIBLE: YES`. Both read IN FULL by the welder.
- FIX-1.md ABSENT (dir carried exactly REPORT.md + REVIEW-1.md at preflight) — consistent with 0 MAJOR/CRITICAL; not invented; no foreign FIX appeared mid-flight.
- Own heading `#### C-TUI-6.n —` present-UNMARKED exact-form (python startswith probe, :4020); marked count **325** at preflight. Master pre-weld HEAD 22f346e92 (6.b run-state fold).
- Lead dirt present exactly as briefed and NEVER staged: `.opencode/agents/cc-cmp-lead.md` M, AGENT_LAW_SNIPPET.md, LEAD_LAWS.md, the harness-redacted untracked stray, lanes/* era WELD-dirt. Index was clean at preflight — NO reset needed, no snapshot taken.
- Lane law greps (in-lane, 31000e3be..HEAD): %ae detain **3** · %ce detain **3** (SEPARATE COUNT greps) · Co-Authored **0** · porcelain **0**.

## Picks (explicit list read FROM the LANE via git log, IN ORDER, never a range, no -x)
`fb021a61a → 1784d207f` (6 files +855/−12) · `96004109a → 7dc6d59a7` (11 files +1075/−21) · `158b1c9a9 → e990fb014` (MEDIA.md +17/−3). ZERO conflicts — moved-master union never had to be forced: lane base 31000e3be is 6.b's own pre-weld tip and 6.b's span (NotebookEdit/ToolCatalog/word-maps) intersects NEITHER MEDIA.md nor en.php; every region auto-merged. Merged span 18 files +1947/−36 == r1's 855+1075+17/12+21+3 arithmetic EXACT. Reflog: exactly THREE cherry records, ZERO foreign movement. Law at landed set: %ae 3 · %ce 3 (separate counts) · Co-Authored 0. Residue grep on added lines (if (false|&& false||| true): 0.

## Union / preservation proofs on merged tip (BY TEXT)
- Voice-core ZERO-DIFF: `git diff 22f346e92..HEAD -- candy-code/src/Media/Voice/` = **0 lines** (engine called, never edited — 6.j/6.k/6.l/6.m era law honored).
- Name-diff ZERO: Bash.php · PermissionGate* · KeyBindingRegistry · Renderer.php · HandlesKeys · Canvas/completer regions (cc/C-TOOL-6.g read-only lane) — 0 paths.
- Bash.php `"bash -c "` double-quote blob-count **1** at merged tip.
- en.php: 8 web.composer.voice.* keys merged sorted (voice.title at :4512 — lane-era :4506-4513 region), PYTHON dupscan over key column: **4,032 keys, 0 dups** (NEVER grep -c).
- PermissionGate untouched by lane AND by weld (name-diff 0); user__→client__→verbatim mcp__ ordering stays exactly as welded.

## TRIO REGEN + DIST REBUILD (owed carry — 6.i/6.l-tool precedent, fourth carrier)
- `php scripts/gen-protocol-schema.php --write`: schema **+98/−1** (8,073→8,170) · docs/SERVER.md **+2/−0 PURE-GENERATED** (1,622→1,624 — the two audio method-table rows). SERVER-MANUAL-SECTION.md VERIFIED ABSENT ⇒ manual rows UNOWED (no hand section invented).
- `npm run gen:protocol` (node scripts/gen-protocol.mjs): generated.ts **+32/−1** (2,384→2,415).
- `npm run build` on pre-seeded node_modules (NO npm ci, NO composer/refresh-deps): index.html/manifest.json/sw.js M · assets i18n-CFrqbToD.js→i18n-BRXRQkR2.js, index-Cg5UjlKP.css→index-BmdNjbGT.css, index-DQpCiax8.js→index-D7O4eLLT.js (3 deletions, 3 adds).
- SLICE PRESERVATION pre==post regen, re-anchored by same convention: 6.d `## Windows` slice :1582-1590→:1584-1592 md5 **a96a7db6dc0ad643b0681b63e2b94582 EQUAL** (byte-equals the full value C-TOOL-6.l/WELD.md recorded — prose unmoved since 6.l) · 6.i "Client-hosted tools." para :644-655→:646-657 md5 **761aa2a0cd2b919a76eddc697383c7ec EQUAL** · 6.l-tool generated row `  "shells.input",` :1970→:2001 md5 **35692e4e504f5e625381b251a9ac71a2 BYTE-INTACT**.
- DISCLOSED erratum-vs-lead-token: the dispatched anchor "c0c0501d5dc0" is C-TOOL-6.l's own re-derive token (their terminal record: "my own slice re-derive") and is NOT reproducible from current bytes under any boundary candidate tested (sed ranges ±1/±2, python split variants); the 6.l WELD.md-recorded sed-tradition hash a96a7db6dc0ad643b0681b63e2b94582 reproduces EXACTLY today pre==post — positive no-move proof stands; STOP-condition ("if any moved") not met, nothing moved.

## Post-regen gates (welder's OWN runs; cwd=candy-code -c phpunit.xml, env -u LINES -u COLUMNS plain pipe, anchored FULL Test stems, NON-ZERO each; the 2 PHPUnit Deprecations per run = accepted-foreign AccessorDeprecation provider-roster shape)
- **ProtocolSchemaDriftTest GREEN-at-regen**: `(ProtocolSchemaDriftTest::|ServerProtocolDocumentationDriftTest)::` **Tests: 13, Assertions: 362, OK** (builder's regen-tree figure reproduced EXACT; pre-regen 1F was by-design staleness).
- Byte-consistency post-regen: `(WebStringsTest::|WebStringsDriftTest)::` **9T/596A OK** · `(CliLangCatalogueTest::|LangParityTest::)` **15T/4271A OK**; sanctioned mirrors web-strings.en.json + strings.en.generated.ts porcelain ZERO (byte-identical post-regen; they rode pick 2 legitimately — NOT the trio).
- `AudioTranscribeMethodTest::` **Tests: 10, Assertions: 55, OK** — the §7 MINT reproduced exact.
- Voice server family (AudioTranscribe|MediaAffordance|HelloMediaFlags|MediaMethods|SecuritySweep|Transcriber|AudioRecorder|VoiceConfig|VoiceSession|TranscribeCmd|TreeWideGuardRoster|DuplicatedTestHelperDrift|DiscoveryFile|StateDir) co-run **166T/4784A OK**. FIGURE SHAPE DISCLOSED: builder 166T/4776A did not reproduce, r1 got 179T/4845A on their identical stem list — per the r1-figures rule THIS weld quotes its own filter shape above; green is green.
- BARE TreeWideGuardRosterTest **17T/4003A OK** (lane rows additive; all master rows survive — 6.b-weld-era 4,001 + merged-tree drift, disclosed) · BARE DuplicatedTestHelperDriftTest **14T/204A OK** exact.
- PAIRING `(BashSandboxTest::|BubblewrapHardeningTest::)` ONE process **39T/192A GREEN** (cure db1441a61 in force; never cured at weld).
- `BootstrapLayeredSettingsTest::` **Tests: 66, Assertions: 137 — zero Failures** (cure 00fedf519 in force, folded NOTHING).
- Generators `--check` **rc0 ×4** (gen-command-docs, gen-tool-docs, gen-settings-doc, protocol-schema) — only the sanctioned trio+dist carry moved.
- Vitest SERIAL `--no-file-parallelism` five web files: **Test Files 5 passed (5) · Tests 56 passed (56)** — recorder 16 + dictation 19 + connectionMedia 6 + composerVoice 6 + composerDictation 9 (r1's 16+40 decomposition reproduced on the merged-regen tree).
- MINOR-1 PROOF (typecheck gate): `npm run typecheck` at the REGENERATED merged tip — connection.ts(98,65) TS2339 'transcribe' **GONE**; lane-touched files (stores/connection.ts, lib/recorder.ts, lib/dictation.ts, components/Composer.vue) **ZERO errors**. The foreign base-era error set PERSISTS (~58 TS2322/TS18048/TS2345/TS2532 lines: ansi.ts, SessionSidebar/ContextPanel.vue, shells.ts, contextSources.ts, *.spec files — r1 Q5 list) — NOT attributed, NOT cured, shape reported only.

## Security posture clause (from REPORT §Security-trace + r1 Q1, carried into §4 mark)
Endpoint-from-settings-ONLY (`MediaMethods.php` configuredVoice :681-693 reads VoiceConfig::fromSettings; the WIRE carries NO URL field — schema params {sessionId,audioBase64,mime,language?,idempotencyKey}); caps TRANSCRIBE_MAX_AUDIO_BYTES 8_388_608 / base64 bound 11_184_812 (< ServerConfig 16_777_216) with decoded check BEFORE writeTake (:546→:573) incl the 1-byte-window leg proving the decoded check load-bearing; Write scope + sideEffects (:213-218), audio.status Read idempotent-replay (:220-225); key NEVER ledger/wire — 502-hostile-echo lands `[redacted]` end-to-end; recorder.ts pre-prompt refusals :119/:122/:126 (no fake success, permission-denied :196); E646-clean: zero new server timers, the only JS timers are the bounded 240×500ms client poll + record-cap (patience, never a wall-clock kill); voice-core called-never-edited 0-line diff.

## NIT ledger
- NIT-1 (figure discipline): builder-vs-r1 server-family non-reproduction — resolved by the r1-figures rule: welder quotes OWN actuals above, never inherits.
- NIT-2 (method honesty): decoded-cap deletion→red was READ-ARGUED from pins (wrong-state assert + zero-file proof), reviewer mutation EXEC DENIED by its permission map (rev-6n-r1-mut + scratch attempts, map-wide cp/mkdir denials) — honest denial credited (6.e/5.e model), not a lane defect.

## ACCEPTED-FOREIGN carried unchanged-shape, cure NONE
ReadmeSuiteFigureDrift staleness pair (pinned 25,537/609,443 vs live ~29.7k-class — battery re-pin owns; THIS weld's **+10T-class carry DISCLOSED**: AudioTranscribeMethodTest 10T new file + HelloMediaFlagsTest new method; suite-figure.json + durations.tsv NEVER touched) · StderrEmitterCensus-era 2F PtyRelay (75174ea40) · LaunchContextDelegatesTest tests/Cli:34 (df106a84c) · ForeignAgentPresetWiringTest PermissionMode · MenuBarLabelDerivationTest doctor/recap 1F · SelfUpdateTest thirteen-verbs · keepWarm 1F · KeyHelpTest 5F+risky (chat.voice 0-hits per r1) · HelpTest[CANDY_CODE_SERVICE_TIER] · BinCandyCodeDispatchTest --worktree :1893 · AttachPagingTest 2F · OneSidedHomeSandboxTest :435 · GlobDialectDifferentialTest 1F (asserted NEITHER number; my edits moved no glob corpus). EXCLUDED from every filter (base-proven): PtyRunToolTest::testExpectMissIsLoudAndTheScriptStillContinues · RegistryTest::testAStdoutThatShrinksAlone... · ForkedChildReaperAdoptionTest Sessions/BackgroundShellRegistryTest item.

## §7 HAND-OFFS — LISTED, NEVER FIXED at weld
1. durations.tsv row for NEW candy-code/tests/Protocol/AudioTranscribeMethodTest.php at welder-live **10T/55A** (battery mints).
2. Web spec facts for the vitest rider ledger: recorder.spec.ts **16 tests** · composerVoice.spec.ts **6 tests** (vitest — phpunit durations.tsv N/A; §7 note only).
3. suite-figure/README pair re-pin carries the +10T-class.
4. RESTRICTED_TOOL_NAMES += 'Script' LEAD rider still pending.
5. web.yml gen:strings LEAD rider still pending.
6. VITEST-28/4 idempotency gate rider: full-suite web run stays `4 failed | 902` (shellsInput×2 + processesSection×2, C-TOOL-6.l foreign) until §7(1)-of-6.l lands — rider quoted from THIS weld's log below.
7. Sweeps owed lead-side: /tmp/opencode/rev-6j-r2-mut · /tmp/opencode/rev6k/ · /tmp/opencode/6l-names.txt · lead-index re-stage /tmp/opencode/cc-cmp-weld-C-TUI-6.d/lead-index-before-reset.txt.
8. Seam disclosed by builder: DiscoveryFile::parseMedia lenient triple-projection drops the new transcribe key — doc shape only, behavior unchanged.

## WARN NEXT WELDER
C-TOOL-6.g (W35-completer, in review) OWNS the trio canvas carry: regen schema +2/−1 MEDIA_REF html + generated.ts +1/−1 :227 + SERVER.md TWO MANUAL canvas rows VERBATIM (regen-wont-derive) + dist rebuild + WELD-MUST-GREEN pairing co-run (its base predates db1441a61) — it must preserve THIS weld's trio state (schema 8,170 / SERVER.md 1,624 / generated.ts 2,415) AND the 6.d `## Windows` slice + 6.i manual para + 6.l `shells.input` row byte-intact through ITS regen.
