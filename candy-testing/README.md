# candy-testing

Test harness for TEA (The Elm Architecture) programs — pioneering what [bubble-tea issue #1654](https://github.com/charmbracelet/bubbletea/issues/1654) never shipped.

> **TEA background:** The Elm Architecture (Model / Update / View) is the foundation of [charmbracelet/bubbletea](https://github.com/charmbracelet/bubbletea). Testing TEA programs deterministically has been a long-standing gap — `candy-testing` closes it for the PHP ecosystem.

## Overview

`candy-testing` provides the infrastructure SugarCraft pioneers for deterministic TEA program testing:

- **`ProgramSimulator`** — drives a `Program` with scripted input, captures model/view/cmds
- **`ScriptedInput`** — fluent builder for message sequences (`->key('q')->enter()`)
- **Snapshot assertions** — `assertGoldenAnsi`, `assertCellGrid`, `assertAnsiEquals`
- **`GoldenFile`** — load/save helper for `.golden` fixture files
- **`TapeRecorder`** — emits VHS-compatible `.tape` files for demo rendering

## Quickstart

```php
use SugarCraft\Testing\ProgramSimulator;
use SugarCraft\Testing\Input\ScriptedInput;
use SugarCraft\Testing\Snapshot\Assertions;

// Build a scripted session and run the simulator.
$sim = ProgramSimulator::for($program)
    ->send(new KeyMsg(KeyType::Char, 'a'))
    ->send(new KeyMsg(KeyType::Enter))
    ->run();

// Assert the view output matches the golden file.
Assertions::assertGoldenAnsi(__DIR__ . '/fixtures/counter.golden', $sim->view);

// Inspect the final model state.
$counter = $sim->model; // CounterModel with updated count
```

## Requirements

- PHP 8.3+
- `sugarcraft/candy-core` (Program, Msg, Model, Cmd)
- `sugarcraft/candy-buffer` (Buffer for cell-grid assertions)

## Install

```sh
composer require sugarcraft/candy-testing:@dev
```

## API

### ProgramSimulator

```php
// Wrap a Program for testing.
$sim = ProgramSimulator::for($program);

// Enqueue messages (fluent).
$sim->send(new KeyMsg(...))->send(new KeyMsg(...));

// Override the cmd runner to capture instead of executing side effects.
$sim->withFakeCmdRunner(fn($cmd) => null);

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
```

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
timer-accuracy notes on `SugarCraft\Core\Program::run()` in candy-core. They are not
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
method for which candy-core paths are safe (blocking in `update()`, because Cmds are
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
