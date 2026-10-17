# Step 01 — Create sugar-ansi-parser

**Branch:** `ai/sugar-ansi-parser-new`
**Depends on:** step-00 ✅
**Blocks:** step-12 (sugar-vterm → sugar-ansi-parser), step-20 (candy-ansi-inspect/sugar-quickpick/candy-codeshot → sugar-ansi-parser)

## Goal

Create the `sugar-ansi-parser` foundation package — a standalone PSR-4 `SugarCraft\AnsiParser\` port of the ECMA-48 / `charmbracelet/x/ansi` state machine. The implementation is a clean **copy** of `sugar-vterm/src/Parser/*` (already a working port). sugar-vterm continues to ship its own copy this step; the migration of sugar-vterm onto sugar-ansi-parser happens in step-12 — that buys us a safe rollback window.

Reference: `docs/repo_map_update.md` §327.5 (frameworks), §336 (already partially ported in sugar-vterm), §387.2 (reinvention to consolidate).

## Files expected to be created

- `sugar-ansi-parser/composer.json`
- `sugar-ansi-parser/phpunit.xml`
- `sugar-ansi-parser/README.md`
- `sugar-ansi-parser/CALIBER_LEARNINGS.md`
- `sugar-ansi-parser/src/Parser/Parser.php`
- `sugar-ansi-parser/src/Parser/State.php`
- `sugar-ansi-parser/src/Parser/Transitions.php`
- `sugar-ansi-parser/src/Parser/Action.php`
- `sugar-ansi-parser/src/Parser/Handler.php`
- `sugar-ansi-parser/src/Parser/HandlerAdapter.php`
- `sugar-ansi-parser/src/Parser/CsiHandler.php`
- `sugar-ansi-parser/src/Parser/CsiHandlerImpl.php`
- `sugar-ansi-parser/src/Parser/OscHandler.php`
- `sugar-ansi-parser/src/Parser/OscHandlerImpl.php`
- `sugar-ansi-parser/src/Parser/DebugHandler.php`
- `sugar-ansi-parser/tests/ParserTest.php` (inline sanity; comprehensive suite from TestEngineer)

## Files expected to be modified

- repo root `composer.json` — add `"sugarcraft/sugar-ansi-parser": "@dev"` + path-repo entry.
- `docs/MATCHUPS.md` — add row.
- `PROJECT_NAMES.md` — add to Candy* (foundation) table.
- root `README.md` — add to lib-list table.
- `docs/index.html` — add tile.
- `docs/lib/sugar-ansi-parser.html` — create.
- `.github/workflows/vhs.yml` — add to `all=(...)` only if a `.vhs/*.tape` is added (Parser libs are non-visual, so likely NOT — leave out).
- `codecov.yml` — add `flag: sugar-ansi-parser`.

## Acceptance criteria

- [ ] `sugar-ansi-parser/` directory exists with the canonical lib skeleton.
- [ ] `composer.json` declares PSR-4 `SugarCraft\AnsiParser\` → `src/`, PHP `^8.3`, PHPUnit `^10.5`, `minimum-stability: dev`, `prefer-stable: true`, metadata block (keywords include `sugarcraft` + `ansi`, single author Joe Huss, support links).
- [ ] No `require sugarcraft/*` deps yet — sugar-ansi-parser is leaf.
- [ ] All `Parser/*.php` files copied byte-for-byte from `sugar-vterm/src/Parser/` with namespace rewrites `SugarCraft\Vterm\Parser` → `SugarCraft\AnsiParser\Parser` throughout.
- [ ] `vendor/bin/phpunit` in `sugar-ansi-parser/` passes (≥1 sanity test; comprehensive suite is the TestEngineer's job).
- [ ] `vendor/bin/phpunit` in `sugar-vterm/` STILL passes (sugar-vterm unchanged this step).
- [ ] `php tools/check-path-repos.php` passes.
- [ ] ≥95 % coverage on the new sugar-ansi-parser package (TestEngineer phase).
- [ ] `git status` clean, on master, PR merged, branch deleted.

## Coder brief

1. **Invoke the `scaffold-library` skill** with slug `sugar-ansi-parser`, namespace `SugarCraft\AnsiParser\`, one-line role "ECMA-48 / charmbracelet/x/ansi state-machine port (shared)". Let it handle the full root-doc + workflow wiring per AGENTS.md.
2. **Copy the parser files** from `sugar-vterm/src/Parser/` into `sugar-ansi-parser/src/Parser/` using `cp` (preserve semantics; do NOT use `mv` — sugar-vterm keeps its copy this step):
   ```bash
   cp sugar-vterm/src/Parser/*.php sugar-ansi-parser/src/Parser/
   ```
3. **Rewrite namespaces** in each copied file: `SugarCraft\Vterm\Parser` → `SugarCraft\AnsiParser\Parser`, and any `use SugarCraft\Vterm\…` that the parser doesn't actually need. Use `sed -i 's|SugarCraft\\\\Vterm\\\\Parser|SugarCraft\\\\Ansi\\\\Parser|g'` then audit. <!-- @rename-keep -->
4. **If any parser file imports outside of `Parser/`** (e.g., a `SugarCraft\Vterm\Cell` or `SugarCraft\Vterm\Terminal`), refactor the import so sugar-ansi-parser is self-contained. The state machine itself emits abstract Actions; cell/terminal handling lives in the *consumer*. If you discover a coupling that can't be cleanly cut, append `BLOCKING: sugar-ansi-parser cannot extract without sugar-vterm coupling: <details>` to `docs/repo_map_updates.md`.
5. **Add a class-level docblock** to `Parser.php`: `@see Mirrors charmbracelet/x/ansi Parser (ECMA-48 state machine)`.
6. **Write one sanity test** in `sugar-ansi-parser/tests/ParserTest.php`:
   - Feed `"hello\x1b[31mworld\x1b[0m"` through `Parser::feed()`.
   - Assert at least one `CsiHandler::sgr([31])` invocation and one `[0]` reset.
7. **Run** `cd sugar-ansi-parser && composer install --quiet && vendor/bin/phpunit`.
8. **Run** `cd sugar-vterm && composer install --quiet && vendor/bin/phpunit` — must still pass (we haven't touched sugar-vterm).
9. **Run** `php tools/check-path-repos.php` from repo root.

If you need clarification on which Parser files belong in the state machine vs the cell handler, spawn a Researcher with the question "list the responsibilities of each file under sugar-vterm/src/Parser/ and identify any that touch sugar-vterm's Cell/Terminal classes".

## Tester brief

sugar-ansi-parser's public API surface = `Parser::feed(string $bytes)`, `Parser::reset()`, the `Handler` interface, `CsiHandler` + `OscHandler` interfaces. Target ≥95 % coverage.

- **State machine coverage**: drive `Parser::feed()` with byte strings exercising every state transition in `State.php`. Use a `data provider` over the ECMA-48 state table.
- **CSI dispatch**: every CSI sequence the handler interface declares (CUU/CUD/CUF/CUB cursor moves, SGR, ED erase display, EL erase line, etc.) — one test asserting the right method is called with right params.
- **OSC dispatch**: hyperlink (OSC 8), title (OSC 0/1/2), color query (OSC 4/10/11/12).
- **Edge cases**: malformed sequences (premature ST, runaway CSI), UTF-8 continuation bytes interleaved with escapes, BEL vs ST terminator for OSC, embedded 7-bit vs 8-bit C1.
- **Snapshot byte tests** are not applicable here (parser doesn't render); use behaviour tests.

Use the `write-phpunit-test` skill. Mirror the namespace `SugarCraft\AnsiParser\Tests`.

## Scribe brief

- `sugar-ansi-parser/README.md`: Composer install + Quickstart (one example: create a custom `Handler`, feed bytes, observe dispatch). Link to upstream `charmbracelet/x/ansi`.
- `sugar-ansi-parser/CALIBER_LEARNINGS.md`: one entry — "Extracted from sugar-vterm/src/Parser/ in step-01 as the shared ANSI state machine. sugar-vterm still has its own copy until step-12."
- Docblocks: class-level `@see Mirrors charmbracelet/x/ansi <ClassName>` on every public class.
- `docs/MATCHUPS.md`: new row in the foundation table — `sugar-ansi-parser | charmbracelet/x/ansi | 🟢 (state machine ported)`.
- `PROJECT_NAMES.md`: Candy-prefix table — add `sugar-ansi-parser → SugarCraft\AnsiParser → x/ansi state-machine port`.
- root `README.md`: lib-list table addition.
- `docs/index.html`: new tile (clone an existing parser-flavored lib tile if there is one, else clone sugar-vterm's).
- `docs/lib/sugar-ansi-parser.html`: new page (clone `docs/lib/sugar-vterm.html` shape and adapt).
- `codecov.yml`: add `- name: sugar-ansi-parser\n  paths: sugar-ansi-parser/`.
- Do NOT modify `.github/workflows/vhs.yml` — sugar-ansi-parser is non-visual.

## Ship brief

- **PR title**: `sugar-ansi-parser: new shared ECMA-48 state-machine package`
- **PR body**:
  ```
  ## Summary
  - New foundation lib sugar-ansi-parser extracts the ECMA-48 / charmbracelet/x/ansi state machine from sugar-vterm/src/Parser/.
  - sugar-vterm is unchanged this PR; the migration onto sugar-ansi-parser happens in step-12 to keep the rollback window open.
  - Sets up sugar-ansi-parser as the future shared parser for candy-ansi-inspect, sugar-quickpick, candy-codeshot (step-20).

  ## Test plan
  - [x] vendor/bin/phpunit in sugar-ansi-parser (≥95% coverage)
  - [x] vendor/bin/phpunit in sugar-vterm (unchanged, still green)
  - [x] php tools/check-path-repos.php

  Refs: docs/repo_map_step_01.md, docs/repo_map_update.md §327.5, §387.2
  ```
- Commit subject: `sugar-ansi-parser: extract ECMA-48 state machine from sugar-vterm`.
