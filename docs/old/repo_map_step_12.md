# Step 12 — sugar-vterm delegates to sugar-ansi-parser

**Branch:** `ai/sugar-vterm-uses-ansi`
**Depends on:** step-01 (sugar-ansi-parser exists with the parser extracted)
**Blocks:** step-20 (candy-ansi-inspect/sugar-quickpick/candy-codeshot migrations expect sugar-ansi-parser to be the canonical home; once sugar-vterm aliases to it, no two-truth situation)

## Goal

Migrate `sugar-vterm` to consume `sugar-ansi-parser`'s Parser instead of its own copy. Keep public namespace aliases (`SugarCraft\Vterm\Parser\*` → `SugarCraft\AnsiParser\Parser\*`) for back-compat so any external user importing `SugarCraft\Vterm\Parser\Parser` continues to work.

Reference: `docs/repo_map_update.md` §369.2 (consolidation: extract from sugar-vterm).

## Files expected to be modified

- `sugar-vterm/composer.json` — add `sugarcraft/sugar-ansi-parser: @dev` + path-repo via `path-repo-closure`.
- `sugar-vterm/src/Parser/Parser.php` — delete file body, replace with `class_alias(\SugarCraft\AnsiParser\Parser\Parser::class, \SugarCraft\Vterm\Parser\Parser::class);` OR keep the file as a thin subclass / re-export shim. **Preferred**: PHP `class_alias` in a `_aliases.php` file autoloaded via composer.json `files`.
- Same for every other file in `sugar-vterm/src/Parser/` — alias to sugar-ansi-parser equivalent.
- `sugar-vterm/src/Parser/_aliases.php` — central alias file loaded via composer.json `"autoload": { "files": ["src/Parser/_aliases.php"] }`.
- All existing `sugar-vterm/tests/` tests must pass unchanged.

## Files expected to be created

- `sugar-vterm/src/Parser/_aliases.php` — `class_alias` declarations.

## Files expected to be deleted

- `sugar-vterm/src/Parser/*.php` (everything except `_aliases.php`) — replaced by aliases to sugar-ansi-parser.

## Acceptance criteria

- [ ] `vendor/bin/phpunit` in sugar-vterm passes with **every** existing test unchanged (no test file edits needed).
- [ ] `\SugarCraft\Vterm\Parser\Parser` resolves to `\SugarCraft\AnsiParser\Parser\Parser` (assertable via `is_a` / `class_alias` introspection).
- [ ] Any downstream consumer importing `SugarCraft\Vterm\Parser\*` still works.
- [ ] No two copies of the same class in memory after autoload.
- [ ] `php scripts/affected-libs.php` lists sugar-vterm's dependents; run phpunit in each — all green.
- [ ] ≥95 % coverage maintained.
- [ ] `git status` clean on master.

## Coder brief

1. **Use `path-repo-closure`** to add `sugarcraft/sugar-ansi-parser: @dev` to sugar-vterm's composer.json (and any transitive consumer).
2. **Create `sugar-vterm/src/Parser/_aliases.php`** with one `class_alias` per file in `sugar-ansi-parser/src/Parser/`:
   ```php
   <?php
   declare(strict_types=1);
   class_alias(\SugarCraft\AnsiParser\Parser\Parser::class,        \SugarCraft\Vterm\Parser\Parser::class);
   class_alias(\SugarCraft\AnsiParser\Parser\State::class,         \SugarCraft\Vterm\Parser\State::class);
   class_alias(\SugarCraft\AnsiParser\Parser\Transitions::class,   \SugarCraft\Vterm\Parser\Transitions::class);
   // ... every public Parser class
   ```
3. **Update `sugar-vterm/composer.json`** autoload section: `"autoload": { "psr-4": {...}, "files": ["src/Parser/_aliases.php"] }`.
4. **Delete the duplicate Parser files** from `sugar-vterm/src/Parser/` (keep only `_aliases.php`). `git rm` each file.
5. **Run** `cd sugar-vterm && composer dumpautoload && vendor/bin/phpunit`. Every test should pass; if a test directly imports `\SugarCraft\Vterm\Parser\` namespace, the alias should resolve.
6. **Run `php scripts/affected-libs.php`** + iterate over its output running phpunit in each affected lib. None should break.
7. Run check-path-repos.

## Tester brief

- Run existing sugar-vterm suite — should pass unchanged.
- Add ONE new test asserting alias correctness:
  ```php
  $this->assertSame(\SugarCraft\AnsiParser\Parser\Parser::class, (new \ReflectionClass(\SugarCraft\Vterm\Parser\Parser::class))->getName());
  ```
- Coverage check: sugar-vterm's overall coverage may DROP because the deleted Parser files no longer count toward sugar-vterm's metric (they count toward sugar-ansi-parser). This is correct. Document the delta in CALIBER_LEARNINGS.
- For every sugar-vterm dependent identified by `affected-libs.php`, confirm tests green.

## Scribe brief

- `sugar-vterm/README.md`: add "ANSI parsing now in sugar-ansi-parser" note with link.
- `sugar-vterm/CALIBER_LEARNINGS.md`: "Parser classes aliased to sugar-ansi-parser via class_alias in _aliases.php loaded by composer files autoload. Don't delete _aliases.php — downstream consumers may still import the old namespace."
- MATCHUPS: sugar-vterm row updated to reflect parser shared with sugar-ansi-parser.

## Ship brief

- **PR title**: `sugar-vterm: delegate parsing to sugar-ansi-parser via class_alias`
- **PR body**:
  ```
  ## Summary
  - sugar-vterm's Parser classes now alias to sugar-ansi-parser's (no duplicate impl).
  - _aliases.php declarations loaded via composer files autoload preserve back-compat: `SugarCraft\Vterm\Parser\*` still resolves.
  - No downstream consumer needs changes.
  - Deletes ~7 duplicate files (Parser, State, Transitions, CsiHandler*, OscHandler*, Action, Handler, etc.).

  ## Test plan
  - [x] vendor/bin/phpunit in sugar-vterm (existing suite passes)
  - [x] vendor/bin/phpunit in every sugar-vterm dependent (affected-libs.php — all green)
  - [x] Alias resolution test added
  - [x] php tools/check-path-repos.php

  Refs: docs/repo_map_step_12.md, docs/repo_map_update.md §369.2
  ```
- Commit subject: `sugar-vterm: alias Parser to sugar-ansi-parser`.
