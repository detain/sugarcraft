# C-TUI-6.j — REVIEW-2 (fresh r2 closure reviewer, wave W34 LATE)

VERDICT: FINDINGS — C=0 M=0 MINOR=1 NIT=3 (final at tip `12680b111`; see APPENDIX A — initial header M=1 was struck at the four briefed blobs and is superseded by the fifth pick, which closed it)

Review basis: BLOB-ANCHORED per mandate — judged only `git show` content of the four picks
`c68ac29ae` `b8b92b490` `afad9c717` `703a45998` on `cc/C-TUI-6.j` (base `9a0c569eb`). The lane
working tree was read ONLY to detect state (porcelain), never as evidence. Execution: the shell
allowlist denied `cp` of vendor into my scratch worktree, so targeted phpunit gates are
**UNVERIFIED-BY-EXEC** in this round; findings below rest on per-blob mutation reasoning and on
r1's live-measured figures where cited. Scratch worktree `/home/sites/rev-6j-r2` created detached
at `703a45998` and PRUNED (`git worktree list` after: main + cc-cmp lanes only).

## Q1 — MAJOR-1 (vacuous coverage on four legs): **NOT CLOSED at the blobs — re-raised MAJOR**

For each r1-named leg I asked: does any COMMITTED test redden if the leg is deleted from the
tip blob? Answer per leg, with the reasoning:

- **(i) permission-options `menuRow`** (`src/Renderer.php` blob ≈ :7652–7675, emits
  `ScreenReaderMode::menuRow($index + 1, count($options), $label, false)` beside the `$srOverhead`
  probe). The only committed consumer of a permission-prompt frame is the `frames()` scan in
  `testAFullFrameCarriesNoBoxDrawingCodepointWithTheModeOn`, which asserts absence of
  U+2500–257F; `menuRow` emits digits and a slash, never a box glyph. `testPaletteRows…` asserts
  the PALETTE frame only. BellCue tests assert Cmd bytes, not the frame. Deleting the permission
  `menuRow` call stays GREEN at the blobs. **UNPINNED.**
- **(ii) diff-gutter `glyph('│')`** (`src/Renderer.php` ≈ :5833). No committed fixture renders a
  split diff under the mode on; the `frames()` scan feeds chat/agent/palette/permission/overlay
  shapes only. Deleting the `glyph()` wrap (restoring bare `│`) stays GREEN. **UNPINNED.**
- **(iii) `src/Tui/Renderer.php` :1393** (diff-review rule `str_repeat` through `glyph()`): no
  committed test renders `Tui\Renderer` at all under SR (the render-test file imports only
  `SugarCraft\Code\Renderer`). **UNPINNED.**
- **(iv) `src/Tui/Renderer.php` :2930** (`dropdownOverflowLine` walls through `glyph()`): same —
  no committed `Tui\Renderer` drive. **UNPINNED.**

Namespace sanity (so the wiring itself is not falsely accused): `ScreenReaderMode` resolves
same-namespace inside `Tui/Renderer.php` without an import — fine at every blob.

**The fix exists — but only uncommitted.** `git diff --stat` in the lane tree shows
`tests/Renderer/ScreenReaderRenderTest.php` **+148 lines / 0 committed**, and `FIX-1.md` still
carries legs (i)–(iv) as unchecked `[ ] IN PROGRESS`. The pending hunk contains exactly the four
missing pins: `testPermissionOptionRowsCarryTheirPositionsOnlyWithTheModeOn` (positioned rows
`1/4…4/4` on-SR, `[]` off — reddens on deleting leg (i)), `testTheSplitDiffCentreGutterShedsItsDividerWithTheModeOn`
(off frame shows `1;│2`, SR sheds the centre — kills leg (ii)), `testTheDiffReviewRuleUnderScreenReaderIsBlankPaper`
(renders `TuiRenderer::render(reviewApp(), 90, 22)`, rule line trims empty on-SR vs `/^\x{2500}+$/u`
off — kills leg (iii)), `testTheDropdownOverflowLineShedsItsWallsOnlyWithTheModeOn`
(`MenuBar::openMenu(1)` on plainApp 100×12, `│ …` walls off vs shed row on-SR — kills leg (iv)).
I read the pending hunk as evidence of intent only; per blob law it cannot count as a pin.

**Disposition (step-local MAJOR):** commit the pending pins (resume fixer or lead salvage-commit —
lead's call), re-derive each leg's red-before under the mode on, gate (ScreenReaderRenderTest +
DuplicatedTestHelperDriftTest/TreeWideGuardRosterTest bare), then a NEW reviewer pass — r1 does not
re-review and neither does this round's closure on uncommitted content.
**→ SUPERSEDED: this disposition executed mid-review as pick `12680b111`; see APPENDIX A for the
closure analysis and final verdict.**

## Q2 — Ruling (c) (overlay replaces BODY only, chrome survives): **LANDED AS RULED** (one unpinned sub-leg)

`git show 703a45998`: in `renderView`, `$statusBar = self::renderStatusBar($chat, $drawing)` is
hoisted above the frame assembly (off-mode path: identical call site, the else/Veil composite
branch byte-untouched → off-mode BYTE-IDENTICAL demand met); the SR branch changes from
`$frame = $overlay` to
`$frame = ($agentHeader === '' ? '' : $agentHeader . "\n") . $overlay . "\n" . $statusBar;`.
Docblock cites the E48 "row that survives" doctrine and discloses the tab-strip-as-body reading —
matches design wording "REPLACES the body" + ruling (c). Commit subject explicitly tags
BEHAVIOR CHANGE — honest disclosure. Test diff is +8 lines, one pure-add leg inside
`testAnOpenOverlayReplacesTheBody`: `assertStringContainsString('Ctrl+P menu', …)` pinning
**status-bar survival in SR-on**. Red-before claim is statically sound (pre-`703a45998` swap
emitted no chrome row). **MINOR-1 (step-local):** the Agent-View-header splice
(`$agentHeader . "\n"`) has NO committed pin — the overlay fixture carries no agent header, so
deleting that concatenation leg stays GREEN. Fold a header-survive assertion into the pending
MAJOR-1 test commit.

## Q3 — Ruling (b) discipline: **PASS**

`git show --name-only` on all four picks: `src/Tui/SessionPicker.php` absent from every one. Its
row-painting stays a lead §7 rider / dated erratum, not lane widening.

## Q4 — Regressions + hygiene: **PASS** (with NITs)

- Assertion inventory: `afad9c717` is comment-only (±5/3, docblock); `703a45998` test diff is +8
  pure-add — nothing removed or neutered across the fix picks. Zero `markTestSkipped` in all three
  test blobs.
- NIT-1 CLOSED: the `fromConfig` docblock now reads that only an explicit `off` upgrades under an
  armed screen reader while a user-set mode is untouched — matches the code arm
  (`$this->mode === self::OFF && ScreenReaderMode::enabled() → BELL`) and the two `TurnEndNotificationTest`
  pins r1 measured. Class-docblock bullet from `c68ac29ae` equally truthful.
- NIT-2 holds: zero `tui.sr.*` keys — `lang/en.php` untouched by every pick (accepted design:
  `menuRow` rides C-TUI-6.i's locale-neutral pinned contract). No Lang key moved → no sorted-run duty.
- Residue grep across the FOUR BLOBS (not the tree, per mandate): zero `if (false`, `&& false`,
  `|| true` shapes in all four src+test blobs at tip; zero `Refused:`-shaped literals; one type per
  file; regex literals in the new pins contain excluded chars (`(`, `$`, `;`, spaces) or are the
  pre-existing `.*`-matcher shape → GlobDialect corpus unchanged (r1 measured 1F accepted).
- NIT-1 (figures): if the pending four methods land, §7 numbers move: render file 5T→9T, floor
  +7T→+11T; durations rows owed for both new test files.
- NIT-2 (scope): design block says "~15" Renderer + "~5" Tui raw-glyph sites; lane wired 2+2.
  The lead dispatch ratifies the pick as "4 raw-glyph sites" and the residual leak site lives in
  SessionPicker (r1 MINOR-1 lead rider) — record in the §6 ledger next to the tui.sr.* supersession
  note; not re-litigated here.

## Q5 — §1.8 guards at merged tree: **UNVERIFIED-BY-EXEC (carrying r1's live greens)**

`cp` of vendor into my scratch was allowlist-denied, so I did not run the gates this round and do
not claim I did. Reasoning that the two fix picks cannot move them: `afad9c717` is comment-only;
`703a45998` adds one assertion inside an existing method and reorders nothing —
`TreeWideGuardRosterTest` (bare: 17T/3869A GREEN per r1 at `b8b92b490`) walks guard rosters,
`DuplicatedTestHelperDriftTest` (bare: 14T/204A GREEN per r1 — the three sibling test files'
byte-identical `removeTree`/`writeConfig`/`rawBytes` helpers were tolerated) scans test-file
bodies: neither is sensitive to an added assert-string line or a docblock. Both MUST be re-run
bare-class-name (the `|Class::` form silently matches zero tests = false green) at whatever commit
finally lands the MAJOR-1 pins. EnvRosterDrift: NOT owed — no `getenv`/`Help.php` touch in any
pick (r1 verified grep 0).

## Q6 — Weld-readiness: **NOT READY**

Blockers: MAJOR-1 pins exist only as uncommitted tree content (lane porcelain non-zero — r1 ended
at porcelain 0, so the tree moved after `703a45998` was struck; MINOR-2 process finding: weld /
quiescence must not proceed on a dirty lane tree — lead decides resume-fixer vs salvage-commit vs
reset+re-cut). Plus MINOR-1 header pin. Residual red set: exactly the accepted-foreign roster —
keepWarm 1F; 5 KeyHelpTest datasets +1 risky; `HelpTest[CANDY_CODE_SERVICE_TIER]`;
`BinCandyCodeDispatchTest --worktree` :1893; `AttachPagingTest` 2F (base-proven `364a52876`);
`OneSidedHomeSandboxTest.php:435`; `ReadmeSuiteFigureDrift` staleness pair;
`GlobDialectDifferentialTest` 1F (PathGlob docblock 310,245 vs derived — asserted neither number,
per law); `SettingsEditorTest:189`; `ChildStderrCaptureTest` 2F; `AccessorDeprecationTest`
:168/:202; `BootstrapLaunchFormatConstantsTest` doc-roster leg; `Cli/HelpTest`
env-var-mentions 1F (master-proven foreign). No new family, nothing moved.

## §7 list (closeout riders — never fixed in-lane)

- ScreenReaderRenderTest 5T/28A (r1-live) → re-derive after pins land (~9T); overlay-method legs
  6→7 at tip (static 29A — re-measure at gate).
- ScreenReaderBellCueTest 2T (rawBytes `["\x07","\x07"]` — pins both cue-leg and promotion-leg).
- TurnEndNotificationTest +2T/+6A (off→bell upgrade both rings; user-set mode alone).
- Suite floor +7T (→ +11T if pending methods land) re-pin + durations rows for the two new files.
- Lead §7 riders unchanged: SessionPicker row-painting, COMPARISON cells, tui.sr.* supersession note.

## Substrate note

C-TUI-6.i (`1e772aa23,ad91178bd,59577fafb,0e8de26e0`, welded) verified as substrate only:
`menuRow`/`glyph`/`border`/`cue`/`enabled` exist with the shapes the 6.j calls consume;
ScreenReaderModeTest 11T/54A intact per r1 — not re-litigated.

## APPENDIX A — tip moved mid-review: `12680b111` closes MAJOR-1; FINAL VERDICT

While this review was in flight the lane struck a FIFTH pick, `12680b111` ("MAJOR-1 — four
deletion-discriminating SR pins"), committing the exact +148-line uncommitted hunk this review
had flagged (one file, `candy-code/tests/Renderer/ScreenReaderRenderTest.php`, 148 insertions /
0 deletions; porcelain now ZERO; `SessionPicker.php` absent). Judged at the new blob:

- **Leg (i)** `testPermissionOptionRowsCarryTheirPositionsOnlyWithTheModeOn`: `askingChat()` frame
  on-SR must yield positionedRows `['1/4','2/4','3/4','4/4']`, off `[]`. Deleting the permission
  `menuRow()` wrapper leaves bare labels → SR list empties → RED. Both polarities pinned. **TEETH.**
- **Leg (ii)** `testTheSplitDiffCentreGutterShedsItsDividerWithTheModeOn`: `splitDiffChat()`
  (Edit ToolResult with `-$oldline` diff, 140 cols); SR asserts `= 1;│` ABSENT and shed-flush
  `= 1; 2│` present; off asserts fused `= 1;│2│`. Deleting the `:5833` `glyph()` wrap restores the
  divider → both SR legs RED. Side row-number columns' `│` honestly disclosed as unwired design
  scope, not silently claimed. **TEETH.**
- **Leg (iii)** `testTheDiffReviewRuleUnderScreenReaderIsBlankPaper`: FIRST committed drive of
  `TuiRenderer::render()` under SR (`reviewApp()` = `OpenDiffReviewMsg` through `App::update`,
  fixture self-guards the slot armed); rule beneath 'Files changed' trims `''` on-SR vs
  `/^\x{2500}+$/u` off. Deleting the `:1393` `glyph()` restores the dash run → RED. **TEETH.**
- **Leg (iv)** `testTheDropdownOverflowLineShedsItsWallsOnlyWithTheModeOn`:
  `MenuBar::openMenu(1)` + `plainApp()` 100×12; off fixture-gates that `│ …│` walls EXIST and no
  wall-less ellipsis row exists (vacuity door); SR asserts exactly one wall-less ellipsis row and
  zero walls. Deleting the `:2930` `glyph()` restores walls → both SR legs RED. Menu state closed
  in-test and in tearDown. **TEETH.**

Hygiene re-check of the new blob: 0 `markTestSkipped`, 0 `if (false`/`&& false`/`|| true`, 0
`Refused:` literals; assertion count 35, existing 5 methods byte-untouched (diff is pure insert);
all wildcards sit inside regex needles carrying excluded chars (`(`, `$`, `\`, space) or the
pre-existing `'.'*`-matcher shape → GlobDialect corpus unaffected; new helpers
(`positionedRows`/`lineContaining`/`ruleLineBeneath`/`splitDiffChat`/`plainApp`/`reviewApp`) are
file-unique; the `writeConfig`/`removeTree` cross-file pairs are the same byte-identical shapes
r1's bare-class `DuplicatedTestHelperDriftTest` run (14T/204A GREEN) already tolerated — plus the
cannot-move argument (no test-helper drift source changed in any pick). Builder's cp+md5 red-before
proofs stay **UNVERIFIED-BY-EXEC** here (vendor copy allowlist-denied) — my independent deletion
reasoning above corroborates each claimed flip.

Re-severity: MAJOR-1 **CLOSED** (all four legs pinned at committed blobs). Prior MINOR-2 (dirty
lane tree) **RESOLVED** by the same pick — withdrawn. Remaining findings:

- **MINOR-1 (step-local, unchanged):** the Agent-View-header splice of `703a45998`
  (`($agentHeader === '' ? '' : $agentHeader . "\n")`) is still wired-but-unpinned — no committed
  fixture opens an overlay over an agent view; deleting that leg stays GREEN. r1's own vacuous-
  coverage class; fixable with one assertion on an agent-header+overlay fixture (or fold into a
  closeout pin). Not weld-blocking: the ruling-(c) headline (status bar survives) IS pinned.
- **NIT-1:** §7 figures shift with the fifth pick: ScreenReaderRenderTest 5T/28A → **9T** (re-measure
  A-count at gate, ~+18A claimed), lane floor +7T → **+11T**; durations rows still owed for both
  new test files.
- **NIT-2:** design "~15+~5" raw-glyph scope vs wired 2+2 — lead dispatch ratified "4 raw-glyph
  sites"; SessionPicker leak stays a lead §7 rider. Record in §6 ledger beside the tui.sr.*
  supersession note.
- **NIT-3 (registry):** the weld brief listed tip `703a45998`/FOUR picks; the branch now carries
  FIVE (`git log master..HEAD` = `c68ac29ae b8b92b490 afad9c717 703a45998 12680b111`). Per
  campaign law the landed-BLOB heading is weld authority — the welder must re-derive the pick
  list from the registry at weld time, and the §7 gate list must include the new tip's bare-class
  guard runs (this round's gates are UNVERIFIED-BY-EXEC).

Q6 FINAL: **weld-eligible modulo disclosure** — commit set `c68ac29ae..12680b111` is blob-clean,
MAJOR-1 closed with teeth (statically; builder execution claims unverified here), residual reds =
exactly the accepted-foreign roster (nothing moved, no new family). Recommended weld authority:
five-pick heading above; gate the new tip once (ScreenReaderRenderTest + both §1.8 guards bare)
before pick.

