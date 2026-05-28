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

## Step execution workflow (applies to every step below)

**Step inventory** (approx. one PR per step; ≤500 LOC + tests + docs each):

| Phase | Steps | PR count | Week |
|---|---|---:|---|
| P0 Foundation | P0.1 contracts · P0.2 PtySystem/Pair/Master · P0.3 SlavePty/Child · P0.4 Libc termios cdef | 4 | 1 |
| P1 Termios | P1.1 PosixTermios · P1.2 SttyTermios · P1.3 Factory+SizeIoctl.query · P1.4 candy-core delegate | 4 | 1–2 |
| P2 Pump | P2.1 PumpOptions · P2.2 Pump core · P2.3 EOF+flush · P2.4 callbacks · P2.5 InProcessTransport migration | 5 | 2 |
| P3 Process | P3.1 ChildPollTrait · P3.2 PosixProcess · P3.3 candy-shell migration | 3 | 2–3 |
| P4 Migration | P4.1 PtySystemFactory · P4.2 InProc DI · P4.3 Spawn middleware DI · P4.4 Program Termios DI · P4.5 deprecation+path-repo · P4.6 example | 6 | 3–4 |
| P5 Hardening | P5.1 bash/dash · P5.2 zsh/fish/python · P5.3 vim · P5.4 resize/large · P5.5 EOF/SIGINT/reap · P5.6 CI matrix · P5.7 docs parity | 7 | 4–5 |
| P6 Stretch | P6.1 Recorder tap · P6.2 PtyPool · P6.3 MultiPump · P6.4 Expect | 4 | 5–6 |
| P6.5 Shirley | P6.5.1 skeleton · P6.5.2 --shell/--env · P6.5.3 --idle-trim · P6.5.4 safety net · P6.5.5 integration · P6.5.6 perf | 6 | 5–6 |
| **Total** | **~39 steps** | **~39 PRs** | **6** |

Every step below is sized to fit a single subagent context and a single PR. The flow for each step is fixed — do not skip stages:

### Stage A — Implement (subagent: `general-purpose`, `feature-dev:code-architect` for design-heavy, `oac:code-execution` if a subtask JSON was generated)

Subagent prompt template:

> Context: SugarCraft PTY consolidation plan, step **<phase>.<n> — <title>**. Read `/home/sites/sugarcraft/plans/sugarcraft-is-a-mono-logical-twilight.md` to orient. Read `CLAUDE.md`, `AGENTS.md`, and the touched libs' `CALIBER_LEARNINGS.md` before editing.
>
> Deliverables: <list>. Files to create: <list>. Files to modify: <list>. Conventions: `declare(strict_types=1);`, PSR-12, `final` classes, immutable + fluent (`with*()` via `mutate()`), bare-named accessors, doc-comment cites upstream (`Mirrors creack/pty.<X>` or `Mirrors portable-pty.<X>`).
>
> Tests: add/update PHPUnit tests covering every public method touched. Use real PTYs (not mocks) per `candy-pty/CALIBER_LEARNINGS.md`. Tests must pass locally (`cd <lib> && vendor/bin/phpunit`).
>
> Docs: update the touched lib's `README.md` if public API changed; add a one-line entry to its `CALIBER_LEARNINGS.md` if a new pattern was discovered; update doc-comments to cite the upstream method.
>
> Do NOT commit, push, or open a PR — stop after editing files and report what changed. The orchestrator handles git operations.

### Stage B — Review (subagent: `feature-dev:code-reviewer` — fresh context, did not implement)

Subagent prompt template:

> Review the uncommitted changes for step **<phase>.<n>**. Run `git diff` to see what changed. Check against the plan's deliverables list for this step. Specifically look for:
>
> - Missed files or modifications the plan listed.
> - Conventions violations: missing `declare(strict_types=1)`, non-final public classes, accessor `get` prefixes, missing upstream cite, mutable state on `with*()` returns.
> - Edge cases not covered: EINTR retry, EOF detection, signal-handler exception safety (must not throw), `proc_close` -1 guard, FFI struct opacity (don't read individual fields).
> - Doc/README drift: public API change without README update, new pattern without `CALIBER_LEARNINGS.md` entry.
> - Backwards compatibility: any public API removal must be documented as a deprecation, not a deletion (v1.x policy).
>
> Report under 400 words: a punch list of items to fix. If clean, say so explicitly.

### Stage C — Fix review findings (same subagent as Stage A, continued via SendMessage)

Apply review findings. If review was clean, skip.

### Stage D — Verify tests + docs (subagent: `feature-dev:code-reviewer` — fresh context)

Subagent prompt template:

> Verify step **<phase>.<n>** is ready to ship. Run these checks and report results:
>
> 1. `cd <touched-lib> && vendor/bin/phpunit` — all green?
> 2. For every consumer lib (candy-core, candy-shell, candy-wish, candy-vcr that depends on what changed): `composer install --quiet && vendor/bin/phpunit` — still green?
> 3. New public methods all have ≥1 test?
> 4. `README.md` of touched lib reflects current public API?
> 5. Doc-comments on new public methods cite the upstream Mirrors line?
> 6. `CALIBER_LEARNINGS.md` updated if a non-obvious pattern was discovered?
> 7. `composer validate` clean (without `--strict`) for every modified `composer.json`?
>
> Report PASS or FAIL with specifics. If FAIL, list items needing fix.

### Stage E — Fix verification findings (same subagent as Stage A)

Apply verification findings. Loop B→C→D→E if anything still fails.

### Stage F — Ship (orchestrator runs these directly, not via subagent)

```sh
# 1. Pre-commit Caliber sync (CLAUDE.md "Before Committing")
grep -q "caliber" .git/hooks/pre-commit 2>/dev/null && echo "hook-active" || echo "no-hook"
# If hook-active: commit normally. If no-hook: run `caliber refresh && git add CLAUDE.md .claude/ .cursor/ .cursorrules .github/copilot-instructions.md .github/instructions/ AGENTS.md CALIBER_LEARNINGS.md .agents/ .opencode/ 2>/dev/null`.

# 2. Branch + commit (author Joe Huss <detain@interserver.net>)
git checkout -b ai/<slug>-<short>      # e.g. ai/candy-pty-contracts
git add <specific files — NOT -A>
git commit -m "$(cat <<'EOF'
<lib>: <step title> (PTY plan <phase>.<n>)

- <bullet 1>
- <bullet 2>
EOF
)"

# 3. Push + PR + merge + pull (TOKEN MUST BE UNSET)
git push -u origin HEAD
unset GITHUB_TOKEN && gh pr create --title "<lib>: <step title> (PTY plan <phase>.<n>)" --body "$(cat <<'EOF'
## Summary
- <what changed>
- <why>

## Test plan
- [x] `cd <lib> && vendor/bin/phpunit` — N tests passing
- [x] Consumer regression: candy-core / candy-shell / candy-wish / candy-vcr suites green
- [x] README + CALIBER_LEARNINGS updated

PTY consolidation plan: \`/home/sites/sugarcraft/plans/sugarcraft-is-a-mono-logical-twilight.md\` step <phase>.<n>.

EOF
)"
PR=$(gh pr view --json number -q .number)
unset GITHUB_TOKEN && gh pr merge "$PR" --merge --delete-branch
git checkout master && git pull --ff-only
```

**Hard rules for every step:**

1. **`unset GITHUB_TOKEN` before every `gh` command.** The CLAUDE.md PR workflow says so; ambient tokens cause auth errors against the SugarCraft remotes.
2. **Author line is `Joe Huss <detain@interserver.net>`.** Never amend, never `--no-verify`, never force-push to master.
3. **Bundle 2–4 related items per PR** (per CLAUDE.md) — if a step is genuinely one tiny item, group it with the next compatible step into one PR. The step list below is sized so most steps stand alone as one PR; adjacent steps that are <50 LOC each may be combined.
4. **Stage A → F is sequential.** Do not skip Review (B) even if implementation feels obvious. Do not skip Verify (D) even if Review (B) was clean.
5. **Each subagent gets the plan path** so it can re-orient. Do not paste the whole plan into the prompt.
6. **No parallel steps within a phase.** Per CLAUDE.md gotcha, concurrent writes to `MATCHUPS.md` / `README.md` collide. Steps run serially.
7. **If a subagent fails, check `.logs/subtask*.log` + `.sisyphus/`** before retrying (CLAUDE.md gotcha).

---

## Phases

### P0 — Foundation (week 1)

**Goal:** introduce the interface layer without breaking any callers.

#### Step P0.1 — Contract interfaces

- **Subagent:** `feature-dev:code-architect` (design-heavy, no logic).
- **Branch:** `ai/candy-pty-contracts`.
- **Create:** `candy-pty/src/Contract/{PtySystem,PtyPair,MasterPty,SlavePty,Child,Process,Termios,Pump}.php` — pure interfaces, signatures from the Architecture section above.
- **Modify:** none.
- **Tests:** `candy-pty/tests/Contract/InterfacesTest.php` — reflection check: every interface autoloads, has expected method names + return types.
- **Docs:** add `## Architecture` section to `candy-pty/README.md` linking each contract → its upcoming Posix implementation.
- **Review focus:** signatures match the design table (return types, nullability, throws annotations). No logic in interfaces. Every method has an `@see` cite to `creack/pty` or `portable-pty` equivalent.

#### Step P0.2 — Extract `PosixPtySystem` + `PosixPtyPair` + `PosixMasterPty` from `Pty.php`

- **Subagent:** `general-purpose`.
- **Branch:** `ai/candy-pty-posix-master`.
- **Create:** `candy-pty/src/Posix/{PosixPtySystem,PosixPtyPair,PosixMasterPty}.php` implementing the contracts.
- **Modify:** `candy-pty/src/Pty.php` — keep public surface intact; static methods delegate to `PosixPtySystem`. Add `@deprecated since v0.x, will keep through v1.x` doc-block.
- **Tests:** existing `Pty.php` tests must still pass unchanged. Add `candy-pty/tests/Posix/PosixPtySystemTest.php` exercising the new class directly.
- **Docs:** update `candy-pty/README.md` quickstart to show both old (`Pty::open()`) and new (`PtySystemFactory::default()->open()`) styles; new is canonical.
- **Review focus:** verbatim lift of EINTR/deadline read loop, `php://fd/N` stream cache, FFI ownership semantics. No behavioural change.

#### Step P0.3 — Extract `PosixSlavePty` + `PosixChild`

- **Subagent:** `general-purpose`.
- **Branch:** `ai/candy-pty-posix-child`.
- **Create:** `candy-pty/src/Posix/{PosixSlavePty,PosixChild}.php`.
- **Modify:** `candy-pty/src/Spawn.php` and `candy-pty/src/Child.php` — keep as backward-compatible aliases that extend the new Posix classes. Mark `@deprecated`.
- **Tests:** existing `SpawnTest`, `SpawnProcTest`, `ControllingTerminalTest` must still pass. Add `candy-pty/tests/Posix/PosixSlavePtyTest.php`, `candy-pty/tests/Posix/PosixChildTest.php`.
- **Docs:** mirror the pattern in `README.md`.
- **Review focus:** destructor zombie-reaper preserved; `proc_close` -1 guard preserved; controlling-terminal shim path unchanged.

#### Step P0.4 — Extend `Libc.php` cdef + `SizeIoctl` doc-cite

- **Subagent:** `general-purpose`.
- **Branch:** `ai/candy-pty-libc-termios-cdef`.
- **Modify:** `candy-pty/src/Libc.php` — extend `cdef` with `tcgetattr`, `tcsetattr`, `cfmakeraw`, `cfgetospeed`. Define `struct termios` as opaque ≥80-byte buffer (do **not** map individual fields — glibc/musl/Darwin differ). `candy-pty/src/SizeIoctl.php` — add `@see creack/pty.GetsizeFull` doc-comment; no behaviour change yet.
- **Tests:** `candy-pty/tests/LibcTermiosTest.php` — assert the cdef parses and the new symbols resolve via `FFI::cdef()` on Linux + macOS. Skip on Windows. **No** actual termios syscalls yet — those land in P1.
- **Docs:** one-line entry in `candy-pty/CALIBER_LEARNINGS.md`: `[pattern:opaque-termios-struct] — struct termios layout differs glibc/musl/Darwin; treat as opaque buffer, only call cfmakeraw/tcgetattr/tcsetattr.`.
- **Review focus:** struct size is ≥80 (covers Darwin's 72 + slack); cdef syntax compiles on both glibcs the CI tests with; no individual field reads.

**Phase P0 acceptance:** `for d in candy-pty candy-core candy-shell candy-wish candy-vcr; do (cd "$d" && composer install --quiet && vendor/bin/phpunit) || exit 1; done` — all green. Zero public API removed. Four PRs merged.

### P1 — Termios FFI primary, stty fallback (week 1–2)

**Goal:** eliminate `stty` from the hot path.

#### Step P1.1 — `PosixTermios` (FFI implementation)

- **Subagent:** `general-purpose`.
- **Branch:** `ai/candy-pty-posix-termios`.
- **Create:** `candy-pty/src/Posix/PosixTermios.php` — implements `Contract\Termios`.
  - Constructor takes int fd or PHP stream resource.
  - `current(): Termios` — `tcgetattr(fd, &buf)` into opaque ≥80-byte buffer; returns new immutable instance.
  - `makeRaw(): Termios` — clones via `mutate()`, calls `cfmakeraw(&buf)` on the clone.
  - `apply(int $when = TCSANOW): void` — `tcsetattr(fd, when, &buf)`.
  - `restore(): void` — re-applies the original saved buffer.
  - `isAtty(): bool` — `posix_isatty($fd)` + `stream_isatty` variant.
- **Tests:** `candy-pty/tests/Posix/PosixTermiosTest.php` — open a real PTY (from `PosixPtySystem`), apply raw mode to the slave, write a line, read back, assert no echo + no canonical line-buffering. Skip if `ext-ffi` unavailable.
- **Docs:** add to `candy-pty/README.md` Architecture section.
- **Review focus:** struct stays opaque (no field reads); `mutate()` returns new instance (immutability invariant from CLAUDE.md); errno surfaced via `RuntimeException` on `-1` return.

#### Step P1.2 — `SttyTermios` (shell-out fallback)

- **Subagent:** `general-purpose`.
- **Branch:** `ai/candy-pty-stty-termios`.
- **Create:** `candy-pty/src/Posix/SttyTermios.php` — same contract.
  - `current()` runs `proc_open(['stty', '-g'])`, captures saved-mode string.
  - `makeRaw()` records the intent (clone with internal flag).
  - `apply()` runs `stty raw -echo`.
  - `restore()` runs `stty <saved>`.
- **Tests:** `candy-pty/tests/Posix/SttyTermiosTest.php` — same raw-mode behavioural test as P1.1; assert identical output bytes to FFI variant when both are run on the same shell.
- **Docs:** README note: "fallback used automatically when ext-ffi unavailable; or force via `SUGARCRAFT_TERMIOS=stty`".
- **Review focus:** all four `stty` invocations use `proc_open` with array argv (no shell quoting risk); errors don't leak terminal state.

#### Step P1.3 — `TermiosFactory` + `SizeIoctl::query()`

- **Subagent:** `general-purpose`.
- **Branch:** `ai/candy-pty-termios-factory`.
- **Create:** `candy-pty/src/TermiosFactory.php` — `open(int|resource $fd): Termios`. Try `PosixTermios` first; on `Throwable` or `SUGARCRAFT_TERMIOS=stty`, fall back to `SttyTermios`. Logs once at info level on downgrade.
- **Modify:** `candy-pty/src/SizeIoctl.php` — add `query(int|resource $fd): array{cols:int,rows:int,xpix:int,ypix:int}` that wraps `TIOCGWINSZ`. Throws `RuntimeException` if `posix_isatty` is false (callers handle fallback).
- **Tests:** `candy-pty/tests/TermiosFactoryTest.php` — verify factory picks FFI by default; `SUGARCRAFT_TERMIOS=stty` forces fallback; both pass the same behavioural snapshot test. `candy-pty/tests/SizeIoctlQueryTest.php` — open PTY, set 132×40 via `resize()`, assert `query()` returns 132×40.
- **Docs:** `candy-pty/README.md` Termios section; one-line entry in `CALIBER_LEARNINGS.md` for the env-var-forced-fallback pattern.
- **Review focus:** factory does not throw on FFI absence — it downgrades silently (with log). `query()` returns the standardized `{cols,rows,xpix,ypix}` shape consistently with existing `SizeIoctl::unpack()`.

#### Step P1.4 — Refactor `candy-core::PosixBackend` to delegate

- **Subagent:** `general-purpose`.
- **Branch:** `ai/candy-core-tty-via-pty`.
- **Modify:** `candy-core/src/Util/Tty/PosixBackend.php`:
  - `enableRawMode()` body becomes: `$this->termios = TermiosFactory::open($this->stream); $this->saved = $this->termios->current(); $this->termios->makeRaw()->apply();`.
  - `restore()` body: `$this->saved->apply();`.
  - `size()` tries `SizeIoctl::query($this->stream)` first; falls back to env vars / `stty size` only if `posix_isatty` is false.
  - `onResize()` / `drainSignals()` unchanged.
- **Modify:** `candy-core/composer.json` — add `"sugarcraft/candy-pty": "@dev"` and path-repo (verify per CLAUDE.md gotcha).
- **Tests:** existing `candy-core` `PosixBackendTest` and `ProgramTest` must pass. Add a test forcing `SUGARCRAFT_TERMIOS=stty` env var and asserting identical raw-mode byte behaviour.
- **Docs:** update `candy-core/README.md` if it mentions stty (search and replace). Add CALIBER entry: `[pattern:tty-via-pty] — Util\\Tty\\PosixBackend now delegates termios to candy-pty; stty is fallback-only.`
- **Review focus:** Composer path-repo closure correct (`composer validate` without `--strict` passes); every lib that depends on candy-core still installs cleanly; `stty` binary no longer in the hot path when ext-ffi present.

**Phase P1 acceptance:** `candy-core::PosixBackend` does not exec `stty` on the primary path (verify with `strace`-grep or test that stubs `proc_open` and asserts no `stty` calls). `SUGARCRAFT_TERMIOS=stty vendor/bin/phpunit` green for candy-core. Four PRs merged.

### P2 — Pump extraction (week 2)

**Goal:** make the byte-pump loop reusable.

#### Step P2.1 — `PumpOptions` DTO

- **Subagent:** `general-purpose`.
- **Branch:** `ai/candy-pty-pump-options`.
- **Create:** `candy-pty/src/PumpOptions.php` — readonly value object: `chunkBytes` (default 4096), `selectTimeoutUs` (50000), `flushDeadlineSec` (0.5), `stdinEofGraceSec` (0.3), `veof` (`"\x04"`), `keepalive` (`?callable`), `onSigwinch` (`?callable`), `onChildExit` (`?callable`). Constants pulled verbatim from current `InProcessTransport` to preserve SSH behaviour.
- **Tests:** `candy-pty/tests/PumpOptionsTest.php` — defaults, immutability via `with*()`, callable null-handling.
- **Docs:** doc-comment on each field cites the originating constant in `InProcessTransport.php`.
- **Review focus:** all `with*()` use the `mutate()` helper (CLAUDE.md immutable+fluent invariant); defaults exactly match current SSH-tested values (no behavioural drift).

#### Step P2.2 — `PosixPump` skeleton (read/write + select loop)

- **Subagent:** `general-purpose`.
- **Branch:** `ai/candy-pty-pump-core`.
- **Create:** `candy-pty/src/Posix/PosixPump.php` implementing `Contract\Pump`.
- **Extract from** `candy-wish/src/Transport/InProcessTransport.php`: `pump()` core, `writeFully()`, `drainMaster()`, `forwardStdinToMaster()`. Keep their internal logic verbatim — just relocate.
- **Signature:** `run(MasterPty $master, $stdinStream, $stdoutStream, ?Child $child, PumpOptions $opts): int`.
- **Modify:** `InProcessTransport` — temporarily duplicate the logic (do NOT delete yet — P2.5 wires the migration). Just import `PosixPump` and add a hidden code path.
- **Tests:** `candy-pty/tests/Posix/PosixPumpTest.php` covering: simple write-through, large-buffer (1MB) round-trip, partial-write retry, EOF on stdin → graceful drain.
- **Docs:** README architecture section gets Pump bullet.
- **Review focus:** zero behavioural change at this stage; `InProcessTransport` callers still hit the original code path.

#### Step P2.3 — `PosixPump` EOF grace + flush deadline

- **Subagent:** `general-purpose`.
- **Branch:** `ai/candy-pty-pump-eof-flush`.
- **Modify:** `PosixPump` — wire `flushDeadlineSec` and `stdinEofGraceSec` from `PumpOptions`. The grace window lets master output drain after stdin closes; the deadline guards against stuck writes.
- **Tests:** `candy-pty/tests/Posix/PosixPumpEofGraceTest.php` — close stdin while child mid-write; assert pump drains output before returning. `candy-pty/tests/Posix/PosixPumpFlushDeadlineTest.php` — fill a pipe past capacity; assert deadline triggers + pump returns with a documented partial-write error.
- **Docs:** README EOF/flush semantics section.
- **Review focus:** timing constants identical to the SSH-tested values; no busy-loop on EOF; exception types match `Contract\Pump`'s throws annotations.

#### Step P2.4 — `PosixPump` SIGWINCH + keepalive callbacks

- **Subagent:** `general-purpose`.
- **Branch:** `ai/candy-pty-pump-callbacks`.
- **Modify:** `PosixPump` — invoke `onSigwinch` and `keepalive` callbacks at the right loop points (between `stream_select` returns). Reuse `SignalForwarder` for SIGWINCH attach; pump just calls back into user code.
- **Tests:** `candy-pty/tests/Posix/PosixPumpSigwinchTest.php` — inject fake size provider, send `posix_kill(getpid(), SIGWINCH)`, assert callback fires and master receives `TIOCSWINSZ`. `candy-pty/tests/Posix/PosixPumpKeepaliveTest.php` — set a 100ms cadence callback, run pump for 350ms idle, assert ≥3 invocations.
- **Docs:** README "Callbacks" section.
- **Review focus:** signal handlers do **not** throw (CLAUDE.md pattern); callback exceptions are caught + logged but don't tear down the pump.

#### Step P2.5 — Migrate `InProcessTransport` to `PosixPump`

- **Subagent:** `general-purpose`.
- **Branch:** `ai/candy-wish-uses-posix-pump`.
- **Modify:** `candy-wish/src/Transport/InProcessTransport.php` — delete the inlined pump internals (`pump()`, `writeFully()`, `drainMaster()`, `forwardStdinToMaster()` and their constants). `runChild()` shrinks to: open pty → spawn child → attach SIGWINCH → instantiate `PosixPump` → `run()`. Target ~30 lines (down from 200+).
- **Tests:** existing `candy-wish/tests/InProcessTransportRunChildTest.php` and `InProcessTransportSigwinchTest.php` must pass unchanged. Run the full candy-wish suite end-to-end SSH fixtures.
- **Docs:** `candy-wish/README.md` updates the "Architecture" section to point at `candy-pty::PosixPump`.
- **Review focus:** byte-for-byte parity with prior behaviour on the SSH SSH/ keepalive timing; no removed PumpOptions defaults; the SSH end-to-end suite passes.

**Phase P2 acceptance:** `candy-wish` full suite green. `candy-pty::PosixPump` is the single owner of the pump loop. Five PRs merged.

### P3 — Process consolidation (week 2–3)

**Goal:** unify non-PTY subprocess handling with the PTY child lifecycle.

#### Step P3.1 — `ChildPollTrait` extraction

- **Subagent:** `general-purpose`.
- **Branch:** `ai/candy-pty-child-poll-trait`.
- **Create:** `candy-pty/src/Posix/ChildPollTrait.php` — package-private trait holding: `running=false` detection, `exitcode` capture before `proc_close()`, `-1` post-reap guard, destructor zombie-reaper safety net.
- **Modify:** `PosixChild` to use the trait (no behavioural change — verify against `ChildLifecycleTest`).
- **Tests:** existing `candy-pty/tests/SpawnTest.php` and `ControllingTerminalTest.php` must pass. Add `candy-pty/tests/Posix/ChildPollTraitTest.php` exercising trait in isolation via a stub class.
- **Docs:** doc-comment on trait cites the upstream `creack/pty` waitpid semantics.
- **Review focus:** trait methods are protected/private (not bleeding into public API); destructor doesn't throw; `proc_close` -1 handling preserved verbatim.

#### Step P3.2 — `PosixProcess` (non-PTY spawn)

- **Subagent:** `general-purpose`.
- **Branch:** `ai/candy-pty-posix-process`.
- **Create:** `candy-pty/src/Posix/PosixProcess.php` — uses `ChildPollTrait`. Public API: `pid()`, `exited()`, `wait()`, `kill(int $signal)`, `exitCode()`, plus `stdoutBytes()`, `stderrBytes()` when capture enabled. Constructor accepts cmd array, env, capture flags.
- **Tests:** `candy-pty/tests/Posix/PosixProcessTest.php` covering capture/no-capture, exit-code propagation, signal kill (SIGTERM/SIGKILL), stdin closure, stderr separation. Spawn real `/bin/echo`, `/bin/sh -c 'exit 42'`, `/bin/sleep 10` + kill.
- **Docs:** `candy-pty/README.md` Process section.
- **Review focus:** stdout/stderr drained before `proc_close()` (avoids hang); non-blocking pipe pattern from current `RealProcess` preserved.

#### Step P3.3 — Migrate `candy-shell::RealProcess`

- **Subagent:** `general-purpose`.
- **Branch:** `ai/candy-shell-uses-posix-process`.
- **Pre-step grep:** `grep -rn "RealProcess" candy-shell/ candy-core/ candy-wish/ candy-vcr/` — count external callers (per Risk #7).
- **Modify:** if `RealProcess` is internal (zero external callers): delete `candy-shell/src/Process/RealProcess.php` and update callers to import `SugarCraft\Pty\Posix\PosixProcess`. If external callers exist: convert `RealProcess` to a thin `extends PosixProcess` alias with `@deprecated`.
- **Modify:** `candy-shell/composer.json` — add `"sugarcraft/candy-pty": "@dev"` + path-repo (likely already transitive via candy-core; verify).
- **Tests:** existing `candy-shell` suite passes. Add no new tests (coverage lives in `PosixProcessTest`).
- **Docs:** `candy-shell/README.md` updates the "Process Execution" section.
- **Review focus:** every caller updated; `composer validate` clean; behavioural parity (especially around `RealProcess`'s `/dev/null` stdin binding).

**Phase P3 acceptance:** `candy-shell` full suite green. `proc_get_status()` polling lives in exactly one trait. Three PRs merged.

### P4 — Migration + interface adoption (week 3–4)

**Goal:** consumers use the new contracts; legacy facades documented as deprecated for next major.

#### Step P4.1 — `PtySystemFactory` + `UnsupportedPlatformException`

- **Subagent:** `general-purpose`.
- **Branch:** `ai/candy-pty-system-factory`.
- **Create:** `candy-pty/src/PtySystemFactory.php` — `default(): PtySystem` returns `PosixPtySystem` on Linux/macOS; throws `UnsupportedPlatformException` on Windows (path reserved for v2 ConPTY). `candy-pty/src/Exception/UnsupportedPlatformException.php`.
- **Tests:** `candy-pty/tests/PtySystemFactoryTest.php` — assert default on POSIX, assert exception text on simulated Windows (mock `PHP_OS_FAMILY` via a swap point — or skip the windows assertion and rely on the comment).
- **Docs:** `candy-pty/README.md` — DI-friendly quickstart section.
- **Review focus:** exception message is actionable (tells user to file an issue / use sidecar v2); factory is dependency-free.

#### Step P4.2 — `candy-wish::InProcessTransport` accepts injected `PtySystem`

- **Subagent:** `general-purpose`.
- **Branch:** `ai/candy-wish-pty-system-di`.
- **Modify:** `candy-wish/src/Transport/InProcessTransport.php` — add optional `PtySystem $pty = null` constructor parameter, defaulting to `PtySystemFactory::default()`.
- **Tests:** existing tests pass; add `InProcessTransportInjectedSystemTest` that passes a stub `PtySystem` and asserts it's invoked.
- **Docs:** README example shows DI usage.
- **Review focus:** default parameter doesn't break call sites (tests SSH server bootstrap still constructs cleanly with no args).

#### Step P4.3 — `candy-wish::Spawn` middleware uses `PtySystem`

- **Subagent:** `general-purpose`.
- **Branch:** `ai/candy-wish-spawn-pty-system`.
- **Modify:** `candy-wish/src/Middleware/Spawn.php` — replace static `Pty::open()` with injected/factory-resolved `PtySystem->open()`.
- **Tests:** existing `SpawnMiddlewareTest` passes; add a test injecting a stub system to verify call count + args.
- **Docs:** README middleware section.
- **Review focus:** middleware behaviour unchanged for existing SSH flows; stub injection works end-to-end.

#### Step P4.4 — `candy-core::Program` optional `Termios` injection

- **Subagent:** `general-purpose`.
- **Branch:** `ai/candy-core-program-termios-di`.
- **Modify:** `candy-core/src/Program.php` — add optional `?Termios $termios = null` constructor parameter; when null, `PosixBackend` continues to resolve via `TermiosFactory`. Wire through `ProgramOptions` if that's how Program is configured.
- **Tests:** existing `ProgramTest` passes; add a test injecting a stub `Termios` and asserting `apply`/`restore` are called at setup/teardown.
- **Docs:** Program docblock cites the injection seam.
- **Review focus:** seam exists for tests without breaking simple `new Program(model)` usage; teardown still always restores.

#### Step P4.5 — `@deprecated` markers + path-repo audit

- **Subagent:** `general-purpose`.
- **Branch:** `ai/candy-pty-deprecation-markers`.
- **Modify:** add `@deprecated since v0.x, use <new path>` doc-blocks to `Pty::open()`, `Spawn::proc()`, `Child` static helpers, etc. Do **not** remove — they ship intact through v1.x per Goals.
- **Modify:** root `composer.json` — verify `repositories[]` covers `candy-pty` path for every consuming lib (per CLAUDE.md gotcha). Run `composer validate` on every lib (no `--strict`); fix any drift.
- **Tests:** add a `tools/check-path-repos.php` script + CI step that fails if a lib's composer.json lists a `sugarcraft/*` dep but no path-repo for it.
- **Docs:** `MATCHUPS.md` row for candy-pty bumped to reflect P0–P4 progress.
- **Review focus:** every `@deprecated` cite is correct + reachable; path-repo closure complete; the new script catches a deliberate test-break.

#### Step P4.6 — `spawn-bash.php` example

- **Subagent:** `general-purpose`.
- **Branch:** `ai/candy-pty-spawn-bash-example`.
- **Create:** `candy-pty/examples/spawn-bash.php` — minimal interactive example using `PtySystemFactory::default()->open(80, 24)`, `$pair->slave()->spawn(['bash'], ...)`, type "echo hi", see "hi". Documented in comments.
- **Tests:** `candy-pty/tests/Examples/SpawnBashExampleTest.php` runs the example via subprocess and asserts exit 0 + expected output substring.
- **Docs:** `candy-pty/composer.json` `autoload-dev` covers `examples/`. README quickstart points at this file.
- **Review focus:** example is genuinely the simplest path (no SSH, no custom options); doesn't `exit()` from mid-example (clean teardown).

**Phase P4 acceptance:** Every consumer compiles + tests green with the new DI seams. Legacy facades remain functional but marked. Six PRs merged.

### P5 — Hardening + parity testing (week 4–5)

**Goal:** be honestly "as good as creack/pty on Linux + macOS".

#### Step P5.1 — Bash + Dash interactive tests

- **Subagent:** `general-purpose`.
- **Branch:** `ai/candy-pty-test-bash-dash`.
- **Create:** `candy-pty/tests/Integration/BashInteractiveTest.php` — spawn `bash -i`, send `echo hi`, assert "hi" appears within 1s. `candy-pty/tests/Integration/DashTest.php` — same script against `dash` (POSIX baseline).
- **Docs:** `candy-pty/tests/Integration/README.md` explains the integration test convention (skip gracefully when shell missing, real-PTY only, 5s timeout default).
- **Review focus:** uses `controllingTerminal: true` so Ctrl-C works in the test; timeout safeguards prevent hung CI runs; `markTestSkipped` paths are correct.

#### Step P5.2 — Zsh + Fish + Python REPL tests

- **Subagent:** `general-purpose`.
- **Branch:** `ai/candy-pty-test-zsh-fish-python`.
- **Create:** `candy-pty/tests/Integration/ZshTest.php`, `FishTest.php`, `PythonReplTest.php`. Each uses the convention from P5.1. Python test exercises multi-line input (heredoc-style continuation prompt).
- **Docs:** matrix README updates for the added shells.
- **Review focus:** the `markTestSkipped('zsh not installed')` pattern is uniform; tests don't leak processes on assertion failure (use `try/finally` to `kill()` + `wait()`).

#### Step P5.3 — Vim smoke test (with candy-vt screen assertion)

- **Subagent:** `general-purpose` — depends on `candy-vt` for screen state.
- **Branch:** `ai/candy-pty-test-vim-smoke`.
- **Create:** `candy-pty/tests/Integration/VimSmokeTest.php` — spawn `vim /tmp/scratch.txt`, send `iHello<Esc>:wq<CR>`, assert file content is "Hello" and exit code 0. Feed PTY output through `candy-vt::Terminal` to assert the editor screen showed "Hello" before save.
- **Modify:** `candy-pty/composer.json` `require-dev` adds `"sugarcraft/candy-vt": "@dev"` + path-repo.
- **Review focus:** vim handles SIGWINCH (verifies the SIGWINCH path); test cleans up `/tmp/scratch.txt`; `markTestSkipped` if `vim` absent.

#### Step P5.4 — Resize race + large buffer tests

- **Subagent:** `general-purpose`.
- **Branch:** `ai/candy-pty-test-resize-buffer`.
- **Create:** `candy-pty/tests/Integration/ResizeRaceTest.php` — spawn `bash -c 'while true; do tput cols; sleep 0.01; done'`, resize the PTY 50 times across 1 second, assert every read line is a parseable integer (no torn reads). `candy-pty/tests/Integration/LargeBufferTest.php` — write 1 MB to master, read on slave, assert byte-identical.
- **Review focus:** resize cadence isn't pathologically tight (DoS-style); 1MB test has explicit `flushDeadlineSec` to avoid hangs.

#### Step P5.5 — EOF grace + SIGINT forwarding + orphan reap

- **Subagent:** `general-purpose`.
- **Branch:** `ai/candy-pty-test-eof-sigint-reap`.
- **Create:** `EOFGraceTest.php` (close stdin while child mid-write; assert drain). `SIGINTForwardingTest.php` (spawn `sleep 30` with controlling-terminal, write `\x03`, assert exit within 1s). `OrphanedChildReapTest.php` (exit parent without `wait()`, fork-and-check via `ps`, assert no zombies).
- **Review focus:** `OrphanedChildReap` test uses a subprocess to do the "abandon" — main test process can't actually abandon. SIGINT test confirms TIOCSCTTY shim is on the path.

#### Step P5.6 — `pty-matrix` CI workflow

- **Subagent:** `general-purpose`.
- **Branch:** `ai/candy-pty-ci-matrix`.
- **Create:** `.github/workflows/pty-matrix.yml` — runs `candy-pty/tests/Integration/` on Ubuntu 22.04 + macOS 14, PHP 8.3 + 8.4, with and without `SUGARCRAFT_TERMIOS=stty`. Cache composer deps per CLAUDE.md gotcha on macOS runner cost — keep to integration suite only.
- **Modify:** `scripts/affected-libs.php` — ensure candy-core + candy-wish included in macOS pool for the duration of P5+. `.github/workflows/vhs.yml` matrix verified.
- **Docs:** root README adds CI badge for the new matrix.
- **Review focus:** macOS minutes are bounded (≤8 min total); secrets aren't required (the matrix uses only public refs); `ext-ffi` presence confirmed via `php -m` step.

#### Step P5.7 — README parity table + `CONCEPTS.md` + upstream cites

- **Subagent:** `general-purpose`.
- **Branch:** `ai/candy-pty-docs-parity`.
- **Modify:** `candy-pty/README.md` — add "Compared to node-pty / creack/pty / portable-pty" feature table from the third agent's research synthesis. Doc-comments on every public method get `Mirrors creack/pty.<Method>` cite (CLAUDE.md convention).
- **Create:** `candy-pty/docs/CONCEPTS.md` — short explainer: what a PTY is, master vs slave, controlling-terminal semantics, why `bin/pty-shim.php` exists, how SIGWINCH propagates. ~400 lines max.
- **Modify:** `candy-pty/CALIBER_LEARNINGS.md` — final pass folding patterns learned across P0–P5.
- **Review focus:** feature table is honest (don't claim parity where it's not earned); cites point at real upstream methods; CONCEPTS.md doesn't duplicate README quickstart.

**Phase P5 acceptance:** 100% of public methods have ≥1 test. Integration suite runs Linux + macOS CI green (without `markTestSkipped` for the standard bash/dash tools). README parity table ≥90% checkmarks vs `creack/pty` for Unix concerns. Seven PRs merged.

### P6 — Stretch / optional (week 5–6 buffer)

Only if P0–P5 land on time. Each item is one step (one PR).

#### Step P6.1 — `PosixPump` Recorder tap (precondition for P6.5)

- **Subagent:** `general-purpose`.
- **Branch:** `ai/candy-pty-pump-recorder-tap`.
- **Modify:** `candy-pty/src/PumpOptions.php` — add `?Recorder $recorder = null` field. `candy-pty/src/Posix/PosixPump.php` — when `$opts->recorder` is set, tee stdin → `recordInputBytes()` and master-read → `recordOutput()`. SIGWINCH callback also calls `recordResize()`.
- **Modify:** `candy-vcr/src/Recorder.php` — verify signatures match (`recordInputBytes(string)`, `recordOutput(string)`, `recordResize(int,int)`); widen if needed without breaking existing callers.
- **Tests:** `candy-pty/tests/Posix/PumpRecorderTapTest.php` — pump a bash session with recorder attached, then `Player::play()` the cassette, assert reproduction.
- **Docs:** README example showing the tap usage.
- **Review focus:** tap is zero-overhead when recorder is null; tee writes happen on the same loop iteration as the read (no buffering drift); cassette structure unchanged.

#### Step P6.2 — Optional: `PtyPool` resource pooling

- **Subagent:** `general-purpose`.
- **Branch:** `ai/candy-pty-pool`.
- **Profile first** — measure libc FFI handle open cost vs reuse cost. Only proceed if the gap is >5ms per open in a tight loop.
- **Create:** `candy-pty/src/PtyPool.php` if measurable — reuses FFI handles across opens for high-churn SSH server scenarios.
- **Tests:** pool stress test (100 sessions/sec), no leak after 10k opens.
- **Review focus:** pool doesn't change semantic guarantees (each `acquire()` is indistinguishable from `PtySystem::open()` to callers).

#### Step P6.3 — Optional: `MultiPump` multiplexer

- **Subagent:** `general-purpose`.
- **Branch:** `ai/candy-pty-multipump`.
- **Create:** `candy-pty/src/Posix/MultiPump.php` — supervises N pumps for split-pane / tmux-like scenarios. Single `stream_select` over all masters.
- **Tests:** spawn 4 children, write to each, assert independent output streams.
- **Review focus:** scales linearly; no deadlocks when one child stalls.

#### Step P6.4 — Optional: Expect-style API

- **Subagent:** `general-purpose`.
- **Branch:** `ai/candy-pty-expect`.
- **Create:** `candy-pty/src/Expect.php` — fluent `Expect::on($pty)->send("login: ")->expect("password:", timeout: 5)->send("...")->...` modeled on Python `pexpect`.
- **Tests:** scripted login dialog against a fixture child.
- **Review focus:** API is genuinely fluent (`with*()` style per CLAUDE.md); timeout semantics are clear; `expect()` returns the matched text.

### P6.5 — Shirley-style PTY recorder CLI (stretch, week 5–6)

**Goal:** standalone `candy-vcr record -- <cmd>` CLI that records command execution at the PTY level, equivalent to `asciinema rec` or the hypothetical charmbracelet/shirley described in `docs/research/libraries/candy-vcr-research.md:110-127`. The PTY consolidation makes this nearly free — it's the canonical first external user of the new `PosixPump` + Recorder-tap combination.

#### Step P6.5.1 — `RecordCommand` skeleton

- **Subagent:** `general-purpose`.
- **Branch:** `ai/candy-vcr-record-command-skeleton`.
- **Create:** `candy-vcr/src/Cli/RecordCommand.php` — Symfony Console command. Argv: `candy-vcr record [--output session.cas] -- <cmd> [args...]`. Wires: `PtySystemFactory::default()->open()`, spawn child with `controllingTerminal: true`, `TermiosFactory::open(STDIN)->makeRaw()->apply()`, instantiate `Recorder`, run `PosixPump` with `recorder` option set, restore termios on exit.
- **Modify:** `candy-vcr/src/Cli/Application.php` — register the new command. `candy-vcr/composer.json` — add `"sugarcraft/candy-pty": "@dev"` + path-repo (direct dep, no longer just transitive).
- **Tests:** `candy-vcr/tests/Cli/RecordCommandTest.php` — invoke `candy-vcr record -- /bin/echo hello`, assert cassette has expected `output` event + `quit` event with exit 0.
- **Docs:** `candy-vcr/README.md` — new "Recording commands" section.
- **Review focus:** controlling-terminal Ctrl+C reaches the recorded program (not the recorder); termios is restored on every exit path including `Throwable`.

#### Step P6.5.2 — `--shell` and `--env` flags

- **Subagent:** `general-purpose`.
- **Branch:** `ai/candy-vcr-record-shell-env`.
- **Modify:** `RecordCommand` — `--shell` shorthand (spawn `$SHELL -l` or `/bin/sh -l`). `--env` flag: capture allowed `ENV` keys (allowlist with secret-name regex filter from `/(SECRET|TOKEN|KEY|PASSWORD|API)/i`) into cassette header.
- **Tests:** `RecordCommandShellTest.php`, `RecordCommandEnvFilterTest.php` — assert secret keys are stripped and visible keys preserved.
- **Docs:** README documents the secret-stripping default.
- **Review focus:** allowlist regex is conservative (rather strip-too-much than leak); env capture doesn't include the user's full shell environment by default (opt-in).

#### Step P6.5.3 — `--idle-trim` with dual timestamps

- **Subagent:** `general-purpose`.
- **Branch:** `ai/candy-vcr-record-idle-trim`.
- **Modify:** `RecordCommand` — `--idle-trim <seconds>` flag. When a gap >N seconds appears between events, write the event with both `t` (compressed) and `tRaw` (original) fields. `candy-vcr/src/Player.php` — honour `tRaw` when present and `--no-trim` is passed to replay.
- **Tests:** `RecordCommandIdleTrimTest.php` — spawn `sleep 2` mid-session, assert original `tRaw` ≥ 2s while trimmed `t` < 0.5s. Replay test asserts both timing modes work.
- **Docs:** README idle-trim section with the asciinema comparison from research doc §4.1.
- **Review focus:** dual-timestamp format stays backward-compatible (events without `tRaw` still parse); `--no-trim` replay is opt-in, not default.

#### Step P6.5.4 — Host termios safety net

- **Subagent:** `general-purpose`.
- **Branch:** `ai/candy-vcr-record-safety-net`.
- **Modify:** `RecordCommand` — register `register_shutdown_function` + `pcntl_signal(SIGTERM, ...)` + `pcntl_signal(SIGHUP, ...)` handlers that always restore host termios. Hold the saved snapshot in a static so handlers can reach it.
- **Tests:** `RecordCommandSafetyNetTest.php` — fork a recorder, send SIGTERM mid-session, parent process verifies its own termios is sane (re-query and compare).
- **Docs:** add CALIBER entry: `[pattern:host-tty-rescue] — recorder must restore termios even on SIGKILL-class exits; use shutdown_function + signal handlers + persistent saved state.`
- **Review focus:** handlers don't allocate / log heavily (signal-safe minimum); SIGKILL is documented as the one case we can't recover from (mitigate by storing saved termios to `/tmp` so a separate `stty sane` recovery is at least possible).

#### Step P6.5.5 — Integration tests: bash and vim

- **Subagent:** `general-purpose`.
- **Branch:** `ai/candy-vcr-shirley-integration`.
- **Create:** `candy-vcr/tests/Integration/ShirleyBashTest.php` (record `bash -c 'echo hello; sleep 0.2; echo world'`, replay, assert byte-equality on stdout events). `candy-vcr/tests/Integration/ShirleyVimTest.php` (record `vim /tmp/scratch`, send `iHello<Esc>:wq<CR>`, replay through `candy-vt::Terminal`, screen-assert empty buffer post-quit).
- **Modify:** `candy-vcr/composer.json` `require-dev` — confirm candy-vt dep.
- **Docs:** README sample output from `candy-vcr stats` on the produced cassettes.
- **Review focus:** integration suite is bounded (≤30s wallclock); uses real shells; cleans up `/tmp/scratch*`.

#### Step P6.5.6 — Performance baseline

- **Subagent:** `general-purpose`.
- **Branch:** `ai/candy-vcr-shirley-perf`.
- **Create:** `candy-vcr/tests/Integration/ShirleyOverheadTest.php` — measure `time bash -c 'seq 100000'` with and without `candy-vcr record` wrapper; assert overhead ≤2% wallclock.
- **Docs:** add the measured number to README.
- **Review focus:** measurement uses multiple runs (≥5) and median, not single-shot; CI tolerance ±0.5% to avoid flakes.

The current `candy-vcr` only records sessions attached as a library to a `SugarCraft\Core\Program` (API-mode capture). A Shirley-style recorder closes the gap so users can record **any** terminal program — `bash`, `vim`, `htop`, `python -i`, an external CLI under test — without that program needing to know about SugarCraft.

**Phase P6.5 acceptance:**
- `candy-vcr record -- bash -c 'echo hello; sleep 0.2; echo world'` → cassette → replay reproduces the session.
- `candy-vcr record -- vim /tmp/scratch.txt` round-trips through `candy-vt::Terminal` screen-assertion.
- Recording overhead ≤2% wall-clock on `time bash -c 'seq 100000'`.
- README documented with asciinema comparison.

**Scope discipline (NOT in P6.5):** asciinema `.cast` format conversion, hook system, custom matchers, gzip compression, SVG rendering. Each tracked separately in `candy-vcr-research.md` §13.

**Dependencies:** P6.1 (PumpOptions Recorder tap) is the hard precondition. P0–P5 must be merged. Six PRs merged total for this phase.

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

**Per-step verification is the Stage D subagent's job** (see "Step execution workflow" above). The commands below are the orchestrator-level / end-of-phase smoke checks the human runs to confirm a phase landed cleanly. They are not a substitute for per-step Stage D.

End-of-phase smoke (manual):

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
