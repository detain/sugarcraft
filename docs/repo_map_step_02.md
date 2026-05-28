# Step 02 — Create candy-buffer

**Branch:** `ai/candy-buffer-new`
**Depends on:** step-00 ✅ (independent of step-01 — buffer/cell don't need the parser)
**Blocks:** step-10 (candy-sprinkles uses candy-buffer), step-14/15/16/17/18 (UI consumers), step-26 (Buffer::diff impl), step-27 (consumers of diff)

## Goal

Create the `candy-buffer` foundation package — `Buffer` (Cell grid) and `Cell` (rune, style, link, width) value objects enabling per-cell dirty tracking and SGR-transition optimisation across the ecosystem. Provides the *data model* this step; the `Buffer::diff()` delta-ANSI emitter is built in step-26.

Reference: `docs/repo_map_update.md` §327.3 (framework), §345.1 (Buffer/Cell components), §387.5 (consolidation of string-composition rendering).

## Files expected to be created

- `candy-buffer/composer.json`
- `candy-buffer/phpunit.xml`
- `candy-buffer/README.md`
- `candy-buffer/CALIBER_LEARNINGS.md`
- `candy-buffer/src/Buffer.php` — final, immutable, fluent.
- `candy-buffer/src/Cell.php` — readonly value object.
- `candy-buffer/src/Position.php` — readonly (row, col).
- `candy-buffer/src/Region.php` — readonly (Position, width, height).
- `candy-buffer/src/Style.php` — minimal style record (fg, bg, attrs bitmask); the rich `SugarCraft\Sprinkles\Style` keeps its place in candy-sprinkles.
- `candy-buffer/src/Hyperlink.php` — readonly (url, id).
- `candy-buffer/tests/BufferTest.php`, `CellTest.php` (sanity).

## Files expected to be modified

- repo root `composer.json` — add require + path-repo.
- `docs/MATCHUPS.md` · `PROJECT_NAMES.md` · root `README.md` · `docs/index.html` · `docs/lib/candy-buffer.html` · `codecov.yml`.

## Acceptance criteria

- [ ] `Buffer::new(int $width, int $height): self` factory.
- [ ] `Buffer::cellAt(int $col, int $row): Cell` — bounds-checked, throws `\OutOfRangeException` on miss.
- [ ] `Buffer::withCellAt(int $col, int $row, Cell $cell): self` — immutable, returns new instance.
- [ ] `Buffer::withRegion(Region $region, Buffer $source): self` — composite/blit.
- [ ] `Buffer::width(): int`, `Buffer::height(): int`, `Buffer::region(): Region`.
- [ ] `Cell::new(string $rune = ' ', ?Style $style = null, ?Hyperlink $link = null, int $width = 1): self`.
- [ ] `Cell::rune(): string`, `style(): ?Style`, `link(): ?Hyperlink`, `width(): int` — bare accessors.
- [ ] Wide chars (CJK, emoji) handled: `width` of 2 means the next cell is the empty "continuation" cell.
- [ ] `Buffer::diff()` is **declared but not implemented** — returns `[]` or `throw new \LogicException('not yet — see step-26')`. Step-26 implements.
- [ ] ≥95 % coverage on Buffer + Cell + Region + Position + Style + Hyperlink.
- [ ] No deps on other `sugarcraft/*` packages — candy-buffer is leaf.
- [ ] `git status` clean on master at end.

## Coder brief

1. **Invoke `scaffold-library`** with slug `candy-buffer`, namespace `SugarCraft\Buffer\`, role "Cell-grid value objects for terminal rendering (shared)".
2. **Invoke `sugarcraft-model-pattern`** when designing Cell + Buffer — they are canonical immutable value objects:
   - `Cell` is `readonly`, all-arg constructor, no `with*()` (it's tiny; rebuild via `new`).
   - `Buffer` uses the `Mutable` trait from candy-core for `mutate()` — `with*()` returns new instance.
3. **Width handling**: don't reimplement width calculation — call out to `\SugarCraft\Core\Util\Width::of($rune)` (existing). If candy-core's util doesn't already expose this, append `Need candy-core::Width::of(string): int` to `docs/repo_map_updates.md` for step-09 to address.
4. **`Buffer::diff()` is a stub**: declare the signature
   ```php
   /** @return list<DiffOp> */
   public function diff(Buffer $previous): array;
   ```
   and return `[]`. Add a class `SugarCraft\Buffer\DiffOp` as a placeholder so the signature compiles, but its real shape is step-26's job. Mark with `@todo step-26`.
5. **Inline sanity tests**: construct a 10×3 buffer, set a few cells, withRegion-blit a 2×2 sub-buffer, assert rune+style at the right coords.
6. Run phpunit + check-path-repos.

## Tester brief

- `Buffer::new` — width/height/region invariants.
- `Buffer::cellAt` — every corner, OutOfRange on overshoot/under-shoot.
- `Buffer::withCellAt` — immutability (original unchanged), coercion (style=null, link=null defaults).
- `Buffer::withRegion` — blit at origin, blit at offset, blit clipped at edges, blit identical buffer (no-op semantics).
- `Cell` — rune narrow/wide/empty, style equality, link equality.
- Wide-char: insert a CJK char (`'中'`, width 2) — the cell after must be a "continuation" cell (rune `''`, width 0). Test reading that cell, blitting over it, etc.
- `Buffer::diff()` stub: assert returns `[]` and does not throw.

## Scribe brief

- README: Quickstart constructing a Buffer, setting cells, blitting; cite the future `diff()` work pointing at step-26.
- CALIBER_LEARNINGS: one entry — "Buffer/Cell are intentionally minimal — rich styling lives in candy-sprinkles, diff() ships in step-26. Don't put rendering logic here."
- MATCHUPS row, PROJECT_NAMES Candy table row, root README, docs/index.html tile, docs/lib/candy-buffer.html.

## Ship brief

- **PR title**: `candy-buffer: new shared Buffer/Cell grid foundation`
- **PR body**:
  ```
  ## Summary
  - New foundation lib candy-buffer adds Buffer (Cell grid) + Cell value objects for terminal rendering.
  - Buffer::diff() declared but unimplemented — step-26 ships the delta-ANSI emitter (ECH/REP/ICH/DCH).
  - Wide-char (CJK/emoji) handled via Width::of from candy-core.
  - Unblocks consumer migration in steps 14-18 (sugar-bits, candy-forms, sugar-prompt, sugar-charts, sugar-table).

  ## Test plan
  - [x] vendor/bin/phpunit in candy-buffer (≥95% coverage)
  - [x] php tools/check-path-repos.php

  Refs: docs/repo_map_step_02.md, docs/repo_map_update.md §327.3, §345.1, §387.5
  ```
- Commit subject: `candy-buffer: add shared Buffer/Cell value objects`.
