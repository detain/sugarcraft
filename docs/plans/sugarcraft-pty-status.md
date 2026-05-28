# sugarcraft-pty — session handoff

Continuation guide for the candy-pty rollout. PTY plan P0–P5 are **done**; P6 (stretch) is the next phase. This doc gives a fresh session everything needed to pick up and run.

## TL;DR

- **Plan**: `plans/sugarcraft-is-a-mono-logical-twilight.md` is the source of truth. P0–P5 are complete; P6.1–P6.5 are open.
- **CI status**: ✅ **GREEN on master** as of run `25982131082` (PR #475 Darwin `stty` fallback merged 2026-05-17). All P0–P5 acceptance criteria met. First fully green master run of the rollout.
- **Working tree**: on `master`, clean. Pre-existing `candy-pty/CALIBER_LEARNINGS.md` modification is harmless (Caliber auto-generated, untracked from this session).
- **Next step**: Start **P6.1** — `PosixPump` Recorder tap.

## Plan reference

`plans/sugarcraft-is-a-mono-logical-twilight.md` — read sections P6.1 → P6.5.6.

P6 step shape (per the plan):

| Step | Branch | Scope |
|---|---|---|
| **P6.1** | `ai/candy-pty-pump-recorder-tap` | `PosixPump` Recorder tee for candy-vcr (precondition for P6.5) |
| **P6.2** | `ai/candy-pty-pool` | Optional `PtyPool` — profile-gated; only proceed if FFI open cost > 5ms |
| **P6.3** | `ai/candy-pty-multipump` | Optional `MultiPump` for split-pane / tmux supervision |
| **P6.4** | `ai/candy-pty-expect` | Optional `Expect`-style fluent API (Python `pexpect` analogue) |
| **P6.5.1** | `ai/candy-vcr-record-command-skeleton` | Shirley-style `candy-vcr record -- <cmd>` CLI |
| **P6.5.2** | `ai/candy-vcr-record-shell-env` | `--shell` / `--env` flags with secret regex filter |
| **P6.5.3** | `ai/candy-vcr-record-idle-trim` | `--idle-trim N` with dual timestamps (t / tRaw) |
| **P6.5.4** | `ai/candy-vcr-record-safety-net` | Host termios rescue via shutdown_function + SIGTERM/SIGHUP handlers |
| **P6.5.5** | `ai/candy-vcr-shirley-integration` | bash + vim integration tests through `candy-vt::Terminal` |
| **P6.5.6** | `ai/candy-vcr-shirley-perf` | ≤2% overhead assertion on `time bash -c 'seq 100000'` |

**P6.5 is the headline.** Closes the gap with `asciinema rec` / charmbracelet/shirley. Depends on P6.1 (Recorder tap). Hot path: P6.1 → P6.5.1 → P6.5.2 → P6.5.3 → P6.5.4 → P6.5.5 → P6.5.6.

## Working pattern (proven across 36+ PRs this rollout)

Ship-as-you-go cadence (per CLAUDE.md / saved memory):

1. **Branch**: `ai/<slug>-<short>` from master.
2. **Plan & implement**: Either dispatch a `general-purpose` subagent with the plan step verbatim (used for P0.x–P5.x), or do it inline if small.
3. **Local verify**: Always run with a backgrounded pkill watchdog — `timeout` doesn't work on PTY/FFI hangs (`feedback_phpunit_kill_pattern` memory):
   ```bash
   ( sleep 90 && pkill -9 -f 'vendor/bin/phpunit'; pkill -9 -f 'pty-shim'; pkill -9 -f 'runchild' ) > /tmp/wd.log 2>&1 &
   cd /home/sites/sugarcraft/candy-pty && vendor/bin/phpunit > /tmp/out.out 2>&1
   ```
4. **Commit**: Author `Joe Huss <detain@interserver.net>`. Caliber pre-commit hook is active — no need to run `caliber refresh` manually.
5. **Push & PR**:
   ```bash
   git push -u origin <branch>
   unset GITHUB_TOKEN && gh pr create --title '...' --body '...'
   ```
6. **Merge & continue**:
   ```bash
   unset GITHUB_TOKEN && gh pr merge <N> --merge --delete-branch
   git checkout master && git pull --ff-only
   ```
7. **Always `unset GITHUB_TOKEN` before `gh`** — the env-set token is invalid; the local keychain auth works.

## Test invocation cheatsheet

```bash
# Per-lib (most common):
cd /home/sites/sugarcraft/candy-pty && composer install && vendor/bin/phpunit

# Without watchdog (only for non-PTY work):
cd <lib> && vendor/bin/phpunit

# Path-repo closure check (CI gate added in P4.5):
php /home/sites/sugarcraft/tools/check-path-repos.php
```

Suites + counts (current):
| Lib | Tests | Notes |
|---|---|---|
| candy-pty | 261 | 2 deprecations, 2 skipped — both expected |
| candy-core | 545 | 24 skipped (FFI / Windows guards) |
| candy-wish | 70 | All real PTY end-to-end |
| candy-shell | 180 | Spin / RealProcess adapter |

## CI workflows

| Workflow | Trigger | What |
|---|---|---|
| `ci.yml` | push to master, all PRs | Affected-libs matrix per `scripts/affected-libs.php`. Linux × {8.3, 8.4}, Windows pool, macOS pool, coverage, PHPStan, **`path-repo-check`** job (P4.5). |
| `pty-matrix.yml` | push touching `candy-pty/**` | Dedicated macOS arm64 + Linux × FFI/stty matrix for the PTY integration suite (P5.6). |
| `macos-debug.yml` (debug branch only) | push to `debug/**` | 30-job split of candy-pty suite for narrowing down macOS hangs. Lives on `debug/macos-candy-pty` branch. |

## Known limitations (carry-overs into P6)

### macOS arm64 PTY resize works via stty fallback (PR #475)

`TIOCSWINSZ` ioctl returns `-1` on macos-15 arm64 via PHP FFI — root cause is the variadic-vs-fixed-arg ABI mismatch (real libc `ioctl` is variadic; arm64 ABI puts varargs on the stack while fixed args sit in `x0`–`x7`; our fixed-arg cdef pushes the winsize pointer to the wrong register).

Failed mitigations (kept as historical record):
- POSIX 2024 `tcsetwinsize` / `tcgetwinsize` (PR #474) — symbols missing in macOS 15 libSystem despite the spec; FFI eagerly resolves symbols and the whole cdef load fails on Darwin.
- Variadic `ioctl(int, unsigned long, ...)` cdef — PHP FFI rejects `FFI\CData` arrays as variadic params with "FFI passing array is not implemented".
- Darwin slave anchor (PRs #468 + #472) — kept in place (Darwin-only, harmless on Linux) but insufficient alone.

**Current solution (PR #475 merged):** `SizeIoctl::setSizeViaLibc()` tries `ioctl(TIOCSWINSZ)` first; on Darwin if `rc != 0` it falls back to `stty -f /dev/fd/<fd> rows N cols N`. Same shape as `SttyTermios`'s libc-then-stty fallback.

### Path-repo lock-version mismatch on PR branches

`candy-core/composer.lock` (and any other consumer's lock) pins `sugarcraft/candy-pty` to `"version": "dev-master"`. When CI checks out a PR branch where candy-pty's HEAD is at `ai/<branch>`, composer reads the symlink's branch as `dev-ai/<branch>` and rejects the lock as incompatible.

**Pattern**: candy-core jobs fail on PR branches that ALSO touch candy-pty; they pass on master after merge. This is expected. **Don't try to "fix" the lock** by regenerating it on a PR branch — that just shifts the failure to the post-merge master push. The 7-cell failure pattern (Test/PHPStan/macOS/Windows × candy-core) on a PR is normal for any candy-pty change.

The macOS candy-wish cell **is** the canary that proves the underlying fix works on Darwin.

### PTY integration matrix flakes on macOS (`pty-matrix.yml`)

`OrphanedChildReapTest` ("fixture did not print a valid orphan PID") and Python REPL tests sometimes fail on the macos-15 runner due to environment issues (Python may not be in the image; orphan-detection is racy). These are pre-existing env issues, **not regressions**. Acceptable to defer with `markTestSkipped` on Darwin if they keep blocking — or fix the image setup.

## Recent PRs (this rollout, most-recent first)

```
#475  ai/candy-pty-stty-fallback     candy-pty stty -f Darwin resize fallback (final macOS fix)
#474  ai/macos-tcsetwinsize          (failed) — tcsetwinsize attempt
#473  ai/hold-macos-pty-final        Hold candy-pty out of MACOS_LIBS
#472  ai/macos-slave-anchor-hold     Hold slave anchor for master lifetime
#471  ai/macos-arm64-image           macos-26-intel → macos-15
#470  ai/hold-candy-pty-from-macos   First MACOS_LIBS hold
#469  ai/macos-resize-after-spawn    Resize after proc_open opens slave
#468  ai/ci-cache-bust-darwin-anchor Cache busts + Darwin slave anchor
#467  ai/monorepo-test-sweep         candy-core lock fix + initial macOS attempt
#466  ai/candy-pty-docs-parity       P5.7 — README parity + CONCEPTS.md
#465  ai/candy-pty-ci-matrix         P5.6 — pty-matrix.yml workflow
#464  ai/candy-pty-test-eof-sigint-reap  P5.5 — EOF/SIGINT/orphan-reap
#463  ai/candy-pty-vim-resize-buffer-tests  P5.3+P5.4 — vim/resize/1MB
#462  ai/candy-pty-shell-repl-tests  P5.1+P5.2 — bash/dash/zsh/fish/python
#461  ai/candy-pty-spawn-bash-example  P4.6 — DI quickstart example
#460  ai/candy-pty-deprecation-markers  P4.5 — path-repo audit + check-path-repos.php
#459  ai/candy-core-program-termios-di  P4.4 — Program optional Termios DI
#458  ai/candy-wish-spawn-pty-system  P4.3 — Spawn middleware DI integration test
#457  ai/candy-wish-pty-system-di    P4.2 — InProcessTransport optional PtySystem DI
#456  ai/candy-pty-system-factory    P4.1 — PtySystemFactory + UnsupportedPlatformException
#455  ai/candy-shell-uses-posix-process  P3.3 — candy-shell RealProcess adapter
#454  ai/candy-pty-posix-process     P3.2 — PosixProcess (non-PTY spawn)
#453  ai/candy-pty-child-poll-trait  P3.1 — ChildPollTrait extraction
#452  ai/candy-pty-p25-redo          P2.5 redo — pump migration freeze fix
```

(Earlier P0–P2.4 PRs: #440 → #451 — see `git log --oneline` on master.)

## Architecture (current state — what to import from)

```
candy-pty/src/
├── Contract/                    # interface layer (P0)
│   ├── Child.php                #   Child::{pid, exited, wait, exitCode, kill}
│   ├── MasterPty.php            #   read/write/resize/size/stream/close/isClosed
│   ├── Process.php              #   non-PTY child handle
│   ├── PtyPair.php              #   master() + slave()
│   ├── PtySystem.php            #   open(cols, rows): PtyPair
│   ├── Pump.php                 #   run(master, stdin, stdout, child, opts): int
│   ├── SlavePty.php             #   path() + spawn(cmd, env, ...)
│   └── Termios.php              #   current/makeRaw/apply/restore/isAtty
├── Posix/                       # implementation layer (P0–P3)
│   ├── ChildPollTrait.php       #   proc_get_status polling + reap (P3.1)
│   ├── PosixChild.php           #   PTY child (extends legacy Child + trait)
│   ├── PosixMasterPty.php       #   FFI-driven master
│   ├── PosixProcess.php         #   non-PTY spawn for candy-shell (P3.2/3.3)
│   ├── PosixPtyPair.php
│   ├── PosixPtySystem.php       #   posix_openpt + grantpt + Darwin slave anchor
│   ├── PosixPump.php            #   byte pump (P2.x) — EOF grace + flush + callbacks
│   ├── PosixSlavePty.php        #   spawn() w/ post-spawn resize for macOS
│   ├── PosixTermios.php         #   FFI termios
│   └── SttyTermios.php          #   stty(1) fallback for termios
├── Exception/
│   └── UnsupportedPlatformException.php  # P4.1, extends PtyException
├── PtySystemFactory.php         # DI entry point (P4.1)
├── PumpOptions.php              # Pump tunables (chunkBytes/timeouts/callbacks)
├── SizeIoctl.php                # TIOCS/GWINSZ + Darwin stty fallback (PR #475)
├── SignalForwarder.php          # SIGWINCH → resize (now on MasterPty contract)
├── TermiosFactory.php           # Picks PosixTermios or SttyTermios
├── Libc.php                     # FFI cdef + lazy handle
├── Pty.php                      # @deprecated facade, implements MasterPty
├── Child.php                    # @deprecated, uses ChildPollTrait
└── Spawn.php                    # @deprecated, delegates to PosixSlavePty
```

**Canonical usage (the v1 API)** — example in `candy-pty/examples/spawn-bash.php`:

```php
use SugarCraft\Pty\PtySystemFactory;

$pair = PtySystemFactory::default()->open($cols, $rows);
$master = $pair->master();
$child = $pair->slave()->spawn($cmd, $env, $cols, $rows, controllingTerminal: true);
stream_set_blocking($master->stream(), false);
// ...drain master->read()...
$exit = $child->wait();
$master->close();
```

**For candy-vcr (P6.5)** the pump invocation:

```php
use SugarCraft\Pty\Posix\PosixPump;
use SugarCraft\Pty\PumpOptions;

$opts = new PumpOptions(
    keepalive: $keepaliveCallback,
    onSigwinch: $sigwinchCallback,
    // P6.1 will add: recorder: $recorder,
);
$exitCode = (new PosixPump())->run($master, $stdin, $stdout, $child, $opts);
```

## Debug artifacts (preserved on disk)

- **`debug/macos-candy-pty` branch** — `.github/workflows/macos-debug.yml` splits candy-pty's suite into ~30 small per-file jobs, each with a 4-5 min timeout. Triggered on `workflow_dispatch` + push to `debug/**`. Run on this branch if a new macOS hang surfaces.
- **`SUGARCRAFT_PTY_DEBUG=1` env-gate** — `PosixPtySystem::open()` and `PosixSlavePty::spawn()` catch blocks write to STDERR when set. Already wired in `macos-debug.yml`; not in `ci.yml` (intentional — keeps noise out of normal runs).
- **`tools/check-path-repos.php`** — verifies every `sugarcraft/* @dev` dep has a path-repo entry. Run before committing if adding new transitive deps.

## Pre-commit hook

Caliber is active (`.git/hooks/pre-commit` greps for "caliber"). Commit normally — `caliber refresh` runs on every commit and pulls fresh CALIBER_LEARNINGS / agent configs.

## Key saved memories (auto-loaded each session)

These are real things this rollout learned and embedded in your auto-memory:

- **PR size — bundle related items**: don't do one PR per audit item; bundle 2–4.
- **Ship-as-you-go**: each cohesive change-set = one PR (commit→push→PR→merge→pull) before starting the next.
- **PHPUnit kill pattern**: `timeout` doesn't work on PTY/FFI hangs; spawn a backgrounded pkill watchdog.
- **candy-wish PTY dual-mode**: in-process SSH is default; HostSshd stays as opt-in.
- **Audit — skip credit + upgrade-guide items**.
- **Monorepo distribution**: all commits land in `detain/sugarcraft`; per-lib repos auto-distributed.

## Plan for a fresh session

1. **CI on master is confirmed green** as of run `25982131082` (2026-05-17, post-merge of PR #475). Spot-check with:
   ```bash
   unset GITHUB_TOKEN && gh run list --workflow=ci.yml --branch=master --limit=1
   ```
   If a fresher run is failing, diagnose first before proceeding to P6.

2. **Start P6.1** — `PosixPump` Recorder tap. The plan step (lines 581–586 of `sugarcraft-is-a-mono-logical-twilight.md`):
   - Branch: `ai/candy-pty-pump-recorder-tap`
   - Modify `candy-pty/src/PumpOptions.php` — add `?Recorder $recorder = null`
   - Modify `candy-pty/src/Posix/PosixPump.php` — tee stdin into `$recorder->recordInputBytes()`, master-read into `$recorder->recordOutput()`, SIGWINCH callback also → `$recorder->recordResize(int, int)`
   - Cross-check `candy-vcr/src/Recorder.php` signatures
   - Add `candy-pty/tests/Posix/PumpRecorderTapTest.php` — record a bash session, play back via `Player`, assert reproduction
   - Add candy-vcr to candy-pty's `require-dev` path-repo if not there
   - Review focus: zero overhead when `$recorder` is null; tee writes on the same loop iteration as the read (no buffering drift)

3. **Continue with P6.5.x** — the Shirley CLI. Plan steps 616–668. Each is ~1 PR, ~1 subagent invocation. Total: 6 PRs for P6.5.

4. **P6.2 / P6.3 / P6.4 are optional** — skip unless time permits or a specific need surfaces.

## CONTACT POINTS / ESCAPE HATCHES

- **macOS PTY resize regresses**: rerun `debug/macos-candy-pty` workflow with `SUGARCRAFT_PTY_DEBUG=1` to see catch traces.
- **candy-core lock CI failures on a PR**: expected. Verify the failures are ONLY `Test/PHPStan/macOS/Windows · candy-core` — don't try to fix them. Once merged to master, they resolve.
- **A new lib added needs MACOS_LIBS / WINDOWS_LIBS**: edit `scripts/affected-libs.php` manually — these pools are hand-maintained, not auto-discovered.
- **Path-repo closure broken**: `tools/check-path-repos.php` will flag it in CI; fix by copying the path-repo entry from `sugar-charts/composer.json`.
- **Pre-commit hook hangs / breaks**: Run `caliber refresh` manually to diagnose, or `git commit --no-verify` as last resort (don't commit this way long-term).
