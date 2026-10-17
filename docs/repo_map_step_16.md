# Step 16 — sugar-forms onto shared foundations

**Branch:** `ai/sugar-forms-shared`
**Depends on:** step-02 (sugar-cellbuffer), step-04 (sugar-testing), step-07 (sugar-fuzzy), step-15 (sugar-forms migrated — sugar-forms builds on it)
**Blocks:** step-23 (async), step-24 (vim mode)

## Goal

Migrate `sugar-forms` onto sugar-cellbuffer (rendering), sugar-testing (snapshot infrastructure), sugar-fuzzy (drop the `class_alias` to sugar-forms' FuzzyMatcher in favor of a direct sugar-fuzzy dependency). After this step, sugar-forms's own `src/Fuzzy/FuzzyMatcher.php` (currently a `class_alias`) can be deprecated and removed in a future cleanup (not this step — back-compat kept).

Reference: §369.1 (consolidate testing), §387.1 (fuzzy reinvention).

## Files expected to be modified

- `sugar-forms/composer.json` — add `sugarcraft/sugar-cellbuffer`, `sugarcraft/sugar-testing` (dev), `sugarcraft/sugar-fuzzy` (prod). Remove the now-redundant `sugarcraft/sugar-forms` dep if it was only there for FuzzyMatcher (verify with grep — it likely is needed for other reasons).
- `sugar-forms/src/Fuzzy/FuzzyMatcher.php` — keep the class_alias for back-compat, but the alias target shifts from `\SugarCraft\Forms\Fuzzy\FuzzyMatcher` to `\SugarCraft\Fuzzy\Matcher\SmithWatermanMatcher`.
- sugar-forms's main runtime (the prompt UI) — render via sugar-cellbuffer where the migration is low-risk.
- `sugar-forms/tests/` — convert to sugar-testing helpers.

## Acceptance criteria

- [ ] All existing sugar-forms tests pass.
- [ ] `\SugarCraft\Forms\Fuzzy\FuzzyMatcher` continues to resolve (back-compat).
- [ ] sugar-forms renderers byte-equivalent to before for existing fixtures.
- [ ] Tests use sugar-testing's assertGoldenAnsi helper.
- [ ] ≥95 % coverage maintained.
- [ ] `git status` clean on master.

## Coder brief

1. **path-repo closure**: add the 3 new requires.
2. **Repoint the FuzzyMatcher alias** to sugar-fuzzy directly. Confirm no behavioural diff via the sugar-fuzzy parity golden tests.
3. **Convert tests** to sugar-testing helpers.
4. **Migrate renderer** to sugar-cellbuffer where straightforward.
5. Run phpunit in sugar-forms + dependents.

## Tester brief

- Existing suite green.
- Add a golden test pinning sugar-forms's rendered output for a representative prompt (Confirm + Text).
- Verify alias works: `(new \ReflectionClass(\SugarCraft\Forms\Fuzzy\FuzzyMatcher::class))->getName()` returns the sugar-fuzzy class.

## Scribe brief

- `sugar-forms/README.md`: `## Shared foundations`.
- `sugar-forms/CALIBER_LEARNINGS.md`: alias target updated; back-compat preserved.

## Ship brief

- **PR title**: `sugar-forms: adopt sugar-cellbuffer + sugar-testing + sugar-fuzzy (direct)`
- **PR body**:
  ```
  ## Summary
  - sugar-forms now depends on sugar-fuzzy directly (was: aliased through sugar-forms).
  - Renderer migrated to sugar-cellbuffer where low-risk; remainder deferred to follow-up.
  - Tests use sugar-testing's golden-file helpers.
  - \SugarCraft\Forms\Fuzzy\FuzzyMatcher alias preserved (now targets sugar-fuzzy).

  ## Test plan
  - [x] vendor/bin/phpunit in sugar-forms (≥95% coverage)
  - [x] Alias resolution test
  - [x] php tools/check-path-repos.php

  Refs: docs/repo_map_step_16.md, docs/repo_map_update.md §369.1, §387.1
  ```
- Commit subject: `sugar-forms: adopt shared foundation packages`.
