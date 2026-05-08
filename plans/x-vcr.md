# Plan: terminal session recorder + replayer (`x/vcr` → `candy-vcr`)

## Goal

New lib that wraps a candy-core `Program` to:

- **Record** every Msg fed into `update()` and every frame emitted from
  `view()`, with timing, into a "cassette" file
- **Replay** that cassette by feeding the recorded Msgs back at recorded
  cadence and asserting frames match (cell-grid equality via
  [candy-vt](./x-vt.md), with byte-equality fallback)

Mirrors `charmbracelet/x/vcr`.

## Use cases

1. **Bug repro from user reports** — user runs `--record bug.cas`, ships
   the cassette, maintainer replays locally
2. **Regression tests** — record a known-good session, replay in CI,
   diff against expected screen state
3. **Demo capture** — alternative to VHS for headless / scriptable
   recordings (no docker, no `ttyd`, runs in PHP unit-test process)
4. **Fuzzing seeds** — record genuine sessions, mutate Msgs slightly,
   replay to find edge cases

## Scope

**In**

- Cassette format: JSONL (one event per line) — primary
- Cassette format: YAML — human-readable secondary
- Recorder hook into `Program` lifecycle
- Player drives a fresh `Program` instance, asserts frames
- Msg serialization for built-in candy-core Msgs (KeyMsg, MouseMsg, WindowSizeMsg, custom user types via JSON-able interface)
- Frame assertion via candy-vt cell-grid diff
- Frame assertion fallback: byte-equality (when candy-vt isn't available or installed)
- CLI: `vendor/bin/candy-vcr replay session.cas`

**Out**

- Recording a *real* TTY session (use `asciinema` / `script` for that and
  feed the resulting bytes through candy-vt for replay)
- Reverse-engineering closed-source TUIs (we record candy-core Programs only)
- Network protocol replay (HTTP/SSE/WebSocket)

## Naming + placement

- Composer pkg: `sugarcraft/candy-vcr`
- Subdir: `candy-vcr/`
- Namespace: `SugarCraft\Vcr`
- Prefix: **Candy-** (foundation tool)

## Layout

```
candy-vcr/
  composer.json
  phpunit.xml
  README.md
  CALIBER_LEARNINGS.md
  bin/
    candy-vcr                          # CLI entry
  src/
    Cassette.php                       # value object: header + Event[]
    CassetteHeader.php                 # readonly: createdAt, sugarcraftVersion, dimensions
    Event.php                          # readonly: t, kind, payload
    EventKind.php                      # enum: Resize, Input, Output, Quit
    Recorder.php                       # tee adapter for Program
    Player.php                         # drives Program from cassette
    ReplayResult.php                   # readonly: pass/fail + per-event diff
    Format/
      Format.php                       # interface: read / write
      JsonlFormat.php
      YamlFormat.php
    Msg/
      MsgSerializer.php                # interface
      BuiltinSerializer.php            # KeyMsg, MouseMsg, WindowSizeMsg, … out of the box
      JsonableSerializer.php           # any Msg implementing JsonSerializable
    Assert/
      Assertion.php                    # interface
      ScreenAssertion.php              # uses candy-vt
      ByteAssertion.php                # raw byte equality fallback
    Cli/
      Command.php                      # router for bin/candy-vcr
      ReplayCommand.php
      InspectCommand.php
      DiffCommand.php
    Lang.php
  examples/
    record.php
    replay.php
    inspect.php
    cassettes/
      counter.cas                      # tiny CandyMold counter recording
  tests/
    fixtures/
      counter.cas
      huh-form.cas
    CassetteTest.php
    RecorderTest.php
    PlayerTest.php
    RoundTripTest.php
    ScreenAssertionTest.php
```

## composer.json

- PHP `^8.1`
- Deps: `sugarcraft/candy-core: @dev`
- Suggest: `sugarcraft/candy-vt: @dev` (enables ScreenAssertion; falls back to bytes if missing)
- bin: `bin/candy-vcr`

## Cassette format (JSONL)

```jsonl
{"v":1,"created":"2026-05-07T10:00:00Z","cols":80,"rows":24,"runtime":"sugarcraft/candy-core@1.0.0"}
{"t":0.000,"k":"resize","cols":80,"rows":24}
{"t":0.001,"k":"output","b":"[2J[H..."}
{"t":0.450,"k":"input","msg":{"@type":"KeyMsg","key":"j"}}
{"t":0.452,"k":"output","b":"..."}
{"t":1.200,"k":"input","msg":{"@type":"KeyMsg","key":"q"}}
{"t":1.201,"k":"quit"}
```

- First line is the header (`v` field present)
- All subsequent lines are events
- `t` is seconds since cassette start (float, ms precision)
- `k` (kind) is one of: `resize`, `input`, `output`, `quit`
- `msg.@type` is the serializer key (`KeyMsg`, etc.)

## Public API

### Recording

```php
use SugarCraft\Vcr\Recorder;

$recorder = new Recorder('session.cas');
$program = (new Program($model))->withRecorder($recorder);
$program->run();      # cassette flushed on each event; closed on quit
```

`Program::withRecorder($recorder)` is a new fluent setter on candy-core
that:

- wraps the current `InputReader` so every dispatched Msg is teed to the recorder
- wraps the current renderer write so every output byte chunk is teed
- captures resize events via the existing SIGWINCH path
- on `QuitMsg`: flushes + closes the cassette file

### Replaying

```php
use SugarCraft\Vcr\Player;
use SugarCraft\Vcr\Assert\ScreenAssertion;

$player = new Player('session.cas');
$result = $player->play(
    new Program(new MyModel()),
    assertion: new ScreenAssertion(),  # default if candy-vt installed
    speed: Player::SPEED_INSTANT,      # or SPEED_REALTIME for demo replay
);

if (!$result->ok) {
    echo $result->diffSummary();
    exit(1);
}
```

`Player::play`:

1. Reads cassette header, asserts dimensions match the program (or resizes)
2. For each event:
   - `resize` → injects `WindowSizeMsg`
   - `input` → deserializes Msg via `MsgSerializer`, dispatches to program
   - `output` → captures program's actual output, compares against recorded bytes
   - `quit` → asserts program quit cleanly

3. Returns `ReplayResult` with pass/fail + per-event diff

### CLI

```sh
vendor/bin/candy-vcr replay session.cas --speed=instant
vendor/bin/candy-vcr inspect session.cas --since=1.0 --until=5.0
vendor/bin/candy-vcr diff a.cas b.cas
```

## Implementation slices

### PR1 — Cassette + Event + JsonlFormat (~half day)

- Pure data structures + I/O round-trip
- Tests: write + read cassette, assert deep equality

### PR2 — Recorder hook in Program (~1 day)

- Add `Program::withRecorder(?Recorder)` in candy-core (1 candy-core patch)
- Tee `InputReader::next()` and `Renderer::write()` via wrappers
- Resize captured via existing SIGWINCH dispatcher
- Tests: run a 10-step Program, assert cassette has expected events

### PR3 — MsgSerializer + builtin serializers (~half day)

- `MsgSerializer` interface
- `BuiltinSerializer` for KeyMsg, MouseClickMsg, MouseMotionMsg, MouseWheelMsg, MouseReleaseMsg, WindowSizeMsg, FocusMsg, BlurMsg, PasteStartMsg, PasteEndMsg, PasteMsg, BackgroundColorMsg, ForegroundColorMsg, CursorPositionMsg
- `JsonableSerializer` for user Msgs implementing `\JsonSerializable`
- Registry pattern: `MsgSerializer::register('@CustomMsg', $serializer)`

### PR4 — Player + ByteAssertion (~1 day)

- Drive Program from cassette events
- Two speed modes: instant (next event ASAP), realtime (sleep until `t`)
- `ByteAssertion` — exact-equality of output chunks per event
- Tests: round-trip a recorded session, assert pass

### PR5 — ScreenAssertion via candy-vt (~half day)

- After replaying each event, feed accumulated output into a candy-vt `Terminal`
- Diff against an expected `Terminal` fed from the recorded output
- `ReplayResult` carries the cell-level diff for friendlier failure messages
- Tests: deliberate divergence (modified Model) → expect specific diff cells

### PR6 — YAML format (~half day)

- `YamlFormat` reader/writer for human-readable cassettes
- Round-trip tests
- Document trade-off: JSONL for tooling, YAML for hand-written test fixtures

### PR7 — CLI + examples + matrix (~half day)

- `bin/candy-vcr` with `replay`, `inspect`, `diff` subcommands
- `examples/` recording a CandyMold counter session
- Matrix entries, MATCHUPS, etc.

## Test strategy

- **Round-trip**: record a known Program (CandyMold counter), replay, assert pass
- **Negative**: record, modify the Model in a known way, replay, assert fail with expected diff
- **Format**: JSONL ↔ YAML round-trip preserves event order + timing within 1ms
- **Cross-platform**: same cassette replays identically on Linux/macOS/Windows (vt normalizes terminal differences)

## Caveats / open questions

1. **Non-deterministic Msgs** — `Cmd::tick(100ms)` schedules a TickMsg. If we
   replay slower than realtime, recorded TickMsgs arrive in different
   order. Solution: replay in `instant` mode injects every Msg in
   recorded order regardless of timing. Realtime mode warns if the
   schedule slips.
2. **Closures in Msgs** — some user Cmds embed closures. Closures don't
   serialize. Document: cassettes record the *resulting* Msg from the
   closure (which we capture when it dispatches), not the closure
   itself. As long as the program runs deterministically, the same Msg
   sequence replays.
3. **Random seeds** — if a Model uses `random_int()`, replay diverges.
   Recommend: candy-vcr-ed Programs should accept a seeded RNG via
   constructor; document this. Out of scope to enforce.
4. **External I/O** — Msgs from `Cmd::http(...)` are application-level;
   their *result* is captured as a Msg and replayed. The replay does
   not re-execute the HTTP call. Good for replay; bad if the user is
   debugging HTTP behaviour. Document the contract.
5. **Cassette portability** — different SugarCraft versions may produce
   slightly different output bytes (e.g. Renderer optimizations). Header
   records `runtime` version; Player warns on mismatch but proceeds. Cell-
   grid assertion (via vt) is more robust than byte equality across
   versions.
6. **File size** — typical cassette: 1KB-100KB. Each output chunk is a
   delta, not a full frame. Multi-minute sessions stay <1MB.
7. **Sensitive data** — recorded output may include secrets pasted into
   forms. Document a `Recorder::redact(string $pattern)` hook for regex-
   based masking before write.
8. **Concurrent recorders** — only one Recorder per Program. Enforce in
   `withRecorder`.

## Effort

| Slice | Effort |
|---|---|
| PR1 cassette + JSONL | half day |
| PR2 recorder hook | 1 day |
| PR3 msg serializers | half day |
| PR4 player + byte assert | 1 day |
| PR5 vt screen assert | half day |
| PR6 YAML format | half day |
| PR7 CLI + matrix | half day |
| **Total** | **~4-5 days** |

## Dependencies

- [x-vt](./x-vt.md) PR3 (basic SGR) — required for ScreenAssertion. Without it, we ship with ByteAssertion only and add ScreenAssertion in a follow-up.
- candy-core `Program::withRecorder` patch (small, lands in PR2)

## Tracking

- `MATCHUPS.md` — new row: `[charmbracelet/x/vcr] | candy-vcr | candy-vcr/ | sugarcraft/candy-vcr | SugarCraft\Vcr | 🟡 | Record + replay terminal sessions`
- Apps table or libraries table? Libraries (it's used as a library by tests; the CLI is convenience).
- `PROJECT_NAMES.md` — naming entry
- `CONVERSION.md` — phase row
- `UPSTREAM_OPPORTUNITIES.md` — flip `x/vcr` 🔴 → 🟡 on PR1, 🟢 on PR7
- `docs/index.html` — homepage tile
- `media/candy-vcr.png` — 256² icon
- candy-core patch documented in `candy-core/CALIBER_LEARNINGS.md` (`withRecorder` hook + tee pattern)
