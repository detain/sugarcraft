# Step 29 — Terminal-probe consumers (candy-mdview + candy-wish) <!-- @rename-keep -->

**Branch:** `ai/probe-consumers`
**Depends on:** step-13 (TerminalProbe in sugar-colorprofile)
**Blocks:** —

## Goal

Migrate `candy-mdview` and `candy-wish` to consume `TerminalProbe` from sugar-colorprofile (extracted in step-13). Today both libs do their own probing; both have known failure modes on Windows/SSH/old terminals. <!-- @rename-keep -->

Reference: §387.9 (terminal-probing reinvention), §13.5 (probing failures cited for these libs).

## Files expected to be modified

- `candy-mdview/composer.json` · `candy-wish/composer.json` — add `sugarcraft/sugar-colorprofile` if not already there (it might already be there for color profile); confirm via `path-repo-closure`. <!-- @rename-keep -->
- `candy-mdview/src/` — replace any direct env-var/terminfo probing with `\SugarCraft\ColorProfile\Probe\TerminalProbe::run()`. Read `Capability` enum to decide rendering paths (e.g., Sixel vs HalfBlock).
- `candy-wish/src/` — same. <!-- @rename-keep -->
- Both libs' existing probing-related tests can be removed (now tested centrally in sugar-colorprofile) — replace with consumer-side tests confirming the lib correctly maps Capabilities to its rendering choices.

## Acceptance criteria

- [ ] No env-var / terminfo parsing code left in candy-mdview or candy-wish. <!-- @rename-keep -->
- [ ] Both libs consume `TerminalProbe::run()` for any capability check.
- [ ] On simulated probe failure, both libs degrade gracefully (no panics).
- [ ] Existing tests pass (after porting probe-specific tests to consumer-side mapping tests).
- [ ] ≥95 % coverage maintained.
- [ ] `git status` clean on master.

## Coder brief

1. **path-repo closure**: sugar-colorprofile in both libs if not already there.
2. **candy-mdview**: identify probing call sites (look for `getenv('TERM_PROGRAM')`, `getenv('COLORTERM')`, terminfo lookups). Replace each with a `Capability` check via TerminalProbe.
3. **candy-wish**: same. <!-- @rename-keep -->
4. **Graceful failure**: ensure that if TerminalProbe throws (it shouldn't after step-13, but defensively), both libs fall back to the lowest-common-denominator rendering.
5. Run phpunit + check-path-repos.

## Tester brief

- Mock TerminalProbe via DI (test seam) to return fixture ProbeReports; assert each lib picks the right rendering path.
- Simulated probe failure: TerminalProbe throws → lib still produces output (no panic).
- Coverage on each lib's rendering-decision branches.

## Scribe brief

- Each lib's README: `## Shared foundations` mentioning sugar-colorprofile's TerminalProbe.
- CALIBER_LEARNINGS in each: "Don't getenv() or read terminfo directly — go through TerminalProbe."

## Ship brief

- **PR title**: `candy-mdview + candy-wish: consume TerminalProbe` <!-- @rename-keep -->
- **PR body**:
  ```
  ## Summary
  - candy-mdview + candy-wish migrated to sugar-colorprofile's TerminalProbe (step-13). <!-- @rename-keep -->
  - Direct env-var/terminfo probing removed from both libs.
  - Graceful fallback on simulated probe failure verified.
  - Closes §387.9 (probing reinvention) and addresses Windows/SSH/old-terminal failure modes.

  ## Test plan
  - [x] vendor/bin/phpunit in candy-mdview + candy-wish (≥95% each) <!-- @rename-keep -->
  - [x] Mocked-Probe consumer-side mapping tests
  - [x] php tools/check-path-repos.php

  Refs: docs/repo_map_step_29.md, docs/repo_map_update.md §387.9, §13.5
  ```
- Commit subject: `candy-mdview + candy-wish: consume TerminalProbe`. <!-- @rename-keep -->
