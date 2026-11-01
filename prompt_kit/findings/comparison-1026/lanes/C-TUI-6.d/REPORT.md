# C-TUI-6.d — candy-code on Windows: PtyRun / interactive Bash over ConPTY, README banner, CI smoke job

Lane: /home/sites/cc-cmp-C-TUI-6.d branch cc/C-TUI-6.d base 13f9a1829 (fresh cut, porcelain 0).

## State (live, incremental)

- Grounding complete: design block design-tui.md:809-841 read verbatim; plan §1/§2/§6 read; live sites re-derived (PtyDriver.php:119, CapturesProcessOutput.php:523 + drainPty :656, ProcessContainment.php interactiveAvailable :738 / interactiveSpawnCommand :798, README banner :23, SERVER.md ## Windows :1567, ci.yml macos-smoke precedent :657).
- Substrate verified class_exists TRUE on vendor: PtySystemFactory, Contract\{PtySystem,PtyPair,MasterPty,SlavePty,Child}, Posix\PosixPtySystem→PosixPtyPair(PosixMasterPty,PosixSlavePty), Windows\{ConPtySystem,ConPtyApi,Kernel32ConPty,ConPtySlavePty}.
- SuiteSkipRoster shape studied: const key + EXPECTED row + predicate IS the body gate + expectedForLayout lift. Windows smokes ride a new windowsSmokeRuns() predicate (PHP_OS_FAMILY === 'Windows').
- InteractiveSpawnCdGuardTest pins POSIX argv shape ['/bin/sh','-c',...] — Windows arm gated on WindowsShell::isWindows() (test-seamed), Linux stays byte-identical.

## Slice 1 (in flight): PtyBackend + opener migration

- create src/Support/PtyBackend.php; tests/Support/PtyBackendTest.php (live POSIX pair + injected system + conpty-override throws = factory path really taken).
- PtyDriver run() open/spawn/loop/teardown → pair master/slave; docblock @param truth-flips (Contract\MasterPty / Contract\Child) + class-docblock SlavePty::spawn naming.
- CapturesProcessOutput runCapturedInteractive same swap; drainPty param type → Contract\MasterPty.

## Slices 2-4 (queued)

2: ProcessContainment Windows arms (gate = Kernel32ConPty::supported() per design, stat-only; interactiveSpawnCommand Windows arm = WindowsShell::bash() -c; bash-missing refusal at the two caller sites riding BASH_MISSING_NOTICE per §6.3 no-cmd.exe law) + injected-fake + structural source-cert pins + docblock truth flip.
3: tests/Windows/WindowsBashSmokeTest + WindowsPtyRunSmokeTest (live legs, skip off Windows, UNCONFIRMED-BY-RUN here) + SuiteSkipRoster rows same commit.
4: README banner :23 + Install prose + docs/SERVER.md ## Windows prose (outside generated regions) + ci.yml candy-code-windows-smoke job mirroring macos-smoke.

## Seam ledger (report-only)

- src/Background/PtyRelay.php:270 also calls Pty::open() — C-TUI-6.j file, NOT in this design's files list; untouched, third-site follow-up.
- tests/Tools/InteractivePromptContainmentTest.php:592 opens the old facade in its own fixture — POSIX live test, facade still ships; untouched.
- sugar-pty ConPtyApi class docblock "Scope of this step" stale (adapters now built) — vendor file, out of scope.

## Slice ledger update (post-2a)

- SLICE 1 COMMITTED 5d7e0f615 — PtyBackend seam + PtyDriver/CapturesProcessOutput
  migration to pair/master/slave-spawn + PtyBackendTest 5T/14A (live POSIX pins,
  injected system, factory-delegation overrides). Linux pty behavior unchanged.
- SLICE 2a COMMITTED 74313e6f9 — Windows arms: interactiveAvailable() ConPTY leg
  (Kernel32ConPty::supported(), stat/version only), interactiveBashMissing()
  (§6.3 stat half), interactiveSpawnCommand() bash.exe leg, both callers refuse
  with WindowsShell::BASH_MISSING_NOTICE after the availability gate. Vendor-
  closure half truth-flipped to PtySystemFactory+PtyPair. InteractiveWindows-
  ArmsTest 7T/35A (injected-fake behavior + source-slice certs; memo-reset
  load-bearing, pin 7). Mutations M1/M2 discriminating, cp+md5 clean.
- SLICE 2b COMMITTING NOW — Windows smoke trio tests/Windows/{WindowsBashSmoke
  Test(2 legs: verbatim %PATH%/!x + timeout tree-kill w/ tasklist census),
  WindowsPtyRunSmokeTest(1 leg: read-prompt over ConPTY)}; SuiteSkipRoster
  WINDOWS_BASH_VERBATIM/WINDOWS_BASH_TREE_KILL/WINDOWS_PTYRUN_CONPTY consts +
  EXPECTED rows + windowsSmokeRuns() body gate + expectedForLayout lift;
  SuiteSkipRosterTest exact-shape arm mirrors the three arms (structure pinned,
  reasons intact); ci.yml candy-code-windows-smoke job (windows-latest, PHP 8.4
  + ffi per Kernel32ConPty::supported() floor, --version boot leg + tests\Windows
  ONLY per design; full suite fatals on POSIX legs — sugar-pty cell precedent).
  Linux gates: tests/Windows 3T/3S dormant rostered skips; SuiteSkipRosterTest
  28T/201A; guards family 142T/10312A; DocFigure 92T/4248A; lane family
  242T/915A; PtyRunToolTest 12-legit 12T/93A. Discrimination: forcing a body to
  run on Linux tripped the roster shutdown guard LOUD (advisory, exit nonzero)
  — skip law is armed, not decorative. ALL Windows legs UNCONFIRMED-BY-RUN here
  (first real execution = the smoke job).
- SEAM (report-only, Bash.php blob = zero-diff law): Bash.php:697-700 inline
  comment "ProcessContainment::interactiveAvailable() refuses Windows" is now
  stale wording — the mechanism no longer refuses-by-construction; the capture-
  path array-spawn rationale itself still holds. Welder/owner adjudicates.
- FOREIGN REDS CONFIRMED base-proven (stash-pair): StderrEmitterCensusTest 2F
  (PtyRelay 6.j debt, same family as rostered SeamCallSiteCensus 2F) and
  GlobDialectDifferentialTest corpus-figure 1F (310,245 vs 560×556=311,360 —
  the rostered 'assert NEITHER number' entry; my six files carry ZERO glob-
  shaped literals, probe-verified). Report-only, cure none.

## Slice ledger update (post-2b)

- SLICE 2b COMMITTED 549c92cb0 — Windows smoke trio + roster consts/rows/
  predicate/lift + exact-shape mirror in SuiteSkipRosterTest + ci.yml
  candy-code-windows-smoke job (8.4+ffi, --version boot + tests\Windows only).
  Linux: 3T/3S dormant; SuiteSkipRosterTest 28T/201A; guards 142T/10312A;
  DocFigure 92T/4248A; family 242T/915A; PtyRunToolTest 12-legit 12T/93A.
  Skip-law discrimination proven LOUD via forced-run mutation (cp+md5 clean).
- SLICE 2c COMMITTING NOW — docs prose: README banner flips the false "Windows
  is not supported" claim to the experimental contract (chat/TUI on PHP 8.4+ /
  Win10 1809+ / Git-for-Windows bash; serve stays Linux/macOS/WSL); Install
  Requirements gains the ConPTY + no-cmd.exe + WSL-for-server prose;
  docs/SERVER.md ## Windows section names the CI smoke tier and keeps the
  serve-refusal truth. All three sites verified unpinned by any grep before
  edit; SERVER.md region is OUTSIDE the six generated markers and
  gen-protocol-schema --check passes rc0 WITH the edit (regen-stable; no trio
  drift). Doc guards green: drift family 325T/45730A, DocFigure 92T/4248A,
  ReadmeRoster/EnvRoster/TrustKey/ConfigWrite/KeyBinding/SettingsTier all in
  the pass set. Zero lang keys moved; zero word-numbers inherited (no numeral
  arms touched).

## LANE CLOSED (4 commits, porcelain clean)

5d7e0f615 PtyBackend seam → 74313e6f9 Windows gate/spawn arms → 549c92cb0
smoke trio + roster + CI job → 642cdde3a truth docs. All cat-file'd; author
law verified per commit (%ae/%ce counts 1/1, zero Co-Authored). Base→tip: 13
files +934/−39. Zero-diff proofs: SandboxFactory/ScriptHook/Bash/HookManager
blobs, src/Media/**, lang/** ALL untouched (name-only diff count 0). Master
at close 77beb227d (moved 3x under lane; NOT rebased — welder cherry-picks).
§7 NEW TEST FILES w/ live Linux counts: tests/Support/PtyBackendTest.php 5T
(14A, live POSIX, no skips), tests/Support/InteractiveWindowsArmsTest.php 7T
(35A, live seams, no skips), tests/Windows/WindowsBashSmokeTest.php 2T + tests/Windows/WindowsPtyRunSmokeTest.php 1T — the Windows trio runs 3T/0A/3S here (dormant rostered skips). Refused/deferred seams:
PtyRelay.php:270 third Pty::open site (6.j file, DO-NOT-TOUCH), Bash.php:697
stale comment (zero-diff blob, welder-owned), ConPtyApi vendor docblock nit.
Foreign-red confirmations: StderrEmitterCensus 2F + GlobDialect figure 1F
both stash-pair base-proven. No durations rows, no suite-figure edits, no
protocol trio drift (regen --check rc0 with docs edit).
