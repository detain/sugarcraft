# CandyVcr

PHP port of [`charmbracelet/x/vcr`](https://github.com/charmbracelet/x/tree/main/vcr).

Records every Msg fed into a candy-core `Program` and every frame emitted by
`view()`, with timing, into a cassette file. Replays cassettes by feeding the
recorded Msgs back at recorded cadence and asserting frames match (cell-grid
equality via [candy-vt](../candy-vt/), with byte-equality fallback).

## Status

🟡 **In progress** — see [`plans/x-vcr.md`](../plans/x-vcr.md) for the slice
roadmap.

| PR | Scope |
|----|-------|
| PR1 | Cassette + Event + JsonlFormat |
| PR2 | Recorder + `Program::withRecorder()` |
| PR3 | Msg serializers — Builtin + Jsonable + Registry |
| PR4 | Player + ByteAssertion + ReplayResult (current) |
| PR5 | ScreenAssertion via candy-vt |
| PR6 | YAML format |
| PR7 | CLI + examples + tracking |

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

`ByteAssertion` is the strict baseline — exact byte equality with a hex-and-printable diff window on failure. PR5 adds `ScreenAssertion` (cell-grid equality via candy-vt) which is more robust to renderer reordering.

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
