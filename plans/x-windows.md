# x-windows — Windows ConPTY backend plan (stub)

**Status:** drafted · **Effort:** 4–5 d · **Phase:** deferred (phase 12)

> This is a stub. Full implementation is in
> `plans/leftover/phase-12-deferred/step-04-windows-conpty.md`.

---

## Goal

Ship `SugarCraft\Pty\Windows\ConPtySystem` and have the full P0–P5
acceptance criteria from the [PTY plan](../PTY_PLAN.md) hold identically
on Windows.

---

## FFI surface

All ConPTY interaction goes through `kernel32.dll` via PHP FFI (ext-ffi;
requires Windows 10 1809+):

| Export | Purpose |
|---|---|
| `CreatePseudoConsole` | Allocate a new ConPTY pseudo-terminal pair |
| `ResizePseudoConsole` | Resize the ConPTY viewport (rows × columns) |
| `ClosePseudoConsole` | Shut down and release a ConPTY handle |
| `CreateProcessW` | Spawn the child process with `STARTUPINFOEXW` carrying the ConPTY output pipe |
| `InitializeProcThreadAttributeList` | Build the `LPPROC_THREAD_ATTRIBUTE_LIST` for `STARTUPINFOEXW` |
| `UpdateProcThreadAttribute` | Inject the ConPTY output pipe into the child startup info |

The ConPTY pair returned by `CreatePseudoConsole` consists of two
`HANDLE`s (input write, output read) that live in the same process
space as PHP. These must be passed to the child via the extended
`STARTUPINFOEXW` mechanism.

---

## Threading model — TBD

ConPTY's input and output pipes are **blocking** on the Console API level.
Several approaches are on the table; the implementation step will pick
one:

1. **Async ReadFile/WriteFile** — issue overlapped I/O requests and pump
   them from the ReactPHP event loop.
2. **Sidecar process** — run the child + ConPTY inside a small Go or
   Rust helper that speaks stdio-framed messages, avoiding the threading
   problem entirely.
3. **Dedicated async PHP thread** — a `pthreads`-backed worker thread if
   `ext-pthreads` is available (not assumed).

See `plans/leftover/phase-12-deferred/step-04-windows-conpty.md` § Threading
for the chosen approach.

Open question: where does `SUGARCRAFT_PTY_BACKEND=conpty` switch in? Likely
in `PtySystemFactory` alongside the existing `UnsupportedPlatformException`
on Windows, replacing it with a `ConPtySystem` instance when the env var or
auto-detection calls for it.

---

## Open questions

- How does `pcntl` absence interact with the existing `SignalForwarder`?
  On POSIX the forwarder uses `pcntl_signal` / `pcntl_async_signals`.
  On Windows this path is a no-op or needs an alternative event-loop-based
  mechanism.
- Should `SUGARCRAFT_PTY_BACKEND=conpty` be the default on Windows, or
  must it be explicitly opted into?
- What is the migration path for the legacy `Pty`, `Spawn`, `Child` facades
  on Windows? Are they stubbed, or do they delegate to `ConPtySystem`?
- ConPTY requires Windows 10 1809+. Should there be a pre-flight check in
  `ConPtySystem::isSupported()` that verifies the build number?

---

## Acceptance criteria

Parity with the Linux/Darwin acceptance matrix from `pty-matrix.yml`
(`candy-pty/pty-matrix.yml`):

- P0: `PtySystemFactory` resolves to `ConPtySystem` on Windows; throws
  `UnsupportedPlatformException` on other platforms.
- P0: `ConPtySystem::open()` returns a `MasterPty` / `SlavePty` pair.
- P1: Child process spawned through `CreateProcessW` + `STARTUPINFOEXW`
  receives correct environment and working directory.
- P1: `MasterPty::resize($rows, $cols)` calls `ResizePseudoConsole`.
- P2: `Pump` reads stdout/stderr from the ConPTY output pipe and pumps
  bytes to the registered callback.
- P2: `MasterPty::close()` calls `ClosePseudoConsole` and reaps the child.
- P3: `SignalForwarder` emits `SIGWINCH` to the child when `resize()`
  is called.
- P4: `PTY_shim` (`bin/pty-shim.php`) works on Windows via ConPTY backend.
- P5: Full `pty-matrix.yml` test suite passes on Windows (GitHub Actions
  `windows-latest` runner).

---

## Dependencies

- **Phase 12** — full implementation lands in
  `plans/leftover/phase-12-deferred/step-04-windows-conpty.md`.
- **conPTY FFI** — requires `ext-ffi` enabled; document the Windows 10
  1809+ minimum in `composer.json` `suggest`.
- **ReactPHP event loop** — async read/write on ConPTY pipes requires
  the existing `React\EventLoop\LoopInterface` integration already
  present in `PosixPump`.

---

## Cross-links

- Parent plan: [PTY_PLAN.md](../PTY_PLAN.md) § Non-goals (line 53)
- Parent plan: [sugarcraft-is-a-mono-logical-twilight.md](./sugarcraft-is-a-mono-logical-twilight.md) § Non-goals (line 53) + § Deferred (line 827)
- Implementation: [plans/leftover/phase-12-deferred/step-04-windows-conpty.md](./leftover/phase-12-deferred/step-04-windows-conpty.md)
- libc FFI counterpart: [candy-pty](../candy-pty/) (already shipped for Linux/Darwin)
