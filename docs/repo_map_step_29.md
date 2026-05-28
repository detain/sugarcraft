# Step 29 — Terminal-probe consumers (sugar-glow + candy-wish)

**Branch:** `ai/probe-consumers`
**Depends on:** step-13 (TerminalProbe in candy-palette)
**Blocks:** —

## Goal

Migrate `sugar-glow` and `candy-wish` to consume `TerminalProbe` from candy-palette (extracted in step-13). Today both libs do their own probing; both have known failure modes on Windows/SSH/old terminals.

Reference: §387.9 (terminal-probing reinvention), §13.5 (probing failures cited for these libs).

## Files expected to be modified

- `sugar-glow/composer.json` · `candy-wish/composer.json` — add `sugarcraft/candy-palette` if not already there (it might already be there for color profile); confirm via `path-repo-closure`.
- `sugar-glow/src/` — replace any direct env-var/terminfo probing with `\SugarCraft\Palette\Probe\TerminalProbe::run()`. Read `Capability` enum to decide rendering paths (e.g., Sixel vs HalfBlock).
- `candy-wish/src/` — same.
- Both libs' existing probing-related tests can be removed (now tested centrally in candy-palette) — replace with consumer-side tests confirming the lib correctly maps Capabilities to its rendering choices.

## Acceptance criteria

- [ ] No env-var / terminfo parsing code left in sugar-glow or candy-wish.
- [ ] Both libs consume `TerminalProbe::run()` for any capability check.
- [ ] On simulated probe failure, both libs degrade gracefully (no panics).
- [ ] Existing tests pass (after porting probe-specific tests to consumer-side mapping tests).
- [ ] ≥95 % coverage maintained.
- [ ] `git status` clean on master.

## Coder brief

1. **path-repo closure**: candy-palette in both libs if not already there.
2. **sugar-glow**: identify probing call sites (look for `getenv('TERM_PROGRAM')`, `getenv('COLORTERM')`, terminfo lookups). Replace each with a `Capability` check via TerminalProbe.
3. **candy-wish**: same.
4. **Graceful failure**: ensure that if TerminalProbe throws (it shouldn't after step-13, but defensively), both libs fall back to the lowest-common-denominator rendering.
5. Run phpunit + check-path-repos.

## Tester brief

- Mock TerminalProbe via DI (test seam) to return fixture ProbeReports; assert each lib picks the right rendering path.
- Simulated probe failure: TerminalProbe throws → lib still produces output (no panic).
- Coverage on each lib's rendering-decision branches.

## Scribe brief

- Each lib's README: `## Shared foundations` mentioning candy-palette's TerminalProbe.
- CALIBER_LEARNINGS in each: "Don't getenv() or read terminfo directly — go through TerminalProbe."

## Ship brief

- **PR title**: `sugar-glow + candy-wish: consume TerminalProbe`
- **PR body**:
  ```
  ## Summary
  - sugar-glow + candy-wish migrated to candy-palette's TerminalProbe (step-13).
  - Direct env-var/terminfo probing removed from both libs.
  - Graceful fallback on simulated probe failure verified.
  - Closes §387.9 (probing reinvention) and addresses Windows/SSH/old-terminal failure modes.

  ## Test plan
  - [x] vendor/bin/phpunit in sugar-glow + candy-wish (≥95% each)
  - [x] Mocked-Probe consumer-side mapping tests
  - [x] php tools/check-path-repos.php

  Refs: docs/repo_map_step_29.md, docs/repo_map_update.md §387.9, §13.5
  ```
- Commit subject: `sugar-glow + candy-wish: consume TerminalProbe`.
