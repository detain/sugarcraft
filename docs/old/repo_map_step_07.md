# Step 07 — Create sugar-fuzzy

**Branch:** `ai/sugar-fuzzy-new`
**Depends on:** step-00 ✅
**Blocks:** step-14 (sugar-widgets uses it), step-15 (sugar-forms uses it), step-16 (sugar-forms uses it), step-19 (candy-scriptui uses it)

## Goal

Create the `sugar-fuzzy` foundation package — extracts `FuzzyMatcher` from `sugar-forms/src/Fuzzy/FuzzyMatcher.php` (canonical Smith-Waterman impl) and the mirror in `sugar-treelist/src/FuzzyMatch.php`. **The key new feature: ranked matches WITH the scored matched character indices**, so UI filter highlighting becomes possible (currently impossible across the ecosystem).

Reference: `docs/repo_map_update.md` §345.3 (FuzzyMatcher interface with matched indices), §387.1 (4-way reinvention).

## Files expected to be created

- `sugar-fuzzy/composer.json`
- `sugar-fuzzy/phpunit.xml`
- `sugar-fuzzy/README.md`
- `sugar-fuzzy/CALIBER_LEARNINGS.md`
- `sugar-fuzzy/src/FuzzyMatcher.php` — interface.
- `sugar-fuzzy/src/MatchResult.php` — readonly (score: int, candidate: string, indices: list<int>).
- `sugar-fuzzy/src/Matcher/SmithWatermanMatcher.php` — canonical impl (port of sugar-forms/sugar-treelist).
- `sugar-fuzzy/src/Matcher/SahilmMatcher.php` — gum-style scoring (port of `sahilm/fuzzy` algorithm).
- `sugar-fuzzy/src/Highlighter.php` — small helper: given a MatchResult, wrap matched indices in `<style>` tokens (a presentation-neutral callable interface).
- `sugar-fuzzy/tests/SmithWatermanMatcherTest.php`, `SahilmMatcherTest.php`, `HighlighterTest.php`.

## Files expected to be modified

- Root composer.json, MATCHUPS, PROJECT_NAMES, README, docs/index.html, docs/lib/sugar-fuzzy.html, codecov.yml.
- **Deprecation shims** (NOT removal — that's later steps):
  - `sugar-forms/src/Fuzzy/FuzzyMatcher.php` — add `@deprecated since 1.x; use SugarCraft\Fuzzy\Matcher\SmithWatermanMatcher` docblock. Keep behavior identical.
  - `sugar-treelist/src/FuzzyMatch.php` — same shim.
  - `sugar-forms/src/Fuzzy/FuzzyMatcher.php` — already a `class_alias` to sugar-forms; leave untouched.

## Acceptance criteria

- [ ] `FuzzyMatcher::match(string $query, string $candidate): ?MatchResult` — null when no match, MatchResult with score + indices otherwise.
- [ ] `FuzzyMatcher::matchAll(string $query, iterable $candidates): list<MatchResult>` — sorted by score desc, then by candidate asc as tiebreak.
- [ ] `MatchResult::indices()` returns 0-based byte offsets of matched chars (UTF-8 safe: indices are at character boundaries).
- [ ] `SmithWatermanMatcher` is **bit-equivalent in score and ranking** to existing `sugar-forms/src/Fuzzy/FuzzyMatcher.php` for a golden test set ported from sugar-forms' existing tests. The only addition is the `indices` field.
- [ ] `SahilmMatcher` ports the `sahilm/fuzzy` Go algorithm (separator-bonus, camel-case bonus, exact-prefix bonus).
- [ ] Existing usages of `sugar-forms\Fuzzy\FuzzyMatcher` still work (back-compat shim) until consumers migrate in steps 15/16/19.
- [ ] **Depends on**: nothing else in `sugarcraft/*`. Leaf package.
- [ ] ≥95 % coverage.
- [ ] All existing sugar-forms / sugar-treelist / sugar-forms tests still pass.
- [ ] `git status` clean on master.

## Coder brief

1. **Invoke `scaffold-library`** with slug `sugar-fuzzy`, namespace `SugarCraft\Fuzzy\`, role "Fuzzy matching with scored matched indices (extracted from sugar-forms)".
2. **Copy `sugar-forms/src/Fuzzy/FuzzyMatcher.php`** to `sugar-fuzzy/src/Matcher/SmithWatermanMatcher.php`, rename class, rewrite namespace.
3. **Add the matched-indices output**: walk the Smith-Waterman traceback matrix to extract indices of matched characters. The existing code computes the score but discards the traceback — preserve it.
4. **Spawn a Researcher** with: "What's the sahilm/fuzzy scoring algorithm? Specifically: char-class bonus (consecutive same-class), separator bonus (`/`, `_`, `-`, ` `, `.`), camel-case bonus, exact-prefix bonus. Include the integer weights from the upstream source."
5. **Implement SahilmMatcher** per Researcher findings.
6. **Highlighter** helper: takes a MatchResult + a styler `callable(string $matched): string` and returns the candidate with matched runs styled. Pure presentation — no terminal coupling.
7. **Back-compat shim**: in `sugar-forms/src/Fuzzy/FuzzyMatcher.php`, keep the class but mark deprecated; internally delegate to `\SugarCraft\Fuzzy\Matcher\SmithWatermanMatcher`. Same for `sugar-treelist/src/FuzzyMatch.php`. **Add `path-repo-closure`** for sugar-forms + sugar-treelist to require sugar-fuzzy.
8. Run phpunit in sugar-fuzzy + sugar-forms + sugar-treelist + sugar-forms + check-path-repos.

## Tester brief

- Golden parity tests: every test fixture currently in `sugar-forms/tests/Fuzzy/FuzzyMatcherTest.php` ported as-is — Smith-Waterman output must match byte-for-byte (except the new indices field).
- Matched indices: query `"foo"` against `"foobar"` → indices `[0,1,2]`; query `"oba"` against `"foobar"` → indices `[1,3,4]`; UTF-8: query `"中"` against `"中文"` → indices `[0]` (character index, not byte).
- SahilmMatcher: known fixtures from the upstream Go test suite (research provides these).
- matchAll ranking: stable sort, tiebreak rules (score desc then candidate asc).
- Empty query → no matches (not "everything matches").
- Highlighter: applies styler to runs, leaves non-matched runs untouched.

## Scribe brief

- README: code example showing query + indices + highlighter pipeline; mention back-compat shims in sugar-forms/sugar-treelist.
- CALIBER_LEARNINGS: "matched-indices is the value-add over the existing impl. Don't drop the traceback walk."
- MATCHUPS: row citing `sahilm/fuzzy` (Go) + sugar-forms (internal) as inputs. 🟢 — improvement over both upstreams.
- PROJECT_NAMES Candy table.
- Docs/index.html tile + docs/lib/sugar-fuzzy.html.
- Update `sugar-forms/README.md` + `sugar-treelist/README.md` with one-line "Fuzzy matching moved to sugar-fuzzy in 1.x; current API delegates" note.

## Ship brief

- **PR title**: `sugar-fuzzy: extract FuzzyMatcher + add scored matched indices`
- **PR body**:
  ```
  ## Summary
  - New foundation lib sugar-fuzzy extracts the canonical Smith-Waterman matcher from sugar-forms.
  - MatchResult now includes scored matched indices — unblocks filter-highlighting UI across the ecosystem.
  - Adds SahilmMatcher (gum-style scoring) as a second algorithm.
  - sugar-forms + sugar-treelist keep their existing API as deprecated shims delegating to sugar-fuzzy.

  ## Test plan
  - [x] vendor/bin/phpunit in sugar-fuzzy (≥95% coverage)
  - [x] Golden parity vs sugar-forms FuzzyMatcher (all existing fixtures preserved)
  - [x] vendor/bin/phpunit in sugar-forms / sugar-treelist / sugar-forms — all green (shims working)
  - [x] php tools/check-path-repos.php

  Refs: docs/repo_map_step_07.md, docs/repo_map_update.md §345.3, §387.1
  ```
- Commit subject: `sugar-fuzzy: scored fuzzy matching with matched indices`.
