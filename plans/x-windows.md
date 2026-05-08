# Plan: native Windows console support (`x/windows`)

## Goal

Make SugarCraft TUIs run as first-class citizens on Windows Terminal /
modern ConHost (Windows 10 1809+) without ConPTY. Today every Windows
code path in `candy-core/src/Util/Tty.php` silently no-ops:

- `openTty()` returns `null` (line 44)
- `onResize()` returns `false` (line 125)
- `hasStty()` returns `false` (line 157), making `enableRawMode()` and `size()` fall through to no-op + 80×24 fake size

Net effect: a TUI on Windows draws a static frame at 80×24, with cooked-mode line-buffered input and zero resize handling. Unusable.

## Scope

**In**

- `ENABLE_VIRTUAL_TERMINAL_PROCESSING` for output (ANSI passthrough)
- `ENABLE_VIRTUAL_TERMINAL_INPUT` for input (xterm-style key sequences)
- Raw mode (clear `LINE_INPUT` + `ECHO_INPUT` + `PROCESSED_INPUT`)
- UTF-8 codepage (`SetConsoleCP` / `SetConsoleOutputCP` to 65001)
- Console size query (`GetConsoleScreenBufferInfo`)
- Resize handling via poll loop (no SIGWINCH equivalent)
- Ctrl+C / Ctrl+Break / window-close via `SetConsoleCtrlHandler` → `InterruptMsg`
- Mintty / MSYS / Git-Bash / WSL detection with fallback to POSIX path
- CI matrix entry: `windows-latest` running candy-core tests
- Bail with a clear error on Windows < 1809 (no VT support)

**Out**

- ConPTY (separate concern; tracked as future addendum to `x-xpty.md`)
- Legacy ConHost console-API rendering (writing `INPUT_RECORD`s, calling `WriteConsoleOutput` etc.). We declare 1809+ as the floor.
- 16-bit code page support (CJK shift-JIS, EUC-KR). UTF-8 only.
- Windows Forms / GUI subsystem.

## Why FFI to `kernel32.dll`

PHP on Windows ships **`ext-ffi` by default since 7.4** but **does not** ship
`ext-pcntl` or `ext-posix`. So we cannot call any POSIX TTY API from PHP
on Windows. The only realistic clean path is FFI to `kernel32.dll`.

Every API we need is synchronous, takes simple value/struct args, no
callback marshaling required (with one exception, the Ctrl handler — see
caveat below). FFI is a clean fit.

## Architecture

```
candy-core/src/Util/
  Tty.php                    # façade — picks backend by platform / mintty
  Tty/
    Backend.php              # interface (isTty / openTty / size / enableRawMode / restore / onResize / drainSignals)
    PosixBackend.php         # current shell-out impl (extracted from Tty.php)
    WindowsBackend.php       # new FFI-backed impl
    Kernel32.php             # FFI cdef + thin handle wrappers
    EnvDetect.php            # MSYSTEM, TERM_PROGRAM, WSL_INTEROP detection
```

`Tty.php` keeps its current public API verbatim. Its constructor picks
a backend:

```php
$this->backend = match (true) {
    EnvDetect::isWsl()                    => new PosixBackend($stream),  # WSL is Linux
    EnvDetect::isMintty()                 => new PosixBackend($stream),  # mintty is a pty pipe
    DIRECTORY_SEPARATOR === '\\'          => new WindowsBackend($stream),
    default                                => new PosixBackend($stream),
};
```

No downstream caller (Renderer, InputReader, Program) sees the backend
swap.

## FFI surface (`kernel32.dll`)

Defined once, lazily, in `Kernel32::lib()`:

```c
typedef void* HANDLE;
typedef unsigned long DWORD;
typedef unsigned int  UINT;
typedef int           BOOL;

HANDLE GetStdHandle(DWORD nStdHandle);
BOOL   GetConsoleMode(HANDLE h, DWORD *lpMode);
BOOL   SetConsoleMode(HANDLE h, DWORD dwMode);
BOOL   GetConsoleScreenBufferInfo(HANDLE h, void *lpInfo);
BOOL   SetConsoleCP(UINT wCodePageID);
BOOL   SetConsoleOutputCP(UINT wCodePageID);
UINT   GetConsoleCP(void);
UINT   GetConsoleOutputCP(void);
BOOL   SetConsoleCtrlHandler(void *HandlerRoutine, BOOL Add);
HANDLE CreateFileW(const wchar_t *name, DWORD access, DWORD share,
                   void *sa, DWORD disp, DWORD flags, HANDLE templ);
BOOL   CloseHandle(HANDLE h);
DWORD  GetLastError(void);
```

## Mode flag values

| Constant | Hex | Direction |
|---|---|---|
| `ENABLE_PROCESSED_OUTPUT` | `0x0001` | output (set) |
| `ENABLE_VIRTUAL_TERMINAL_PROCESSING` | `0x0004` | output (set) |
| `DISABLE_NEWLINE_AUTO_RETURN` | `0x0008` | output (set) |
| `ENABLE_PROCESSED_INPUT` | `0x0001` | input (clear in raw mode) |
| `ENABLE_LINE_INPUT` | `0x0002` | input (clear in raw mode) |
| `ENABLE_ECHO_INPUT` | `0x0004` | input (clear in raw mode) |
| `ENABLE_VIRTUAL_TERMINAL_INPUT` | `0x0200` | input (set) |
| `ENABLE_WINDOW_INPUT` | `0x0008` | input (set, lets us see resize events) |

Standard handle ids: `STD_INPUT_HANDLE = -10`, `STD_OUTPUT_HANDLE = -11`,
`STD_ERROR_HANDLE = -12`.

## Public API — unchanged

`Util\Tty` keeps these methods verbatim, with Windows-correct behaviour
behind each:

```php
public function isTty(): bool
public static function openTty(): ?array  # CONIN$ / CONOUT$ on Windows
public function size(): array             # GetConsoleScreenBufferInfo.srWindow
public function enableRawMode(): void
public function restore(): void
public static function onResize(\Closure $onResize): bool
public static function drainSignals(): bool
```

## Implementation slices (one PR each)

### PR1 — FFI scaffold (~half day) ✅ merged `a2da599`

- New: `src/Util/Tty/Backend.php` (interface)
- New: `src/Util/Tty/Kernel32.php` (FFI cdef + handle helpers)
- New: `src/Util/Tty/WindowsBackend.php` (skeleton: only `isTty()` + `size()` implemented; `enableRawMode()` / `restore()` no-op)
- Refactor: extract POSIX impl from `Util/Tty.php` into `Tty/PosixBackend.php`. Keep `Util/Tty.php` as façade.
- Tests: `tests/Util/Tty/PosixBackendTest.php` (existing tests moved). `WindowsBackendTest.php` skipped on non-Windows.

### PR2 — raw mode + restore (~half day) ✅ merged `e2fb396`

- `WindowsBackend::enableRawMode()`:
  - capture `GetConsoleMode(stdin)` → `$savedInputMode`
  - capture `GetConsoleMode(stdout)` → `$savedOutputMode`
  - capture `GetConsoleCP()` / `GetConsoleOutputCP()` → `$savedCp` / `$savedOutCp`
  - `SetConsoleMode(stdin, $savedInputMode & ~PROCESSED & ~LINE & ~ECHO | VT_INPUT | WINDOW_INPUT)`
  - `SetConsoleMode(stdout, $savedOutputMode | PROCESSED_OUT | VT_PROCESSING | DISABLE_NL_AUTO_RETURN)`
  - `SetConsoleCP(65001)` / `SetConsoleOutputCP(65001)`
- `WindowsBackend::restore()` — reverse of all four
- Hook into `Program::run()` — already calls `Tty::enableRawMode/restore`, so no Program changes needed
- Tests: round-trip mode capture + restore via stub Kernel32

### PR3 — resize via poll (~half day) ✅ merged `8a31660` (#268)

- `WindowsBackend::onResize(Closure $cb)` — register the closure on the backend
- Add `pollResize()` method called once per event-loop tick (via `Tty::drainSignals` redirect):
  - `GetConsoleScreenBufferInfo(stdout)` → current `srWindow.{Right-Left+1, Bottom-Top+1}`
  - compare against last poll; if changed, fire callback
- Wire into `Program`'s tick loop (already calls `drainSignals()`)
- Tests: drive a stub Kernel32 returning two different sizes across two ticks; assert one callback

### PR4 — Ctrl handler → InterruptMsg (~half day) ✅ merged `028643c` (#269)

- Allocate a `\FFI\CData` callback for `SetConsoleCtrlHandler` (requires `FFI::dynamicFunction`, PHP 8.4+)
- Handler must be reentrant-safe — runs on a separate Windows thread. **Only** sets a process-global `volatile` flag (use `\Shmop` — see caveat 6 below)
- `pollResize()` (renamed `pollEvents()`) checks the flag each tick; if set, posts `InterruptMsg` into the Program's msg queue
- Register handlers for `CTRL_C_EVENT (0)`, `CTRL_BREAK_EVENT (1)`, `CTRL_CLOSE_EVENT (2)`
- Tests: simulate flag-set; assert `InterruptMsg` posted

### PR5 — mintty / WSL / pipe-stdin detection (~half day)

- `EnvDetect::isWsl()` — read `/proc/sys/kernel/osrelease` if it exists; check for `microsoft` / `WSL`
- `EnvDetect::isMintty()` — env vars: `MSYSTEM`, `TERM_PROGRAM=mintty`, `MINTTY_SHORTCUT`
- `EnvDetect::isCygwin()` — env var `OSTYPE=cygwin`
- `Tty.php` factory dispatches to `PosixBackend` for any of those
- Pipe-stdin fallback: when `stream_isatty(STDIN) === false` on Windows, try `CreateFileW("CONIN$", GENERIC_READ|GENERIC_WRITE, FILE_SHARE_READ|FILE_SHARE_WRITE, NULL, OPEN_EXISTING, 0, NULL)` and same for `CONOUT$`. Mirrors POSIX `/dev/tty` open. Returns `null` if `GetLastError() == 6` (invalid handle).
- Tests: env-var matrix → expected backend class

### PR6 — CI matrix (~half day)

- `.github/workflows/ci.yml` — add `runs-on: windows-latest` job, PHP 8.1/8.2/8.3, run `cd candy-core && composer install && vendor/bin/phpunit`
- Document in README that Windows support is for Windows Terminal / ConHost 1809+
- Tests: full candy-core suite must pass on `windows-latest`

### PR7 — downstream smoke tests (~1 day)

- Add `windows-latest` smoke matrix entries for: `sugar-bits`, `sugar-prompt`, `candy-shell`, `candy-shine`
- Each runs the lib's existing PHPUnit suite — no new tests, just verifying the candy-core Windows backend doesn't break consumers
- Drop "Windows is a known gap" wording from sugar-prompt README on green

## Test strategy

- **Unit tests on Linux**: stub `Kernel32` via dependency injection. `WindowsBackend` takes a `Kernel32Interface`. Tests pass a recording stub that asserts the right calls happen in the right order with the right args.
- **Integration tests on Windows CI**: real FFI, real console (CI runners give you a console). Run candy-core's full suite plus a Windows-only suite that asserts mode flags actually flipped on a real handle.
- **No mintty CI**: too brittle. Document expected behaviour, manual smoke before each release.

## Caveats / open questions

1. **Ctrl handler thread safety** — Windows runs the handler on a *different OS thread* than the main PHP thread. The Zend VM is not reentrant. Touching any Zend memory (string concat, array set, even refcount bumps) from the handler can corrupt the heap. Safe pattern: handler writes a single byte to a process-shared `\Shmop` segment or sets a `\FFI`-allocated `volatile int` and returns immediately. Main loop polls. Document this as a hard rule in `Kernel32.php`.
2. **`\FFI` + opcache** — preloading FFI definitions for performance is fiddly. First cut: defer; allocate per-process at first call. Profile later.
3. **Resize poll cost** — `GetConsoleScreenBufferInfo` is a syscall. At 60Hz that's ~60 syscalls/sec; negligible. Don't bother with `ReadConsoleInputW` event-driven path unless someone reports lag.
4. **Mintty stdin** — `stream_isatty` returns `false` because mintty uses pipes. Without our env-var detection it'd fall to "pipe stdin" handling. We must detect mintty *before* checking `stream_isatty`. Order matters in the `Tty.php` factory.
5. **WSL distro running Windows-side PHP** — PHP installed inside WSL runs Linux ELF and uses POSIX naturally. PHP installed on the Windows side and called from WSL via interop runs as Windows. Detection works in both directions.
6. **`SetConsoleCtrlHandler` callback lifetime** — PHP's `\FFI::cast` of a closure must be kept alive for the whole program; if it's GC'd, Windows calls into freed memory. Store it as a static class property on `WindowsBackend` so it lives until process exit.
7. **Cmd.exe vs PowerShell vs Windows Terminal** — all three host ConHost (or Windows Terminal which uses ConHost over a pipe). Same code path. No special handling.
8. **Codepage round-trip** — restoring the codepage on exit is critical. If we set 65001 and crash, the user's cmd.exe is left in UTF-8 mode and subsequent non-Unicode commands break. Register a shutdown function via `register_shutdown_function([$this, 'restore'])` defensively.

## Effort

| Slice | PR | Effort |
|---|---|---|
| FFI scaffold | PR1 | half day |
| Raw mode | PR2 | half day |
| Resize | PR3 | half day |
| Ctrl handler | PR4 | half day |
| Mintty/WSL detection | PR5 | half day |
| CI matrix | PR6 | half day |
| Downstream smoke | PR7 | 1 day |
| **Total** | | **4-5 days** |

## Dependencies

- None (independent of every other plan in this wave).
- Unblocks: removal of "Windows is a known gap" from sugar-prompt README.

## Tracking

- `MATCHUPS.md` — no new row (it's a candy-core feature, not a new lib)
- `UPSTREAM_OPPORTUNITIES.md` — flip `x/windows` row from ⚪ to 🟡 on PR1 land, 🟢 on PR7 land
- `CALIBER_LEARNINGS.md` (root) — add gotchas 1, 4, 6, 8 above as they're proven in CI
- `candy-core/CALIBER_LEARNINGS.md` — add per-lib gotchas (FFI cdef caching, mode-flag defaults)
- README + AGENTS.md — note Windows minimum: Win10 1809 / Windows Terminal
