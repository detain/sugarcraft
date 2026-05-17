# Session Handoff — sugarcraft-pty rollout

Continuation guide for the candy-pty plan. **P0–P5 complete + P6.1 complete.** P6.2–P6.5 still open.

## Bootstrap prompt (paste this into the new Claude Code session)

```
Read plans/session_handoff.md and plans/sugarcraft-pty-status.md.
Confirm master CI is green with `unset GITHUB_TOKEN && gh run list --workflow=ci.yml --branch=master --limit=1`.
Then continue the plan: P6.2 next (or skip-and-go-to-P6.5.1 since
P6.2 is profile-gated and may not be needed). Ship-as-you-go cadence
(branch → commit → push → PR → merge → master → next). Author all
commits as Joe Huss <detain@interserver.net>. Use the watchdog
pkill pattern for any PHPUnit run that touches PTY/FFI. Always
`unset GITHUB_TOKEN` before `gh` invocations. Continue all the way
through P6.5.6 unless I tell you to stop.
```

## Current state (as of session end)

| Phase | Status |
|---|---|
| P0–P5 | ✅ Complete (PRs #440 → #475) |
| P5.7 (final docs) | ✅ Merged (PR #466) |
| Master CI | ✅ Green (run `25982131082`, post-PR-#475) |
| **P6.1 Recorder tap** | ✅ **Just shipped (PR #477)** |
| P6.2 PtyPool | ⏳ Open — profile-gated, may be skipped |
| P6.3 MultiPump | ⏳ Open — optional |
| P6.4 Expect API | ⏳ Open — optional |
| P6.5.1 RecordCommand skeleton | ⏳ Open — **next critical step** |
| P6.5.2 --shell / --env flags | ⏳ Open |
| P6.5.3 --idle-trim dual timestamps | ⏳ Open |
| P6.5.4 termios safety net | ⏳ Open |
| P6.5.5 bash + vim integration | ⏳ Open |
| P6.5.6 perf baseline (≤2% overhead) | ⏳ Open |

## Test counts (post-P6.1)

| Lib | Count | Notes |
|---|---|---|
| candy-pty | 264 | +3 from P6.1 (`PumpRecorderTapTest`) |
| candy-core | 545 | unchanged |
| candy-wish | 70 | unchanged |
| candy-shell | 180 | unchanged |
| candy-vcr | 324 | unchanged |

## Recent PRs

```
#477 ai/candy-pty-pump-recorder-tap  P6.1 — PosixPump Recorder tap (Recorder tee)
#476 ai/p6-handoff-doc               P6 session handoff doc
#475 ai/candy-pty-stty-fallback      stty -f Darwin resize fallback (macOS green)
#474 ai/macos-tcsetwinsize           Failed tcsetwinsize attempt (kept for history)
#473 ai/hold-macos-pty-final         Hold candy-pty out of MACOS_LIBS
...
```

Full ledger lives in `plans/sugarcraft-pty-status.md`.

## What P6.1 delivered (read before P6.5.1)

The Shirley-style `candy-vcr record` CLI hinges on this. P6.1 wired:

- `PumpOptions::$recorder` — optional `?\SugarCraft\Core\Recorder` field, with `withRecorder()` mutator
- `PosixPump::pumpStdinToMaster` — calls `recorder->recordInputBytes($bytes)` AFTER `master->write`
- `PosixPump::pumpMasterToStdout` — calls `recorder->recordOutput($bytes)` BEFORE `fwrite` to stdout
- Zero overhead when recorder is null (single null-check per chunk)
- `recordResize` deliberately NOT wired from the idle-tick — TODO points P6.5.1 at `SignalForwarder::attachSigwinch` as the proper hook
- candy-vcr added to candy-pty's `require-dev` + path-repo
- `PumpRecorderTapTest` walks a real bash session through the tap, validates via `JsonlFormat`

**Key design decision worth knowing**: the test in P6.1 uses `JsonlFormat` direct cassette walk (not `Player::play()`) because `Player` drives a candy-core `Program`, not a raw byte stream. P6.5's integration tests (P6.5.5) can use `Player::play()` because the CLI naturally pairs with Program-style replay.

## Plan steps for the next session

### Decision point 1: Skip P6.2/P6.3/P6.4?

The plan calls these "optional":
- **P6.2 PtyPool** — profile-gated. Plan says "only proceed if FFI open cost > 5ms in a tight loop". Likely SKIP — measure first, but on Linux FFI open is fast.
- **P6.3 MultiPump** — for split-pane/tmux supervision. Only if a consumer needs it; nothing in candy-vcr does.
- **P6.4 Expect API** — `Expect::on($pty)->send("login: ")->expect("password:", ...)` fluent API. Useful eventually but not on critical path.

**Recommendation**: go straight to P6.5.1. Revisit P6.2–P6.4 only if explicitly requested.

### P6.5.1 — `RecordCommand` skeleton (CRITICAL NEXT STEP)

Plan section: `plans/sugarcraft-is-a-mono-logical-twilight.md` lines 616–625.

- Branch: `ai/candy-vcr-record-command-skeleton`
- Create `candy-vcr/src/Cli/RecordCommand.php` — Symfony Console command
  - Argv: `candy-vcr record [--output session.cas] -- <cmd> [args...]`
  - Wires: `PtySystemFactory::default()->open()` → spawn child with `controllingTerminal:true` → `TermiosFactory::open(STDIN)->makeRaw()->apply()` → instantiate `Recorder` → run `PosixPump` with `recorder` option set → restore termios on exit
- Modify `candy-vcr/src/Cli/Application.php` — register the new command
- Modify `candy-vcr/composer.json` — add `sugarcraft/candy-pty: @dev` + path-repo (currently only transitive via candy-core)
- Create `candy-vcr/tests/Cli/RecordCommandTest.php` — invoke `candy-vcr record -- /bin/echo hello`, assert cassette has expected `output` event + `quit` event with exit 0
- Update `candy-vcr/README.md` — "Recording commands" section
- Review focus: Ctrl+C reaches the recorded program (not the recorder); termios restored on every exit path including `Throwable`

### P6.5.2 — `--shell` and `--env` flags

Plan lines 627–633. After P6.5.1 merges.

### P6.5.3 — `--idle-trim` dual timestamps

Plan lines 635–642.

### P6.5.4 — Host termios safety net

Plan lines 644–651. `register_shutdown_function` + `pcntl_signal(SIGTERM/SIGHUP, ...)` handlers that always restore host termios. Hold saved snapshot in a static.

### P6.5.5 — Integration tests

Plan lines 653–660. `ShirleyBashTest` + `ShirleyVimTest` (through candy-vt screen assertion).

### P6.5.6 — Performance baseline

Plan lines 662–668. Assert overhead ≤2% wallclock on `time bash -c 'seq 100000'`.

## Working pattern (mandatory)

1. **Branch**: `ai/<slug>-<short>` from master.
2. **Subagent**: dispatch `general-purpose` agent with the plan step verbatim + the API context from `plans/sugarcraft-pty-status.md` Architecture section.
3. **Verify with watchdog** (PHPUnit hangs on PTY tests — `timeout` doesn't work):
   ```bash
   ( sleep 90 && pkill -9 -f 'vendor/bin/phpunit'; pkill -9 -f 'pty-shim' ) > /tmp/wd.log 2>&1 &
   cd /home/sites/sugarcraft/candy-pty && vendor/bin/phpunit > /tmp/out 2>&1
   ```
4. **Path-repo check**: `php /home/sites/sugarcraft/tools/check-path-repos.php`
5. **Commit**: Caliber pre-commit hook auto-runs. Author = `Joe Huss <detain@interserver.net>`.
6. **Push + PR + Merge**:
   ```bash
   git push -u origin <branch>
   unset GITHUB_TOKEN && gh pr create --title '...' --body '...'
   unset GITHUB_TOKEN && gh pr merge <N> --merge --delete-branch
   git checkout master && git pull --ff-only
   ```
7. **`unset GITHUB_TOKEN` before EVERY `gh` invocation** — the env-set token is invalid; the keychain auth works.

## Critical reference files

| Read this | When |
|---|---|
| `plans/sugarcraft-pty-status.md` | Always — has architecture map + API patterns + escape hatches |
| `plans/sugarcraft-is-a-mono-logical-twilight.md` | For P6 step specs (lines 577–680) |
| `candy-pty/src/Posix/PosixPump.php` | Before P6.5.1 — see how the Recorder tap is wired |
| `candy-vcr/src/Recorder.php` | Before P6.5.1 — understand the existing API |
| `candy-vcr/src/Player.php` | Before P6.5.5 — replay semantics |
| `candy-vcr/src/Cli/Application.php` | Before P6.5.1 — Symfony Console registration pattern |
| `candy-pty/examples/spawn-bash.php` | Canonical v1 API usage |

## Known caveats (do NOT relitigate)

1. **macOS arm64 TIOCSWINSZ broken via FFI** — fixed in PR #475 via `stty -f /dev/fd/N rows cols` fallback in `SizeIoctl::setSizeViaLibc`. Don't undo this.
2. **candy-core lock fails on PR branches that touch candy-pty** — expected, self-fixes on merge. 7-cell failure pattern (Test/PHPStan/macOS/Windows × candy-core) is normal during PR review. Don't try to "fix" the lock on the PR branch.
3. **`tcsetwinsize`/`tcgetwinsize` not in macOS 15 libSystem** — POSIX 2024 spec only, not implemented. Don't re-add to cdef.
4. **PHPUnit hangs on PTY tests, `timeout` doesn't work** — always use the backgrounded pkill watchdog pattern.
5. **Pre-existing `candy-pty/CALIBER_LEARNINGS.md` and `CALIBER_LEARNINGS.md` modifications** — harmless, auto-generated by Caliber. Don't commit them as part of feature PRs; let Caliber's own commits pick them up.

## P6.5 acceptance criteria (per the plan, line 671)

When P6.5 is done:
- `candy-vcr record -- bash -c 'echo hello; sleep 0.2; echo world'` → cassette → replay reproduces the session
- `candy-vcr record -- vim /tmp/scratch.txt` round-trips through `candy-vt::Terminal` screen-assertion
- Recording overhead ≤2% wallclock on `time bash -c 'seq 100000'`
- README documented with asciinema comparison

## Stop conditions

Per user's standing instructions: continue autonomously through ALL of P6.5 unless:
- The user types `pause` or `stop`
- A PR fails CI in a way that needs human input
- A new permission decision is needed (e.g. adding a new top-level lib)
