# Step 22 — Mouse hit-test consumers onto sugar-mouse

**Branch:** `ai/mouse-consumers`
**Depends on:** step-05 (sugar-mouse)
**Blocks:** —

## Goal

Migrate `sugar-overlay`, `sugar-navstack`, and `sugar-treelist` from external sugar-mouse `Manager` wiring to self-contained sugar-mouse `Scanner` per consumer.

Reference: §387.4 (mouse-tracking reinvention), §369.4 (consolidation).

## Files expected to be modified

- `sugar-overlay/composer.json` · `sugar-navstack/composer.json` · `sugar-treelist/composer.json` — add `sugarcraft/sugar-mouse` via `path-repo-closure`. Keep the existing `sugarcraft/sugar-mouse` dep — sugar-mouse's other features (Manager for *other* purposes) may still be needed; only the mouse-tracking surface migrates.
- `sugar-overlay/src/Veil.php` — replace `Manager $manager` field with own `Scanner`. Update `withManager()` accordingly (keep the method, mark @deprecated, delegate internally).
- `sugar-navstack/src/Breadcrumb.php` — same pattern as sugar-overlay with `zoneManager` field.
- `sugar-treelist/src/` (wherever mouse-tracking happens, if at all) — same.

## Acceptance criteria

- [ ] Each of the 3 libs has internal Scanner; no external Manager wiring required.
- [ ] Existing `withManager()` / `withZoneManager()` methods preserved as deprecated wrappers (back-compat).
- [ ] All existing mouse-handling tests in the 3 libs pass.
- [ ] ≥95 % coverage maintained in each.
- [ ] `git status` clean on master.

## Coder brief

1. **path-repo closure**: sugar-mouse in all 3.
2. **Per lib**: introduce a private `Scanner` field; on render, do `Scanner::scan($renderedString)`; on mouse event, do `Scanner::hit($x, $y)` to look up the zone.
3. **Back-compat**: keep `withManager(Manager)` etc. methods; mark `@deprecated`; internally bridge to Scanner (or simply ignore — Manager's role is replaced).
4. Run phpunit in 3 libs + dependents.

## Tester brief

- For each lib: simulate a click at coords inside / outside known zones; assert the right zone is reported.
- Back-compat: call `withManager(new Manager())` — should not throw, lib continues to work using the internal Scanner.

## Scribe brief

- Each lib's README: `## Shared foundations` mentioning sugar-mouse, with a note that external Manager wiring is no longer needed.
- Each lib's CALIBER_LEARNINGS: "Mouse hit-testing self-contained via sugar-mouse. Don't pass Managers around for new code."

## Ship brief

- **PR title**: `sugar-overlay + sugar-navstack + sugar-treelist: self-contained mouse via sugar-mouse`
- **PR body**:
  ```
  ## Summary
  - Three libs migrate from sugar-mouse external Manager wiring to sugar-mouse self-contained Scanner.
  - Each lib owns its Scanner; mouse hit-testing is local.
  - withManager() / withZoneManager() kept as deprecated back-compat wrappers.
  - No external sugar-mouse Manager required for mouse-only use cases.

  ## Test plan
  - [x] vendor/bin/phpunit in sugar-overlay / sugar-navstack / sugar-treelist (≥95% each)
  - [x] Back-compat: withManager() still callable
  - [x] php tools/check-path-repos.php

  Refs: docs/repo_map_step_22.md, docs/repo_map_update.md §369.4, §387.4
  ```
- Commit subject: `sugar-overlay + sugar-navstack + sugar-treelist: adopt sugar-mouse`.
