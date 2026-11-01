# W38 wave review — round 1

Anchor (re-derived from disk at start and re-read at end): master = 16d1a94e85764ea408c5eedb5dad33777238e749 (NO mid-review advance observed).
Members per LANDED blob §4 W38 section + §1 table `| W38 (LATE) | 2 | C-TUI-6.a · C-TOOL-6.h |`: C-TUI-6.a, C-TOOL-6.h. Both welded.

## Duty 0 — eligibility (PASS)
Heading `#### ✅ 3751c1fc4,5204adf95,0fc37619a C-TUI-6.a` at blob :3917; `#### ✅ 97b2787d4,adf70e27f,1fa7ae06d,99ca97f4c,7c5d46c32,df04f0f97,e06b5dbfc,9a79f6423,85bfba37f C-TOOL-6.h` at blob :3928 (read from `git show master:plan_comparison.md`). cat-file -t = commit AND merge-base --is-ancestor == 0 for ALL 12 shas (3/6.a + 9/9 6.h). Chain order proven: 3751c1fc4 parent = b45117223 (6.a lane base, == master at its weld); 97b2787d4 parent = 13f9a1829 (6.a close record) — matches both marks' moved-master narratives.
RECORD-ONLY: the C-TOOL-6.i ✅ mark (:3970) self-labels "W38 LATE member" but sits INSIDE the W39 section; §1 table (W39 row lists 6.i), §6 roster (`C-TOOL-6.i (W39)`) and section placement agree 6.i is W39. Wording NIT against the 6.i mark (W39 account), not W38 content — see NIT-1.

## Duty 1 — cross-lane interactions (PASS)
6.a (Windows process layer: Bash.php + Support trio + Background) ∩ 6.h (Host/ClientTools + Backend/PendingClientCall + Runtime fold + PermissionGate arms) share NO file by the 41-path span name-list. Union health on the merged tree:
- Bash.php double-quoted `"bash -c "` form survives at blob count 1 — the PermissionGateScopedWriteTest :479-leg pin GREEN in re-run (78T/85A OK).
- PermissionGate prefix trio intact at current blob: const USER :864 / CLIENT :877; isWriteCapable arms user__ :1464 BEFORE client__ :1465; verbatim mcp__ literals :1076/:1883 (client__-before-mcp__ ordering preserved).
- No duplicate keys: en.php ZERO diff across the whole b45117223..85bfba37f span (name-diff 0). No catalog position collision (6.h added BuiltInToolCorpus rows only; corpus green). TreeWideGuardRoster + DuplicatedTestHelperDrift co-run green at tip for both accounts (below).

## Duty 2 — merged-tree targeted tests + guards (own content: ALL GREEN)
cwd=candy-code -c phpunit.xml, env -u LINES -u COLUMNS, plain pipe, anchored stems, PtyRunToolTest::testExpectMiss excluded from every filter (no filter matched it):
- 6.a own five-file combined: `OK ... Tests: 64, Assertions: 141` — EXACT vs mark.
- 6.h own three (non-fork): `OK ... Tests: 53, Assertions: 136` — EXACT; fork e2e alone: `OK ... Tests: 7, Assertions: 40` — EXACT (net-new 60T/176A consistent).
- PermissionGateScopedWriteTest::: `OK ... 78T/85A` — EXACT.
- (SessionHostTest|TurnPathContractTest)::: `OK ... 27T/151A` = 18/83 + 9/68 EXACT sums.
- BARE TreeWideGuardRosterTest: `OK ... 17T/4003A` (A-drift vs weld-era 3949/3957 = later-weld merged drift, disclosed, counts stable, ZERO own rows claim holds).
- BARE DuplicatedTestHelperDriftTest: `OK ... 14T/204A` — EXACT.
- PAIRING (BashSandboxTest|BubblewrapHardeningTest): `OK ... 39T/192A` — EXACT, cure db1441a61 holds on the tip.
- LayeredSettingsTest::: `OK ... 99T/423A` 0F (+10A later-drift vs 413-era, disclosed).
- "PHPUnit Deprecations: 2" ambient on every line (accepted house shape).
FOREIGN RED SETS AT THIS ANCHOR (exact report, cure-NONE for W38 — neither is a W38 lane finding):
1. NEW (not on the accepted ledger): (DescriptorInheritanceGuardTest::|ReadPathCensusTest::) → `FAILURES! Tests: 34, Assertions: 5971, Failures: 1` = ReadPathCensusTest::testTheCensusIsDerivedFromSrcAndFullyClassified, stale MediaMethods rows (expected fgc 1/fopen 2, actual 2/3). PROVEN post-W38: src/Protocol count 0 in the W38 span; the extra sinks arrive with C-TUI-6.n 1784d207f (W40-era); the 6.n mark claimed the co-run GREEN — route to the W40 wave-loop/gate ledger, NOT curable here (MediaMethods/roster files are in the voice/protocol lanes' claimed space while C-TUI-6.h builds).
2. NEW: (RuntimeTest::|BuiltInToolCorpus) → `FAILURES! Tests: 209, Assertions: 799, Failures: 3` — all three in the shouldPromptIdleCompaction family (RuntimeTest.php:1990/:2008/boundary), reproduce 3/3 ISOLATED (not co-shard). W38 span touched RuntimeTest (+4 prefix lines) but git log -S 'shouldPromptIdleCompaction' over b45117223..85bfba37f = EMPTY — the idle-compaction code/tests are W38-untouched. Post-W38 src/idle suspects by -S: e9bb3620e (C-TOOL-6.b, W39-era) and 543364946 (C-TOOL-6.k, W39-era). Route to W39 accounts/gate; same in-flight-collision bar (Runtime.php hub).
Accepted-foreign shapes from the marks (staleness pair, GlobDialect assert-NEITHER, StderrEmitterCensus PtyRelay 2F, ForeignAgentPreset 1F, LaunchContextDelegates, keepWarm/KeyHelp/HelpTest-tier/BinDispatch/AttachPaging/OneSided/SettingsEditor/ChildStderrCapture/BootstrapLaunchFormat): NOT re-run per targeted-only law; W38 own content moves none of them; the two NEW families above are the delta this anchor adds to the program ledger.

## Duty 3 — generated files (as owed)
`php tools/gen-command-docs.php --check` rc0 · `gen-tool-docs` rc0 · `gen-settings-doc` rc0 · `scripts/gen-protocol-schema.php --check` rc0 (cwd candy-code). tools/check-path-repos.php / check-child-lifetimes.php / check-one-type-per-file.php: EXECUTION DENIED by this reviewer's shell permission map at every corrected form — UNVERIFIED-BY-REVIEWER, disclosed honestly (per-weld rc0 claims stand in the marks; W38 spans add zero new types beyond the accounted layer). DIST: W38 members moved ZERO web sources (candy-code-web name-diff 0 over the span) ⇒ rebuild UNOWED — checked, NOT rebuilt. trio/suite-figure.json/durations.tsv: span count 0 — weld hygiene (no hand-edits, forbidden files untouched) holds.

## Duty 4 — §4 claims vs landed (PASS)
Per-pick numstat re-derived from git show: 6.a 8 files +776/−13, 4 files +982, 3 files +33/−2 — EXACT vs mark. 6.h pick1 10 files +1337/−3 EXACT; r1 fix +4/−3 EXACT (PendingClientCall docblock). Span-level: 41 paths total b45117223..85bfba37f; forbidden/generated files 0/41; en.php/web 0/41. §7 hand-offs (6.a five durations rows, 6.h four) correctly LISTED-not-fixed at weld (durations.tsv never touched) — battery owns.

## Duty 5 — commit law (PASS)
All 12 wave commits: %ae = detain@interserver.net AND %ce = detain@interserver.net (separate greps, 12/12 each); Co-Authored-By across all 12 full messages: 0.

## Duty 6 — residue (PASS)
git worktree list = main + /home/sites/cc-cmp-C-TOOL-6.g (review r1) + /home/sites/cc-cmp-C-TUI-6.h (building) — EXACTLY the two in-flight lanes the dispatch flags-not-charges (also survivors per both weld records). NO cc-cmp worktree or cc/ branch for C-TUI-6.a or C-TOOL-6.h.

## Duty 7 — §3 (PASS)
Landed blob §3 row `| W38 (LATE) | 2 | C-TUI-6.a · C-TOOL-6.h |` carries NO ✅ — wave not yet flipped, correct pre-loop state.

## Findings
- MINOR-1 (report-only, FOREIGN, non-blocking for W38): ReadPathCensus MediaMethods roster staleness 1F at tip — attributed by proof to C-TUI-6.n-era; lead routes to W40 loop/gate ledger (accepted-foreign roster grows by this NEW family).
- MINOR-2 (report-only, FOREIGN, non-blocking for W38): RuntimeTest shouldPromptIdleCompaction 3F deterministic at tip — post-W38 attribution (6.b/6.k suspects); lead routes to W39 loop/gate.
- NIT-1: C-TOOL-6.i ✅ mark prose self-labels "W38 LATE member" while §1/§6/section-placement put it in W39 — one-word closeout wording fix on the 6.i mark (W39 account; plan §4 wording only).
Every W38 duty PASSES on the merged tree; zero findings touch W38 member content. §3-flip license rests on the lead's W33-precedent ruling (foreign-attributed report-only items do not block a wave whose own account is clean).

WAVE VERDICT: FINDINGS C=0 M=0 MINOR=2 NIT=1
