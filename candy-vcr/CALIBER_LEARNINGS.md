# Caliber Learnings

Accumulated patterns and anti-patterns from development sessions.
Auto-managed by [caliber](https://github.com/caliber-ai-org/ai-setup) — do not edit manually.

- **[gotcha:project]** When using `candy-vcr` `Recorder` attached to a `Program` via `withRecorder()`, do NOT close the recorder on `QuitMsg` — `teardownTerminal()` runs AFTER the loop stops and emits bytes (alt-screen leave, mode resets like `\x1b[?2027l`) that must land in the cassette for replay byte-equality. Close the recorder only at the end of `Program::run()`. Tests asserting Quit is the LAST cassette event will be wrong — Quit appears once but is followed by teardown output events.
- **[gotcha:project]** When `candy-vcr` `Player` drives a `Program` in `SPEED_INSTANT`, do NOT schedule every cassette event at `delay=0` via `addTimer` — React's `StreamSelectLoop` fires them all in the same tick before the program's framerate-based render tick gets a turn, so `view()` is never rendered and no output is produced. Schedule events sequentially with a small yield (~5 ms — `Player::INSTANT_YIELD_SECONDS`) between consecutive events; in `SPEED_REALTIME` use the delta between recorded `t` values, clamped to `>= 0`.
- **[pattern:project]** For VCR-style round-trip tests (record → replay → assert byte-equality), the replay `Program` must use the SAME `ProgramOptions` (alt-screen, hide-cursor, catch-interrupts) as the recording Program, since the cassette captures setup AND teardown bytes — any divergence in lifecycle options will break `ByteAssertion`. Build both Programs from a shared factory closure that takes `(input, output, loop)` and applies identical options.
- **[pattern:project]** For raw-byte (`b`-form) input events on replay, bypass the program's stream watcher: parse the bytes through a fresh `InputReader` and call `program->send($msg)` directly per parsed `Msg`. Going through `fwrite($inputWrite, ...)` introduces an async race between write/read/parse and the loop's next tick that makes byte-deterministic assertions flaky.
- **[gotcha:project]** `candy-vcr` `ByteAssertion::compare()` returns a hex window that STARTS at the first-divergence offset (not before it). Tests that expect to see bytes preceding the divergence point in the diff string will fail — assert against the divergent tail bytes and onward, e.g. `4a (J)` / `4b (K)`, not `1b5b324a`.
- **[pattern:project]** When a PHPUnit failure for a `Program`/`Recorder`/`Player` integration is opaque ("true is false"), drop a one-shot debug script under `/tmp/dbg*.php` that requires the lib's `vendor/autoload.php`, runs the same record-then-replay flow, and prints the cassette contents + replay verdict — it is much faster than adding `var_dump` calls to the test and reveals issues like missing render output or unexpected teardown ordering.
- **[pattern:project]** When adding a CLI bin script to a SugarCraft lib, use a portable autoload-resolution loop in the bin file that tries both `__DIR__ . '/../vendor/autoload.php'` (lib-local development) and `__DIR__ . '/../../../autoload.php'` (composer-installed shim under a parent project's `vendor/bin/`) — single-path requires break when the lib is consumed downstream as a Composer dep. Pair it with `"bin": ["bin/<name>"]` in `composer.json` and `chmod +x` on the script.
- **[pattern:project]** Generate small fixture cassettes used by examples + tests by running `php examples/record.php examples/cassettes/<name>.cas` once and committing the cassette — the JSONL format is byte-stable enough to keep in git, and `bin/candy-vcr inspect <path>` is the quickest sanity check that the cassette is well-formed (header line + per-event lines, ends with `N / N event(s) shown`).
- **[gotcha:project]** `cd /path && ( watchdog... ) > log 2>&1 &` puts BOTH the `cd` AND the watchdog into a single backgrounded subshell — the parent shell never cd's, so the next foreground command (e.g. `vendor/bin/phpunit`) runs from the original CWD and fails with `No such file or directory` (exit 127). Fix: background only the watchdog subshell (`( sleep N && pkill ... ) &` as its own statement), then `cd /lib && ./vendor/bin/phpunit ...` on the next line. Always prefix the test binary with `./` so a stray `$PATH` doesn't shadow the local copy.
- **[pattern:project]** PHPUnit watchdog for PTY/FFI-heavy candy-vcr / candy-pty tests: `( sleep 120 && pkill -9 -f 'vendor/bin/phpunit'; pkill -9 -f 'pty-shim'; pkill -9 -f bash; pkill -9 -f /bin/echo; pkill -9 -f /bin/sh ) > /tmp/wd.log 2>&1 &` then save `WATCHDOG_PID=$!`, run phpunit, then `kill $WATCHDOG_PID 2>/dev/null` so the watchdog doesn't survive a passing run and SIGKILL unrelated shells later. `timeout(1)` alone won't reap PTY-spawned children that have escaped into their own session.
- **[gotcha:project]** When mutating env via `putenv()` inside a PHPUnit test that later spawns a PTY child to write the cassette, the captured env (via `RecordCommand::filteredHostEnv()`) lives in the parent PHP process — so direct-call tests of `filteredHostEnv()` see the putenv'd vars, but if the cassette header ends up empty in a record-and-read-back test, the failure is downstream (cassette write/read round-trip), NOT a putenv visibility issue. Verify with a one-liner: `php -r 'putenv("X=y"); var_dump(getenv()["X"] ?? "missing");'` before adding more env plumbing.
 - **[convention:project]** `candy-vcr` `CassetteHeader::env` is opt-in (defaults `[]`) and gated on `record --env` — never default to capturing the full shell environment. The conservative secret-name regex (`/(SECRET|TOKEN|KEY|PASSWORD|API|CRED|AUTH|PRIV)/i`) is intentionally over-aggressive (it strips `KEYBOARD_LAYOUT` because it contains `KEY`); callers needing finer control use `--env-regex=PATTERN`. Don't relax the default to keep more keys.
 - **[pattern:project]** SIGINT rescue on macOS: when `record` puts the host TTY in raw mode and the recorded child has a controlling terminal, `SIGINT` propagates correctly. The rescue shutdown handler + `pcntl_signal(SIGTERM/SIGHUP)` covers `SIGTERM`/`SIGHUP` cleanly, but `SIGKILL` remains unhandleable. Always leave the rescue marker file mechanism in place for hard-kill recovery.
 - **[gotcha:project]** `RecordCommand::filteredHostEnv('')` (empty string regex) skips ALL env filtering — no keys are stripped. This is the internal mechanism behind `--env-allow-secrets` and it is a genuine footgun: any test that accidentally passes `''` instead of `null` or a proper regex will record credentials verbatim into cassettes.
- **[pattern:relative-format-dt]** `RelativeFormat` uses `dt` (delta since previous event) at file level; `Player::detectFormat()` auto-selects `RelativeFormat` vs `JsonlFormat` by checking for `dt` on the first event line — callers pass `Recorder::withFormat(new RelativeFormat())` to record in delta-time mode, and replay requires no format argument.
- **[pattern:idle-trim-realtime-playback]** `Player::withIdleTrim(?float $seconds)` sets a replay-time idle-gap ceiling: when `SPEED_REALTIME` playback encounters a delay between events that exceeds the threshold, the delay is clamped to the threshold so CI tests don't hang on multi-second gaps from the original recording. The same pattern (clamp `delta` when `idleTrim !== null && delta > idleTrim`) is applied in `ReplayCommand` so the CLI honours `--idle-trim` flags. `withIdleTrim(null)` disables trimming. The explicit `$idleThresholdSeconds` parameter to `play()` overrides the fluent setting — the parameter form is useful for one-off test overrides while the fluent form suits library-level configuration.
- **[pattern:tape-compiler-emits-raw-bytes]** The `Compiler::compile()` for `TypeDirective` emits `Input` events with `['b' => string]` payload (raw bytes) rather than `['msg' => [...]]` envelope form. This avoids the need to serialize `KeyMsg` objects (which don't implement `\JsonSerializable`) and is the simplest path since the downstream `Player` handles both forms identically. Direct raw-byte emission also avoids double-parsing through `InputReader`. The trade-off: `TypeDirective` can't emit non-KeyMsg input events (mouse, focus, etc.) — but those never appear in `.tape` files so this is not a limitation in practice.
- **[gotcha:tape-parser-env-value-quoting]** `Env KEY "value"` directives have their value stored with quotes intact in the lexer token; the `Compiler` strips surrounding quotes with `trim($node->value, '"\' ')`. This is correct for the common case (`"xterm-256color"`, `'demo'`). Unquoting at compile time (rather than parse time) avoids needing to handle mismatched quote styles in the parser.
- **[pattern:snapshot-equality-excludes-time]** `Snapshot::equals()` compares `grid` and `cursor` but NOT `time`. This is intentional — two frames captured at different virtual times but with identical terminal state should be considered equal for frame-dedup purposes. Use `equalsWithTime()` when exact reproducibility at a specific timestamp matters. Mirrors charmbracelet/x/vcr snapshot equality.
- **[gotcha:frame-dedup-holdmax-off-by-one]** The `FrameDedup::dedup()` holdMax logic uses `$prevHold <= $holdMax` to decide whether to skip emitting a duplicate frame. When `$prevHold` equals `holdMax`, the duplicate IS emitted (since the condition becomes false). This means with `holdMax=300`, a run of 300 identical frames yields 1 output frame (the 301st identical frame is emitted). Document this as "at most `holdMax` collapses" semantics.
- **[pattern:frame-stream-iterator-memory]** `FrameStream` implements `\IteratorAggregate` (not `\Iterator`) so that iteration is lazy — only one frame is held in memory at a time. The `getIterator()` method yields frames via `yield`, never buffering. This is critical for long-running renders that could otherwise exhaust memory with accumulated snapshots.
- **[gotcha:frame-stream-final-frame-logic]** The final frame emission check in `FrameStream::getIterator()` uses `$virtualTime > $lastSnapshotTime` (strict greater-than, not `>=`). This prevents duplicate frame emission when the last event timestamp equals the last frame boundary. The check `0 > 0` is false, so no extra frame is emitted at the same virtual time as the previous emission.

---

## Phase 0 benchmark (2026-05-21)

**Scope:** Unblock candy-vcr PHPUnit suite + establish tooling baseline for vhs-replacement work.

### PHPUnit suite fix

`TickModel::subscriptions()` was unimplemented across three test files, causing a fatal error on every test run. Fixed by adding `return null;` implementations:
- `tests/PlayerTest.php` — local `TickModel`
- `tests/Assert/ScreenRoundTripTest.php` — local `TickModel`
- `tests/ProgramRecordingTest.php` — `CountingModel`
- `tests/PlayerIdleTrimTest.php` — anonymous class model
- `tests/PlayerIdleTrimmingTest.php` — anonymous class model

### phpstan baseline

Added `candy-vcr/phpstan.neon` (level: max, paths: src/ + tests/) and a generated baseline (`phpstan-baseline.neon`, 441 errors suppressed). The pre-existing type-inference issues in the legacy code require broader cleanup beyond Phase 0 scope; baselines preserve signal for NEW errors introduced by subsequent phases. Same pattern applied to `candy-vt/phpstan.neon` (128 errors baseline).

### Benchmark — Player replay (counter.cas, 6 events)

```
Cassette: examples/cassettes/counter.cas (6 events)
Wall time: ~31 ms
Peak memory delta: <0.01 MB
Mode: SPEED_INSTANT
```

This is the baseline before render-pipeline work. Per-event cost will grow as Phases 3–6 add Terminal feed + snapshot + rasterize + GIF encode steps.

## Phase 3 Renderer (2026-05-22)

**Scope:** Renderer + FrameStream + FrameDedup + Snapshot equality methods.

### Snapshot equality

Added `Snapshot::equals()` that compares `grid` and `cursor` (but NOT `time`) to enable frame dedup across different virtual timestamps. Also added `equalsWithTime()` for exact reproducibility checks. Added `CellGrid::equals()` by iterating all cells and delegating to `Cell::equals()`. Added `Cursor::equals()` for completeness (already had in original implementation).

### typingSpeed in CassetteHeader

The tape `Compiler` now stores `Set TypingSpeed` values in the `CassetteHeader::typingSpeed` field. This field is nullable (`?float`) with `null` meaning "not set / use default 50ms". Existing cassettes without this field (recorded before Phase 3) remain compatible.

### FrameStream design

`FrameStream` implements `\IteratorAggregate` (not `\Iterator`) for lazy iteration — only one frame in memory at a time. The `getIterator()` method uses `Generator::yield` to emit frames at 1/fps intervals as the virtual clock advances through cassette events. Resize events cause a new `Terminal` instance to be created; subsequent events feed to the new terminal.

### Decision log

- **Snapshot::equals excludes time:** For frame dedup, we want to collapse identical visual states regardless of when they were captured. Including time would prevent any dedup since each frame has a unique virtual timestamp.
- **holdMax default 300:** At 30fps, 300 frames = 10 seconds of hold. This prevents infinite holds from frozen/stuck terminal states while still collapsing the common case of cursor-blink idle time.
- **Cell equality is O(cols × rows):** Documented as a known bottleneck for Phase 4 optimization (possible hash-based fast path).

### Tests

- `tests/Render/RendererTest.php` — 7 tests covering empty cassette, fps cadence, input byte feeding, resize handling, quit behavior, typing speed header usage.
- `tests/Render/FrameDedupTest.php` — 7 tests covering identical frame collapse, unique frame passthrough, middle identical runs, 10-identical + 1-different case, holdMax honoring, empty stream, and holdMax=0 edge case.

- **Primary rasterizer backend:** `ext-gd` — universal availability (bundled with PHP), sufficient for cell-grid rendering at 800×480.
- **Primary GIF encoder:** `FfmpegGifEncoder` — ffmpeg already present in CI runner image; `palettegen=stats_mode=diff` + `paletteuse` two-pass produces quality GIFs at acceptable speed.
- **Font:** JetBrainsMono Regular + Bold — OFL license, excellent monospace coverage, broad glyph support including box-drawing characters used in terminal UIs.

## Phase 5 GIF Encoder (2026-05-22)

**Scope:** GifEncoder interface + FfmpegGifEncoder + PhpGifEncoder + TapeToGif pipeline + tests.

### Namespace: `SugarCraft\Vcr\Encode`

New files created:
- `src/Encode/GifEncoder.php` — interface `encode(\Iterator, int $cols, int $rows, array $frameHolds, string $outputPath): void`
- `src/Encode/FfmpegGifEncoder.php` — default ffmpeg implementation; two-pass palettegen with `stats_mode=diff`; CFR via `-framerate` and VFR via concat demuxer with `proc_open` + process substitution
- `src/Encode/PhpGifEncoder.php` — pure-PHP stub throwing `RuntimeException('Pure-PHP GIF encoder not yet implemented; use FfmpegGifEncoder')`
- `src/Encode/TapeToGif.php` — full pipeline: Lexer → Parser → Compiler → Player → Terminal → Renderer → FrameStream → FrameDedup → Rasterizer → GifEncoder

### Frame hold tracking

`FrameDedup::dedup()` collapses identical adjacent snapshots but does not expose how many frames were collapsed per emitted snapshot. `TapeToGif::buildFramesWithHolds()` tracks the virtual timestamp of each emitted snapshot and computes hold as the delta from the previous emitted snapshot's time. This correctly distributes the total display duration across collapsed runs.

Example: if FrameStream emits frames 0, 1, 2, 3, 4 at t=0.000, 0.033, 0.067, 0.100, 0.133 (all identical), and frame 5 at t=0.167 (different), FrameDedup yields only snapshot 0 and snapshot 5. The hold for snapshot 0 is 5 × 0.033 = 0.167 seconds (all five original frames collapsed into one).

### FfmpegGifEncoder VFR concat format

```
file 'frame00000.png'
duration 0.033
file 'frame00001.png'
duration 0.033
...
file 'frame00004.png'
duration 0.033
file 'frame00004.png'
```

The last frame is listed twice without a trailing duration — this is the standard technique for giving the final frame a display duration (the second listing's duration applies to the frame listed BEFORE it in the concat format). The GIF loops forever after the last frame.

### Symfony Process v7 signature

`Process::run(?array $env = null, ?float $timeout = null)` — the `$timeout` is `?float`, not `int`. Passing an `int` causes a TypeError. Always use `5.0` not `5` when setting a timeout in seconds.

### Decision log

- **`cols`/`rows` in GifEncoder interface:** These parameters exist on the interface but are not used inside `FfmpegGifEncoder::encode()` — the PNG frames are already rasterized at the correct pixel dimensions by the `Rasterizer`. The parameters exist for `PhpGifEncoder` (which would need them to construct a GIF from scratch). This is a minor API design smell but not a bug.
- **symfony/process as runtime dep:** `FfmpegGifEncoder` directly uses `Symfony\Component\Process\Process` and `proc_open`, so `symfony/process` is in `require` (not `require-dev`).
- **Empty frames fallback:** When `encode()` receives zero frames, it creates a 1×1 empty PNG and encodes it — this produces a minimal valid GIF (required for zero-duration/concatenation-safe encoders).
- **Temp file cleanup:** All temp PNGs are cleaned up in a `finally` block even on encode failure.

### Tests

- `tests/Encode/FfmpegGifEncoderTest.php` — 6 tests: CFR encode, VFR encode, empty frames, ffmpeg failure, temp cleanup.
- `tests/Encode/PhpGifEncoderTest.php` — 1 test: asserts RuntimeException is thrown.
- `tests/Encode/TapeToGifTest.php` — 7 tests: end-to-end smoke, custom options, default output path, php encoder throws, nonexistent tape throws, encoder/backend selection.

## Phase 4 Rasterizer (2026-05-22)

**Scope:** Raster + Glyphs + FontLoader for Snapshot → PNG rendering.

### Namespace: `SugarCraft\Vcr\Raster`

New files created:
- `src/Raster/FontLoader.php` — TTF font path resolver; tries `candy-vcr/fonts/` first, then system dirs. Returns file path string for `imagettftext()`.
- `src/Raster/Glyphs.php` — per-(char, fg, bg, bold, italic, underline) tile cache. Cache key: `"$char|$fg|$bg|$bold|$italic|$underline"`. Pre-renders tiles at cell dimensions; wide chars get a 2× wide tile.
- `src/Raster/Rasterizer.php` — interface `rasterize(Snapshot, cellW, cellH, ?FontLoader): GdImage|Imagick`.
- `src/Raster/GdRasterizer.php` — default ext-gd implementation; blits glyph tiles onto canvas, renders cursor overlay.
- `src/Raster/ImagickRasterizer.php` — ext-imagick alternative; better anti-aliasing.

### Cell metrics

```
Cell metrics at FontSize 14:
  charWidth  = 8px
  charHeight = 16px (includes descent)
  cellW     = charWidth
  cellH     = charHeight
  GIF dims  = cols * cellW × rows * cellH
  Example: 80 cols × 24 rows → 640 × 384 px at FontSize 14
```

### Font vendoring

DejaVuSansMono is vendored as a fallback (no JetBrainsMono in env). The `fonts/` directory contains:
- `JetBrainsMono-Regular.ttf` — pending download
- `JetBrainsMono-Bold.ttf` — pending download
- `DejaVuSansMono.ttf` — fallback
- `DejaVuSansMono-Bold.ttf` — fallback

FontLoader tries bundled fonts first, then system dirs (`/usr/share/fonts/`, `~/.fonts/`, etc.). Italic style falls back to regular if Italic variant unavailable.

### Glyphs cache

Cache key: `"$char|$fg|$bg|$bold|$italic|$underline"`. Wide chars (mb_strwidth > 1) get a 2×-wide tile; the rasterizer advances 2 columns after blitting. Typical terminal frame has thousands of cells but only ~50 unique combinations — caching converts rasterization from O(cells) to O(unique tiles).

### Cursor rendering

Block cursor (shape=1): renders the glyph in reverse video (swapped fg/bg colors).
Underline cursor (shape=2): filled rectangle at y = cellH × 0.75.
Bar cursor (shape=3): narrow filled rectangle at left edge.

### Decision log

- **Snapshot property access:** `Snapshot` has `$grid` and `$cursor` as public properties (not methods). Use `$snapshot->grid` and `$snapshot->cursor`, not `$snapshot->grid()`.
- **`imagettftext()` font path:** ext-gd requires an actual file path string, not a font resource ID. `FontLoader::load()` returns `string` (absolute path).
- **`imagecreatetruecolor` assertion:** width/height must be `int<1, max>`. `assert($width >= 1 && $height >= 1)` added before call since both dimensions derive from positive integers.
- **`imagecolorallocate` color values:** palette index 0-255 maps to 0-255 RGB. Values must be explicitly clamped with `max(0, min(255, $val))` before passing to `imagecolorallocate` so PHPStan understands the type constraint.
- **PHPStan 2.0 static var inference:** `static $palette` inside methods with complex array types caused `return.type` errors ("returns mixed"). Fixed by returning array literals directly (16-element static is negligible perf). Do not use `@var` annotation on static to suppress this — return the literal directly.
- **FontLoader `$_SERVER['HOME']`:** In PHP 8.1+ this can be `false`, not just `string|null`. Must use `is_string($_SERVER['HOME']) ? $_SERVER['HOME'] : '/root'`, not `(string) ($_SERVER['HOME'] ?? '/root')`.

### Tests

- `tests/Raster/FontLoaderTest.php` — 9 tests: load bundled font, bold variant, missing font throws RuntimeException, resolve path, lastResolvedPath tracking, systemDirs(), italic/bolditalic fallback.
- `tests/Raster/GlyphsTest.php` — 9 tests: cache hits (same instance), cache misses (different instance), wide char double-width, measure() dimensions, space character tile.
- `tests/Raster/GdRasterizerTest.php` — 11 tests: returns GdImage, correct dimensions, empty grid, cursor visible, bold/underline/inverse attrs, hidden cursor, different cursor shapes, auto FontLoader, non-zero pixels.

