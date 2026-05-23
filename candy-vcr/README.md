# CandyVcr

PHP port of [`charmbracelet/x/vcr`](https://github.com/charmbracelet/x/tree/main/vcr).

Records every Msg fed into a candy-core `Program` and every frame emitted by
`view()`, with timing, into a cassette file. Replays cassettes by feeding the
recorded Msgs back at recorded cadence and asserting frames match (cell-grid
equality via [candy-vt](../candy-vt/), with byte-equality fallback).

## Status

🟢 **v1 ready** — all 7 PRs merged. See [`plans/x-vcr.md`](../plans/x-vcr.md) for the slice history.

| PR | Scope |
|----|-------|
| PR1 | Cassette + Event + JsonlFormat |
| PR2 | Recorder + `Program::withRecorder()` |
| PR3 | Msg serializers — Builtin + Jsonable + Registry |
| PR4 | Player + ByteAssertion + ReplayResult |
| PR5 | ScreenAssertion via candy-vt |
| PR6 | YamlFormat |
| PR7 | `bin/candy-vcr` CLI + examples + tracking |
| PR8 | Tape lexer/parser/compiler (`.tape` → Cassette) |
| PR9 | Renderer + FrameStream + FrameDedup (Phase 3 of vhs-replacement) |
| PR10 | Raster + Glyphs + FontLoader (Phase 4 of vhs-replacement) |
| PR11 | GIF encoder — GifEncoder interface + FfmpegGifEncoder + PhpGifEncoder + TapeToGif (Phase 5 of vhs-replacement) |

## Use cases

- **Bug repro** — user runs `--record bug.cas`, ships the cassette,
  maintainer replays locally.
- **Regression tests** — record a known-good session, replay in CI, diff
  against expected screen state.
- **Demo capture** — alternative to VHS for headless / scriptable
  recordings (no docker, runs in PHP unit-test process).
- **Fuzzing seeds** — mutate recorded Msgs slightly, replay to find edge
  cases.

## Install

```sh
composer require sugarcraft/candy-vcr
```

## Cassette format (JSONL)

> **Full schema reference:** [`docs/CASSETTE.md`](docs/CASSETTE.md) — includes
> dual-timestamp (`t` + `tRaw`) format, all event kinds, and the header
> structure.

```jsonl
{"v":1,"created":"2026-05-07T10:00:00Z","cols":80,"rows":24,"runtime":"sugarcraft/candy-core@1.0.0"}
{"t":0.000,"k":"resize","cols":80,"rows":24}
{"t":0.001,"k":"output","b":"[2J[H..."}
{"t":0.450,"k":"input","msg":{"@type":"KeyMsg","key":"j"}}
{"t":1.201,"k":"quit"}
```

- Line 1 is the header (carries `v`).
- Subsequent lines are events keyed by `k` (`resize`, `input`, `output`,
  `quit`) with kind-specific payload fields.
- `t` is seconds since cassette start (ms precision).

### Formats

| Format | File extension | Description |
|--------|---------------|-------------|
| `JsonlFormat` | `.cas` | Absolute timestamps (`t`). Default. |
| `RelativeFormat` | `.cas` | Relative/delta timestamps (`dt`). Deterministic replay. |
| `AsciinemaFormat` | `.cast` | Import asciinema v3 cast files. |
| `YamlFormat` | `.yaml` | Human-readable YAML (test fixtures). |
| `CompressedJsonlFormat` | `.cas.gz` | Gzip-compressed JSONL (5–10× smaller). |

All formats share the same `Cassette` value-object model and are
interchangeable via the `Format` interface. `Player::open()` auto-detects
`RelativeFormat` vs `JsonlFormat` from the first event line.

### Timestamp modes

Cassettes support two timestamp modes:

| Mode | Field | Use case |
|------|-------|----------|
| `absolute` (default) | `t` = seconds since cassette start | Playback timing |
| `relative` | `dt` = interval since previous event (like asciinema v3) | Deterministic replay; easier manual editing |

Select the mode at record time via `Recorder::withFormat()`:

```php
use SugarCraft\Vcr\Recorder;
use SugarCraft\Vcr\Format\RelativeFormat;

// Relative timestamps (delta between events)
$recorder = Recorder::open('/tmp/session.cas')
    ->withFormat(new RelativeFormat());

(new Program($model))
    ->withRecorder($recorder)
    ->run();
```

The `Player::open()` factory auto-detects which format a cassette uses by
examining the first event line — `dt` field means RelativeFormat, `t` field
means JsonlFormat. No format parameter needed on replay.

**Absolute mode example:**
```jsonl
{"t":0.000,"k":"output","b":"$ "}
{"t":0.500,"k":"output","b":"ls\r\n"}
{"t":0.502,"k":"output","b":"file1.txt file2.txt\r\n"}
```

**Relative mode example (same events):**
```jsonl
{"dt":0.000,"k":"output","b":"$ "}
{"dt":0.500,"k":"output","b":"ls\r\n"}
{"dt":0.002,"k":"output","b":"file1.txt file2.txt\r\n"}
```

The header carries `timestampMode` so the format is self-describing.
Backwards compatibility is preserved — cassettes without a `timestampMode`
key default to `absolute`.

### Gzip compression

Cassettes can be gzip-compressed by using the `.gz` extension or by using
`CompressedJsonlFormat` directly:

```php
use SugarCraft\Vcr\Format\CompressedJsonlFormat;

$format = new CompressedJsonlFormat();

// Write compressed cassette
$format->write($cassette, '/tmp/session.cas.gz');

// Read compressed cassette (auto-detects .gz extension)
$cassette = $format->read('/tmp/session.cas.gz');
```

 Compressed cassettes are typically 5-10x smaller than plain JSONL,
 making them suitable for CI storage and git repositories. The format
 uses streaming gzip with per-line flush to maintain memory efficiency
 for large cassettes.

 ## Asciinema import (L2)

 Import asciinema v3 cast files as candy-vcr Cassettes for replay:

 ```php
 use SugarCraft\Vcr\Format\AsciinemaFormat;

 $cassette = (new AsciinemaFormat())->read('/path/to/session.cast');
 $player = new Player($cassette);
 $result = $player->play(programFactory: $factory, speed: Player::SPEED_REALTIME);
 ```

 The importer handles asciinema v3's relative timestamps, converts `o` (stdout)
 events to output events, `i` (stdin) events to input events, and `x` (exit)
 events to quit events.

 ## Quickstart

Record a session:

```php
use SugarCraft\Core\Program;
use SugarCraft\Vcr\Recorder;

(new Program($model))
    ->withRecorder(Recorder::open('/tmp/session.cas'))
    ->run();
// cassette is closed automatically on QuitMsg
```

Read a recorded cassette back:

```php
use SugarCraft\Vcr\Format\JsonlFormat;

$cassette = (new JsonlFormat())->read('/tmp/session.cas');
foreach ($cassette->events as $event) {
    echo $event->kind->value, ' @ ', $event->t, "\n";
}
```

The CLI lands in PR7.

## CLI

```sh
vendor/bin/candy-vcr record       --output session.cas -- bash -c 'echo hi'  # capture a real PTY session
vendor/bin/candy-vcr inspect      session.cas                                # list events
vendor/bin/candy-vcr replay       session.cas --speed=realtime               # stream output to stdout
vendor/bin/candy-vcr replay       session.cas --idle-trim=1.0                # clamp long gaps to 1s during replay
vendor/bin/candy-vcr diff         a.cas b.cas                                # structural diff
vendor/bin/candy-vcr stats        session.cas                                # show cassette statistics
vendor/bin/candy-vcr render-tape demo.tape                                 # render .tape to .gif
vendor/bin/candy-vcr render-batch demos/                                     # render all .tape files in directory
```

`record` (PR P6.5.1) spawns the given command under a fresh master/slave PTY, drops the host stdin into raw mode, runs the candy-pty byte pump with a `Recorder` tee'd onto every stdin/master-output chunk, and writes a `session-<timestamp>.cas` cassette (override with `--output PATH`). The recorded child gets a controlling terminal by default so Ctrl+C reaches it (use `--no-ctty` to disable); the host termios is restored on every exit path including thrown exceptions. The cassette can then be replayed via `vendor/bin/candy-vcr replay …` or loaded by tests through `Player::play()`.

`inspect` shows each event's timestamp, kind, and a short payload summary (with `--since=<seconds>` / `--until=<seconds>` filters). `replay` streams the cassette's recorded output bytes to stdout — `--speed=realtime` honours the recorded cadence (use it for visual demos), `--speed=instant` flushes everything as fast as the kernel will accept it. `diff` compares headers + per-event payloads and exits non-zero on any difference. `stats` prints event tallies by kind, total duration, input message type breakdown, and output byte counts with per-event averages.

### Recording commands

```sh
vendor/bin/candy-vcr record -- vim /tmp/scratch
vendor/bin/candy-vcr record --output bash-session.cas --cols 132 --rows 40 -- bash -l
vendor/bin/candy-vcr record --no-ctty -- /bin/echo 'hello, world'   # non-interactive child, no Ctrl+C wiring
vendor/bin/candy-vcr record --shell                                  # spawn $SHELL -l (or /bin/sh -l)
vendor/bin/candy-vcr record --env -- bash -c 'echo hi'              # capture filtered host env into cassette header
vendor/bin/candy-vcr record --idle-trim 1.0 -- bash demo.sh         # compress idle gaps > 1s (asciinema-style trim)
vendor/bin/candy-vcr replay  --no-trim session.cas --speed=realtime # restore real cadence on a trimmed cassette
```

Roughly equivalent to `asciinema rec` / charmbracelet's `shirley`, but writes the candy-vcr JSONL cassette so the existing inspect / replay / diff / stats commands and the `Player::play()` API work without conversion. Subsequent plan steps will layer in `--idle-trim` (P6.5.3) and a host-termios safety net via `register_shutdown_function` + signal handlers (P6.5.4).

#### `--shell` (PR P6.5.2)

Spawn the user's `$SHELL -l` (falling back to `/bin/sh -l` when `$SHELL` is empty or non-executable) instead of an explicit positional command. Useful for "capture what my prompt does" demos without enumerating the shell binary every time. Mutually exclusive with positional `<cmd>`.

#### `--env` and `--env-regex=PATTERN` (PR P6.5.2)

Env capture is **opt-in** — `--env` snapshots the host environment into the cassette header. By default, keys matching the conservative secret-name regex `/(SECRET|TOKEN|KEY|PASSWORD|API|CRED|AUTH|PRIV)/i` are stripped before they hit disk. The bias is "rather strip-too-much than leak" — `KEYBOARD_LAYOUT` is stripped because it contains `KEY`. Override the regex with `--env-regex=PATTERN` when you need a narrower (or wider) filter; passing `--env-regex` implies `--env`.

#### `--env-allow-secrets` (PR P6.5.2)

**DANGEROUS — for trusted, isolated environments only.** When this flag is set, secret-key filtering is disabled entirely and the cassette will contain credential values verbatim (API tokens, passwords, private keys, etc.). Only use this flag when recording in a fully isolated environment and you understand that the resulting cassette must never be shared or stored in an untrusted location.

```sh
vendor/bin/candy-vcr record --env-allow-secrets -- bash -c 'echo $GITHUB_TOKEN'
# GITHUB_TOKEN value is now in the cassette in plain text
```

Captured env lands on the cassette header as a JSON object:

```jsonl
{"v":1,"created":"...","cols":80,"rows":24,"runtime":"sugarcraft/candy-vcr@record","env":{"HOME":"/home/me","LANG":"en_US.UTF-8","PATH":"/usr/bin:/bin","TERM":"xterm-256color"}}
```

`Recorder::filteredHostEnv(string $regex = SECRET_KEY_REGEX): array<string,string>` is the public helper invoked under the hood; tests can drive it directly without spawning a child.

#### `--idle-trim N` and `replay --no-trim` (PR P6.5.3)

Borrowed from asciinema, idle-trim compresses long inter-event gaps so a 30-second `make build` doesn't take 30 seconds to replay. When the gap between consecutive events exceeds `N` seconds, the recorder writes the event with both `t` (the compressed timestamp) and `tRaw` (the original wall-clock timestamp). The compressed gap defaults to 0.5 s (or `N`, whichever is smaller).

```jsonl
{"v":1,"created":"...","cols":80,"rows":24,"runtime":"sugarcraft/candy-vcr@record"}
{"t":0,"k":"output","b":"pre\r\n"}
{"t":0.5,"k":"output","b":"post\r\n","tRaw":1.234}
{"t":0.515,"k":"quit","tRaw":1.249}
```

Replay defaults to the compressed timeline. Pass `--no-trim` to replay to honour `tRaw` instead — useful when the original cadence matters (demos, race-condition repros). Events without `tRaw` (older cassettes, or untrimmed events) replay using `t`, so the format stays backward-compatible. The `Player::play(... useRawTimestamps: true)` flag exposes the same behaviour to PHP callers.

### Host TTY safety net (PR P6.5.4)

`record` puts the host stdin into raw mode while the recorded program runs. The in-band `finally` restores it on every PHP-controlled exit path (clean exit, exception). For exits that bypass `finally` — SIGTERM, SIGHUP, fatal errors — the command installs:

- `register_shutdown_function([RecordCommand::class, 'rescueRestore'])` — fires on every PHP run shutdown, including fatal errors.
- `pcntl_signal(SIGTERM/SIGHUP, [RecordCommand::class, 'handleRescueSignal'])` with `pcntl_async_signals(true)` — restores then re-raises the signal with the default handler so the process still dies with the right status.

`SIGKILL` cannot be intercepted by anything. As a mitigation, while recording is in flight the command drops a marker file at `sys_get_temp_dir() . '/candy-vcr-rescue.<pid>'` containing the host TTY's device path (resolved via `posix_ttyname(STDIN)`). If a hard kill leaves your terminal stuck in raw mode, run `stty sane < /path/to/your/tty` (you'll find the path in the marker file, which is cleaned up on every clean exit).

The static handlers are signal-safe (no allocation, no logging) and idempotent; calling `rescueRestore()` twice in a row is a no-op.

### Recording overhead (PR P6.5.6)

The PosixPump recorder tap (PR P6.1) is a single conditional `recorder->recordOutput($bytes)` call per master-read chunk — no extra syscalls on the hot path, no per-chunk serialization beyond appending a JSON line to the open cassette stream.

Benchmark (`tests/Integration/ShirleyOverheadTest.php`, median of 5 timed runs after a warmup, `time bash -c 'seq 100000'`):

| Scenario | Median wallclock |
|----------|------------------|
| Pump WITHOUT recorder | ~47 ms |
| Pump WITH recorder | ~40 ms |
| Measured overhead | **within noise (≤2% per plan target)** |

The CI bound is set to 5 % to absorb shared-runner jitter while still catching the regression class this test exists to flag (a real serialization-per-chunk regression would land at dozens of percent).

### Hook system (L4)

Hooks intercept and transform events during recording, enabling sanitization,
metadata injection, and custom logging:

```php
use SugarCraft\Vcr\Recorder;
use SugarCraft\Vcr\Hook\SanitizingHook;
use SugarCraft\Vcr\Hook\MetadataHook;

$recorder = Recorder::open('/tmp/session.cas');

// Remove sensitive keys from all events
$recorder->withHook(new SanitizingHook(
    removeKeys: ['API_KEY', 'SECRET_TOKEN'],
));

// Add CI metadata to the first output event
$recorder->withHook(new MetadataHook([
    'CI_RUN_ID' => getenv('GITHUB_RUN_ID'),
    'test_name' => 'MyTest::testViewOutput',
]));

(new Program($model))->withRecorder($recorder)->run();
```

**Available hooks:**
- `SanitizingHook` — removes keys or replaces patterns via regex
- `MetadataHook` — injects metadata into the first output event
- Custom hooks implement `SugarCraft\Vcr\Hook\Hook`

### Rendering `.tape` files to GIF (PR12)

The `render-tape` and `render-batch` commands convert `.tape` files to animated GIFs. They use the `TapeToGif` pipeline: Lexer → Parser → Compiler → Player → Terminal → Renderer → FrameStream → FrameDedup → Rasterizer → GifEncoder.

```sh
# Render a single .tape file
vendor/bin/candy-vcr render-tape demo.tape

# Custom output path
vendor/bin/candy-vcr render-tape demo.tape -o output.gif

# Specify theme and fps
vendor/bin/candy-vcr render-tape demo.tape --theme Dracula --fps 20

# Use imagick backend instead of gd
vendor/bin/candy-vcr render-tape demo.tape --backend imagick

# Use pure-PHP encoder (fallback when ffmpeg unavailable)
vendor/bin/candy-vcr render-tape demo.tape --encoder php

# Strict mode — fail on unknown directives
vendor/bin/candy-vcr render-tape demo.tape --strict

# Batch render all .tape files in a directory
vendor/bin/candy-vcr render-batch demos/

# Batch render recursively
vendor/bin/candy-vcr render-batch demos/ --recursive

# Custom output directory for batch
vendor/bin/candy-vcr render-batch demos/ -o output-gifs/
```

#### `render-tape` options

| Option | Short | Description |
|--------|-------|-------------|
| `--output` | `-o` | Output .gif path (default: same as input with .gif extension) |
| `--font` | `-f` | TTF font family name (default: JetBrainsMono) |
| `--theme` | `-t` | Theme name (default: TokyoNight). Options: TokyoNight, TokyoNightLight, TokyoNightStorm, Dracula, SolarizedDark |
| `--fps` | | Frames per second (default: 30) |
| `--backend` | `-b` | Rasterizer backend: `gd` (default) or `imagick` |
| `--encoder` | `-e` | GIF encoder: `ffmpeg` (default) or `php` |
| `--strict` | | Error on unknown directives instead of skipping |

#### `render-batch` options

Same as `render-tape` plus:

| Option | Short | Description |
|--------|-------|-------------|
| `--output-dir` | `-o` | Output directory for .gif files (default: same as source dir) |
| `--recursive` | `-r` | Search recursively for .tape files |

#### GIF encoding pipeline

The `TapeToGif` class wires together the full render pipeline:

1. **Parsing** — Lexer tokenizes the `.tape` source, Parser produces an AST
2. **Compilation** — Compiler converts the AST into a Cassette with timed events
3. **Rendering** — Player drives the cassette events into a Terminal, Renderer produces frames via FrameStream
4. **Deduplication** — FrameDedup collapses visually identical adjacent frames
5. **Rasterization** — Rasterizer converts each Snapshot to a PNG using glyph tiles
6. **Encoding** — FfmpegGifEncoder produces the final GIF (or PhpGifEncoder as fallback)

Frame hold durations are tracked through the dedup stage and passed to the encoder for accurate VFR (variable frame rate) timing.

### Cassette migration

Cassette format versions evolve over time. The `migrate` command upgrades cassettes automatically:

```sh
# Migrate in-place (creates session.cas.bak backup)
candy-vcr migrate session.cas

# Migrate to a new file
candy-vcr migrate session.cas upgraded.cas

# Dry-run — validate without writing
candy-vcr migrate session.cas --dry-run

# Show registered migrators
candy-vcr migrate --info
```

The migration system is pluggable via `SugarCraft\Vcr\Migration\CassetteMigrator`.
`V1ToV2Migrator` upgrades v1 cassettes by adding sequential event IDs, explicit
encoding metadata on output events, and other structural improvements. Future version
migrators slot in without modifying the core infrastructure.

## Tape compiler (PR8)

candy-vcr ships a `SugarCraft\Vcr\Tape` layer that parses `.tape` files (the
VHS DSL) into a `Cassette` that the existing `Player` can replay. This
decouples the render pipeline (Phase 3+) from the tape format.

```php
use SugarCraft\Vcr\Tape\Compiler;

$source = file_get_contents('demo.tape');
$result = Compiler::parseSource($source);

if (!empty($result['errors'])) {
    foreach ($result['errors'] as $error) {
        echo $error->getLine(), ': ', $error->getMessage(), "\n";
    }
    exit(1);
}

$cassette = (new Compiler())->compile($result['ast'], 'demo.tape');
// Feed to Player::play() for replay...
```

**Supported directives:**

| Directive | Supported | Notes |
|---|---|---|
| `Type "..."` | ✅ | Each char emits a KeyMsg at TypingSpeed cadence |
| `Enter`, `Tab`, `Backspace` | ✅ | Raw bytes: `\r`, `\t`, `\x7f` |
| `Space`, `Escape` | ✅ | Raw bytes: ` `, `\x1b` |
| `Up`, `Down`, `Left`, `Right` | ✅ | CSI sequences: `\x1b[A` etc. |
| `Ctrl+<letter>` | ✅ | Control character (char & 0x1F) |
| `Sleep <duration>` | ✅ | Advances virtual clock only |
| `Set Width/Height` | ✅ | Sets cassette header cols/rows |
| `Set Theme` | ✅ | Sets theme name in header |
| `Set TypingSpeed` | ✅ | Typing cadence (ms per keystroke) |
| `Env KEY "value"` | ✅ | Adds to cassette header env |
| `Output <path>` | ✅ | Accepted (stored for render step) |
| `Hide`, `Show` | ⚠️ | Parsed, no-op (deferred to v2) |
| `Wait <duration>` | ⚠️ | Parsed, no-op (deferred to v2) |
| `Screenshot <path>` | ⚠️ | Parsed, no-op (deferred to v2) |
| `Screen /regex/` | ⚠️ | Parsed, ignored (deferred to v2) |

The `Compiler::compile()` method produces a `Cassette` with a `CassetteHeader`
carrying the configured cols/rows/theme/env and a list of `Event` objects
typed as `EventKind::Input` with raw bytes (`['b' => string]`) payloads.
`Sleep` directives advance the virtual clock without emitting events, so
inter-event timing is preserved for the Player.

**Corpus coverage:** All 841+ `.tape` files in the monorepo parse without
error and compile to valid Cassettes (verified by `TapeCorpusTest`).

## Frame renderer (PR9)

candy-vcr ships a `SugarCraft\Vcr\Render` layer that converts a compiled
`Cassette` into a stream of terminal `Snapshot` frames at configurable fps,
with optional deduplication of identical adjacent frames:

```php
use SugarCraft\Vcr\Player;
use SugarCraft\Vcr\Render\Renderer;
use SugarCraft\Vcr\Render\FrameDedup;
use SugarCraft\Vt\Terminal;

// Open a cassette (from tape compiler or direct record)
$player = Player::open('demo.cas');

// Create terminal emulator and renderer
$terminal = Terminal::new(80, 24);
$renderer = new Renderer($player, $terminal, fps: 30.0);

// Get frame stream and optionally dedup identical frames
$stream = $renderer->render($player, $terminal, 30.0);
$deduped = FrameDedup::dedup($stream);

foreach ($deduped as $index => $snapshot) {
    // $snapshot is a SugarCraft\Vt\Snapshot with grid + cursor + time
    printf("Frame %d at t=%.3f\n", $index, $snapshot->time);
}
```

**Key classes:**

| Class | Role |
|---|---|
| `Renderer` | Orchestrates Player + Terminal; produces `FrameStream` |
| `FrameStream` | `\IteratorAggregate` yielding `Snapshot` at fps cadence |
| `FrameDedup` | Static filter collapsing identical adjacent frames |

**Frame dedup:** Typical terminal recordings have 80–95% identical frames
(e.g., cursor blink, idle time between keystrokes). `FrameDedup::dedup()`
collapses consecutive identical frames into a single frame, reducing
downstream GIF encoder work significantly. The `holdMax` parameter (default
300) caps how many identical frames can be collapsed to prevent pathological
cases.

**Snapshot equality:** Two `Snapshot` objects are equal when their `grid`
and `cursor` state match, regardless of capture time. This enables frame
dedup across different virtual timestamps. The `equalsWithTime()` method
compares all three fields (grid, cursor, time) for exact reproducibility
checks.

**Performance note:** Cell equality comparison is O(cols × rows) per frame —
for a typical 80×24 terminal that's 1920 cell comparisons. At 30fps with dedup
disabled, that's ~57,600 cell comparisons per second. This is acceptable
for now (Phase 3) but is a known bottleneck for optimization in Phase 4.

## Frame rasterizer (Phase 4)

The `SugarCraft\Vcr\Raster` namespace converts terminal `Snapshot` frames
into PNG images for GIF encoding:

```php
use SugarCraft\Vcr\Raster\GdRasterizer;
use SugarCraft\Vcr\Raster\FontLoader;

$rasterizer = new GdRasterizer(fontSize: 14, fontFamily: 'DejaVuSansMono');

$png = $rasterizer->rasterize($snapshot, cellW: 8, cellH: 16);

$pngData = stream_get_contents(fopen('php://memory', 'r+'), null, 0);
imagepng($png);
imagedestroy($png);
```

**Cell metrics (FontSize 14, JetBrainsMono / DejaVuSansMono):**
```
  cellW = 8px, cellH = 16px
  80×24 terminal → 640×384 px
  120×40 terminal → 960×640 px
```

**Architecture:**

| Class | Role |
|---|---|
| `FontLoader` | Resolves TTF font paths from `fonts/` bundle + system dirs |
| `Glyphs` | Per-(char, fg, bg, bold, italic, underline) tile cache — the performance key |
| `Rasterizer` | Interface: `rasterize(Snapshot, cellW, cellH, ?FontLoader): GdImage\|Imagick` |
| `GdRasterizer` | Default ext-gd backend; blits tiles + renders cursor |
| `ImagickRasterizer` | ext-imagick alternative; better anti-aliasing |

**Bundled fonts:** `fonts/JetBrainsMono-{Regular,Bold,Italic,BoldItalic}.ttf`
(default family) plus `DejaVuSansMono.ttf` and `DejaVuSansMono-Bold.ttf` as a
fallback. FontLoader tries the `fonts/` dir first, then system font directories
(`/usr/share/fonts/`, `~/.fonts/`, etc.). See the [Fonts](#fonts) section under
Development for licensing and override details.

**Glyphs cache:** Typical terminal frames have thousands of cells but only ~50
unique (char, attrs) combinations. The tile cache makes rasterization O(unique
tiles) instead of O(cells). Cache key: `"$char|$fg|$bg|$bold|$italic|$underline"`.

**Wide chars:** CJK and fullwidth characters get a 2×-wide tile; the rasterizer
advances 2 columns after blitting. Checked via `mb_strwidth($char) > 1`.

**Cursor shapes:**
- Block (shape=1): glyph rendered in reverse-video (fg/bg swapped)
- Underline (shape=2): filled rect at y = cellH × 0.75
- Bar (shape=3): narrow filled rect at left edge

## GIF encoder (Phase 5)

The `SugarCraft\Vcr\Encode` namespace converts a stream of rasterized PNG frames
into an animated GIF:

```php
use SugarCraft\Vcr\Encode\FfmpegGifEncoder;
use SugarCraft\Vcr\Encode\TapeToGif;

$tapeToGif = TapeToGif::create(['encoder' => 'ffmpeg']);
$tapeToGif->render('demo.tape', 'demo.gif');
```

**Pipeline:** `.tape` → Lexer → Parser → Compiler → Cassette → Player → Terminal → Renderer → FrameStream → FrameDedup → Rasterizer → FfmpegGifEncoder → `.gif`

**Encoders:**

| Encoder | Description |
|--------|-------------|
| `FfmpegGifEncoder` | Default; uses ffmpeg with two-pass palette generation. CFR via `-framerate`; VFR via concat demuxer with process substitution. |
| `PhpGifEncoder` | Pure-PHP fallback; stub that throws `RuntimeException`. LZW encoding in pure PHP is 5-10× slower than ffmpeg. |

**VFR (Variable Frame Rate):** When `frameHolds` differ between frames, FfmpegGifEncoder writes a concat demuxer file and pipes it to ffmpeg's stdin:

```
file 'frame00000.png'
duration 0.033
file 'frame00001.png'
duration 0.100
...
file 'frame00004.png'
duration 0.033
file 'frame00004.png'
```

The last frame is listed twice to give it a display duration (the entry before it carries the duration). This produces accurate per-frame timing without re-encoding artifacts.

**Two-pass palette:** `palettegen=stats_mode=diff` computes an optimal 256-color palette by analyzing frame-to-frame pixel differences. `paletteuse=dither=bayer:bayer_scale=5` applies the palette with ordered dithering. This produces significantly better quality than single-pass GIF encoding.

**TapeToGif options:**

```php
$tapeToGif->render($tapePath, $outputPath, [
    'fps'      => 30.0,        // frames per second
    'theme'    => 'TokyoNight', // theme name
    'fontSize' => 14,           // terminal font size
    'backend'  => 'gd',        // 'gd' (default) or 'imagick'
    'encoder'  => 'ffmpeg',   // 'ffmpeg' (default) or 'php'
]);
```

**Requirements:** `ffmpeg` must be in `$PATH` for `FfmpegGifEncoder`. The `symfony/process` package is required as a runtime dependency.

## Examples

`examples/record.php`, `examples/replay.php`, `examples/inspect.php` — runnable scripts using a tiny `CounterModel`. The `examples/cassettes/counter.cas` fixture is a real recording you can play with:

```sh
php examples/record.php examples/cassettes/counter.cas
bin/candy-vcr inspect examples/cassettes/counter.cas
bin/candy-vcr stats   examples/cassettes/counter.cas
bin/candy-vcr replay  examples/cassettes/counter.cas --speed=realtime
php examples/replay.php examples/cassettes/counter.cas
```

### Replay (PR4)

```php
use SugarCraft\Vcr\Player;
use SugarCraft\Vcr\Assert\ByteAssertion;

$player = Player::open('/tmp/session.cas');
$result = $player->play(
    programFactory: fn ($input, $output, $loop) => new Program(
        new MyModel(),
        new ProgramOptions(
            useAltScreen: false, catchInterrupts: false, hideCursor: false,
            input: $input, output: $output, loop: $loop,
        ),
    ),
    assertion: new ByteAssertion(),
    speed: Player::SPEED_INSTANT,  // or SPEED_REALTIME for demo replay
);

if (!$result->ok) {
    echo $result->diffSummary();
    exit(1);
}
```

`Player::play` walks the cassette and feeds each event into the program: resize → `WindowSizeMsg`, input bytes → re-parsed via `InputReader` and dispatched, input msg envelope → decoded via the serializer registry, quit → `program->quit()`. Output events accumulate into the expected byte buffer; the program's actual output stream is captured and compared via the supplied assertion.

**Idle time trimming:** In `SPEED_REALTIME` mode, long pauses between events can slow down CI tests. Set `idleThresholdSeconds: 0.5` (or `withIdleTrim(0.5)` on the `Player`) to clamp pauses longer than 500 ms to 500 ms, making tests run faster while still honoring shorter pauses. The fluent `withIdleTrim()` form is useful when the threshold is configured once at the call site:

```php
$result = $player->withIdleTrim(0.5)->play(
    programFactory: $factory,
    speed: Player::SPEED_REALTIME,
);
// Or pass it explicitly to play():
$result = $player->play(
    programFactory: $factory,
    speed: Player::SPEED_REALTIME,
    idleThresholdSeconds: 0.5,  // Skip long pauses in CI
);
```

`ByteAssertion` is the strict baseline — exact byte equality with a hex-and-printable diff window on failure. `ScreenAssertion` (cell-grid equality via [candy-vt](../candy-vt/)) is the recommended choice for round-trip tests:

```php
use SugarCraft\Vcr\Assert\ScreenAssertion;

$result = $player->play(
    programFactory: $factory,
    assertion: new ScreenAssertion(cols: 80, rows: 24),
);
```

It feeds both expected and actual byte streams into separate
`SugarCraft\Vt\Terminal\Terminal` instances and compares the resulting
cell grids. ANSI-level reorderings — redundant SGR re-emission,
equivalent cursor moves, partial vs full repaints — collapse to the
same grapheme grid, so a recording → replay round trip passes even
when the byte streams differ. Failure messages list the first 5
differing cells with `(row,col)` coordinates and the expected vs
actual graphemes.

`ContainsAssertion` provides flexible partial matching — it passes when
the expected substring is found anywhere within the actual output:

```php
use SugarCraft\Vcr\Assert\ContainsAssertion;

$result = $player->play(
    programFactory: $factory,
    assertion: new ContainsAssertion(),
);
// Passes if actual output contains "Ready." anywhere
// even if the full byte stream differs from expected
$this->assertTrue($result->ok);
```

This is useful when you only care about specific content appearing
in the output (e.g. a status message, prompt, or error keyword) without
requiring exact formatting. The comparison is case-sensitive; empty
substring always matches.

### Msg serializers (PR3)

`SugarCraft\Vcr\Msg\Registry::default()` is preloaded with:

- **`BuiltinSerializer`** — covers 19 Msgs: `KeyMsg`,
  `MouseClickMsg / MotionMsg / WheelMsg / ReleaseMsg`, `WindowSizeMsg`,
  `FocusGainedMsg / FocusLostMsg / BlurMsg`, `FocusInMsg / FocusOutMsg`,
  `PasteStartMsg / EndMsg / Msg`, `BackgroundColorMsg`, `ForegroundColorMsg`,
  `CursorPositionMsg`. Tag is the unqualified class name.
- **`JsonableSerializer`** — catch-all for any Msg implementing
  `\JsonSerializable`. Tag is the FQCN; `data` is the
  `jsonSerialize()` result. Round-trip works when the constructor's
  parameter names match the keys returned by `jsonSerialize()`.

```php
use SugarCraft\Vcr\Msg\Registry;
$registry = Registry::default();
$envelope = $registry->encode($msg);  // ['@type' => 'KeyMsg', …] or null
$decoded  = $registry->decode($envelope);  // Msg|null
```

Custom serializers slot in via `$registry->register(new MyOne())`.

## Development

```sh
composer install
vendor/bin/phpunit                                          # test suite
vendor/bin/phpstan analyze                                  # static analysis (level: max)
vendor/bin/php-cs-fixer fix --config=../.php-cs-fixer.dist.php  # lint + auto-fix style
```

Code style is enforced by `php-cs-fixer` via the root `.php-cs-fixer.dist.php` (PSR-12 + `declare_strict_types` + `strict_param` + short array syntax). Append `--dry-run --diff` to preview without writing.

### Fonts

`candy-vcr/fonts/` ships [JetBrainsMono](https://github.com/JetBrains/JetBrainsMono) (Regular, Bold, Italic, BoldItalic) as the default rasterizer font family. JetBrainsMono is distributed under the [SIL Open Font License, version 1.1](fonts/LICENSE) — the full license text is bundled alongside the TTFs. `Glyphs::DEFAULT_FONT_FAMILY` resolves to `JetBrainsMono`, with `DejaVuSansMono` (also bundled) retained as a fallback when JetBrainsMono is unavailable. To use a different family pass it to the `Glyphs` constructor (or set `font_family` on the rasterizer); `FontLoader` searches the bundled `fonts/` dir first, then `/usr/share/fonts/{truetype,opentype}`, `~/.fonts/`, and `~/.local/share/fonts/`.

## License

MIT
