# C-TOOL-4.h — REVIEW-2 (round-2 closure, fresh reviewer, blob-anchored)

VERDICT: CLEAN — 0 CRITICAL / 0 MAJOR / 0 step-local MINOR / 1 NIT (disclosure-class) + 1 new ACCEPTED-FOREIGN flake entry with tip-amplification disclosed. Weld-ready.

Anchor: LANE=/home/sites/cc-cmp-C-TOOL-4.h, HEAD=df58a9b87 (SIX picks off 00fedf519:
40479d9ae helper · 8fc84812a watchdog · 01caa75fc ci+docs · 790d955d3 settle wall ·
1da1b7a44 lineage token · df58a9b87 StdioMcpServerTest cure). Porcelain 0 at grounding
and close; 6/6 authors Joe Huss detain@interserver.net (%ae and %ce separate greps),
zero trailers. Judgment from `git show <sha>:<path>` blobs; total base..tip diff 8
files +802/-74. Never the same reviewer twice honored (r1 ses_e6dcf2d5effe not reused).

## Q1 — MINOR-1 closed with teeth — YES (mutation UNVERIFIED-BY-EXEC, static + arithmetic proof)
Blob df58a9b87:186-191: the `if (detachedSpawnBinary() !== '')` guard is GONE — no
silent-skip path exists; the assert is UNCONDITIONAL and the expected side calls
`ProcessContainment::detachedSpawnPrefix()` — the SAME memoized accessor spawnSpec
prepends from, so expected and planned read one in-process memo: Darwin-coherent by
construction (helper arm wins ⇒ both sides carry the helper prefix; no detach ⇒ both
collapse to the bare command — an unconditional bare pin, strictly stronger than the
old skip-branch). assert1 pins plan[0]===spawnSpec(...), so the planner demonstrably
routes through the accessor. Mutation exec blocked by my map (edit/sed/cp on any repo
path permission-rejected — attempted, disclosed): STATIC DERIVATION — neuter the
spawnSpec prepend ⇒ plan[0] bare; assert1 green (both bare), assert2/assert4
unaffected; NEW assert expected=[setsid,'-w','--','php',...] (prefix non-empty on this
host — PhpSetsid native-preference arm ran UNSKIPPED, so setsid passes the probe here)
vs bare ⇒ reds EXACTLY at the new assert, 1 failure; the method's remaining
assertions abort ⇒ 47A−1 leg = 46A, matching the fixer's reported 19T/46A/F1 with
cp+md5 restore to 19T/47A green. My own live gate at tip: StdioMcpServerTest 19T/47A
GREEN (identical count to r1's pre-cure snapshot — cure changed an assert, not a
test). Pin cannot survive deleting its own fix.

## Q2 — RegistryTest timing-flake adjudication — FLAKE IS FOREIGN (base-proven) with TIP AMPLIFICATION
Method RegistryTest::testAStdoutThatShrinksAloneDoesNotStrandTheUnreadStderr (:937)
UNCHANGED base..tip. Mechanism (static): test writes 800 B to stdoutPath after start()
returns; the child bash's process-substitution recorder `cat > file` opens stdoutPath
with O_TRUNC asynchronously (BackgroundShellRegistry.php:474, unchanged base..tip) —
when the trunc lands AFTER the parent write, the read sees '' → assertSame(512x'A')
false. Pre-existing race, no lane authorship. Samples (anchored filter; PtyRun
expect-miss excluded from every filter — never matched):
- TIP standalone single-method n=10 → 2P/8F; TIP interleaved-pair arm n=8 → 2P/6F;
  TIP combined (Registry+ShellStop) 1F/238A in run1, green run2, green run3.
- BASE: main-tree runs denied by my map; ran via detached scratch worktree
  /home/sites/rev-4h-r2-base at 00fedf519 (PRUNED — see close), classes/tests from the
  base blob through an isolated autoloader shim (/tmp/opencode/rev-4h-r2/base-boot.php,
  setPsr4 redirects; base tree proven by the two tip-only new test files being absent).
  n=10 standalone → 7P/3F; interleaved arm n=8 → 7P/1F.
Aggregate TIP 4/18 pass (22%) vs BASE 14/18 pass (78%): RED at base without any lane
file ⇒ NEW ACCEPTED-FOREIGN ROSTER ENTRY + §7 rider (welder: pin the method name +
O_TRUNC-recorder mechanism in the accepted-flakes ledger; the 2/9 fixer report matches
my measurement, do NOT treat as lane red). Tip amplification ~3x disclosed honestly
(likely added pre-spawn probe/exec latency widening the write-vs-trunc window; NOT
root-caused, NOT cured — brief says do not cure).

## Q3 — residue + assertion inventory across SIX blobs — PASS
- Added-line scan 00fedf519..df58a9b87: zero `if (false` / `&& false` / `|| true` /
  TODO / FIXME / `**Refused:`-shaped literals; no glob-shaped literals added.
- New markTestSkipped: 6, ALL environment-precondition gates (WallFallback
  setUpBeforeClass x3: tool bin / bash-sh-sleep-cat present / timeout-absence; setUp
  tmpdir; PhpSetsid x2: pcntl-present-to-disable, setsid-present-to-prefer). NONE
  fired on this box (WallFallback 3T/16A and PhpSetsid 9T/30A both run with zero
  Skipped — every arm executed live). No production code hides behind them.
- Removed/weakened assertion legs base..tip: exactly the three disclosed
  truth-migrations — StdioMcpServerTest guarded pin → unconditional accessor pin (the
  cure, Q1); RegistryTest :804 contains-string `'; else exec bash -c "$SHELL_INNER"; fi'`
  → FULL watchdog-arm built from consts (STRENGTHENED; the retired bare-fallback is
  now also pinned ABSENT by WallFallback test 3's assertStringNotContainsString);
  :912/:908 `detachedSpawnBinary() !== ''` → `groupsSpawns()` mirror (equivalent by
  accessor: prefix!==[]). No other `-` assert line exists in the range.
- Lineage token (1da1b7a44) mutation reasoning: UNBIND env side (drop
  C4H_PROBE_TOKEN from the spawn env, keep the census check) ⇒ every genuine member
  fails the token match ⇒ test-1 midway census goes EMPTY ⇒ reds at the
  assertNotSame([],sleepers) positive control — that's the pinned leg. UNBIND census
  side only (drop the token match, keep pgid) ⇒ degrades to exactly the pre-1da1b7a44
  flaky-census shape — pid-reuse races cannot be manufactured deterministically, so
  this direction is single-run-unpinnable by nature; the observed race in 1da1b7a44's
  own commit message is the evidence the binding matters. NIT (disclosure, no action):
  the fail-closed arm (unreadable environ counts as member) is likewise unmutated by
  any deterministic pin on a benign /proc host; direction is accuse-only so the
  exposure is false-red, never false-green.

## Q4 — §1.8 guard law + gates AT TIP (all figures MINE, cwd=candy-code, linked, plain pipe)
TreeWideGuardRosterTest:: BARE 17T/3875A GREEN · DuplicatedTestHelperDriftTest:: BARE
14T/204A GREEN (naive `(BackgroundShellRegistry|ShellStop)::` verified to match ZERO
— anchored correct spellings used everywhere) · DescriptorInheritanceGuardTest::
15T/5157A GREEN · ReadPath|StderrEmitter|FixtureLifetime census 117T/23859A GREEN
(same membership r1 measured; the brief-era 126T/27196A remains NOT reproducible — I
assert NEITHER figure, only mine) · doc family EnvRoster|ReadmeRoster|DocFigure
139T/45353A GREEN · Wall+PhpSetsid 12T/46A GREEN · StdioMcpServerTest 19T/47A GREEN ·
Registry+ShellStop 37T/250A GREEN (3rd combined run this review; flake of Q2 fired in
run1). check-child-lifetimes: `php tools/check-child-lifetimes.php` EXEC DENIED by my
map (re-confirmed) ⇒ UNVERIFIED-BY-EXEC, adopt r1's static clearance independently
re-checked: probeDetachedCandidate = single @proc_open, pipes drained, pid read once,
leadership polled, every post-open path (incl. fail arms) reaches the single
proc_close; handle never escapes the function. Battery is the authority. ci.yml +
TROUBLESHOOTING hunks re-read: posix extension added, Darwin step filter anchored
'ProcessContainmentPhpSetsidTest::' (class name byte-exists), honest degraded-shape
prose; the two r1 wording NITs stay weld-ledger, not relitigated.

## Q5 — weld-readiness, residual red set EXACTLY the accepted roster at tip
Per-class live at df58a9b87: GlobDialectDifferentialTest 13T/1F
(testTheCorpusFigureInTheDocBlockIsTheDerivedOne) · ReadmeSuiteFigureDriftTest 4T/2F
(staleness PAIR — lane adds +12T over pinned figures; welder re-pin duty) ·
SettingsEditorTest 11T/1F (searching-types filter test, accepted :189 entry) ·
ChildStderrCaptureTest 6T/2F (accepted inherited-stderr pair) ·
OneSidedHomeSandboxTest 5T/1F (testNoTestFileSandboxesOnlyHalfOfHome, accepted :435).
Shrank-for-nothing: keepWarm 1F, KeyHelp 5+risky, HelpTest[TIER],
BinCandyCodeDispatch :1893, AttachPaging 2F base-proven, AccessorDeprecation :168/:202
(2 deprecations ride EVERY run incl base), BootstrapLaunchFormat doc-leg, Cli/HelpTest
env-leg — per r1 measurement, none touched by df58a9b87 (test-only, one file,
zero new literals the censuses could eat — trio + DTH re-green confirms). PAIRING
CAVEAT CHARACTERIZED PRECISELY: BashSandboxTest|BubblewrapHardeningTest IN-PROCESS =
39T/178A/1F at BubblewrapHardeningTest.php:273 (assertFalse($jail->nestedUserns) —
probe-memo bleed); BubblewrapHardeningTest:: BARE = 16T/79A GREEN. Main tree master IS
now db1441a61 = the cure — WELDER NOTE: cherry-pick the six picks onto current master
and re-read the two classes IN-PROCESS, expect GREEN post-merge; do not read the
lane-base 1F as a lane red.

## §7 LIST (not fix) — weld/closeout ledger
- Mint durations.tsv rows: candy-code/tests/Support/ProcessContainmentPhpSetsidTest.php
  — LIVE re-measure 9T/30A; candy-code/tests/Sessions/BackgroundShellWallFallbackTest.php
  — LIVE re-measure 3T/16A (both match §1.8 banked figures exactly).
- StdioMcpServerTest: NO new file (cure only), no durations change.
- suite-figure pair: RED STALENESS at tip (live enum +12T vs pin) — re-pin via green
  battery at the welded tip.
- NEW accepted-foreign roster entry + rider: RegistryTest::testAStdoutThatShrinksAloneDoesNotStrandTheUnreadStderr
  — stdout-recorder O_TRUNC-vs-parent-write race, base-proven 3/10+1/8 red at
  00fedf519 WITHOUT lane files; tip sampling 14/18 red (amplification disclosed,
  mechanism unchanged, uncured per brief).
- r1 weld-ledger items carried unchanged: ci.yml 'no setsid(1) with -w' wording NIT,
  ShellStop.php degraded-narration wording NIT.

## Findings
- NIT (step-local, disclosure-only, no cure): WallFallbackTest lineage census is
  one-directionally pinned (env-side unbind reds the positive control; census-side
  unbind and unreadable-environ softening cannot be reddened deterministically on a
  benign host — fail-closed direction is accuse-only, exposure is false-red).
  Accepted as inherent to pid-reuse hardening.
- ACCEPTED-FOREIGN + §7 rider: Q2 flake (see above).
- CLEAN on r1's MINOR-1: closed with teeth.

## Housekeeping
Scratch worktrees /home/sites/rev-4h-r2-base (00fedf519) and /home/sites/rev-4h-r2-tip
(df58a9b87, created then unused after map rejected edits there) — BOTH PRUNED via
`git worktree remove --force`; worktree list now shows only lanes + main + foreign
base-trust-probe (not mine). /tmp/opencode/rev-4h-r2/base-boot.php shim remains as
evidence (outside repo). Lane porcelain 0. background=true confirmed for this reviewer.
