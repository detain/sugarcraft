# Step 15 — candy-forms onto shared foundations

**Branch:** `ai/candy-forms-shared`
**Depends on:** step-02 (candy-buffer), step-03 (candy-layout), step-04 (candy-testing), step-07 (candy-fuzzy), step-09 (candy-core ProgressReporter — optional)
**Blocks:** step-23 (async consumers includes candy-forms), step-24 (vim mode consolidation)

## Goal

Migrate `candy-forms` (Form + 7 field types: TextInput, TextArea, Select, MultiSelect, Confirm, Note, FilePicker) onto: candy-buffer (cell rendering), candy-layout (form/field sizing), candy-testing (snapshot infrastructure — replace any per-test ad-hoc fixtures), candy-fuzzy (Select / MultiSelect filter delegates to candy-fuzzy). candy-forms keeps its own FuzzyMatcher as the back-compat shim added in step-7.

Reference: §369.1 (merge candy-forms + sugar-prompt testing utilities → candy-testing), §387.1 (fuzzy reinvention).

## Files expected to be modified

- `candy-forms/composer.json` — add `sugarcraft/candy-buffer`, `sugarcraft/candy-layout`, `sugarcraft/candy-testing` (dev-only OK), `sugarcraft/candy-fuzzy` (production).
- `candy-forms/src/Form.php` — layout fields via candy-layout when an explicit constraint set is provided.
- `candy-forms/src/Field/Select.php` + `MultiSelect.php` — internal filter delegates to candy-fuzzy (the existing public API stays).
- `candy-forms/src/TextInput/TextInput.php` + `TextArea/TextArea.php` — render via candy-buffer (snapshot tests pin byte output for safety).
- `candy-forms/src/Fuzzy/FuzzyMatcher.php` — already a back-compat shim from step-7; no further edits.
- `candy-forms/tests/` — convert any ad-hoc fixture loading to candy-testing's `assertGoldenAnsi` + `assertCellGrid`.

## Acceptance criteria

- [ ] All existing candy-forms tests pass; converted-to-golden tests use candy-testing helpers.
- [ ] Select/MultiSelect filter UX unchanged (same candidate ordering); match highlights newly visible via candy-fuzzy indices.
- [ ] Renderers (TextInput, TextArea, Select view) byte-equivalent to before (snapshot tests assert).
- [ ] No regression in any candy-forms dependent (sugar-prompt is the main one).
- [ ] ≥95 % coverage maintained.
- [ ] `git status` clean on master.

## Coder brief

1. **path-repo closure**: add the 4 new requires.
2. **Migrate filters first** (lowest risk): Select / MultiSelect internal `filter()` method now uses `\SugarCraft\Fuzzy\Matcher\SmithWatermanMatcher`. Existing public `withFilter(callable)` injection point preserved.
3. **Migrate renderers next**: TextInput + TextArea render through candy-buffer. Build a Buffer, paint glyphs, render to ANSI via Buffer's toAnsi() (or a temporary string-render fallback if buffer toAnsi isn't there yet — append a `Need Buffer::toAnsi()` to `docs/repo_map_updates.md` for step-26 to address).
4. **Convert tests**: replace ad-hoc fixture loaders with `\SugarCraft\Testing\Snapshot\Assertions::assertGoldenAnsi(...)`. Goldens live in `tests/fixtures/`.
5. **Form layout**: if a Form is built with `withConstraints([...])`, route through candy-layout's `LayoutSolver`. Otherwise keep the existing greedy-vertical default.
6. Run phpunit in candy-forms + sugar-prompt + affected-libs.

## Tester brief

- All existing tests converted to candy-testing helpers; each formerly-string-equality assertion becomes assertGoldenAnsi with the fixture filename.
- Add a Select test with a deliberately ambiguous query ("ab") against ["alpha", "ablation", "label"] — assert ordering by candy-fuzzy score + that highlight indices are non-empty for matched candidates.
- ItemList of candy-forms (if present) — same.

## Scribe brief

- `candy-forms/README.md`: `## Shared foundations` section.
- `candy-forms/CALIBER_LEARNINGS.md`: golden-file convention; any divergence between candy-forms' own FuzzyMatcher behavior and candy-fuzzy's (should be none).

## Ship brief

- **PR title**: `candy-forms: adopt candy-buffer + candy-layout + candy-testing + candy-fuzzy`
- **PR body**:
  ```
  ## Summary
  - candy-forms migrated onto 4 shared packages (buffer, layout, testing, fuzzy).
  - Select / MultiSelect filter now shows match-highlight indices via candy-fuzzy.
  - Tests converted to candy-testing's golden-file helpers.
  - candy-forms\Fuzzy\FuzzyMatcher remains as the back-compat shim (delegates to candy-fuzzy).

  ## Test plan
  - [x] vendor/bin/phpunit in candy-forms (≥95% coverage, golden fixtures in place)
  - [x] vendor/bin/phpunit in sugar-prompt (dependent — unchanged)
  - [x] php tools/check-path-repos.php

  Refs: docs/repo_map_step_15.md, docs/repo_map_update.md §369.1, §387.1
  ```
- Commit subject: `candy-forms: adopt shared foundation packages`.
