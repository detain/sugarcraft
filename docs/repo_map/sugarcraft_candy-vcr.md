# SugarCraft candy-vcr: In-Depth Innovation/Comparison Report

## Package Overview

**Package:** `candy-vcr`  
**Composer package:** `sugarcraft/candy-vcr`  
**Namespace:** `SugarCraft\Vcr`  
**Status:** 🟢 v1 ready (11 PRs merged)  
**Upstream:** [charmbracelet/x/vcr](https://github.com/charmbracelet/x/tree/main/vcr) — and serves as a drop-in PHP replacement for [charmbracelet/vhs](https://github.com/charmbracelet/vhs) (.tape → .gif renderer)  
**Primary role:** Record and replay candy-core TUI sessions with byte/cell-grid assertions, plus full VHS-style .tape → GIF rendering pipeline

---

## 1. Recording/Replay Implementation Analysis

### 1.1 Recording Architecture

**Core component:** `SugarCraft\Vcr\Recorder`

The `Recorder` class implements `SugarCraft\Core\Recorder` interface, making it attachable to any `Program` via `Program::withRecorder()`. Key design decisions:

**Streaming JSONL with crash-safety** (`Recorder.php:13-14`):
- Each event is encoded and flushed immediately via `fwrite()` + `@fflush()`
- A crash mid-recording loses only the event currently being written, not the entire cassette
- This is critical for long-running recordings in CI or demo capture

**Event model** (`Recorder.php:246-291`):
```php
private function writeEvent(string $kind, array $payload): void {
    $realT = microtime(true) - $this->startTime;
    // idle-trim calculation...
    $effectiveT = round($realT - $this->cumulativeTrim, 3);
    
    $event = new Event(t: $effectiveT, kind: EventKind::from($kind), payload: $payload);
    
    // Hook processing (beforeSave can transform or suppress)
    $event = $this->hooks->beforeSave($event);
    if ($event === null) return;  // suppressed
    
    $line = ['t' => $event->t, 'k' => $event->kind->value, ...$event->payload];
    // Relative timestamp mode (dt field)...
    // Dual-timestamp for idle-trim (tRaw field)...
    $this->writeLine($line);
    $this->hooks->afterCapture($event);  // fire-and-forget
}
```

**Idle trimming** (`Recorder.php:250-256`):
```php
if ($this->idleTrimSec !== null && $this->hasPriorEvent) {
    $gap = $realT - $this->prevRealT;
    if ($gap > $this->idleTrimSec) {
        $newGap = min($this->idleTrimSec, $this->idleTrimCompressedMaxSec);
        $this->cumulativeTrim += ($gap - $newGap);
    }
}
```
- Inter-event gaps exceeding `$idleTrimSec` are compressed to `min(idleTrimSec, 0.5s)`
- Original wall-clock time preserved in `tRaw` field for `--no-trim` replay
- Cumulative trim tracked so subsequent events continue from compressed timeline

### 1.2 Replay Architecture

**Core component:** `SugarCraft\Vcr\Player`

The `Player` drives a fresh `Program` through a recorded `Cassette` and compares output via assertions:

**Event dispatch loop** (`Player.php:213-265`):
```php
$step = function () use (&$step, &$i, $events, ...) : void {
    if ($i >= $eventCount) return;
    
    $event = $events[$i];
    $i++;
    
    ($this->dispatchEvent($event, $program, $inputWrite, $registry, ...))();
    
    // Schedule next event
    if ($speed === self::SPEED_REALTIME) {
        $delta = max(0.0, $thisT - $prevT);
        if ($idleThreshold !== null && $delta > $idleThreshold) {
            $delta = $idleThreshold;  // clamp long pauses
        }
    } else {
        $delta = self::INSTANT_YIELD_SECONDS;  // 5ms yield
    }
    $loop->addTimer($delta, $step);
};
```

**Critical 5ms yield in INSTANT mode** (CALIBER_LEARNINGS.md:7):
- Without the yield, React's `StreamSelectLoop` fires all timers in the same tick before the program's framerate-based render tick
- `view()` is never rendered and no output is produced
- 5ms is enough for a 1kHz tickInterval to produce a frame

**Two speed modes:**
- `SPEED_INSTANT`: 5ms between events, fast for CI
- `SPEED_REALTIME`: Uses recorded `t` deltas, optionally clamped via `idleThresholdSeconds`

### 1.3 Input Event Handling

**Two input payload forms** (`Player.php:359-376`):

1. **Msg envelope form** (`payload['msg']`): Decoded via `Msg\Registry` serializer
   ```php
   $msg = $registry->decode($event->payload['msg']);
   $program->send($msg);
   ```

2. **Raw byte form** (`payload['b']`): Parsed through `InputReader` directly
   ```php
   $reader = new InputReader();
   foreach ($reader->parse($event->payload['b']) as $msg) {
       $program->send($msg);
   }
   ```
   - Bypasses the program's stream watcher to avoid async race between `fwrite` + `fread` + parse
   - This is the key insight for deterministic byte replay (CALIBER_LEARNINGS.md:9)

### 1.4 Cassette Format

**JSONL schema** (`docs/CASSETTE.md`):

```jsonl
{"v":1,"created":"2026-05-07T10:00:00Z","cols":80,"rows":24,"runtime":"sugarcraft/candy-core@1.0.0"}
{"t":0.000,"k":"resize","cols":80,"rows":24}
{"t":0.001,"k":"output","b":"\x1b[2J\x1b[H..."}
{"t":0.450,"k":"input","msg":{"@type":"KeyMsg","key":"j"}}
{"t":1.201,"k":"quit"}
```

**Event kinds** (`EventKind.php`):
- `resize` — terminal dimensions
- `input` — raw bytes (`b`) or msg envelope (`msg`)
- `output` — program output bytes (`b`)
- `quit` — session end
- `snapshot` — screenshot directive (render-side only)
- `hide`/`show` — cursor visibility control

**Dual timestamps** (`Recorder.php:281-286`):
```php
if ($this->cumulativeTrim > 0.0 && !isset($line['tRaw'])) {
    $line['tRaw'] = round($realT, 3);  // original wall-clock
}
// $line['t'] = compressed timeline (default for replay)
```

---

## 2. Timing Model

### 2.1 Absolute vs Relative Modes

**Absolute mode (default)** — `JsonlFormat`:
- `t` = seconds since cassette start (ms precision)
- Intuitive for playback timing, but editing requires re-shifting all subsequent `t` values

**Relative mode** — `RelativeFormat`:
- `dt` = interval since previous event
- Easier manual editing (change one event's timing without cascading changes)
- Self-describing via header's `timestampMode: "relative"`
- Auto-detected on replay by presence of `dt` vs `t` on first event

**Conversion** (`RelativeFormat.php:102-115` and `JsonlFormat.php:102-135`):
```php
// Absolute → Relative (on write)
$interval = round($event->t - $prevT, self::T_PRECISION);
$result[] = new Event(t: $interval, kind: $event->kind, payload: $event->payload);

// Relative → Absolute (on read)
$cumulative += $event->t;
$result[] = new Event(t: round($cumulative, self::T_PRECISION), ...);
```

### 2.2 Idle Trimming

**Recording side** (`Recorder.php:250-256`):
- Threshold configurable via `Recorder::withIdleTrim(?float $thresholdSec, float $compressedMaxSec = 0.5)`
- Gaps exceeding threshold compressed to `min(threshold, 0.5s)`
- Original time preserved in `tRaw`

**Replay side** (`Player.php:254-263`):
- `--idle-trim=N` clamps long pauses to N seconds for faster CI
- `--no-trim` honors `tRaw` (original cadence) over compressed `t`

### 2.3 Playback Speed

**Header-level** (`CassetteHeader.php:48-51`):
```php
public ?float $playbackSpeed  // e.g., 2.0 = 2x speed, 0.5 = half speed
```

**Applied in FrameStream** (`FrameStream.php:62-70`):
```php
if ($playbackSpeed !== null && $playbackSpeed > 0.0) {
    $scaledDelta = ($event->t - $previousEventTime) / $playbackSpeed;
    $virtualTime += $scaledDelta;
}
```

---

## 3. Determinism Guarantees

### 3.1 Byte-Exact Replay

**`ByteAssertion`** (`Assert/ByteAssertion.php`):
- Exact byte equality check
- Hex window diff starting at first divergence
- The strictest assertion — fails on any byte difference even if visually equivalent

**Gotcha** (CALIBER_LEARNINGS.md:10):
- `ByteAssertion::compare()` returns a hex window that **starts at the first-divergence offset**, not before it
- Tests expecting to see bytes preceding the divergence will fail

### 3.2 Cell-Grid Replay

**`ScreenAssertion`** (`Assert/ScreenAssertion.php:41-59`):
- Feeds expected/actual byte streams into separate `Terminal` instances
- Compares resulting cell grids via `Screen::diff()`
- Tolerates ANSI reorderings (redundant SGR re-emission, equivalent cursor moves, partial vs full repaints)
- Failure shows first 5 differing cells with (row, col) coordinates

### 3.3 Event Matchers

**`PassthroughMatcher`** — default, every event matches (assertion handles diff)  
**`ContentMatcher`** — matches on `(kind, payload)`, ignores timing  
**`TimingTolerantMatcher`** — matches when `|t_actual - t_recorded| ≤ tolerance` (default 100ms)

### 3.4 Program Lifecycle Requirements

**Critical requirement** (CALIBER_LEARNINGS.md:8):
- Replay `Program` must use the **same `ProgramOptions`** as the recording program
- Cassette captures setup AND teardown bytes (alt-screen enter/leave, mode resets)
- Any divergence in lifecycle options breaks `ByteAssertion`
- Build both Programs from a shared factory closure taking `(input, output, loop)`

### 3.5 Quit Event Placement

**Critical gotcha** (CALIBER_LEARNINGS.md:6):
```php
// WRONG: close recorder on QuitMsg
public function update(Msg $msg): array {
    if ($msg instanceof QuitMsg) {
        $recorder->close();  // teardown bytes lost!
    }
}

// RIGHT: close recorder only at end of Program::run() after loop stops
// teardownTerminal() runs AFTER the loop stops and emits bytes
// that must land in the cassette for replay byte-equality
```

**First resize skipped** (`Player.php:343-351`):
- Cassette's first resize is the recording program's startup size
- Replay program emits its own `WindowSizeMsg` from `tty.size()` lookup
- Dispatching cassette's first resize would duplicate it and throw msg counts off

---

## 4. CLI Design

### 4.1 Command Architecture

**`bin/candy-vcr`** (`bin/candy-vcr`):
- Portable autoload resolution loop (tries 5 candidate paths)
- Routes to `SugarCraft\Vcr\Cli\Application`

**`Application`** (`Cli/Application.php`):
- Custom `Command` interface for legacy commands
- Symfony `Command` for `render-tape`/`render-batch`
- `runSymfonyCommand()` bridges custom argv/stream to Symfony `InputInterface`/`OutputInterface`

### 4.2 Commands

| Command | Purpose | Key Options |
|---------|---------|-------------|
| `record` | PTY session capture | `--output`, `--cols`, `--rows`, `--no-ctty`, `--shell`, `--env`, `--env-regex`, `--idle-trim` |
| `inspect` | Dump cassette JSONL | `--since`, `--until`, `--frames` |
| `replay` | Stream output to stdout | `--speed=instant\|realtime`, `--idle-trim`, `--no-trim` |
| `diff` | Compare two cassettes | structural diff, exits non-zero on difference |
| `stats` | Cassette statistics | event tallies, duration, byte counts |
| `migrate` | Upgrade cassette format | `--dry-run`, in-place or explicit output |
| `render-tape` | `.tape` → `.gif` | `--output`, `--theme`, `--fps`, `--backend`, `--encoder`, `--strict`, `--dry-run` |
| `render-batch` | Batch render directory | `--output-dir`, `--recursive` |

### 4.3 Host TTY Safety Net

**RecordCommand** (`RecordCommand.php:358-376`):
- Puts host stdin into raw mode during recording
- `register_shutdown_function([RecordCommand::class, 'rescueRestore'])` — fires on every PHP shutdown including fatal errors
- `pcntl_signal(SIGTERM/SIGHUP/SIGINT, handler)` with `pcntl_async_signals(true)`
- Rescue marker file at `sys_get_temp_dir() . '/candy-vcr-rescue.<pid>'` with TTY device path
- `SIGKILL` cannot be intercepted (documented)

### 4.4 Env Capture

**Opt-in by default** (CALIBER_LEARNINGS.md:17):
```php
// SECRET_KEY_REGEX intentionally over-aggressive
public const SECRET_KEY_REGEX = '/(SECRET|TOKEN|KEY|PASSWORD|API|CRED|AUTH|PRIV)/i';
```
- Defaults to empty env
- Strips `KEYBOARD_LAYOUT` because it contains `KEY`
- `--env-allow-secrets` disables filtering (documented footgun)

---

## 5. Integration with candy-core

### 5.1 Program::withRecorder()

```php
(new Program($model))->withRecorder(Recorder::open('/tmp/session.cas'))->run();
```

The `Recorder` implements `SugarCraft\Core\Recorder` interface:
```php
interface Recorder {
    public function recordResize(int $cols, int $rows): void;
    public function recordInputBytes(string $bytes): void;
    public function recordOutput(string $bytes): void;
    public function recordQuit(): void;
    public function close(): void;
}
```

### 5.2 Msg Serialization

**`Msg\BuiltinSerializer`** — covers 19 Msg types:
- KeyMsg, MouseClickMsg, MotionMsg, WheelMsg, ReleaseMsg
- WindowSizeMsg, FocusGainedMsg, FocusLostMsg, BlurMsg, FocusInMsg, FocusOutMsg
- PasteStartMsg/EndMsg/Msg, BackgroundColorMsg, ForegroundColorMsg, CursorPositionMsg

**`Msg\JsonableSerializer`** — catch-all for any `Msg` implementing `\JsonSerializable`
- Tag is FQCN, `data` is `jsonSerialize()` result
- Round-trip works when constructor param names match `jsonSerialize()` keys

**`Msg\Registry`** — manages serializers:
```php
$registry->encode($msg);   // ['@type' => 'KeyMsg', ...] or null
$decoded = $registry->decode($envelope);  // Msg|null
```

### 5.3 Player Event Dispatch

**Uses `program->send()` directly** (CALIBER_LEARNINGS.md:9):
```php
// Bypasses program's stream watcher
$reader = new InputReader();
foreach ($reader->parse($event->payload['b']) as $msg) {
    $program->send($msg);  // direct delivery, no async race
}
```

### 5.4 VHS Replacement Pipeline

Full `.tape` → `.gif` pipeline via `TapeToGif`:

```
.tape → Lexer → Parser → Compiler → Cassette
    → Player → Terminal → Renderer → FrameStream → FrameDedup
    → Rasterizer → FfmpegGifEncoder → .gif
```

---

## 6. Overhead Considerations

### 6.1 Recording Overhead

**Benchmark** (CALIBER_LEARNINGS.md:326-334):
```
Pump WITHOUT recorder: ~47 ms
Pump WITH recorder:    ~40 ms  (within noise)
Measured overhead:    ≤2% per plan target
```

**Key optimizations:**
- Single conditional `recorder->recordOutput($bytes)` per master-read chunk
- No extra syscalls on hot path
- No per-chunk serialization beyond appending a JSON line to open stream

### 6.2 Replay Overhead

**`SPEED_INSTANT` mode:**
- 5ms yield between events is minimal
- Total replay time dominated by program's own processing

**`SPEED_REALTIME` mode:**
- Real-time delays inserted
- `idleThresholdSeconds` clamps long pauses for CI

### 6.3 Render Pipeline

**Glyph cache benchmark** (CALBER_LEARNINGS.md:235-243):
```
Rasterize-only:  enabled 0.0563s  disabled 0.0606s  speedup 1.08x (~7%)
End-to-end:      enabled 1.2832s  disabled 1.2889s  speedup 1.00x
Cache stats:     hits=9599  misses=6  hit-rate=99.9%
```
- End-to-end dominated by PHP GIF encoder
- Glyph cache provides ~7% rasterization speedup
- 99.9% hit rate — typical 80×24 grid has 1920 cells but only ~6 unique combinations

---

## 7. Extension Opportunities

### 7.1 Hook System

Already implemented:
- `Hook\Hook` interface: `beforeSave(Event): ?Event` (transform/suppress) + `afterCapture(Event): void` (fire-and-forget)
- `HookRegistry`: chains hooks in order, returning null from `beforeSave` suppresses event
- `SanitizingHook`: removes keys or replaces patterns via regex
- `MetadataHook`: injects CI metadata into first output event

### 7.2 Matchers

Already implemented:
- `PassthroughMatcher` — default, kind-only matching
- `ContentMatcher` — `(kind, payload)` equality, ignores timing
- `TimingTolerantMatcher` — ±50ms (configurable) timing tolerance

### 7.3 Assertions

Already implemented:
- `ByteAssertion` — strict byte equality
- `ScreenAssertion` — cell-grid equality (via candy-vt)
- `ContainsAssertion` — substring match
- `RegexAssertion` — PCRE pattern match

### 7.4 Format Extensions

**CompressedJsonlFormat** (`Format/CompressedJsonlFormat.php`):
- Gzipped JSONL, 5-10× smaller
- Streaming gzip with per-line flush
- Auto-detected via `.gz` extension

**YamlFormat** — human-editable fixtures  
**AsciinemaFormat** — import only (read `.cast` files)

### 7.5 Future Opportunities

| Feature | Description | Priority |
|---------|-------------|----------|
| **MsgPack binary format** | Compact binary cassette for large recordings | Lower |
| **SVG output** | term-transcript-style rendered frames | Lower |
| **Cassette append** | Resume recording on existing cassette | Lower |
| **Custom matcher scoring** | Partial match scoring for fuzzy assertion | Lower |

---

## 8. Comparison with Related Tools

### 8.1 vs charmbracelet/vhs (Go)

| Aspect | VHS (Go) | candy-vcr (PHP) |
|--------|----------|-----------------|
| Input | go-rod browser automation | Direct PTY byte pump |
| Output | FFmpeg encoding | PHP FFmpeg/blessed GIF encoder |
| Assertion | N/A | Byte + Screen + Contains + Regex |
| Hooks | N/A | Full beforeSave/afterCapture |
| Timing | .tape DSL | Dual-timestamp (t + tRaw) |
| Format | Binary | Streaming JSONL |

### 8.2 vs asciinema v3 (Rust)

| Aspect | asciinema v3 | candy-vcr |
|--------|--------------|-----------|
| Format | JSONL + gzip | JSONL + YAML + compressed |
| Timestamps | Relative only | Absolute + relative |
| Idle trim | Yes | Yes (dual-timestamp) |
| Env capture | Yes | Yes (opt-in) |
| Assertions | None | Byte + Screen + Contains + Regex |
| TUI replay | No | Yes (via Player) |

### 8.3 vs go-vcr/charmbracelet/x/vcr (Go, HTTP)

| Aspect | go-vcr (HTTP) | candy-vcr (TUI) |
|--------|---------------|-----------------|
| Domain | HTTP request/response | Terminal I/O |
| Matching | URL + method + body | Event kind + content |
| Hooks | BeforeSave, AfterCapture | Same |
| Cassette | YAML | JSONL |

---

## 9. File Index

| File | Purpose |
|------|---------|
| `src/Recorder.php` | Streaming JSONL writer with hook/trim support |
| `src/Player.php` | Event dispatch loop with SPEED_INSTANT/REALTIME |
| `src/Cassette.php` | Header + event list value object |
| `src/CassetteHeader.php` | Version, dimensions, runtime, env, timestamp mode |
| `src/Event.php` | Timestamp + kind + payload |
| `src/EventKind.php` | Enum: resize, input, output, quit, snapshot, hide, show |
| `src/ReplayResult.php` | DTO: ok, diff, event counts |
| `src/Assert/ByteAssertion.php` | Strict byte equality with hex window diff |
| `src/Assert/ScreenAssertion.php` | Cell-grid equality via candy-vt Terminal |
| `src/Assert/ContainsAssertion.php` | Substring match assertion |
| `src/Assert/RegexAssertion.php` | PCRE pattern assertion |
| `src/Matcher/EventMatcher.php` | Interface for event matching |
| `src/Matcher/PassthroughMatcher.php` | Default, kind-only |
| `src/Matcher/ContentMatcher.php` | Kind + payload equality |
| `src/Matcher/TimingTolerantMatcher.php` | ±N second tolerance |
| `src/Format/JsonlFormat.php` | Primary streaming format |
| `src/Format/RelativeFormat.php` | Delta timestamps (asciinema-style) |
| `src/Format/CompressedJsonlFormat.php` | Gzipped JSONL |
| `src/Format/YamlFormat.php` | Human-editable fixtures |
| `src/Format/AsciinemaFormat.php` | Import asciinema v3 cast files |
| `src/Format/CassetteLoader.php` | Auto-detection via extension + content sniff |
| `src/Hook/Hook.php` | Interface: beforeSave/afterCapture |
| `src/Hook/HookRegistry.php` | Hook chain manager |
| `src/Hook/SanitizingHook.php` | Key removal / regex replacement |
| `src/Hook/MetadataHook.php` | CI metadata injection |
| `src/Msg/Registry.php` | Msg serializer registry |
| `src/Msg/BuiltinSerializer.php` | 19 built-in Msg types |
| `src/Msg/JsonableSerializer.php` | \JsonSerializable catch-all |
| `src/Cli/Application.php` | Subcommand router |
| `src/Cli/RecordCommand.php` | PTY recording with safety net |
| `src/Cli/ReplayCommand.php` | Output streaming replay |
| `src/Cli/InspectCommand.php` | Cassette JSONL dumper |
| `src/Cli/DiffCommand.php` | Structural cassette comparison |
| `src/Cli/StatsCommand.php` | Event tallies + duration |
| `src/Cli/RenderTapeCommand.php` | .tape → .gif (Symfony) |
| `src/Cli/RenderBatchCommand.php` | Batch .tape → .gif (Symfony) |
| `src/Cli/MigrateCommand.php` | Cassette v1 → v2 migration |
| `src/Tape/Lexer.php` | .tape tokenizer |
| `src/Tape/Parser.php` | Token → AST |
| `src/Tape/Compiler.php` | AST → Cassette |
| `src/Tape/Decompiler.php` | Cassette → tape source (round-trip) |
| `src/Render/Renderer.php` | Player + Terminal orchestration |
| `src/Render/FrameStream.php` | Lazy Snapshot iterator at fps cadence |
| `src/Render/FrameDedup.php` | Collapse identical adjacent frames |
| `src/Raster/Rasterizer.php` | Interface: Snapshot → PNG |
| `src/Raster/GdRasterizer.php` | ext-gd backend |
| `src/Raster/ImagickRasterizer.php` | ext-imagick backend |
| `src/Raster/Glyphs.php` | Per-(char,fg,bg,bold,italic,underline) tile cache |
| `src/Raster/FontLoader.php` | TTF path resolver with bundled fonts |
| `src/Encode/GifEncoder.php` | Interface: Iterator → GIF |
| `src/Encode/FfmpegGifEncoder.php` | FFmpeg two-pass VFR encoder |
| `src/Encode/PhpGifEncoder.php` | Pure-PHP LZW fallback |
| `src/Encode/TapeToGif.php` | Full .tape → .gif pipeline |
| `src/Migration/CassetteMigrator.php` | Migration plugin interface |
| `src/Migration/V1ToV2Migrator.php` | v1 → v2 upgrade |

---

*Report compiled: 2026-05-27*
