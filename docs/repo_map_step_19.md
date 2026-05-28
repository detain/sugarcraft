# Step 19 — candy-shell onto candy-fuzzy (+ SubStyleParser fix + CommandScanner autoload fix)

**Branch:** `ai/candy-shell-shared`
**Depends on:** step-07 (candy-fuzzy)
**Blocks:** —

## Goal

Replace candy-shell's `SubStyleParser` fuzzy bits with candy-fuzzy. While here, fix the `CommandScanner` autoloading order bug cited in `docs/repo_map_update.md` (use `spl_autoload_functions()` before `get_declared_classes()`).

Reference: §387.1 (fuzzy reinvention), §103.2 (CommandScanner autoload fix — quick win), §260.2 (immediate-wins list).

## Files expected to be modified

- `candy-shell/composer.json` — add `sugarcraft/candy-fuzzy` via `path-repo-closure`.
- `candy-shell/src/Style/SubStyleParser.php` — its fuzzy-matching responsibility (if it has any) moves to candy-fuzzy; styling-parser responsibility stays.
- `candy-shell/src/CommandScanner.php` (or wherever the scanner lives) — fix autoload order: call `spl_autoload_functions()` first, register listener, THEN `get_declared_classes()` for the already-loaded set.

## Acceptance criteria

- [ ] candy-shell Filter component uses candy-fuzzy directly (scored matches, highlight indices).
- [ ] CommandScanner discovers commands that are autoloaded LATER (regression test: a command class that doesn't exist at scanner registration but is autoloaded on later usage is still discoverable).
- [ ] Existing candy-shell tests pass.
- [ ] ≥95 % coverage maintained.
- [ ] `git status` clean on master.

## Coder brief

1. **Delegate to an Explore subagent**: "In `candy-shell/src/`, find SubStyleParser.php (cited as containing fuzzy bits) and CommandScanner.php (cited autoload bug). Report current responsibilities and any tests covering them."
2. **path-repo closure**: candy-fuzzy.
3. **SubStyleParser**: if it does fuzzy scoring, extract that to a call into candy-fuzzy. Style-parsing logic stays in SubStyleParser.
4. **CommandScanner fix**: order should be:
   ```php
   spl_autoload_register(function ($class) { /* track */ }, true, true); // prepend listener
   $known = get_declared_classes();
   // scanner uses both $known + the listener's accumulating list
   ```
5. **Add regression test**: scan, then trigger autoload of a new class, then re-query — it should appear.
6. Run phpunit + check-path-repos.

## Tester brief

- candy-shell Filter using candy-fuzzy: query "ab" returns ranked matches with indices.
- CommandScanner: register, autoload-trigger a new command class, scanner sees it.
- SubStyleParser: existing style-parsing tests still pass.

## Scribe brief

- `candy-shell/README.md`: `## Shared foundations` mentioning candy-fuzzy.
- `candy-shell/CALIBER_LEARNINGS.md`: "CommandScanner: register autoload listener BEFORE calling get_declared_classes — otherwise late-loaded classes are invisible."

## Ship brief

- **PR title**: `candy-shell: adopt candy-fuzzy + fix CommandScanner autoload order`
- **PR body**:
  ```
  ## Summary
  - candy-shell Filter delegates fuzzy matching to candy-fuzzy (scored, with highlight indices).
  - CommandScanner autoload order fixed: spl_autoload_functions() registered before get_declared_classes() snapshot.
  - SubStyleParser keeps its style-parsing responsibility; fuzzy logic moved out.

  ## Test plan
  - [x] vendor/bin/phpunit in candy-shell (≥95% coverage)
  - [x] Regression test for autoload-later command discovery
  - [x] php tools/check-path-repos.php

  Refs: docs/repo_map_step_19.md, docs/repo_map_update.md §387.1, §103.2, §260.2
  ```
- Commit subject: `candy-shell: adopt candy-fuzzy + fix CommandScanner autoload`.
