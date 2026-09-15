# candy-vt allocation-growth proof

Audit P2 — the "unbounded allocation growth across repeated resize/feed
cycles" concern, as handed down in the brief at `sc-briefs/w3-vt.md` (that
brief cites a `vt-source-code-audit.md` which is not committed to this
repo). `candy-vt/tests/AllocationTest.php` pins the opposite property: the
grid/Buffer/Screen/Parser surfaces are **bounded** across long churn. No
src/ or composer.json change — the proof is a test + this record.

## Measurement methodology

Two independent signals per scenario, so neither a heap leak nor a silently
growing data structure can pass:

1. **Exact structural counts** (environment-independent): grid rows ==
   `rows`, each row == `cols`, distinct live `Cell` object census ==
   `cols*rows` (vcr `CellGrid`) or `1 + writtenCells` (emulator
   `Buffer`, where `Cell::empty()` memoises one shared immutable instance
   across all blank slots), `Scrollback::count()` == `min(pushed, maxSize)`
   exactly, `Parser::currentState() === State::Ground` after `reset()`.
2. **Settled live-heap growth** (`GROWTH_CEILING_BYTES` = **256 KiB**):
   `memory_get_usage()` after `gc_collect_cycles()` + `gc_mem_caches()`
   (`settledUsage()` helper) — this process's zend EMALLOC live bytes,
   unaffected by other processes on a shared runner (unlike raw RSS). Each
   loop warms the allocator first (identical shapes recycle the same chunks)
   and samples the settled baseline at a steady point, then measures the
   cold phase. Because the warm baseline and cold measurement hold the same
   steady-state live set (one grid of the current geometry), that shared
   instance cancels out of the delta — the 256 KiB ceiling bounds
   *per-iteration retention*. A single leaked 320x120 `Buffer` clone is
   ~1.5 MB and a `CellGrid` ~6.3 MB (measured), so a leak overshoots within
   a handful of cycles while allocator bookkeeping slack does not.
3. **Peak working-set growth** (`PEAK_CEILING_BYTES` = **8 MiB**, scoped per
   test by `capturePeakBaseline()` calling `memory_reset_peak_usage()`):
   `memory_get_peak_usage()` is process-monotone, so without a per-test
   reset an earlier heavy test's high-water mark makes a later test's peak
   delta read 0 — the assertion would pass vacuously. Resetting scopes it to
   each test's own transients. The ceiling is an order of magnitude looser
   than the settled one because it measures a different quantity: healthy
   churn transiently holds **one extra full grid** (`resize()` and
   `Screen::fromBuffer()` build the new structure before releasing the old),
   so peak sits ~1.3–3.0 MB above settled by design. 8 MiB bounds that
   one-grid hump; a per-iteration retention smashes straight through it.

`NullHandler` (a discarding `Handler` that counts dispatches so the parser
tests measure the parser's own buffers, not a growing log) lives at
`candy-vt/tests/Support/NullHandler.php` under the `SugarCraft\Vt\Tests\`
→ `tests/` autoload-dev mapping.

## Scenarios covered (15 tests)

- `Buffer` 320x120 shape invariant: `copy()` exposes exactly `rows` arrays of
  `cols` cells each, re-checked across 120 alternating 321x320 resize
  round-trips — a widening grid or stray row fails even if the heap is flat.
- `Buffer` 320x120 resize round-trip churn (321x121 <-> 320x120, x150 after
  x40 warm): live census pinned, content preserved, settled + peak heap flat.
- Pristine 320x120 `Buffer` shares exactly **one** `Cell` object across all
  38400 slots (`Cell::empty()` singleton) — guards the allocation model
  itself from regressing to per-slot empty cells.
- `Terminal` feed of a fixed SGR/CSI/UTF-8 stream x80 at constant dims:
  written-cell census identical every cycle (overwrites free the old
  cells; nothing accumulates).
- vcr `CellGrid` 160x50 clear/resize round-trips x80: census exactly
  8000 every cycle.
- `CsiHandlerImpl` dirty-region bounds never leave the grid under 150
  cycles of clamped `cup` + print.
- `Scrollback` ring: fills one-for-one to `maxSize`, then occupancy pinned
  at `maxSize` across 4x oversupply of pushes; settled heap flat after
  saturation; slots reference the shared empty `Cell` (no clone churn).
- `Terminal` scroll cycle x1200 on a 300-row ring: count stays 300, heap
  flat after the ring saturates.
- Full pipeline `SugarCraft\Vt\Terminal\Terminal`: resize to 320x120, feed
  deterministic SGR/CSI/UTF-8 stream + **alt-screen enter/leave**
  (`\x1b[?1049h`/`l`, DEC 1049 — allocates a whole fresh `Buffer` per
  cycle, the lib's largest per-cycle allocation), snapshot, resize back to
  80x24, feed again, x100 after x5 warm: heap and peak flat.
- UTF-8 wide/emoji/combining reflow x100 with resize down/up through the
  stream: cell census invariant per geometry, heap flat.
- `Screen::fromBuffer` snapshot churn x500 dropped without reset: prior
  snapshots collected, no linear growth.
- `Screen::diff` churn x60: at most 1 change per single write, heap flat.
- `Parser` feed+reset x2000 (warm x200) of a mixed CSI/SGR/OSC 8/UTF-8
  stream through a **discarding** handler: parser-internal buffers
  (`params`, `stringBuffer`, `utf8Buffer`) reclaimed by `reset()`; state
  back to `Ground`. The handler discards dispatches on purpose — a
  recording handler's log grows with input volume and would measure the
  fixture instead of the parser.
- Hostile truncated input x5000 (unterminated OSC, unterminated DCS, cut
  UTF-8 lead byte, 42-parameter CSI over the 32-param cap) with
  flush()+reset() every cycle: no growth.
- Second pipeline (`SugarCraft\Vt\Terminal`, vcr renderer path): feed +
  `snapshot()` x300 at 160x50: heap and peak flat, grid dims intact.

## Where the unbounded-growth risk actually lives

- **`Buffer`/`CellGrid`**: `resize()` returns a *clone*; the only retained
  reference is the slot it replaces, so churn is recycling, not
  accumulation. `Buffer::makeGrid` fills blank slots with the memoised
  `Cell::empty()` singleton: 38400 slots == 1 cell object + array slots
  (~39 B/slot). The vcr `CellGrid` instead allocates one immutable
  `Vt\Cell` per slot (6.3 MB at 320x120) — bounded by construction, but
  ~4x the emulator path: the single largest grid allocation in the lib,
  now pinned by census.
- **`Screen`**: `readonly` value copy of `Buffer::copy()`; snapshots are
  independent and drop cleanly. `diff()` allocates a per-change list of
  cell refs (never cell copies) — bounded by touched-cell count.
- **`Scrollback`**: ring buffer, `array_fill`'d once at construction
  (`maxSize` default 1000), overwrites in place past saturation — the only
  input-volume-proportional storage in the render path, and it is capped
  at construction.
- **`Parser`** (`sugarcraft/candy-ansi` `Parser`): internal `params` list
  capped at 32, string buffer capped at 65536 B
  (`maxStringBuffer: 65536` — wired in both `Terminal` facades, the W1.2
  reduction from the 1 MiB upstream default), `reset()` = `flush()` +
  `clear()` returns to ground with all buffers empty. The Parser has **no
  resize** (grid size is not its state); the feed/**resize**/reset cycle
  named in the audit is therefore proven as feed()+reset() on the Parser
  plus resize() churn through `Terminal::resize` -> `Buffer::resize`, both
  covered above.
- **Named types in the source audit that do not exist here**: there is no
  `SugarCraft\Vt\Grid\Grid` and no `ScrollingBuffer`/`FixedBuffer` — the
  grid is `CellGrid` + `Buffer\Buffer` (+ `Screen\Screen`/
  `Screen\Scrollback`). Per the brief the test asserts against the real
  classes; no API was invented. The per-cycle allocation risk in the
  `ScreenHandler` path is the alt-screen swap (one fresh `Buffer` per
  DEC 1049 enter), exercised above.
- **Input-volume-proportional fields, intentionally retained** (not
  grid-state leaks, identical stream => identical size): `ScreenHandler`
  `palette` (bounded 256), `clipboardEvents`/`focusEvents` (OSC 52 /
  DEC 1004 records, grow only if the *program* emits those; churn loops
  above keep the per-cycle input constant).

## Known non-blocking diagnostics

- `candy-vt/vendor/.../candy-ansi` tests report "Class cannot be found in
  the configured test source" when `--filter` runs across symlinks —
  pre-existing, unrelated to this change.
- phpstan baseline: 108 raw diagnostics on stdout for `src/`+`tests/` on HEAD
  (124 with `2>&1` — the 16-line delta is PHPStan's stderr guidance footer,
  not diagnostics). Verified identical with the new files present and absent
  — they add zero.
- php-cs-fixer is configured at the repo root (`.php-cs-fixer.dist.php`),
  not in `candy-vt/`; the check runs with `--config=../.php-cs-fixer.dist.
  php --path-mode=intersection` over both new files — 0 of 2 fixable.
