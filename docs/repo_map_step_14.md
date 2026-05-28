# Step 14 — sugar-bits onto shared foundations

**Branch:** `ai/sugar-bits-shared`
**Depends on:** step-02 (candy-buffer), step-03 (candy-layout), step-05 (candy-mouse), step-07 (candy-fuzzy)
**Blocks:** —

## Goal

Migrate `sugar-bits` (TextInput, TextArea, ItemList, FilePicker, Cursor, Spinner, Table, Tabs primitives) onto candy-buffer (cell rendering), candy-layout (component sizing), candy-mouse (self-contained zone tracking for Tabs / Table / FilePicker), and candy-fuzzy (ItemList filtering uses str_contains today — upgrade to scored fuzzy with highlight indices).

Reference: `docs/repo_map_update.md` Phase 3 migration; §387.1 (fuzzy reinvention), §387.5 (layout reinvention).

## Files expected to be modified

- `sugar-bits/composer.json` — add `sugarcraft/candy-buffer`, `sugarcraft/candy-layout`, `sugarcraft/candy-mouse`, `sugarcraft/candy-fuzzy` (all via `path-repo-closure`).
- `sugar-bits/src/Table/Table.php` — render through candy-buffer; layout columns via candy-layout.
- `sugar-bits/src/Tabs/Tabs.php` — replace any direct candy-zone Manager wiring with candy-mouse Scanner.
- `sugar-bits/src/ItemList/ItemList.php` — swap `str_contains` filter for `\SugarCraft\Fuzzy\Matcher\SmithWatermanMatcher`; expose matched indices for highlight rendering.
- `sugar-bits/src/FilePicker/FilePicker.php` — candy-mouse for clickable rows; candy-fuzzy for filename filter.
- Renderers across primitives: emit through candy-buffer where possible, fall back to string composition only if migration is non-trivial (note any deferrals in `docs/repo_map_updates.md`).

## Acceptance criteria

- [ ] All existing `sugar-bits/tests/` continue passing (UI primitives are heavily tested).
- [ ] ItemList filter shows highlighted match indices in its rendered output (verify with a snapshot test).
- [ ] Tabs / Table / FilePicker mouse hit-testing works via candy-mouse Scanner — no external candy-zone Manager needed.
- [ ] Table column layout uses candy-layout when given mixed Min/Fill/Fixed column specs.
- [ ] No regression on any primitive's `view()` byte output for existing fixtures.
- [ ] ≥95 % coverage maintained.
- [ ] `git status` clean on master.

## Coder brief

1. **Path-repo closure**: add the 4 new requires to sugar-bits' composer.json.
2. **Migrate ONE primitive at a time** (commit per primitive in this branch is fine; final PR squashes via merge):
   - Table → candy-buffer + candy-layout
   - Tabs → candy-mouse
   - ItemList → candy-fuzzy (mandatory) + candy-buffer (if low-risk)
   - FilePicker → candy-mouse + candy-fuzzy
3. **For each primitive**: read existing `view()` output, generate the same bytes via candy-buffer's render path, assert byte-identical (snapshot test) before/after.
4. **If ItemList currently does NOT highlight matched chars** (it doesn't — that's the whole point of candy-fuzzy), add the highlight render path AND add a new snapshot showing it.
5. Run phpunit in sugar-bits + every dependent lib via `affected-libs.php`.

## Tester brief

- For each migrated primitive: byte-snapshot test of `view()` for the existing baseline behaviour, plus a new test for any added capability (e.g., ItemList match highlight).
- Mouse: simulate a click event at a known coord, assert the right zone is reported.
- Layout: build a Table with constraints `[Min(5), Fill, Fixed(8)]` at width 30, assert column widths.

## Scribe brief

- `sugar-bits/README.md`: a new `## Shared foundations` section listing the 4 new requires and the user-visible benefit per primitive.
- `sugar-bits/CALIBER_LEARNINGS.md`: any per-primitive gotchas discovered (e.g., "FilePicker zones must use Scanner per-page, not global, because pagination changes positions").

## Ship brief

- **PR title**: `sugar-bits: adopt candy-buffer + candy-layout + candy-mouse + candy-fuzzy`
- **PR body**:
  ```
  ## Summary
  - sugar-bits primitives migrated onto shared foundation packages.
  - ItemList now shows match highlights via candy-fuzzy scored indices (new UX).
  - Tabs / Table / FilePicker use candy-mouse self-contained scanners.
  - Table column layout via candy-layout's constraint solver.
  - Byte-snapshot tests confirm no regression on existing primitives.

  ## Test plan
  - [x] vendor/bin/phpunit in sugar-bits (existing suite green, new highlight snapshots)
  - [x] vendor/bin/phpunit in dependents (affected-libs.php)
  - [x] php tools/check-path-repos.php

  Refs: docs/repo_map_step_14.md, docs/repo_map_update.md §387.1, §387.3, §387.5
  ```
- Commit subject: `sugar-bits: adopt shared foundation packages`.
