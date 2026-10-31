# C-TUI-6.j — screen-reader renderer/bell wiring (W34)

## Verdict: DONE on branch cc/C-TUI-6.j, 2 picks off base 9a0c569eb

- `c68ac29ae` — src wiring: the 7 `Border::` sites in `candy-code/src/Renderer.php` now route
  through `ScreenReaderMode::border()`; the raw box-drawing code sites route through
  `ScreenReaderMode::glyph()`; `renderPalette` and `renderPermissionPrompt` paint
  `ScreenReaderMode::menuRow()`; `TerminalNotifier::fromConfig()` upgrades `notify:off` to
  `bell` while the mode is on (user-set modes untouched); `Chat::requestPermission()` batches
  `ScreenReaderMode::cue()` beside the wait (off-shape byte-identical: `Cmd::batch` drops nulls,
  confirmed sugar-core/src/Cmd.php:52).
- `b8b92b490` — tests: CREATE `candy-code/tests/Renderer/ScreenReaderRenderTest.php` (5T),
  CREATE `candy-code/tests/Chat/ScreenReaderBellCueTest.php` (2T), MODIFY
  `candy-code/tests/Chat/TurnEndNotificationTest.php` (+2 methods).

Author law on both: `%ae`/`%ce` detain-grep counts 1/1, Co-Authored-By count 0 (checked per commit
in the citing shell; `git cat-file -t` = commit on both).

## Design compliance, item by item

1. BORDERS — all 7 `Border::` sites wired (`->border(ScreenReaderMode::border(...))`): 4×
   `Border::normal()` (diff box, two menu boxes, input box) + 3× `Border::rounded()->withTitle(...)`
   (palette, keyHelp, permission). Off mode: `border()` returns the SAME instance → byte-exact.
2. RAW GLYPH SITES — PCRE-censused (docblock/comment lines excluded): exactly 4 code sites tree-
   wide, not the design's "~15 in Renderer.php / ~5 in Tui/Renderer.php". Those counts are
   audit-era stale — the renderer shed manual chrome over 30 waves. Sites wired: Renderer.php
   compaction boundary rule (`── … str_repeat('─')`, now inside `glyph()` before `Width::truncate`),
   Renderer.php diff-gutter `render('│')`, Tui/Renderer.php review rule `str_repeat('─',$cols)`,
   Tui/Renderer.php ellipsis row `'│ …' … ' │'`. Every raw site is now behind the door; intent
   satisfied. (Tui/Renderer.php needed no import — same namespace `SugarCraft\Code\Tui`.)
3. OVERLAYS — the overlay composite block in `src/Renderer.php` (after `clipRowsToCols`) replaced
   the body with `ScreenReaderMode::enabled() { $frame = $overlay }`; off path keeps the
   lift/backdrop/Veil/restore verbatim; `markPaletteItems`/`markSessionRows` unchanged after the
   branch (no-op without registries on a replaced frame). Toast branch condition untouched.
4. MENUS — palette rows: SR branch paints `menuRow($index+1, count($results), ($active?'● ':'').$body,
   $index===$selected)`; the off branch is the original marker concat, byte-identical. Permission
   options: each option row wrapped in `menuRow(...,false)` with a self-adjusting wrap-reserve probe
   (`strlen(menuRow(i,n,'',false))`, 0 off → off-mode byte-exact); the 'v' aid row stays unwrapped
   (it is an aid, not an option). No new keys → KeyBindingDrift unmoved.
5. BELL CUES — `fromConfig`: `$mode` OFF **and** mode on → bell; everything else untouched
   (covers turn end + goal end without a Chat.php edit, per design). `requestPermission` batches
   the cue; `pumpLiveToolEvents` asks THROUGH `requestPermission` (:4420) so the cue propagates —
   single choke, no second site needed.

## Deviations (adjudicate)

- **tui.sr.* lang keys: zero shipped.** `menuRow`'s ' n/of' position is 6.i's welded, pinned,
  locale-neutral contract (its docblock anticipates optional richer 6.j phrasing); nothing in this
  wiring renders a string richer than the existing localized keys, and shipping unused keys would be
  dead surface (no-fabrication law). `candy-code/lang/en.php` untouched. The design's
  `files.modify: lang/en.php (tui.sr.*)` clause is reported NOT NEEDED on that reasoning.
- **renderSessionPicker menuRow half NOT wired (SEAM).** Rows, box border and `─` separators are
  painted in `src/Tui/SessionPicker.php` (Border::rounded :1248, separator :1289, marker '▶'
  :1336) — NOT in the design's files.modify. Same applies to the dirPicker/historySearch row
  interiors. Everything reachable inside declared files IS wired (the picker frame/box goes through
  the chain and its title/overlay path is covered). Wiring the ROWS needs `src/Tui/SessionPicker.php`
  added to scope — lead adjudicates as a follow-up.
- Overlay replacement drops the status-bar line with the body (design said "REPLACES the body";
  frame-level replacement is the literal reading). Flag if the lead wants the chrome kept.

## Guards

- `TreeWideGuardRosterTest` — GREEN unmodified: none of the three test files names the package
  root, so their `removeTree()` scandir walkers are never consulted (exact precedent documented in
  the roster comment for ScreenReaderModeTest, :585-586). No licence rows needed.
- `DuplicatedTestHelperDriftTest` GREEN (chat()/rawBytes()/removeTree() copies byte-identical,
  srConfig()/writeConfig() distinct). GlobDialect: my files contribute ZERO to the corpus —
  proven by hiding both new files and re-running: identical 560×556 derived figure; the standing
  PathGlob docblock 1F is the accepted foreign red (neither number asserted).
- `StderrEmitterCensus` / `ReadPathCensus` / `SettingsWriterCensus` / `KeyBindingDrift` /
  `RouteTraitParity` / `DocFigureProse` / `EnvRoster` / `ReadmeRoster` / `ConfigWriteProducer` /
  `ChildWallClockBudget`: combined targeted run 184T green + census batch 380T with ONLY the
  accepted 1F. SETTINGS.md untouched (no help text changed; 6.i's screenReader.help already
  discloses the audible cue). Off-mode goldens: combined KeyHelp-family run shows exactly the
  accepted 5 foreign KeyHelpTest dataset reds (verified shape: live-binding overflow counts, not
  SGR/glyph drift) + 1 load-dependent 60s-timeLimit abort in a KeyHelp dataset (89 assertions, 0
  failures in it — co-shard slowness under the running welder fleet, disclosed not cured).

## Mutation discrimination (all cp-backup restores, md5-verified)

- M1 neuter `fromConfig` SR branch → exactly 2 reds: TurnEnd `testScreenReaderModeUpgradesOffToBell`
  + BellCue `testAPermissionAskBatchesTheCueBesideTheWait`.
- M2 drop the cue leg from the batch → exactly 1 red (the cue-carrier test).
- M3+M4 neuter palette SR branch + overlay SR branch → exactly 2 reds (palette-position test,
  overlay-replaces test).
- M5 strip `border()` at the 4 normal sites → exactly 1 red (no-glyph frame test — the input box
  border is in every fixture frame).
- The glyph()/permission legs feed the same no-glyph walk + off/negative legs; reasoned load-bearing
  (each deleted site re-appears as a rune the codepoint walk catches).

## TWIN-HAZARD INCIDENT (lead attention)

During mutation-proofing this lane's worktree /home/sites/cc-cmp-C-TUI-6.j was repeatedly re-poisoned
with mutation-shaped edits (`if (false)`, `&& false`) landing in src/Tui/TerminalNotifier.php and
src/Renderer.php SECONDS after md5-verified restores — by an external process sharing the worktree
(a prior/zombie incarnation of this same step running its own mutation sequence; other lanes'
phpunit was sighted only in its own worktrees). Every gate run here was executed in a verified-clean
window and both picks were committed before re-contamination could enter them (the tests-commit
caught Renderer dirty mid-commit; restored from my own commit, tree now clean, porcelain empty).
If a C-TUI-6.j twin is still registered live, terminate it before weld — a third commit-scope
collision could otherwise land its mutation residue.

## §7 additions

- NEW `*Test.php` with live counts: `tests/Renderer/ScreenReaderRenderTest.php` 5T (28A solo),
  `tests/Chat/ScreenReaderBellCueTest.php` 2T. Durations rows NOT minted (lane law).
- `tests/Chat/TurnEndNotificationTest.php` grew 8T→10T (2 methods, in-file).
- Final combined lane gate: 31T/117A green (TurnEnd+BellCue+Render+ScreenReaderMode+
  AbandonedPaletteIsNotComposited), "OK, but there were issues!" = 2 pre-existing-family PHPUnit
  deprecations.
