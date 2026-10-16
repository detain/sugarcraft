# Step 33 — candy-kv + candy-ssh-launcher + candy-git adopt sugar-fuzzy

**Branch:** `ai/filter-consumers`
**Depends on:** step-07 (sugar-fuzzy), step-30 (audit)
**Blocks:** —

## Goal

Three libs that do filtering / search adopt sugar-fuzzy for scored-with-indices matching: candy-kv (key-value store TUI with key filtering), candy-ssh-launcher (SSH host list with name filtering), candy-git (whatever it stashes — filter via fuzzy).

Reference: step-30 audit. None of these libs are explicitly in §387.1 but all use ad-hoc filter approaches.

## Files expected to be modified

- 3 libs' composer.json — add `sugarcraft/sugar-fuzzy` via `path-repo-closure`.
- Each lib's filter code path — use sugar-fuzzy's `SmithWatermanMatcher::match()` and expose matched indices for highlighting.

## Acceptance criteria

- [ ] Each lib's filter UI shows scored ranking + highlighted match indices.
- [ ] Existing filter tests pass; new highlight tests added.
- [ ] ≥95 % coverage maintained.
- [ ] `git status` clean on master.

## Coder brief

1. Read step-30 audit entries.
2. **path-repo closure**: sugar-fuzzy in all 3 libs.
3. Per lib: locate the filter function (probably `str_contains` or similar today); replace with sugar-fuzzy match.
4. Wire matched-indices into the renderer (style the matched chars).
5. Run phpunit + check-path-repos.

## Tester brief

- Per lib: filter test with ambiguous query → ranked output.
- Highlight rendering test pinning the styled-substring output.

## Scribe brief

- Each lib's README: `## Shared foundations`.
- CALIBER_LEARNINGS: "Use sugar-fuzzy; don't `str_contains`-style filter."

## Ship brief

- **PR title**: `candy-kv + candy-ssh-launcher + candy-git: adopt sugar-fuzzy`
- **PR body**:
  ```
  ## Summary
  - Three filter-heavy libs adopt sugar-fuzzy.
  - Scored ranking + match-highlight indices now available.
  - Existing UX preserved for the no-query case.

  ## Test plan
  - [x] vendor/bin/phpunit in 3 libs (≥95% each)
  - [x] Highlight rendering tests
  - [x] php tools/check-path-repos.php

  Refs: docs/repo_map_step_33.md, docs/repo_map_update_followups.md
  ```
- Commit subject: `candy-kv + candy-ssh-launcher + candy-git: adopt sugar-fuzzy`.
