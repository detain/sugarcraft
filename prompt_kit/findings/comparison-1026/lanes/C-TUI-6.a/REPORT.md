# C-TUI-6.a — Windows process layer (bash quoting, bash resolution, tree kill)

LANE: /home/sites/cc-cmp-C-TUI-6.a  branch cc/C-TUI-6.a  base b45117223 (master at cut == at final commit; zero picks owed, never rebased).
PICKS (cat-file'd in the citing shell, both = commit):
- 6db0d00cb  C-TUI-6.a: POSIX argv quoting, Windows shell resolution, taskkill tree-kill layer  (8 files, +776/−13)
- 1efe40828  C-TUI-6.a: pins for the Windows process layer  (4 files, +982)
Author/committer: %ae and %ce each grep-detain count = 1 per pick; zero Co-Authored-By trailers. porcelain 0 at end. plan_comparison.md untouched (no §4 marks — welder's job).

## Design conformance (block re-read verbatim from design-tui.md:696-730; §6.3 ruling 'a — Windows shell' ADOPTED as written)

Root cause 1 QUOTING — PosixShellQuote::quote() replaces escapeshellarg at exactly the three bash-builder sites named: ProcessContainment::cdGuard() (src/Support/ProcessContainment.php:624), Bash::runCommand() (src/Tools/BuiltIn/Bash.php, the 'bash -c ' build), BackgroundShellRegistry::buildWrapper() inner (src/Background/BackgroundShellRegistry.php). POSIX bytes identical (pinned live vs the escapeshellarg oracle, 29T/32A corpus incl ' % ! " newline tab CJK backslash empty; NUL throws ValueError, measured parity — 8.3 escapeshellarg throws, does not strip).
Root cause 2 RESOLUTION — WindowsShell::locate() splits PATH on ';' honouring PATHEXT (default .COM;.EXE;.BAT;.CMD), is_file-only accept (Windows has no executable bit); bash() = PATH rung then %ProgramFiles%\Git\bin\bash.exe rung; none ⇒ '' and callers refuse with BASH_MISSING_NOTICE naming Git for Windows; cmd.exe NEVER consulted (structural pin: quoted 'cmd.exe' literals absent from WindowsTaskKill/WindowsShell; refusal notice names it only to forbid it). locateOnPath() (ProcessContainment:1519) delegates on Windows family — 18+ callers (git/gh/bwrap/secret-tool/bash…) inherit the fix.
Root cause 3 SPAWN SHAPE — Bash sync path spawns proc_open ARRAY [bash.exe,'-c',$script] via ProcessContainment::spawnSpec (unchanged passthrough when prefix==[]); BackgroundShellRegistry::start() Windows arm spawns [bash.exe,'-c',$wrapped]. On Linux the arms stay dormant (family-gated) — exercised here as data via the Keychain-pattern injected seams.
Root cause 4 TREE KILL — terminate/terminatePid/killTree/killTreeAsync Windows arms route through WindowsTaskKill::tree($pid) = `taskkill /T /F /PID <pid>` run as a short-lived child through spawnSpec with bounded drain (CHILD_BOUND_SECONDS=5.0, hrtime deadline, proc_terminate(9) at deadline, in-function proc_close ⇒ scanner classifies short-lived — NO DescriptorInheritanceGuard row needed, gate-proven) BEFORE any proc_terminate fallback; grace is already-maximal there (no tree-reaching soft signal exists on Windows — doctrine in docblocks, E646-clean: bounded kill window, no blanket timers). terminatePid/killTree no longer return false silently at the ext-posix gate on Windows. ProcessTree stays Linux-only (Windows arms never consult it — pin: locateOnPath/kill routing tests). grouped:false background rows: already honest at base (groupsSpawns() false via empty detach prefix — verified :229-256), unchanged.
files.create — all 3 landed. files.modify — all 4 landed (2 census rows CONDITIONAL, both resolved 'no row' — gate-proven: ReadPathCensus 34T/5957A and DescriptorInheritanceGuard green without edits; new src uses no FUNCTION_SINKS reader). tests create — all 5 landed. Keep-green — ProcessTreeKill/Async 25T/85A, BackgroundShellRegistryTest 29T/228A (full, incl the foreign O_TRUNC item green this run), BashTimeoutTest in 36T/94A family run.

## Deviations (disclosed)

1. src/Tools/Concerns/CapturesProcessOutput.php runCaptured() widened string → string|array — type-only enabler (Bash's only route to proc_open; all ~20 callers pass strings unchanged; runCapturedInteractive stays string until C-TUI-6.d).
2. ProcessContainment Windows-arm pins consolidated into WindowsTaskKillTest (design named no ProcessContainment test file; arms are pure routing into the same seams).
3. BackgroundShellRegistry null-device made kernel-derived (DIRECTORY_SEPARATOR) in BOTH branches instead of adding a Windows-only special case — real hosts behave identically; lets the injected-family spawn EXEC on this Linux runner (the design's Windows-leg posture: adapters tested by shape, quoting/resolution/tree-kill as data).
4. WindowsTaskKill gained setChildBoundForTesting seam (bound table pin + wedge leg without a 5s test tax).
5. WindowsShell: explicit-extension query skips PATHEXT walk (Windows' own rule — ghost.exe.exe trap pinned); no lowercasing (fixtures spell injected-PATHEXT case; real-Windows case-insensitivity UNCONFIRMED-BY-RUN).
6. Bash sandbox-wrap Windows note is comment-only: no SandboxBackend can prove a jail on Windows so the refusal short-circuit already prevents wrap (C-TOOL-4 owns that seam; untouched).

## Gates (cwd=candy-code, -c phpunit.xml, env -u LINES -u COLUMNS plain pipe; house 'PHPUnit Deprecations: 2' pre-existing in EVERY filter incl unrelated files — not mine)

- (PosixShellQuote|WindowsShell|WindowsTaskKill|BashWindowsSpec|BackgroundShellWindowsArm)Test = 63T/139A OK  [non-zero ✓]
- ProcessTreeKill|ProcessTreeKillAsync|BashTimeout|BashMissingRoot = 36T/94A OK
- filter ProcessContainment = 12T/46A OK; + kill/async = 25T/85A OK
- DescriptorInheritanceGuard|ReadPathCensus = 34T/5957A OK (zero rows needed — design conditional resolved)
- BackgroundShellRegistryTest = 29T/228A OK
- InteractiveSpawnCdGuard|CapturedProcessDeadline|ProcessOutputCapture|ShellOutput = 27T/92A OK
- Bash* nine-stem family = 135T/397A OK; (BashSandbox|BubblewrapHardening) co-run pairing = 39T/192A OK
- killTree consumers (ChatToolCancel|ParallelDeadline|EngineBackendTeardown|EngineBackendReap) = 16T/55A OK
- (BackgroundShellInteractive|BackgroundShellWallFallback|ToolSecurity) = 97T/305A OK
- BARE TreeWideGuardRosterTest = 17T/3949A OK; BARE DuplicatedTestHelperDriftTest = 14T/204A OK
- php -l clean on all 12 touched files; tools/check-one-type-per-file.php rc0 (62 libs 3367 files 0 problems); tools/check-child-lifetimes.php rc0 (33 sites 9 findings 9 accounted 0 problems)
- §1.8 cascade: en.php diff EMPTY (zero new keys — all refusals raw-English per sibling precedent) ⇒ CliLangCatalogueTest/LangParityTest NOT run (not forced), BOTH protocol mirrors untouched (git diff empty on docs/protocol/web-strings.en.json + candy-code-web/src-web/lib/strings.en.generated.ts); no sprintf added ⇒ StderrEmitterCensus own-file legs clean; residue grep (if \(false|&& false|\|\| true) = 0 before both commits.

## Foreign-red adjudications (cure NONE)

- StderrEmitterCensusTest::testTheDirectFwriteStderrRosterIsUnchanged + ::testTheInheritedCensusStillAgreesWithTheScan — moved row is src/Background/PtyRelay.php (+9 fwrite sites) ONLY; file clean in my porcelain, authored by welded 75174ea40 (C-TOOL-6.j) — 38+9=47 is the same debt. Base-carried, cert-foreign from the diff itself.
- BootstrapLaunchFormatConstantsTest::testEveryDocPageQuotingAPromotedFormatIsOnTheReadersRoster — PROJECT_TIER doc-quote-set drift (README.md leg); roster-listed doc-leg; my lane edits no docs.
- GlobDialectDifferentialTest 1F — docblock 310,245 vs derived 560×556. Proved NOT mine twice over: token-scan of all 8 new files admits ZERO glob-shaped literals; the 11 admitted literals in my-edited ProcessContainment exist identically in base blob b45117223; zero quoted-wildcard tokens on any diff line. Per brief: assert NEITHER number, cure NONE.
- ReadmeSuiteFigureDrift staleness pair + suite-figure.json/durations.tsv untouched (lane-forbidden; §7 list below).

## Windows-leg posture (EXEC-as-data vs static-cert vs UNCONFIRMED-BY-RUN)

EXEC-as-data on this Linux box: quoting corpus + byte-identity oracle; ';' PATH walk both orders + PATHEXT candidate table + bash() rung ordering (temp-dir fixtures); treeArgv tables; runner-refusal pid≤0; exact-argv runner routing + bool passthrough; real-drain EXEC of /bin/true|/bin/false via private runTreeKill reflection (deadline leg: bound=0.4 seam + /bin/sleep 2 ⇒ false in 0.3..1.8s); ProcessContainment terminate/terminatePid/killTree/killTreeAsync arms via seam (real spawned child's pid observed inside taskkill argv); Bash Windows sync path = REAL proc_open of [fixture-bash.exe,'-c',script] (argv count 2, payload '%PATH% !x "q"' byte-intact, cdGuard leg); registry Windows arm = REAL spawn, wrapper rode -c argv whole (argc=2, arg=[-c], SHELL_INNER/%PATH%/!x verbatim in marker); POSIX legs of both.
static-cert: runTreeKill spawn<drain<proc_close structural order + single proc_open site + quoted-cmd.exe absence; ProcessTree Linux-only by construction (existing pins); detachedSpawnPrefix Windows []-prefix leg — owned by ProcessContainmentPhpSetsidTest (:116/:147-149, untouched, green in the 12T run).
UNCONFIRMED-BY-RUN (impossible on Linux, stated honestly): taskkill semantics against a real Windows process tree; PATHEXT case-insensitive file matching; NUL null-device consumption by proc_open on a real Windows host (kernel-derived line runs '/dev/null' here); cmd.exe absence on real Windows (proven only as absence-of-code).
Zero Windows-only code EXECUTed against real Windows; zero sugar-pty/ConPTY files touched (C-TUI-6.b/6.c substrate reused at the doctrine level only — my layer is the process/tooling half, no FFI anywhere: zero cdef).

## Collisions

C-TOOL-6.h (src/Tools/** engine): my Bash.php/CapturesProcessOutput hunks sit inside the runCommand/trait regions named by THIS design block only; no Bootstrap tool-assembly or LaunchContext seam was demanded ⇒ no STOP needed. Reviewer lane cc/C-TOOL-6.g files untouched. C-TUI-6.d seam respect: interactiveAvailable()/interactiveSpawnCommand()/startInteractive() untouched (grep-verified absent from my diff).

## §7 — NEW *Test.php files (lead/gate mints durations rows; live figures from green runs)

- candy-code/tests/Support/PosixShellQuoteTest.php — 29T/32A
- candy-code/tests/Support/WindowsShellTest.php — 13T/26A
- candy-code/tests/Support/WindowsTaskKillTest.php — 13T/50A
- candy-code/tests/Tools/BuiltIn/BashWindowsSpecTest.php — 5T/14A
- candy-code/tests/Sessions/BackgroundShellWindowsArmTest.php — 3T/17A
(Σ 63T/139A matches the combined-family gate run.) No existing test file grew methods; no *Test.php deleted; suite-figure re-pin will absorb +63T.

## DoD check vs design

cdGuard/runCommand/wrapper all quote host-independently ✓; bash resolution honours ';' + PATHEXT with Git-for-Windows rungs ✓; refusal names install Git for Windows, never cmd.exe ✓; Windows spawns are argv arrays through spawnSpec unchanged ✓; all four kill paths tree-kill first with bounded escalation ✓; ProcessTree never consulted on Windows ✓; grouped:false stays honest ✓; five test files + both census conditionals gate-proven no-row ✓; zero doc edits (C-TUI-6.d owns platform docs) ✓.

STATUS: COMPLETE — 2 picks on cc/C-TUI-6.a, porcelain 0. background=true confirmed.
