# SugarCraft PTY consolidation — full quality push

## Context

SugarCraft is a PHP monorepo porting the Charmbracelet TUI ecosystem. PHP has a reputation for poor PTY support — the goal of this plan is to close that gap and reach feature/quality parity with `node-pty` (Node), `creack/pty` (Go), `portable-pty` (Rust/wezterm) on Linux + macOS.

**Key existing-state finding** (`candy-pty/src/*`, ~1024 LOC): a working FFI-based PTY library already exists. It allocates master/slave via `posix_openpt → grantpt → unlockpt → ptsname_r`, spawns children via `proc_open` with explicit slave descriptors, forwards `SIGWINCH`, supports controlling-terminal via a `bin/pty-shim.php` shim (`setsid` + `ioctl(TIOCSCTTY)`), and is integration-tested against real PTYs.

This is **not** a from-scratch build. It is a consolidation + gap-close + hardening pass that pulls duplicated PTY/TTY plumbing out of `candy-core`, `candy-shell`, and `candy-wish` into `candy-pty`, then adds the missing pieces (FFI termios, reusable Pump abstraction, portable-pty-style trait architecture, comprehensive test matrix).

User decisions (confirmed up front):
- **Skip Windows ConPTY for v1.** Linux + macOS only. Document the Windows path as a deferred follow-up.
- **Termios:** FFI to libc primary, `stty` shell-out as fallback when FFI unavailable.
- **Full consolidation scope:** move raw-mode handling, process spawning, and the byte-pump loop out of consumer libs and into `candy-pty`.
- **Full quality push:** ~4–6 weeks of work, ending at "as good as `node-pty`/`creack` on Linux + macOS".

---

## Current state — what works today

| Concern | Location | Status |
|---|---|---|
| PTY allocation (posix_openpt/grantpt/unlockpt/ptsname_r) | `candy-pty/src/Pty.php`, `candy-pty/src/Libc.php` | ✅ Solid, FFI-based |
| `proc_open` with slave descriptors | `candy-pty/src/Spawn.php` | ✅ Solid |
| Controlling-terminal claim (setsid + TIOCSCTTY) | `candy-pty/bin/pty-shim.php` (proc_open-prepended shim) | ✅ Solid; 5–50ms startup cost |
| Resize ioctl (TIOCSWINSZ / TIOCGWINSZ) | `candy-pty/src/SizeIoctl.php` (Linux + macOS branched constants) | ✅ Solid |
| SIGWINCH forwarder | `candy-pty/src/SignalForwarder.php` | ✅ Solid; supports `pcntl_async_signals` or polled mode |
| EINTR-safe read with deadline retry | `candy-pty/src/Pty.php` (read loop) | ✅ Solid |
| Child lifecycle (proc_get_status poll, EOF, exit code capture) | `candy-pty/src/Child.php` | ✅ Solid |
| `php://fd/N` stream wrapping | `candy-pty/src/Pty.php` (stream cache) | ✅ Solid |
| Real-PTY integration tests | `candy-pty/tests/*` (8 files) | ✅ Solid |
| Raw mode (termios) | `candy-core/src/Util/Tty/PosixBackend.php` via `stty -g` / `stty -icanon -echo` shell-out | ⚠️ Works, but shells out, no FFI path |
| Bidirectional byte pump (stdin↔master, with backpressure + EOF grace) | `candy-wish/src/Transport/InProcessTransport.php` | ⚠️ Works, but bound to candy-wish; not reusable by other consumers |
| Non-PTY process spawning | `candy-shell/src/Process/RealProcess.php` (proc_open pipes) | ⚠️ Duplicates parts of `candy-pty::Child` (proc_get_status polling pattern) |
| Terminal size query | `candy-core/src/Util/Tty/PosixBackend.php` (stty size + env fallback) | ⚠️ Should share path with candy-pty's ioctl readback |
| Windows ConPTY | not implemented | ❌ Deferred (v2) |
| Cross-OS test coverage | Linux primary; macOS CI runs candy-pty only | ⚠️ Expand macOS coverage post-consolidation |

---

## Goals (v1, 4–6 weeks)

1. **Single namespace owns PTY/process/termios plumbing.** `SugarCraft\Pty\*` is the source of truth for: opening PTYs, spawning processes (with or without PTY), termios state, byte pumping, signal forwarding.
2. **`candy-core::Util\Tty::PosixBackend` becomes a thin delegate** to `candy-pty` for raw mode and size — no more `stty` shell-out on the primary code path.
3. **`candy-wish::InProcessTransport` pump loop is extracted** to `candy-pty::Pump` so any consumer (recording, scripting, REPLs, candy-vcr playback) reuses the same backpressure/EOF-grace/SIGWINCH-aware pump.
4. **`candy-shell::RealProcess` migrates** to `candy-pty::Process` (non-PTY spawn) sharing lifecycle code with `Child`.
5. **Portable-pty-style interfaces** (`PtySystem`, `MasterPty`, `SlavePty`, `Child`, `Process`) are introduced so future backends (ConPTY, sidecar) can slot in without touching consumers.
6. **Comprehensive test matrix** — every public method has Linux + macOS coverage; a representative subset runs against bash, zsh, fish, `dash` (POSIX baseline), `python -i`, `vim`, and a SIGWINCH-aware test harness.
7. **Documentation parity** — public API doc-comments cite the equivalent `creack/pty` or `portable-pty` method; `candy-pty/README.md` includes a "compared to node-pty / creack" feature table.

## Non-goals (v1)

- Windows ConPTY backend (tracked separately; `plans/x-windows.md`).
- Replacing ReactPHP as the event loop primitive.
- Pure-PHP termios via ext-readline or custom PECL extension.
- Sidecar binary distribution.
- ANSI parsing (lives in `candy-core::InputReader` + `candy-vt`, stays there).
- VT-state recording semantics (lives in `candy-vcr`, stays there).
- Changes to `candy-core::Util\Tty::WindowsBackend` (it handles native Windows console mode, not PTY allocation; out of scope for this plan).

---

## Architecture

### Interface layer (new — modeled on wezterm's `portable-pty`)

`candy-pty/src/Contract/` — pure interfaces, no logic:

```
SugarCraft\Pty\Contract\
├── PtySystem.php       // factory: open() → PtyPair, capabilities()
├── PtyPair.php         // master() + slave() accessors
├── MasterPty.php       // read/write/resize/size/stream/close
├── SlavePty.php        // path(), spawn(cmd, opts) → Child
├── Child.php           // pid, exited, wait, kill, exitCode
├── Process.php         // non-PTY equivalent of Child (for candy-shell)
├── Termios.php         // get/set, makeRaw, restore, isatty
└── Pump.php            // run(master, stdin, stdout, opts) → exit code
```

### Implementation layer (new + refactored)

`candy-pty/src/Posix/` — Linux/macOS implementation:

```
SugarCraft\Pty\Posix\
├── PosixPtySystem.php       // PtySystem; current Pty::open() logic
├── PosixPtyPair.php         // wraps Master + slave path
├── PosixMasterPty.php       // current Pty.php read/write/resize/stream
├── PosixSlavePty.php        // current Spawn.php logic
├── PosixChild.php           // current Child.php
├── PosixProcess.php         // migrated RealProcess.php (no-PTY proc_open)
├── PosixTermios.php         // NEW — FFI tcgetattr/tcsetattr
├── SttyTermios.php          // NEW — stty shell-out fallback
└── PosixPump.php            // NEW — extracted from InProcessTransport
```

### Existing classes — keep but refactor

```
SugarCraft\Pty\
├── Libc.php                 // extend cdef: add tcgetattr, tcsetattr, struct termios, cfmakeraw
├── SizeIoctl.php            // unchanged
├── SignalForwarder.php      // unchanged
└── bin/pty-shim.php         // unchanged
```

### Convenience facade

`candy-pty/src/Pty.php` stays as a **convenience facade** for backwards compatibility — its current static methods (`Pty::open()`, etc.) delegate to `PosixPtySystem`. Existing call sites in `candy-wish` keep working without changes. New code is encouraged to inject `PtySystem` via DI.

### Termios resolution

`candy-pty/src/TermiosFactory.php` (new):

1. Try `PosixTermios` — attempt to load libc, cdef `tcgetattr`/`tcsetattr`/`cfmakeraw`/`struct termios`. If FFI is disabled or libc load fails, catch and fall through.
2. Fall back to `SttyTermios` — invokes `stty -g` (save) / `stty raw -echo` (apply) / `stty $saved` (restore) via `proc_open`. Same surface, different mechanism.
3. Expose a `capabilities()` method on the returned instance so consumers can log/warn when the fallback is in use.

### Dependency direction (post-consolidation)

```
candy-pty   (no internal deps; ext-ffi, ext-pcntl optional)
    ↑
candy-core  (uses candy-pty for Termios + size queries; Util\Tty\PosixBackend becomes thin)
    ↑
candy-shell, candy-wish, candy-vcr, ...
```

`candy-pty` stays dependency-free at the SugarCraft level. `candy-core` adds `"sugarcraft/candy-pty": "@dev"` and the path-repo entry. Every consuming lib already has candy-pty in its transitive closure via candy-core — only need to verify path repos.

---

## Phases

### P0 — Foundation (week 1)

**Goal:** introduce the interface layer without breaking any callers.

- Add `SugarCraft\Pty\Contract\*` interfaces.
- Refactor existing `Pty.php` / `Spawn.php` / `Child.php` to implement them, in-place under `SugarCraft\Pty\Posix\*`. Keep `SugarCraft\Pty\Pty` as a backward-compatible static facade that delegates to `PosixPtySystem`.
- Extend `Libc.php` cdef with the termios surface: `tcgetattr`, `tcsetattr`, `cfmakeraw`, `cfgetospeed`, plus `struct termios` (use 64-byte buffer + opaque; we don't need to read individual c_cflag bits in PHP — `cfmakeraw` does it for us).
- Add `SizeIoctl::winsize` doc-comment cross-reference to `creack/pty.GetsizeFull`.
- Confirm `candy-wish::InProcessTransport` still passes its full test suite unchanged.
- **Acceptance:** `vendor/bin/phpunit` green for candy-pty, candy-wish, candy-shell, candy-core. No public API removed.

### P1 — Termios FFI primary, stty fallback (week 1–2)

**Goal:** eliminate `stty` from the hot path.

- Implement `Posix\PosixTermios` (`candy-pty/src/Posix/PosixTermios.php`):
  - Constructor takes an int fd or PHP stream resource.
  - `current(): Termios` snapshot — calls `tcgetattr(fd, &buf)`.
  - `makeRaw(): self` — clones, calls `cfmakeraw(&buf)`, returns new instance.
  - `apply(int $when = TCSANOW): void` — calls `tcsetattr(fd, when, &buf)`.
  - `restore(): void` — re-applies the saved snapshot.
  - `isAtty(): bool` — `posix_isatty($fd)` + `stream_isatty` for the stream variant.
- Implement `Posix\SttyTermios` (`candy-pty/src/Posix/SttyTermios.php`):
  - Same surface but `current()` runs `proc_open(['stty','-g'])`, captures the saved-mode string.
  - `makeRaw()` records the intent; `apply()` runs `stty raw -echo`; `restore()` runs `stty <saved>`.
  - Acceptable for environments without ext-ffi (CI containers, restrictive shared hosts).
- `TermiosFactory::open(int|resource $fd): Termios` — try FFI first, log + downgrade on failure. Honor `SUGARCRAFT_TERMIOS=stty` env var to force the fallback (for testing).
- Refactor `candy-core/src/Util/Tty/PosixBackend.php`:
  - Replace `enableRawMode()` body with `$this->termios = TermiosFactory::open($this->stream); $this->saved = $this->termios->current(); $this->termios->makeRaw()->apply();`
  - Replace `restore()` body with `$this->saved->apply();`
  - Replace `size()` body with a call to `SizeIoctl::query($this->stream)` (new helper, wraps `TIOCGWINSZ`) — fall back to env vars / `stty size` if `posix_isatty` is false.
  - Keep `onResize()` / `drainSignals()` unchanged (they use `SignalForwarder` already).
- **Acceptance:**
  - `candy-core::PosixBackend` no longer invokes `stty` when ext-ffi is available.
  - `SUGARCRAFT_TERMIOS=stty` env var forces the legacy path; both pass identical input/output snapshot tests.
  - macOS BSD-stty quirks are no longer reachable on the primary path.

### P2 — Pump extraction (week 2)

**Goal:** make the byte-pump loop reusable.

- Create `Posix\PosixPump` (`candy-pty/src/Posix/PosixPump.php`) by moving:
  - `InProcessTransport::pump()` and helpers (`writeFully`, `drainMaster`, `forwardStdinToMaster`) — currently in `candy-wish/src/Transport/InProcessTransport.php`.
  - The `PUMP_TIMEOUT_USEC`, `PUMP_CHUNK`, `FLUSH_DEADLINE_SEC`, `STDIN_EOF_GRACE_SEC`, `VEOF` constants — make them configurable via a `PumpOptions` value object.
  - The keepalive callback hook.
- Define `PumpOptions` (readonly DTO): `chunkBytes`, `selectTimeoutUs`, `flushDeadlineSec`, `stdinEofGraceSec`, `veof`, `keepalive` (callable), `onSigwinch` (callable), `onChildExit` (callable).
- `PosixPump::run(MasterPty $master, $stdinStream, $stdoutStream, ?Child $child, PumpOptions $opts): int` returns the child's exit code (or 0 if no child).
- Refactor `candy-wish/src/Transport/InProcessTransport.php`:
  - Delete pump internals.
  - `runChild()` becomes: open pty, spawn child, attach SIGWINCH forwarder, instantiate `PosixPump`, call `run()`, return result. ~30 lines instead of 200+.
- **Acceptance:**
  - `candy-wish` full suite green (especially `InProcessTransportRunChildTest`, `InProcessTransportSigwinchTest`).
  - New `candy-pty/tests/Posix/PosixPumpTest.php` exercises pump in isolation: large writes, EOF grace, partial writes, SIGWINCH mid-stream, keepalive cadence.

### P3 — Process consolidation (week 2–3)

**Goal:** unify non-PTY subprocess handling with the PTY child lifecycle.

- Create `Posix\PosixProcess` (`candy-pty/src/Posix/PosixProcess.php`) by moving and generalizing `candy-shell/src/Process/RealProcess.php`. Public API mirrors `Child`: `pid()`, `exited()`, `wait()`, `kill($signal)`, `exitCode()`, plus `stdoutBytes()`, `stderrBytes()` for the capture case.
- Extract the shared `proc_get_status()` poll pattern into a package-private trait `ChildPollTrait` used by both `PosixChild` and `PosixProcess`. Handles: `running=false` detection, `exitcode` capture before close, `-1` post-reap guard.
- Move the destructor zombie-reaper safety net from `Child` into the trait so `PosixProcess` inherits it.
- Replace `candy-shell/src/Process/RealProcess.php` with a thin alias class `extends PosixProcess` (or just update callers and delete it; the class is internal, so an alias may be unnecessary — verify).
- **Acceptance:**
  - `candy-shell` full suite green.
  - `proc_get_status()` polling lives in exactly one place.
  - `candy-pty/tests/Posix/PosixProcessTest.php` covers capture/no-capture, exit-code propagation, signal kill, stdin closure.

### P4 — Migration + interface adoption (week 3–4)

**Goal:** consumers use the new contracts; legacy facades documented as deprecated for next major.

- Add `PtySystemFactory::default(): PtySystem` returning `PosixPtySystem` on Linux/macOS; throw `UnsupportedPlatformException` on Windows for now (caught + documented).
- Update `candy-wish` to construct `InProcessTransport` with a `PtySystem $pty = null` constructor parameter (defaulting to `PtySystemFactory::default()`) — supports test injection.
- Update `candy-wish::Spawn` middleware to depend on `PtySystem` instead of static `Pty::open()`.
- Update `candy-core::Program` to accept an optional `Termios $termios = null` constructor argument; if null, the `PosixBackend` resolves one via `TermiosFactory`.
- Add `@deprecated` doc-comments to the legacy static facades (`Pty::open()` etc.) pointing at the DI-friendly equivalents. Do **not** remove them — they ship intact through v1.x.
- Update root `composer.json` `repositories[]` entries — verify `candy-core` now correctly path-reps `candy-pty`. Run `composer validate` (without `--strict`).
- **Acceptance:**
  - Every consumer compiles and tests green with the new constructor signatures.
  - A demo script `candy-pty/examples/spawn-bash.php` shows the canonical usage (`$pty = PtySystemFactory::default()->open(80, 24); $child = $pty->slave()->spawn(['bash'], ...)`).

### P5 — Hardening + parity testing (week 4–5)

**Goal:** be honestly "as good as creack/pty on Linux + macOS".

Cross-shell integration tests under `candy-pty/tests/Integration/`:

- `BashInteractiveTest` — spawn `bash -i`, send commands, assert prompt + output.
- `ZshTest`, `FishTest`, `DashTest` — same script, different shells. Skip gracefully if shell absent (`require($this)->markTestSkipped('zsh not installed')`).
- `PythonReplTest` — spawn `python3 -u -i`, exercise multi-line input.
- `VimSmokeTest` — spawn `vim`, send `:q!`, assert clean exit. (Pulls in vt-parsing via `candy-vt` for screen state.)
- `ResizeRaceTest` — spawn `tput cols` in a loop while resizing the PTY; assert no torn reads.
- `LargeBufferTest` — write 1 MB through master, read on slave, assert byte-identical (catches non-blocking write loops).
- `EOFGraceTest` — close stdin while child is mid-write; assert pump drains output before returning.
- `SIGINTForwardingTest` — spawn `sleep 30` with `controllingTerminal: true`, write `\x03` to master, assert child exits within 1s.
- `OrphanedChildReapTest` — exit the parent without `wait()`; assert no zombies via `ps`.

Cross-platform CI:

- Extend `scripts/affected-libs.php` `MACOS_LIBS` array to include candy-pty (already there), candy-core, candy-wish.
- Add a `pty-matrix` GitHub workflow that runs the integration suite on Ubuntu 22.04 + macOS 14 against PHP 8.3 and 8.4.
- Verify `ext-ffi` is present in both CI images (it's bundled in php-cli on standard images).

Documentation parity:

- `candy-pty/README.md` — add "Compared to node-pty / creack/pty / portable-pty" feature table.
- Doc-comments on every public method cite the equivalent upstream method (`Mirrors creack/pty.Start` style — matches the convention in `CLAUDE.md`).
- `candy-pty/docs/CONCEPTS.md` — short explainer covering: what a PTY is, master vs slave, controlling-terminal semantics, why the shim exists, how SIGWINCH propagates.

**Acceptance:**
- 100% of public methods have ≥1 test.
- Integration suite runs on Linux + macOS CI without `markTestSkipped` for the standard tools.
- README feature table shows ≥90% checkmark parity vs `node-pty`/`creack/pty` for Unix concerns.

### P6 — Stretch / optional (week 5–6 buffer)

Only if P0–P5 land on time:

- **Resource pooling** — `PtyPool` for SSH server scenarios (candy-wish) that spawn many short-lived sessions; reuse libc FFI handles across opens. Profile first; only build if measurable.
- **Pty multiplexing helper** — `MultiPump` that supervises N pumps for split-pane / tmux-like scenarios. Driven by candy-zone if it needs it.
- **Higher-level Expect-style API** — `Expect::on($pty)->send("login: ")->expect("password:", timeout: 5)->...` for scripting / test fixtures. Modeled on `pexpect` (Python).
- **`candy-vcr` Recorder tap in `PosixPump`** — add a `?Recorder $recorder = null` field to `PumpOptions`. When set, the pump tees stdin → `recordInputBytes()` and master-read → `recordOutput()` directly, and feeds `WindowSizeMsg` resizes through `recordResize()`. Makes byte-level cassette capture "free" for any pump-based consumer (candy-wish SSH sessions, the Shirley CLI below, REPL fixtures). Acceptance: existing `candy-vcr` round-trip tests pass; new `PumpRecorderTapTest` asserts the tap captures a `bash` session and `Player::play()` reproduces it.

### P6.5 — Shirley-style PTY recorder CLI (stretch, week 5–6)

**Goal:** standalone `candy-vcr record -- <cmd>` CLI that records command execution at the PTY level, equivalent to `asciinema rec` or the hypothetical charmbracelet/shirley described in `docs/research/libraries/candy-vcr-research.md:110-127`. The PTY consolidation makes this nearly free — it's the canonical first external user of the new `PosixPump` + Recorder-tap combination.

The current `candy-vcr` only records sessions attached as a library to a `SugarCraft\Core\Program` (API-mode capture). A Shirley-style recorder closes the gap so users can record **any** terminal program — `bash`, `vim`, `htop`, `python -i`, an external CLI under test — without that program needing to know about SugarCraft.

**Architecture** (lives in `candy-vcr`, not `candy-pty`):

- `SugarCraft\Vcr\Cli\RecordCommand.php` (new) — Symfony Console command. Argv: `candy-vcr record [--output session.cas] [--shell] [--idle-trim] -- <cmd> [args...]`.
- On invocation:
  1. Resolve `PtySystem` via `PtySystemFactory::default()`.
  2. Query host TTY size via `SizeIoctl::query(STDIN)`; default 80×24 if not a tty.
  3. `$pair = $pty->open($cols, $rows)`.
  4. `$child = $pair->slave()->spawn($cmd, $env, controllingTerminal: true)` — controlling-terminal so Ctrl+C reaches the recorded program, not the recorder.
  5. Save host termios via `TermiosFactory::open(STDIN)`, switch host stdin to raw mode (transparent passthrough).
  6. Open a streaming `Recorder` against the output cassette path; write the header (cols, rows, env-snapshot if `--env` flag).
  7. Attach `SignalForwarder::attachSigwinch()` to propagate host resizes into the master.
  8. Build `PumpOptions(recorder: $recorder, onSigwinch: …)` and run `PosixPump::run($pair->master(), STDIN, STDOUT, $child, $opts)`.
  9. On child exit: restore host termios, flush + close recorder, exit with child's exit code.
- `--shell` shorthand: if no command given, spawns `$SHELL` (or `/bin/sh`) with `-l`.
- `--idle-trim <seconds>`: if a gap >N seconds appears between events, the pump emits a synthetic shortened timestamp delta. Modeled on `asciinema --idle-time-limit` (research doc §4.1).
- `--env`: capture allowed `ENV` keys (allowlist with secret-name regex filter) into the cassette header for deterministic replay.
- Cassette format: existing JSONL — no new schema, just exercised more thoroughly than the API-mode recorder hits today.

**Why this fits the PTY plan:**

- Validates `PosixPump`'s Recorder tap in a real user-facing path (not just unit tests).
- Validates `TermiosFactory` host raw-mode switching with an interactive shell — the highest-stakes termios consumer.
- Validates `SignalForwarder` across two boundaries (host SIGWINCH → master, child Ctrl+C → controlling tty).
- Validates the deferred-Windows decision: this is the feature that would most benefit from ConPTY; if it ships smoothly on Linux/macOS, the demand for Windows is concrete and prioritization for v2 has evidence.

**Acceptance:**

- `candy-vcr record --output /tmp/bash.cas -- bash -c 'echo hello; sleep 0.2; echo world'` produces a cassette with `output` events totalling "hello\nworld\n" (after CR/LF normalization) and a `quit` event with exit 0.
- `candy-vcr record --output /tmp/vim.cas -- vim /tmp/scratch.txt` then `<Esc>:wq<CR>` produces a cassette that replays into a `candy-vt` Terminal showing an empty buffer post-replay.
- `candy-vcr replay /tmp/bash.cas` round-trips on the same host (timing tolerance applied).
- `candy-vcr stats /tmp/bash.cas` (from P6 stats CLI in `candy-vcr-research.md` H1) reports plausible event counts.
- Recording overhead measured at ≤2% wall-clock vs running the same command without `record` (on `time bash -c 'seq 100000'`).
- Documented in `candy-vcr/README.md` with the asciinema comparison from research doc §4.1.

**Scope discipline:**

- Format conversion to/from `.cast` (asciinema) — explicitly **out of scope** for P6.5; tracked as candy-vcr research §13 item #L2.
- Hook system, custom matchers, gzip compression — out of scope; tracked separately in candy-vcr research §13.
- SVG rendering output (term-transcript style) — out of scope.

**Dependencies on the rest of the PTY plan:** P0–P5 must land first. The Recorder tap in `PumpOptions` (above P6 bullet) is the precondition. If P6.5 is started before that bullet, both expand into a single ~3-day effort.

---

## Critical files

To create:

- `candy-pty/src/Contract/PtySystem.php`
- `candy-pty/src/Contract/PtyPair.php`
- `candy-pty/src/Contract/MasterPty.php`
- `candy-pty/src/Contract/SlavePty.php`
- `candy-pty/src/Contract/Child.php`
- `candy-pty/src/Contract/Process.php`
- `candy-pty/src/Contract/Termios.php`
- `candy-pty/src/Contract/Pump.php`
- `candy-pty/src/Posix/PosixPtySystem.php`
- `candy-pty/src/Posix/PosixPtyPair.php`
- `candy-pty/src/Posix/PosixMasterPty.php`
- `candy-pty/src/Posix/PosixSlavePty.php`
- `candy-pty/src/Posix/PosixChild.php`
- `candy-pty/src/Posix/PosixProcess.php`
- `candy-pty/src/Posix/PosixTermios.php`
- `candy-pty/src/Posix/SttyTermios.php`
- `candy-pty/src/Posix/PosixPump.php`
- `candy-pty/src/Posix/ChildPollTrait.php`
- `candy-pty/src/TermiosFactory.php`
- `candy-pty/src/PtySystemFactory.php`
- `candy-pty/src/PumpOptions.php`
- `candy-pty/src/Exception/UnsupportedPlatformException.php`
- `candy-pty/examples/spawn-bash.php`
- `candy-pty/docs/CONCEPTS.md`
- `candy-pty/tests/Integration/*Test.php` (~9 files per P5)
- `candy-pty/tests/Posix/PosixTermiosTest.php`
- `candy-pty/tests/Posix/SttyTermiosTest.php`
- `candy-pty/tests/Posix/PosixPumpTest.php`
- `candy-pty/tests/Posix/PosixProcessTest.php`
- `.github/workflows/pty-matrix.yml`
- `candy-pty/tests/Posix/PumpRecorderTapTest.php` *(P6 — pump-side Recorder tap)*
- `candy-vcr/src/Cli/RecordCommand.php` *(P6.5 — Shirley-style CLI)*
- `candy-vcr/tests/Cli/RecordCommandTest.php` *(P6.5)*
- `candy-vcr/tests/Integration/ShirleyBashTest.php` *(P6.5 — record `bash -c '...'`, replay, assert)*
- `candy-vcr/tests/Integration/ShirleyVimTest.php` *(P6.5 — record `vim`, screen-assert via candy-vt)*

To modify:

- `candy-pty/src/Pty.php` — refactor to delegate to `PosixPtySystem`; keep public surface stable; add `@deprecated` markers on static helpers.
- `candy-pty/src/Spawn.php` — move logic into `PosixSlavePty`; keep as thin alias.
- `candy-pty/src/Child.php` — move logic into `PosixChild`; keep as thin alias.
- `candy-pty/src/Libc.php` — extend cdef with termios surface.
- `candy-pty/src/SizeIoctl.php` — add `query($fd): array` helper (TIOCGWINSZ readback).
- `candy-pty/composer.json` — bump description, keywords, add `examples/` to autoload-dev.
- `candy-vcr/src/Cli/Application.php` — register `RecordCommand` alongside existing `inspect`/`replay`/`diff` (P6.5).
- `candy-vcr/src/Recorder.php` — verify `recordInputBytes()` / `recordOutput()` / `recordResize()` signatures match what `PosixPump`'s tap will call; widen if needed without breaking existing callers (P6).
- `candy-vcr/composer.json` — add `"sugarcraft/candy-pty": "@dev"` + path repo (P6.5; was already needed transitively, now direct).
- `candy-vcr/README.md` — document `candy-vcr record` and compare to `asciinema rec` (P6.5).
- `candy-pty/README.md` — add feature parity table + concept link.
- `candy-pty/CALIBER_LEARNINGS.md` — log new patterns as they emerge.
- `candy-core/src/Util/Tty/PosixBackend.php` — delegate to `candy-pty::Termios` + `SizeIoctl`.
- `candy-core/composer.json` — add `"sugarcraft/candy-pty": "@dev"` + path repo (verify not already present).
- `candy-shell/src/Process/RealProcess.php` — either delete and update callers, or convert to alias of `PosixProcess`.
- `candy-shell/composer.json` — depend on candy-pty if not already (transitive via candy-core probably suffices; verify).
- `candy-wish/src/Transport/InProcessTransport.php` — shrink to ~30 lines; delegate to `PosixPump`.
- `candy-wish/src/Middleware/Spawn.php` — accept injected `PtySystem`.
- `MATCHUPS.md` — update candy-pty row(s) to reflect P1–P5 scope.
- `scripts/affected-libs.php` — ensure candy-core/candy-wish included in macOS pool for the duration of P5.
- `composer.json` (root) — verify `repositories[]` entries.

To leave alone:

- `candy-pty/src/SignalForwarder.php` — already correct
- `candy-pty/src/Master.php` — already correct
- `candy-pty/bin/pty-shim.php` — already correct
- `candy-core/src/Util/Tty/WindowsBackend.php` — out of scope
- `candy-core/src/InputReader.php` — ANSI parsing, not PTY plumbing
- `candy-vt/` — VT-state machine, not PTY plumbing
- `candy-vcr/` — recording semantics, not PTY plumbing

---

## Reused existing helpers (do not reinvent)

- **EINTR + deadline read loop** — `candy-pty/src/Pty.php` `read()` body. Lift verbatim into `PosixMasterPty::read()`.
- **SIGWINCH forwarder** — `candy-pty/src/SignalForwarder.php`. Use as-is.
- **Controlling-terminal shim** — `candy-pty/bin/pty-shim.php`. Use as-is.
- **proc_get_status polling** — `candy-pty/src/Child.php` `exited()` / `wait()`. Move into `ChildPollTrait`.
- **`php://fd/N` stream wrapping** — `candy-pty/src/Pty.php` `stream()` cache. Move into `PosixMasterPty::stream()`.
- **`SizeIoctl` Linux/macOS branch** — `candy-pty/src/SizeIoctl.php`. Keep; add `query()` helper.
- **Libc lazy-load + override** — `candy-pty/src/Libc.php`. Keep; extend cdef.

---

## Verification

End-to-end smoke (manual):

```sh
cd candy-pty
composer install
vendor/bin/phpunit                     # unit + integration
php examples/spawn-bash.php            # spawn bash interactively, type `echo hi`, see "hi"
```

Termios fallback exercise:

```sh
SUGARCRAFT_TERMIOS=stty vendor/bin/phpunit --testsuite=integration
```

Consumer regression:

```sh
for d in candy-pty candy-core candy-shell candy-wish candy-vcr; do
  (cd "$d" && composer install --quiet && vendor/bin/phpunit) || { echo "FAIL: $d"; exit 1; }
done
```

CI matrix:

- `.github/workflows/ci.yml` (auto-discovered via `scripts/affected-libs.php`) — should already run candy-pty on macOS.
- `.github/workflows/pty-matrix.yml` (new) — runs integration tests on Linux + macOS, PHP 8.3 + 8.4, with and without `SUGARCRAFT_TERMIOS=stty`.

Parity check (manual, end of P5):

- Open `candy-pty/README.md` feature table side-by-side with the matrix in `creack/pty` README and `node-pty` README.
- For any unchecked row in our column, either: implement, justify as "PHP-irrelevant" in the column, or move to deferred-roadmap with a tracking issue.

---

## Risks + open questions

1. **FFI termios struct layout differences between glibc / musl / Darwin libc.** `struct termios` size differs (glibc=60, Darwin=72) and `cfmakeraw` exists on both but isn't POSIX-mandated. Mitigation: treat the struct as opaque ≥80-byte buffer and only call `cfmakeraw`/`tcgetattr`/`tcsetattr` — never read individual fields from PHP. Test on alpine (musl) explicitly if shipping container support.
2. **`ext-ffi` not always enabled.** Shared hosts, locked-down corporate PHP. Termios fallback addresses raw-mode case. For PTY allocation itself there's no fallback — document `ext-ffi` as a hard requirement, throw a clear `UnsupportedPlatformException` if missing.
3. **`pcntl_async_signals` and ReactPHP loop interaction.** `SignalForwarder` defaults to async; the ReactPHP loop's own `pcntl_signal_dispatch` ticking may double-handle. Verify with `SignalForwarderTest` on a ReactPHP-driven scenario before declaring P4 done.
4. **Migration of `candy-wish::InProcessTransport`** — the existing `STDIN_EOF_GRACE_SEC` / `FLUSH_DEADLINE_SEC` constants encode subtle SSH protocol assumptions. Keep them as the **defaults** of `PumpOptions` to avoid behavioural regression; verify the SSH end-to-end suite before merging P2.
5. **macOS CI runner cost.** macOS GitHub runners are 10x cost of Linux. P5's integration matrix should be limited to the necessary tests on macOS — not the full suite. Use `markTestSkipped` on Linux-only edge cases.
6. **Composer path-repo closure.** Adding candy-pty as a dependency of candy-core means every lib that depends on candy-core must also list candy-pty in its `repositories[]`. Mechanically tedious but the CLAUDE.md "Gotchas" section explicitly warns about this. Use `scripts/affected-libs.php` output to enumerate before merge.
7. **`candy-shell::RealProcess` deletion vs alias.** If it's `internal` and zero external callers, prefer deletion. Grep `RealProcess` across the monorepo before P3 to decide.
8. **Shirley CLI and host terminal hygiene.** The recorder switches the host's stdin into raw mode while a child program runs — if it crashes mid-record, the user is left in a broken terminal. Mitigation: register a `register_shutdown_function` + `pcntl_signal(SIGTERM)` handler that always calls `TermiosFactory::open(STDIN)->saved->apply()`. Test by killing the recorder with SIGKILL during a `vim` session and verifying the host shell is usable after.
9. **Idle-trim timestamp semantics.** `--idle-trim` rewrites event timestamps, which breaks naive byte-exact replay if a downstream consumer expects original timing. Mitigation: store both `t` (trimmed) and `tRaw` (original) on the event when `--idle-trim` was active; `Player` honors `tRaw` if present and `--no-trim` is passed. Document the trade-off in `candy-vcr/README.md`.

---

## Deferred (post-v1) follow-ups

- **Windows ConPTY backend.** Implement as `SugarCraft\Pty\Windows\ConPtySystem` using FFI to `kernel32.dll` (`CreatePseudoConsole`, `ResizePseudoConsole`, `ClosePseudoConsole`, `CreateProcessW` with `STARTUPINFOEXW` extended attributes). Threading model TBD — ConPTY's input/output pipes are blocking, may need to be polled via async ReadFile/WriteFile or run on a sidecar process. Tracked in `plans/x-windows.md`.
- **Sidecar binary fallback for Windows.** Small Go program using `creack/pty`-equivalent or `pty` crate, talks to PHP over stdio framed messages. Same interface as FFI backend. Avoids ConPTY threading entirely. Cost: Composer post-install download of a per-OS binary.
- **PECL extension.** `php_pty` extension that wraps `forkpty(3)` / `openpty(3)` natively, skipping FFI. Best perf, deployment burden. Likely never necessary if FFI continues to work.
- **AIX / Solaris / OpenBSD / FreeBSD ioctl constants.** Currently only Linux + Darwin in `SizeIoctl.php` and the planned `PosixTermios`. Each BSD has slightly different ioctl numbers. Pull from `nix` crate's `ioctl.rs` per-platform files when a user reports needing one.
- **Pty multiplexer / tmux-style sessions.** `MultiPump` from P6, promoted to a first-class API if `candy-zone` needs it.
- **Expect-style scripting API.** P6 item, if there's demand from `candy-vcr` / `candy-skate`.
