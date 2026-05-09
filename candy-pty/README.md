# candy-pty

[![Tests](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![Coverage](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=candy-pty)](https://codecov.io/gh/detain/sugarcraft)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](../LICENSE)

PHP port of [`charmbracelet/x/xpty`](https://github.com/charmbracelet/x) —
the pseudo-terminal primitive used by Charm to drive child processes
inside a TUI. Lets you open a master/slave PTY pair, spawn a child with
its stdio wired to the slave, and forward resizes from the host TTY
into the child via `TIOCSWINSZ`.

**Status**: Linux + macOS only. Windows ConPTY is a separate concern
tracked in `plans/x-windows.md`.

## Install

```sh
composer require sugarcraft/candy-pty
```

Requires PHP 8.1+ with `ext-ffi`. `ext-pcntl` is optional — the lib
polls `waitpid()` when pcntl is absent.

## Quickstart

```php
use SugarCraft\Pty\Pty;

$pty = Pty::open();        // posix_openpt + grantpt + unlockpt + ptsname_r
echo $pty->slavePath();    // /dev/pts/3 (Linux) or /dev/ttysXX (macOS)
$pty->close();
```

`Pty::spawn()`, `Pty::read()` / `write()`, `Pty::resize()` ship in
follow-up PRs (PR2-PR5).

## Why FFI?

`posix_openpt`, `grantpt`, `unlockpt`, `ptsname_r`, and `ioctl(TIOCSWINSZ)`
are libc primitives with no PHP equivalents. Shelling out to
`/usr/bin/script` works but provides no clean exit-code detection and
no resize control. FFI keeps the call-graph in-process and gives us
native errno reporting.

## Library lookup

By default the lib loads `libc.so.6` on Linux and
`/usr/lib/libSystem.B.dylib` on macOS. Override via the
`SUGARCRAFT_LIBC` env var for unusual setups (musl, Alpine, custom
sysroots).

## Mirrors

| Charm symbol                 | candy-pty                            |
|------------------------------|--------------------------------------|
| `xpty.Open()`                | `Pty::open()` *(PR1)*                |
| `xpty.Pty.Start(cmd)`        | `Pty::spawn(cmd, env, cols, rows)` *(PR2)* |
| `xpty.Pty.Resize(cols, rows)`| `Pty::resize(cols, rows)` *(PR3)*    |
| `xpty.Pty.SetReadDeadline()` | `Pty::read(len, timeout)` *(PR4)*    |

See [`plans/x-xpty.md`](../plans/x-xpty.md) for the full roadmap.

## License

MIT — see [LICENSE](../LICENSE).
