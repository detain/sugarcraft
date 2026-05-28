# Step 36 — candy-flip + candy-kit + honey-bounce + honey-flap adopt candy-testing

**Branch:** `ai/testing-rollout`
**Depends on:** step-04 (candy-testing), step-30 (audit)
**Blocks:** —

## Goal

Four libs that render output (candy-flip GIF flipbook, candy-kit CLI presenter, honey-bounce spring physics with sample rendering, honey-flap projectile physics with sample rendering) adopt candy-testing for snapshot coverage. None of these were named in the primary analysis but all have rendered output that benefits from golden-file pinning.

Reference: step-30 audit. honey-bounce + candy-kit superiority cited (§420/§446) — pinning their behavior protects that superiority during future refactors.

## Files expected to be modified

- 4 composer.json — add `sugarcraft/candy-testing` (dev) via `path-repo-closure`.
- Each lib's tests/ → add assertGoldenAnsi snapshots for representative renders / outputs.

## Acceptance criteria

- [ ] Each of the 4 libs has at least one snapshot test.
- [ ] Existing tests pass.
- [ ] ≥95 % coverage maintained.
- [ ] `git status` clean on master.

## Coder brief

1. Step-30 audit per lib.
2. **path-repo closure** (dev-only).
3. Per lib: identify a representative output, capture as golden, assertGoldenAnsi.
4. honey-bounce / honey-flap: their outputs might be numeric trajectories rather than ANSI — in that case, use assertGolden (file equality) on the JSON / CSV representation, not assertGoldenAnsi specifically.
5. Run phpunit + check-path-repos.

## Tester brief

- Per lib: at least one snapshot of canonical output.
- candy-flip: a single frame snapshot.
- candy-kit: a presenter slide snapshot.
- honey-bounce: a spring trajectory snapshot (numeric).
- honey-flap: a projectile trajectory snapshot (numeric).

## Scribe brief

- READMEs add `## Snapshot tests` note.
- CALIBER_LEARNINGS: "Use candy-testing's assertGolden* for any renderable/serializable output."

## Ship brief

- **PR title**: `candy-flip + candy-kit + honey-bounce + honey-flap: snapshot tests via candy-testing`
- **PR body**:
  ```
  ## Summary
  - Four libs gain golden-file snapshot coverage via candy-testing.
  - Pins behavior of libs cited as superior to upstream (§420 honey-bounce, §446 candy-kit).
  - Numeric outputs use assertGolden file equality; ANSI outputs use assertGoldenAnsi.

  ## Test plan
  - [x] vendor/bin/phpunit in all 4 (≥95%)
  - [x] Goldens committed
  - [x] php tools/check-path-repos.php

  Refs: docs/repo_map_step_36.md, docs/repo_map_update_followups.md
  ```
- Commit subject: `candy-flip + candy-kit + honey-bounce + honey-flap: snapshot tests via candy-testing`.
