# Plan: PTY abstraction lib (`x/xpty` → `candy-pty`, Linux/macOS)

## Goal

New lib for spawning child processes attached to a pseudo-terminal we
control. Mirrors `charmbracelet/x/xpty` minus the Windows ConPTY half.

Conditional plan — only worth doing if we're going to upgrade
candy-wish to host SSH sessions in-process (today it leans on host
sshd). Document the upgrade path; don't ship until the use case is
green-lit.

## Scope

**In**

- Linux + macOS via FFI to libc (`posix_openpt`, `grantpt`, `unlockpt`, `ptsname_r`, `ioctl`)
- Spawn a child via `proc_open` with the slave PTY wired to stdio
- Read / write master PTY
- Resize forwarding via `TIOCSWINSZ` ioctl
- Process lifecycle: wait, kill, exit code
- Composition with candy-core `Program` — let a Program *be* the parent driving a child PTY

**Out**

- Windows ConPTY (separate; not in this wave)
- BSD platforms beyond macOS (FreeBSD/OpenBSD probably work but untested)
- TTY ↔ PTY bridging (chains where stdin is one PTY, stdout is another)
- Job control / process groups beyond a single child

## Naming + placement

- Composer pkg: `sugarcraft/candy-pty`
- Subdir: `candy-pty/`
- Namespace: `SugarCraft\Pty`
- Prefix: **Candy-** (foundation primitive)

## Routes evaluated

| Route | Pros | Cons | Verdict |
|---|---|---|---|
| **FFI to libc** | clean, no shell-out, native types, full control | requires ext-ffi (default since 7.4 on Linux/macOS) | **chosen** |
| Shell out to `/usr/bin/script` | works without FFI | hacky, no resize ctl, no clean exit detection | fallback only if FFI disabled |
| Use existing `php-pty` packagist pkg | code we don't write | survey first: license + maintenance + API fit | revisit if FFI route hits a wall |
| C extension | maximum perf | maintenance + Composer doesn't ship binaries | no |

## Layout

```
candy-pty/
  composer.json
  phpunit.xml
  README.md
  CALIBER_LEARNINGS.md
  src/
    Pty.php                            # facade
    Master.php                         # readonly: master fd resource + size + slave path
    Child.php                          # readonly: pid + slave path + child stdio descriptors
    Libc.php                           # FFI cdef + libc handle
    SizeIoctl.php                      # platform-aware TIOCSWINSZ constant + struct packer
    Spawn.php                          # proc_open variant for slave wiring
    SignalForwarder.php                # forwards SIGWINCH from parent → child via TIOCSWINSZ
    Lang.php
  examples/
    spawn-bash.php
    spawn-vim.php
    pump-output.php
  tests/
    OpenTest.php
    SpawnTest.php
    ResizeTest.php
    ExitCodeTest.php
```

## composer.json

- PHP `^8.1`
- ext-ffi (required)
- Suggest: ext-pcntl (for cleaner child-process management; lib works without it)
- Deps: `sugarcraft/candy-core: @dev` (for `Util/Tty` integration)

## FFI surface (`libc.so.6` / `libSystem.dylib` on macOS)

```c
int  posix_openpt(int flags);
int  grantpt(int fd);
int  unlockpt(int fd);
int  ptsname_r(int fd, char *buf, size_t buflen);
int  ioctl(int fd, unsigned long request, ...);
int  close(int fd);
int  read(int fd, void *buf, size_t count);
int  write(int fd, const void *buf, size_t count);
int  tcsetattr(int fd, int optional_actions, const struct termios *tp);
int  tcgetattr(int fd, struct termios *tp);
int  fork(void);
int  setsid(void);
int  dup2(int oldfd, int newfd);
int  execvp(const char *file, char *const argv[]);
int  waitpid(int pid, int *wstatus, int options);
int  errno(void);    # actually __errno_location() / __error() depending on platform
```

Library name lookup: `ffi.libdir` + `libc.so.6` on Linux,
`/usr/lib/libSystem.B.dylib` on macOS, env override
`SUGARCRAFT_LIBC` for unusual setups.

## Platform-specific constants

| Constant | Linux | macOS |
|---|---|---|
| `O_RDWR` | `02` | `0x0002` |
| `O_NOCTTY` | `0400` | `0x20000` |
| `TIOCSWINSZ` | `0x5414` | `0x80087467` |
| `TIOCGWINSZ` | `0x5413` | `0x40087468` |
| `TIOCSCTTY` | `0x540E` | `0x20007461` |

`SizeIoctl::request()` returns the right constant for the host
platform via `PHP_OS_FAMILY`.

## Public API

```php
use SugarCraft\Pty\Pty;

$pty = Pty::open();
$child = $pty->spawn(
    cmd: ['/bin/bash', '-i'],
    env: ['TERM' => 'xterm-256color', 'HOME' => $_SERVER['HOME']],
    cols: 100,
    rows: 30,
);

# Pump
$pty->write("ls -la\n");
$bytes = $pty->read(timeout: 0.05);   # returns null on timeout, '' on EOF
echo $bytes;

# Resize on host SIGWINCH
$pty->resize(cols: 120, rows: 40);

# Wait
$exit = $child->wait();   # blocks; returns int exit code
$pty->close();
```

Non-blocking variant (for candy-core integration):

```php
$pty->setBlocking(false);
$bytes = $pty->read(8192);    # returns '' immediately if no data
```

## Spawn algorithm

```
1. master_fd = posix_openpt(O_RDWR | O_NOCTTY)
2. grantpt(master_fd); unlockpt(master_fd)
3. slave_path = ptsname_r(master_fd)
4. proc_open(
       cmd,
       descriptors = [
           0 => ['file', slave_path, 'r'],
           1 => ['file', slave_path, 'w'],
           2 => ['file', slave_path, 'w'],
       ],
       options = ['create_new_console' => false],   # ignored on Linux/macOS
   )
5. ioctl(master_fd, TIOCSWINSZ, {cols, rows, xpix=0, ypix=0})
6. set master_fd non-blocking via stream_set_blocking(false)
```

`proc_open` opens the slave path *three times* — once per descriptor —
which means three separate file descriptors all pointing at the slave
pty device. The kernel handles this fine; the alternative (dup the
slave fd) requires `pcntl_fork` which we want to avoid.

## Resize forwarding

```php
public function resize(int $cols, int $rows): void
{
    $ws = $this->packWinsize($cols, $rows);
    $rc = Libc::lib()->ioctl($this->masterFd, SizeIoctl::SET, $ws);
    if ($rc !== 0) {
        throw new PtyException('TIOCSWINSZ failed: errno=' . Libc::errno());
    }
}
```

`packWinsize()` produces a 4×u16 little-endian struct: `[ws_row, ws_col, ws_xpixel, ws_ypixel]`.

## candy-core integration (separate plan / follow-up)

`candy-pty` is a primitive. To use it in `candy-wish` for in-process SSH:

```php
$pty = Pty::open();
$child = $pty->spawn(['/bin/bash', '-l'], $env, ...);

# Pump bytes between SSH channel and PTY
while (!$ch->eof() && !$child->exited()) {
    $fromSsh = $ch->read(4096, timeout: 0.01);
    if ($fromSsh !== null) $pty->write($fromSsh);
    $fromPty = $pty->read(4096, timeout: 0.01);
    if ($fromPty !== null) $ch->write($fromPty);
}
```

Track the upgrade as a separate plan (`plan-candy-wish-pty.md`) once
candy-pty is solid.

## Implementation slices

### PR1 — Libc cdef + posix_openpt round-trip (~1 day)

- `Libc.php` FFI cdef
- `Pty::open()` returns Master with master_fd + slave path
- `Master::close()`
- Tests: open + ptsname + close round-trip on Linux + macOS CI

### PR2 — Spawn via proc_open (~1.5 days)

- `Spawn.php` wiring slave to descriptors
- `Pty::spawn(cmd, env, cols, rows)` returns Child
- `Child::pid`, `Child::wait()`, `Child::exited()`
- Tests: spawn `echo hello`, read output, assert exit code 0

### PR3 — Resize via ioctl (~half day)

- `SizeIoctl::request()` platform constant
- `Pty::resize($cols, $rows)`
- Tests: spawn `tput cols && tput lines`, resize, read again, assert new dims

### PR4 — Non-blocking I/O + read/write helpers (~half day)

- `Pty::setBlocking(bool)`
- `Pty::read($len, $timeout = null)` with stream_select
- `Pty::write($bytes)`
- Tests: non-blocking read on empty PTY returns '' immediately; with timeout returns null after timeout

### PR5 — Signal forwarder + edge cases (~1 day)

- `SignalForwarder` — installs SIGWINCH handler that calls `$pty->resize($newCols, $newRows)` from candy-core's terminal-size query
- Reap zombie children via SIGCHLD handler (or `waitpid(WNOHANG)` polling)
- Handle `EAGAIN` / `EWOULDBLOCK` cleanly in read
- Handle `EINTR` retries
- Tests: resize-during-running scenario

### PR6 — examples + matrix entries (~half day)

- `spawn-bash.php`, `spawn-vim.php`, `pump-output.php`
- macOS CI matrix entry (in addition to Linux)
- All cross-cutting touch-ups

## Test strategy

- **Linux CI** (existing): full PHPUnit suite
- **macOS CI** (new): same suite — verifies platform constant divergence
- **Skip on Windows**: `if (PHP_OS_FAMILY === 'Windows') $this->markTestSkipped(...)` at fixture level
- **Skip if no FFI**: `if (!extension_loaded('ffi')) $this->markTestSkipped(...)`
- Integration tests: spawn small commands (`echo`, `tput`, `cat`) with deterministic output; never spawn interactive shells in tests

## Caveats / open questions

1. **proc_open with slave path on macOS** — opening `/dev/ttysXX` three
   separate times for stdin/stdout/stderr works on Linux but macOS may
   require dup2 via pcntl_fork. Verify in PR2; add a pcntl-fork fallback
   if needed (libc `fork` + `dup2` + `execvp` direct).
2. **TIOCSCTTY** — child needs to claim the slave as its controlling
   terminal so signals (Ctrl+C) reach it. proc_open doesn't do this on
   its own. We may need a tiny shim binary that does
   `setsid(); ioctl(0, TIOCSCTTY, 0); execvp(...)` and proc_open the shim.
   Investigate — upstream `creack/pty` (Go) handles this with `fork+exec`.
3. **macOS sandboxing (T2/M-series)** — corp-managed Macs may deny PTY
   allocation. `posix_openpt` returns -1 with `EPERM`. Document the
   symptom + recommend disabling sandbox for dev.
4. **/dev/ptmx permissions** — most distros: `crw-rw-rw- root tty` so any
   user can open. Some hardened distros restrict — bail with a clear
   error.
5. **Reaping zombies** — after child exits, `waitpid` must be called or
   the process becomes a zombie. The Recorder pattern naturally calls
   `Child::wait()` on its destructor; document the requirement.
6. **PCNTL absence** — pcntl is *usually* available on Linux/macOS but
   some shared-host PHP installs strip it. Where we'd use pcntl_fork,
   prefer proc_open paths; only require pcntl as a soft dep for SIGCHLD
   handling (otherwise poll waitpid every tick).
7. **FFI overhead** — each FFI call is ~1µs. read/write are called per
   byte burst, not per byte. ~1000 calls/sec on a typical TUI = 1ms/sec.
   Negligible.
8. **Composer "ext-ffi" require** — `composer install` fails on Windows
   with `ext-ffi` listed as required. Workaround: list it in `require`
   anyway (Windows users probably *do* have ffi shipped) and document.
   Or move to `suggest` and runtime-check.

## Effort

| Slice | Effort |
|---|---|
| PR1 Libc + open | 1 day |
| PR2 Spawn | 1.5 days |
| PR3 Resize | half day |
| PR4 Non-blocking I/O | half day |
| PR5 Signal forwarder + edges | 1 day |
| PR6 Examples + matrix | half day |
| **Total** | **~4-5 days** |

candy-wish in-process upgrade: another **2-3 days**, separate plan.

## Dependencies

- candy-core `Util/Tty` for terminal-size queries (already exists)
- ext-ffi (default since PHP 7.4 on Linux/macOS)
- Optional ext-pcntl for cleaner SIGCHLD; soft dep

## Tracking

- `MATCHUPS.md` — new row: `[charmbracelet/x/xpty] | candy-pty | candy-pty/ | sugarcraft/candy-pty | SugarCraft\Pty | 🟡 (Linux/macOS only) | PTY primitive — open / spawn / resize`
- `PROJECT_NAMES.md` — naming entry
- `CONVERSION.md` — phase row
- `UPSTREAM_OPPORTUNITIES.md` — flip `x/xpty` row to 🟡 on PR1, 🟢 on PR6
- `docs/index.html` — homepage tile
- `media/candy-pty.png` — 256² icon
- `candy-pty/CALIBER_LEARNINGS.md` — capture caveats 1, 2, 3, 5 above
- `candy-wish` upgrade tracked separately (`plan-candy-wish-pty.md`, not in this wave)
