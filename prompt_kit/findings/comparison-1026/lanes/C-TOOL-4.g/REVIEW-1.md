# C-TOOL-4.g — REVIEW-1
VERDICT: FINDINGS — 0 CRITICAL / 0 MAJOR / 1 MINOR (step-local) / 3 NIT. WELD-ELIGIBLE: YES.

## Claim re-derivation (all lead claims TRUE)
porcelain 0 · tip ed626d858 · law 2/2 detain (%ae+%ce separate greps), 0 Co-Authored · forbidden-path grep across picks == 0 · sibling-lane grep (StdioMcpServer/LspConnection/PtyRelay/src/Protocol) == 0 · NEW candy-code/tests/Tools/Sandbox/EgressAllowlistModeTest.php present (448L, 11 methods, read fully) · 4.f trio ZERO-diff, hashes identical base vs tip: EgressPolicy 9082196b41…, EgressProxy bc2774c892…, EgressProxyProcess d259884c43… · full-range diff 18 files +1030/-63 = declared 16+4 minus shared-file overlap. Builder REPORT.md re-statted EXACT 9,061 B @14:06:41.

## Q1 — shared-door reuse: PROVEN by blob-text
ToolSettings sandboxAllowedDomains StringList [] user-tier Security::withLayered, projectSettable false-by-tier. Bootstrap::egressProxy() :9546 — gate PHP_OS_FAMILY!=='Darwin' && bashSandbox===MODE_ALLOWLIST; entries array_filter is_string (fail-CLOSED drop, never widens); registerEgressProxyShutdown() BEFORE new+start; start() throw propagates. tools() door FIRST statement :9597 (design :8523 cite drifted — placement correct, deviation-b). SandboxFactory::fromSetting allowlist arm reads the LaunchContext SHELF only (never spawns — doctor-probe safe). Bubblewrap::argv: '--tmpfs /tmp' :410 BEFORE egress '--bind' :436 (ORDER verified); env quartet :439 + NO_PROXY :441; --share-net absent (:405, allowlist ctor network=false); tail after --chdir '--' = PHP_BINARY -r stub -- sock 50774 --. EgressBridge runFromArgv fail-closed validator (usage→STDERR, return 2); run() :221 resolveProgram (cure i). EgressProxy/EgressPolicy 403 legs welded 4.f. 4.d flow: forScope :147 delegates self::fromSetting(...) — SAME factory, no parallel jailer, no second validator. Nonsense domains: dropped at door + enforcement ctor (parseEntry null) + benign unmatchable if string survives; announced via help text + describe verbatim-listing + observable 403.

## Q2 — the two FOUND+FIXED defect pins
(cure h) resolve-once: testTheLaunchDoor seeds user settings, first call returns running instance, assertSame($first,$second) at :235 — removing setEgressProxy's resolved-marking mints a fresh proxy ⇒ assertSame RED. Static map CONFIRMED, not exec-run (labeled UNCONFIRMED-BY-RUN for the mutation itself).
(cure i) execv-PATH: resolveProgram unit pins (:188-196) — absolute/empty untouched, missing bare word UNCHANGED (honest 127), bare 'sh' arrives absolute is_executable. MINOR-1 (step-local): EgressBridge.php:221 run()-entry call-site is UNPINNED — deleting that one line leaves every local test green (unit pins test the pure function; live driver execs absolute /bin/sh). The 127-shape REFUSAL assertion for a PATH-unresolvable program inside the bridge exists only as the unchanged-word unit leg; no fork-through-run() pin. Fix candidate: one assert in runFromArgv family execing a uniqid bare word via run() expecting the bridge's child exit 127 shape (or a source-slice pin on :221).
argv ORDER load-bearing: assertGreaterThan at :112 reddens if --bind is hoisted before --tmpfs (bound tree would be hidden). NO_PROXY legs :123-127 and env-quartet loop :114-121 each redden on removal. Bridge tail exact-slice :129-138.

## Q3 — §1.8 cascade same-commit locality: CLEAN
git show 685ffddb5 carries ALL: LAYERED_KEYS row + README regen + eighty-eight→eighty-nine hand-word + SETTINGS.md regen + LayeredSettingsTest:68 roster flip + TrustKey word + StderrEmitterCensus roster row (EgressBridge.php => 1) + :60 ledger THIRTY-EIGHT word + BinCandyCodeAutoloadGuard word + lang/en.php 3 hunks. My anchored runs (cwd=candy-code, full Test stems, NON-ZERO every filter):
- EgressAllowlistModeTest:: → Tests: 11, Assertions: 90 (EXACT builder)
- sandbox family (positional tests/Tools/Sandbox + BashSandboxTest.php) → OK 113/606 (EXACT)
- (SettingsSchemaDocDrift|LayeredSettings|CliLangCatalogue|TrustKey|ConfigWriteProducer):: → OK 153/1460 (EXACT)
- (DocFigureProseDrift|ReadmeRosterDrift|StderrEmitterCensus|ReadPathCensus):: → OK 216/28525 (EXACT)
- (LayeredSettingsTest|TrustKey|StderrEmitterCensus|ReadPathCensus):: → 225/25218 green
- BARE TreeWideGuardRosterTest:: → 17T/3922A (builder 3920A — +2 assertion-only wobble, green, disclosed)
- BARE DuplicatedTestHelperDriftTest:: → 14T/204A (EXACT)
- BinCandyCodeWiringTest:: → 1322T/7866A green — builder's 1322T claim VERIFIED by my run
- (CliLangCatalogue|LangParity):: → 15T/4205A green (2 new en.php keys sorted-adjacent: label :2230, help :4051, each once)
PtyRunToolTest::testExpectMiss EXCLUDED from all my filters (never ran). RegistryTest O_TRUNC + ForkedChildReaper out of combined filters (report-only, untouched by lane). gen-settings-doc --check NOT RUN — `php tools/...` exec is permission-denied by this reviewer's shell map (harness fact, not a lane gap); SettingsSchemaDocDriftTest:: green substitutes (generator-owned anchor per 4.d precedent).

## Q4 — deviation judgments (r1 authority)
(a) MODES const on SandboxBackend interface (design cited Bubblewrap): COHERENT — interface owns the vocabulary since 4.c; cite drift only.
(c) EgressBridge touched beyond design file-list (JAIL_TCP_PORT/stub()/runFromArgv()): ACCEPTED, not scope-creep — design's own argv contract names "EgressBridge's prefix" as the tail; the stub must live beside the bridge; run() is the single program-exec seam and forScope jails ride the SAME argv()/bridge when a socket is present ⇒ genuinely shared single source.
(d) NO settings validator for malformed domains: ACCEPTED — enforcement drop can only SHRINK the grant (fail-closed), help text states the law verbatim, describe lists what will match. 4.d precedent differs because an unknown scope spelling reads as EVERY-scope (widening) and needed audit announcement; here widening is impossible. NIT-1 (optional, lead's taste): surface dropped-entry COUNT in the settings audit for observability.
(e) settings family-adjacent insert: no tree-wide sort guard exists on the settings block (only cli.* pins SORT_STRING); CliLangCatalogue/LangParity green ⇒ nothing owed.
(f) Seatbelt arm = describe-sentence + macOS loopback-port follow-up: design-scoped §7 line, NOT a defect; Darwin-never-spawns consistent in code (PHP_OS_FAMILY!=='Darwin' gate) + docs + describe.
Live 200/403 leg: this box bwrap cannot start (RTM_NEWADDR) ⇒ JAIL-REFUSED arm EXECUTED (asserts real refusal), both arms never skip-only ⇒ HONEST per design's host-gate clause, not vacuous. 200/403 remains UNCONFIRMED-locally (§7 battery note). Hairpin IP 216.158.226.14 note fine.

## Q5 — inventory + hygiene
18 files == declared set, zero forbidden paths, zero sibling-lane files, zero removed/weakened legs (additive hunks; with*/signature carries preserve prior args; mode() allowlist fast-path only). Residue greps clean (no TODO/XXX/not-implemented; uniqid used in temp dirs; no glob-shaped literals added — GlobDialect innocence PROVEN: PathGlob untouched, en.php '/*.example.com' sits in a >64-char ineligible string). Zero new markTestSkipped. One-type-per-file OK. E646 clean: no wall-clock timers; JAIL_TCP_PORT 50774 fixed-port collision story documented in docblock (host pre-binder ⇒ bridge bind fails ⇒ loud refusal; fresh jail netns). StderrEmitter census: exactly one new emitter (runFromArgv usage), rostered in-step.
GlobDialect co-run showed 1F (310,245 pinned vs 311,360 derived) = FOREIGN-INHERITED per lead roster "assert-NEITHER": lane adds zero corpus literals, PathGlob blob untouched ⇒ master-side era drift, weld/battery re-pin owns. NOT a lane finding.

## Q6 — weld-readiness + §7 LIST (never fixed by me)
WELD-ELIGIBLE: YES. Landed picks for welder: 685ffddb5, ed626d858 off base 895b9d6fd — NEVER a range.
§7: (1) durations.tsv row EgressAllowlistModeTest.php = 11T/90A (my live re-measure EXACT). (2) battery note: hairpin-capable-host 200/403 leg. (3) Seatbelt macOS loopback-port arm follow-up. (4) optional settings-audit dropped-entry count (NIT-1, lead's ruling). (5) RESTRICTED_TOOL_NAMES seam remains LEAD-HELD — lane touched nothing there, zero new evidence, do not re-open.
Foreign roster adjudicate-only, not re-litigated: keepWarm 1F / KeyHelp 5+risky / HelpTest tier / BinCandyCodeDispatch:1893 / AttachPaging 2F base-proven / OneSided:435 / staleness pair / SettingsEditor:189 / ChildStderrCapture 2F / AccessorDeprecation / BootstrapLaunchFormat doc-leg / Cli/HelpTest env leg. Base carries db1441a61 ⇒ pairing co-runs expected green; my sandbox-family co-runs were green.
NIT-2: builder gate list never co-ran GlobDialect with the sandbox family (their filters omitted GlobDialect entirely); the 1F is foreign anyway, but future sandbox lanes should co-run it.
NIT-3 (doc-cite drift, no action): design §files cite Bubblewrap::MODES :53-56 vs landed interface vocabulary; tools() cite :8523 vs live :9597 — historical record, leave.

Method note: all mutation claims are static deletion-discrimination maps labeled UNCONFIRMED-BY-RUN except gates I executed above (no scratch EXEC needed for the verdict; the sole runtime-uncertain leg is the cure-i call-site gap = MINOR-1).
