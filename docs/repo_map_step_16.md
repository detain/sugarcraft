# Step 16 — sugar-prompt onto shared foundations

**Branch:** `ai/sugar-prompt-shared`
**Depends on:** step-02 (candy-buffer), step-04 (candy-testing), step-07 (candy-fuzzy), step-15 (candy-forms migrated — sugar-prompt builds on it)
**Blocks:** step-23 (async), step-24 (vim mode)

## Goal

Migrate `sugar-prompt` onto candy-buffer (rendering), candy-testing (snapshot infrastructure), candy-fuzzy (drop the `class_alias` to candy-forms' FuzzyMatcher in favor of a direct candy-fuzzy dependency). After this step, sugar-prompt's own `src/Fuzzy/FuzzyMatcher.php` (currently a `class_alias`) can be deprecated and removed in a future cleanup (not this step — back-compat kept).

Reference: §369.1 (consolidate testing), §387.1 (fuzzy reinvention).

## Files expected to be modified

- `sugar-prompt/composer.json` — add `sugarcraft/candy-buffer`, `sugarcraft/candy-testing` (dev), `sugarcraft/candy-fuzzy` (prod). Remove the now-redundant `sugarcraft/candy-forms` dep if it was only there for FuzzyMatcher (verify with grep — it likely is needed for other reasons).
- `sugar-prompt/src/Fuzzy/FuzzyMatcher.php` — keep the class_alias for back-compat, but the alias target shifts from `\SugarCraft\Forms\Fuzzy\FuzzyMatcher` to `\SugarCraft\Fuzzy\Matcher\SmithWatermanMatcher`.
- sugar-prompt's main runtime (the prompt UI) — render via candy-buffer where the migration is low-risk.
- `sugar-prompt/tests/` — convert to candy-testing helpers.

## Acceptance criteria

- [ ] All existing sugar-prompt tests pass.
- [ ] `\SugarCraft\Prompt\Fuzzy\FuzzyMatcher` continues to resolve (back-compat).
- [ ] sugar-prompt renderers byte-equivalent to before for existing fixtures.
- [ ] Tests use candy-testing's assertGoldenAnsi helper.
- [ ] ≥95 % coverage maintained.
- [ ] `git status` clean on master.

## Coder brief

1. **path-repo closure**: add the 3 new requires.
2. **Repoint the FuzzyMatcher alias** to candy-fuzzy directly. Confirm no behavioural diff via the candy-fuzzy parity golden tests.
3. **Convert tests** to candy-testing helpers.
4. **Migrate renderer** to candy-buffer where straightforward.
5. Run phpunit in sugar-prompt + dependents.

## Tester brief

- Existing suite green.
- Add a golden test pinning sugar-prompt's rendered output for a representative prompt (Confirm + Text).
- Verify alias works: `(new \ReflectionClass(\SugarCraft\Prompt\Fuzzy\FuzzyMatcher::class))->getName()` returns the candy-fuzzy class.

## Scribe brief

- `sugar-prompt/README.md`: `## Shared foundations`.
- `sugar-prompt/CALIBER_LEARNINGS.md`: alias target updated; back-compat preserved.

## Ship brief

- **PR title**: `sugar-prompt: adopt candy-buffer + candy-testing + candy-fuzzy (direct)`
- **PR body**:
  ```
  ## Summary
  - sugar-prompt now depends on candy-fuzzy directly (was: aliased through candy-forms).
  - Renderer migrated to candy-buffer where low-risk; remainder deferred to follow-up.
  - Tests use candy-testing's golden-file helpers.
  - \SugarCraft\Prompt\Fuzzy\FuzzyMatcher alias preserved (now targets candy-fuzzy).

  ## Test plan
  - [x] vendor/bin/phpunit in sugar-prompt (≥95% coverage)
  - [x] Alias resolution test
  - [x] php tools/check-path-repos.php

  Refs: docs/repo_map_step_16.md, docs/repo_map_update.md §369.1, §387.1
  ```
- Commit subject: `sugar-prompt: adopt shared foundation packages`.
