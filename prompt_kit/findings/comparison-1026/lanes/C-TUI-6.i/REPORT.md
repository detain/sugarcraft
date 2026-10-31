# C-TUI-6.i — Screen-reader core + `screenReader` setting — LANDED

- Branch `cc/C-TUI-6.i`, base master `288b81f11`, picks=1: `9d34122df97fd80845e36b24b5b4a718423a4f86`
  (`git cat-file -t` = commit, same shell; %ae=detain, %ce=detain separate greps; zero Co-Authored; tree clean).
- Owner-stop/twin history: sole worker per owner stop-order; pre-stop work verified coherent single-lineage
  (HEAD/picks/dirty re-measured from disk before continuing; the "lanes/C-TUI-6.i evidence files" scare was a
  misattribution of the tracked `C-TUI-6.k` dir in my own worktree checkout — no collision, nothing reverted).

## What landed (12 files, +487/−22)
- NEW `candy-code/src/Tui/ScreenReaderMode.php` — process-global, reads `UiSettings::bool('screenReader')`
  (memo keyed by config fingerprint; `Chat::applySettings` already `forget()`s on save ⇒ Live with no Chat edit).
  Helpers for 6.j, off-mode = byte-identical passthrough (6.j capture pins survive):
  `border(Border):Border` (hidden-glyph set + `titles:` carried verbatim), `glyph(string):string`
  (strtr over lazily built U+2500–U+257F map — zero regex, GlobDialect-safe),
  `menuRow(int,int,string,bool):string` (`> `/`  ` marker + locale-neutral `N/M`),
  `cue():?\Closure` = `Cmd::raw("\x07")` arm (a Cmd, never written from view()).
- `InterfaceSettings.php` screenReader definition (Bool false, Interface, Cosmetic, withLayered, Live,
  readerSymbol `ScreenReaderMode::enabled`, NO withProjectSettable ⇒ user-tier only).
- `LayeredSettings::LAYERED` +`'screenReader'` after `'vimMode'`; `UiSettings::KEYS` likewise.
- `AgentLiveRegistry`: `staticSpinner()` = `self::$staticSpinner || ScreenReaderMode::enabled()`;
  `frameAt()` now gates on the getter (ctor+advance paths both covered); docblocks truth-updated. No Bootstrap touch.
- `lang/en.php`: `settings.screenReader.label` + `.help` (Lang-only; NO tui.sr.* keys minted — that prefix is
  6.j's block; menuRow uses digits+slash, disclosed).
- `docs/ENVIRONMENT.md` reduced-motion sentence now names the `screenReader` setting; generator's table column
  untouched (no env var added ⇒ EnvRosterDrift green, Help.php untouched — correct, no new env key).
- `docs/SETTINGS.md` + `README.md` via `php tools/gen-settings-doc.php --write` (never hand-edited):
  145 layered / 86 user-tier-only / 44 live word-numbers, roster + session-keys + env-split sentences all regenerated.
- NEW `tests/Tui/ScreenReaderModeTest.php` (9T/41A: off-default passthroughs, definition tier pins, project-file
  containment via `projectLayer()`, save-applies-live, invalid-value false, on-mode border/glyph/menuRow/cue pins).
  §7 durations row owed. MODIFIED `tests/Agents/Live/AgentLiveRegistryTest.php`: class now runs under
  HomeSandboxTrait (design risk note) + NEW `testTheScreenReaderSettingPinsTheFrameWithTheEnvSwitchUnset`
  (17T/72A green). `tests/Chat/ApplySettingsTest.php`: session-tier array re-derived (+`'screenReader'`, see fold).

## LEAD-ruling fold (ApplySettingsTest) — conclusion + proof
Ruled branch-2 (re-derive the stale pinned side), NOT branch-1. Measured live: derived
`SettingsWriter::sessionKeys()` = 82 == pinned 82, order-equal, after inserting my one row after `vimMode`;
base pinned array (81 rows) is a strict subset of derived — sole delta `[screenReader]`. The "2 foreign
writers welded off-roster" theory is disproven by diff (base-not-derived = []): no writer classes added to any
roster in my commit; the pinned array's staleness was exactly and only my key. (Pre-fold STOP-report numbers
differed because they predated the :346 insertion landing.)

## Gates (cwd=candy-code, -c phpunit.xml, env -u LINES -u COLUMNS, plain pipe, anchored exact-class filters; PtyRun exclusion moot — never in filters)
- Batch 1 (ScreenReaderMode|AgentLiveRegistry|SettingsSchema|UiSettings|SettingsSchemaDocDrift|ReadmeRosterDrift|
  ReadmeSettingsTierClaim|EnvRosterDrift|CliLangCatalogue|ChatHostLangCatalogue|TuiLang|LangParity|ApplySettings):
  **206T/49,170A, 1F = foreign `ApplySettingsTest::testEveryLiveKeyTheViewCanSaveHasAnApplyArm` keepWarm arm (not cured).**
- Batch 2 (DocFigureProseDrift|DuplicatedTestHelperDrift): 106T/4,392A, 2F — both DuplicatedTestHelperDrift
  FOREIGN-file drift pairs (`titleBackend` AutoCommitTurn/AwayRecap/Btw trio; ResumeCostEstimate-vs-AwayTracker const pair),
  zero mention of my lineage; DocFigure (settings count words) green. Not cured (baseline reds; list to lead).
- `tools/check-one-type-per-file.php`: 62 libs / 3316 files / **0 problems**.
- 2 "PHPUnit Deprecations" appear on every run incl. an untouched class ⇒ environmental, not my lineage.
- php -l all 10 changed+new PHP files: clean. No full suite run; suite-figure/README staleness pair NOT touched (§7:
  +1 new test file, +10 methods net for ScreenReaderModeTest 9T + AgentLiveRegistryTest +1T — figures re-pin is gate duty).

## Refused/observed widenings
- No `tui.sr.*` lang keys (6.j's block owns that prefix); no renderer/Chat/TerminalNotifier wiring (6.j);
  no Help.php/ENV var; keepWarm 1F not cured; DuplicatedTestHelperDrift foreign pairs not cured;
  no composer/npm/refresh-deps; no git ever run against /home/sites/sugarcraft; worktree clean.
