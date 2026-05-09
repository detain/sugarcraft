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
| PR3 | Msg serializers — Builtin + Jsonable + Registry (current) |
| PR4 | Player + ByteAssertion |
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

The Player (replay-and-assert) lands in PR4; the CLI lands in PR7.

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
