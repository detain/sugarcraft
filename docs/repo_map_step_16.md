# Step 16 — candy-forms onto shared foundations

**Branch:** `ai/candy-forms-shared`
**Depends on:** step-02 (candy-buffer), step-04 (candy-testing), step-07 (sugar-fuzzy), step-15 (candy-forms migrated — candy-forms builds on it)
**Blocks:** step-23 (async), step-24 (vim mode)

## Goal

Migrate `candy-forms` onto candy-buffer (rendering), candy-testing (snapshot infrastructure), sugar-fuzzy (drop the `class_alias` to candy-forms' FuzzyMatcher in favor of a direct sugar-fuzzy dependency). After this step, candy-forms's own `src/Fuzzy/FuzzyMatcher.php` (currently a `class_alias`) can be deprecated and removed in a future cleanup (not this step — back-compat kept).

Reference: §369.1 (consolidate testing), §387.1 (fuzzy reinvention).

## Files expected to be modified

- `candy-forms/composer.json` — add `sugarcraft/candy-buffer`, `sugarcraft/candy-testing` (dev), `sugarcraft/sugar-fuzzy` (prod). Remove the now-redundant `sugarcraft/candy-forms` dep if it was only there for FuzzyMatcher (verify with grep — it likely is needed for other reasons).
- `candy-forms/src/Fuzzy/FuzzyMatcher.php` — keep the class_alias for back-compat, but the alias target shifts from `\SugarCraft\Forms\Fuzzy\FuzzyMatcher` to `\SugarCraft\Fuzzy\Matcher\SmithWatermanMatcher`.
- candy-forms's main runtime (the prompt UI) — render via candy-buffer where the migration is low-risk.
- `candy-forms/tests/` — convert to candy-testing helpers.

## Acceptance criteria

- [ ] All existing candy-forms tests pass.
- [ ] `\SugarCraft\Forms\Fuzzy\FuzzyMatcher` continues to resolve (back-compat).
- [ ] candy-forms renderers byte-equivalent to before for existing fixtures.
- [ ] Tests use candy-testing's assertGoldenAnsi helper.
- [ ] ≥95 % coverage maintained.
- [ ] `git status` clean on master.

## Coder brief

1. **path-repo closure**: add the 3 new requires.
2. **Repoint the FuzzyMatcher alias** to sugar-fuzzy directly. Confirm no behavioural diff via the sugar-fuzzy parity golden tests.
3. **Convert tests** to candy-testing helpers.
4. **Migrate renderer** to candy-buffer where straightforward.
5. Run phpunit in candy-forms + dependents.

## Tester brief

- Existing suite green.
- Add a golden test pinning candy-forms's rendered output for a representative prompt (Confirm + Text).
- Verify alias works: `(new \ReflectionClass(\SugarCraft\Forms\Fuzzy\FuzzyMatcher::class))->getName()` returns the sugar-fuzzy class.

## Scribe brief

- `candy-forms/README.md`: `## Shared foundations`.
- `candy-forms/CALIBER_LEARNINGS.md`: alias target updated; back-compat preserved.

## Ship brief

- **PR title**: `candy-forms: adopt candy-buffer + candy-testing + sugar-fuzzy (direct)`
- **PR body**:
  ```
  ## Summary
  - candy-forms now depends on sugar-fuzzy directly (was: aliased through candy-forms).
  - Renderer migrated to candy-buffer where low-risk; remainder deferred to follow-up.
  - Tests use candy-testing's golden-file helpers.
  - \SugarCraft\Forms\Fuzzy\FuzzyMatcher alias preserved (now targets sugar-fuzzy).

  ## Test plan
  - [x] vendor/bin/phpunit in candy-forms (≥95% coverage)
  - [x] Alias resolution test
  - [x] php tools/check-path-repos.php

  Refs: docs/repo_map_step_16.md, docs/repo_map_update.md §369.1, §387.1
  ```
- Commit subject: `candy-forms: adopt shared foundation packages`.
