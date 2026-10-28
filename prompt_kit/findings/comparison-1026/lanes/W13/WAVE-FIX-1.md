# W13 WAVE-FIX-1 — guard roster cures

Verdict-first: both W13-owned MAJORs cured green at master 6875a1d90 base;
DocFigureProseDriftTest hunk lands BYTE-IDENTICAL to the C-TOOL-3.d pending
cure (cc/C-TOOL-3.d @ 1de8e9266) so the lane welds cleanly.

## Findings addressed (from WAVE-REVIEW-1.md, verbatim scope)

- MAJOR — candy-code/tests/Config/DocFigureProseDriftTest.php:5424 —
  'thirty-seven' outside pinned word map.
- MAJOR — candy-code/tests/TreeWideGuardRosterTest.php — unlicensed walkers
  LSP/LspInstallerTest.php `scandir($dir)` AND Cli/DoctorSandboxProbeTest.php
  `RecursiveDirectoryIterator($this->tmpDir,\FilesystemIterator::SKIP_DOTS)`.
  (The second is cross-wave per the review — taken here under the wave rule
  that the W13 loop may fix what it needs to go green.)

## Red-before (captured at pre-fix master)

Run 1 `--filter 'DocFigureProseDrift'` → 1F:
- testAgentsAuthoringToolClaimsSurviveTheBuiltInDirectory —
  "the spelled count 'thirty-seven' is outside the pinned word map" (:5425)

Run 2 `--filter 'TreeWideGuardRoster'` → 2F:
- testEveryWalkerCallSiteInAFileThatNamesThePackageRootIsAccountedFor (:1115)
- testEveryFileWithAnUnresolvedWalkIsARosterMemberOrFullyLicensed (:1972)

## Cures + proof

1. Word map: added `$wordNumbers['thirty-seven'] = 37;` with the lane's
   4-line comment, verbatim after `$wordNumbers['thirty-six'] = 36;`.
   Live re-derivation matches the guard's own source of truth
   (`scandir(src/Tools/BuiltIn)` filtered on `.php`): 37 files;
   AGENTS_AUTHORING.md:463 + ARCHITECTURE.md:832 both spell thirty-seven
   (flipped by C-AGT-5.a 5cd68af7e, which missed this arm's in-step flip).
   Byte-identity PROVEN: `cmp` of the 13-line region master-vs-1de8e9266 →
   HUNK-BYTE-IDENTICAL.
   Post-fix: 91T OK, 3999A (+16 = the two new agreement legs now scored), 0F.

2. Roster rows in WALKS_A_DIRECTORY_THE_TEST_MADE (alpha placement; the
   guard's fail messages demand file+exact flattened expression):
   - `'Cli/DoctorSandboxProbeTest.php' => ['RecursiveDirectoryIterator(
     $this->tmpDir,\FilesystemIterator::SKIP_DOTS)']` — provenance
     C-TOOL-4.a 1945e347f (W14, welded b264527ff; rostered here per wave
     rule, row comment carries the id); justification: tearDown empties the
     sys_get_temp_dir() tree setUp() made under $this->tmpDir.
   - `'LSP/LspInstallerTest.php' => ['scandir($dir)']` — provenance
     C-TOOL-5.c 14d37bbf5 (the reviewer's own wave licensed its
     LspLauncherAutoDetectTest but not this file); justification: the
     installer probes/wipes candidate install dirs inside its sandbox root.
   Prose arms: NONE enumerate the roster size — the self-check at :2037
   states the count is `count(self::WALKS_A_DIRECTORY_THE_TEST_MADE)`
   (dynamic); no ordering arm exists for this const; the removal-half test
   passes proving both rows match live sites (LspInstallerTest :484/:510
   scandir, DoctorSandboxProbeTest :65 RDI). Post-fix: 17T OK, 3495A (+4), 0F.

## Welder#2 'forty-six' history check

`git log -S 'forty-six' -- tests/Config/DocFigureProseDriftTest.php` →
single hit e6567b98c (the rename). 'forty-six' => 46 lives ONLY in the
SETTINGS LAYERED_KEYS arm's full-spelling map (:6376-era block), a
DIFFERENT arm policing LayeredSettings, never the BuiltIn-tools count.
The failing arm's messages name thirty-seven only; no forty-six ever gated
this directory count. Master carried thirty-six→36 as the map head
(red-at-base confirmed by run 1). Other map entries (twenty…, thirty,
thirty-one…thirty-six) untouched — their arms were green.

## Gates (post-fix, plain pipe, env -u LINES -u COLUMNS, cwd=candy-code)

- DocFigureProseDrift: OK 91T/3999A (only pre-existing PHPUnit deprecations)
- TreeWideGuardRoster: OK 17T/3495A
- DuplicatedTestHelperDrift|GlobDialectDifferential|ChildWallClockBudget|
  OneSidedHomeSandbox: OK 43T/12240A — corpus-figure arm
  'The corpus figure in the doc block is the derived one' GREEN. NOTE on the
  brief's "131,765": that is the pre-rename sugar-crush corpus figure and
  survives only in untracked docs/plans/crush_code_filemap.md; the candy-code
  live figure (src/Util/PathGlob.php:51) is 304,700 = 554 patterns x 550
  paths, and the green arm proves THAT equals the live derivation — the
  substance of the law (no drift) holds. No glob-shaped literals added: both
  comments are wildcard-free; row expressions are single-quoted strings
  already present verbatim in the const.
- ReadPathCensus|StderrEmitterCensus: OK 114T/21737A (unchanged, green)

## §7 must-run reminder (closeout duty — map/permission-denied to fixer)

php tools/gen-docs.php --check · php tools/gen-ansi-readme.php --check ·
php tools/check-path-repos.php --no-lib-path-repos ·
php tools/check-child-lifetimes.php

## Not taken (out of brief scope, disclosed)

ContainedPathInventoryTest 2F (cross-wave, all pre-W13 per review item 3)
stays with its owning loops. run-state.json working-tree dirt NOT staged.
plan_comparison.md §4 marks NOT touched (closeout's).
