# Step 15 — sugar-forms onto shared foundations

**Branch:** `ai/sugar-forms-shared`
**Depends on:** step-02 (sugar-cellbuffer), step-03 (sugar-constraints), step-04 (sugar-testing), step-07 (sugar-fuzzy), step-09 (sugar-core ProgressReporter — optional)
**Blocks:** step-23 (async consumers includes sugar-forms), step-24 (vim mode consolidation)

## Goal

Migrate `sugar-forms` (Form + 7 field types: TextInput, TextArea, Select, MultiSelect, Confirm, Note, FilePicker) onto: sugar-cellbuffer (cell rendering), sugar-constraints (form/field sizing), sugar-testing (snapshot infrastructure — replace any per-test ad-hoc fixtures), sugar-fuzzy (Select / MultiSelect filter delegates to sugar-fuzzy). sugar-forms keeps its own FuzzyMatcher as the back-compat shim added in step-7.

Reference: §369.1 (merge sugar-forms + sugar-forms testing utilities → sugar-testing), §387.1 (fuzzy reinvention).

## Files expected to be modified

- `sugar-forms/composer.json` — add `sugarcraft/sugar-cellbuffer`, `sugarcraft/sugar-constraints`, `sugarcraft/sugar-testing` (dev-only OK), `sugarcraft/sugar-fuzzy` (production).
- `sugar-forms/src/Form.php` — layout fields via sugar-constraints when an explicit constraint set is provided.
- `sugar-forms/src/Field/Select.php` + `MultiSelect.php` — internal filter delegates to sugar-fuzzy (the existing public API stays).
- `sugar-forms/src/TextInput/TextInput.php` + `TextArea/TextArea.php` — render via sugar-cellbuffer (snapshot tests pin byte output for safety).
- `sugar-forms/src/Fuzzy/FuzzyMatcher.php` — already a back-compat shim from step-7; no further edits.
- `sugar-forms/tests/` — convert any ad-hoc fixture loading to sugar-testing's `assertGoldenAnsi` + `assertCellGrid`.

## Acceptance criteria

- [ ] All existing sugar-forms tests pass; converted-to-golden tests use sugar-testing helpers.
- [ ] Select/MultiSelect filter UX unchanged (same candidate ordering); match highlights newly visible via sugar-fuzzy indices.
- [ ] Renderers (TextInput, TextArea, Select view) byte-equivalent to before (snapshot tests assert).
- [ ] No regression in any sugar-forms dependent (sugar-forms is the main one).
- [ ] ≥95 % coverage maintained.
- [ ] `git status` clean on master.

## Coder brief

1. **path-repo closure**: add the 4 new requires.
2. **Migrate filters first** (lowest risk): Select / MultiSelect internal `filter()` method now uses `\SugarCraft\Fuzzy\Matcher\SmithWatermanMatcher`. Existing public `withFilter(callable)` injection point preserved.
3. **Migrate renderers next**: TextInput + TextArea render through sugar-cellbuffer. Build a Buffer, paint glyphs, render to ANSI via Buffer's toAnsi() (or a temporary string-render fallback if buffer toAnsi isn't there yet — append a `Need Buffer::toAnsi()` to `docs/repo_map_updates.md` for step-26 to address).
4. **Convert tests**: replace ad-hoc fixture loaders with `\SugarCraft\Testing\Snapshot\Assertions::assertGoldenAnsi(...)`. Goldens live in `tests/fixtures/`.
5. **Form layout**: if a Form is built with `withConstraints([...])`, route through sugar-constraints's `LayoutSolver`. Otherwise keep the existing greedy-vertical default.
6. Run phpunit in sugar-forms + sugar-forms + affected-libs.

## Tester brief

- All existing tests converted to sugar-testing helpers; each formerly-string-equality assertion becomes assertGoldenAnsi with the fixture filename.
- Add a Select test with a deliberately ambiguous query ("ab") against ["alpha", "ablation", "label"] — assert ordering by sugar-fuzzy score + that highlight indices are non-empty for matched candidates.
- ItemList of sugar-forms (if present) — same.

## Scribe brief

- `sugar-forms/README.md`: `## Shared foundations` section.
- `sugar-forms/CALIBER_LEARNINGS.md`: golden-file convention; any divergence between sugar-forms' own FuzzyMatcher behavior and sugar-fuzzy's (should be none).

## Ship brief

- **PR title**: `sugar-forms: adopt sugar-cellbuffer + sugar-constraints + sugar-testing + sugar-fuzzy`
- **PR body**:
  ```
  ## Summary
  - sugar-forms migrated onto 4 shared packages (buffer, layout, testing, fuzzy).
  - Select / MultiSelect filter now shows match-highlight indices via sugar-fuzzy.
  - Tests converted to sugar-testing's golden-file helpers.
  - sugar-forms\Fuzzy\FuzzyMatcher remains as the back-compat shim (delegates to sugar-fuzzy).

  ## Test plan
  - [x] vendor/bin/phpunit in sugar-forms (≥95% coverage, golden fixtures in place)
  - [x] vendor/bin/phpunit in sugar-forms (dependent — unchanged)
  - [x] php tools/check-path-repos.php

  Refs: docs/repo_map_step_15.md, docs/repo_map_update.md §369.1, §387.1
  ```
- Commit subject: `sugar-forms: adopt shared foundation packages`.
