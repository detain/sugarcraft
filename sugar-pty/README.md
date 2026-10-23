# sugar-pty

> Formerly published as `sugarcraft/candy-pty` (renamed 2026-10). <!-- @rename-keep -->

[![Tests](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![Coverage](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=sugar-pty)](https://codecov.io/gh/detain/sugarcraft)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

sugar-pty — pseudo-terminal primitives for PHP 8.3+, the building blocks for
driving child processes inside terminal UIs. Open a master/slave PTY pair, spawn a child with
its stdio wired to the slave, pump bytes between the host and the
child, and forward host resizes into the child via `TIOCSWINSZ`.

**Status**: Linux + macOS. Windows ConPTY is not covered here.

## Install

```sh
composer require sugarcraft/sugar-pty
```

Requires PHP 8.3+ with `ext-ffi`. `ext-pcntl` is optional — the lib
polls `waitpid()` when pcntl is absent and `SignalForwarder` degrades
to a no-op. `ext-posix` is optional too — `Child::kill()` signals
through `Libc::kill()`, which uses `posix_kill()` when it is loaded and
libc `kill(2)` over FFI otherwise.

## Quickstart

Contract-first — resolve the backend through the factory so tests can
inject a stub `PtySystem` without touching libc (and so a Windows
ConPTY sidecar can land in v2 without call-site changes):

```php
use SugarCraft\Pty\PtySystemFactory;

$system = PtySystemFactory::new();       // throws UnsupportedPlatformException on Windows
$pair   = $system->open(100, 30);        // cols × rows — winsize applied at open
$master = $pair->master();

// Environment warning: a non-null $env REPLACES the entire parent
// environment (proc_open 5th-arg semantics). Passing ['TERM' => …]
// alone leaves the child with no PATH, HOME or LANG. Spread the parent
// env and override what you mean to. (env: null inherits the parent
// env wholesale — including any secrets in it; see Security.)
$env   = [...(array) getenv(), 'TERM' => 'xterm-256color'];
$child = $pair->slave()->spawn(
    ['/bin/bash', '-c', 'echo $TERM; uname -s; date'],
    $env,
    100, 30,                              // cols × rows
    controllingTerminal: true,            // Ctrl+C / job control for interactive children
);

$out = '';
// Drain until read() returns '' (EOF) — NOT until exited() first flips.
// A child that has just exited can still have bytes sitting in the
// master's kernel buffer; stopping at exited() intermittently truncates
// the tail. EOF arrives when every slave fd has closed and the buffer
// is drained.
while (true) {
    $chunk = $master->read(4096, 0.05);   // 50 ms timeout
    if ($chunk === null) {
        continue;                         // timeout — child may still be producing
    }
    if ($chunk === '') {
        break;                            // EOF
    }
    $out .= $chunk;
}
$exit = $child->wait();
$master->close();

echo $out;
```

If a child might hold the slave open forever (never producing EOF),
bound the drain with a deadline and break on `$child->exited()` with
one final tail read — that is the shape
[`examples/spawn-bash.php`](examples/spawn-bash.php) demonstrates.

### Legacy facade (deprecated)

`SugarCraft\Pty\Pty` and the `Child` class its `spawn()` returns are
`@deprecated` shims kept for consumers predating the contract split —
`Pty::open()` delegates to the same `PosixPtySystem` path, and both
go away at v2.0. New code should use `PtySystemFactory` as above.

## API at a glance

| Call | What it does |
|---|---|
| `$system->open(int $cols=80, int $rows=24): PtyPair` (`PtySystemFactory::new()`) | The canonical path: on Darwin `openpty()` first (single call), falling back to the quartet on -1; on Linux `posix_openpt + grantpt + unlockpt + ptsname_r`. The master fd is `FD_CLOEXEC` (children never inherit it, so closing it delivers the hangup) and the requested winsize is applied at open. Returns a `PtyPair` exposing `master()` (readonly fd + slavePath) and `slave()`. The deprecated `Pty::open($cols, $rows)` facade delegates here, so both paths share exactly this implementation. |
| `$pty->spawn(array $cmd, ?array $env, int $cols=80, int $rows=24, bool $controllingTerminal=false): Child` | `proc_open` with slave-path descriptors. `$env` follows proc_open semantics: `null` inherits the full parent environment, a non-null array REPLACES it entirely (merge explicitly — see Quickstart). TIOCSWINSZ is applied before the spawn (throws, no child, on failure) and re-asserted best-effort after it, because macOS zeroes the winsize when the child's slave descriptors open. Pass `controllingTerminal: true` to route through `bin/pty-shim.php` so the child claims the slave PTY as its ctty (Ctrl+C → SIGINT, job control); requires ext-pcntl. |
| `$pair->slave()->spawn(array $cmd, ?array $env, ?int $cols=null, ?int $rows=null, bool $controllingTerminal=false): Child` | Same `$env` replace-vs-null semantics. Window size is a property of the pty, not of the controlling-terminal relationship, so an explicit `$cols`/`$rows` is applied on every spawn (before it, throwing with no child on failure, then re-asserted best-effort after it for macOS) — like `creack/pty.StartWithSize()`. Omit both to keep the size the pair was opened or last resized with (`creack/pty.Start()`, portable-pty `spawn_command()`); pass one to keep the other half. The parent's slave handle is opened `O_CLOEXEC`, so the child holds the slave only at fds 0-2. |
| `$pty->read(int $len=8192, ?float $timeout=null): ?string` | `null` on timeout, `''` on EOF, bytes otherwise. EINTR-safe. |
| `$pty->write(string $bytes): int` | Returns bytes written. |
| `$pty->setBlocking(bool $blocking): void` | Toggles non-blocking mode on the master fd. |
| `$pty->resize(int $cols, int $rows): void` | TIOCSWINSZ on the master fd. |
| `$pty->size(): array{cols,rows,xpix,ypix}` | TIOCGWINSZ readback. |
| `$pty->stream(): resource` | Cached `php://fd/` wrapper around the master fd for direct PHP-stream use. |
| `$pty->close(): void` | Idempotent. `fclose`s the `stream()` wrapper if one was materialised, then always `close(2)`s the original master fd via FFI. Throws `PtyException` if that `close(2)` fails (on either path) or if the `fclose` failed — in the latter case only after the master fd itself was released. |
| `$child->pid(): int` | OS process id. Contract-typed children (`Contract\Child`) declare the *method* only. The `public readonly int $pid` *property* exists on the concrete `PosixChild`/`Child` classes (inherited from the `Child` alias's promoted constructor), but do not rely on it behind a contract type — calling `$child->pid` on a `Contract\Child` fatals. |
| `$child->wait(): int` | Waits via `waitpid` FFI (sub-ms) first, falls back to 10ms `proc_get_status` poll. Returns exit code (signal death = 128+signal). Idempotent. |
| `$child->exited(): bool` | Non-blocking probe. |
| `ControllingTerminal::claim(int $fd): void` | Calls `setsid()` + `ioctl(TIOCSCTTY)` to claim `$fd` as the session's controlling terminal. Used internally by the `controllingTerminal: true` shim path; callable directly from FFI-heavy contexts that want the same effect without the PHP shim. Throws `PtyException` on failure. |

> The winsize and cloexec cells above are deliberate mini-essays —
> design rationale for each lives in [`docs/CONCEPTS.md`](docs/CONCEPTS.md).

## Non-PTY processes

For child processes that don't need a PTY (e.g. a sub-step in a
spinner overlay), `PosixProcess` shares the same lifecycle
(`pid/exited/wait/exitCode/kill`) but binds stdin to `/dev/null`
and lets you capture stdout/stderr into in-memory buffers:

```php
use SugarCraft\Pty\Posix\PosixProcess;

$proc = PosixProcess::spawn(
    ['/bin/sh', '-c', 'echo out; echo err >&2'],
    env: null,
    captureStdout: true,
    captureStderr: true,
);
$exit = $proc->wait();
echo $proc->stdoutBytes();   // "out\n"
echo $proc->stderrBytes();   // "err\n"
```

When a capture flag is `false`, the corresponding stream is
inherited from the parent's `STDOUT` / `STDERR` and the
matching `*Bytes()` accessor returns `''`.

## Resize forwarding

```php
use SugarCraft\Pty\SignalForwarder;
use SugarCraft\Core\Util\Tty;

SignalForwarder::attachSigwinch(
    $pty,
    fn () => Tty::size(),                 // returns ['cols' => N, 'rows' => N]
);
// Now every host SIGWINCH triggers $pty->resize($cols, $rows).
```

For callers that hold a raw `/dev/tty` fd (no `MasterPty` object),
`attachSigwinchToFd()` calls `TIOCSWINSZ` directly on the fd:

```php
use SugarCraft\Pty\SignalForwarder;
use SugarCraft\Pty\Libc;

$ttyFd = Libc::lib()->open('/dev/tty', 0x0002);   // O_RDWR
SignalForwarder::attachSigwinchToFd(
    $ttyFd,
    fn () => Tty::size(),
    fn (int $cols, int $rows) => null,     // optional post-resize callback
);
```

Both methods default to `pcntl_async_signals(true)` so handlers
fire between PHP opcodes; pass `async: false` if your event loop
already polls `pcntl_signal_dispatch()` itself.

Caveats for the raw-fd form: the example's `open(2)` fd is **not**
`O_CLOEXEC`, so it leaks into any child the process later spawns
(including the `stty` helpers the Darwin resize fallback runs) and
nothing in the lib ever closes it — `Libc::lib()->close($ttyFd)`
yourself when you detach. Signal handlers are process-global: a
second `attachSigwinchToFd()`/`attachSigwinch()` silently replaces
the first, so attach once per process.

## Session automation and pools

### `Expect` — scripted automation over a master

Immutable expect-style helper wrapping a `MasterPty`. `Expect::on($master, $readChunk = 4096, ?Recorder)`
binds an instance; `send()` / `sendLine($line, $eol = "\n")` write to
the master immediately (side effect — the returned instance carries no
extra state). The matchers block (default timeout 5 s) until the byte
stream yields them, returning a new instance that exposes the matched
`$lastMatch` and the `$before` text:

| Call | Waits for |
|---|---|
| `expect(string $needle, float $timeoutSec = 5.0)` | literal substring |
| `expectAny(array $needles, float $timeoutSec = 5.0)` | first of the needles |
| `expectPattern(string $regex, float $timeoutSec = 5.0)` | PCRE match |
| `expectEof(float $timeoutSec = 5.0)` | master EOF |

Timeouts raise `ExpectTimeoutException`; EOF before a content match
raises `ExpectEofException`. `withMaxBuffer(?int)` /
`withSearchWindow(?int)` bound retained input on chatty long-running
sessions (buffer growth and needle window respectively). A send whose
bytes the master refuses to accept for the whole `SEND_STALL_BUDGET_SEC`
(0.1 s no-progress window — every accepted byte restarts it) raises
`RuntimeException` instead of spinning.

### `PtyPool` — a concurrency ceiling, not a handle cache

`new(?PtySystem = null, int $maxSize = 50)`; `acquire($cols = 80, $rows = 24): PtyPair`
opens a *fresh* pair (PTY pairs are 1:1 with their slave path and
cannot be reused once a child has been spawned against the slave —
and `open()` measures 0.05 ms anyway), throwing
`PoolExhaustedException` past the cap; `release(PtyPair)` closes the
master and frees the slot; `inFlight()` / `available()` /
`totalAcquired()` report; `drain()` closes every outstanding pair.

### `PtyInputDecoder` — key/mouse events straight off the master

Feeds master reads through sugar-input's `EscapeDecoder`:
`new(MasterPty $master, EscapeDecoder $decoder = new, int $readChunk = 8192)`,
`readEvents(float $timeout = 0.05): list<Event>` returns after one
packet or one timeout (partial escape sequences buffer across calls),
`readEventsBlocking(?float $timeout = null)` keeps reading until it
has events — a **null timeout blocks indefinitely**; pass a budget in
request paths. `remainder()` exposes un-decoded trailing bytes,
`reset()` drops the partial-sequence buffer.

### `AnsiOutputParser` + `SgrHandler` — inspect output without eating it

`AnsiOutputParser::forMaster($master)` reads chunks **verbatim**
(return value is still safe to forward to a real terminal) while a
sugar-ansi-parser `Parser` tracks the SGR state those bytes imply.
`readChunk(float $timeout = 0.05): string` gives the bytes;
`readChunkWithTransitions()` additionally returns the
`[from, to]` `SgrState` pairs crossed; `state()` exposes the current
`SgrState` (`COLOR_BLACK`–`COLOR_WHITE` = 0–7, `COLOR_BRIGHT_*` = 8–15,
`COLOR_DEFAULT` = −4, `COLOR_256` = −1, `COLOR_RGB` = −2); `reset()`
clears parser, state and transition log. The underlying `SgrHandler`
caps its undrained transition ring at `MAX_EVENTS` = 1024 and drops
the OLDEST transition when full — slow consumers lose history head,
never the tail.

### `SignalForwarder::attachSigchld` — push-style reaping (opt-in)

`attachSigchld(callable $reaper, bool $async = true): bool` routes
`SIGCHLD` to your reaper callback (returns `false` without
`ext-pcntl`; the handler never propagates throws). Note the pumps in
this lib deliberately do **not** use it — they poll `exited()` —
because signal dispositions are process-global and `ext-pcntl` is
optional. Use it when you own the process's signal handling and want
exit notifications without a poll timer.

## Recording sessions (Recorder tap)

`PumpOptions` accepts an optional `SugarCraft\Core\Recorder`.
(`SugarCraft\Vcr\Recorder` itself lives in **sugarcraft/candy-vcr**,
a *dev-only* dependency of this package: apps copying the example
below must `composer require sugarcraft/candy-vcr` first.)
When set, `PosixPump` tees stdin bytes (`recordInputBytes`) as they
are delivered to the master — bytes parked behind a full master are
recorded when they land, not when they were read — and master-read
chunks (`recordOutput`) on the iteration that reads them. Null = zero
overhead.

```php
use SugarCraft\Pty\Posix\PosixPump;
use SugarCraft\Pty\PumpOptions;
use SugarCraft\Vcr\Recorder;

$recorder = Recorder::open('/tmp/session.cas');
$opts     = (new PumpOptions())->withRecorder($recorder);
$exit     = (new PosixPump())->run($master, STDIN, STDOUT, $child, $opts);
$recorder->recordQuit();
$recorder->close();
// /tmp/session.cas can now be walked via SugarCraft\Vcr\Format\JsonlFormat
// or driven through SugarCraft\Vcr\Player::play() against a sugar-core Program.
```

Resize events still need a separate `SignalForwarder` callback that
chains into `$recorder->recordResize($cols, $rows)` — the pump
itself stays clear of SIGWINCH detection so it can be reused for
non-interactive recordings.

## Multi-pump

`MultiPump` demuxes multiple PTY sessions from a single
`stream_select` loop — a split-pane viewer, parallel test harness,
or tmux-style supervisor use-case:

```php
use SugarCraft\Pty\PtySystemFactory;
use SugarCraft\Pty\Posix\MultiPump;

$system = PtySystemFactory::new();
$mp     = new MultiPump();

// Register two shells and tee each master to stdout with a prefix.
foreach ([0, 1] as $idx) {
    $pair   = $system->open(80, 24);
    $prefix = "[session-{$idx}] ";
    $sink   = fopen('php://memory', 'w+b');

    $child = $pair->slave()->spawn(
        ['/bin/bash', '-i'],
        [...(array) getenv(), 'TERM' => 'xterm-256color'],  // merge — non-null env replaces
        80, 24,
        controllingTerminal: true,
    );

    $mp->add($pair->master(), $sink, $child);
}

// Drive the multiplexer until every session exits.
while (!$mp->allDone()) {
    $drained = $mp->tick();
    if ($drained > 0) {
        // Drain each per-session sink, tag with prefix, print.
    }
}
```

See [`examples/multi-pump.php`](examples/multi-pump.php) for the full
interactive demo — two shells teed to stdout with per-session prefixes,
quit on Ctrl-C.

## Async (ReactPHP)

Every blocking primitive has an event-loop counterpart, so a ReactPHP
application can drive PTY sessions without stalling its loop:

- **`ReactPump`** — the async `PosixPump`. Registers the PTY master
  (and optional host stdin) with the loop via `addReadStream()`;
  returns a promise that resolves with the sync pump's exit-code
  contract (exit code / `0` no-child / `-1` still-running-at-teardown).
- **`PosixChild::waitAsync()`** — promise-returning `wait()`. Periodic
  non-blocking `exited()` poll (FFI `waitpid(WNOHANG)` fast path);
  cancelling the promise cancels its poll timer only.
- **`MultiPump::runAsync()`** — promise-returning `run()`. One
  `ReactPump` per session; resolves with the session-id → exit-code map
  once every child exits / every master EOFs.

```php
use React\EventLoop\Loop;
use SugarCraft\Pty\Posix\ReactPump;
use SugarCraft\Pty\PtySystemFactory;
use SugarCraft\Pty\PumpOptions;

$pair  = PtySystemFactory::new()->open(80, 24);
$child = $pair->slave()->spawn(['/usr/bin/top'], [...(array) getenv(), 'TERM' => 'xterm-256color'], 80, 24);

$pump = new ReactPump();                     // Loop::get() by default; injectable
$pump->start(
    $pair->master(),
    stdinStream:  STDIN,                     // optional — forwarded to the child
    stdoutStream: STDOUT,                    // optional — receives child output
    child: $child,
    opts:  new PumpOptions(),
    onData: fn (string $bytes) => null,      // optional tap on every output chunk
)->then(function (int $exit) use ($pair): void {
    echo "child exited: {$exit}\n";
    $pair->master()->close();
});

// Meanwhile the loop stays free for timers, sockets, other sessions…
$child->waitAsync()->then(fn (int $code) => error_log("reaped: {$code}"));

Loop::run();
```

`ReactPump::stop()` detaches every loop registration (read/write
streams + timers) and resolves the pending promise — nothing leaks
into the loop. All loop-accepting APIs take an optional
`LoopInterface` so tests and multi-loop apps can inject their own.

Child-exit detection polls `exited()` on a timer (at the
`selectTimeoutUs` cadence for the pump, 15 ms default for
`waitAsync()`) instead of trapping `SIGCHLD`: signal dispositions are
process-global, so a handler would conflict with user code and with
`SignalForwarder`, and `ext-pcntl` is not guaranteed to be loaded.

The blocking APIs (`PosixPump::run()`, `Child::wait()`,
`MultiPump::run()`) are unchanged — the async layer is purely
additive.

## Pump back-pressure

When the master accepts only part of a stdin chunk (the child is not
reading fast enough), `PosixPump` parks the remainder, stops reading
stdin, and watches the master for writability until the remainder
drains — so input is never reordered, never grows without bound, and
is delivered even after stdin has hit EOF. VEOF is sent only behind
the last data byte. While a remainder is still draining after stdin
EOF, every successful write restarts the `stdinEofGraceSec` window: the
grace is "this long with no progress", not a cut-off mid-delivery.

## Pump callbacks

`PumpOptions` exposes four optional callbacks covering the pump loop
lifecycle, plus a `sshDefault()` named constructor for SSH-session-tuned
behaviour:

| Callback | Fires when | Use for |
|---|---|---|
| `keepalive` | idle tick (stream_select → 0) | Legacy heartbeat alias; prefer `onIdle` |
| `onIdle` | idle tick — every `stream_select` timeout | Keepalive pings, polling housekeeping, any periodic task |
| `onSigwinch` | terminal dimensions change | Forward host resize into child's TIOCSWINSZ |
| `onChildExit` | child process exits | Resource cleanup, notification |

`PumpOptions::sshDefault(): self` — SSH-session-tuned preset. Returns a
`PumpOptions` with the same defaults (`chunkBytes`, `selectTimeoutUs`,
`flushDeadlineSec`, `stdinEofGraceSec`, `veof`) previously hardcoded in
`InProcessTransport`. Use when you need SSH-session behaviour outside
the `sugar-ssh-server` transport layer:

```php
$opts = PumpOptions::sshDefault()->withOnIdle(fn () => error_log('alive'));
```

**`onIdle` vs `onSigwinch`:** These are independent hooks.
`onIdle` fires on *every* idle tick (no I/O ready). `onSigwinch`
fires only when `MasterPty::size()` differs from the last known size
— driven by the consumer's `SignalForwarder` callback. Do not conflate
them: if you were passing `onSigwinch(0, 0)` as a fake idle tick,
migrate to `onIdle`.

```php
use SugarCraft\Pty\Posix\PosixPump;
use SugarCraft\Pty\PumpOptions;

$opts = (new PumpOptions())
    ->withOnIdle(fn () => error_log('still alive'))        // every ~50 ms
    ->withOnSigwinch(fn (int $cols, int $rows) => null)   // on dimension change
    ->withOnChildExit(fn (int $code) => error_log("exited $code"));

(new PosixPump())->run($master, STDIN, STDOUT, $child, $opts);
```

## Examples

- [`examples/spawn-bash.php`](examples/spawn-bash.php) — The simplest end-to-end slice: `PtySystemFactory::new()->open()` → `$pair->slave()->spawn(['bash', ...])` → drain master → reap. Start here.
- [`examples/pump-output.php`](examples/pump-output.php) — Long-running counter; demonstrates non-blocking read with timeout, line-by-line pumping.
- [`examples/resize-forwarding.php`](examples/resize-forwarding.php) — Wire `SignalForwarder` to deliver host SIGWINCH into the child PTY's TIOCSWINSZ; observe the child's `tput cols / lines` flip mid-stream.

## Library lookup

Defaults: `libc.so.6` on Linux, `/usr/lib/libSystem.B.dylib` on macOS.
Override via the `SUGARCRAFT_LIBC` env var for unusual setups (musl,
Alpine, custom sysroots).

## Environment variables

Every environment variable this package reads, in one place. The table is
CHECKED, not proof-read: `tests/EnvRosterTest.php` token-scans `src/`, `bin/`
and `tests/` and reds in both directions — a variable read with no row here,
and a row here nothing reads.

| Variable | Read by | Effect |
|---|---|---|
| `SUGARCRAFT_LIBC` | `Libc` | Path to the C library to bind FFI against, instead of the platform default (`libc.so.6` on Linux, `/usr/lib/libSystem.B.dylib` on macOS). For musl, Alpine and custom sysroots. |
| `SUGARCRAFT_PTY_BACKEND` | `PtySystemFactory::new()` | Which PTY backend to build: unset / `auto`, `posix-ffi`, `sidecar`, `pecl`. Unrecognised values throw `\InvalidArgumentException`. See [Backend selection](#backend-selection). |
| `SUGARCRAFT_TERMIOS` | `TermiosFactory` | `stty` forces the subprocess termios backend instead of the FFI one. Anything else leaves the platform default in place. |
| `SUGAR_PTY_HANG_BUDGET` | `tests/Support/HangWatchdog` | Seconds a single test may run before the suite's own watchdog turns a wedged test into a named failure plus a forensic bundle. `0` opts the watchdog out entirely; unset uses the built-in budget. **Test suite only** — it configures nothing in `src/`, and it is here because a contributor chasing a hang needs to find it. |

`SUGARCRAFT_LIBC` and `SUGARCRAFT_TERMIOS` are also read by the test suite,
which sets and restores them around the cases that exercise each branch.

The pre-rename prefix `CANDY_PTY_` is still read, with a deprecation warning, and imported into `SUGAR_PTY_` by `tests/bootstrap.php`. <!-- @rename-keep -->

## Compared to node-pty / creack/pty / portable-pty

Cross-ecosystem parity table for the dominant PTY libraries in Go,
Rust, and Node.js. The goal is "as good as `creack/pty` on Linux and
macOS" — not a kitchen-sink reimplementation. This table is deliberately honest
about gaps: Windows ConPTY, foreground-job control, and worker-thread
support are flagged as planned-or-missing rather than papered over.

| Feature | sugar-pty | creack/pty (Go) | portable-pty (Rust) | node-pty (Node.js) |
|---|---|---|---|---|
| Open / close PTY pair | ✅ `PtySystemFactory::new()->open()` | ✅ `pty.Open()` | ✅ `native_pty_system().openpty()` | ✅ `pty.spawn()` |
| Master read | ✅ `MasterPty::read($len, $timeout)` | ✅ `Pty.Read([]byte)` | ✅ `MasterPty::try_clone_reader()` | ✅ `pty.onData()` |
| Master write | ✅ `MasterPty::write($bytes)` | ✅ `Pty.Write([]byte)` | ✅ `MasterPty::take_writer()` | ✅ `pty.write()` |
| Resize (TIOCSWINSZ) | ✅ `MasterPty::resize($cols, $rows)` | ✅ `pty.Setsize()` | ✅ `MasterPty::resize()` | ✅ `pty.resize()` |
| Get size (TIOCGWINSZ) | ✅ `MasterPty::size()` | ✅ `pty.GetsizeFull()` | ✅ `MasterPty::get_size()` | ⚠️ via `cols`/`rows` props |
| Slave device path | ✅ `SlavePty::path()` | ✅ `Pty.Name()` | ✅ `SlavePty::as_raw_fd()` | ❌ hidden |
| Child spawn on slave | ✅ `SlavePty::spawn($cmd, $env, ...)` | ✅ `pty.Start(cmd)` | ✅ `SlavePty::spawn_command()` | ✅ `pty.spawn(file, args)` |
| Controlling terminal (TIOCSCTTY) | ✅ opt-in via `controllingTerminal: true` | ✅ implicit in `Start()` | ✅ implicit in `spawn_command()` | ✅ implicit |
| Termios raw mode | ✅ `Termios::makeRaw()` (FFI + stty fallback) | ✅ via `golang.org/x/term` | ✅ `Termios` in `nix` crate | ⚠️ caller's responsibility |
| Termios get / restore | ✅ `Termios::current()` / `restore()` | ✅ `term.MakeRaw()` / `Restore()` | ✅ `Termios::set_termios()` | ❌ caller's responsibility |
| SIGWINCH forwarding | ✅ `SignalForwarder::attachSigwinch()` | ✅ `pty.InheritSize()` | ⚠️ caller wires signal handler | ✅ implicit |
| Exit code retrieval | ✅ `Child::wait()` / `exitCode()` | ✅ `cmd.Wait()` / `ProcessState` | ✅ `Child::wait()` | ✅ `pty.onExit()` |
| Signal injection (SIGINT/TERM/KILL) | ✅ `Child::kill($signal)` | ✅ `cmd.Process.Signal()` | ✅ `Child::kill()` | ✅ `pty.kill(signal)` |
| EOF / VEOF handling | ✅ `PosixPump` writes VEOF on stdin EOF | ⚠️ caller drives termios | ⚠️ caller drives termios | ⚠️ caller writes `\x04` |
| Non-blocking master I/O | ✅ `setBlocking(false)` + `stream_select` | ✅ `os.File` non-blocking | ✅ `set_nonblocking()` | ✅ event-driven |
| Byte pump abstraction | ✅ `PosixPump::run()` w/ EOF grace + keepalive | ❌ caller copies bytes | ❌ caller copies bytes | ✅ libuv-driven |
| Async / threaded operation | ⚠️ single-loop pump only (ReactPHP-friendly) | ✅ goroutines built-in | ✅ thread / async runtime | ✅ libuv worker thread |
| Windows ConPTY | ❌ not yet implemented | ❌ Linux/macOS only | ✅ `ConPtySystem` | ✅ `winpty` / ConPTY |
| Dependency-free DI seam | ✅ `Contract\PtySystem` interface | ❌ concrete `*Pty` only | ✅ `PtySystem` trait | ❌ concrete bindings |

Legend: ✅ shipping today · ⚠️ partial / opt-in / caller-driven · ❌ not
implemented. Method names cite real PTY primitives — see
`docs/CONCEPTS.md` for the design rationale behind each row.

## Controlling terminal (Ctrl+C, job control)

Pass `controllingTerminal: true` to `spawn()` when you need
`Ctrl+C` typed at the master to deliver `SIGINT` to the child —
required for interactive shells (`bash -i`), editors (`vim`,
`less`), and anything else that uses tty-driven job control.

```php
$child = $pty->spawn(
    ['/bin/bash', '-i'],
    env: [...],
    controllingTerminal: true,    // claim slave as the child's ctty
);
$pty->write("\x03");              // Ctrl+C → SIGINT to the child
```

Routes the spawn through `bin/pty-shim.php`, which does
`setsid()` + `ioctl(0, TIOCSCTTY, 0)` + `pcntl_exec()` between
`proc_open` and the actual cmd. The `setsid()` + `ioctl(TIOCSCTTY)` logic
is also available directly as `ControllingTerminal::claim(int $fd)` —
usable from FFI-heavy contexts that want the same effect without the
shim overhead. Requires `ext-pcntl`. Costs ~5-50 ms of shim startup per
spawn — opt-in because non-interactive spawns (`echo`, `tput`, `bash -c '…'`)
don't benefit.

## Architecture

### Backend selection

`PtySystemFactory::new()` respects the `SUGARCRAFT_PTY_BACKEND`
environment variable to select which PTY backend to use:

| Value | Behaviour |
|---|---|
| _(unset)_ / `auto` | Platform-appropriate default (same as `posix-ffi` on POSIX) |
| `posix-ffi` | `PosixPtySystem` — FFI into libc `posix_openpt` etc. (Linux / macOS) |
| `sidecar` | Not implemented in v1 — throws `UnsupportedPlatformException` |
| `pecl` | Not implemented in v1 — throws `UnsupportedPlatformException` |

Unrecognised values throw `\InvalidArgumentException` naming the
valid options.

 The termios backend follows the same pattern via `SUGARCRAFT_TERMIOS`
 (`posix-ffi` / `stty`).

### Platform behaviour — `openpty` vs quartet

On macOS (Darwin), `PosixPtySystem::openPtyMaster()` attempts `openpty(3)`
first — a single libc call that opens both master and slave and returns
both file descriptors. If it returns -1 (symbol unavailable on a given
Darwin build), the code falls back to the standard POSIX quartet:
`posix_openpt + grantpt + unlockpt + ptsname_r`.

On Linux, the quartet is used directly. The `openpty` symbol on Linux
lives in `libutil.so.1` rather than `libc.so.6`; `Libc::libutil()` loads
it as a fallback specifically for this symbol. Most Linux systems have
both, so the Darwin-first path is not normally exercised on Linux.

This behaviour is internal to `PosixPtySystem` and requires no consumer
action — `$system->open()` abstracts it away completely.

### Library layout

The library is organised in two layers:

### Contract interfaces (`src/Contract/`) — pure signatures, no logic

| Contract | Counterpart primitive | Upcoming POSIX implementation |
|---|---|---|
| `PtySystem` | `portable-pty.PtySystem` | `PosixPtySystem` |
| `PtyPair` | `portable-pty.PtyPair` | `PosixPtyPair` |
| `MasterPty` | `creack/pty.Pty` / `portable-pty.MasterPTY` | `PosixMasterPty` |
| `SlavePty` | `creack/pty.Pty` / `portable-pty.SlavePty` | `PosixSlavePty` |
| `Child` | `creack/pty.Cmd` / `portable-pty.Process` | `PosixChild` |
| `Process` | `creack/pty.Cmd` (non-PTY spawn) | `PosixProcess` |
| `Termios` | `portable-pty.Termios` | `PosixTermios` / `SttyTermios` |
| `Pump` | `sugar-ssh-server.InProcessTransport` | `PosixPump` |

### POSIX implementation (`src/Posix/`) — implementation layer

The `Posix*` classes implement the contracts above using Linux/macOS syscalls:
FFI into libc for `posix_openpt/grantpt/unlockpt/ptsname_r`, `proc_open`
for child spawning, `ioctl(TIOCSWINSZ/TIOCGWINSZ)` for resize, and
`FFI::cdef()` tcgetattr/tcsetattr/cfmakeraw for termios. The factory
`TermiosFactory` selects `PosixTermios` (FFI) when `ext-ffi` is available
and falls back to `SttyTermios` (shell-out `stty`) otherwise. Every
fallback is logged via `error_log` with its ordinal and the FFI failure
reason (not just the first one per process), and
`TermiosFactory::fallbackCount()` reports the running total.

## Shared foundations

sugar-pty builds on two sibling libraries for input/output escape-sequence handling:

- **[sugar-input](https://github.com/detain/sugarcraft/tree/master/sugar-input)** — `EscapeDecoder` consumes raw bytes from the PTY master and emits keyboard/mouse/osc events. The pty-read loop feeds `Pty::read()` chunks directly into `EscapeDecoder`; no private arrow-key or Ctrl-key buffering.
- **[sugar-ansi-parser](https://github.com/detain/sugarcraft/tree/master/sugar-ansi-parser)** — `Parser` handles ANSI escape sequences in PTY output when inspection is needed (cursor-position reports, SGR state, OSC strings). PTY output that only needs pass-through to the terminal skips this layer entirely.

## Security and trust boundaries

- **`SUGARCRAFT_LIBC` is a code-execution surface.** Naming the C
  library to bind replaces which machine code your process calls. `Libc`
  refuses anything that is not an absolute, regular file with a
  libc-family basename (validation is fail-closed at load — see the
  SECURITY block in `src/Libc.php`), but treat the variable as
  operator-only: never accept it from untrusted config or child input.
- **Spawns take argv arrays** (proc_open list form) — no shell is
  invoked, so there is no shell-injection surface; the controlling-
  terminal shim `pcntl_exec()`s the argv verbatim.
- **`env: null` hands the child your entire environment** — API keys,
  tokens and paths included. The replace-semantics footgun documented
  in the Quickstart has this mirror image: build an explicit allowlist
  when a child should not see the parent's secrets.
- **PTY bytes are untrusted input.** Child output can carry escape
  sequences targeting whatever terminal displays it. The lib is a
  pass-through by design and does not sanitize; when output goes
  anywhere besides a terminal you control, decode with
  `AnsiOutputParser`/`SgrHandler` (inspection without consumption) or
  route through sugar-core's `Sanitize` before rendering.

## Known limitations

- **Linux + macOS only.** Windows ConPTY is not yet supported.
- **ext-ffi is required but disabled by default.** `ext-ffi` is loaded eagerly
  because `FFI::cdef()` must resolve symbols at script startup. Many Linux
  distributions ship PHP with `ffi.enable=0` in `php.ini` (a security hardening
  measure) which causes sugar-pty to throw `PtyException: Failed to load libc`.
  To fix: set `ffi.enable=1` in `php.ini` and restart the web server or
  PHP-FPM pool, or inline at process start: `php -d ffi.enable=1
  your-script.php`. `ffi.enable` is `PHP_INI_SYSTEM` — `ini_set()` at runtime
  has no effect. Two traps worth naming: PHP 8.3 accepts only `0`, `1` and
  `preload` (8.4 adds `lazy`), and an unrecognised string such as `peer`
  casts to `0` — silently disabling FFI and reproducing the exact failure
  this bullet fixes. And `preload` is not "a safer `1`": it is designed for
  FFI against libraries baked into a custom PHP build via the
  `ffi.preload` / `ffi.preload_user` directives, and how it treats a runtime
  `dlopen` of an arbitrary path like `libc.so.6` varies with the build (it
  happened to be accepted on the distro build this was tested on; that is
  incidental, not documented behavior). sugar-pty binds libc with a runtime
  `FFI::cdef()`, so its
  supported setting is `ffi.enable=1` — not `preload`, whose behavior sugar-pty
  does not rely on. Verify what your PHP actually answered:

  ```sh
  php -r 'try { FFI::cdef("int getpid(void);"); echo "FFI cdef OK\n"; }
           catch (Throwable $e) { echo "FFI unavailable: ", $e->getMessage(), "\n"; }'
  ```
- **`/dev/ptmx` access is required.** Every `PosixPtySystem::open()` asks the
  kernel for a new PTY master via `posix_openpt()`, which needs a readable and
  writable `/dev/ptmx` with a mounted `devpts` behind it. Minimal or locked-down
  containers (scratch/distroless images, sandboxes with a read-only or masked
  `/dev`, hosts where `/dev/ptmx` is `0600 root:root`) fail with
  `PtyException: posix_openpt() failed (rc=-1, errno=...)` — typically
  `2 (No such file or directory)` when the node is missing or `13 (Permission
  denied)` when it is inaccessible. Remediation, in order of preference:
  ensure `devpts` is mounted (`mount -t devpts devpts /dev/pts`; Docker and
  Podman do this by default unless `/dev` is overridden), fix the node's
  permissions (`chmod 666 /dev/ptmx` — the standard mode on Linux), or as a
  last resort run the container privileged. The test suite skips PTY tests
  automatically on such hosts rather than failing.
- **Darwin arm64 sizes shell out to `stty` in BOTH directions.** On macOS
  arm64 the real libc `ioctl` is variadic — varargs go on the stack while a
  fixed-arg FFI cdef puts the winsize pointer in a register — so
  `ioctl(TIOCSWINSZ)` fails there, and `ioctl(TIOCGWINSZ)` fails too (the
  read-side breakage was measured in CI: `rc=-1` on a fd that
  `posix_isatty()` had just accepted). `SizeIoctl` therefore keeps an
  `stty(1)` fallback for each direction: SET runs
  `stty -f /dev/fd/<fd> rows R cols C`; GET runs `stty -f /dev/fd/<fd> -a`
  and parses the **BSD** word order (`43 rows` / `137 columns` — a
  GNU-ordered answer must not parse, and a pinned test proves it doesn't).
  Three consequences worth knowing: the fd the child opens is a `dup()`
  alias, because the master itself is `FD_CLOEXEC` and a raw fd name would
  resolve to nothing inside `stty(1)`; reads are **never cached** — every
  `size()` re-queries, so a read-after-resize is ground truth (pixel
  fields report 0 via the fallback — ioctl carries them, `stty -a` output
  is rows/cols only); and identical resizes within a 20 ms window are
  rate-limited so SIGWINCH bursts don't fork a process each. Expect resize
  and size reads on Darwin arm64 to cost milliseconds, not microseconds.
  Both fallbacks retire once macOS ships POSIX 2024 `tcsetwinsize` /
  `tcgetwinsize` (macOS 15's libSystem exports neither).

## License

MIT — see [LICENSE](LICENSE).

## Credits & inspiration

Originally inspired by the Go [Charm](https://github.com/charmbracelet) ecosystem; SugarCraft is developed as a native PHP project.
