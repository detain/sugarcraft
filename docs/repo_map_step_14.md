# Step 14 — sugar-widgets onto shared foundations

**Branch:** `ai/sugar-widgets-shared`
**Depends on:** step-02 (sugar-cellbuffer), step-03 (candy-layout), step-05 (sugar-mouse), step-07 (sugar-fuzzy)
**Blocks:** —

## Goal

Migrate `sugar-widgets` (TextInput, TextArea, ItemList, FilePicker, Cursor, Spinner, Table, Tabs primitives) onto sugar-cellbuffer (cell rendering), candy-layout (component sizing), sugar-mouse (self-contained zone tracking for Tabs / Table / FilePicker), and sugar-fuzzy (ItemList filtering uses str_contains today — upgrade to scored fuzzy with highlight indices).

Reference: `docs/repo_map_update.md` Phase 3 migration; §387.1 (fuzzy reinvention), §387.5 (layout reinvention).

## Files expected to be modified

- `sugar-widgets/composer.json` — add `sugarcraft/sugar-cellbuffer`, `sugarcraft/candy-layout`, `sugarcraft/sugar-mouse`, `sugarcraft/sugar-fuzzy` (all via `path-repo-closure`).
- `sugar-widgets/src/Table/Table.php` — render through sugar-cellbuffer; layout columns via candy-layout.
- `sugar-widgets/src/Tabs/Tabs.php` — replace any direct sugar-mouse Manager wiring with sugar-mouse Scanner.
- `sugar-widgets/src/ItemList/ItemList.php` — swap `str_contains` filter for `\SugarCraft\Fuzzy\Matcher\SmithWatermanMatcher`; expose matched indices for highlight rendering.
- `sugar-widgets/src/FilePicker/FilePicker.php` — sugar-mouse for clickable rows; sugar-fuzzy for filename filter.
- Renderers across primitives: emit through sugar-cellbuffer where possible, fall back to string composition only if migration is non-trivial (note any deferrals in `docs/repo_map_updates.md`).

## Acceptance criteria

- [ ] All existing `sugar-widgets/tests/` continue passing (UI primitives are heavily tested).
- [ ] ItemList filter shows highlighted match indices in its rendered output (verify with a snapshot test).
- [ ] Tabs / Table / FilePicker mouse hit-testing works via sugar-mouse Scanner — no external sugar-mouse Manager needed.
- [ ] Table column layout uses candy-layout when given mixed Min/Fill/Fixed column specs.
- [ ] No regression on any primitive's `view()` byte output for existing fixtures.
- [ ] ≥95 % coverage maintained.
- [ ] `git status` clean on master.

## Coder brief

1. **Path-repo closure**: add the 4 new requires to sugar-widgets' composer.json.
2. **Migrate ONE primitive at a time** (commit per primitive in this branch is fine; final PR squashes via merge):
   - Table → sugar-cellbuffer + candy-layout
   - Tabs → sugar-mouse
   - ItemList → sugar-fuzzy (mandatory) + sugar-cellbuffer (if low-risk)
   - FilePicker → sugar-mouse + sugar-fuzzy
3. **For each primitive**: read existing `view()` output, generate the same bytes via sugar-cellbuffer's render path, assert byte-identical (snapshot test) before/after.
4. **If ItemList currently does NOT highlight matched chars** (it doesn't — that's the whole point of sugar-fuzzy), add the highlight render path AND add a new snapshot showing it.
5. Run phpunit in sugar-widgets + every dependent lib via `affected-libs.php`.

## Tester brief

- For each migrated primitive: byte-snapshot test of `view()` for the existing baseline behaviour, plus a new test for any added capability (e.g., ItemList match highlight).
- Mouse: simulate a click event at a known coord, assert the right zone is reported.
- Layout: build a Table with constraints `[Min(5), Fill, Fixed(8)]` at width 30, assert column widths.

## Scribe brief

- `sugar-widgets/README.md`: a new `## Shared foundations` section listing the 4 new requires and the user-visible benefit per primitive.
- `sugar-widgets/CALIBER_LEARNINGS.md`: any per-primitive gotchas discovered (e.g., "FilePicker zones must use Scanner per-page, not global, because pagination changes positions").

## Ship brief

- **PR title**: `sugar-widgets: adopt sugar-cellbuffer + candy-layout + sugar-mouse + sugar-fuzzy`
- **PR body**:
  ```
  ## Summary
  - sugar-widgets primitives migrated onto shared foundation packages.
  - ItemList now shows match highlights via sugar-fuzzy scored indices (new UX).
  - Tabs / Table / FilePicker use sugar-mouse self-contained scanners.
  - Table column layout via candy-layout's constraint solver.
  - Byte-snapshot tests confirm no regression on existing primitives.

  ## Test plan
  - [x] vendor/bin/phpunit in sugar-widgets (existing suite green, new highlight snapshots)
  - [x] vendor/bin/phpunit in dependents (affected-libs.php)
  - [x] php tools/check-path-repos.php

  Refs: docs/repo_map_step_14.md, docs/repo_map_update.md §387.1, §387.3, §387.5
  ```
- Commit subject: `sugar-widgets: adopt shared foundation packages`.
