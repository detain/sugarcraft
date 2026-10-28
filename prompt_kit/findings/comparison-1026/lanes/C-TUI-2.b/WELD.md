# C-TUI-2.b — WELD record (2026-10-28, welder ses-run)

- Pick: `d3d5e7825` -> `e2bc4292d` (cherry-pick -x onto master, CLEAN zero conflicts; patch-id
  eb008bf446e6c9fcbeb2d484fc4543653aafad14 IDENTICAL lane<->pick; %ae/%ce separate greps 1/1 detain;
  zero Co-Authored trailers; 4 files +428/-0: SideBySideFormat.php + SideBySideRow.php one-type-each +
  tests/Writer/SideBySideFormatTest.php + README).
- LIB-LOCAL (sugar-diff only): NO durations rows (lib tests never in candy-code tsv), NO regen, no candy-code paths.
- r1 CLEAN 0C/0M/2NIT (REVIEW-1.md). Both NITs carried unchanged, no fix required:
  - NIT1: REPORT prose "46/113" vs committed 114A (totals exact; report prose only).
  - NIT2: README API row lists rows()/fromUnified() only; SideBySideRow::isPaired() undocumented —
    consistent with sibling headline-method convention.
- Merged gates (plain pipe, env -u LINES -u COLUMNS):
  - sugar-diff full suite -> **OK (116 tests, 339 assertions)** EXACT vs brief + r1.
  - --filter SideBySideFormat -> **OK (46 tests, 114 assertions)** = r1's disk truth.
  - php tools/check-one-type-per-file.php -> **62 libs, 3218 psr-4 files, 0 problems** rc0.
  - php tools/check-path-repos.php --no-lib-path-repos -> rc0 clean (legitimates the leg the r1 reviewer's
    shell map denied: no composer touch in the diff, guard unaffected).
- Figures carry: sugar-diff local suite 70 -> 116T/339A (r1 story re-verified on merged tree); candy-code
  suite-figure untouched (zero candy-code paths).
- Absorption: git cherry -v master cc/C-TUI-2.b -> '-'; worktree ../cc-cmp-C-TUI-2.b + branch pruned. NEVER pushed.
