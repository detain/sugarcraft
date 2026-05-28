# Step 22 — Mouse hit-test consumers onto candy-mouse

**Branch:** `ai/mouse-consumers`
**Depends on:** step-05 (candy-mouse)
**Blocks:** —

## Goal

Migrate `sugar-veil`, `sugar-crumbs`, and `candy-lister` from external candy-zone `Manager` wiring to self-contained candy-mouse `Scanner` per consumer.

Reference: §387.4 (mouse-tracking reinvention), §369.4 (consolidation).

## Files expected to be modified

- `sugar-veil/composer.json` · `sugar-crumbs/composer.json` · `candy-lister/composer.json` — add `sugarcraft/candy-mouse` via `path-repo-closure`. Keep the existing `sugarcraft/candy-zone` dep — candy-zone's other features (Manager for *other* purposes) may still be needed; only the mouse-tracking surface migrates.
- `sugar-veil/src/Veil.php` — replace `Manager $manager` field with own `Scanner`. Update `withManager()` accordingly (keep the method, mark @deprecated, delegate internally).
- `sugar-crumbs/src/Breadcrumb.php` — same pattern as sugar-veil with `zoneManager` field.
- `candy-lister/src/` (wherever mouse-tracking happens, if at all) — same.

## Acceptance criteria

- [ ] Each of the 3 libs has internal Scanner; no external Manager wiring required.
- [ ] Existing `withManager()` / `withZoneManager()` methods preserved as deprecated wrappers (back-compat).
- [ ] All existing mouse-handling tests in the 3 libs pass.
- [ ] ≥95 % coverage maintained in each.
- [ ] `git status` clean on master.

## Coder brief

1. **path-repo closure**: candy-mouse in all 3.
2. **Per lib**: introduce a private `Scanner` field; on render, do `Scanner::scan($renderedString)`; on mouse event, do `Scanner::hit($x, $y)` to look up the zone.
3. **Back-compat**: keep `withManager(Manager)` etc. methods; mark `@deprecated`; internally bridge to Scanner (or simply ignore — Manager's role is replaced).
4. Run phpunit in 3 libs + dependents.

## Tester brief

- For each lib: simulate a click at coords inside / outside known zones; assert the right zone is reported.
- Back-compat: call `withManager(new Manager())` — should not throw, lib continues to work using the internal Scanner.

## Scribe brief

- Each lib's README: `## Shared foundations` mentioning candy-mouse, with a note that external Manager wiring is no longer needed.
- Each lib's CALIBER_LEARNINGS: "Mouse hit-testing self-contained via candy-mouse. Don't pass Managers around for new code."

## Ship brief

- **PR title**: `sugar-veil + sugar-crumbs + candy-lister: self-contained mouse via candy-mouse`
- **PR body**:
  ```
  ## Summary
  - Three libs migrate from candy-zone external Manager wiring to candy-mouse self-contained Scanner.
  - Each lib owns its Scanner; mouse hit-testing is local.
  - withManager() / withZoneManager() kept as deprecated back-compat wrappers.
  - No external candy-zone Manager required for mouse-only use cases.

  ## Test plan
  - [x] vendor/bin/phpunit in sugar-veil / sugar-crumbs / candy-lister (≥95% each)
  - [x] Back-compat: withManager() still callable
  - [x] php tools/check-path-repos.php

  Refs: docs/repo_map_step_22.md, docs/repo_map_update.md §369.4, §387.4
  ```
- Commit subject: `sugar-veil + sugar-crumbs + candy-lister: adopt candy-mouse`.
