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

### Timestamp modes

Cassettes support two timestamp modes:

| Mode | Description | Use case |
|------|-------------|-----------|
| `absolute` (default) | `t` is seconds since cassette start | Playback timing |
| `relative` | `t` is interval since previous event (like asciinema v3) | Easier manual editing |

Set the mode when creating a cassette header:

```php
use SugarCraft\Vcr\CassetteHeader;

// Absolute timestamps (default)
$header = new CassetteHeader(
    version: 1,
    createdAt: '2026-05-07T10:00:00Z',
    cols: 80,
    rows: 24,
    runtime: 'sugarcraft/candy-core@dev',
);

// Relative timestamps (interval since previous event)
$header = new CassetteHeader(
    version: 1,
    createdAt: '2026-05-07T10:00:00Z',
    cols: 80,
    rows: 24,
    runtime: 'sugarcraft/candy-core@dev',
    timestampMode: CassetteHeader::TIMESTAMP_MODE_RELATIVE,
);
```

**Absolute mode example:**
```jsonl
{"t":0.000,"k":"output","b":"$ "}
{"t":0.500,"k":"output","b":"ls\r\n"}
{"t":0.502,"k":"output","b":"file1.txt file2.txt\r\n"}
```

**Relative mode example (same events):**
```jsonl
{"t":0.000,"k":"output","b":"$ "}
{"t":0.500,"k":"output","b":"ls\r\n"}
{"t":0.002,"k":"output","b":"file1.txt file2.txt\r\n"}
```

The JsonlFormat reader and writer handle conversion automatically based on the header's `timestampMode`. Backwards compatibility is preserved — cassettes without a `timestampMode` key default to `absolute`.

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
vendor/bin/candy-vcr inspect session.cas               # list events
vendor/bin/candy-vcr replay  session.cas --speed=realtime  # stream output to stdout
vendor/bin/candy-vcr diff    a.cas b.cas               # structural diff
vendor/bin/candy-vcr stats   session.cas               # show cassette statistics
```

`inspect` shows each event's timestamp, kind, and a short payload summary (with `--since=<seconds>` / `--until=<seconds>` filters). `replay` streams the cassette's recorded output bytes to stdout — `--speed=realtime` honours the recorded cadence (use it for visual demos), `--speed=instant` flushes everything as fast as the kernel will accept it. `diff` compares headers + per-event payloads and exits non-zero on any difference. `stats` prints event tallies by kind, total duration, input message type breakdown, and output byte counts with per-event averages.

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

- **`BuiltinSerializer`** — covers 14 candy-core Msgs: `KeyMsg`,
  `MouseClickMsg / MotionMsg / WheelMsg / ReleaseMsg`, `WindowSizeMsg`,
  `FocusMsg`, `BlurMsg`, `PasteStartMsg / EndMsg / Msg`,
  `BackgroundColorMsg`, `ForegroundColorMsg`, `CursorPositionMsg`. Tag
  is the unqualified class name.
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

## License

MIT
