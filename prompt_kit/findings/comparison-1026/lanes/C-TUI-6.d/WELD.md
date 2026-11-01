# C-TUI-6.d — WELD

Welder: cc-cmp-welder (sole master writer). 2026-10-31.
Master before: 77beb227d (weld: C-TOOL-6.h — run-state fold). Marks before: 317 (python startswith count).
Dispatch precondition: own heading `#### C-TUI-6.d —` present UNMARKED at plan :3978 (present-UNMARKED verified; no phantom-reweld).

## Loop arc (record)

Builder landed 4 picks clean-first-run — NO phantom lineage here. r1 REVIEW-1.md (9,108 B, disk-statted by welder, brief byte-match):
verbatim `VERDICT: CLEAN — 0C/0M/1MINOR(step-local)/4NIT; WELD-ELIGIBLE: yes`. REPORT.md 8,000 B re-statted, read fully, re-stat quoted.
No FIX-1 exists: the single MINOR is an EVIDENCE-WORDING misname — REPORT.md:62 wrote "SeamCallSiteCensus"; by measurement the real
debt class is **StderrEmitterCensusTest** (2F, PtyRelay 6.j-debt 75174ea40). LEAD RULING: RECORD-ONLY, no fix round; already
corrected inside REVIEW-1; this weld cites the correction (name-by-measurement law — the misname is never repeated).
NITs adjudicated at weld: (1) Kernel32ConPty::supported() instead of design's ConPtyApi::supported() = CORRECT deviation (the
stat/version ladder carrying the promise; design cites the contract decl). (2) stale-comments report-only: PtyRelay.php:270 third
Pty::open site (6.j file, untouched) + Bash.php:697-700 "refuses Windows" wording (zero-diff blob, owner-side adjudication kept).
(3) co-run "Warnings: 1" pre-existing cross-class candidate, report-only. (4) CapturesProcessOutput catch-arm master-strand
cleanup: behavior-beyond-pure-reroute improvement, disclosed in code comments — accepted.

## Picks (explicit list read from LANE `git log 13f9a1829..HEAD`, cherry-picked in listed order, NEVER a range, no -x)

- 5d7e0f615 → 82e22a922 PtyBackend seam (NEW src/Support/PtyBackend.php pure delegation; PtyDriver + CapturesProcessOutput rerouted to PtySystemFactory pairs; drainPty → Contract\MasterPty)
- 74313e6f9 → 33915c3f9 Windows arms (interactiveAvailable via Kernel32ConPty::supported() stat/version NEVER-A-SPAWN; interactiveBashMissing()+BASH_MISSING_NOTICE refusals; vendor-closure truth-flip)
- 549c92cb0 → b7f830aae Windows smoke trio + SuiteSkipRoster consts/rows/windowsSmokeRuns() + .github/workflows/ci.yml candy-code-windows-smoke job
- 642cdde3a → df2613aff README banner/Install + docs/SERVER.md ## Windows prose (ACCEPTED trio-family deviation, see below)

Zero conflicts. Law at landed commits: %ae detain=4, %ce detain=4 SEPARATE counts, Co-Authored-By=0. Reflog: 4 cherry-pick
entries + 2 weld records, ZERO foreign commits mid-flight.

## Welder mechanics disclosure

The lead's index carried staged lanes/ files + .opencode/agents/cc-cmp-lead.md, which git cherry-pick refuses outright. Welder
ran `git reset -q` (index-only; ALL working-tree content preserved, zero --abort/--stash) and snapshotted the staged set to
/tmp/opencode/cc-cmp-weld-C-TUI-6.d/lead-index-before-reset.txt so the lead can re-stage if desired. Lead dirt otherwise left as found.

## Merged-tree duty proofs (by TEXT/name, at df2613aff)

- Union name list = exactly the lane's 13 files. ZERO touches: Bash.php, PermissionGate (src/Permissions/), lang/en.php + all locales, schema.json, generated.ts, dist, suite-figure.json, durations.tsv, Chat.php, Bootstrap.php, Runtime.php.
- Bash.php :535 double-quote `"bash -c "` PRESERVED (file untouched; live re-grepped).
- Permission-twin ordering user__→client__ untouched by name-diff (lane never touches PermissionGate).
- CapturesProcessOutput runCaptured :176 string|array widen (6.a) SURVIVED alongside the reroute (:535 PtyBackend::open, :537 slave()->spawn, :680 drainPty(Contract\MasterPty)).
- ci.yml: 0 removed lines; candy-code-windows-smoke ADDED at :744 beside macos-smoke :657 (union, 15 top-level jobs). Smoke-job shape legal: mirrors base windows-test cell (8.4+ffi), runs ONLY --version + tests\Windows.
- SERVER.md: lane diff +5/−1 applied byte-equal; ## Windows at :1567. ACCEPTED deviation: design files.modify NAMES this prose section (same class as 6.g/5.d welder-landed rows); NO regen, NO new rows; gen-protocol-schema --check rc0 EXEC-verified at merged tip (r1's denial cured here). All four generators --check rc0.

## Gates (merged tip, cwd=candy-code -c phpunit.xml, env -u LINES -u COLUMNS plain pipe, anchored exact-class stems)

- (PtyBackendTest|InteractiveWindowsArmsTest):: → `Tests: 12, Assertions: 49` EXACT (r1-era mint; YOUR §7 figure live: 5T/14A + 7T/35A per-file re-measured same run).
- SuiteSkipRosterTest:: → `Tests: 28, Assertions: 201` EXACT.
- tests/Windows on Linux → `Tests: 3, Assertions: 0, Skipped: 3` — the rostered-skip design shape (WindowsBashSmokeTest 2T(2S) + WindowsPtyRunSmokeTest 1T(1S)); first real Windows exec = the CI smoke job, BY DESIGN.
- Retainer set (PtyDriverRetainTest|PtyRunBashPermissionParityTest|WindowsShellTest|PosixShellQuoteTest|ProcessContainmentPhpSetsidTest):: → `Tests: 140, Assertions: 247` EXACT.
- PtyRunToolTest:: minus expect-miss → `Tests: 12, Assertions: 93` EXACT.
- DocFigureProseDriftTest:: → `Tests: 92, Assertions: 4253` GREEN; +5A vs lane-era 4248 — merged-tree prose word-number re-derivation, guard self-consistent; DISCLOSED, no src-truth issue.
- BARE TreeWideGuardRosterTest → `Tests: 17, Assertions: 3965` GREEN (lane adds ZERO rows; +8A vs r1 3957 = 6.h-era tree walk; all master rows survive). BARE DuplicatedTestHelperDriftTest → `Tests: 14, Assertions: 204` GREEN EXACT.
- LayeredSettingsTest:: → `Tests: 99, Assertions: 413, 0F` — fold-NOTHING confirmed (cure 00fedf519 shape intact).
- PAIRING (BashSandboxTest|BubblewrapHardeningTest):: ONE process → `Tests: 39, Assertions: 192` GREEN.
- Accepted foreign, cure NONE, shape-moved-or-STOP: StderrEmitterCensus 2F (PtyRelay 6.j-debt 75174ea40 — CORRECT class name per ruling) · LaunchContextDelegatesTest 4.g-debt · ForeignAgentPresetWiringTest base-carried · staleness pair (pinned 25,537/609,443; this weld carries +15T/+49A-class; NEVER touched here) · GlobDialect assert-NEITHER · keepWarm · KeyHelp · HelpTIER · BinDispatch :1893 · AttachPaging · OneSided :435 · SettingsEditor · ChildStderrCapture · AccessorDeprecation · BootstrapLaunchFormat doc-leg · Cli/HelpTest env leg.
- No full suite run; no composer/npm/refresh-deps; vitest N/A (zero web files in union).

## §7 hand-offs (LISTED, never fixed here)

- durations.tsv rows for battery mint (live merged-tip figures): tests/Support/PtyBackendTest.php 5T/14A · tests/Support/InteractiveWindowsArmsTest.php 7T/35A · tests/Windows/WindowsBashSmokeTest.php 2T(0A/2S on Linux) · tests/Windows/WindowsPtyRunSmokeTest.php 1T(0A/1S on Linux). Suite pair carry +15T/+49A-class.
- RESTRICTED_TOOL_NAMES += 'Script' — LEAD rider still pending. web.yml gen:strings — LEAD rider.
- WARN NEXT WELDER — C-TOOL-6.g (W35-completer, in review) OWNS: trio regen schema +2/−1 MEDIA_REF html + generated.ts +1/−1 :227 + SERVER.md TWO manual canvas rows verbatim + dist rebuild + ProtocolSchemaDriftTest:39 by-design green-at-regen — re-derive EVERY union from the CURRENT blob after this weld.

## Windows posture clause

Pure-delegation seam parity-proven vs vendor facade; availability arm is stat/version NEVER-A-SPAWN; refusals single-source
WindowsShell::BASH_MISSING_NOTICE; CI smoke-job legal per macos-smoke precedent; tests/Windows 3S is the design shape on Linux
with forced-run LOUD rostering (builder discrimination claim, structurally sound). SERVER.md ACCEPTED-deviation: design-named
prose, byte-intact, regen UNOWED (all four generators rc0 confirm no drift).
