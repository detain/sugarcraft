# Step 28 — Golden-file snapshot rollout via candy-testing

**Branch:** `ai/golden-file-rollout`
**Depends on:** step-04 (candy-testing exists), step-15/16/17/18 (candy-forms / sugar-prompt / sugar-charts / sugar-table use candy-testing for some tests already)
**Blocks:** —

## Goal

Roll out candy-testing's `assertGoldenAnsi` helpers across all rendering libs that the analysis flagged: `candy-forms`, `sugar-prompt`, `sugar-bits`, `sugar-charts`, `sugar-table`, `sugar-glow`, `candy-vt`, `candy-vcr`, `candy-shine`. Replaces ad-hoc string comparisons and brings every lib into the "snapshot tests for ANSI output" pattern. This is the #1 ecosystem-wide weakness called out in the analysis.

Reference: §19, §149 (zero ANSI byte assertions cited as the most common weakness across 8+ libs).

## Files expected to be modified

- Each of the 9 lib's composer.json — add `sugarcraft/candy-testing` (dev) via `path-repo-closure`.
- Each lib's `tests/` — convert existing tests that build string-equal assertions into `Assertions::assertGoldenAnsi(...)`; add NEW golden-file tests for view() output on representative scenarios.
- `tests/fixtures/` in each lib — golden files added.

## Acceptance criteria

- [ ] Each of the 9 libs has at least one `assertGoldenAnsi` test for a representative renderer (text input, table render, chart, markdown, etc.).
- [ ] Existing tests preserved; new golden tests are additive.
- [ ] `UPDATE_GOLDENS=1 vendor/bin/phpunit` regenerates fixture files (tested in at least one lib end-to-end).
- [ ] Coverage on rendering paths increases measurably (track per-lib coverage delta).
- [ ] `git status` clean on master.

## Coder brief

1. **path-repo closure**: candy-testing (dev-only require) in each of the 9 libs.
2. **For each lib**: identify the most exercised render() method; build a representative input fixture; capture the output via `UPDATE_GOLDENS=1` once; commit the .golden file; the test asserts against it on subsequent runs.
3. **Convert ad-hoc string-equality tests**: where a test currently does `$this->assertSame($expected, $rendered)` with a literal string, swap to `Assertions::assertGoldenAnsi('fixtures/foo.golden', $rendered)`. Drop the inline expected string into the fixture file.
4. **DO NOT** delete any existing tests — only convert or add.
5. Run phpunit in each lib + spot-check `UPDATE_GOLDENS=1` regenerates one of them, then re-run unset to verify the regenerated golden matches.

## Tester brief

- Per lib: add 1+ assertGoldenAnsi test for the canonical view() output of the lib's main component(s).
- Convert any tests where the expected string is embedded literally → use a golden file.
- Verify `UPDATE_GOLDENS=1` flow on candy-vt (or pick one lib that's representative).

## Scribe brief

- Each lib's README: a `## Snapshot tests` paragraph pointing at the golden-file pattern and the UPDATE_GOLDENS env-var workflow.
- Each lib's CALIBER_LEARNINGS: "Use assertGoldenAnsi for any new render() test. Re-record goldens with UPDATE_GOLDENS=1 phpunit after intentional output changes."

## Ship brief

- **PR title**: `9 rendering libs: golden-file snapshot tests via candy-testing`
- **PR body**:
  ```
  ## Summary
  - candy-forms, sugar-prompt, sugar-bits, sugar-charts, sugar-table, sugar-glow, candy-vt, candy-vcr, candy-shine all gain assertGoldenAnsi-backed golden-file tests.
  - Closes the ecosystem's most-cited weakness: "no snapshot tests for ANSI rendering" (§19/§149).
  - UPDATE_GOLDENS=1 phpunit workflow documented.
  - Tests are additive; existing tests preserved.

  ## Test plan
  - [x] vendor/bin/phpunit in 9 libs (existing suites + new golden tests all green)
  - [x] UPDATE_GOLDENS=1 spot-check round-trip verified
  - [x] php tools/check-path-repos.php

  Refs: docs/repo_map_step_28.md, docs/repo_map_update.md §19, §149, §322
  ```
- Commit subject: `9 rendering libs: golden-file snapshot test rollout via candy-testing`.
