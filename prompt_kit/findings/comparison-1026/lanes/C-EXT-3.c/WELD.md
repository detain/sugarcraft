# C-EXT-3.c — WELD (2026-10-28, welder)

Step: Bundled `skill-creator` built-in + `/skills new <name>` scaffold.
Lane branch cc/C-EXT-3.c tip 22099540eba72a72da7f3bbe46808f925b97eee2
(single commit over base after the r2 cure amend; 9 files +363/−17).

## Pick

- Cherry-picked clean onto master a7abfe61c → **a1adc4f35bf1efc8d96f161ddf84b690cc3b3c74**
  (`C-EXT-3.c: Bundled skill-creator + /skills new scaffold`). Zero conflicts.
- Author+committer detain@interserver.net (`%ae`/`%ce` separate greps 1/1), zero trailers.

## Review chain

- REVIEW-1: CLEAN 0C/0M + 1 step-local MINOR (no-home refusal arm unpinned at the
  command surface).
- FIX-1 r2 cure: `testNoOwnedHomeRefusesTheScaffoldAtTheCommandSurface` leg landed
  (chmod-0777 lever, Role::System 'no home directory' notice + skills-proposed never
  created); red-before PROVEN by HomeDirectory::owned() neuter cp-harness (exactly 1F,
  dangerous shape), restore md5 396c409d cmp-clean; blob-verified in 22099540e; amended
  IN PLACE, single-commit law held (7dbe548f6 → 22099540e).

## Merged-tree gates (plain pipe, env -u LINES -u COLUMNS, cwd candy-code, PtyRunToolTest excluded)

- `SkillsNew|BuiltInSkills|SkillsHostCommand|SkillsEnableDisable`: **OK, 162T/798A**
  (era 161T/795A + 1 cure leg). Broadened `SkillsNew|BuiltIn|...` spelling: 1404T/138957A green.
- Guard set (ClaimFamilies|CommandDocs|SkillPromptLine|PathsGlob|CompiledPattern|Inert|
  EnvRoster|LangParity|ConfigWriteProducer|ReadmeRoster|DocFigure|GlobDialect|DupHelper):
  **OK, 278T/46651A** (set has grown since reviewer's 231T-era spelling — all green).
- Census set `ReadPathCensus|FrontmatterKeyAudit|SkillFrontmatter|SlashDispatch|TreeWideGuardRoster`:
  **OK, 109T/4731A**.
- `php tools/gen-command-docs.php --check`: rc0 ("command docs: up to date") — COMMANDS.md
  row rides the pick; no riders owed (zero src/Protocol/** or web files; suite-figure/dist untouched).
- Durations: separate `scripts:` commit **0cebf66532cfbd833f7289b84d5c0083c9062e1b**, one row
  `candy-code/tests/Host/Commands/SkillsNewTest.php 0.023 6` (junit per-file sum; tsv 1522→1523).
  Set-equality live recount: 1591 live *Test.php vs 1522 tsv rows → my row closes MY gap;
  68 other missing rows are pre-existing foreign gaps (report-only, other welds' debt).

## Carries recorded at weld

- **2 NITs from REVIEW-1** (non-blocking, no action): (1) builder REPORT quotes guard-set
  46381A vs reviewer tree 46392A (+11 tree-scan census wobble; both green); (2)
  CompiledPatternCacheBoundTest docblock :134 prose reads "the shipped built-ins" (wordless)
  — unaffected by the eight→nine count flip.
- **en-only `host.skills.new.*` lang keys** — correct today (candy-code/lang/ holds only
  en.php); the 11-locale strict-parity fleet lands LAST at C-TUI-6.h (§6.3 ruling honored).
  Carried to C-TUI-6.h locale sweep.
- **SkillRegistry docblock eight→nine flip provenance**: MANDATED in-step —
  CompiledPatternCacheBoundTest.php:219 derives the word-count from the LIVE BuiltIn roster
  and asserts it against that docblock; leaving 'eight' would have reddened the guard.
  Honest deviation, not scope creep (REVIEW-1 §2).

## Absorption

- `git cherry master cc/C-EXT-3.c` → '-' (patch-id absorbed); worktree + branch pruned post-weld.
