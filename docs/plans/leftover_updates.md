---
status: open
phase: post-P6.5
updated: 2026-05-17
goal: Close residual gaps in the PTY consolidation plan (PTY_PLAN.md + plans/sugarcraft-is-a-mono-logical-twilight.md)
parent_plans:
  - PTY_PLAN.md
  - plans/sugarcraft-is-a-mono-logical-twilight.md
  - plans/sugarcraft-pty-status.md
---

# PTY consolidation — leftover updates

Audit of the PTY consolidation plan (`PTY_PLAN.md` + the executable
`sugarcraft-is-a-mono-logical-twilight.md`) against the actual state of
the tree on `master` (commit `ba054dc4`, 2026-05-17). P0–P6.5.6 have all
shipped (PRs #440–#489) and CI is green, but a handful of items either
missed the cut, regressed during the macOS arm64 firefight, or were
explicitly deferred without a follow-up ticket. This file is the
canonical list.

The items are graded:

- **🔴 Plan deliverable missing** — the plan promised something that did not actually land.
- **🟡 Plan deliverable degraded** — landed but with a worse trade-off than the plan called for.
- **🟢 Improvement opportunity** — beyond the plan, but a natural next step.
- **🪦 Deferred** — explicitly out-of-scope in the original plan; tracked here so the next session does not re-discover it.

Each item lists: *what*, *where it lives now*, *what should change*,
*acceptance signal*. Items are sized to be one PR each unless flagged
`[multi-PR]`.

---

## Phase-by-phase audit

### P0 — Foundation

✅ All four steps shipped (contracts, PosixPtySystem, PosixSlavePty,
Libc termios cdef). Public surface intact.

No leftovers.

### P1 — Termios FFI primary, stty fallback

🟡 **P1-LO-01 — `candy-core::PosixBackend::size()` still falls through to `shell_exec('stty size')`.**
*Where:* `candy-core/src/Util/Tty/PosixBackend.php:93-98`.
*Why it matters:* The plan (`P1.4` review focus) said `stty` should leave
the primary code path entirely. Today `SizeIoctl::query()` is tried
first, but if it throws (`posix_isatty` false, or any libc failure on
Darwin), the next branch is still `shell_exec('stty size')`, not
`SizeIoctl::query()` against the `/dev/tty` fallback path that
`openTty()` already knows how to open.
*Fix:* When `posix_isatty($this->stream)` is false, try
`openTty()` → `SizeIoctl::query()` against the returned `/dev/tty` fd
before falling through to env vars. Drop the `stty size` and `hasStty`
branches. Keep the 80×24 default as the last resort.
*Acceptance:* `grep -n "shell_exec.*stty" candy-core/src` returns
nothing; `PosixBackendTest` covers `posix_isatty=false` + `/dev/tty`
readable still returns real dimensions.

🟡 **P1-LO-02 — `candy-core::PosixBackend::restoreLast()` still uses
`shell_exec('stty -g')` and `shell_exec('stty $saved')`.**
*Where:* `candy-core/src/Util/Tty/PosixBackend.php:177-202`,
plus the private `hasStty()` cache at line 194.
*Why it matters:* `restoreLast()` is the emergency / panic-handler
path. The plan’s `TermiosFactory` already exposes the `current()` →
`apply()` pair that does the same job through FFI when available. Two
parallel rescue mechanisms is brittle.
*Fix:* Replace the static `$lastSttyState` string with a static
`?Termios` snapshot obtained via `TermiosFactory::open(STDIN)`. Drop
`hasStty()` entirely. If `TermiosFactory` returns `SttyTermios` because
ext-ffi is missing, the existing shell-out lives there — single rescue
path.
*Acceptance:* `grep -n "shell_exec" candy-core/src` returns nothing;
panic-handler test (`tests/Util/Tty/PosixBackendRestoreLastTest.php`)
calls `restoreLast()` twice, verifies termios round-trips identically.

🟢 **P1-LO-03 — No env-var to force `SttyTermios` for *PTY allocation*.**
`SUGARCRAFT_TERMIOS=stty` only swings the `TermiosFactory`. There is no
equivalent for forcing the `PtySystemFactory` to a hypothetical
non-FFI backend. Once a sidecar / PECL backend lands (deferred items
below), `SUGARCRAFT_PTY_BACKEND={posix-ffi|sidecar|pecl}` should be the
selector. Cheap to design now: add the env-var read in `PtySystemFactory::default()`
and document the names even if only `posix-ffi` is wired today.

### P2 — Pump extraction

✅ All five steps shipped; `InProcessTransport` is 30 lines as
promised, full SSH end-to-end suite green.

🟢 **P2-LO-01 — `PumpOptions::$flushDeadlineSec` and `$stdinEofGraceSec`
constants are duplicated as Pump defaults AND in
`candy-wish/src/Transport/InProcessTransport.php` comments.**
*Where:* `candy-pty/src/PumpOptions.php` defaults vs.
`candy-wish/src/Transport/InProcessTransport.php` historical comments.
*Why it matters:* If the SSH protocol assumptions ever shift, future
maintainers will look at the candy-wish constants first. They have
already migrated.
*Fix:* Replace any remaining numeric constants in `InProcessTransport`
with a `PumpOptions::sshDefault(): self` named-constructor on
`PumpOptions` that bakes the SSH-tuned values. Reference it from
`InProcessTransport::runChild()`.

🟢 **P2-LO-02 — `PosixPump::pump()` short-circuits SIGWINCH detection
inside the pump.**
*Where:* `candy-pty/src/Posix/PosixPump.php:117-125` — when
`stream_select` returns `0` (idle), pump unconditionally fires
`onSigwinch(0, 0)`. The 220-237 TODO block calls this out as
"intentional", but the callsite still passes synthetic `(0, 0)` to
the callback, which the consumer must then disambiguate from a real
resize event.
*Fix:* Rename the idle callback. Two distinct hooks:
- `onIdle()` — fired every select-timeout tick (current "fake sigwinch").
- `onSigwinch(int $cols, int $rows)` — fired *only* by
  `SignalForwarder::attachSigwinch` (P0 promise).
The `RecordCommand` and SSH server already need both — keep one or the
other today is forcing tasks like P6.1's recordResize wiring into the
consumer layer.
*Acceptance:* `PosixPumpKeepaliveTest`, `PosixPumpSigwinchTest` updated;
new `PosixPumpIdleVsSigwinchTest` exercises both hooks independently.

### P3 — Process consolidation

✅ All three steps shipped; `ChildPollTrait` is the single owner of
`proc_get_status` polling.

🟡 **P3-LO-01 — `candy-shell/src/Process/RealProcess.php` still exists
as a thin alias.**
*Where:* The plan (step P3.3, Risk #7) said: "if internal and zero
external callers, prefer deletion." Current state on master keeps it
as a `@deprecated` alias.
*Fix:* `grep -rn "candy-shell.*RealProcess\|Process\\RealProcess"`
across the monorepo (and Packagist if any sister repos snapshot the
class). If the count is still zero outside `candy-shell` itself, delete
the file and have `candy-shell/src/Process/Process.php` delegate
straight to `\SugarCraft\Pty\Posix\PosixProcess`. Saves one indirection
and one CALIBER entry's worth of "why does this exist" reading.
*Acceptance:* `candy-shell/tests/*` still green after deletion;
`MATCHUPS.md` row for candy-shell unchanged.

### P4 — Migration + interface adoption

✅ All six steps shipped. Legacy facades marked `@deprecated`; example
runs.

🟢 **P4-LO-01 — `Pty.php`, `Spawn.php`, `Child.php`, `Master.php`
remain in `candy-pty/src/` as deprecated facades.**
*Why it matters:* The Goals list said they ship through v1.x and get
removed at v2.0. There is no current "v1.x is starting" tag, so the
facades are forever-living. Two of them (`Pty.php` and `Master.php`)
still contain non-trivial logic (the EINTR retry loop in
`Pty::read()`), not just delegation — they were the originals.
*Fix:* Reduce each to a 5-line `extends` or `@uses` alias of the Posix
class. `Pty::open()` → `PosixPtySystem::open()`. `Pty::read()` →
`$master->read()`. Verify with `git diff --stat` that the facades are
≤30 LOC each.
*Acceptance:* Every facade is a pure delegation shim; CI green.

🟢 **P4-LO-02 — `PtySystemFactory::default()` does not honour an env
override.**
See P1-LO-03.

### P5 — Hardening + parity testing

✅ All seven steps shipped. Integration suite + parity README + CONCEPTS.md
in place.

🔴 **P5-LO-01 — `plans/x-windows.md` does not exist.**
*Where:* `PTY_PLAN.md:53`, `sugarcraft-is-a-mono-logical-twilight.md:822`,
both reference `plans/x-windows.md` as the tracking document for the
Windows ConPTY backend, but no such file ever landed.
*Fix:* Create `plans/x-windows.md` as a stub plan for the Windows
backend (see deferred items below for the rough contents).
*Acceptance:* `ls plans/x-windows.md` returns the file; root
`MATCHUPS.md` Windows row points at it.

🟡 **P5-LO-02 — macOS arm64 PTY resize fallback is shell-out, not FFI.**
*Where:* `candy-pty/src/SizeIoctl.php` Darwin branch (added in PR #475).
The Darwin path calls `stty -f /dev/fd/<fd> rows N cols N` because
`ioctl(TIOCSWINSZ)` returns -1 through PHP FFI's fixed-arg cdef (the
real libc `ioctl` is variadic, and arm64 puts varargs on the stack
while fixed args sit in `x0`–`x7`).
*Why it matters:* The plan’s P5 acceptance was "as good as creack/pty
on Linux + macOS". We are not — we are "as good as creack/pty on Linux
and via stty shell-out on macOS arm64". Performance is acceptable but
this is a known regression and the patch comment explicitly invites a
better fix.
*Fix options (any one):*
1. Wait for a PHP FFI release that supports `FFI\CData` arrays as variadic
   params (tracked upstream).
2. Build a tiny Darwin-only C shim (`candy-pty/bin/macos-ioctl-resize`)
   that does the syscall and is exec'd via `proc_open` — same indirection
   cost as stty but no shell parsing.
3. Distribute a tiny "resize-ioctl" binary as a Composer post-install
   artifact (Go or Zig, single static binary per arch).
*Acceptance:* `SizeIoctl::setSizeViaLibc` returns true on macOS arm64
without any shell-out; `pty-matrix.yml` job names stop saying "stty
fallback" on Darwin.

🟡 **P5-LO-03 — `OrphanedChildReapTest` and `PythonReplTest` flake on
macOS without a tracking issue.**
*Where:* `candy-pty/tests/Integration/OrphanedChildReapTest.php` +
`PythonReplTest.php`. Status doc says: "These are pre-existing env
issues, **not regressions**. Acceptable to defer with `markTestSkipped`
on Darwin."
*Why it matters:* The CI matrix on master has these as
"flaky-but-acceptable" with no expiry. Either skip on Darwin with a
visible tracker comment, or fix the environment.
*Fix:* Two-step:
1. Add `markTestSkipped` on Darwin with a clear `@todo` referencing this
   file ("see P5-LO-03").
2. Track image fix in `.github/workflows/pty-matrix.yml` — pre-install
   `python3` + add a `pgrep`-based orphan-detection harness that does
   not rely on the test process being its own session leader.

🟢 **P5-LO-04 — `pty-matrix.yml` does not exercise `SUGARCRAFT_TERMIOS=stty`
on Darwin.**
*Where:* `.github/workflows/pty-matrix.yml` runs both modes on Ubuntu;
Darwin only runs the default (FFI) mode.
*Why it matters:* Darwin's BSD-style `stty` is the variant most likely
to surface quoting bugs. P1.2's promise was "macOS BSD-stty quirks are
no longer reachable on the primary path", but the fallback path is the
one we *do* hit on macOS arm64 resize.
*Fix:* Mirror the Ubuntu matrix to Darwin (PHP 8.3 + 8.4, FFI + stty).

### P6 — Stretch / optional

✅ P6.1 Recorder tap, P6.2 PtyPool, P6.3 MultiPump, P6.4 Expect all shipped.

🟡 **P6-LO-01 — Stale "TODO" block in `PosixPump.php` lines 220-237.**
*Where:* `candy-pty/src/Posix/PosixPump.php` ends with a multi-line
comment explaining why `recordResize` is NOT wired in the pump,
labeling it "TODO". The wiring has since shipped on the *consumer* side
(`candy-vcr/src/Cli/RecordCommand.php:152` calls
`$recorder->recordResize($cols, $rows)` from the `onSigwinch` closure).
*Why it matters:* Code archaeology — a future maintainer reads "TODO"
and either re-wires it inside the pump (re-introducing the zero-noise
bug the comment warned about) or wastes a session figuring out why the
TODO is wrong.
*Fix:* Rewrite the comment as a "Design note: SIGWINCH detection lives
in the consumer (see RecordCommand:152)" — strike the TODO word, keep
the rationale.
*Coupling:* Pair this with P2-LO-02 (split `onIdle` / `onSigwinch`).
After that split lands the comment can shrink to one line: "The
consumer owns SIGWINCH detection; pump exposes `onSigwinch(cols,rows)`
for it to drive directly."

🟢 **P6-LO-02 — `PtyPool` has no tests for ReactPHP loop integration.**
*Where:* `candy-pty/tests/PtyPoolTest.php` covers acquire/release and
exhaustion but not the typical SSH-server scenario where the pool is
driven by a ReactPHP loop. Risk #3 of the plan flagged the
`pcntl_async_signals` ↔ ReactPHP interaction; the pool inherits this.
*Fix:* Add `PtyPoolReactLoopTest.php` driving the pool from a `ReactPHP\EventLoop\Loop::run()`
session and asserting no signal double-handling.

🟢 **P6-LO-03 — `Expect` API has no integration with `PumpOptions::recorder`.**
*Where:* `candy-pty/src/Expect.php`. The Expect API is currently a
synchronous wrapper around `MasterPty::read/write`; it cannot replay
recorded sessions and cannot tee into a recorder.
*Fix:* Add `Expect::recordedAgainst(Recorder)` factory or
`->withRecorder(?Recorder)` wither so test fixtures can record once and
replay forever — useful for the `candy-vcr` test helpers and for SSH
fixtures in `candy-wish`.

🟢 **P6-LO-04 — `MultiPump` is profiled but not documented in `README.md`.**
*Where:* `candy-pty/src/Posix/MultiPump.php` shipped with `MultiPumpTest.php`
but `candy-pty/README.md` has no "Splitting between multiple PTYs"
section and no `examples/multi-pump.php`. Step P6.3 acceptance asked
for "scales linearly, no deadlocks" tests but did not enforce docs.
*Fix:* Add a 30-line example (`examples/multi-pump.php` spawning two
shells and tee'ing each into stdout with a prefix) and a `README.md`
section.

### P6.5 — Shirley-style PTY recorder CLI

✅ All six sub-steps shipped (skeleton, `--shell`/`--env`,
`--idle-trim`, safety net, integration tests, perf baseline).

🟢 **P6.5-LO-01 — `--idle-trim` dual-timestamp format is in the writer
but not in the cassette schema doc.**
*Where:* `candy-vcr/src/Cli/RecordCommand.php` writes both `t` and
`tRaw` when trim is active; `candy-vcr/docs/CASSETTE.md` (if it exists)
does not mention `tRaw`.
*Fix:* Either create `candy-vcr/docs/CASSETTE.md` and document the
dual-timestamp shape, or extend `candy-vcr/README.md` Replay section
with the same.

🟢 **P6.5-LO-02 — Host termios safety net only covers SIGTERM + SIGHUP, not SIGINT.**
*Where:* `candy-vcr/src/Cli/RecordCommand.php::installRescueHandlers()`.
*Why it matters:* A user hitting Ctrl-C during `vim` will get SIGINT to
the recorder process (not the recorded child, because controlling-terminal
is on the child) — but the rescue handlers only register for SIGTERM
and SIGHUP. If somehow SIGINT reaches the recorder (signal weirdness
on macOS), the host terminal is left in raw mode.
*Fix:* Also register SIGINT in the rescue handlers.

🟢 **P6.5-LO-03 — `--env-regex` overrides `SECRET_KEY_REGEX` but lacks
an opt-out for the default deny-list.**
*Where:* `RecordCommand::SECRET_KEY_REGEX`.
*Fix:* Add `--env-allow-secrets` (off by default) for users replaying
in trusted environments. Document the trade-off: the default deny-list
strips everything matching `/(SECRET|TOKEN|KEY|PASSWORD|API|CRED|AUTH|PRIV)/i`;
opt-out is a footgun and the flag's docblock should say so.

🟢 **P6.5-LO-04 — Shirley integration tests cover bash + vim but not htop.**
*Where:* `candy-vcr/tests/Integration/ShirleyBashTest.php`,
`ShirleyVimTest.php`. The plan called out `htop` and `python -i` as
examples of "any terminal program". `python -i` is covered indirectly
via `candy-pty/tests/Integration/PythonReplTest.php` (which itself
flakes on macOS — see P5-LO-03). `htop` would exercise the alt-screen
sequence which neither test currently does.
*Fix:* Add `ShirleyHtopTest.php` — record `htop -n 1` (one tick), assert
the cassette starts with the alt-screen enter sequence (`\x1b[?1049h`)
and ends with the corresponding leave sequence on quit. `markTestSkipped`
if `htop` is missing.

🪦 **P6.5-LO-05 — asciinema `.cast` format conversion.**
*Where:* Scope discipline section (`sugarcraft-is-a-mono-logical-twilight.md:678`).
The plan tracks this as research-doc item §13 #L2.
*Fix:* When picked up: implement `candy-vcr cast import` / `cast export`
to/from asciinema's v2 JSON-lines format. Cross-tool replay parity is
the main user request.

🪦 **P6.5-LO-06 — Hook system, custom matchers, gzip compression, SVG rendering.**
*Where:* All explicitly out-of-scope per `sugarcraft-is-a-mono-logical-twilight.md:678`.
Each tracked in `docs/research/libraries/candy-vcr-research.md §13`.

---

## Cross-cutting items

### CC-LO-01 — `tools/check-path-repos.php` has no auto-fix mode

*Where:* `tools/check-path-repos.php`. Reports missing path-repo
entries but requires manual `composer.json` edits to fix.
*Fix:* Add `--fix` flag that copies the canonical path-repo entry from
`sugar-charts/composer.json` and inserts it. Same rules as the manual
process today; only difference is no human in the loop.

### CC-LO-02 — Path-repo lock-version mismatch generates a 7-cell red on every candy-pty PR

*Where:* `candy-core/composer.lock` (and consumers) pin
`sugarcraft/candy-pty` to `"version": "dev-master"`; on a candy-pty PR
branch the symlinked HEAD is `dev-ai/<branch>` and composer rejects the
lock. Status doc calls this "expected".
*Why it matters:* "Expected" failures train reviewers to ignore CI red.
That is the wrong direction.
*Fix options:*
1. CI script that rewrites consumer locks on PR branches to point at
   `dev-ai/<branch>` for the duration of the PR (and rewrites them back
   to `dev-master` before merge). High mechanical complexity.
2. Drop `composer.lock` from consumer libs entirely. They are path-repo
   siblings; locking transitively pinned `@dev` deps adds noise without
   buying determinism. (The root `composer.json` plus
   `affected-libs.php` matrix already provides per-PR reproducibility.)
*Recommendation:* Option 2. The plan’s own gotchas section (#1) flags
`composer validate --strict` as expected-to-fail because of
`"sugarcraft/*": "@dev"` — locks built on top of that pin compound the
brittleness.

### CC-LO-03 — `SignalForwarder` + ReactPHP loop interaction was a Risk, never tested

*Where:* Risk #3 in both plans. `SignalForwarderTest` covers async +
polled modes but not the case where the user also has a ReactPHP loop
running `pcntl_signal_dispatch` itself.
*Fix:* Add `SignalForwarderReactLoopTest.php` (under
`candy-pty/tests/`) that boots a `React\EventLoop\Loop::get()`, attaches
a `SignalForwarder`, sends `posix_kill(getpid(), SIGWINCH)`, and asserts
the callback fires exactly once.

### CC-LO-04 — No `SIGHUP` test, only `SIGINT`

*Where:* `SIGINTForwardingTest.php` is the only signal-forwarding
integration test. SIGHUP is part of the controlling-terminal contract
(close of master ⇒ SIGHUP to slave session) and matters for SSH
disconnection semantics.
*Fix:* `SIGHUPForwardingTest.php` — open PTY, spawn `sleep 30` with
controlling terminal, close master, assert child exits within 1s.

### CC-LO-05 — `controllingTerminal: false` path is under-tested

*Where:* Most integration tests pass `controllingTerminal: true`. The
no-shim path is exercised only implicitly by the legacy `SpawnTest`.
*Fix:* Add explicit `NoControllingTerminalTest.php` that verifies a
process spawned without TIOCSCTTY does NOT respond to Ctrl-C from
master — opposite of `SIGINTForwardingTest`.

### CC-LO-06 — `candy-mosaic/src/Detect.php` uses `posix_isatty` directly

*Where:* `candy-mosaic/src/Detect.php:236-237`.
*Why it matters:* Single-source-of-truth violation. Detect's job is
"do we have a TTY that can render images?" — the canonical answer
should come from `\SugarCraft\Pty\Contract\Termios::isAtty()` (or a
new `Util\TtyDetect::isAtty()` static in candy-core that delegates to
candy-pty).
*Fix:* Replace `posix_isatty(0) && posix_isatty(1)` with a single
delegate call. Tracked under the broader audit in
`plans/leftover_updates_later.md` (single-source-of-truth section)
but listed here too because it touches the PTY surface.

### CC-LO-07 — `candy-core/src/Util/Editor.php` and `Open.php` shell out to `$EDITOR` without using `PosixProcess`

*Where:* `candy-core/src/Util/Editor.php`, `candy-core/src/Util/Open.php`
use `proc_open` directly.
*Why it matters:* The plan's Goal #4 was "Process unification". Both
files would benefit from `PosixProcess` (zombie-reap safety net, exit
code propagation, stderr capture).
*Fix:* Replace inline `proc_open` blocks with `PosixProcess`
construction; tests in `candy-core` remain green.

### CC-LO-08 — `candy-shell` still has its own `Process\Process.php` interface

*Where:* `candy-shell/src/Process/Process.php`.
*Why it matters:* `candy-pty/src/Contract/Process.php` was introduced in
P0.1 as the canonical interface. `candy-shell` should re-export
(`use SugarCraft\Pty\Contract\Process as Process;`) or implement the
upstream contract, not maintain a parallel one.
*Fix:* Verify the two interfaces have compatible methods; align signatures
if drifted; have `candy-shell` import the candy-pty contract and delete its
own. Same shape as the `RealProcess` → `PosixProcess` move (P3.3).

---

## Deferred follow-ups (post-v1, tracked here)

🪦 **Windows ConPTY backend** — `SugarCraft\Pty\Windows\ConPtySystem` via
FFI to `kernel32.dll` (`CreatePseudoConsole`, `ResizePseudoConsole`,
`ClosePseudoConsole`, `CreateProcessW` with `STARTUPINFOEXW` extended
attributes). Threading model TBD; ConPTY pipes are blocking, may need
async ReadFile/WriteFile or a sidecar process. **Create `plans/x-windows.md`
as the stub** (see P5-LO-01).

🪦 **Sidecar binary fallback** — small Go binary using `creack/pty` (or a
Rust binary using `portable-pty`), talks to PHP over framed stdio
messages. Same `PtySystem` interface as the FFI backend. Avoids ConPTY
threading; downside is Composer post-install download per OS/arch.

🪦 **PECL extension `php_pty`** — native wrapper around `forkpty(3)` /
`openpty(3)`. Best perf; deployment burden. Likely never necessary if
FFI continues to work and the macOS arm64 ioctl issue gets a clean
workaround.

🪦 **AIX / Solaris / OpenBSD / FreeBSD ioctl constants** — currently only
Linux + Darwin in `SizeIoctl.php` and `PosixTermios.php`. Each BSD has
slightly different ioctl numbers. Pull from `nix` crate's per-platform
`ioctl.rs` files when a user reports needing one.

🪦 **`MultiPump` promotion to first-class API** — promoted out of "P6
stretch" if `candy-zone` (tmux-style panes) starts using it. Profile
first to confirm scaling claims hold up to ~16 concurrent PTYs.

🪦 **Expect-style scripting API expansion** — current `Expect` is a
synchronous read/write/match loop. Once `candy-skate` (or the equivalent
"task automation" library) needs scripted dialogs, extend with:
`expectRegex(string $pattern)`, `interact(): never` (Python pexpect's
`interact` mode handoff), `before / after` capture buffers,
`expectExact / expectAny` variants.

🪦 **`PosixProcess` async support** — current `PosixProcess` is blocking
`wait()`-based. ReactPHP / Amp users would benefit from a
`promise(): PromiseInterface<int>` method that returns the exit code.
Cleanly layered on top of `proc_get_status` polling.

🪦 **`PtyPool` SSH-server profile** — current pool is generic. SSH
servers have a specific churn profile (many short-lived sessions, each
needs a fresh termios snapshot). Profile against a `candy-wish` SSH
server fixture with N concurrent connections; tune defaults.

---

## Sequencing

Recommended order (each line ≤1 PR unless `[multi-PR]` flag):

1. P5-LO-01 (`plans/x-windows.md` stub). One file, no code.
2. CC-LO-02 (drop consumer `composer.lock` files). One PR, exercises
   path-repo gotcha but ends with quieter CI.
3. P6-LO-01 (de-TODO the PosixPump comment) + P2-LO-02 (split onIdle /
   onSigwinch). Combined PR.
4. P1-LO-01 + P1-LO-02 (drop `shell_exec` from PosixBackend). Combined PR.
5. P3-LO-01 (delete `RealProcess` if zero callers) + CC-LO-08 (collapse
   `candy-shell::Process` interface). Combined PR.
6. P5-LO-02 (better macOS arm64 ioctl path). `[multi-PR]` — investigate
   each option, prototype, decide.
7. P5-LO-03 (skip + track flakes) + P5-LO-04 (Darwin stty matrix cell).
   Combined PR.
8. CC-LO-03 + CC-LO-04 + CC-LO-05 (signal + ReactPHP + SIGHUP +
   no-ctty integration tests). Combined PR — adds 3 test files,
   touches nothing else.
9. CC-LO-06 + CC-LO-07 (`candy-mosaic` + `candy-core/Util/Editor|Open`
   single-source-of-truth fix). One PR.
10. P6-LO-02 + P6-LO-03 + P6-LO-04 (Pool ReactPHP test, Expect ↔ Recorder
    integration, MultiPump docs+example). Combined PR.
11. P6.5-LO-01..04 (cassette doc, SIGINT rescue, env-allow-secrets,
    htop integration test). Combined PR; small per-item delta.
12. P4-LO-01 (slim deprecated facades). Last because it's pure cleanup.

Estimated ~10 PRs total. None are blockers for v1.0; all close real
gaps the audit surfaced.

---

## Out of scope for this file

- **Library research-doc gaps** (single-source-of-truth violations *outside*
  the PTY surface, missing features in non-PTY libs, sugar-dash dashboards).
  See `plans/leftover_updates_later.md`.
- **New PTY features beyond the deferred list.** If a user requests
  `forkpty(3)`-style anonymous spawn (no `proc_open` shim), open a new
  plan — that is a v2 surface, not a cleanup.
- **Documentation rewrites.** `candy-pty/README.md` parity table and
  `candy-pty/docs/CONCEPTS.md` are accurate. Touch them only if the
  underlying code shifts.
