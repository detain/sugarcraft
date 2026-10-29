# C-EXT-3.a review 1 (round-1, reviewer ran gates directly)

Branch cc/C-EXT-3.a @ 7b416e03d (base e91eb1d47, 1 commit). Worktree clean except
intentional untracked BRIEF.md (REPORT.md itself IS committed, 132 lines — the
"no report" fear was wrong; the abort happened after it landed).

VERDICT: CLEAN

## Completeness vs BRIEF
- Diff = exactly the brief's file list (13 code/doc files) + REPORT.md. All serialized
  claims honored: [runtime:turnContext], [docs:SKILLS.md], [docs:COMMANDS.md],
  [lang:host.skills.reload.], [hot:SkillsHostCommand]. Zero touches to
  suite-figure.json / durations.tsv / dist / src/Protocol.
- reload arm, reloadSkills(), SkillRegistry::replaceAll(), SkillRosterDiffTracker,
  TurnContextBlock field+wither+render section, Runtime stamp+read, docs sections,
  en.php keys, all 5 test files: present, no stubs/TODOs.

## Gates reproduced by reviewer (cwd=candy-code, worktree, env -u LINES -u COLUMNS, plain pipe)
- php -l: 11/11 changed files clean.
- 5 touched classes: 90T/306A OK (report per-class figures match exactly).
- 20-family guard filter (incl. DocFigure, GlobDialect 13T, SymbolCitationDrift 9T,
  DuplicatedTestHelper/DocBlock, TreeWideGuardRoster, StderrEmitterCensus,
  OneSidedHomeSandbox, SuiteSkipRoster, CommandDocsDrift — whose
  testTheCheckModeAgreesWithTheTest runs `tools/gen-command-docs.php --check` rc0 in-suite):
  629T/63,909A/2F — the 2F are the declared inherited base-reds, both re-proven foreign:
  OneSided roster diff names only C-CTX-5.a/C-CTX-4.c files (not lane files);
  ChatHostLangCatalogue's 6 orphans are chat.* keys, identical red at main-tree
  master 6742a2ad3. ClaimFamilies/ReadmeRoster/RegistryTranslation/KeyBinding extra
  family: 373T/28,888A green. 'PHPUnit Deprecations: 2' = pre-existing baseline (main
  tree shows same pair).
- Author law: A/C Joe Huss <detain@interserver.net>, zero trailers.

## Correctness / cache law
- Prompt-cache law satisfied and pinned: tracker bytes never enter the prefix; the
  stability leg asserts HEADER absent from both turns' systemPrompt, the swap moves
  only the pre-existing Stability::PerTurn <available-skills> listing (same documented
  freshness as enable/disable — honest disclosed re-scope of the brief's prefix DoD),
  and prior-turn message rows byte-equal after a mid-session replaceAll.
- Identity chain verified on disk: Bootstrap::workspace() shares ONE SkillRegistry
  with the engine (backend → sessionApp()->withAvailableSkills), so reload's in-place
  swap reaches listing + tracker; reload tests prove it through real run().
- recheck() traced: pure, deterministic, per-class cap 8 + named overflow, body
  budget stops before an over-cap row then appends the remainder line; removal
  renders escaped name only; rosterRow handles gone-at-render honestly. Hostile
  fence/name payloads escaped-as-data (pins present). skills/ dir-name forging a
  '</turn-context>' name is escaped too.
- reload counts derived from names() before/after; veto list rides across; disabled
  skill re-discovered counts 0/0 and total excludes it (pinned by SkillsReloadTest).
- Doc truth: COMMANDS.md table row + prose and SKILLS.md section match shipped
  behavior; C-EXT id jargon matches the wave's established docs convention (C-EXT-2.c
  rows already live on the same pages).

## Findings (all NIT, non-blocking)
1. NIT — REPORT.md:129 "Suite figure drift: +21T net" contradicts its own breakdown
   "13+5+3+3+1 = 25 new methods", and my per-class diff confirms +25. Welder re-pins
   from live enumeration anyway; erratum only.
2. NIT — SkillRosterDiffTracker.php:86-91 docblock says "MAX_TOTAL_BYTES of body
   under the header" but the budget counter (recheck() :158-166) includes the header
   bytes in $candidate, so usable body is the ceiling minus the header (~122B).
   Bound still ≤4096+remainder-line; wording precision only.

## Weld-carry (report declares all of these; verified)
- DUR rows: tests/Skills/SkillRosterDiffTrackerTest.php, tests/Host/Commands/SkillsReloadTest.php.
- Suite-figure delta: +25T live enumeration (not the report's 21 headline).
- Foreign reds for owning lanes/weld: OneSidedHomeSandbox roster pair (53b8e0fbc/
  d361720d9 files), ChatHostLangCatalogue 6 orphan chat.* keys.
- BRIEF.md stays untracked; only REPORT.md committed.
