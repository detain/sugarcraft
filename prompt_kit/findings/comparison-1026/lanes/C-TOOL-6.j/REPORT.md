# C-TOOL-6.j — PTY relay core for interactive background shells (W36, LATE)

Base 895b9d6fd (fresh cut; master unmoved at every grounding read so far; lane branch cc/C-TOOL-6.j).

## Slice S1 — relay + pathFor + census rows
- `src/Background/PtyRelay.php` NEW: final class, one type, strict_types. main():int over argv `-- dir id cols rows [capBytes]`; capBytes is a TEST-ONLY seam (production startInteractive passes exactly four; invalid/absent falls back to OUTPUT_LIMIT_BYTES — the seam can only narrow). Data flow (file:line at commit): .cmd read+unlink-before-spawn → Pty::open(cols,rows) → spawn ['bash','-c',cmd] env-null(controlling-terminal shim: setsid+TIOCSCTTY, child leads own session) → .pid written before first pump tick → pump loop stream_select(master,fifo): master→.out append + byte-count cap→KILL group+record 153; fifo(r+ never-EOF, non-blocking)→pty->write keystrokes; SIGTERM handler forwards 15 to -pgid, loop enforces 9 after TERM_GRACE_SECONDS; wall MAX_LIFETIME_SECONDS TERM→grace→KILL; child exited→bounded 2-quiet final drain (1 s cap)→flush/close→exit file LAST (capped?153:child->wait())→pty close. buildWrapper trailer/ulimit -f deliberately unused (SIGXFSZ would kill the relay, not the shell — docblock WHY). E646: process bounds only (wall+cap), no timer kills idle user work below the wall.
- `BackgroundShellRegistry.php`: pathFor() widened to public static with six keys (+ptyPid/.pid, in/.in, cmd/.cmd), both call sites (start, parseRow) updated to self::pathFor($directory,$id). Pure refactor — registry suite green unchanged.
- `tests/Support/ReadPathCensusTest.php`: + 'Background/PtyRelay.php|file_get_contents' 1 row (SELF_LOCATED .cmd), + 'Background/PtyRelay.php|fopen' 2 rows (SELF_LOCATED .out ab / .in r+).

### Gates (targeted, cwd=candy-code -c phpunit.xml, env -u LINES -u COLUMNS, plain pipe)
- ReadPathCensusTest|DescriptorInheritanceGuardTest: OK 34T/5945A (NON-ZERO).
- BackgroundShellRegistryTest|TreeWideGuardRosterTest|DuplicatedTestHelperDriftTest|SuiteSkipRosterTest|ChildStderrCaptureTest: 94T/4938A, 2F = ChildStderrCaptureTest::testNoChildLaunchedInScope... + ::testNoDirectoryWithAnUnguardedSpawnIsUnaccountedFor — PROVEN BASE-FOREIGN by git-stash probe (same 2F on pristine 895b9d6fd; offending roster rows are foreign Host/+Protocol/ test files welded by siblings, none mine; adjudicate-only per law).
- tools/check-one-type-per-file: 3359 files 0 problems. tools/check-child-lifetimes: 33 sites 9 findings 9 accounted 0 problems (src/Background relay file does not alter the answer — relay's spawn sites live inside sugar-pty, lib-rostered; registry's proc_open still the one ::start site until S2 adds ::startInteractive + its guard row).
- 2 PHPUnit Deprecations house-wide pre-existing noise (unrelated).

## S2 LANDED — 747aa6c44 "C-TOOL-6.j: startInteractive + send + teardown parity on the registry"
cat-file -t=commit (same shell); %ae grep -c detain=1; %ce=1; Co-Authored=0; porcelain 0 after.
3 files +337/-9: BackgroundShellRegistry.php (INTERACTIVE_COLS=80/INTERACTIVE_ROWS=24, private SEND_MAX_BYTES=4096, startInteractive doors+relay spawn, send() cursor-before+r+ FIFO, runKillLadder pty-child backstop via ptyChildPid() fresh read of <id>.pid, sweep regex +in|cmd|pid, sweepDeadLaunchDirs is_file||is_fifo, parseRow 7 new keys+interactive path/geometry validation), ReadPathCensusTest (+registry .pid row, +send .in row, sweep prose x2), DescriptorInheritanceGuardTest (::startInteractive count 1 row).
Gates: census pair 34T/5950A OK; registry+guards 88T/4532A OK (testAStdoutThatShrinksAlone flake seen once in an earlier combined run — ROSTERED foreign base-proven, cure NONE); tools one-type 62/3359 0, child-lifetimes 61 libs 33 sites 9 accounted 0 problems.
Fixed pre-commit defect from pre-death write: send() called observe() with 1 arg (signature requires row) — now observe($shellId,$row).
NEXT: S3 tests/Sessions/PtyRelayTest.php (direct-launch harness; cap seam 5th argv for the 153 pin; fifo writes non-blocking/timeout-guarded; stage live cmds like cat/sleep for input pins), then S4 BackgroundShellInteractiveTest + registry-test five-name cleanup list widening.

## S3 LANDED — 86a340422 "C-TOOL-6.j: fork the pty pump (session-leader claim law), read-deadline fix, per-chunk flush, relay pins"
3 files +619/-7: src/Background/PtyRelay.php (fork: parent supervises/forwards TERM/anchors session, pump member opens master+slave+shell; patrol both directions; POST_SELECT_READ_SECONDS=0.05 fixes PosixMasterPty::read 0.0-timeout-answers-null-without-looking; fflush per chunk so read()/sinkCapped() see the live file), BackgroundShellRegistry.php (startInteractive door +docblock name pcntl_fork), NEW tests/Sessions/PtyRelayTest.php (8T/80A 0F ALL-LIVE EXEC on this host: fifo round trip, stty geometry 30 100, exit 7, group TERM ends child, cap 8192 -> .exit 153, killed pump -> shell dies via tty_hangup + .exit stays blank (trio pre-created EMPTY by sinkFiles shape — contents-assert not is_file-assert), .cmd unlinked, bad-argv refusal).
ROOT CAUSE LEDGER: (1) session-leader slave-open auto-claims ctty -> shim TIOCSCTTY(0) EPERM code 3 (measured: member works, leader fails; setsid -w EXEC's so relay IS the leader) — fixed by fork, zero sugar-pty edits; (2) read(8192, 0.0) deadline semantics answer null WITHOUT looking; (3) buffered transcript invisible while running.
Gates: census pair ReadPathCensusTest|DescriptorInheritanceGuardTest 34T/5950A OK (no new sink occurrences: fread/fflush/posix_getppid not FUNCTION_SINKS); tools/check-one-type-per-file 62 libs 3359 files 0; tools/check-child-lifetimes 61 libs 33 sites 9 accounted 0 (pcntl_fork not a proc_open site); master 895b9d6fd unmoved.
Deviations now on disk (design ledger): (a) locateOnPath('bash') ?: '/bin/bash' absolute spelling (shim pcntl_exec no-PATH law); (b) optional 5th argv capBytes test-only, narrow-only (production passes 4); (c) relay forks pump (session-member claim law) + pcntl_fork registry door; (d) per-chunk fflush; (e) pin-6 blank-contents correction.
NEXT (S4): tests/Sessions/BackgroundShellInteractiveTest.php + BackgroundShellRegistryTest :734/:739 five-name cleanup -> eight (.in/.cmd/.pid) + final cascade.

## Finisher session (S4a + S4b) — 2026-10-31

S4a — NEW candy-code/tests/Sessions/BackgroundShellInteractiveTest.php, LIVE 12T/75A,
ALL EXEC (never-skip: refusal polarity = memo-injection of
interactiveAvailableMemo + finally reset; the three function_exists doors are
labelled UNCONFIRMED-static scan pins per the EXEC-or-label law).
Pins: refusal mints nothing (scandir root byte-identical); row shape
(interactive/cols/rows/ptyPidPath/inPath === pathFor); fork round trip (toArray ->
second forSession merge -> send -> token in .out); send refusals x4 (plain row,
settled row, 4097 bytes, foreign owner = the identical no-trace 'is tracked by
this launch' while owner-null types fine); cursor/read pairing ((string)C slice
grows to contain the token); humanInputs 2 + lastInputAt float; stop() kills
relay AND recorded .pid child (zombie-excluded /proc state pair, names
procStateChar/interactiveProcessUp — drift-law distinct); aged corpse FIFO + trio
swept by the next launch (in|cmd|pid suffix proof).
Root-caused during this slice: a send landing in the pre-pump window answers
ok against the sender's OWN r+ fifo fd and the bytes DIE when it closes (bytes
buffered pre-attach are lost when the last writer-fd closes — measured;
reproduced twice). The pins therefore await the <id>.pid record (written two
statements before the pump's fifo open) inside driveSend. Production edge is
NOTED for 6.k: a keystroke sent <10ms after startInteractive can be lost the
same way — 6.j's design never promised pre-attach buffering; report-only, no
src touched here.
In-step: TreeWideGuardRosterTest gained the two WALKS_A_DIRECTORY_THE_TEST_MADE
rows for the new tearDown walk (same commit via amend, 65196e347).

S4b — BackgroundShellRegistryTest flood-pin cleanup widened five names to eight
(.in/.cmd/.pid), comment flipped; buildWrapper outd/errd pins and the
next-launch-sweeps pin untouched. Commit a8628f131.

Gates (lane tip a8628f131): cascade
(Registry|PtyRelay|Interactive|ReadPathCensus|DescriptorInheritanceGuard|SuiteSkipRoster)
111T/6513A 0F; TreeWideGuardRoster bare 17T/3925A green; DuplicatedTestHelperDrift
bare 14T/204A green; ChildStderrCaptureTest = the two BASE-PROVEN FOREIGN fails only
(testNoChildLaunchedInScopeLeavesItsStderrOnTheSuites +
testNoDirectoryWithAnUnguardedSpawnIsUnaccountedFor — adjudicate-only honored);
tools/check-one-type-per-file 0 problems; tools/check-child-lifetimes 0 problems
(33 sites / 9 findings / 9 accounted — unchanged). 2x "PHPUnit Deprecations: 2"
is the pre-existing family (PtyRelayTest alone carries the same 2).
Porcelain 0; master unmoved at 895b9d6fd (re-checked at close).

## §7 new-test files (durations rows owed by the GATE, live counts)
- candy-code/tests/Sessions/PtyRelayTest.php — 8T/80A
- candy-code/tests/Sessions/BackgroundShellInteractiveTest.php — 12T/75A

MASTER MOVED during this finisher session: 895b9d6fd -> 52ae3e3eb (observed at
close; lane untouched by it — 5 picks on cc/C-TOOL-6.j base 895b9d6fd, tip
a8628f131, porcelain 0). Per brief law: no rebase performed; the welder cherry-picks
as usual.
