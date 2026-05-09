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
| PR1 | Cassette + Event + JsonlFormat (current) |
| PR2 | Recorder hook in candy-core `Program` |
| PR3 | Msg serializers (KeyMsg, MouseMsg, WindowSizeMsg, …) |
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

## Quickstart (PR1 surface)

```php
use SugarCraft\Vcr\Cassette;
use SugarCraft\Vcr\CassetteHeader;
use SugarCraft\Vcr\Event;
use SugarCraft\Vcr\EventKind;
use SugarCraft\Vcr\Format\JsonlFormat;

$cassette = new Cassette(
    new CassetteHeader(version: 1, createdAt: '2026-05-07T10:00:00Z', cols: 80, rows: 24, runtime: 'sugarcraft/candy-core@dev'),
    [
        new Event(t: 0.0, kind: EventKind::Resize, payload: ['cols' => 80, 'rows' => 24]),
        new Event(t: 0.001, kind: EventKind::Output, payload: ['b' => "\x1b[2J\x1b[H"]),
        new Event(t: 1.201, kind: EventKind::Quit, payload: []),
    ],
);

$format = new JsonlFormat();
$format->write($cassette, '/tmp/session.cas');
$loaded = $format->read('/tmp/session.cas');
```

The runtime API (`Recorder`, `Player`, CLI) lands in subsequent PRs.

## License

MIT
