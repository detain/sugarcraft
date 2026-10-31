VERDICT: CLEAN — 0 CRITICAL / 0 MAJOR / 0 MINOR / 1 NIT (ledger-arithmetic, non-blocking) · WELD-ELIGIBLE: YES
STEP=C-TOOL-4.e round r2 closure · reviewer fresh (r1 was ses_e6ce064bfffe…) · LANE=/home/sites/cc-cmp-C-TOOL-4.e · branch cc/C-TOOL-4.e · tip 55a934ef0 over base 895b9d6fd, 4 picks (d5112901b/340876035/027ef2464/55a934ef0) · porcelain 0 · %ae/%ce separate-count greps = 4/4 detain each · Co-Authored 0. All blobs read from the LANE (master untouched).

## Q1 — Cure coverage: PASS
- Gate on-disk at tip (McpClient.php stdioJailPrefix, method at :1133): recording leg now `if ($this->sandboxJail !== null && !in_array($name, $this->sandboxOptOuts, true))` — exactly r1's prescription. Docblock truth-flipped in both bullets ("While a jail is active…" / "nothing recorded: … the drained notice must never claim a jail that is not there") — matches the code's actual fall-through (project-false under null jail reaches the `sandboxJail === null → return []` door below).
- Pin `ServerJailTest::testTheOptOutNoticeStaysSilentWhenNoJailIsActive` asserts BOTH shapes on ONE client: no-jail ⇒ assertSame([], prefix) + assertSame('', sandboxOptOutNotice()); jail installed via withSandbox (verified `: void` in-place mutator, so the same $client carries the state) ⇒ assertNotSame([], prefix) + assertStringContainsString('projSrv', notice). Notice channel verified load-bearing: sandboxOptOutNotice() returns '' iff sandboxOptOuts===[] else the lang string sandbox.mcp.opt_out_ignored (en.php:2330) whose body carries r1's quoted false claim "The server(s) jail as scoped."
- Load-bearingness STATIC-CERT: deleting the `!== null` guard reddens EXACTLY the new method's 2nd assert (no-jail run records 'projSrv' → notice non-empty) and no other leg — the jail-active polarity records identically under both code shapes; every pre-existing recording expectation runs with a non-null jail (its green-in-both claim confirmed by my 9T green); the "null-jail door inert" leg (:237) passes $spelled=null, never entering the recording branch.
- EXEC status: my gates re-run GREEN with fixer-exact figures; the red-before reproduction needs a scratch mutation copy and my reviewer map DENIED it (mkdir/cp/du/find all rejected — honest map-denial, TUI-6.g precedent): red-before 9T/60A/1F = **UNCONFIRMED-BY-RUN**, resting on the fixer's exec + the deterministic single-leg static cert above.

## Q2 — Zero collateral: PASS
- git show --name-only 55a934ef0 = EXACTLY the two declared files; diff +31/−6 (−6 all McpClient docblock/gate replacements, 0 removed test lines).
- lang/en.php byte-untouched by the fixer (git diff 027ef2464..55a934ef0 = 0 lines). Base..tip substrate: SandboxFactory/ScriptHook/BuiltIn/Bash/HookManager = 0 lines. Full-range name-list still the r1-verified 18 paths.
- New lines: uniqid()-tempest paths, no glob-shaped literals, no '**Refused:**'-shape, no TODO/FIXME (grep RC=1), porcelain stayed 0 across all my runs. DuplicatedTestHelperDrift + TreeWideGuardRoster BARE both green (my runs, below).

## Q3 — Gates (MY figures, cwd=candy-code -c phpunit.xml, env -u LINES -u COLUMNS, plain pipe, anchored full-Test stems, all NON-ZERO):
- (ServerJailTest|ScopedRunnerJailTest|LspLauncherTest|LspHandshakeBudgetTest|StdioMcpServerTest|McpTrustPinsTest):: = Tests: 64, Assertions: 336, OK (2 pre-baseline deprecations) — EXACT FIX-1.
- ServerJailTest:: = Tests: 9, Assertions: 62, OK — EXACT FIX-1 (r1 8T/57A + the new leg).
- McpClientTest:: = Tests: 66, Assertions: 134, OK, Skipped: 1 — EXACT.
- (CliLangCatalogueTest|LangParityTest):: = Tests: 15, Assertions: 4206, OK — EXACT (zero new keys, as expected).
- BARE TreeWideGuardRosterTest:: = 17T/3922A OK; BARE DuplicatedTestHelperDriftTest:: = 14T/204A OK; LayeredSettingsTest:: = 99T/411A OK — all EXACT.
- NIT (ledger, non-blocking): FIX-1's explanatory line "baseline 8T/57A + 1T/5A" doesn't square — the new method carries 4 self:: asserts yet BOTH paired live measurements (63→64T/331→336A family; 57→62A alone) show +5A. Figures-as-run are self-consistent (my 9T/62A == FIX-1's 9T/62A); only the prose delta arithmetic has a stray 1A (r1's 57A baseline or the +5A labeling). No code/test impact; welder may quote FIX-1 figures as-is (they are live-true).

## Q4 — Weld-readiness: YES
- r1's full-range PASSes accepted per closure law (not re-litigated); the fix pick adds zero surface beyond the two files, so r1's inventory/hygiene/doc-figure conclusions carry unchanged.
- Residual reds stay exactly the accepted-foreign roster (GlobDialect :336 etc.) — none of my gate families touched them; none ADDED: every run above is 0F. Base-pairing (BashSandbox|Bubblewrap co-run, cure db1441a61 on 895b9d6fd) was GREEN-verified by r1 and this fix touches neither path.
- §7 LIST (battery owns, never fix here): durations.tsv row for NEW tests/Tools/Sandbox/ServerJailTest.php — my live figure **9T/62A at tip 55a934ef0** (r1-era 8T/57A superseded by the fixer pick; weld record takes the green-run number); suite +1T/+5A rides the battery carry from r1's +11T-class total; suite-figure.json staleness pair expected at weld (lane never touched the pinned files — re-confirmed 0 lines); NIT lang-keys-ride-pick-1 truth-note goes to weld §4 by CITING FIX-1.md:14 (REPORT.md history not rewritten, correctly).
- Excluded and honored: PtyRunToolTest expect-miss, RegistryTest O_TRUNC, ForkedChildReaper — never in my filters; no full suite run.

WELD-ELIGIBLE: YES — cure landed exactly as prescribed, both-polarity pin live-green with my own figures, zero collateral, r1's MINOR closed, NIT ledger-only.
