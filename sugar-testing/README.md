# sugar-testing

> Formerly published as `sugarcraft/candy-testing` (renamed 2026-10). <!-- @rename-keep -->

Deterministic test harness for PHP 8.3+ SugarCraft programs — the MVC-style
Model–Update–View apps in this repo: scripted input, golden snapshots
(committed byte-exact reference output), cell-grid assertions, program
simulation, VHS tape building, and graphics-stream decoding.

> **Architecture background:** SugarCraft apps follow the MVC-style Model–Update–View architecture. `sugar-testing` is the deterministic test harness for such programs in this PHP toolchain.

## Overview

`sugar-testing` provides the infrastructure for deterministic SugarCraft app testing:

- **`ProgramSimulator`** — drives a `Model` or `Program` with scripted messages,
  intercepting control messages at the dispatch layer exactly as `Program` does
  (see [Simulation subset](#simulation-subset))
- **`ScriptedInput`** — fluent builder for message sequences (`->key('q')->enter()`)
- **Snapshot assertions** — `assertGoldenAnsi`, `assertCellGrid`, `assertAnsiEquals`,
  `assertViewIdempotent` — the parsers reject malformed expectation shapes at the
  boundary, so an assertion that would compare nothing fails loudly instead of passing
  (`assertViewIdempotent`'s model-unchanged leg uses a shallow clone: nested
  mutation by reference is out of its reach, output idempotence is the guarantee)
- **`GoldenFile`** — lifecycle-aware `.golden` helper: `load()` (null only when
  missing; unreadable or symlinked goldens throw), `save()` (atomic clobber —
  fixture seeding), `create()` (one-way, refuses an existing golden),
  `update()` (deliberate re-baseline), `resolve()` (fixtures-relative path — the
  traversal *and* symlink jail keeps the walk inside `<base>/fixtures`'s subtree;
  the fixtures root itself is the sanctioned container)
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
- PHPUnit `^10.5` — `Assertions` extends `PHPUnit\Framework\Assert` and the
  `TestResult` verbs report through it; that is the version this suite is
  verified against
- `sugarcraft/sugar-core` (Program, Msg, Model, Cmd)
- `sugarcraft/sugar-cellbuffer` (Buffer for cell-grid assertions)
- `react/event-loop` (the loop `LoopPin` swaps)
- `phpunit/phpunit` `^10.5` — a de facto dependency of the consumer: `Assertions`
  delegates to PHPUnit's `Assert` (declared here as a dev-requirement, because the
  lib is only ever pulled into test code)
- `ext-gd` — only needed to materialize pixels in `Graphics` assertions; it is a
  `suggest`, not a hard requirement, and streams can be decoded without it

## Install

```sh
composer require --dev sugarcraft/sugar-testing:@dev
```

This is a PHPUnit test harness: it belongs in `require-dev` and must not enter
runtime dependencies.

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

$result = ProgramSimulator::new($counter)
    ->addMsgs(...ScriptedInput::new()->key('a')->key('q')->build())
    ->run();

// Real suites COMMIT the golden file; a missing golden then fails loudly (see
// Assertions). The guarded save() below just stands in for that first seeding.
$golden = sys_get_temp_dir() . '/sugar-testing-quickstart.golden';
if (GoldenFile::load($golden) === null) {
    GoldenFile::save($golden, $result->view);
}

Assertions::assertGoldenAnsi($golden, $result->view);
```

`addMsgs()` takes one message or many — pairing `ScriptedInput` with the
simulator is a single spread: `$sim->addMsgs(...$input->build());`
(equivalently, a `foreach` of `addMsgs()` calls over `$input->build()`).

## API

### ProgramSimulator

```php
use SugarCraft\Testing\ProgramSimulator;

$sim = ProgramSimulator::new($program);           // Model or Program

$sim = $sim->withRealCmdRunner(false);             // capture-only: cmds collected, never executed
$sim = $sim->withFakeCmdRunner(fn ($cmd) => $nextMsg); // substitute the Cmd result (new sim — keep it)
$sim->addMsgs($msgA, $msgB);                       // enqueue (variadic — queues on this handle)
$result = $sim->run();                             // replayable from the initial state

echo $result->view;                                // last view() string
echo $result->output;                              // view() across the run + RawMsg/PrintMsg writes
$finalModel = $result->model;                      // the model object — inspect it, don't echo it
```

**Fake-runner caveat.** `withFakeCmdRunner()` substitutes the message a Cmd
*resolves to* — it is not a suppression switch. The simulator defaults to
execute mode, so the real Cmd still runs for its side effects before the fake
message is injected. Pair it with `withRealCmdRunner(false)` when the original
execution must not happen — or reach for the direct capture arms:
`captureCmds()` (collect cmds, never execute) and `withCmdCapture($onCmd)`
(never execute, inject `$onCmd`'s message instead, both in one clone).

**Queue and clone discipline (enforced).** `addMsgs()` mutates the handle you
call it on and marks it sent; the `with*()` configuration setters return a
**clone**. Configure first, then queue — calling a `with*()` setter on a handle
that has already queued messages throws `RuntimeException` rather than silently
running without its queue. (`send()` remains as a deprecated alias of
`addMsgs()`.) `run()` resets all drain state and replays from the
initial model, so repeated runs are identical.

### Simulation subset

`Program::dispatch()` intercepts control messages before your `update()` ever
sees them. The simulator mirrors that layer for the messages listed below —
but it is a simulator, not the real runtime, and the boundary is explicit:

- `BatchMsg` / `SequenceMsg` — each member closure runs as a Cmd would; the
  messages they produce are dispatched, appended behind everything already queued —
  so a member's result lands after later-listed messages, not in member-list order.
  Members must be `Closure` instances (production's own `scheduleCmd()`
  types them the same way); anything else throws. The container itself never reaches `update()`.
- `TickRequest` — `produce()` fires **immediately**. There is no virtual clock:
  the requested delay is not simulated, and nothing is armed on a loop.
- `RawMsg` / `PrintMsg` — written to `$result->output` as a side channel; no
  model turn, no frame.
- `QuitMsg` / `InterruptMsg` — halt the drain, production-style: the model
  never sees them, and any messages still queued when the halt lands are
  counted in `$result->droppedAfterStop` instead of vanishing silently.
  `ScriptedInput::quit()` sends the real thing.
- `SuspendMsg` — DROPPED, and this is a divergence, not parity: the real
  `Program::dispatch()` delivers `SuspendMsg` to `update()` and later emits
  `ResumeMsg`; the simulator does neither, so Ctrl-Z-aware models cannot
  exercise suspend/resume under it.
- `ExecRequest` — **throws** a `RuntimeException`. `exec` suspends onto a real
  terminal; the simulator has none, so it refuses rather than faking a pass.
- **Interceptions that are NOT mirrored** — the real `dispatch()` also swallows
  `AsyncCmd` (arms the promise instead) and `SubscriptionsMsg` (reconciles the
  subscription set instead), routes `ScreenStackPushedMsg` /
  `ScreenStackPoppedMsg` to the root model bypassing the active screen, and
  applies a wrapped Program's `ProgramOptions::$filter` before every `update()`.
  The simulator does none of these: the first two fall through to `update()`
  as ordinary messages — a scripted `AsyncCmd` exercises a path production
  never shows — and the filter is ignored, so filter-dependent models see
  unfiltered input here. Test those surfaces on a real `Program`, not under
  the simulator.
- **Startup trio** — `Program` dispatches `WindowSizeMsg`, `EnvMsg` and
  `ColorProfileMsg` *before* `init()`. The simulator replays that order only
  when armed, so a default run stays byte-identical to pre-parity suites:
  `->withWindowSize(80, 24)->withEnvironment($vars)->withColorProfile($profile)`.
- Subscriptions are fired after `init()` and after each sent message, as
  `Program` does. A runaway cmd↔message loop is refused at 10,000 update
  cycles within a single drain chain — a fixed bound, not a growable one,
  re-armed fresh by each `run()`.

### Assertions

```php
use SugarCraft\Testing\Snapshot\Assertions;

// Golden ANSI snapshot. Argument order: (golden path, actual bytes).
Assertions::assertGoldenAnsi('tests/fixtures/view.golden', $actual);

// Cell-grid diff against a Buffer — schema below.
Assertions::assertCellGrid($grid, $buffer);

// Byte-exact ANSI comparison with readable diff on failure.
Assertions::assertAnsiEquals("\x1b[1;32mHello\x1b[0m", $actual);

// Render purity: view() must be idempotent and must not mutate the model
// (the unchanged-check is a shallow clone — nested-by-reference mutation passes).
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
// One buffer, two rows: row 0 painted "abcd", row 1 painted "ok🎉" (the wide
// rune auto-claims column 3 as its continuation). A plain string row spreads
// its grapheme clusters across cells, one per cell; a `null` row slot skips
// that whole row:
Assertions::assertCellGrid(['abcd', null], $buffer);

// Per-cell forms. An array row must list exactly `buffer->width()` entries —
// one entry per column. Auto-claiming the continuation cell happens only in
// string rows; an array row must state the continuation cell itself after a
// wide rune. Same buffer, now asserting row 1 (row 0 wildcarded):
Assertions::assertCellGrid([
    null,   // whole-row wildcard — the row slot must still exist
    ['o', 'k', ['rune' => '🎉', 'width' => 2], ['rune' => '', 'width' => 0]],
], $buffer);
```

Rules the parser enforces (each violation raises immediately; malformed input
is never skipped into a pass):

- The outer grid must be a list, with exactly `$buffer->height()` rows; a
  height or row-width mismatch fails the assertion, naming the dimensions.
  Ragged rows used to leave their tails never-compared — they are a hard error
  now.
- A row is a plain string (its clusters spread cell-by-cell; a wide
  cluster claims the following continuation cell), a list of cells of
  exactly buffer width, or plain `null` — a legal whole-row wildcard, though
  the row slot must still exist (a fully-wildcard grid then trips the
  zero-cells rule below).
- A cell is `null` (a deliberate wildcard for that one position), a string
  holding a **single narrow** grapheme cluster, or an array with keys from
  `rune` and `width` (`0` continuation / `1` / `2`). Unknown keys throw with a
  teaching message — a typo'd `'run'` would once have unchecked the position
  forever. A string cell claiming more than one cluster throws: spread it
  across cells or use the array form.
- A grid whose every cell is a wildcard **fails** ("compared zero cells") —
  a vacuous pass is the exact failure mode this library exists to prevent.
- Failures dump up to 20 mismatching cells with `(col, row)` coordinates, then
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

Every write goes through a uniquely named temp file and a single publish step
— `rename()` for `save()`/`update()`, `link()` for the no-clobber `create()`
path — so a crash mid-write cannot leave a half-baseline; empty content (0 bytes) is a
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
// ($kittyBytes, $sixelBytes, $itermBytes: captured output of each protocol.)
$kitty  = KittyStream::decode($kittyBytes)->image();      // png(), cols(), rows(), id()…
$sixel  = SixelStream::decode($sixelBytes);               // grid(): PixelGrid, palette()
$iterm  = Iterm2Stream::decode($itermBytes);              // payload(), png(), params()
$mode   = Mode::detect($kittyBytes);                      // which protocol it introduces

// One-shot assertions over a raw stream (dimensions and round-trip need no GD):
GraphicsAssertions::assertInlineImageDimensions($sixelBytes, Mode::Sixel, 80, 40);
GraphicsAssertions::assertProtocolRoundTrip($kittyBytes, Mode::Kitty);
// Pixel diff materializes the raster — needs ext-gd, see below:
GraphicsAssertions::assertRendersTo($kittyBytes, Mode::Kitty, $expectedGrid, tolerance: 0);
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
    ->ticks(5, factory: static fn (int $i, float $s) => new \SugarCraft\Testing\Input\TickMsg($s))  // factory required — see the tick note below
    ->resize(120, 40)
    ->key('q')
    ->build();              // list<Msg>, feed with $sim->send(...$input)
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
`widgets`, `forms`, … each match their own), so the factory is required: a
bare `ticks(5)` throws `InvalidArgumentException` instead of quietly enqueuing
the harness's `SugarCraft\Testing\Input\TickMsg` — which would match **nothing**
in your model and pass vacuously. Script your lib's real tick:

```php
->ticks(3, factory: fn (int $i, float $s) => new \My\Lib\Msg\TickMsg($s))
```

Symbolic builders (`key()`, `arrow()`, `enter()`, …) produce the DECODED message
a keyboard press becomes — `arrow('down')` is a `KeyType::Down` message, not bytes.
`bytes()` carries the real WIRE form instead: arrows are `ESC [` plus a CSI final
byte — `ESC [ A` Up, `ESC [ B` Down, `ESC [ C` Right, `ESC [ D` Left. Never script
an arrow as the literal `→` glyph: a UTF-8 arrow is plain text — it takes the
paste/IME path and decodes as character input, never as `KeyType::Down`. (A PTY
harness driving a program from outside must send the same wire bytes — `ESC [ B`
for down.)

Terminal-shaped input is decoded by sugar-core's real `InputReader` — the parser
`Program` reads stdin with — so a script sees the same messages the live runtime
delivers:

```php
ScriptedInput::new()
    ->paste("line 1\nline 2")         // PasteStartMsg, PasteEndMsg, PasteMsg (sanitized)
    ->bytes("\x1b[200~ab\x1b[20", "1~") // raw reads: an end marker split across two reads
    ->bytes("\x1b")                    // a lone ESC becomes Escape after the silence
    ->build();

$raw = "\x1b[200~pasted\x1b[201~";                       // your unfiltered payload
$paste = ScriptedInput::new()->withSanitizePaste(false)->paste($raw)->build(); // mirrors ProgramOptions::$sanitizePaste
```

`paste()` follows the runtime: payloads are sanitized by default, a paste past
`InputReader::MAX_PASTE_BYTES` arrives as several `PasteMsg`s (concatenate every one
between `PasteStartMsg` and the one after `PasteEndMsg`), and a paste whose end marker
never arrives is closed out eagerly. For a bare `PasteMsg` with no envelope,
`push(new PasteMsg($text))`.

`ProgramSimulator` fires each subscription once after `init()` and once after every sent
message (not every delivered one — subscription-produced messages do not re-fire the
roster; the runtime spaces those fires by interval, the simulator has no clock to space
them with), delivering what it produces before the next sent message. Each sent message
drains its cmd chain through the same pump, bounded at `MAX_CYCLES` = 10,000 cycles per
chain — a model that produces a cmd on every update trips a named throw, not a hang.
`run()` replays from the Program's model each time, so repeated runs give the same result.

### TestResult

`run()` returns a `SugarCraft\Testing\TestResult`:

- `->model` — final model object
- `->view` — last `view()` string
- `->cmds` — every captured command closure, in dispatch order
- `->output` — concatenated `view()` output across the run plus `RawMsg`/`PrintMsg`
  side-channel writes
- `->frames` — every frame the run produced, in order: the `view()` after the init-trio
  updates, after each delivered message's update leg, and the final render — for
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

TapeRecorder::new(__DIR__ . '/.vhs/demo.tape')
    ->header(theme: 'TokyoNight')          // Set Theme/FontSize/Width/Height
                                          // (+ Set TypingSpeed when the optional
                                          //  $typingSpeed float is passed)
    ->type('php examples/demo.php')
    ->enter()
    ->sleep(1.0)
    ->resize(100, 30)                      // '# Resize 100x30' comment line only
    ->type("q")
    ->comment('rendered by .github/workflows/vhs.yml')
    ->save();
```

Everything interpolated is escaped so it cannot forge a standalone directive
line — `type()` neutralizes newlines and control bytes inside the quotes,
`comment()` prefixes every physical line, and even the theme string is escaped.
`line()` is the deliberate verbatim escape hatch: CI executes the tapes these
files describe, so only feed it trusted, generated directives. Beyond the fence
above, the builder roster is `new()` (deprecated alias `to()`), `header()`,
`type()`, `enter()`, `sleep()`,
`resize()`, `comment()`, `line()`, `save()` — and `resize()` is disclosure-only:
the harness supports initial dimensions only (fixed by `header()`), so it records
a `# Resize CxR` comment line and changes nothing; whether upstream VHS grew a
mid-tape resize directive is unverified (2026-11 probe), and emission is
deliberately out of scope — not asserted impossible. The tape-side
companion to the key builders is the static `keyMsgToVhs(KeyMsg): ?string` — it
renders a decoded key message back into VHS `Type` syntax (a quoted rune for
character keys, `Enter`/`PageUp`/`F12` and friends for named keys, `null` for
anything unmappable). `save()` refuses a
symlinked target and publishes atomically (temp file + rename, with a counted
byte-for-byte write), so a crashed or short write can never leave a half-tape;
the full path jail — confining writes under a fixtures subtree — remains
`GoldenFile`'s guarantee, not this class's. See
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

Two caveats about the pin itself, before why it exists:

- Pin before anything else touches it: an already-created loop may own watchers that
  do not transfer. `pinStableClock()` also calls `Loop::stop()` after installing the
  pin, which disarms the shutdown hook `Loop::get()` may already have registered — so a
  late pin can no longer leave the process hanging on a stale loop with a periodic
  timer, and there is no surprise loop run after your last test. The stopped-state that
  `Loop::stop()` leaves is process-wide and permanent, and it cuts both ways: it also
  switches off React's "you never have to call `run()` yourself" convenience, so a suite
  that schedules work on `Loop::get()` and leaves the shutdown hook to execute it gets
  nothing instead. Call `run()` explicitly — which a test that wants to assert on the
  outcome should be doing anyway.
- `StreamSelectLoop`'s constructor calls `pcntl_async_signals(true)` process-wide, at
  bootstrap. `Program::run()` normally does the same in production, so for most suites
  this is the production setting arriving earlier rather than a divergence — but a
  Program constructed with `withoutSignalHandler` or `catchInterrupts: false` never
  enables async signals at all, and a suite built around one of those does diverge.
  Re-check it if your suite does its own `pcntl_signal()` bookkeeping.

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
arms).

`LoopPin::hasStaleClockRisk($loop)` reports whether a given loop is affected, for a suite
that wants to assert or diagnose rather than pin.

## License

MIT

## Credits & inspiration

Originally inspired by the Go [Charm](https://github.com/charmbracelet) ecosystem
(with `charmbracelet/x/exp/golden` and `charmbracelet/teatest` as the nearest prior art);
SugarCraft is developed as a native PHP project.
