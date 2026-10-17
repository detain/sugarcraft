# Findings Resume Plan — Complete All Audit Findings

## STATUS: COMPLETE (2026-07-04)

All phases 0–6 are closed. Phase 0–5 items landed as the commit series
through `b9c63b74`; the Phase 6 leftovers were re-verified against
current source on 2026-07-04 and closed by: `a160cefd` (test
skips/flake), `5f33c4b7` (sugar-mouse contains, candy-wish StreamHelper
+ pid()), `1920988f`/`09546125` (candy-git-server auth verification,
maxPackBytes, streaming, stray bare-repo artifacts), `7834370f`
(candy-scriptui), `c21aa335` (sugar-table), `5649732f` (sugar-readline +
candy-input paste-EOF hang), `1050caf5` (candy-sql), `41b47d9a`
(candy-pty). candy-sprinkles plan items were all verified
misreferenced/N-A (see plan_candy-sprinkles.md annotations).
Deliberate deferrals are recorded per-lib in CALIBER_LEARNINGS.md
"Future work (deferred pre-1.0)" sections: candy-git-server 6.1/6.2/7.5-7.9,
candy-pty 8.1/8.3/8.4, candy-sql 3.1/3.3/3.4/5.1 (+1.8/0.25
react-pg WONTFIX, react/mysql stable pin WONTFIX — no 0.7 tag),
sugar-readline 3.2 (vi text objects, needs sugar-forms VimAction).
## ⚠️ CONCURRENCY RULES — READ BEFORE SPAWNING ANY AGENT

These rules are **mandatory for every agent** in every batch. Violation = stop immediately and report.

### Agent Safety Contract (inject in EVERY dispatch prompt)

Every subagent dispatch MUST include these instructions verbatim in the dispatch prompt:

```
SAFETY CONTRACT — VIOLATION = STOP AND REPORT
1. BRANCHES: You may ONLY commit/push to `master`. Do NOT create branches,
   do NOT push to non-master branches, do NOT open PRs. If you need to
   branch, STOP and explain why.
2. STAGING: Do NOT run `git add .` or `git commit` or `git push` until you
   have verified your changes compile and tests pass for that lib. Stage
   and push ONLY when ready — not before.
3. CHAINED COMMANDS: When you are ready to commit, do NOT run separate
   git commands with delays between them. Use a SINGLE chained command
   that stages, commits, and pushes atomically with `&&`:
   `git add -A && git commit -m "message" && git push origin master`.
   No intermediate `git status` or other commands between staging and push.
4. WORKING TREE: Do NOT leave the working tree dirty. If your changes need
   more work before committing, complete the work first, THEN stage+commit
   in one chain. Never `git add .` on partial work.
5. NO OTHER BRANCHES: If you see any branch in `git branch -a` output that
   is not `master` or `remotes/origin/master`, do NOT interact with it.
   Report its existence and continue with master only.
```

### Concurrency Model

- **MAX 3 agents simultaneously.** Always. No exceptions.
- Group items into sets of 3 when possible.
- When a group completes, wait for confirmation, then spawn the next group of 3.
- Do not spawn a 4th agent while 3 are still running.

### Phase Execution Protocol

For each phase:
1. Read the phase's item list
2. Group items into sets of 3 (last group may have 1-2)
3. Spawn exactly 3 agents with items from the current group
4. Wait for all 3 to report DONE
5. Run branch verification
6. Run test verification for all 3 modified libs
7. Mark the plan file items ✅
8. Proceed to next group
9. Repeat until phase complete

### Definitions

- **🔴 CRITICAL**: Security vulnerability or crash bug — fix immediately
- **🟡 HIGH**: Functional bug or missing feature — fix in current phase
- **🟢 MEDIUM**: Code quality, perf, or docs — fix when reached
- **⚪ LOW**: Nice-to-have — fix if straightforward, else defer

---

## Phase 0: Immediate Critical Security Fixes

### Group 0-A (3 agents — CRITICAL)

#### 0.1 🔴 candy-files: Predictable trash path (Finding #6)

**Plan:** `findings/plan_candy-files.md` — Item 1.1
**Issue:** `Manager.php:453` uses `microtime(true) . uniqid('', true)` — predictable, exploitable
**Fix:** Replace with `bin2hex(random_bytes(16))`
**Files:** `candy-files/src/Manager.php:448-456`
**Agent:** Spawn 1 coder agent — inject full Safety Contract above

#### 0.2 🔴 candy-files: Path traversal in performRename (Finding #7)

**Plan:** `findings/plan_candy-files.md` — Item 1.2
**Issue:** Only string-based check, doesn't validate resolved path with `Phar::canonicalize()`
**Fix:** Use `Phar::canonicalize()` to validate full resolved path stays within source directory
**Files:** `candy-files/src/Manager.php:727-732`
**Agent:** Same coder agent as 0.1 (grouped — same lib)

#### 0.3 🔴 candy-git-server: Git ref names shell injection (Item 1.1)

**Plan:** `findings/plan_candy-git-server.md` — Item 1.1
**Issue:** `GitDaemon.php:472-473` — shell injection in Git ref names (escapeshellarg but no validation)
**Fix:** Validate Git ref names against safe pattern before passing to shell commands
**Files:** `candy-git-server/src/GitDaemon.php:472-473`
**Agent:** Spawn 1 coder agent — inject full Safety Contract above

---

#### 0.4 🔴 candy-git-server: SSHServer path traversal (Item 1.2)

**Plan:** `findings/plan_candy-git-server.md` — Item 1.2
**Issue:** `SSHServer.php:104` — path traversal via `..` after basename()
**Fix:** Validate resolved path stays within allowed directory
**Files:** `candy-git-server/src/SSHServer.php:104`
**Agent:** Same coder agent as 0.3 (grouped — same lib)

#### 0.5 🔴 candy-git-server: LFSHandler hardcoded bearer token (Item 1.3)

**Plan:** `findings/plan_candy-git-server.md` — Item 1.3
**Issue:** `LFSHandler.php:200,213` — hardcoded `'Bearer lfs-token'`
**Fix:** Remove hardcoded token; use proper credential handling
**Files:** `candy-git-server/src/LFSHandler.php:200,213`
**Agent:** Same coder agent as 0.3 (grouped — same lib)

#### 0.6 🔴 candy-git-server: Empty password auth bypass (Item 1.4)

**Plan:** `findings/plan_candy-git-server.md` — Item 1.4
**Issue:** `Server.php:566` — empty password auth bypass (`null` password allows any password)
**Fix:** Reject null/empty passwords explicitly
**Files:** `candy-git-server/src/Server.php:566`
**Agent:** Same coder agent as 0.3 (grouped — same lib)

---

#### 0.7 🔴 candy-git-server: git init wrong directory (Item 2.1)

**Plan:** `findings/plan_candy-git-server.md` — Item 2.1
**Issue:** `Repo.php:145` — git init runs in wrong directory (no `-C $path`)
**Fix:** Use `git -C $path init` to ensure init runs in correct directory
**Files:** `candy-git-server/src/Repo.php:145`
**Agent:** Same coder agent as 0.3 (grouped — same lib)

#### 0.8 🔴 candy-git-server: GitDaemon array modification during iteration (Item 2.2)

**Plan:** `findings/plan_candy-git-server.md` — Item 2.2
**Issue:** `GitDaemon.php:213-223` — array modification during iteration
**Fix:** Collect keys to remove first, then remove after iteration
**Files:** `candy-git-server/src/GitDaemon.php:213-223`
**Agent:** Same coder agent as 0.3 (grouped — same lib)

#### 0.9 🔴 candy-git-server: GitDaemon temp file leak (Item 2.3)

**Plan:** `findings/plan_candy-git-server.md` — Item 2.3
**Issue:** `GitDaemon.php:309-320` — temp file leak on exception
**Fix:** Use try/finally to ensure `$stdinFile` is deleted even if exception thrown
**Files:** `candy-git-server/src/GitDaemon.php:309-320`
**Agent:** Same coder agent as 0.3 (grouped — same lib)

---

### Group 0-B (3 agents)

#### 0.10 🔴 candy-pty: FD reuse race (Item 1.1)

**Plan:** `findings/plan_candy-pty.md` — Item 1.1
**Issue:** `PosixMasterPty::close()` closes FD directly without `libc::dup()` first — race condition
**Fix:** Add `libc::dup($this->fd)` before closing to prevent FD reuse
**Files:** `candy-pty/src/Posix/PosixMasterPty.php:244-251`
**Agent:** Spawn 1 coder agent — inject full Safety Contract above

#### 0.11 🔴 candy-pty: Encoding artifact (Item 2.1)

**Plan:** `findings/plan_candy-pty.md` — Item 2.1
**Issue:** `Expect.php:389` — `Mirrors charmbracelet/m泡泡/expect.Exp.` — Chinese chars in comment
**Fix:** Remove the artifact, correct the comment
**Files:** `candy-pty/src/Expect.php:389`
**Agent:** Same coder agent as 0.10 (grouped — same lib)

#### 0.12 🔴 candy-vcr: Undefined $cassette variable (Item 1.1)

**Plan:** `findings/plan_candy-vcr.md` — Item 1.1
**Issue:** `TapeToGif.php:70-71` uses `$cassette->header->fontSize` BEFORE line 81 where `$cassette` is defined
**Fix:** Move the `$cassette` definition before the usage, or restructure the code
**Files:** `candy-vcr/src/TapeToGif.php:70-81`
**Agent:** Spawn 1 coder agent — inject full Safety Contract above

---

#### 0.13 🔴 candy-vcr: ImagickRasterizer shared tileCache (Item 1.2)

**Plan:** `findings/plan_candy-vcr.md` — Item 1.2
**Issue:** `ImagickRasterizer.php:62,77` — `$clone->tileCache = $this->tileCache` is shallow copy sharing Imagick objects
**Fix:** Clone the tileCache array: `$clone->tileCache = new WeakMap(); foreach ($this->tileCache as $key => $value) { $clone->tileCache[$key] = $value; }` or use deep copy
**Files:** `candy-vcr/src/Raster/ImagickRasterizer.php:58-66`
**Agent:** Same coder agent as 0.12 (grouped — same lib)

#### 0.14 🔴 candy-wish: AsyncMiddleware blocks event loop (Item 1.1)

**Plan:** `findings/plan_candy-wish.md` — Item 1.1
**Issue:** `AsyncMiddleware.php:49` — `PromiseAwait::settle($result)` blocks the event loop
**Fix:** Use non-blocking async patterns; do not await synchronously
**Files:** `candy-wish/src/AsyncMiddleware.php:49`
**Agent:** Spawn 1 coder agent — inject full Safety Contract above

#### 0.15 🔴 candy-wish: PromiseAwait loses original rejection (Item 1.2)

**Plan:** `findings/plan_candy-wish.md` — Item 1.2
**Issue:** `PromiseAwait.php:60-67` — timeout overwrites `$ex` even if original already errored
**Fix:** Preserve original rejection; only set `$ex` if not already set
**Files:** `candy-wish/src/PromiseAwait.php:60-67`
**Agent:** Same coder agent as 0.14 (grouped — same lib)

---

### Group 0-C (3 agents)

#### 0.16 🔴 candy-scriptui: Shell injection in env-var fallback (Item 1.1)

**Plan:** `findings/plan_candy-scriptui.md` — Item 1.1
**Issue:** `Application.php:137-141` — `escapeshellarg → stripslashes → trim` chain is vulnerable
**Fix:** Use argv array approach with `$input->setOption()` to avoid shell interpolation
**Files:** `candy-scriptui/src/Application.php:137-150`
**Agent:** Spawn 1 coder agent — inject full Safety Contract above

#### 0.17 🔴 sugar-video: resume() uses SIGCONT (Item 1.2)

**Plan:** `findings/plan_sugar-video.md` — Item 1.2
**Issue:** `AudioPlayer.php:145-152` — SIGCONT has same PTY issues as SIGSTOP
**Fix:** Use `proc_terminate($this->processHandle, SIGTERM)` and restart, or find proper resume mechanism
**Files:** `sugar-video/src/AudioPlayer.php:145-152`
**Agent:** Spawn 1 coder agent — inject full Safety Contract above

#### 0.18 🔴 candy-flappy: Score fires at wrong x position (Item 1.1)

**Plan:** `findings/plan_candy-flappy.md` — Item 1.1
**Issue:** `Game.php:273` fires score at x=7, not x=8
**Fix:** Change from `> 7` to `> 8` (or whatever the correct threshold is per game mechanics)
**Files:** `candy-flappy/src/Game.php:273`
**Agent:** Spawn 1 coder agent — inject full Safety Contract above

---

#### 0.19 🔴 sugar-forms: Signal handlers use undefined $status (Item 1.1)

**Plan:** `findings/plan_sugar-forms-huh.md` — Item 1.1 (issue found during audit)
**Issue:** Signal handlers at lines 141, 154 reference `$status` but should reference `$waitStatus` (line 170)
**Fix:** Replace `$status` with `$waitStatus` in both signal handler closures
**Files:** `sugar-forms/src/Spinner.php:141, 154`
**Agent:** Spawn 1 coder agent — inject full Safety Contract above

#### 0.20 🔴 sugar-toast: renderAlertToBuffer uses unclamped maxWidth (Item 1.2)

**Plan:** `findings/plan_sugar-toast.md` — Item 1.2
**Issue:** `Toast.php:531` uses `$width = $this->maxWidth` (unclamped), causing negative xOffset when maxWidth > viewportWidth
**Fix:** Pass clamped `$contentWidth` to `renderAlertToBuffer()` or size buffer based on clamped width
**Files:** `sugar-toast/src/Toast.php:430, 531`
**Agent:** Spawn 1 coder agent — inject full Safety Contract above

#### 0.21 🔴 candy-metrics: SessionMetrics hard-coupled to candy-wish (Item 1.1)

**Plan:** `findings/plan_candy-metrics.md` — Item 1.1
**Issue:** `SessionMetrics.php:8-10` imports from `SugarCraft\Wish\*` directly — fatal without candy-wish
**Fix:** Remove hard dependency; use interface or lazy resolution; or add candy-wish to `suggest` → `require`
**Files:** `candy-metrics/src/Middleware/SessionMetrics.php:8-10`
**Agent:** Spawn 1 coder agent — inject full Safety Contract above

---

#### 0.22 🔴 candy-metrics: Backend interface missing flush() (Item 1.2)

**Plan:** `findings/plan_candy-metrics.md` — Item 1.2
**Issue:** `Backend.php` interface has no `flush(): void` method — no way to flush metrics to persistent backends
**Fix:** Add `flush(): void` to `Backend` interface and implement in all concrete backends (especially `PrometheusFileBackend`)
**Files:** `candy-metrics/src/Backend.php`, `candy-metrics/src/Backend/PrometheusFileBackend.php`
**Agent:** Same coder agent as 0.21 (grouped — same lib)

#### 0.23 🔴 candy-metrics: PrometheusFileBackend flush() re-emits all (Item 1.3)

**Plan:** `findings/plan_candy-metrics.md` — Item 1.3
**Issue:** `PrometheusFileBackend.php:132-273` — every flush() re-serializes entire state, no dirty flag
**Fix:** Add `$dirty` boolean tracking per metric; only re-emit changed metrics
**Files:** `candy-metrics/src/Backend/PrometheusFileBackend.php:132-273`
**Agent:** Same coder agent as 0.21 (grouped — same lib)

#### 0.24 🔴 candy-sql: SQLite branch still uses str_replace (Item 1.7)

**Plan:** `findings/plan_candy-sql.md` — Item 1.7
**Issue:** `PreviewQuery.php:63` — SQLite branch uses `str_replace('"', '""', $table)` not `Identifier::quote()`
**Fix:** Change to `Identifier::quote(Flavor::Sqlite, $table)`
**Files:** `candy-sql/src/Db/PreviewQuery.php:63`
**Agent:** Spawn 1 coder agent — inject full Safety Contract above

---

#### 0.25 🔴 candy-sql: voryx/pgasync replacement (Item 1.8) — BLOCKED

**Plan:** `findings/plan_candy-sql.md` — Item 1.8
**Issue:** `react/pg` not on Packagist — cannot replace voryx/pgasync as specified
**Fix:** Mark as WONTFIX for now and document. No code change required.
**Files:** `candy-sql/composer.json:45`, `candy-sql/src/ReactPostgresConnection.php`
**Agent:** BLOCKED — skip, document as WONTFIX pre-1.0
**Note:** This item is blocked (dependency not available). Skip and mark ⏭️ WONTFIX pre-1.0.

#### 0.26 🔴 sugar-mouse: OSC terminator bounds check not fixed (Item 2.1)

**Plan:** `findings/plan_sugar-mouse.md` — Item 2.1
**Issue:** `Scan.php:133` — bounds check `if ($rendered[$j] === "\x1b" && ($rendered[$j + 1] ?? '') === '\\')` needs explicit `$j + 1 < $len` guard
**Fix:** Add explicit bounds check before accessing `$j + 1`
**Files:** `sugar-mouse/src/Scan.php:133`
**Agent:** Spawn 1 coder agent — inject full Safety Contract above

#### 0.27 🔴 sugar-animation: Player::scheduleTick() uses fixed interval (Item 1.2)

**Plan:** `findings/plan_sugar-animation.md` — Item 1.2
**Issue:** `Player.php:119-122` — uses `$this->interval` uniformly instead of `$this->frames[$this->index]->delay / 100.0`
**Fix:** Use per-frame delay from Frame::$delay
**Files:** `sugar-animation/src/Player.php:119-122`
**Agent:** Spawn 1 coder agent — inject full Safety Contract above

#### 0.28 🔴 candy-async: markCancelled() visibility (Item 1.1) — PHP LANGUAGE ERROR

**Plan:** `findings/plan_candy-async.md` — Item 1.1
**Issue:** Plan says make `markCancelled()` "private" — but PHP `private` means same CLASS only. `CancellationSource::cancel()` is a separate class. Cannot implement as specified.
**Fix:** Alternative: make `markCancelled()` `protected` AND move cancellation logic into `CancellationSource`, OR inline the cancellation into `CancellationSource` directly. **Do NOT make it private** (would break `CancellationSource::cancel()`).
**Files:** `candy-async/src/CancellationToken.php:46`, `candy-async/src/CancellationSource.php:57`
**Agent:** Spawn 1 coder agent — inject full Safety Contract above

---

### Group 0-D (2 agents — remaining Phase 0)

#### 0.29 🔴 sugar-log: Phive-style directory detection (Item 1.1)

**Plan:** `findings/plan_sugar-log.md` — Item 1.1
**Issue:** `Manager.php:__construct` checks for `.phive/phars/` but never actually resolves `.phive/`

---
**Fix:** Either implement the resolution or remove the unused code path
**Files:** `sugar-log/src/Manager.php`
**Agent:** Spawn 1 coder agent — inject full Safety Contract above

#### 0.30 🔴 sugar-log: PSR-3 $context array type (Item 1.2)

**Plan:** `findings/plan_sugar-log.md` — Item 1.2
**Issue:** `$context` parameter typed as `array` but downstream formatters expect `string[]`
**Fix:** Add proper type annotation or coerce to `string[]`
**Files:** `sugar-log/src/Manager.php`, `sugar-log/src/Formatter/JsonFormatter.php`
**Agent:** Same coder agent as 0.29 (grouped — same lib)

### Phase 0 Verification

After ALL 0.x items complete (all groups DONE):
```
cd /home/sites/sugarcraft
git status
git log --oneline -10
git branch -a
gh pr list --state open
```
If any branches exist other than master, merge/clean per Branch Cleanup Protocol below.
Run tests for every modified lib:
```bash
cd candy-files && composer install --quiet && vendor/bin/phpunit 2>&1 | tail -5
cd ../candy-git-server && composer install --quiet && vendor/bin/phpunit 2>&1 | tail -5
# ... repeat for each lib modified in Phase 0

```

---

## Phase 1: High Priority Functional Fixes

### Group 1-A (3 agents)

#### 1.1 🟡 candy-files: Concurrency limiting for copyManyAsync (Item 2.1)

**Plan:** `findings/plan_candy-files.md` — Item 2.1
**Fix:** Add `$concurrency` parameter (default 4-8) using `React\Promise\Queue` to `AsyncOps::copyManyAsync()`
**Files:** `candy-files/src/AsyncOps.php:98-122`
**Agent:** Spawn 1 coder agent — inject full Safety Contract above

#### 1.2 🟡 candy-files: Trash cleanup on shutdown (Item 2.2)

**Plan:** `findings/plan_candy-files.md` — Item 2.2
**Fix:** Register `register_shutdown_function()` to clean up trash directory
**Files:** `candy-files/src/Manager.php`
**Agent:** Same coder agent as 1.1 (grouped — same lib)

#### 1.3 🟡 candy-files: FsLister syscall optimization (Item 2.3)

**Plan:** `findings/plan_candy-files.md` — Item 2.3
**Fix:** Use `S_ISLNK($mode)` and `S_ISDIR($mode)` from lstat mode bits instead of `is_link()` + `is_dir()`
**Files:** `candy-files/src/FsLister.php:32-33`
**Agent:** Same coder agent as 1.1 (grouped — same lib)

---

#### 1.4 🟡 sugar-quickpick: Highlighter new instance every call (Item 2.1)

**Plan:** `findings/plan_sugar-quickpick.md` — Item 2.1
**Fix:** Add static `$highlighter` property or `getHighlighter()` method on `Hermit.php`
**Files:** `sugar-quickpick/src/Hermit.php:810`
**Agent:** Spawn 1 coder agent — inject full Safety Contract above

#### 1.5 🟡 sugar-quickpick: ANSI parser new instance every call (Item 2.2)

**Plan:** `findings/plan_sugar-quickpick.md` — Item 2.2
**Fix:** Cache the anonymous class handler and Parser instance on `Hermit` instead of recreating each `printableText()` call
**Files:** `sugar-quickpick/src/Hermit.php:704-753`
**Agent:** Same coder agent as 1.4 (grouped — same lib)

#### 1.6 🟡 sugar-quickpick: StatusBar::segments() returns internal array (Item 2.3)

**Plan:** `findings/plan_sugar-quickpick.md` — Item 2.3
**Fix:** Return `array_values($this->segments)` to return a copy
**Files:** `sugar-quickpick/src/StatusBar.php:92-96`
**Agent:** Spawn 1 coder agent — inject full Safety Contract above

---

#### 1.7 🟡 sugar-quickpick: HelpBar::shortcuts() same issue (Item 2.4)

**Plan:** `findings/plan_sugar-quickpick.md` — Item 2.4
**Fix:** Return `array_values($this->shortcuts)`
**Files:** `sugar-quickpick/src/HelpBar.php:67-71`
**Agent:** Same coder agent as 1.6 (grouped — same lib)

#### 1.8 🟡 sugar-cli: Section::header multi-cell rune formula (Item 1.1)

**Plan:** `findings/plan_sugar-cli.md` — Item 1.1
**Fix:** Change `$leftPad` semantics to "rune count" with `str_repeat($rune, max(0, $leftPad))` (remove intdiv)
**Files:** `sugar-cli/src/Section.php:33-34`
**Agent:** Spawn 1 coder agent — inject full Safety Contract above

#### 1.9 🟡 sugar-cli: Section::rule minimum width inconsistency (Item 1.2)

**Plan:** `findings/plan_sugar-cli.md` — Item 1.2
**Fix:** Make `?? 2` consistent with header() or document the difference
**Files:** `sugar-cli/src/Section.php:58`
**Agent:** Same coder agent as 1.8 (grouped — same lib)

---

### Group 1-B (3 agents)

#### 1.10 🟡 sugar-cli: Theme::byName type guard (Item 2.1)

**Plan:** `findings/plan_sugar-cli.md` — Item 2.1
**Fix:** Add `\InvalidArgumentException` for non-string input before `strtolower()`
**Files:** `sugar-cli/src/Theme.php:113-124`
**Agent:** Spawn 1 coder agent — inject full Safety Contract above

#### 1.11 🟡 sugar-treelist: View() catches RuntimeException not Throwable (Item 1.1)

**Plan:** `findings/plan_sugar-treelist.md` — Item 1.1
**Fix:** Change `catch (\RuntimeException $e)` to `catch (\Throwable $e)` at `Model.php:474`
**Files:** `sugar-treelist/src/Model.php:474`
**Agent:** Spawn 1 coder agent — inject full Safety Contract above

#### 1.12 🟡 sugar-treelist: FilterState::filtered dead enum case (Item 1.2)

**Plan:** `findings/plan_sugar-treelist.md` — Item 1.2
**Fix:** Remove `case filtered;` from `FilterState.php:25`
**Files:** `sugar-treelist/src/FilterState.php:25`
**Agent:** Same coder agent as 1.11 (grouped — same lib)

---

#### 1.13 🟡 sugar-treelist: splitOverWidth() UTF-8 grapheme (Item 1.4)

**Plan:** `findings/plan_sugar-treelist.md` — Item 1.4
**Fix:** Replace `\strlen($word)` with `\grapheme_strlen($word)` and `\substr` with `\grapheme_substr`
**Files:** `sugar-treelist/src/Model.php:593-601`
**Agent:** Same coder agent as 1.11 (grouped — same lib)

#### 1.14 🟡 sugar-treelist: ANSI injection in renderItem() (Item 3.1)

**Plan:** `findings/plan_sugar-treelist.md` — Item 3.1
**Fix:** Add `Ansi::strip()` around `(string) $item->value` before `hardWrap()` or document trust boundary
**Files:** `sugar-treelist/src/Model.php:513`
**Agent:** Same coder agent as 1.11 (grouped — same lib)

#### 1.15 🟡 sugar-focus: next()/previous() code duplication (Items 2.1-2.4)

**Plan:** `findings/plan_sugar-focus.md` — Items 2.1-2.4
**Fix:** Extract `enabledPositions(): ?array` helper; update `next()` and `previous()` to use it
**Files:** `sugar-focus/src/FocusRing.php:213-219, 258-264`
**Agent:** Spawn 1 coder agent — inject full Safety Contract above

---

### Group 1-C (3 agents)

#### 1.16 🟡 candy-input: Shift+scroll tests missing (Item 1.1)

**Plan:** `findings/plan_candy-input.md` — Item 1.1
**Fix:** Add `testShiftScrollUp` and `testShiftScrollDown` to `EscapeDecoderTest.php`
**Files:** `candy-input/tests/EscapeDecoderTest.php`
**Agent:** Spawn 1 coder agent — inject full Safety Contract above

#### 1.17 🟡 candy-input: equals() docblock missing (Item 2.3)

**Plan:** `findings/plan_candy-input.md` — Item 2.3
**Fix:** Add docblock to `KeyModifier::equals()` explaining why it exists despite `===` working for value comparison
**Files:** `candy-input/src/KeyModifier.php:159-162`
**Agent:** Same coder agent as 1.16 (grouped — same lib)

#### 1.18 🟡 sugar-colorprofile: No DetectionChain (Items 1.1-1.8)

**Plan:** `findings/plan_sugar-colorprofile.md` — Items 1.1-1.8
**Fix:** Create `DetectionChain.php` and refactor `Palette::detectProfile()`, `Probe::colorProfile()`, `TerminalProbe::checkEnvVars()` to use it. Reduce triplication of detection logic.
**Files:** `sugar-colorprofile/src/DetectionChain.php` (new), `sugar-colorprofile/src/Palette.php`, `sugar-colorprofile/src/Probe.php`
**Agent:** Spawn 1 coder agent — inject full Safety Contract above
**Note:** This is a large refactor — give this agent extra context time

---

### Group 1-D (3 agents)

#### 1.19 🟡 sugar-colorprofile: StandardColors caching (Item 1.11)

**Plan:** `findings/plan_sugar-colorprofile.md` — Item 1.11
**Fix:** Add static cache to `StandardColors::all()` — return same instance on repeated calls
**Files:** `sugar-colorprofile/src/StandardColors.php`
**Agent:** Spawn 1 coder agent — inject full Safety Contract above

#### 1.20 🟡 sugar-dashboard: EventDispatcher mutation (Item C-3)

**Plan:** `findings/plan_sugar-dashboard.md` — Item C-3
**Fix:** Return `[Event, self]` tuple from `dispatch()` without mutating `$this->listeners`; use `$this->onceKeysToRemove` properly after iteration
**Files:** `sugar-dashboard/src/Events/EventDispatcher.php:98-120`
**Agent:** Spawn 1 coder agent — inject full Safety Contract above

#### 1.21 🟡 sugar-dashboard: Chart render() mutation (Item H-1)

**Plan:** `findings/plan_sugar-dashboard.md` — Item H-1
**Fix:** Use render-context object instead of mutating `$this->previousFrame`, `$this->prevWidth`, `$this->prevHeight`
**Files:** `sugar-dashboard/src/Chart.php:129-134`
**Agent:** Same coder agent as 1.20 (grouped — same lib)

---

#### 1.22 🟡 sugar-dashboard: SystemModule fetchSystemData mutation (Item H-3)

**Plan:** `findings/plan_sugar-dashboard.md` — Item H-3
**Fix:** Don't call `fetchSystemData()` before `withSystemState()` — move it inside or compute fresh
**Files:** `sugar-dashboard/src/Modules/SystemModule.php:97-112`
**Agent:** Same coder agent as 1.20 (grouped — same lib)

#### 1.23 🟡 sugar-dashboard: FocusManager array_search false (Item M-5)

**Plan:** `findings/plan_sugar-dashboard.md` — Item M-5
**Fix:** Check `$idx !== false` before using result of `array_search()` in `focusNext()` and `focusPrevious()`
**Files:** `sugar-dashboard/src/FocusManager.php:70-72, 85-87`
**Agent:** Same coder agent as 1.20 (grouped — same lib)

#### 1.24 🟡 candy-git: removeDir() DRY extraction (Item 2.1)

**Plan:** `findings/plan_candy-git.md` — Item 2.1
**Fix:** Extract `removeDir()` into `tests/Concerns/RecursiveDirCleanup.php` trait; update both test files
**Files:** `candy-git/tests/Concerns/RecursiveDirCleanup.php` (new)
**Agent:** Spawn 1 coder agent — inject full Safety Contract above

---

### Group 1-E (3 agents)

#### 1.25 🟡 candy-git: Interactive rebase execution (Item 4.1)

**Plan:** `findings/plan_candy-git.md` — Item 4.1
**Fix:** Either wire `markDone()` into `App.php` key handling, OR add TODO comment documenting this is unimplemented
**Files:** `candy-git/src/InteractiveRebase.php:194-196`, `candy-git/src/App.php`
**Agent:** Spawn 1 coder agent — inject full Safety Contract above

#### 1.26 🟡 candy-vcr: FrameStream mutation during iteration (Item 2.1)

**Plan:** `findings/plan_candy-vcr.md` — Item 2.1
**Fix:** `TapeToGif.php:105` — `$renderCursor` read before foreach; restructure to avoid mutation during iteration
**Files:** `candy-vcr/src/TapeToGif.php:105`
**Agent:** Spawn 1 coder agent — inject full Safety Contract above

#### 1.27 🟡 candy-vcr: Negative dt validation (Item 2.2)

**Plan:** `findings/plan_candy-vcr.md` — Item 2.2
**Fix:** Add `if ($dt < 0) { throw new \InvalidArgumentException(...); }` at `RelativeFormat.php:200-201`
**Files:** `candy-vcr/src/RelativeFormat.php:200-201`
**Agent:** Same coder agent as 1.26 (grouped — same lib)

---

#### 1.28 🟡 candy-vcr: Double iteration in InspectCommand (Item 2.4)

**Plan:** `findings/plan_candy-vcr.md` — Item 2.4
**Fix:** Single-pass counting and processing instead of two iterations
**Files:** `candy-vcr/src/Command/InspectCommand.php`
**Agent:** Same coder agent as 1.26 (grouped — same lib)

#### 1.29 🟡 sugar-video: rebuildAudio() extraction (Item 2.3)

**Plan:** `findings/plan_sugar-video.md` — Item 2.3
**Fix:** Extract duplicate `withSeek()` and `seekToSeconds()` code into shared `rebuildAudio()` helper
**Files:** `sugar-video/src/Player.php:931-942, 1058-1068`
**Agent:** Spawn 1 coder agent — inject full Safety Contract above

#### 1.30 🟡 sugar-video: PNG buffer unbounded (Item 3.2)

**Plan:** `findings/plan_sugar-video.md` — Item 3.2
**Fix:** Add `MAX_PNG_BUFFER` constant and size check in `nextPng()` — fail gracefully if buffer exceeds limit
**Files:** `sugar-video/src/FfmpegDecoder.php:313-333`
**Agent:** Same coder agent as 1.29 (grouped — same lib)

---

### Group 1-F (3 agents)

#### 1.31 🟡 sugar-video: DecoderInterface::reopen() (Item 2.1)

**Plan:** `findings/plan_sugar-video.md` — Item 2.1
**Fix:** Add `reopen()` method to `Decoder.php` interface and implement in `FakeDecoder.php`; use in `Player.php:904`
**Files:** `sugar-video/src/Decoder.php`, `sugar-video/src/FakeDecoder.php`, `sugar-video/src/Player.php:904`
**Agent:** Spawn 1 coder agent — inject full Safety Contract above

#### 1.32 🟡 sugar-spring: CubicBezier x-value validation (Item 1.3)

**Plan:** `findings/plan_sugar-spring.md` — Item 1.3
**Fix:** Add range check `0 <= x <= 1` for x1/x2 in `CubicBezier.php` constructor; throw `\InvalidArgumentException` on invalid
**Files:** `sugar-spring/src/Easing/CubicBezier.php:40-47`
**Agent:** Spawn 1 coder agent — inject full Safety Contract above

#### 1.33 🟡 sugar-spring: SpringConfig silent clamping (Item 1.4)

**Plan:** `findings/plan_sugar-spring.md` — Item 1.4
**Fix:** Throw `\InvalidArgumentException` for mass ≤ 0 instead of silently clamping to 0.001
**Files:** `sugar-spring/src/SpringConfig.php:33`
**Agent:** Same coder agent as 1.32 (grouped — same lib)

---

### Phase 1 Verification

After ALL 1.x items complete (all groups DONE):
```
git status
git branch -a
gh pr list --state open
```
Run tests for all modified libs, per lib:
```bash
cd <lib> && composer install --quiet 2>/dev/null && vendor/bin/phpunit 2>&1 | tail -5
```

---

## Phase 2: DRY Refactoring and Architecture

### Group 2-A (3 agents)

#### 2.1 🟢 candy-core: Extract `Clamp.php` migration incomplete (Item 1.1)

**Plan:** `findings/plan_repeated_logic.md` — Item 1.1
**Status:** sugar-widgets and sugar-forms still have local `clamp()` methods
**Fix:** Replace `sugar-widgets/src/Tree/Tree.php:L317` and `sugar-forms/src/Viewport/Viewport.php:L462` with `SugarCraft\Core\Util\Clamp::int()` or shared helper
**Files:** `sugar-widgets/src/Tree/Tree.php`, `sugar-forms/src/Viewport/Viewport.php`
**Agent:** Spawn 1 coder agent — inject full Safety Contract above
**Note:** sugar-widgets clamp() has different semantics (returns self, modifies cursor+offset) — may need custom solution

#### 2.2 🟢 repeated_logic: Validation API mismatch (Item 1.2)

**Plan:** `findings/plan_repeated_logic.md` — Item 1.2
**Issue:** Implemented `nonNeg(int $value, string $name = 'value'): int` but plan spec said `nonNeg(int|float $value, string $key, array $params = []): void`
**Fix:** Evaluate: current API (returns value) is more usable than spec (void). Keep current API. Add `positive()` and `range()` if needed.
**Files:** `candy-core/src/Util/Validation.php`
**Agent:** Same coder agent as 2.1 (grouped — same lib area)

#### 2.3 🟢 sugar-widgets: Extract sanitizeCell() to candy-core (Item 2.1)

**Plan:** `findings/plan_sugar-widgets.md` — Item 2.1
**Fix:** Create `candy-core/src/Util/Sanitize.php` with `sanitizeCell()`; update Tabs, Tree, Table to use it
**Files:** `candy-core/src/Util/Sanitize.php` (new), `sugar-widgets/src/Tabs.php`, `sugar-widgets/src/Tree.php`, `sugar-widgets/src/Table.php`
**Agent:** Spawn 1 coder agent — inject full Safety Contract above

---

### Group 2-B (3 agents)

#### 2.4 🟢 sugar-image: Extract DRY helpers (Items 3.1-3.4)

**Plan:** `findings/plan_sugar-image.md` — Items 3.1-3.4
**Fix:**

- Extract `Renderer::prepareRender()` base method (or document why not)
- Create `candy-core/src/Util/ColorUtil.php` for `dist()` and `luma()`
- Split `QuarterBlockRenderer::renderCell()` into 4 methods
- Extract RLE inner loop from `emitBand()`
**Files:** `sugar-image/src/QuarterBlockRenderer.php`, `sugar-image/src/SixelRenderer.php`, etc.
**Agent:** Spawn 1 coder agent — inject full Safety Contract above

#### 2.5 🟢 sugar-dashboard: Chart wither boilerplate (Items M-4, 6.1)

**Plan:** `findings/plan_sugar-dashboard.md` — Items M-4, 6.1
**Fix:** Add canonical `with(array $overrides)` method to Chart.php; reduce 12 individual withers
**Files:** `sugar-dashboard/src/Chart.php:436-690`
**Agent:** Same coder agent as 2.4 (grouped — same lib)

#### 2.6 🟢 sugar-quickpick: computeWidth() redundant call (Item 3.1)

**Plan:** `findings/plan_sugar-quickpick.md` — Item 3.1
**Fix:** Compute width once in `View()` and pass as parameter to `compositeOver()` instead of recomputing
**Files:** `sugar-quickpick/src/Hermit.php:476, 823`
**Agent:** Same coder agent as 2.4 (grouped — same lib)

---

### Group 2-C (3 agents)

#### 2.7 🟢 sugar-quickpick: withItems filter redundancy (Item 3.2)

**Plan:** `findings/plan_sugar-quickpick.md` — Item 3.2
**Fix:** Early return when `filterText === ''` — skip `applyFilter()` call
**Files:** `sugar-quickpick/src/Hermit.php:122-129`
**Agent:** Spawn 1 coder agent — inject full Safety Contract above

#### 2.8 🟢 sugar-quickpick: applyFilter array_filter (Item 3.3)

**Plan:** `findings/plan_sugar-quickpick.md` — Item 3.3
**Fix:** Use `foreach` loop instead of `\array_filter()` + `\array_values()` pattern
**Files:** `sugar-quickpick/src/Hermit.php:582-608`
**Agent:** Same coder agent as 2.7 (grouped — same lib)

#### 2.9 🟢 sugar-widgets: sortedRows() single-pass (Item 2.2)

**Plan:** `findings/plan_sugar-widgets.md` — Item 2.2
**Fix:** Implement single-pass usort instead of loop with multiple usort passes
**Files:** `sugar-widgets/src/Table.php:506-526`
**Agent:** Spawn 1 coder agent — inject full Safety Contract above

---

#### 2.10 🟢 sugar-widgets: AnimatedProgress spring caching (Item 3.6)

**Plan:** `findings/plan_sugar-widgets.md` — Item 3.6
**Fix:** Cache the Spring instance instead of creating new one every tick
**Files:** `sugar-widgets/src/AnimatedProgress.php:79`
**Agent:** Same coder agent as 2.9 (grouped — same lib)

#### 2.11 🟢 candy-buffer: DiffOptimiser array_merge loop (Item 3.2)

**Plan:** `findings/plan_candy-buffer.md` — Item 3.2
**Fix:** Use `foreach` with `$buffer[] = $cell` instead of `array_merge($buffer, $op->cells)` inside loop
**Files:** `candy-buffer/src/DiffOptimiser.php:96`
**Agent:** Spawn 1 coder agent — inject full Safety Contract above

#### 2.12 🟢 candy-buffer: DiffEncoder state reset (Item 4.1)

**Plan:** `findings/plan_candy-buffer.md` — Item 4.1
**Fix:** Reset `cursorCol`, `cursorRow`, `currentStyle`, `currentLinkUrl`, `lastRune` at start of `encode()`
**Files:** `candy-buffer/src/Diff/DiffEncoder.php:50-65`
**Agent:** Same coder agent as 2.11 (grouped — same lib)

---

### Group 2-D (3 agents)

#### 2.13 🟢 candy-buffer: Hyperlink identity comparison (Item 2.2)

**Plan:** `findings/plan_candy-buffer.md` — Item 2.2
**Fix:** Add `Hyperlink::equals(Hyperlink $other): bool` method; use in `Buffer::toAnsi()`
**Files:** `candy-buffer/src/Hyperlink.php`, `candy-buffer/src/Buffer.php:458`
**Agent:** Spawn 1 coder agent — inject full Safety Contract above

#### 2.14 🟢 sugar-markdown: String concat loops (Items 2.1-2.4)

**Plan:** `findings/plan_sugar-markdown.md` — Items 2.1-2.4
**Fix:**

- 2.1: Replace `foreach ($lines as &$line)` reference loop with `array_map`
- 2.2: Use array accumulation + `implode()` in `Renderer::renderChildren()`
- 2.3: Cache regex pattern in `tokenise()`
- 2.4: Use array accumulation in `renderList()`
**Files:** `sugar-markdown/src/SyntaxHighlighter.php`, `sugar-markdown/src/Renderer.php`
**Agent:** Spawn 1 coder agent — inject full Safety Contract above

#### 2.15 🟢 sugar-markdown: Theme::fromJson issues (Items 3.1-3.3)

**Plan:** `findings/plan_sugar-markdown.md` — Items 3.1-3.3
**Fix:**

- 3.1: Remove `@` from `file_get_contents()`; use `error_get_last()` for enrichment
- 3.2: Add `json_last_error() !== JSON_ERROR_NONE` check
- 3.3: Remove redundant `is_file()` check before `file_get_contents()`
**Files:** `sugar-markdown/src/Theme.php:555-572`
**Agent:** Same coder agent as 2.14 (grouped — same lib)

---

### Group 2-E (3 agents)

#### 2.16 🟢 candy-codeshot: LayoutCalculator extraction (Item 3.1)

**Plan:** `findings/plan_candy-codeshot.md` — Item 3.1
**Fix:** Extract shared `LayoutCalculator.php` from duplicate sizing logic in SvgRenderer and PngRenderer
**Files:** `candy-codeshot/src/LayoutCalculator.php` (new)
**Agent:** Spawn 1 coder agent — inject full Safety Contract above

#### 2.17 🟢 candy-codeshot: match expression in applySgr (Item 3.3)

**Plan:** `findings/plan_candy-codeshot.md` — Item 3.3
**Fix:** Replace 20+ `if/continue` with `match (true)` expression in `AnsiParser.php:122-133`
**Files:** `candy-codeshot/src/AnsiParser.php:122-133`
**Agent:** Same coder agent as 2.16 (grouped — same lib)

#### 2.18 🟢 candy-testing: O(n) array_shift (Item 2.3)

**Plan:** `findings/plan_candy-testing.md` — Item 2.3
**Fix:** Use `SplQueue` or index pointer instead of `array_shift()` at `ProgramSimulator.php:140`
**Files:** `candy-testing/src/ProgramSimulator.php:140`
**Agent:** Spawn 1 coder agent — inject full Safety Contract above

---

#### 2.19 🟢 candy-testing: escapeVhsRune addcslashes (Item 2.4)

**Plan:** `findings/plan_candy-testing.md` — Item 2.4
**Fix:** Replace manual character loop with `addcslashes($rune, '\\"')`
**Files:** `candy-testing/src/Assertions.php:207-221`
**Agent:** Same coder agent as 2.18 (grouped — same lib)

#### 2.20 🟢 candy-testing: KeyType coverage (Items 2.1-2.2)

**Plan:** `findings/plan_candy-testing.md` — Items 2.1-2.2
**Fix:** Add F1-F12, Home, End, PageUp, PageDown, Delete, Insert key mappings to `keyMsgToVhs()` and tests
**Files:** `candy-testing/src/TapeRecorder.php:175-196`, `candy-testing/tests/TapeRecorderTest.php`
**Agent:** Same coder agent as 2.18 (grouped — same lib)

### Phase 2 Verification

Same branch/PR check as Phase 0/1.

---

## Phase 3: Missing Features and API Completeness

### Group 3-A (3 agents)

#### 3.1 🟢 sugar-fuzzy: matchAllGenerator (Item 3.1)

**Plan:** `findings/plan_sugar-fuzzy.md` — Item 3.1
**Fix:** Add generator-based `matchAllGenerator()` to `FuzzyMatcher` interface and both implementations
**Files:** `sugar-fuzzy/src/FuzzyMatcher.php`, `sugar-fuzzy/src/Matcher/SahilmMatcher.php`, `sugar-fuzzy/src/Matcher/SmithWatermanMatcher.php`
**Agent:** Spawn 1 coder agent — inject full Safety Contract above

#### 3.2 🟢 sugar-fuzzy: FuzzyMatcherFactory (Item 3.2)

**Plan:** `findings/plan_sugar-fuzzy.md` — Item 3.2
**Fix:** Create `src/Matcher/FuzzyMatcherFactory.php` with static `create()` method
**Files:** `sugar-fuzzy/src/Matcher/FuzzyMatcherFactory.php` (new)
**Agent:** Same coder agent as 3.1 (grouped — same lib)

#### 3.3 🟢 sugar-fuzzy: MatchResultSorter (Item 3.3)

**Plan:** `findings/plan_sugar-fuzzy.md` — Item 3.3
**Fix:** Create `src/MatchResultSorter.php`; update both matchers to use it (remove duplicated sorting logic)
**Files:** `sugar-fuzzy/src/MatchResultSorter.php` (new)
**Agent:** Same coder agent as 3.1 (grouped — same lib)

---

#### 3.4 🟢 sugar-focus: enabledCount/disabeledCount (Items 3.1-3.2)

**Plan:** `findings/plan_sugar-focus.md` — Items 3.1-3.2
**Fix:** Add `enabledCount(): int` and `disabledCount(): int` methods
**Files:** `sugar-focus/src/FocusRing.php`
**Agent:** Spawn 1 coder agent — inject full Safety Contract above

#### 3.5 🟢 sugar-focus: IteratorAggregate (Item 3.3)

**Plan:** `findings/plan_sugar-focus.md` — Item 3.3
**Fix:** Implement `\IteratorAggregate` on `FocusRing`; add `getIterator(): \ArrayIterator`
**Files:** `sugar-focus/src/FocusRing.php`
**Agent:** Same coder agent as 3.4 (grouped — same lib)

#### 3.6 🟢 sugar-focus: JsonSerializable (Item 3.4)

**Plan:** `findings/plan_sugar-focus.md` — Item 3.4
**Fix:** Implement `\JsonSerializable` on `FocusRing`; add `jsonSerialize(): array`
**Files:** `sugar-focus/src/FocusRing.php`
**Agent:** Same coder agent as 3.4 (grouped — same lib)

---

### Group 3-B (3 agents)

#### 3.7 🟢 sugar-cli: Theme getters for Style properties (Item 3.4)

**Plan:** `findings/plan_sugar-cli.md` — Item 3.4
**Fix:** Add `success(): Style`, `error(): Style`, etc. getter methods to `Theme`
**Files:** `sugar-cli/src/Theme.php`
**Agent:** Spawn 1 coder agent — inject full Safety Contract above

#### 3.8 🟢 sugar-cli: Banner::title Style caching (Item 3.1)

**Plan:** `findings/plan_sugar-cli.md` — Item 3.1
**Fix:** Cache `Style::new()` instance in `Banner::title()` instead of recreating each call
**Files:** `sugar-cli/src/Banner.php:28-31`
**Agent:** Same coder agent as 3.7 (grouped — same lib)

#### 3.9 🟢 sugar-table: column() accessor (Item 4.1)

**Plan:** `findings/plan_sugar-table.md` — Item 4.1
**Fix:** Add `column(string $key): ?Column` method to Table class
**Files:** `sugar-table/src/Table.php`
**Agent:** Spawn 1 coder agent — inject full Safety Contract above

---

#### 3.10 🟢 sugar-table: Visible column pre-computation (Item 2.1)

**Plan:** `findings/plan_sugar-table.md` — Item 2.1
**Fix:** Pre-compute visible column indices once before row loop; reuse in `fillDataRow()`, `fillDataRowLines()`, etc.
**Files:** `sugar-table/src/Table.php`
**Agent:** Same coder agent as 3.9 (grouped — same lib)

#### 3.11 🟢 sugar-toast: Timer control API (Items 2.2-2.5)

**Plan:** `findings/plan_sugar-toast.md` — Items 2.2-2.5
**Fix:** Add `withoutExpiry()`, `cancelAlert()`, `extendAlert()`, `extendAll()` methods to `Toast`
**Files:** `sugar-toast/src/Toast.php`, `sugar-toast/src/Alert.php`
**Agent:** Spawn 1 coder agent — inject full Safety Contract above

#### 3.12 🟢 sugar-toast: Action buttons (Items 4.1-4.4)

**Plan:** `findings/plan_sugar-toast.md` — Items 4.1-4.4
**Fix:** Add `?list<Action> $actions` parameter to `Toast::alert()` and `Toast::progressToast()`; wire through to `Alert`
**Files:** `sugar-toast/src/Toast.php:182-215`
**Agent:** Same coder agent as 3.11 (grouped — same lib)

---

### Group 3-C (3 agents)

#### 3.13 🟢 sugar-treelist: Navigation methods (Item 5.1)

**Plan:** `findings/plan_sugar-treelist.md` — Item 5.1
**Fix:** Add `cursorPageUp()`, `cursorPageDown()`, `cursorToStart()`, `cursorToEnd()` methods
**Files:** `sugar-treelist/src/Model.php`
**Agent:** Spawn 1 coder agent — inject full Safety Contract above

#### 3.14 🟢 sugar-treelist: setLineOffset() fluent (Item 1.3)

**Plan:** `findings/plan_sugar-treelist.md` — Item 1.3
**Fix:** Add `setLineOffset(int $n): self` fluent setter
**Files:** `sugar-treelist/src/Model.php`
**Agent:** Same coder agent as 3.13 (grouped — same lib)

#### 3.15 🟢 sugar-treelist: sort O(n) cursor relocation (Item 2.1)

**Plan:** `findings/plan_sugar-treelist.md` — Item 2.1
**Fix:** Maintain cursor index during sort using object identity map instead of O(n) foreach
**Files:** `sugar-treelist/src/Model.php:264-284`
**Agent:** Same coder agent as 3.13 (grouped — same lib)

---

#### 3.16 🟢 candy-testing: TemporaryDirectoryTrait (Item 4.1)

**Plan:** `findings/plan_candy-testing.md` — Item 4.1
**Fix:** Extract duplicated `setUp()`/`tearDown()` temp directory logic into `tests/Concerns/TemporaryDirectoryTrait.php`
**Files:** `candy-testing/tests/Concerns/TemporaryDirectoryTrait.php` (new)
**Agent:** Spawn 1 coder agent — inject full Safety Contract above

#### 3.17 🟢 candy-input: EscapeDecoderOptions (Item 4.1)

**Plan:** `findings/plan_candy-input.md` — Item 4.1
**Fix:** Create `EscapeDecoderOptions.php` for protocol filtering
**Files:** `candy-input/src/EscapeDecoderOptions.php` (new)
**Agent:** Same coder agent as 3.16 (grouped — same lib)

#### 3.18 🟢 candy-input: ReactInputDriver (Item 4.2)

**Plan:** `findings/plan_candy-input.md` — Item 4.2
**Fix:** Create `ReactInputDriver.php` wrapping ReactPHP streams
**Files:** `candy-input/src/Driver/ReactInputDriver.php` (new)
**Agent:** Same coder agent as 3.16 (grouped — same lib)

---

### Group 3-D (3 agents)

#### 3.19 🟢 candy-input: SignalResizeDriver (Item 4.3)

**Plan:** `findings/plan_candy-input.md` — Item 4.3
**Fix:** Create `SignalResizeDriver.php` for SIGWINCH-based resize detection
**Files:** `candy-input/src/Driver/SignalResizeDriver.php` (new)
**Agent:** Spawn 1 coder agent — inject full Safety Contract above

#### 3.20 🟢 sugar-colorprofile: AsyncProbe (Item 3.1)

**Plan:** `findings/plan_sugar-colorprofile.md` — Item 3.1
**Fix:** Create `AsyncProbe.php` using ReactPHP ChildProcess for async terminal detection
**Files:** `sugar-colorprofile/src/AsyncProbe.php` (new)
**Agent:** Spawn 1 coder agent — inject full Safety Contract above

#### 3.21 🟢 sugar-spring: Spring reducedMotionOverride (Item 2.1)

**Plan:** `findings/plan_sugar-spring.md` — Item 2.1
**Fix:** Add `reducedMotionOverride: ?bool = null` parameter to `Spring` constructor; cache per-instance
**Files:** `sugar-spring/src/Spring.php:34-38`
**Agent:** Spawn 1 coder agent — inject full Safety Contract above

---

#### 3.22 🟢 sugar-spring: Vector methods (Item 2.3)

**Plan:** `findings/plan_sugar-spring.md` — Item 2.3
**Fix:** Add `lengthSquared()`, `normalize()`, `lerp()` to `Vector.php`
**Files:** `sugar-spring/src/Vector.php`
**Agent:** Same coder agent as 3.21 (grouped — same lib)

#### 3.23 🟢 sugar-spring: EasingFunction interface (Item 2.5)

**Plan:** `findings/plan_sugar-spring.md` — Item 2.5
**Fix:** Create `EasingFunction.php` interface; have `Easing` enum implement it
**Files:** `sugar-spring/src/Easing/EasingFunction.php` (new)
**Agent:** Same coder agent as 3.21 (grouped — same lib)

#### 3.24 🟢 candy-git-server: Async GitDaemon (Item 6.1)

**Plan:** `findings/plan_candy-git-server.md` — Item 6.1
**Fix:** (Deferred — marked as future work in plan)
**Agent:** ⏭️ SKIP — deferred, not blocking

---

### Group 3-E (3 agents)

#### 3.25 🟢 sugar-video: Seeking callback (Item 5.1)

**Plan:** `findings/plan_sugar-video.md` — Item 5.1
**Fix:** (Deferred — marked as future work)
**Agent:** ⏭️ SKIP — deferred, not blocking

#### 3.26 🟢 sugar-log: StreamHandler resource type hint (Item 1.1)

**Plan:** `findings/plan_sugar-log.md` — Item 1.1
**Fix:** Add `resource` type hint to `$stream` in `StreamHandler::__construct()`
**Files:** `sugar-log/src/Handler/StreamHandler.php`
**Agent:** Spawn 1 coder agent — inject full Safety Contract above

#### 3.27 🟢 sugar-log: HookRegistry::fire() return type (Item 2.1)

**Plan:** `findings/plan_sugar-log.md` — Item 2.1
**Fix:** Document or fix `HookRegistry::fire()` — it always returns `null` but `Manager::fire()` checks return value
**Files:** `sugar-log/src/HookRegistry.php`, `sugar-log/src/Manager.php`
**Agent:** Same coder agent as 3.26 (grouped — same lib)

---

#### 3.28 🟢 sugar-log: JsonFormatter coercion (Item 2.4)

**Plan:** `findings/plan_sugar-log.md` — Item 2.4
**Fix:** Ensure `JsonFormatter::format()` coerces all values to strings per PSR-3
**Files:** `sugar-log/src/Formatter/JsonFormatter.php`
**Agent:** Same coder agent as 3.26 (grouped — same lib)

#### 3.29 🟢 sugar-log: RemoveHandler::handle() void (Item 2.5)

**Plan:** `findings/plan_sugar-log.md` — Item 2.5
**Fix:** `RemoveHandler::handle()` should return `void` not `false`
**Files:** `sugar-log/src/Handler/RemoveHandler.php`
**Agent:** Same coder agent as 3.26 (grouped — same lib)

#### 3.30 🟢 sugar-log: Styles::getDefault() return type (Item 3.3)

**Plan:** `findings/plan_sugar-log.md` — Item 3.3
**Fix:** `Styles::getDefault()` should return `string[]` not `array`
**Files:** `sugar-log/src/Styles.php`
**Agent:** Same coder agent as 3.26 (grouped — same lib)

---

### Phase 3 Verification

Same verification as previous phases.

---

## Phase 4: Documentation and CALIBER_LEARNINGS

### Group 4-A (3 agents)

#### 4.1 🟢 CALIBER_LEARNINGS: pattern:new-factory-required

**Plan:** `findings/plan_repeated_logic.md` — Item 1.3
**Fix:** Add entry requiring all new classes to provide `::new(): self` factory method
**Files:** `candy-core/CALIBER_LEARNINGS.md`
**Agent:** Spawn 1 coder agent — inject full Safety Contract above

#### 4.2 🟢 CALIBER_LEARNINGS: mutable trait guidance (Item 4.1)

**Plan:** `findings/plan_repeated_logic.md` — Item 4.1
**Fix:** Add guidance for 10+ parameter constructors, manual state copying cases
**Files:** `candy-core/CALIBER_LEARNINGS.md`
**Agent:** Same coder agent as 4.1 (grouped — same file)

#### 4.3 🟢 CALIBER_LEARNINGS: AsyncOps ADR (Item 4.2)

**Plan:** `findings/plan_repeated_logic.md` — Item 4.2
**Fix:** Create `docs/adr/001-asyncops-consolidation.md` documenting decision on candy-async vs candy-files AsyncOps
**Files:** `docs/adr/001-asyncops-consolidation.md` (new)
**Agent:** Same coder agent as 4.1 (grouped — same file)

---

#### 4.4 🟢 CALIBER_LEARNINGS: Scanner state docs (candy-veil Item 5)

**Plan:** `findings/plan_sugar-overlay.md` — Item 5
**Fix:** Add docblock to `resetPreviousFrame()` explaining scanner state is NOT reset
**Files:** `sugar-overlay/src/Veil.php:720-723`
**Agent:** Spawn 1 coder agent — inject full Safety Contract above

#### 4.5 🟢 CALIBER_LEARNINGS: RenderSession frame counter (candy-veil Item 4)

**Plan:** `findings/plan_sugar-overlay.md` — Item 4
**Fix:** Either add frame counter with auto-reset, or add `release()` method and document usage
**Files:** `sugar-overlay/src/RenderSession.php`
**Agent:** Same coder agent as 4.4 (grouped — same lib)

#### 4.6 🟢 CALIBER_LEARNINGS: candy-git sync-only (Item 5.1)

**Plan:** `findings/plan_candy-git.md` — Item 5.1
**Fix:** Add documentation that GitDriver is synchronous-only; add `@deprecated async operations planned for v2` in GitDriver
**Files:** `candy-git/README.md`, `candy-git/src/GitDriver.php`
**Agent:** Spawn 1 coder agent — inject full Safety Contract above

---

### Group 4-B (3 agents)

#### 4.7 🟢 CALIBER_LEARNINGS: sugar-mouse async gap (Item 5.1)

**Plan:** `findings/plan_sugar-mouse.md` — Item 5.1
**Fix:** Add note about synchronous-only design in CALIBER_LEARNINGS.md
**Files:** `sugar-mouse/CALIBER_LEARNINGS.md`
**Agent:** Spawn 1 coder agent — inject full Safety Contract above

#### 4.8 🟢 CALIBER_LEARNINGS: sugar-mouse spatial index gap (Item 5.2)

**Plan:** `findings/plan_sugar-mouse.md` — Item 5.2
**Fix:** Add note about O(n) Scanner::hit() limitation and spatial index opportunity
**Files:** `sugar-mouse/CALIBER_LEARNINGS.md`
**Agent:** Same coder agent as 4.7 (grouped — same lib)

#### 4.9 🟢 CALIBER_LEARNINGS: sugar-mouse memoization (Item 5.3)

**Plan:** `findings/plan_sugar-mouse.md` — Item 5.3
**Fix:** Add note about memoization opportunity for repeated scans
**Files:** `sugar-mouse/CALIBER_LEARNINGS.md`
**Agent:** Same coder agent as 4.7 (grouped — same lib)

---

#### 4.10 🟢 CALIBER_LEARNINGS: sugar-colorprofile async gap

**Plan:** `findings/plan_sugar-colorprofile.md` — Item 5.1 (implicit)
**Fix:** Add note about synchronous probe design in CALIBER_LEARNINGS.md
**Files:** `sugar-colorprofile/CALIBER_LEARNINGS.md`
**Agent:** Spawn 1 coder agent — inject full Safety Contract above

#### 4.11 🟢 CALIBER_LEARNINGS: sugar-widgets transitional architecture (Item 3.4)

**Plan:** `findings/plan_sugar-widgets.md` — Item 3.4
**Fix:** Document deprecated aliases and transitional architecture in CALIBER_LEARNINGS.md
**Files:** `candy-core/CALIBER_LEARNINGS.md`
**Agent:** Same coder agent as 4.10 (grouped — same file area)

#### 4.12 🟢 CALIBER_LEARNINGS: sugar-widgets ReactPHP/async note (Item 6.2)

**Plan:** `findings/plan_sugar-widgets.md` — Item 6.2
**Fix:** Add note about Cmd::tick() vs subscriptions() pattern
**Files:** `candy-core/CALIBER_LEARNINGS.md`
**Agent:** Same coder agent as 4.10 (grouped — same file area)

---

## Phase 5: Test Coverage Additions

### Group 5-A (3 agents)

#### 5.1 🟢 sugar-table: Frozen/hidden conflict tests (Item 1.1)

**Plan:** `findings/plan_sugar-table.md` — Item 1.1
**Fix:** Add tests for `withFrozenCols()` and `withHiddenCols()` conflict detection
**Files:** `sugar-table/tests/TableTest.php`
**Agent:** Spawn 1 coder agent — inject full Safety Contract above

#### 5.2 🟢 sugar-table: Filter/SortBy invalid key tests (Item 1.2)

**Plan:** `findings/plan_sugar-table.md` — Item 1.2
**Fix:** Add tests for `SortBy()` and `Filter()` throwing on invalid column keys
**Files:** `sugar-table/tests/TableTest.php`
**Agent:** Same coder agent as 5.1 (grouped — same lib)

#### 5.3 🟢 sugar-animation: Per-frame delay test (Item 1.3)

**Plan:** `findings/plan_sugar-animation.md` — Item 1.3
**Fix:** Add `testPerFrameDelaySchedulesAppropriateInterval()` to `PlayerTest.php`
**Files:** `sugar-animation/tests/PlayerTest.php`
**Agent:** Spawn 1 coder agent — inject full Safety Contract above

---

#### 5.4 🟢 sugar-animation: DISPOSAL_PREVIOUS test (Item 4.3)

**Plan:** `findings/plan_sugar-animation.md` — Item 4.3
**Fix:** Add `testDisposalPreviousRestoresFromSnapshot()` to `DecoderCompositingTest.php`
**Files:** `sugar-animation/tests/DecoderCompositingTest.php`
**Agent:** Same coder agent as 5.3 (grouped — same lib)

#### 5.5 🟢 candy-input: FocusEvent tests (Item 3.1)

**Plan:** `findings/plan_candy-input.md` — Item 3.1
**Fix:** Add tests for intermediate bytes, private mode prefix, focus events followed by other sequences
**Files:** `candy-input/tests/EscapeDecoderTest.php`
**Agent:** Spawn 1 coder agent — inject full Safety Contract above

#### 5.6 🟢 candy-async: markCancelled visibility test (Item 1.1)

**Plan:** `findings/plan_candy-async.md` — Item 1.1
**Fix:** After fixing visibility, add test that CancellationSource::cancel() properly sets token cancelled state
**Files:** `candy-async/tests/CancellationTokenTest.php`
**Agent:** Same coder agent as 5.5 (grouped — same lib)

---

### Group 5-B (3 agents)

#### 5.7 🟢 sugar-spring: CubicBezier invalid control points (Item 4.1)

**Plan:** `findings/plan_sugar-spring.md` — Item 4.1
**Fix:** Add test expecting `\InvalidArgumentException` for out-of-range x values
**Files:** `sugar-spring/tests/CubicBezierTest.php`
**Agent:** Spawn 1 coder agent — inject full Safety Contract above

#### 5.8 🟢 candy-mdview: Viewport key binding tests (Item 2.1)

**Plan:** `findings/plan_candy-mdview.md` — Item 2.1
**Fix:** Add tests for PageUp, PageDown, Ctrl+U, Ctrl+D, Home, End, horizontal scroll, mouse wheel
**Files:** `candy-mdview/tests/MdViewModelTest.php`
**Agent:** Spawn 1 coder agent — inject full Safety Contract above

#### 5.9 🟢 sugar-image: ChafaRenderer pipe cleanup (Item 1.1)

**Plan:** `findings/plan_sugar-image.md` — Item 1.1
**Fix:** Add `testAvailableFalseCleansUpPipes()` test
**Files:** `sugar-image/tests/ChafaRendererTest.php`
**Agent:** Spawn 1 coder agent — inject full Safety Contract above

---

#### 5.10 🟢 sugar-image: ChafaRenderer::reset() (Item 1.5)

**Plan:** `findings/plan_sugar-image.md` — Item 1.5
**Fix:** Add `testAvailableResets()` test
**Files:** `sugar-image/tests/ChafaRendererTest.php`
**Agent:** Same coder agent as 5.9 (grouped — same lib)

### Phase 5 Verification

Same verification as previous phases.

---

## Phase 6: Remaining Items by Plan File

### candy-* libs (remaining low-priority)

| Plan | Items | Summary |
| ---- | ----- | ------- |
| `plan_candy-ansi.md` | 3.5, 3.6, 4.1-4.3 | MAX_STRING_BUFFER constant, 65535 magic number, test coverage |
| `plan_candy-buffer.md` | 5.1, 6.1-6.3, 7.1-7.5, 8.1 | Serialization, JsonSerializable, Buffer fill/copy, documentation |
| `plan_candy-codeshot.md` | 2.2-2.3, 3.2, 4.1-4.4, 5.1-5.2, 6.1, 7.1-7.2, 8.1-8.5 | Unicode warning, symlink check, WindowChromeGeometry, output streaming, LanguageDetector, Segment mutate, fclose, test coverage |
| `plan_sugar-fuzzy.md` | 2.1-2.3, 4.1-4.3 | Documentation, scoring config, Closure type, alignment constraint |
| `plan_sugar-quickpick.md` | 3.4-3.6, 4.1-4.5, 5.1, 5.3, 6.1-6.5 | ASCII fast-path, backgroundView validation, cursorBottom, filter length, constants, Visible trait, tests || `plan_sugar-cli.md` | 2.4, 3.2-3.3, 3.5-3.8, 4.1-4.5 | Snapshot tests, Theme null checks, logo color separation, Frame truncation, unused repos |
| `plan_sugar-treelist.md` | 4.1-4.7, 5.2-5.3, 6.1-6.3, 7.1-7.6, 8.1-8.4 | Fluent setters, mutate pattern, navigation methods, async patterns, code quality |
| `plan_sugar-log.md` | 1.2-1.3, 2.2-2.3, 2.6-2.9, 3.1-3.2, 3.4 | resource type hints, hook registry, JsonFormatter coercion, remove() method, styles default cache |
| `plan_candy-metrics.md` | 2.1-2.8, 3.1-3.10, 4.1-4.2 | DRY extractions, InMemoryBackend key format, bucket config, reset/remove, phpunit.xml |
| `plan_sugar-image.md` | 2.1-2.2 | SixelRenderer stripe-processing (memory), strided iteration (perf) — 2.3 ✅, 4.1-4.5 ✅, 5.1-5.5 ✅, 6.1 ✅ |
| `plan_sugar-mouse.md` | 3.1-3.3, 4.1-4.3 | Button constants, nextGrapheme docs, ScanIterator design, sentinel extraction |
| `plan_candy-pty.md` | 3.1-3.5, 4.1-4.4, 5.1-5.4, 6.1-6.2, 7.1-7.5, 8.1-8.4 | O_RDWR duplication, buffer trim, tight polling, mutable session, expect patterns, Darwin stty, value objects, async patterns |
| `plan_candy-sql.md` | 2.1-2.4, 3.1-3.5, 4.1-4.3, 5.1-5.3, 6.1 | isIgnorableError codes, AdminQueryCache injectable, atomic operations, DsnParser, query cancellation, timeout |
| `plan_candy-git-server.md` | 3.1-3.2, 4.1-4.5, 5.1-5.2, 6.1-6.2, 7.1-7.9 | SSH key validation, git error output, config properties, async patterns |
| `plan_candy-scriptui.md` | 2.2-2.3, 3.1-3.2, 4.1, 5.1-5.3 | Hex color validation, terminate closed, php://memory limit, ImagickRasterizer cache, CLI options |
| `plan_sugar-markdown.md` | 2.3, 3.3, 4.1, 5.1, 7.1-7.3 | Regex compilation caching, redundant is_file, Emoji shortcode regex, write() parity, emoji map expansion |
| `plan_candy-sprinkles.md` | All items | References wrong files — needs reassessment of which issues actually exist |
| `plan_candy-testing.md` | 3.1, 3.3-3.5, 4.1-4.2, 5.1-5.6, 6.1 | readonly docs, file_put_contents 0-byte, maxCycles docs, AsyncProgramSimulator, command assertions, mouse wheel, paste, streaming |
| `plan_candy-wish.md` | 2.1-2.4, 3.1-3.3 | Double getenv, rate limit atomicity, setTransport consistency, middleware error handling |
| `plan_sugar-mouse-zones.md` | 2.1 | Zone::contains() implementation |
| `plan_candy-vcr.md` | 2.3, 2.6-2.9, 3.1, 3.3-3.5 | preg_replace error handling, async defers, Glyphs O(1), formatHunk, V1ToV2Migrator |

### sugar-* libs (remaining low-priority)

| Plan | Items | Summary |
| ---- | ----- | ------- |
| `plan_sugar-widgets.md` | 2.3-2.4, 3.1-3.5, 3.7, 4.1-4.4, 5.1-5.2, 6.1-6.2, 7.1, 7.3 | Proportional width, Tabs scrollEnd, Progress layout, Tree/Table caching, C0 sanitization, input validation, ReactPHP/async docs |
| `plan_sugar-dashboard.md` | M-1, M-2, M-3, M-4, L-1, L-3, F-2, F-4 | WeatherModule HOME fallback, FilesystemIterator, LegacyModuleAdapter msg filtering, Gauge double clamp, PluginSdk signals, EventDispatcherTest |
| `plan_candy-mdview.md` | 2.2-2.3, 2.5 | GlamourTheme $throws, loadInput() docs, Pager empty stream |
| `plan_candy-mail.md` | 3.1, 3.2, 4.1-4.7, 5.1, 6.1-6.2 | buildMimeMessage refactor, async transport, Resend API key, address deduplication, hasExtension parsing, CID validation, getenv consistency |
| `plan_sugar-forms-huh.md` | 2.2-2.4, 3.2-3.7, 4.1-4.5, 5.1-5.2, 6.1-6.3, 7.1-7.3 | SIGCHLD handler, @ suppression removal, signal handler restoration, Spinner timeout, AsyncSpinner, SequentialSpinner, deprecation triggers, mutate helper |
| `plan_sugar-video.md` | 1.4, 2.4, 3.3-3.8, 4.1-4.9, 5.2-5.9, 6.2-6.9, 7.1-7.5, 8.1-8.6 | Exit code capture, mutate false/0, HalfBlock path, GifDecoder silent fallback, mode switch rebuild, renderPlaceholder memo, subtitle not found, skip limit, autoMode sentinel, decoderFactory, color packing, Windows NUL, tickMsg singleton, frame PTS |
| `plan_candy-git.md` | 3.1-3.2, 5.1 | DiffViewer early return, HistoryManager spread syntax, async git driver |
| `plan_sugar-table.md` | 2.2-2.4, 3.1-3.3, 4.1-4.3 | Navigation short-circuit, totalRows double-compute, widthSolveCache LRU, styleFunc doc, visibleColumnIndices helper |
| `plan_sugar-toast.md` | 3.1-3.3, 5.3, 6.3 | Null message handling, Finding 9 marker, Finding 10 marker ✅ COMPLETE d0f85418 |
| `plan_sugar-overlay.md` | 7 | VeilStack::compositeAll() misleading doc |
| `plan_sugar-boxer.md` | 1.1, 2.1-2.5, 3.1, 3.3-3.7 | Division guard, MAX_CARRY, func_num_args, WeakMap cache, flex separator docs, empty() check, function_exists cache, static regex, sentinel class |
| `plan_sugar-calendar.md` | 2.1-2.2, 3.1, 4.1, 5.1 | Viewport width config, month grid cache, week-boundary docs, ISO week column |
| `plan_sugar-readline.md` | 1.1, 1.2, 2.1, 2.3-2.4, 3.1-3.3, 4.1, 5.1 | Ctrl+R search, Vi cursor off-by-one, history race, unbounded growth, chmod 0600, Emacs incremental search, Vi text objects, architecture docs |
| `plan_sugar-flexbox.md` | 1.1, 2.1-2.2, 3.1-3.3 | FlexBox multibyte, Justify import, cursor reset docs, scroll default, perf docs |
| `plan_sugar-spring.md` | 2.2, 2.6, 3.2-3.4, 4.2-4.5 | SETTLING_THRESHOLD constant, EasingFunction interface, __toString, SpringChain::build, JsonSerializable, fps float, ReducedMotionTest parallel-unsafe, examples autoload |

---

## Branch Cleanup Protocol (After Each Phase)

After every batch of commits, run:
```bash
cd /home/sites/sugarcraft

# Check ALL branches (local and remote)

echo "=== Local branches ==="
git branch -a
echo ""
echo "=== Open PRs ==="
gh pr list --state open --json number,title,headRefName
echo ""
echo "=== Remote tracking refs ==="
git branch -r

# If any branches exist (other than master):
# For each branch that IS a merged commit into master:
#   git branch -d <branch>
#   git push origin --delete <branch>
# For each branch that has commits NOT in master:
#   git merge --no-ff origin/<branch>
#   git push origin master
#   git branch -d <branch>
#   git push origin --delete <branch>

```

**Rule: Only work on master. If any other branch exists after a phase, merge it into master or delete it before proceeding.**

---

## Plan File Status Markers

After completing each group, mark items in the original plan files:
- ✅ COMPLETE — with commit hash reference
- ⏭️ WONTFIX — with reason (e.g., "blocked: react/pg not on Packagist")
- 🔄 IN_PROGRESS — if actively being worked

**Do not modify the original plan files** — mark them read-only as historical record. Update the status in THIS file instead.

---

## Final Completion

When all phases are complete:
1. Run final branch cleanup
2. Verify 0 open PRs, 0 local/remote branches (except master)
3. Run full test suite: `for lib in */; do cd "$lib" && composer install --quiet && vendor/bin/phpunit 2>&1 | tail -3 && cd ..; done`
4. Update this plan file header: `# STATUS: COMPLETE`
5. All original plan files should have ALL items marked ✅/⏭️

---

*Last updated: 2026-07-04 — STATUS flipped to COMPLETE; see header for the closing commit map.*
*Source: 57 plan files, ~500+ total items*
*Concurrency: MAX 3 simultaneous agents, chained commit/push per agent*
