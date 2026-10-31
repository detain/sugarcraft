# C-TUI-6.j — REVIEW-1 (r1 reviewer, wave W34 LATE)

VERDICT: FINDINGS — C=0 M=1 MINOR=2 NIT=2

Reviewer: fresh r1 lane-reviewer. Method: ANTI-POISON honored — all src/test judgments derived from commit BLOBS (`git show c68ac29ae:<f>` / `b8b92b490:<f>`), never working-tree contents; no worktree mutation by me. Builder REPORT re-statted at open: 8,056 B @ 08:16:31 (matches lead). Branch cc/C-TUI-6.j confirmed; base 9a0c569eb; tip b8b92b490; porcelain 0 at open and remained 0 throughout my session (re-checked after each gate); no residue events observed.

## Q1 — Deviation adjudications (vs design block design-tui.md :1012-1044, lane copy)

(a) `tui.sr.*` ZERO keys — ACCEPT. Design files.modify line literally lists "`candy-code/lang/en.php`: `tui.sr.*`" (and the serialized `[lang:tui.sr.]`), so the clause EXISTS; but C-TUI-6.i's welded `ScreenReaderMode::menuRow()` (ScreenReaderMode.php blob, docblock) pins the position form " n/of" as locale-neutral BY DESIGN and says only "richer 6.j phrasings MAY live under tui.sr.*" — permissive, not mandatory. No new-path string is user-prose: '> ', digits/'of', '● ' and box-glyphs are punctuation/graphics (Lang::t() not bypassed without right — nothing translatable is emitted). Minting unused keys = dead surface (project law). Deviation justified; NIT-2: §6 ledger should record the design clause as adjudicated-superseded by the 6.i helper contract.

(b) SessionPicker refusal — scope-CORRECT, seam REAL → MINOR (out-of-scope). Design MENUS bullet ("Palette, session picker and permission options ... paint menuRow()") DOES expect picker wiring; files.modify misattributes painting to "src/Renderer.php renderSessionPicker" — at tip the rows/boxes paint in src/Tui/SessionPicker.php (:1248 `Border::rounded` unwired, :1289 raw ─ separator, :1336 '▶' row marker — U+25B6 is OUTSIDE U+2500-257F so glyph() would not shed it anyway). Refusing undeclared files is the lane law (builder reported the seam in the test docblock + REPORT §deviations, did not silently widen). Consequence: SR-mode box-glyph leak persists while pickers are open → LEAD ACTION OWED: follow-up lane widening files.modify, or dated design erratum.

(c) Overlay drops status bar + agent header — MINOR (step-local, needs ruling). Design text: overlay "REPLACES the body" — shipped `$frame = $overlay` (Renderer.php blob, renderView()) replaces the WHOLE frame: status bar and agent header vanish under SR. Literal "body" ≠ frame, and the file's own E48 doctrine calls the status bar "the row that survives" clipping — tension with the shipped choice. Behavior IS pinned (testAnOpenOverlayReplacesTheBody blob asserts body-gutter 'user>' absent under SR), builder disclosed it as deviation #3 requesting ruling. Not fabricated, not broken; adjudicate: keep (screen-reader users get one clean announcement surface) or restore bar — lead ruling, design text does not settle it.

(d) extra NIT-1: TerminalNotifier::fromConfig docblock "a user who DID set a mode keeps it untouched" overstates — explicit `screenReader => 'off'` ALSO upgrades to bell (testScreenReaderModeUpgradesOffToBell blob pins both [null] and ['off'] ring). Wording fix only.

## Q2 — Vacuity hunt (builder REPORT lists M1-M5; cp to /tmp DENIED by permission map → I ran NO mutations; static derivation only, as predecessors)

Blob-traced the builder's five outcomes — all FIVE are LOGICALLY SOUND: M1 neuter fromConfig upgrade → BellCue rawBytes [] vs ["\x07","\x07"] RED + TurnEnd upgradesOffToBell RED (2 ✓); M2 drop requestPermission cue leg → 2→1 RED (1 ✓); M3 neuter palette menuRow branch → position regex ">.*1/3"-style leg RED (✓); M4 remove `$frame = $overlay` → 'user>' survives under SR, testAnOpenOverlayReplacesTheBody RED (✓); M5 strip border() at input-box site → no-glyph walk catches │/┌ codepoints RED (✓).

HOWEVER FOUR WIRED LEGS HAVE NO DISCRIMINATING PIN (deletion → whole suite stays green):
(i) permission-options `menuRow()` leg (Renderer.php blob ~:7652-7675): no test asserts n/of position rows on the permission frame — frames() includes 'permission prompt open' only in the BOX-GLYPH scan, and menuRow emits no U+2500-257F runes, so its removal is invisible; the srOverhead probe vanishes silently too (off-mode byte-identity preserved either way).
(ii) diff-gutter `glyph()` leg, Renderer.php blob ~:5833 — frames() has NO diff-view fixture (plain/keyHelp/palette/permission only).
(iii)(iv) Tui/Renderer.php `glyph()` sites :1393 (review-rule) and :2930 (ellipsis) — the new tests render ONLY SugarCraft\Code\Renderer::render; no test drives Tui\Renderer under armed SR.
Builder disclosed (i)-(iv)-class reasoning ("legs feed the same no-glyph walk") but never mutated them; my derivation shows the reasoning FALSE for those four. Wiring itself is blob-correct and off-mode byte-safe — this is a TEST-GAP, not a behavior defect. **MAJOR-1**: extend ScreenReaderRenderTest frames() with a diff-tool-call fixture + a Tui\Renderer review-rule frame under SR, and a position-row assertion on the permission frame (e.g. regex '/ 1\/3$/' leg), so every wired leg reddens its deletion. Per rubric: "a pin that survives deleting its own fix is a MAJOR".
Pinned-and-sound (no finding): 7 border sites (no-glyph walk covers each frame shape), compaction-rule glyph :4415 (─ inside frames()), palette branch, overlay swap, fromConfig upgrade, cue batching.

## Q3 — SR honesty at merged-tree state

ScreenReaderMode.php read at TIP (post-6.i weld + cure 00fedf519): cue() = Cmd::raw(BEL) null under off; view() "must NEVER emit" law intact; border() keeps titles, sheds U+2500-257F. New call sites use it correctly: bell fires ONLY from update()-side (requestPermission batching + terminal event), never from render/view — no re-announce; fromConfig upgrade decides once at construction (mode read via public readonly), silent for non-off modes. Overlay SR path cannot leak the visual body (frame swap, pinned). NON-SR path: every helper is door-guarded returning the SAME instance/bytes when off (palette off-branch byte-identical original; srOverhead=''/probe 0 under off) — byte-identity is pinned STRUCTURALLY (off-negative legs in RenderTest) + by the existing golden family; no NEW golden file was minted (design's "goldens" satisfied via existing captures + off-legs — NIT-grade, acceptable). KeyHelpTest 63T/96589A at tip shows exactly the 5 accepted dataset reds — goldens did not move.

## Q4 — §1.8 guard law across both picks: ALL NOT-OWED (proofs) or verified green

EnvRosterDrift NOT-OWED (no new env var: `git show b8b92b490 | grep -c 'getenv'` = 0 in src hunks). KeyBindingDrift NOT-OWED (zero key-routing lines in either pick). ReadmeRosterDrift / DocFigureProseDrift NOT-OWED (no README/docs/SETTINGS.md in diff — `git diff --name-only 9a0c569eb..b8b92b490` = 7 code/test files only; no word-number prose moved). StderrEmitterCensus/ReadPathCensus NOT-OWED (grep: zero new error_log/stderr/file-read call sites in c68ac29ae). SuiteSkipRoster NOT-OWED (zero markTestSkipped added). Generated files (suite-figure.json, durations.tsv, dist, protocol trio, SERVER.md, generated.ts, ansi tables) absent from diff — protocol-lanes-restored law holds. DuplicatedTestHelperDrift RUN green: bare class `DuplicatedTestHelperDriftTest` 14T/204A OK — the 3 new files' byte-identical removeTree/writeConfig/rawBytes helpers + divergent askingChat tolerated (e78d5dc14 cures still green at tip = real finding avoided). TreeWideGuardRosterTest RUN green bare-name 17T/3869A (+2..4A scan wobble vs weld-era 3857-3865A, disclosed, roster not moved). One-type-per-file + declare(strict_types=1) + final classes: verified in blobs.

## Q5 — Scope, removals, needles

Machine-diff both picks: 7 files = design declared set MINUS the two refused/deferred seams (Q1a/Q1b). Test diff: exactly −1 removed line (a docblock sentence rewrite) — NO assertion leg removed/weakened anywhere; `markTestSkipped` added: 0. Absence needles are renderable: 'user>' / 'marsh-' / 'zebra-' tokens exist in the off-mode fixtures (falsifiable both directions; no glob-shaped literals — codepoint-walk instead, GlobDialect corpus contribution ZERO, see Q6). en.php untouched → nothing to pin. §7 MEASURED LIVE: ScreenReaderRenderTest 5T/28A; ScreenReaderBellCueTest 2T; TurnEndNotificationTest 8T→10T (+2 in-file). Floor moves +7T; durations rows NOT minted (lane law) — §7 closeout duty.

## Q6 — Weld readiness (gate figures measured at tip, cwd=candy-code linked vendor, plain pipe)

- ScreenReaderRenderTest:: 5T/28A OK (builder claim EXACT).
- BellCue+TurnEnd pair 12T/26A OK; ScreenReaderModeTest:: 11T/54A OK (6.i intact).
- Lane family (Render+Bell+TurnEnd+Mode+AbandonedPalette) 31T/117A OK == builder figure.
- Guard set (DocFigure|EnvRoster|KeyBinding|ReadmeRoster|StderrEmitterCensus|ReadPathCensus|SuiteSkipRoster|GlobDialect) 538T/71549A, ONLY 1F = ACCEPTED GlobDialect staleness (PathGlob docblock quotes 310,245 vs derived 560×556; same derived shape as merged-tree weld records → lane moved it ZERO; asserting neither number).
- TreeWideGuardRosterTest 17T/3869A OK (BARE); DuplicatedTestHelperDriftTest 14T/204A OK (BARE) — e78d5dc14 cures READ GREEN as required.
- KeyHelpTest:: 63T/96589A = exactly the 5 accepted dataset reds, no 6th; builder's disclosed extra timeLimit abort did NOT reproduce in my run.
- Accepted set adjudicate-only: keepWarm/HelpTest[TIER]/Dispatch:1893/AttachPaging/OneSided:435/staleness-pair NOT re-run (lane files reach none of those fixtures; carried). Set has NOT shrunk (still 8 items) and nothing MOVED. No new foreign family. 00fedf519: screenReader pinned-roster guards green in my 538T window — no old-blob misread.
- Mutation EXECUTION: DENIED by my permission map (cp -a blocked) → static trace only, disclosed above; builder's M1-M5 logic independently re-derived SOUND.

## Findings list

- MAJOR-1 (step-local, fix-before-weld): four wired legs unpin-able — permission-options menuRow (Renderer.php blob ~:7652-7675), diff-gutter glyph (~:5833), Tui/Renderer.php :1393 + :2930 glyph sites. Fix: extend frames() + add permission position-row assertion (Q2).
- MINOR-1 (out-of-scope, LEAD ACTION): session picker/dirPicker/historySearch SR leak — design MENUS demands menuRow there; row painting lives in undeclared src/Tui/SessionPicker.php (:1248/:1289/:1336). Follow-up lane or dated design erratum.
- MINOR-2 (step-local, ruling needed): overlay frame-replacement drops status bar + agent header; design says "REPLACES the body"; E48 "row that survives" doctrine in tension; shipped+pin-able either way (Q1c).
- NIT-1: fromConfig docblock wording overstates ("DID set a mode keeps it untouched" vs explicit 'off' upgrades).
- NIT-2: design's tui.sr.* files.modify clause adjudicated-superseded by 6.i locale-neutral contract → §6 ledger note (Q1a).

Bottom line: wiring reaches PRODUCTION render paths (renderView callers Chat.php:6335/Renderer::render:1666; notifier built App.php:6460/Chat.php:2991) — NOT a REACHABLE-GAP; scope/guard law clean; one genuine vacuity MAJOR (test gap, fix is ~1 test-method-sized), plus the picker seam that belongs to the lead.
