# candy-pty concepts

Audience: PHP developer who's never written a PTY-aware program before.
By the end you'll know what a pseudo-terminal is, why the master/slave
split exists, what makes Ctrl+C "just work" in a real shell but not in
a naively-spawned child process, and what each candy-pty contract is
for.

This is the orientation doc — the [`README`](../README.md) is the
quickstart and the API reference. Read this once; read the README
every time you sit down to write code.

## What is a PTY?

A **pseudo-terminal** is a pair of kernel character-device endpoints
that act exactly like a hardware serial line, but with two PHP / Go /
Rust processes on either side instead of a modem and a DEC VT220.
The kernel allocates a write buffer between them; bytes the parent
writes on one end show up as input on the other and vice versa.

```
+------------+        +-----------------------+        +-------------+
|   parent   |  fd N  |  kernel ringbuffer    |  fd 0  |   child     |
|   process  |<------>|  (line discipline,    |<------>|   process   |
|  (master)  |        |   termios, winsize)   |        |   (slave)   |
+------------+        +-----------------------+        +-------------+
       ^                                                      |
       | TIOCSWINSZ                                stdout / stderr
       | SIGWINCH                                       fd 1, 2
```

Why use one instead of plain pipes? Because programs that "feel
interactive" — shells with line editing, editors with full-screen
redraws, `top`, `vim`, `ssh` — query the terminal for size, want
keystrokes character-by-character (not line-by-line), turn echo on
and off, and react to job-control signals. A pipe is just bytes;
a PTY is bytes plus the [termios](#cooked-vs-raw-mode-termios)
attributes that make all of the above work.

candy-pty wraps the four libc syscalls (`posix_openpt` / `grantpt` /
`unlockpt` / `ptsname_r`) that allocate a fresh PTY pair on Linux and
macOS, plus the ioctls that drive it.

## Master vs slave

The "master" is the end the **parent process** keeps. The "slave" is
the end the **child process** binds its stdio to. When you call
`posix_openpt()` you get back a single file descriptor — the master.
The slave isn't an fd yet; it's a kernel-side endpoint with a device
path (`/dev/pts/N` on Linux, `/dev/ttysNNN` on macOS) the parent
discovers via `ptsname_r(masterFd, buf, size)`.

```php
$pair   = PtySystemFactory::default()->open(80, 24);
$master = $pair->master();           // SugarCraft\Pty\Contract\MasterPty
$slave  = $pair->slave();            // SugarCraft\Pty\Contract\SlavePty
echo $slave->path();                 // "/dev/pts/14"
```

To spawn the child, candy-pty hands the slave's path to `proc_open()`
three times — once each for stdin/stdout/stderr. The kernel opens
`/dev/pts/14` three times, you get three file descriptors in the
child's address space, and now the child can do `fread(STDIN)` /
`fwrite(STDOUT)` exactly like a terminal app.

The master fd stays in the parent. Reading from it gets the child's
stdout/stderr bytes; writing to it puts characters in the child's
stdin queue, after termios processing.

## Cooked vs raw mode (termios)

`termios` is the kernel structure that holds a terminal's attributes:
echo on/off, canonical (line-buffered) vs raw (byte-by-byte) input,
which character is the "interrupt" key (default Ctrl+C → SIGINT),
how to translate `\n` ↔ `\r\n` on output, and roughly 30 other
flags. Both the parent and the child can mutate it via
`tcgetattr` / `tcsetattr`; the kernel honours whatever the most
recent call set.

**Cooked mode** (the default) is what scripts and batch programs
want: input arrives line-by-line, the kernel handles backspace
internally, Ctrl+C generates SIGINT to the foreground process group.
Friendly for `cat | grep`.

**Raw mode** is what interactive TUIs want: every keystroke arrives
immediately, no kernel-side editing, no signal injection from
control characters. Editors and shells flip in and out of raw mode
constantly — vim wants raw while you're typing inside a buffer,
cooked while it's prompting for `:` commands.

candy-pty exposes the contract:

```php
$termios = TermiosFactory::open(STDIN_FILENO);   // FFI or stty
$saved   = $termios->current();
$raw     = $termios->makeRaw();                  // immutable copy
$raw->apply();
// ... run TUI ...
$saved->apply();                                 // restore on exit
```

`makeRaw()` returns a new immutable instance (canonical SugarCraft
immutable + fluent pattern); you keep `$saved` to restore later. The
factory picks `PosixTermios` (libc FFI) when ext-ffi is available
and falls back to `SttyTermios` (shell-out to `stty`) otherwise —
the contract is the same.

## Controlling terminal (TIOCSCTTY)

A "controlling terminal" is the tty associated with a Unix
**session**. Each session has at most one ctty; the kernel uses it
to decide who gets SIGINT when someone types Ctrl+C, SIGTSTP for
Ctrl+Z, SIGHUP when the tty disappears. Crucially: a process that
has *no* ctty receives *no* tty-driven signals — Ctrl+C just turns
into a regular `\x03` byte and disappears into the void.

By default `proc_open()` does NOT make the slave PTY into the
child's ctty. The child's stdio talks to the slave, but the child
isn't a session leader and the slave isn't its ctty. So Ctrl+C goes
nowhere. This is fine for non-interactive children (echo, true,
tput) but breaks interactive shells, editors, anything with job
control.

To fix this you need three syscalls in the child, between `fork()`
and `exec()`:

1. `setsid()` — make the child its own session leader.
2. `ioctl(0, TIOCSCTTY, 0)` — claim the slave (now on fd 0) as ctty.
3. `pcntl_exec()` — replace self with the real command.

But PHP's runtime is **fork-hostile** — zval allocators, opcache,
atexit handlers and FFI handles all carry over and corrupt the
child. You can't safely `pcntl_fork()` from a real PHP app. The
workaround is the **`bin/pty-shim.php`** trampoline: prepend
`[PHP_BINARY, /abs/path/pty-shim.php]` to the cmd, let `proc_open()`
fork cleanly into a fresh PHP, have *that* shim do the three
syscalls and `pcntl_exec()` to the actual command.

```php
$child = $slave->spawn(['/bin/bash', '-i'], null, 80, 24,
    controllingTerminal: true,       // route through bin/pty-shim.php
);
$master->write("\x03");              // Ctrl+C → SIGINT to bash
```

Costs ~5–50 ms of shim startup per spawn — opt-in because most
non-interactive callers don't need it. See
[`CALIBER_LEARNINGS.md`](../CALIBER_LEARNINGS.md) entry
`[pattern:tioscctty-shim]` for the post-mortem.

## SIGWINCH propagation

When you resize your terminal emulator, the kernel sends `SIGWINCH`
to the host PHP process. The child running inside the PTY doesn't
get it automatically — its "terminal" is the slave PTY whose winsize
is set by `TIOCSWINSZ` on the master, not by the host's emulator.
You have to forward.

candy-pty ships `SignalForwarder::attachSigwinch()`:

```php
SignalForwarder::attachSigwinch(
    $master,
    fn () => SugarCraft\Core\Util\Tty::size(),    // ['cols'=>N, 'rows'=>M]
);
```

It installs a `pcntl_signal(SIGWINCH, ...)` handler that calls the
size provider, reads the new dims, and pipes them to
`$master->resize($cols, $rows)`. The handler is wrapped in a
try-catch — **signal handlers must not throw** (it corrupts the
call stack at the next opcode). Same rule for the size provider:
if it errors, the SIGWINCH is silently dropped, not propagated.

By default `pcntl_async_signals(true)` so handlers fire between
opcodes without manual polling. Pass `async: false` if your event
loop already calls `pcntl_signal_dispatch()` on a tick.

## Byte pump (`PosixPump`)

Once you've spawned a child, something has to shuttle bytes
between the host's STDIN/STDOUT and the master fd. `PosixPump::run()`
is that something:

```php
$pump = new PosixPump();
$exit = $pump->run($master, STDIN, STDOUT, $child, new PumpOptions());
```

Inside, a single `stream_select()` loop watches:

- host STDIN → master (forward keystrokes to the child),
- master → host STDOUT (forward output to the user),
- a timeout that triggers optional `keepalive` and `onSigwinch`
  callbacks (`PumpOptions`),
- the child's `exited()` probe.

Termination semantics:

- **child exits** → pump drains the master with a `flushDeadlineSec`
  window (default 500 ms) for tail bytes, then returns the exit
  code.
- **STDOUT hits EPIPE** → user closed their terminal, pump bails
  out and returns -1.
- **STDIN hits EOF** → pump writes a VEOF byte (default `\x04`) to
  the master so the child's `read()` returns 0, then waits
  `stdinEofGraceSec` (default 300 ms) for the child to exit on its
  own. If still running, pump returns -1 and the **caller** decides
  whether to send SIGHUP or close the master.

The pump deliberately does **not** call `Child::wait()` when the
child is still alive. The caller (e.g. `candy-wish`'s
`InProcessTransport`) needs to enforce its own kill-on-stdin-EOF
policy without the pump holding it hostage. See plan step P2.5 for
the rationale.

## Child lifecycle (`ChildPollTrait`)

`PosixChild` and `PosixProcess` both consume the package-internal
`ChildPollTrait` to expose the canonical waitpid-style lifecycle:

```php
$child->pid();         // OS pid
$child->exited();      // non-blocking probe via proc_get_status()
$child->wait();        // 10ms-poll loop, blocks until exit
$child->exitCode();    // null until exit, cached after
$child->kill($sig);    // posix_kill, no-throw
```

Gotchas the trait hides:

1. **`proc_close()` returns -1 after `proc_get_status()` reaps the
   child.** PHP's runtime consumes the waitpid slot the first time
   `proc_get_status()` sees `running=false`. So we capture
   `$status['exitcode']` first, then call `proc_close()` purely to
   release the resource, ignoring its return value.
2. **`wait()` is idempotent.** Subsequent calls return the cached
   exit code without re-entering the poll loop.
3. **Destructor zombie reaping.** If the consumer forgets to call
   `wait()`, the destructor calls `pollDestruct()` which does a
   best-effort `proc_get_status` + `proc_close` so the kernel
   doesn't keep a zombie around. Suppression is deliberate —
   destructors must not throw.
4. **`PosixProcess::wait()` overrides the trait** to drain captured
   stdout/stderr pipes every poll iteration. Without that drain,
   `yes | head`-style children deadlock on a full pipe buffer
   while we sleep.

## Why a contract-based DI design

candy-pty splits surface from implementation:

```
src/Contract/                src/Posix/
├── PtySystem.php            ├── PosixPtySystem.php
├── PtyPair.php              ├── PosixPtyPair.php
├── MasterPty.php            ├── PosixMasterPty.php
├── SlavePty.php             ├── PosixSlavePty.php
├── Child.php                ├── PosixChild.php
├── Process.php              ├── PosixProcess.php
├── Pump.php                 ├── PosixPump.php
└── Termios.php              ├── PosixTermios.php
                             └── SttyTermios.php  (FFI-free fallback)
```

Two reasons:

1. **Testability.** Tests in `candy-wish`, `candy-shell`, etc. can
   inject a stub `PtySystem` that returns scripted in-memory streams
   without touching libc. Production code resolves the real one via
   `PtySystemFactory::default()`.
2. **Windows ConPTY v2.** When the Windows sidecar lands (see
   `plans/x-windows.md`), a `WinConPtySystem` will implement the
   same contract. Application code that calls
   `PtySystemFactory::default()->open()` doesn't change; only the
   factory's `match` on `PHP_OS_FAMILY` grows a new arm.

The contracts are intentionally narrow — read, write, resize, size,
spawn, wait, kill — so a new backend doesn't have to implement
PHP-stream-wrapping or `php://fd/N` quirks. `Pump` and `Termios` are
contracts for the same reason: a `MockPump` in tests, an
`InMemoryTermios` in unit tests, no FFI required.

The deprecated facades `Pty`, `Spawn`, `Child` (top-level `src/`)
implement the new contracts as adapters, so consumers on the old
shape keep working while migrating. They will go away at v2.0.

## Backend selection via `SUGARCRAFT_PTY_BACKEND`

`PtySystemFactory::default()` reads the `SUGARCRAFT_PTY_BACKEND`
environment variable before picking a backend. On POSIX this defaults
to `posix-ffi`. Setting it to `sidecar` or `pecl` causes an immediate
`UnsupportedPlatformException` noting the backend is deferred to
phase 12. Unrecognised values throw `\InvalidArgumentException` listing
the four valid options (`posix-ffi`, `sidecar`, `pecl`, `auto`).

This design keeps the door open for phase-12 backends without any
call-site changes: application code always calls
`PtySystemFactory::default()`, and only the factory grows new arms
when those backends are wired in.

## Further reading

- `bin/pty-shim.php` — the ctty trampoline, ~50 lines of PHP.
- `src/PumpOptions.php` — every knob that tunes pump behaviour.
- `src/Posix/PosixPump.php` — the canonical `stream_select` loop.
- `CALIBER_LEARNINGS.md` — postmortems and rules-of-thumb.
- `plans/sugarcraft-is-a-mono-logical-twilight.md` — the project plan
  that drove the P0–P5 ports.
- Upstream parity tables: see [`README`](../README.md) "Compared to
  node-pty / creack/pty / portable-pty."

@see creack/pty — https://github.com/creack/pty
@see portable-pty — https://docs.rs/portable-pty
@see node-pty — https://github.com/microsoft/node-pty
@see charmbracelet/x/xpty — https://github.com/charmbracelet/x/tree/main/xpty
