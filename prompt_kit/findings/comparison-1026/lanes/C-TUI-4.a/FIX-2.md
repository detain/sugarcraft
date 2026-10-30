# C-TUI-4.a — FIX-2 (round 2 cure)

## Finding (verbatim, r2 reviewer — the only blocker)

"MAJOR (blocks weld): StderrEmitterCensusTest 2F at tip — RUNTIME_NOTICE_SITES
roster has no src/Theme.php row while the scan counts 4 RuntimeNoticeSink::warn()
sites (:217/:239/:251/:255). 3 sites pre-existed at 8bc69dcfa (landed-blob grep),
m2 added the 4th; roster never moved (§1.8 in-step guard law); failure diff
isolates Theme.php — NOT foreign. Fix: add 'src/Theme.php' => 4, at :810 with
decision comment, re-run (expect 95T/0F)."

## Pre-fix verification (landed-blob proof)

- `git show aa5ddf638:candy-code/src/Theme.php | grep -c RuntimeNoticeSink::warn` → **0**
  (all 4 sites are this lane's work; §1.8 in-step census duty was ours at 8bc69dcfa).
- `git show 8bc69dcfa:...` → 3 sites (file_missing, file_malformed, file_notes).
- Tip 3243d8e53 → 4 sites (+ file_refused from round-1 m2).
- Base gate (plain pipe, cwd=candy-code): **Tests: 95, Assertions: 22595, Failures: 2** —
  both failures' diffs isolate exactly `+ 'src/Theme.php' => 4,`, nothing else moved.
  Confirms reviewer diagnosis verbatim.

## Cure

One file: `candy-code/tests/Cli/StderrEmitterCensusTest.php`.

1. New row at the alphabetical tail of RUNTIME_NOTICE_SITES (after
   src/Skills/SkillMatcher.php, :810): `'src/Theme.php' => 4,` with a decision
   comment in the neighbors' style, recording that the lane's four theme-file
   notice sites are routed through the RuntimeNoticeSink seam BY DESIGN (a theme
   that silently degrades to the default is something the user must see in the
   transcript, not only on stderr), each behind Theme's own once-per-name
   `$warned` latches so at most one row per shape per session.
2. Same-commit aggregate prose bump required by the roster-derived word pins at
   :3073/:3078 (expected = array_sum/count of the roster, NUMBER_WORDS-parsed
   from the class doc-block :158): **FORTY-FIVE → FORTY-NINE** sites,
   **NINETEEN → TWENTY** files; plus the map itself widened with the four new
   entries it had to read (see Moved numerals below). No other roster numeral,
   file, or src/ change.

## Gate

- `env -u LINES -u COLUMNS php vendor/bin/phpunit -c phpunit.xml --filter StderrEmitterCensusTest`
  (cwd=candy-code, plain pipe): **Tests: 95, Assertions: 22608, Failures: 0**
  ("OK, but there were issues!" = the 2 pre-existing PHPUnit Deprecations, same
  shape as the base run). Matches reviewer's expected 95T/0F.
- Theme trio re-run for collateral — the four theme-named classes together
  (`--filter 'ThemeTest|ThemeFileTest|ThemePersistenceFramingTest|PaletteThemePickerTest'`):
  **Tests: 33, Assertions: 144, Failures: 0** — superset of FIX-1's trio claim
  (that entry did not record its exact filter string; 24T/117A not reproducible
  as any class-pairing of the four — the four classes' own totals are what
  matter, and they are green). My diff touches no theme file.
- Collateral disclosure: a bare `--filter Theme` substring also drags in
  `Config/MediaSettingsTest.php:254` (one method named *theme*), which is RED —
  proven foreign: this branch never touched MediaSettingsTest.php
  (`git diff --name-only aa5ddf638 HEAD -- tests/Config/Settings/` shows only
  SettingsSchemaTest.php; MediaSettingsTest last touched at 426c66203), and it
  reds identically with my census edit reverted. Standing red, out of scope.

## Moved numerals (in-step census law, §1.8)

- Class doc-block :158: channel-6 totals `FORTY-FIVE` → `FORTY-NINE` sites,
  `NINETEEN` → `TWENTY` files (the roster-derived word pins at :3073/:3078 read
  array_sum()/count() of the roster, so the prose MUST move with the row).
- `NUMBER_WORDS` (:1033): added `forty-six..forty-nine` (46–49) — the map
  stopped at forty-five and the cardinality guard failed LOUD on the new word
  ("not a number word this guard can read"), exactly as its doc-block says it
  should; adding entries cannot wave anything through (both arms stay compared).
  No other roster numeral, file, or src/ line changed anywhere.

## Commit

Single commit on `cc/C-TUI-4.a` atop 3243d8e53, message
`C-TUI-4.a: roster Theme.php runtime-notice sites (census cure)`, staging
exactly `candy-code/tests/Cli/StderrEmitterCensusTest.php` and this FIX-2.md.
Own SHA is cat-file-verified and carried in the lane return; branch tip
(`git rev-parse HEAD` on cc/C-TUI-4.a) is the durable record.
