# sugar-testing

> Formerly published as `sugarcraft/candy-testing` (renamed 2026-10). <!-- @rename-keep -->

Deterministic test harness for PHP 8.3+ SugarCraft programs — the MVC-style
Model–Update–View apps in this repo: scripted input, golden snapshots
(committed byte-exact reference output), cell-grid assertions, program
simulation, VHS tape building, and graphics-stream decoding.

> **Architecture background:** SugarCraft apps follow the MVC-style Model–Update–View architecture. Testing such programs deterministically has been a long-standing gap — `sugar-testing` closes it for the PHP ecosystem.

## Overview

`sugar-testing` provides the infrastructure for deterministic SugarCraft app testing:

- **`ProgramSimulator`** — drives a `Model` or `Program` with scripted messages,
  intercepting control messages at the dispatch layer exactly as `Program` does
  (see [Simulation subset](#simulation-subset))
- **`ScriptedInput`** — fluent builder for message sequences (`->key('q')->enter()`)
- **Snapshot assertions** — `assertGoldenAnsi`, `assertCellGrid`, `assertAnsiEquals`,
  `assertViewIdempotent` — the parsers reject malformed expectation shapes at the
  boundary, so an assertion that would compare nothing fails loudly instead of passing
- **`GoldenFile`** — lifecycle-aware `.golden` helper: `load()` (null only when
  missing; unreadable or symlinked goldens throw), `save()` (atomic clobber —
  fixture seeding), `create()` (one-way, refuses an existing golden),
  `update()` (deliberate re-baseline), `resolve()` (fixtures-relative path with a
  traversal *and* symlink jail)
- **`TestResult`** — what `run()` returns: final model, last view, captured cmds,
  raw output, per-turn `frames`, and `droppedAfterStop`
- **`Graphics\`** — inline-image stream assertions (`KittyStream`, `KittyImage`,
  `Iterm2Stream`, `SixelStream`, `PixelGrid`, `Mode`, `DecodeLimits`,
  `MalformedGraphicsException`)
  — consumed by sugar-image
- **`LoopPin`** — pins the shared ReactPHP loop to `StreamSelectLoop` so safety
  timers keep their real deadlines (see below)
- **`TapeRecorder`** — builds VHS-compatible `.tape` files for demo rendering, with
  an escape guarantee: typed text can never forge a standalone directive line
- **`TemporaryDirectoryTrait`** — unique, mode-`0700`, symlink-safe scratch dirs
  for filesystem tests

## Requirements

- PHP 8.3+
- `sugarcraft/sugar-core` (Program, Msg, Model, Cmd)
- `sugarcraft/sugar-cellbuffer` (Buffer for cell-grid assertions)
- `react/event-loop` (the loop `LoopPin` swaps)
- `ext-gd` — only needed to materialize pixels in `Graphics` assertions; it is a
  `suggest`, not a hard requirement, and streams can be decoded without it

## Install

```sh
composer require sugarcraft/sugar-testing:@dev
```

## Quickstart

This block runs exactly as copy-pasted (the suite executes it as a test):

```php
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Testing\Input\ScriptedInput;
use SugarCraft\Testing\ProgramSimulator;
use SugarCraft\Testing\Snapshot\Assertions;
use SugarCraft\Testing\Snapshot\GoldenFile;

// The smallest drivable counter — inline so this block is self-contained.
// A real suite hands the simulator its own app's Model (or Program).
$counter = new class implements \SugarCraft\Core\Model {
    public function __construct(private int $count = 0)
    {
    }

    public function init(): ?\Closure
    {
        return null;
    }

    public function update(Msg $msg): array
    {
        if ($msg instanceof KeyMsg && $msg->rune === 'a') {
            return [new self($this->count + 1), null];
        }

        return [$this, null];
    }

    public function view(): string
    {
        return 'count: ' . $this->count;
    }

    public function subscriptions(): ?\SugarCraft\Core\Subscriptions
    {
        return null;
    }
};

$result = ProgramSimulator::for($counter)
    ->send(...ScriptedInput::new()->key('a')->key('q')->build())
    ->run();

// Real suites COMMIT the golden file; a missing golden then fails loudly (see
// Assertions). The guarded save() below just stands in for that first seeding.
$golden = sys_get_temp_dir() . '/sugar-testing-quickstart.golden';
if (GoldenFile::load($golden) === null) {
    GoldenFile::save($golden, $result->view);
}

Assertions::assertGoldenAnsi($golden, $result->view);
```

`send()` takes one message or many — pairing `ScriptedInput` with the simulator
is a single spread: `$sim->send(...$input->build());` (equivalently, a
`foreach` of `send()` calls over `$input->build()`).

## API

### ProgramSimulator

```php
use SugarCraft\Testing\ProgramSimulator;

$sim = ProgramSimulator::for($program);           // Model or Program

$sim->send($msgA, $msgB);                          // enqueue (fluent, variadic)
$sim->withRealCmdRunner(false);                    // capture-only: cmds collected, never executed
$sim->withFakeCmdRunner(fn ($cmd) => $nextMsg);    // substitute the Cmd result
$result = $sim->run();                             // replayable from the initial state

echo $result->view;                                // last view() string
echo $result->output;                              // every view() line, concatenated
$finalModel = $result->model;                      // the model object — inspect it, don't echo it
```

**Queue and clone discipline.** `send()` mutates the handle you call it on; the
`with*()` configuration setters return a **clone** that copies the queue by
value at clone time. Configure first, then send — sending on the original
handle after cloning leaves the clone's queue (and therefore its `run()`)
without those messages. `run()` resets all drain state and replays from the
initial model, so repeated runs are identical.

### Simulation subset

`Program::dispatch()` intercepts control messages before your `update()` ever
sees them. The simulator mirrors that layer message for message — but it is a
simulator, not the real runtime, and the boundary is explicit:

- `BatchMsg` / `SequenceMsg` — each member closure runs as a Cmd would; the
  messages they produce are dispatched, appended behind everything already queued —
  so a member's result lands after later-listed messages, not in member-list order.
  Members must be callables; anything
  else throws. The container itself never reaches `update()`.
- `TickRequest` — `produce()` fires **immediately**. There is no virtual clock:
  the requested delay is not simulated, and nothing is armed on a loop.
- `RawMsg` / `PrintMsg` — written to `$result->output` as a side channel; no
  model turn, no frame.
- `QuitMsg` / `InterruptMsg` — halt the drain, production-style: the model
  never sees them, and any messages still queued when the halt lands are
  counted in `$result->droppedAfterStop` instead of vanishing silently.
  `ScriptedInput::quit()` sends the real thing.
- `SuspendMsg` — consumed (there is no terminal to suspend).
- `ExecRequest` — **throws** a `RuntimeException`. `exec` suspends onto a real
  terminal; the simulator has none, so it refuses rather than faking a pass.
- **Startup trio** — `Program` dispatches `WindowSizeMsg`, `EnvMsg` and
  `ColorProfileMsg` *before* `init()`. The simulator replays that order only
  when armed, so a default run stays byte-identical to pre-parity suites:
  `->withWindowSize(80, 24)->withEnvironment($vars)->withColorProfile($profile)`.
- Subscriptions are fired after `init()` and after each delivered message, as
  `Program` does. A runaway cmd↔message loop is refused at 10,000 update
  cycles — a fixed bound, not a growable one.

### Assertions

```php
use SugarCraft\Testing\Snapshot\Assertions;

// Golden ANSI snapshot. Argument order: (golden path, actual bytes).
Assertions::assertGoldenAnsi('tests/fixtures/view.golden', $actual);

// Cell-grid diff against a Buffer — schema below.
Assertions::assertCellGrid($grid, $buffer);

// Byte-exact ANSI comparison with readable diff on failure.
Assertions::assertAnsiEquals("\x1b[1;32mHello\x1b[0m", $actual);

// Render purity: view() must be idempotent and must not mutate the model.
Assertions::assertViewIdempotent($model);
```

Golden comparison is BYTE-EXACT — no SGR stripping and no line-1 header skip
(machine-stamped golden headers are a sugar-dash convention, handled by that
lib's own comparator). A golden here must be byte-stable across machines.

**Missing goldens FAIL.** On a fresh checkout the run fails rather than
silently green-ing itself; the failure message points at the create flag.
`SUGAR_TESTING_UPDATE_GOLDENS=1` (legacy alias `UPDATE_GOLDENS=1`) is
CREATE-ONLY: the first run creates the file — and counts a read-back assertion
proving what it wrote — but an existing golden that differs still **fails and
is never rewritten**. To re-baseline deliberately, delete the file and rerun
with the flag set (that is also the CI workflow: no flag is ever set in CI, so
CI only ever compares).

### The cell-grid schema (`assertCellGrid`)

The grid is a list of rows compared against a `SugarCraft\CellBuffer\Buffer`.
Every part of the shape is parsed at the boundary and **nothing is silently
skipped** — a grid that cannot assert anything fails, so a typo cannot produce
a false pass:

```php
// A plain string row spreads its grapheme clusters across cells, one per
// cell. This matches a 1-row, 4-column buffer painted "abcd":
Assertions::assertCellGrid(['abcd'], $buffer);

// Per-cell forms — each row is exactly `buffer->width()` cells long:
Assertions::assertCellGrid([
    ['ok', ['rune' => '🎉', 'width' => 2], null],  // string cell, array form (wide glyph claims
                                                   // its continuation cell), wildcard skip
    [null, null, null, null],                      // fully wildcard row (4 columns wide)
], $buffer);
```

Rules the parser enforces (each violation raises immediately; malformed input
is never skipped into a pass):

- The outer grid must be a list, with exactly `$buffer->height()` rows; a
  height or row-width mismatch fails the assertion, naming the dimensions.
  Ragged rows used to leave their tails never-compared — they are a hard error
  now.
- A row is either a plain string (its clusters spread cell-by-cell; a wide
  cluster claims the following continuation cell) or a list of cells of
  exactly buffer width.
- A cell is `null` (a deliberate wildcard for that one position), a string
  holding a **single narrow** grapheme cluster, or an array with keys from
  `rune` and `width` (`0` continuation / `1` / `2`). Unknown keys throw with a
  teaching message — a typo'd `'run'` would once have unchecked the position
  forever. A string cell claiming more than one cluster throws: spread it
  across cells or use the array form.
- A grid whose every cell is a wildcard **fails** ("compared zero cells") —
  a vacuous pass is the exact failure mode this library exists to prevent.
- Failures dump up to 20 mismatching cells with `(row, col)` coordinates, then
  a summary of cells compared vs skipped, so a full-buffer diff cannot flood a
  test log.

### GoldenFile

```php
use SugarCraft\Testing\Snapshot\GoldenFile;

$actual = 'frame';                                   // the bytes you want pinned

$path = GoldenFile::resolve(__DIR__, 'view.golden'); // anchors at <this file's dir>/fixtures/view.golden;
                                                    // escapes ('../', backslash smuggles, symlinked
                                                    // sub-directories) throw

$expected = GoldenFile::load($path);                 // null ONLY when absent; unreadable or
                                                    // symlinked golden -> RuntimeException
if ($expected === null) {
    GoldenFile::save($path, $actual);                // atomic (temp + rename), clobbering: seeding
}

// GoldenFile::create($path, $actual);               // one-way creation: throws
                                                    // GoldenFileExistsException if the golden
                                                    // exists — the race-safe path behind
                                                    // assertGoldenAnsi's create flag
// GoldenFile::update($path, $actual);               // deliberate re-baseline (same atomic write)
```

Every write goes through a uniquely named temp file and a `rename()`, so a
crash mid-write cannot leave a half-baseline; empty content (0 bytes) is a
legal golden for a silent renderer. Reading or writing **through** a symlinked
golden is refused outright — a fixture path must be a fixture.

### Graphics stream assertions

`SugarCraft\Testing\Graphics\*` decodes the three inline-image protocols back
into structured images so tests can assert on what a renderer transmitted —
the parse-side mirror (inverse) of sugar-image's renderers (used by its
suite):

```php
use SugarCraft\Testing\Graphics\{GraphicsAssertions, Iterm2Stream, KittyStream, Mode, SixelStream};

// Decode into an inspectable stream object with typed accessors.
$kitty  = KittyStream::decode($bytes)->image();   // png(), cols(), rows(), id()…
$sixel  = SixelStream::decode($bytes);            // grid(): PixelGrid, palette()
$iterm  = Iterm2Stream::decode($bytes);           // payload(), png(), params()
$mode   = Mode::detect($bytes);                   // which protocol it introduces

// One-shot assertions over a raw stream:
GraphicsAssertions::assertRendersTo($bytes, Mode::Kitty, $expectedGrid, tolerance: 0);
GraphicsAssertions::assertInlineImageDimensions($bytes, Mode::Sixel, 64, 32);
GraphicsAssertions::assertProtocolRoundTrip($bytes, Mode::Iterm2);
```

Pixel materialization goes through GD. Where ext-gd is absent the pixel paths
**fail loudly** (a `RuntimeException` naming the missing extension) — a
graphics assertion never skips silently, because a skipped pixel check reads
identical to a passing one once the suite reports OK. Decoding needs no GD.

These decoders eat captured output, which may be hostile-sized, so
`DecodeLimits` budgets every allocation before it happens: declared rasters
beyond 4M cells, numeric literals beyond 9 digits, `o=z` payloads inflating
past 64 MiB (capped **while** streaming, so a bomb cannot pre-materialize),
and images declaring more than 16,777,216 pixels (2^24) all raise a catchable
`MalformedGraphicsException` with a precise reason — never an OOM-killed
runner. Malformed streams likewise raise `MalformedGraphicsException` (bad
parameter, missing introducer, unterminated payload…).

### ScriptedInput

```php
use SugarCraft\Testing\Input\ScriptedInput;

$input = ScriptedInput::new()
    ->key('h', ctrl: true)  // Ctrl+h
    ->arrow('down')         // lowercase direction vocabulary
    ->enter()
    ->ticks(5)              // 5 harness TickMsgs — see the tick note below
    ->resize(120, 40)
    ->key('q')
    ->build();              // list<Msg>, feed with $sim->send(...$input->build())
```

Builders: `new()`, `key(char, shift:, ctrl:, alt:)`, `namedKey(KeyType, rune,
shift:, ctrl:, alt:)`, `enter()`, `escape()`, `backspace()`, `tab()`,
`arrow(dir, shift:, ctrl:, alt:)`, `ticks(count, seconds:, factory:)`,
`resize(cols, rows)`, `quit()` (a real `QuitMsg` — it halts the simulator
drain, production-style), `mouse(...)`, `wheel(...)`, `paste(content)`,
`bytes(...$reads)`, `clipboard(content, selection:)`,
`keyboardEnhancements(flags)`, `push(msg)` for anything bespoke, plus
`build()`, `count()`, and constants `READ_SIZE`, `PASTE_START`, `PASTE_END`.

**Ticks.** Every fleet lib ships its *own* tick message class (`minesweeper`,
`widgets`, `forms`, … each match their own), so a bare `ticks(5)` enqueues the
harness's `SugarCraft\Testing\Input\TickMsg` — which matches **nothing** in
your model. Pass the factory to script your lib's real tick:

```php
->ticks(3, factory: fn (int $i, float $s) => new \My\Lib\Msg\TickMsg($s))
```

Symbolic builders (`key()`, `arrow()`, `enter()`, …) produce the DECODED message
a keyboard press becomes — `arrow('down')` is a `KeyType::Down` message, not bytes.
`bytes()` carries the real WIRE form instead (arrow keys as `ESC [ C`). Never script
an arrow as the literal `→` glyph: a UTF-8 arrow is plain text — it takes the
paste/IME path and decodes as character input, never as `KeyType::Down`. (A PTY
harness driving a program from outside must send the `ESC [ C` bytes for the same
reason.)

Terminal-shaped input is decoded by sugar-core's real `InputReader` — the parser
`Program` reads stdin with — so a script sees the same messages the live runtime
delivers:

```php
ScriptedInput::new()
    ->paste("line 1\nline 2")         // PasteStartMsg, PasteEndMsg, PasteMsg (sanitized)
    ->bytes("\x1b[200~ab\x1b[20", "1~") // raw reads: an end marker split across two reads
    ->bytes("\x1b")                    // a lone ESC becomes Escape after the silence
    ->build();

ScriptedInput::new()->withSanitizePaste(false)->paste($raw); // mirrors ProgramOptions::$sanitizePaste
```

`paste()` follows the runtime: payloads are sanitized by default, a paste past
`InputReader::MAX_PASTE_BYTES` arrives as several `PasteMsg`s (concatenate every one
between `PasteStartMsg` and the one after `PasteEndMsg`), and a paste whose end marker
never arrives is closed out eagerly. For a bare `PasteMsg` with no envelope,
`push(new PasteMsg($text))`.

`ProgramSimulator` fires each subscription once after `init()` and once after every sent
message, delivering what it produces before the next sent message; `run()` replays from
the Program's model each time, so repeated runs give the same result.

### TestResult

`run()` returns a `SugarCraft\Testing\TestResult`:

- `->model` — final model object
- `->view` — last `view()` string
- `->cmds` — every captured command closure, in dispatch order
- `->output` — concatenated `view()` output across the run plus `RawMsg`/`PrintMsg`
  side-channel writes
- `->frames` — every frame the run produced, in order: the `view()` after the init
  update and after each delivered message's update leg, plus the final render — for
  animation/progression assertions
- `->droppedAfterStop` — messages left in the queue when a `QuitMsg`/`InterruptMsg`
  halted the drain (0 in a healthy run; a surprise non-zero means your script
  over-sent)

Verbs: `assertCmdCount(int)`, `assertCmdContains(callable $filter, ?string $message)`
(failure names the captured-cmd count, since "matched none of 0" is the usual story),
`assertNoCmds()`.

### TapeRecorder

`SugarCraft\Testing\Tape\TapeRecorder` **builds** `.tape` scripts for the VHS
GIF renderer (it records nothing from a running program — you compose the
choreography):

```php
use SugarCraft\Testing\Tape\TapeRecorder;

TapeRecorder::to(__DIR__ . '/.vhs/demo.tape')
    ->header(theme: 'TokyoNight')          // Set Theme/FontSize/Width/Height
    ->type('php examples/demo.php')
    ->enter()
    ->sleep(1.0)
    ->resize(100, 30)                      // Set Width/Height mid-tape
    ->type("q")
    ->comment('rendered by .github/workflows/vhs.yml')
    ->save();
```

Everything interpolated is escaped so it cannot forge a standalone directive
line — `type()` neutralizes newlines and control bytes inside the quotes,
`comment()` prefixes every physical line, and even the theme string is escaped.
`line()` is the deliberate verbatim escape hatch: CI executes the tapes these
files describe, so only feed it trusted, generated directives. `save()` is a plain
trusted-path write that fails loudly on a short write — there is no path jail and no
atomic publish here; those are `GoldenFile`'s guarantees, not this class's. See
`examples/tape-recorder.php` for a runnable demo.

### TemporaryDirectoryTrait

`SugarCraft\Testing\Concerns\TemporaryDirectoryTrait` gives a test class a
scratch directory that is per-test unique, mode-`0700`, and removed without
ever following a planted symlink into someone else's tree. The trait **owns**
the `$tmpDir` property — do not re-declare it in your class (that is a
fatal). Call `$this->setUpTemporaryDirectory()` from `setUp()` and
`$this->tearDownTemporaryDirectory()` from `tearDown()`; override
`getTempDirSuffix()` to name the directory after your test class.

### LoopPin

If your suite arms a **safety timer** to bound a wait — `addTimer(5.0, fn () => $loop->stop())`
around some async work — pin the shared event loop in your `tests/bootstrap.php`:

```php
\SugarCraft\Testing\LoopPin::pinStableClock();
```

Why: `Loop::get()` autodetects, and where `ext-uv` is installed it returns `ExtUvLoop`.
libuv computes a timer's deadline against the loop's **cached** clock, and refreshes that
cache once per loop iteration — as soon as the poll syscall returns. A timer's error is
the wall time between the last refresh and the
arm. A PHPUnit process runs the loop in short bursts with long stretches of synchronous
test code in between, so the effective delay becomes `delay - idle_since_last_refresh`. A
timer armed for 10s after 12s of idle is already overdue and fires on the first tick, so
your safety net fires instead of the work and the test fails **having consumed no wall
time** — an intermittent flake with no obvious cause.

The measurements — a 5s timer armed after N seconds of synchronous work at each arming
site, under ext-uv and under `StreamSelectLoop` — live in exactly one place: the
timer-accuracy notes on `SugarCraft\Core\Program::run()` in sugar-core. They are not
copied here; one home for the table means one place to correct it.

What the numbers show: every ext-uv figure is linear in the idle, which is the proof the
deadline comes off a stale clock rather than the OS clock. `StreamSelectLoop` returns the
full delay at every site and every N.

**Do not read "armed before the loop's first `run()`" as an escape hatch.** It survives
only in the degenerate case where the armed timer is the loop's earliest deadline and
nothing else wakes the first poll; the apparent safety is an arithmetic cancellation
inside `UV::RUN_ONCE`, not an absent baseline. A never-run loop
has a baseline (`uv_loop_new()` sets it), and `uv_now()` shows the cache stale by the full
pre-run idle at the moment of the arm. Add a periodic render tick, an earlier timer, or a
stream with bytes already waiting — a real `Program` always has at least one — and the
timer fires early by that whole idle, exactly like every other row. The rule is about the
handles present at **run** time, not about the moment of the arm.

Two corollaries worth spelling out, because they are easy to get backwards:

- It is **not** "the loop must stay inside `uv_run()`". One `$loop->run()` is not one
  `uv_run()` — `ExtUvLoop::run()` is itself a `while` loop of short `uv_run()` calls. Idle
  between refreshes is the hazard, wherever it comes from, and blocking inside a callback
  does it just as effectively as idling between bursts.
- `StreamSelectLoop` is not safe because it lacks a cached clock — it has one
  (`Timers::$time`, from `hrtime(true) * 1e-9`). It is safe because `Timers::add()` calls
  `Timers::updateTime()` at **arm** time.

`Program::run()` is not automatically immune either; see the timer-accuracy notes on that
method for which sugar-core paths are safe (blocking in `update()`, because Cmds are
deferred through `futureTick()`) and which are exposed (blocking inside a Cmd that then
arms). Pin the loop before anything else touches it: an already-created loop may own
watchers that do not transfer. `LoopPin` additionally calls `Loop::stop()` after installing
the pin, which disarms the shutdown hook `Loop::get()` may already have registered — so a
late pin can no longer leave the process hanging on a stale loop with a periodic timer, and
there is no surprise loop run after your last test. That last part cuts both ways, and the
flag is process-wide and permanent: it also switches off React's "you never have to call
`run()` yourself" convenience, so a suite that schedules work on `Loop::get()` and leaves
the shutdown hook to execute it gets nothing instead. Call `run()` explicitly — which a
test that wants to assert on the outcome should be doing anyway.

Note one global side effect: `StreamSelectLoop`'s constructor calls
`pcntl_async_signals(true)` process-wide, at bootstrap. `Program::run()` normally does the
same in production, so for most suites this is the production setting arriving earlier
rather than a divergence — but a Program constructed with `withoutSignalHandler` or
`catchInterrupts: false` never enables async signals at all, and a suite built around one
of those does diverge. Re-check it if your suite does its own `pcntl_signal()` bookkeeping.

`LoopPin::hasStaleClockRisk($loop)` reports whether a given loop is affected, for a suite
that wants to assert or diagnose rather than pin.

## License

MIT

## Credits & inspiration

Originally inspired by the Go [Charm](https://github.com/charmbracelet) ecosystem
(with `charmbracelet/x/exp/golden` and `charmbracelet/teatest` as the nearest prior art);
SugarCraft is developed as a native PHP project.
