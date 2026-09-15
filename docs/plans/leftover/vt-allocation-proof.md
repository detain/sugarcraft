# candy-vt allocation-growth proof

Audit P2 (source audit, ~lines 175-181, "unbounded allocation growth across
repeated resize/feed cycles"). `candy-vt/tests/AllocationTest.php` pins the
opposite property: the grid/Buffer/Screen/Parser surfaces are **bounded**
across long churn. No src/ or composer.json change — the proof is a test +
this record.

## Measurement methodology

Two independent signals per scenario, so neither a heap leak nor a silently
growing data structure can pass:

1. **Exact structural counts** (environment-independent): grid rows ==
   `rows`, each row == `cols`, distinct live `Cell` object census ==
   `cols*rows` (vcr `CellGrid`) or `1 + writtenCells` (emulator
   `Buffer`, where `Cell::empty()` memoises one shared immutable instance
   across all blank slots), `Scrollback::count()` == `min(pushed, maxSize)`
   exactly, `Parser::currentState() === State::Ground` after `reset()`.
2. **Settled live-heap growth**: `memory_get_usage()` after
   `gc_collect_cycles()` + `gc_mem_caches()` (`settledUsage()` helper).
   This is this process's zend EMALLOC live bytes — unaffected by other
   processes on a shared runner (unlike raw RSS). Each loop warms the
   allocator first (identical shapes recycle the same chunks), then
   measures a cold phase; the ceiling is **256 KiB** of growth between
   warm and cold phase. A single retained 320x120 `Buffer` clone is
   ~1.5 MB and a `CellGrid` ~6.3 MB (measured), so a per-iteration leak
   overshoots the ceiling within a handful of cycles, while allocator
   bookkeeping slack does not. `memory_get_peak_usage()` deltas are
   asserted alongside, to catch allocation rate outrunning release.

## Scenarios covered (15 tests)

- `Buffer` 320x120 resize round-trip churn (321x121 <-> 320x120, x150 after
  x40 warm): live census pinned, content preserved, no heap growth.
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
- phpstan baseline at 124 raw diagnostics for `src/`+`tests/` on HEAD
  (verified identical with `AllocationTest.php` present and absent — the
  new test adds zero).
- php-cs-fixer is configured at the repo root (`.php-cs-fixer.dist.php`),
  not in `candy-vt/`; the check runs with `--config=../.php-cs-fixer.dist.
  php --path-mode=intersection` — 0 of 1 files fixable for this test.
