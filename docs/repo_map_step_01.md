# Step 01 — Create candy-ansi

**Branch:** `ai/candy-ansi-new`
**Depends on:** step-00 ✅
**Blocks:** step-12 (candy-vt → candy-ansi), step-20 (sugar-spark/candy-hermit/candy-freeze → candy-ansi)

## Goal

Create the `candy-ansi` foundation package — a standalone PSR-4 `SugarCraft\Ansi\` port of the ECMA-48 / `charmbracelet/x/ansi` state machine. The implementation is a clean **copy** of `candy-vt/src/Parser/*` (already a working port). candy-vt continues to ship its own copy this step; the migration of candy-vt onto candy-ansi happens in step-12 — that buys us a safe rollback window.

Reference: `docs/repo_map_update.md` §327.5 (frameworks), §336 (already partially ported in candy-vt), §387.2 (reinvention to consolidate).

## Files expected to be created

- `candy-ansi/composer.json`
- `candy-ansi/phpunit.xml`
- `candy-ansi/README.md`
- `candy-ansi/CALIBER_LEARNINGS.md`
- `candy-ansi/src/Parser/Parser.php`
- `candy-ansi/src/Parser/State.php`
- `candy-ansi/src/Parser/Transitions.php`
- `candy-ansi/src/Parser/Action.php`
- `candy-ansi/src/Parser/Handler.php`
- `candy-ansi/src/Parser/HandlerAdapter.php`
- `candy-ansi/src/Parser/CsiHandler.php`
- `candy-ansi/src/Parser/CsiHandlerImpl.php`
- `candy-ansi/src/Parser/OscHandler.php`
- `candy-ansi/src/Parser/OscHandlerImpl.php`
- `candy-ansi/src/Parser/DebugHandler.php`
- `candy-ansi/tests/ParserTest.php` (inline sanity; comprehensive suite from TestEngineer)

## Files expected to be modified

- repo root `composer.json` — add `"sugarcraft/candy-ansi": "@dev"` + path-repo entry.
- `docs/MATCHUPS.md` — add row.
- `PROJECT_NAMES.md` — add to Candy* (foundation) table.
- root `README.md` — add to lib-list table.
- `docs/index.html` — add tile.
- `docs/lib/candy-ansi.html` — create.
- `.github/workflows/vhs.yml` — add to `all=(...)` only if a `.vhs/*.tape` is added (Parser libs are non-visual, so likely NOT — leave out).
- `codecov.yml` — add `flag: candy-ansi`.

## Acceptance criteria

- [ ] `candy-ansi/` directory exists with the canonical lib skeleton.
- [ ] `composer.json` declares PSR-4 `SugarCraft\Ansi\` → `src/`, PHP `^8.3`, PHPUnit `^10.5`, `minimum-stability: dev`, `prefer-stable: true`, metadata block (keywords include `sugarcraft` + `ansi`, single author Joe Huss, support links).
- [ ] No `require sugarcraft/*` deps yet — candy-ansi is leaf.
- [ ] All `Parser/*.php` files copied byte-for-byte from `candy-vt/src/Parser/` with namespace rewrites `SugarCraft\Vt\Parser` → `SugarCraft\Ansi\Parser` throughout.
- [ ] `vendor/bin/phpunit` in `candy-ansi/` passes (≥1 sanity test; comprehensive suite is the TestEngineer's job).
- [ ] `vendor/bin/phpunit` in `candy-vt/` STILL passes (candy-vt unchanged this step).
- [ ] `php tools/check-path-repos.php` passes.
- [ ] ≥95 % coverage on the new candy-ansi package (TestEngineer phase).
- [ ] `git status` clean, on master, PR merged, branch deleted.

## Coder brief

1. **Invoke the `scaffold-library` skill** with slug `candy-ansi`, namespace `SugarCraft\Ansi\`, one-line role "ECMA-48 / charmbracelet/x/ansi state-machine port (shared)". Let it handle the full root-doc + workflow wiring per AGENTS.md.
2. **Copy the parser files** from `candy-vt/src/Parser/` into `candy-ansi/src/Parser/` using `cp` (preserve semantics; do NOT use `mv` — candy-vt keeps its copy this step):
   ```bash
   cp candy-vt/src/Parser/*.php candy-ansi/src/Parser/
   ```
3. **Rewrite namespaces** in each copied file: `SugarCraft\Vt\Parser` → `SugarCraft\Ansi\Parser`, and any `use SugarCraft\Vt\…` that the parser doesn't actually need. Use `sed -i 's|SugarCraft\\\\Vt\\\\Parser|SugarCraft\\\\Ansi\\\\Parser|g'` then audit.
4. **If any parser file imports outside of `Parser/`** (e.g., a `SugarCraft\Vt\Cell` or `SugarCraft\Vt\Terminal`), refactor the import so candy-ansi is self-contained. The state machine itself emits abstract Actions; cell/terminal handling lives in the *consumer*. If you discover a coupling that can't be cleanly cut, append `BLOCKING: candy-ansi cannot extract without candy-vt coupling: <details>` to `docs/repo_map_updates.md`.
5. **Add a class-level docblock** to `Parser.php`: `@see Mirrors charmbracelet/x/ansi Parser (ECMA-48 state machine)`.
6. **Write one sanity test** in `candy-ansi/tests/ParserTest.php`:
   - Feed `"hello\x1b[31mworld\x1b[0m"` through `Parser::feed()`.
   - Assert at least one `CsiHandler::sgr([31])` invocation and one `[0]` reset.
7. **Run** `cd candy-ansi && composer install --quiet && vendor/bin/phpunit`.
8. **Run** `cd candy-vt && composer install --quiet && vendor/bin/phpunit` — must still pass (we haven't touched candy-vt).
9. **Run** `php tools/check-path-repos.php` from repo root.

If you need clarification on which Parser files belong in the state machine vs the cell handler, spawn a Researcher with the question "list the responsibilities of each file under candy-vt/src/Parser/ and identify any that touch candy-vt's Cell/Terminal classes".

## Tester brief

candy-ansi's public API surface = `Parser::feed(string $bytes)`, `Parser::reset()`, the `Handler` interface, `CsiHandler` + `OscHandler` interfaces. Target ≥95 % coverage.

- **State machine coverage**: drive `Parser::feed()` with byte strings exercising every state transition in `State.php`. Use a `data provider` over the ECMA-48 state table.
- **CSI dispatch**: every CSI sequence the handler interface declares (CUU/CUD/CUF/CUB cursor moves, SGR, ED erase display, EL erase line, etc.) — one test asserting the right method is called with right params.
- **OSC dispatch**: hyperlink (OSC 8), title (OSC 0/1/2), color query (OSC 4/10/11/12).
- **Edge cases**: malformed sequences (premature ST, runaway CSI), UTF-8 continuation bytes interleaved with escapes, BEL vs ST terminator for OSC, embedded 7-bit vs 8-bit C1.
- **Snapshot byte tests** are not applicable here (parser doesn't render); use behaviour tests.

Use the `write-phpunit-test` skill. Mirror the namespace `SugarCraft\Ansi\Tests`.

## Scribe brief

- `candy-ansi/README.md`: Composer install + Quickstart (one example: create a custom `Handler`, feed bytes, observe dispatch). Link to upstream `charmbracelet/x/ansi`.
- `candy-ansi/CALIBER_LEARNINGS.md`: one entry — "Extracted from candy-vt/src/Parser/ in step-01 as the shared ANSI state machine. candy-vt still has its own copy until step-12."
- Docblocks: class-level `@see Mirrors charmbracelet/x/ansi <ClassName>` on every public class.
- `docs/MATCHUPS.md`: new row in the foundation table — `candy-ansi | charmbracelet/x/ansi | 🟢 (state machine ported)`.
- `PROJECT_NAMES.md`: Candy-prefix table — add `candy-ansi → SugarCraft\Ansi → x/ansi state-machine port`.
- root `README.md`: lib-list table addition.
- `docs/index.html`: new tile (clone an existing parser-flavored lib tile if there is one, else clone candy-vt's).
- `docs/lib/candy-ansi.html`: new page (clone `docs/lib/candy-vt.html` shape and adapt).
- `codecov.yml`: add `- name: candy-ansi\n  paths: candy-ansi/`.
- Do NOT modify `.github/workflows/vhs.yml` — candy-ansi is non-visual.

## Ship brief

- **PR title**: `candy-ansi: new shared ECMA-48 state-machine package`
- **PR body**:
  ```
  ## Summary
  - New foundation lib candy-ansi extracts the ECMA-48 / charmbracelet/x/ansi state machine from candy-vt/src/Parser/.
  - candy-vt is unchanged this PR; the migration onto candy-ansi happens in step-12 to keep the rollback window open.
  - Sets up candy-ansi as the future shared parser for sugar-spark, candy-hermit, candy-freeze (step-20).

  ## Test plan
  - [x] vendor/bin/phpunit in candy-ansi (≥95% coverage)
  - [x] vendor/bin/phpunit in candy-vt (unchanged, still green)
  - [x] php tools/check-path-repos.php

  Refs: docs/repo_map_step_01.md, docs/repo_map_update.md §327.5, §387.2
  ```
- Commit subject: `candy-ansi: extract ECMA-48 state machine from candy-vt`.
