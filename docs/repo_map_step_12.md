# Step 12 — candy-vt delegates to candy-ansi

**Branch:** `ai/candy-vt-uses-ansi`
**Depends on:** step-01 (candy-ansi exists with the parser extracted)
**Blocks:** step-20 (sugar-spark/candy-hermit/candy-freeze migrations expect candy-ansi to be the canonical home; once candy-vt aliases to it, no two-truth situation)

## Goal

Migrate `candy-vt` to consume `candy-ansi`'s Parser instead of its own copy. Keep public namespace aliases (`SugarCraft\Vt\Parser\*` → `SugarCraft\Ansi\Parser\*`) for back-compat so any external user importing `SugarCraft\Vt\Parser\Parser` continues to work.

Reference: `docs/repo_map_update.md` §369.2 (consolidation: extract from candy-vt).

## Files expected to be modified

- `candy-vt/composer.json` — add `sugarcraft/candy-ansi: @dev` + path-repo via `path-repo-closure`.
- `candy-vt/src/Parser/Parser.php` — delete file body, replace with `class_alias(\SugarCraft\Ansi\Parser\Parser::class, \SugarCraft\Vt\Parser\Parser::class);` OR keep the file as a thin subclass / re-export shim. **Preferred**: PHP `class_alias` in a `_aliases.php` file autoloaded via composer.json `files`.
- Same for every other file in `candy-vt/src/Parser/` — alias to candy-ansi equivalent.
- `candy-vt/src/Parser/_aliases.php` — central alias file loaded via composer.json `"autoload": { "files": ["src/Parser/_aliases.php"] }`.
- All existing `candy-vt/tests/` tests must pass unchanged.

## Files expected to be created

- `candy-vt/src/Parser/_aliases.php` — `class_alias` declarations.

## Files expected to be deleted

- `candy-vt/src/Parser/*.php` (everything except `_aliases.php`) — replaced by aliases to candy-ansi.

## Acceptance criteria

- [ ] `vendor/bin/phpunit` in candy-vt passes with **every** existing test unchanged (no test file edits needed).
- [ ] `\SugarCraft\Vt\Parser\Parser` resolves to `\SugarCraft\Ansi\Parser\Parser` (assertable via `is_a` / `class_alias` introspection).
- [ ] Any downstream consumer importing `SugarCraft\Vt\Parser\*` still works.
- [ ] No two copies of the same class in memory after autoload.
- [ ] `php scripts/affected-libs.php` lists candy-vt's dependents; run phpunit in each — all green.
- [ ] ≥95 % coverage maintained.
- [ ] `git status` clean on master.

## Coder brief

1. **Use `path-repo-closure`** to add `sugarcraft/candy-ansi: @dev` to candy-vt's composer.json (and any transitive consumer).
2. **Create `candy-vt/src/Parser/_aliases.php`** with one `class_alias` per file in `candy-ansi/src/Parser/`:
   ```php
   <?php
   declare(strict_types=1);
   class_alias(\SugarCraft\Ansi\Parser\Parser::class,        \SugarCraft\Vt\Parser\Parser::class);
   class_alias(\SugarCraft\Ansi\Parser\State::class,         \SugarCraft\Vt\Parser\State::class);
   class_alias(\SugarCraft\Ansi\Parser\Transitions::class,   \SugarCraft\Vt\Parser\Transitions::class);
   // ... every public Parser class
   ```
3. **Update `candy-vt/composer.json`** autoload section: `"autoload": { "psr-4": {...}, "files": ["src/Parser/_aliases.php"] }`.
4. **Delete the duplicate Parser files** from `candy-vt/src/Parser/` (keep only `_aliases.php`). `git rm` each file.
5. **Run** `cd candy-vt && composer dumpautoload && vendor/bin/phpunit`. Every test should pass; if a test directly imports `\SugarCraft\Vt\Parser\` namespace, the alias should resolve.
6. **Run `php scripts/affected-libs.php`** + iterate over its output running phpunit in each affected lib. None should break.
7. Run check-path-repos.

## Tester brief

- Run existing candy-vt suite — should pass unchanged.
- Add ONE new test asserting alias correctness:
  ```php
  $this->assertSame(\SugarCraft\Ansi\Parser\Parser::class, (new \ReflectionClass(\SugarCraft\Vt\Parser\Parser::class))->getName());
  ```
- Coverage check: candy-vt's overall coverage may DROP because the deleted Parser files no longer count toward candy-vt's metric (they count toward candy-ansi). This is correct. Document the delta in CALIBER_LEARNINGS.
- For every candy-vt dependent identified by `affected-libs.php`, confirm tests green.

## Scribe brief

- `candy-vt/README.md`: add "ANSI parsing now in candy-ansi" note with link.
- `candy-vt/CALIBER_LEARNINGS.md`: "Parser classes aliased to candy-ansi via class_alias in _aliases.php loaded by composer files autoload. Don't delete _aliases.php — downstream consumers may still import the old namespace."
- MATCHUPS: candy-vt row updated to reflect parser shared with candy-ansi.

## Ship brief

- **PR title**: `candy-vt: delegate parsing to candy-ansi via class_alias`
- **PR body**:
  ```
  ## Summary
  - candy-vt's Parser classes now alias to candy-ansi's (no duplicate impl).
  - _aliases.php declarations loaded via composer files autoload preserve back-compat: `SugarCraft\Vt\Parser\*` still resolves.
  - No downstream consumer needs changes.
  - Deletes ~7 duplicate files (Parser, State, Transitions, CsiHandler*, OscHandler*, Action, Handler, etc.).

  ## Test plan
  - [x] vendor/bin/phpunit in candy-vt (existing suite passes)
  - [x] vendor/bin/phpunit in every candy-vt dependent (affected-libs.php — all green)
  - [x] Alias resolution test added
  - [x] php tools/check-path-repos.php

  Refs: docs/repo_map_step_12.md, docs/repo_map_update.md §369.2
  ```
- Commit subject: `candy-vt: alias Parser to candy-ansi`.
