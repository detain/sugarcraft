# sugar-testing

> Formerly published as `sugarcraft/candy-testing` (renamed 2026-10). <!-- @rename-keep -->

Deterministic test harness for SugarCraft apps — MVC-style Model–Update–View
programs — for PHP 8.3+: scripted input, golden snapshots and full program
simulation.

> **Architecture background:** SugarCraft apps follow the MVC-style Model–Update–View architecture. Testing such programs deterministically has been a long-standing gap — `sugar-testing` closes it for the PHP ecosystem.

## Overview

`sugar-testing` provides the infrastructure for deterministic SugarCraft app testing:

- **`ProgramSimulator`** — drives a `Program` with scripted input, captures model/view/cmds
- **`ScriptedInput`** — fluent builder for message sequences (`->key('q')->enter()`)
- **Snapshot assertions** — `assertGoldenAnsi`, `assertCellGrid`, `assertAnsiEquals`, `assertViewIdempotent`
- **`GoldenFile`** — load/save helper for `.golden` fixture files (`resolve()` refuses traversal outside the base directory)
- **`Graphics\`** — inline-image stream assertions (`KittyStream`, `Iterm2Stream`, `SixelStream`, `PixelGrid`, `Mode`, `MalformedGraphicsException`) — consumed by sugar-image
- **`LoopPin`** — pins the shared ReactPHP loop to `StreamSelectLoop` so safety timers keep their real deadlines (see below)
- **`TapeRecorder`** — emits VHS-compatible `.tape` files for demo rendering

## Quickstart

```php
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Testing\ProgramSimulator;
use SugarCraft\Testing\Input\ScriptedInput;
use SugarCraft\Testing\Snapshot\Assertions;

// Build a scripted session and run the simulator; run() returns a TestResult.
$result = ProgramSimulator::for($program)
    ->send(new KeyMsg(KeyType::Char, 'a'))
    ->send(new KeyMsg(KeyType::Enter))
    ->run();

// Assert the view output matches the golden file.
Assertions::assertGoldenAnsi(__DIR__ . '/fixtures/counter.golden', $result->view);

// Inspect the final model state.
$counter = $result->model; // CounterModel with updated count
```

## Requirements

- PHP 8.3+
- `sugarcraft/sugar-core` (Program, Msg, Model, Cmd)
- `sugarcraft/sugar-cellbuffer` (Buffer for cell-grid assertions)

## Install

```sh
composer require sugarcraft/sugar-testing:@dev
```

## API

### ProgramSimulator

```php
// Wrap a Program for testing.
$sim = ProgramSimulator::for($program);

// Enqueue messages (fluent).
$sim->send(new KeyMsg(...))->send(new KeyMsg(...));

// Provide the fake runner that decides the injected message for each Cmd;
// the REAL Cmd closures still execute for their side effects (counters,
// files, sockets) unless you also call withRealCmdRunner(false).
$sim->withFakeCmdRunner(fn($cmd) => null);

// Capture-only mode: cmds are collected in $result->cmds and never executed.
$sim->withRealCmdRunner(false);

// Run the session and get the result.
$result = $sim->run();
echo $result->view;   // Last view() output
echo $result->model;  // Final model state
echo $result->output; // Concatenated view() output across steps
```

### Assertions

```php
// Golden ANSI snapshot (auto-creates on first run if UPDATE_GOLDENS=1).
Assertions::assertGoldenAnsi('tests/fixtures/view.golden', $actual);

// Cell-grid diff (for buffer-based renderers).
Assertions::assertCellGrid($expected2DArray, $buffer);

// Byte-exact ANSI comparison with readable diff on failure.
Assertions::assertAnsiEquals("\x1b[1;32mHello\x1b[0m", $actual);

// Render purity: view() must be idempotent and must not mutate the model.
Assertions::assertViewIdempotent($model);
```

Golden comparison is BYTE-EXACT — no SGR stripping and no line-1 header skip
(machine-stamped golden headers are a sugar-dash convention, handled by that
lib's own comparator). A golden here must be byte-stable across machines.
`UPDATE_GOLDENS=1` is CREATE-ONLY: it writes a missing golden, but an existing
golden that differs still fails and is never rewritten — delete the file to
re-baseline deliberately.

### GoldenFile

```php
// Load (null when missing) / save; save creates parent dirs and accepts an
// empty golden (0 bytes is a legal pin for a silent renderer).
$expected = GoldenFile::load('/abs/path/tests/fixtures/view.golden');
GoldenFile::save('/abs/path/tests/fixtures/view.golden', $actual);

// Fixtures-relative path builder with a traversal guard: the result is
// anchored at <baseDir>/fixtures and anything escaping it throws.
$path = GoldenFile::resolve(__DIR__, 'view.golden');
```

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

Pixel materialisation goes through GD; tests that need pixels skip where
ext-gd is absent. Malformed streams raise `MalformedGraphicsException` with a
precise reason (bad parameter, missing introducer, unterminated payload…).

### ScriptedInput

```php
$input = ScriptedInput::new()
    ->key('h', ctrl: true)  // Ctrl+h
    ->arrow('down')
    ->enter()
    ->ticks(5)             // 5 tick events
    ->resize(120, 40)
    ->key('q')
    ->build();
```

Symbolic builders (`key()`, `arrow()`, `enter()`, …) produce the DECODED message
a keyboard press becomes — `arrow('down')` is a `KeyType::Down` message, not bytes.
`bytes()` carries the real WIRE form instead (arrow keys as `ESC [ C`). Never script
an arrow as the literal `→` glyph: a UTF-8 arrow is plain text — it takes the
paste/IME path and decodes as character input, never as `KeyType::Down`. (A PTY
harness driving a program from outside must send the `ESC [ C` bytes for the same
reason.)

Terminal-shaped input is decoded by sugar-core's real `InputReader` — the parser
`Program` reads stdin with — so a script sees what the live runtime delivers:

```php
ScriptedInput::new()
    ->paste("line 1\nline 2")         // PasteStartMsg, PasteEndMsg, PasteMsg (sanitized)
    ->bytes("\x1b[200~ab\x1b[20", "1~") // raw reads: an end marker split across two reads
    ->bytes("\x1b")                    // a lone ESC becomes Escape after the silence
    ->build();

ScriptedInput::new()->withSanitizePaste(false)->paste($raw); // mirrors ProgramOptions::$sanitizePaste
```

`paste()` follows the runtime exactly: payloads are sanitized by default, a paste past
`InputReader::MAX_PASTE_BYTES` arrives as several `PasteMsg`s (concatenate every one
between `PasteStartMsg` and the one after `PasteEndMsg`), and a paste whose end marker
never arrives is closed after the runtime's idle timeout. For a bare `PasteMsg` with no
envelope, `push(new PasteMsg($text))`.

`ProgramSimulator` fires each subscription once after `init()` and once after every sent
message, delivering what it produces before the next sent message; `run()` replays from
the Program's model each time, so repeated runs give the same result.

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

The measurements — a 5s timer armed after N seconds of synchronous work at five different
arming sites, under ext-uv and under `StreamSelectLoop` — live in exactly one place: the
timer-accuracy notes on `SugarCraft\Core\Program::run()` in sugar-core. They are not
copied here, because a copy in three files is how one wrong row came to be wrong in three
files.

What the numbers show: every ext-uv figure is linear in the idle, which is the proof the
deadline comes off a stale clock rather than the OS clock. `StreamSelectLoop` returns the
full delay at every site and every N.

**Do not read "armed before the loop's first `run()`" as an escape hatch.** It survives
only in the degenerate case where the armed timer is the loop's earliest deadline and
nothing else wakes the first poll; the apparent safety is an arithmetic cancellation
inside `UV::RUN_ONCE`, not — as it is often stated — an absent baseline. A never-run loop
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

Originally inspired by the Go [Charm](https://github.com/charmbracelet) ecosystem; SugarCraft is developed as a native PHP project.
