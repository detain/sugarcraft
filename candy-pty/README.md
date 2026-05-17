# candy-pty

[![Tests](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![Coverage](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=candy-pty)](https://codecov.io/gh/detain/sugarcraft)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](../LICENSE)

PHP port of [`charmbracelet/x/xpty`](https://github.com/charmbracelet/x/tree/main/xpty) —
the pseudo-terminal primitive Charm uses to drive child processes
inside their TUIs. Open a master/slave PTY pair, spawn a child with
its stdio wired to the slave, pump bytes between the host and the
child, and forward host resizes into the child via `TIOCSWINSZ`.

**Status**: Linux + macOS. Windows ConPTY is a separate concern
tracked in `plans/x-windows.md`.

## Install

```sh
composer require sugarcraft/candy-pty
```

Requires PHP 8.1+ with `ext-ffi`. `ext-pcntl` is optional — the lib
polls `waitpid()` when pcntl is absent and `SignalForwarder` degrades
to a no-op.

## Quickstart

```php
use SugarCraft\Pty\Pty;

$pty   = Pty::open();
$child = $pty->spawn(
    ['/bin/bash', '-c', 'echo $TERM; uname -s; date'],
    ['TERM' => 'xterm-256color'],
    100, 30,                              // cols × rows
);

$pty->setBlocking(false);
$out = '';
while (!$child->exited()) {
    $chunk = $pty->read(4096, 0.05);     // 50 ms timeout
    if ($chunk === null || $chunk === '') continue;
    $out .= $chunk;
}
$exit = $child->wait();
$pty->close();

echo $out;
```

### DI-friendly (preferred for libraries)

For consumers that want to stay decoupled from the POSIX backend
(useful in tests + when a Windows ConPTY sidecar lands in v2), resolve
the `PtySystem` through the factory:

```php
use SugarCraft\Pty\PtySystemFactory;

$system = PtySystemFactory::default();   // throws UnsupportedPlatformException on Windows
$pair   = $system->open(100, 30);
$master = $pair->master();
$child  = $pair->slave()->spawn(['/bin/bash', '-l'], null, 100, 30, controllingTerminal: true);
```

Tests can swap in a stub `PtySystem` without touching libc.

## API at a glance

| Call | What it does |
|---|---|
| `Pty::open(): Pty` | `posix_openpt + grantpt + unlockpt + ptsname_r`. Returns a Pty exposing `master` (readonly fd + slavePath). |
| `$pty->spawn(array $cmd, ?array $env, int $cols=80, int $rows=24, bool $controllingTerminal=false): Child` | `proc_open` with slave-path descriptors + initial TIOCSWINSZ. Pass `controllingTerminal: true` to route through `bin/pty-shim.php` so the child claims the slave PTY as its ctty (Ctrl+C → SIGINT, job control); requires ext-pcntl. |
| `$pty->read(int $len=8192, ?float $timeout=null): ?string` | `null` on timeout, `''` on EOF, bytes otherwise. EINTR-safe. |
| `$pty->write(string $bytes): int` | Returns bytes written. |
| `$pty->setBlocking(bool $blocking): void` | Toggles non-blocking mode on the master fd. |
| `$pty->resize(int $cols, int $rows): void` | TIOCSWINSZ on the master fd. |
| `$pty->size(): array{cols,rows,xpix,ypix}` | TIOCGWINSZ readback. |
| `$pty->stream(): resource` | Cached `php://fd/` wrapper around the master fd for direct PHP-stream use. |
| `$pty->close(): void` | Idempotent. Routes through `fclose` if `stream()` was materialised, else `close(2)` via FFI. |
| `$child->pid: int` | OS process id. |
| `$child->wait(): int` | Blocks via 10ms `proc_get_status` poll, returns exit code. Idempotent. |
| `$child->exited(): bool` | Non-blocking probe. |

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

The forwarder defaults to `pcntl_async_signals(true)` so handlers
fire between PHP opcodes; pass `async: false` if your event loop
already polls `pcntl_signal_dispatch()` itself.

## Recording sessions (Recorder tap)

`PumpOptions` accepts an optional `SugarCraft\Core\Recorder` —
when set, `PosixPump` tees stdin chunks (`recordInputBytes`) and
master-read chunks (`recordOutput`) into the recorder on the same
loop iteration as the read. Null = zero overhead.

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
// or driven through SugarCraft\Vcr\Player::play() against a candy-core Program.
```

Resize events still need a separate `SignalForwarder` callback that
chains into `$recorder->recordResize($cols, $rows)` — the pump
itself stays clear of SIGWINCH detection so it can be reused for
non-interactive recordings.

## Examples

- [`examples/spawn-bash.php`](examples/spawn-bash.php) — The simplest end-to-end slice: `PtySystemFactory::default()->open()` → `$pair->slave()->spawn(['bash', ...])` → drain master → reap. Start here.
- [`examples/pump-output.php`](examples/pump-output.php) — Long-running counter; demonstrates non-blocking read with timeout, line-by-line pumping.
- [`examples/resize-forwarding.php`](examples/resize-forwarding.php) — Wire `SignalForwarder` to deliver host SIGWINCH into the child PTY's TIOCSWINSZ; observe the child's `tput cols / lines` flip mid-stream.

## Library lookup

Defaults: `libc.so.6` on Linux, `/usr/lib/libSystem.B.dylib` on macOS.
Override via the `SUGARCRAFT_LIBC` env var for unusual setups (musl,
Alpine, custom sysroots).

## Mirrors

| Charm symbol                      | candy-pty                                                |
|-----------------------------------|----------------------------------------------------------|
| `xpty.Open()`                     | `Pty::open()`                                            |
| `xpty.Pty.Start(cmd)`             | `Pty::spawn(cmd, env, cols, rows)`                       |
| `xpty.Pty.Read(buf)`              | `Pty::read($len, $timeout)`                              |
| `xpty.Pty.Write(buf)`             | `Pty::write($bytes)`                                     |
| `xpty.Pty.Resize(cols, rows)`     | `Pty::resize(cols, rows)`                                |
| `xpty.Pty.Size()`                 | `Pty::size()`                                            |
| `signalpty.NotifyResize(c, pty)`  | `SignalForwarder::attachSigwinch($pty, $sizeProvider)`   |

## Compared to node-pty / creack/pty / portable-pty

Cross-ecosystem parity table for the dominant PTY libraries in Go,
Rust, and Node.js. The goal is "as good as `creack/pty` on Linux and
macOS" — not a kitchen-sink port. This table is deliberately honest
about gaps: Windows ConPTY, foreground-job control, and worker-thread
support are flagged as planned-or-missing rather than papered over.

| Feature | candy-pty | creack/pty (Go) | portable-pty (Rust) | node-pty (Node.js) |
|---|---|---|---|---|
| Open / close PTY pair | ✅ `PtySystemFactory::default()->open()` | ✅ `pty.Open()` | ✅ `native_pty_system().openpty()` | ✅ `pty.spawn()` |
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
| Windows ConPTY | ❌ planned (v2 sidecar; see `plans/x-windows.md`) | ❌ Linux/macOS only | ✅ `ConPtySystem` | ✅ `winpty` / ConPTY |
| Dependency-free DI seam | ✅ `Contract\PtySystem` interface | ❌ concrete `*Pty` only | ✅ `PtySystem` trait | ❌ concrete bindings |

Legend: ✅ shipping today · ⚠️ partial / opt-in / caller-driven · ❌ not
implemented. Method names cite real upstream symbols — see
`docs/CONCEPTS.md` for the porting rationale behind each row.

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
`proc_open` and the actual cmd. Requires `ext-pcntl`. Costs ~5-50
ms of shim startup per spawn — opt-in because non-interactive
spawns (`echo`, `tput`, `bash -c '…'`) don't benefit.

## Architecture

The library is organised in two layers:

### Contract interfaces (`src/Contract/`) — pure signatures, no logic

| Contract | Upstream mirror | Upcoming POSIX implementation |
|---|---|---|
| `PtySystem` | `portable-pty.PtySystem` | `PosixPtySystem` |
| `PtyPair` | `portable-pty.PtyPair` | `PosixPtyPair` |
| `MasterPty` | `creack/pty.Pty` / `portable-pty.MasterPTY` | `PosixMasterPty` |
| `SlavePty` | `creack/pty.Pty` / `portable-pty.SlavePty` | `PosixSlavePty` |
| `Child` | `creack/pty.Cmd` / `portable-pty.Process` | `PosixChild` |
| `Process` | `creack/pty.Cmd` (non-PTY spawn) | `PosixProcess` |
| `Termios` | `portable-pty.Termios` | `PosixTermios` / `SttyTermios` |
| `Pump` | `candy-wish.InProcessTransport` | `PosixPump` |

### POSIX implementation (`src/Posix/`) — implementation layer

The `Posix*` classes implement the contracts above using Linux/macOS syscalls:
FFI into libc for `posix_openpt/grantpt/unlockpt/ptsname_r`, `proc_open`
for child spawning, `ioctl(TIOCSWINSZ/TIOCGWINSZ)` for resize, and
`FFI::cdef()` tcgetattr/tcsetattr/cfmakeraw for termios. The factory
`TermiosFactory` selects `PosixTermios` (FFI) when `ext-ffi` is available
and falls back to `SttyTermios` (shell-out `stty`) otherwise.

## Known limitations

- **Linux + macOS only.** Windows ConPTY is a separate port.

## License

MIT — see [LICENSE](../LICENSE).
