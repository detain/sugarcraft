# P-IMG.b — content-sized, centred /generate popup

Commit `2daed61e8` on `cc/P-IMG.b` (base `e4689c28e`), author/committer
Joe Huss <detain@interserver.net>, zero Co-Authored trailers. Never pushed.

## What landed
- `MediaForm::naturalSize(Theme, maxCols, maxRows)` — the box measure: title
  line, FULL tab strip via new `FormTabs::naturalWidth()` (mirrors
  SettingsTabStrip cell/separator maths, no scroll marks), widest
  label+value row per visible section at bodyLines' labelW cap (14),
  footer hint chain, whole status line; clamped
  `[MIN_COLS 60, min(maxCols, MAX_COLS 104)]`, full band only when content
  demands more; rows = 6 chrome + tallest visible section, floor 7, ceiling
  the band. `view()` untouched → goldens stay byte-valid (green unchanged).
- `Tui\Renderer`: renderView's band layout moved VERBATIM into
  `composeBand()` (publish/chrome stay in renderView); `renderMediaForm`
  now composes the real band, pads it to exactly cols×paneRows
  (`padBand()`), and composites the box over it with
  `Veil::new()->withBackdrop(50)` at `OverlayPosition::CENTER` (palette
  law, src/Renderer.php:2060 precedent; `use ... Position as OverlayPosition`
  — Style\Position already owned the short name). Band `images` DROPPED
  (no picture paints through the popup; stray U+E002 cells blank per the
  resolve-with-no-placements law); chat zones stay cleared; `media:` zones
  offset by the exact `intdiv` remainders Veil's floor() lands them at
  (verified against the painted corner, not re-derived from the renderer).

## Gates (targeted only; no full suite)
- `tests/Tui tests/App` whole dirs: **OK 1667 tests / 71,647 assertions** (incl.
  `MediaForm|Renderer` filter run 308 OK; goldens byte-stayed, no UPDATE_GOLDENS needed).
- Guard battery `tests/Config + Support drift/census files`: 697 tests, exactly 4 reds —
  `ReadmeSuiteFigureDrift` staleness **pair** (pinned 25,537 vs live 25,748 = base live
  ~25,740 + my +8T; figure files are COMMIT-FORBIDDEN here, re-pin is the gate's) and
  2 `EnvRosterDrift` `CANDY_CODE_REF`/docs/DOCKER.md reds **proven pre-existing at base**
  (git stash re-run reds identically; zero docs touched by this lane).
- E646: no timers anywhere in the change.

## Deviations / notes
- Test chrome arithmetic initially assumed 3 chrome rows; probe showed the unhosted
  shell's InputPane block + status bar = 4 → assertion corrected to the measured
  layout (comment records it's probe-measured, fail-loud on InputPane shape drift).
- naturalSize's labelW uses bodyLines' 14 cap (upper bound; avoids the width→labelW
  circularity), disclosed here rather than silently approximated.

## NEW test files (durations.tsv, gate's duty)
- `candy-code/tests/Tui/MediaForm/MediaFormPopupSizeTest.php` (7 tests)
- modified: `candy-code/tests/App/MediaFormWiringTest.php` (+1 test) — suite delta +8T.

## round 2 fixes (commit b9dd35a93, branch cc/P-IMG.b)

- Finding 1 (MINOR, docblock/zone straddle): took option (a) — padBand() now sweeps
  zone tags from the band string via new private const BAND_ZONE_TAG_PATTERN
  (byte-identical to the chat renderer's private ZONE_MARKER_PATTERN; duplicated
  rather than promoting either renderer's private surface) before clipping/padding,
  and the renderMediaForm docblock now states the honest premise: chat rows arrive
  sentinel-free via scanRoot, the split column and any future band producer may not,
  and padBand refuses the straddle outright.
  Disclosure: an empirical probe (live split + armed clicks + open form) showed the
  band middle sentinel-free at today's code — composeAgentSplit's column panes emit
  no Mark::zone; the reviewer's causal trace names AgentStrip/AgentViewHeader paths
  that ride the scanRoot-stripped chat chain. The property was true but unpinned;
  the strip makes it load-bearing for any band producer, zones-off rendering of the
  backdrop unchanged.
- Pin: MediaFormPopupSizeTest::testPadBandSweepsZoneTagsOutOfTheBackdropBeforeTheCellCut
  (reflection-invokes padBand with a live Mark::zone pair; asserts no U+E000/E001
  halves, no id bytes, content survives, lines exactly $cols). Mutation-proven
  fails-before-fix: sweep line disabled -> exactly 1 red at the sentinel assert
  (:238); restore md5-verified.
- Finding 2 (NIT): stray blank line before the SavePreset section comment in
  MediaFormWiringTest.php collapsed.
- Gates (cwd candy-code, plain pipe, -u LINES -u COLUMNS): tests/Tui tests/App
  whole dirs OK 1668 tests / 71,661 assertions (= builder's 1667/71,647 +1 pin
  /+14A); five-guard (GlobDialect|DocFigureProseDrift|ReadmeSuiteFigureDrift|
  ChildWallClockBudget|DuplicatedTestHelperDrift) 133 tests, exactly the 2
  ReadmeSuiteFigureDrift staleness reds (25,537 pinned vs 25,749 live — gate's
  re-pin duty). GlobDialect corpus unaffected (pin literals not glob-shaped).
- Temp ZoneProbeTmpTest.php deleted pre-commit; file-set stayed within the
  declared three files.
