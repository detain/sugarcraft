# Phase 3 Batch A entry 2: candy-mines → candy-minesweeper <!-- @rename-keep -->

Procedure R run, 2026-10-15, following the A1 pilot shape (read its NOTES first).

## Steps
- R1 dry-run: 95 content files, 191 paths, R1=140 R2=24 R3=1143 R5=3 R6=1.
  Historical campaign-record mentions tagged `@rename-keep` in a pre-run commit
  (0153a9fdc: rename-baseline.md table+failure rows, rename-1.1/1.3/2.5/remediation
  brief notes, rename-3-A1/NOTES.md CI-red line — A1 bf23476b1 precedent) →
  dry-run re-check 89 files / R3=1136 (−7 exactly, nothing else moved).
  Foreign untracked `oc.7z` moved aside for the tool's clean-tree preflight,
  restored after R7 (brief instruction).
- R2 remote first: `scripts/rename-remote.sh candy-mines candy-minesweeper` —
  verified record shows description/homepage carried over, old URL redirects.
- R3 tool run: exit 0, 89 files / 192 paths (same +1 bin-path shape as A1).
  `php -l` sweep over every touched PHP + the bin: all clean.
- R4 follow-ups:
  1. README formerly + namespace notices (both `@rename-keep`), house shape.
  2. composer keywords: the manifest ALREADY shipped a duplicate `"sugarcraft"`
     entry (pre-existing, surfaced by the eye-check) — deduped and added the
     upstream Go name `go-sweep` (description already carries
     `port of maxpaulus43/go-sweep`). homepage/support verified rewritten.
  3. No bare-short-name bin prose (bin is documented as `./bin/candy-mines…`).
  4. User data paths: NONE — stats live only where `CANDY_MINES_STATS` points; <!-- @rename-keep -->
     grep found zero XDG/dotfile paths in src/bin → no LegacyPaths migration
     (honest verify, same verdict family as A1's env question).
  5. Env shim: `LegacyEnv::import(['CANDY_MINES_' => 'CANDY_MINESWEEPER_'])` <!-- @rename-keep -->
     at top of `bin/candy-minesweeper` after the autoloader/use-block, STDERR
     deprecation notice per imported var. Only ONE old prefix ever existed
     (CANDYMINES_ was never read anywhere) — mapping it too would be dead
     noise; deviation recorded. Behavior pinned live: old-only → notice+import;
     new-set → silent, new wins (LegacyEnv precedence law). README env
     paragraph gained the compatibility sentence (`@rename-keep`).
     The residue checker caught the shim's own docblock line naming the old var
     (line 26) — tagged, re-run 0 findings.
  6. Lang domain: R6 rewrote NAMESPACE 'mines'→'minesweeper'; no test asserts
     the domain literal (LocaleParityTest only uses key suffixes) → nothing to
     update.
  7. PROJECT_NAMES.md rationale line was mechanically rewritten into a false
     statement (`CandyMinesweeper | candy + mines = Minesweeper`) — A1 FINDING-C
     family; rewritten honestly (wordplay retired with the rename).
  8. MATCHUPS row already under `## Apps` — no section move needed.
  9. ansi: keys rewritten by R3 (180 art files, paths renamed, content
     excluded); `gen-ansi-readme.php` regenerated + `--check` agrees (3595).
- R5 wiring: root composer require+repositories rewritten by tool;
  `composer update sugarcraft/candy-minesweeper --no-install` — lock diff
  verified sugarcraft-only (content-hash + one package block);
  `refresh-deps --mode=linked --libs=candy-minesweeper` OK 9/9 (the
  `*** WANTED PUBLISHED ***` status annotation predates this entry — mode
  unchanged, same as pre-run candy-mines); no consumers beyond root
  (`grep '"sugarcraft/candy-mines"' */composer.json` matched only the lib's own <!-- @rename-keep -->
  name line). gen-docs: 61 pages + 4 redirect stubs, `--check` clean — the
  `docs/lib/candy-mines.html` stub appears only after the renames.json status
  flip (stub map derives from executed entries; A1 landed it the same way,
  inside R7).
- R6 gates: candy-minesweeper suite 180T/820A/1F/4W — byte-identical SHAPE to
  the pre-rename baseline (`DifficultyStatsTest::testSaveLeavesNoTempArtifacts`
  + same 4 warnings; the /tmp fixture prefix rides the renamed slug as
  expected). tools/tests 426T/1484A = the post-A1 floor exactly (+0 from this
  entry; the brief's 422/1434 is the pre-pilot figure). residue 0,
  check-path-repos --no-lib-path-repos rc 0, gen-docs/--check + gen-ansi
  --check clean.

## Deviations from A1 experience
- No tool defects hit this entry: underscore-joined doc PATHS moved cleanly in
  R3 (53d9e944 fix works); only ONE underscore-joined content ref existed
  (`sugarcraft_candy-mines.md` in update_sugarcraft_*:563) — fixed manually,
  invisible to tool+residue exactly as A1 FINDING B predicted. <!-- @rename-keep -->
- My first splice script duplicated the anchor `use` line when inserting the
  shim (re-included `m.group(1)` after an end-index slice) — caught by `php -l`
  in the same step, fixed; process lesson: lint immediately after scripted
  splices, never batch-verify at the end.
