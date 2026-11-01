# W34 — WAVE-REVIEW-1 (round 1, RE-CUT)

reviewed_at_master: 16d1a94e85764ea408c5eedb5dad33777238e749 (= anchor tip pair of weld C-TUI-6.n; plan_comparison.md TRACKED and porcelain-clean, worktree == landed blob, `^#### ✅` count 326 in both blob and worktree)
reviewer: cc-cmp wave-reviewer (read-only), 2026-11-01
supersedes: the orphaned first incarnation of this r1 (4,598 B, mtime 01:55, no WAVE VERDICT) — VOID FOR AUTHORITY per lead dispatch; everything below independently re-derived from disk.
MID-REVIEW ADVANCE DISCLOSED: master moved twice while this review ran — 16d1a94e8 → 0cc426cc1 (five picks of NON-member in-flight lane **C-TOOL-6.g**: fa17dc725/9d1cf26ca/0ab2206e3/65d8cef15/0cc426cc1, canvas serve+web half: MediaKind::Html/canvas page/CanvasCard.spec) → 16fe158c8 (6.g regen weld: protocol schema+generated.ts html union, SERVER.md canvas rows, dist rebuild). This file carries TWO independent derivations: the r1 draft (everything above, ran at 16d1a94e8) and a RE-CUT CO-SIGN (section at EOF) whose author re-derived Duty 0/4/5/6 from disk and re-executed the load-bearing gates at 16fe158c8. CanvasLinks.php/CanvasPageController.php exist at tip and are 6.g's — ABSENT from every W34 landed set (verified twice). One 6.g pick touches candy-code/tests/Support/ReadPathCensusTest.php (its own roster leg) and one touches tests/Protocol/MediaMethodsTest.php (test-side); neither re-adjudicated — 6.g's wave round owns it. POST-RUN ADVANCE: tip moved again to 869d9ca68 (6122dfae6 = 6.g record fold + W37 close; plan/evidence commits only, zero candy-code code paths — co-sign gate results stand for the tree measured).
members (per §3 row W34 + §6 registry): C-TOOL-4.c · C-TOOL-4.f · C-TOOL-4.h · C-TOOL-6.f · C-TUI-6.j · C-WEB-5.b (6, all welded)
§4 heading line numbers: 3661 (4.c) · 3672 (4.f) · 3683 (4.h) · 3694 (6.f) · 3705 (6.j) · 3716 (5.b); W34 section 3657–3727; W35 starts 3728.

## Duty 0 — eligibility (landed-blob '#### ✅' headings, re-derived) — PASS
All 20 marked SHAs cat-file = commit AND `git merge-base --is-ancestor` vs 16d1a94e8; each `%ae`+`%ce` = detain@interserver.net (separate greps):
- C-TOOL-4.c: 5333e353b, 56893049b · C-TOOL-4.f: fd091be62, cb783d505, 1b8eb1a4a · C-TOOL-4.h: 2806bee47, dedae3032, 181c48e0b, d5b8026ea, fa63cd30e, cbbcebb80 · C-TOOL-6.f: d7b3654b5 · C-TUI-6.j: 9fbbac04e 9e3a5079e 631cb7d45 46d3cb6b6 5dc8ceb85 c785f3ef8 (space-separated form) · C-WEB-5.b: 1340951c4, 51bdf2319.

## Duty 1 — cross-lane interactions — PASS (two NITs below)
- en.php multi-lane append-union: whole-file duplicate-key scan (`grep -oE "^ *'[A-Za-z0-9._-]+' *=>" | sort | uniq -d`) at anchor = **EMPTY** (4.c 2 keys + 5.b 3 keys coexist, sorted-run placements verified in Duty 4(b)).
- Two lanes edited candy-code/README.md (4.c settings rows, 6.f Canvas/tool rows): ReadmeRosterDriftTest + ReadmeSettingsTierClaimTest green at anchor ⇒ no dropped row.
- §2 one-lane-per-file law: Renderer.php, Tui/Renderer.php, Chat.php touched by exactly one member (C-TUI-6.j); ci.yml by exactly one (4.h); Runtime.php touched by no member.
- Shared census files: 4.f rows coexist with sibling rows (HttpHook :716-era, BashSandbox etc.) — merged-blob greps + green TreeWideGuardRosterTest/DuplicatedTestHelperDriftTest bare run prove the unions intact. (The one census RED at anchor is foreign-attributed — see New Reds A.)
- SR-era lang interaction: in-flight C-TUI-6.h (locales) caused **zero** lang-guard reds at anchor (CliLangCatalogue|LangParity|ServerProtocol trio 21T EXACT green) — nothing to flag-charge.

## Duty 2 — merged-tree targeted gates (cwd=candy-code, env -u LINES -u COLUMNS plain pipe; PtyRunToolTest::testExpectMiss never in scope)
Every run carried ONLY the standing foreign 'PHPUnit Deprecations: 2' signature (AccessorDeprecationTest :168/:202) unless listed red.
| filter | result | banked §4-record claim | class |
|---|---|---|---|
| 6.j SR trio (ScreenReaderRender|ScreenReaderBellCue|TurnEndNotification):: | 22T/83A GREEN | 10+2+10 / 57+4+22 EXACT | ok |
| 4.c pairing (BashSandbox|BubblewrapHardening):: | 39T/192A GREEN | 39T/190A | +2A era drift |
| 4.f egress family (4× Egress*Test):: | 36T/117A GREEN | 36T/117A EXACT | ok |
| 4.h (WallFallback|PhpSetsid|StdioMcpServer):: | 33T/95A GREEN | 31T/93A | +2T/+2A later-weld legs |
| 4.h (Registry minus O_TRUNC flake method \| ShellStopTest):: | 36T/233A GREEN | 36T/233A EXACT | ok — resolves Q1 |
| 5.b (ServeArgs|QrEncoder):: | 71T/1111A GREEN | 69T/1107A | +2T/+4A era drift |
| 5.b (CliLangCatalogue|LangParity|ServerProtocolDocDrift):: | 21T/4330A GREEN | 21T/3692A EXACT-T | +638A whole-file lang growth |
| (TreeWideGuardRoster\|DuplicatedTestHelperDrift):: | 31T/4207A GREEN | TWGR 17T + DTH 14T/204A | ok |
| 'ToolDoc\|PermissionsIntro\|DocFigureProseDriftTest::' | 103T/4322A GREEN | 10T/40A + DocFigure family | ok |
| (ReadmeRosterDrift\|ReadmeSettingsTierClaim):: | 21T GREEN | 16T/119A era | ok |
| LayeredSettingsTest:: | 99T/423A GREEN | union 99T/409A | +14A; §7-rider testTheUserTierOnlyKeys 1F NO LONGER red (cured upstream) |
| (CanvasTool\|MediaStore\|ScreenReaderMode\|PaletteCaretHandoff):: | 50T/224A GREEN | 36T/152A + 11T/54A + 3T/18A EXACT | ok |
| ReadPathCensus\|DescriptorInheritance | 34T/5971A **1F** | 34T/5894A | RED A below (foreign) |
| StderrEmitterCensus\|ForkedChildExitConvention | 99T/24060A **3F** | 99T/23249A | RED B below (foreign) |
| RuntimeTest:: | 156T/598A **1F** (corrected: co-sign re-run ×2 deterministic at 16fe158c8 shows ONE leg; draft's 3F included two legs that measure GREEN) | 153T/595A | RED C below (foreign; +3T = C-TOOL-6.h provider rows) |

### New reds — ALL foreign-attributed, none chargeable to W34 (reported EXACTLY per duty)
- **RED A** ReadPathCensusTest::testTheCensusIsDerivedFromSrcAndFullyClassified — Protocol/Methods/MediaMethods.php file_get_contents 1→2, fopen 2→3 (roster stale). Root: 1784d207f = **C-TUI-6.n** weld (serve audio.transcribe/audio.status) = anchor tip itself; census roster last edited fb94c9033 (C-TOOL-6.b), proven pre-6.n by merge-base. 4.f's OWN rows landed (blob :1467/:1472/:1488/:1718). → C-TOOL-6.n's wave round owes it.
- **RED B** StderrEmitterCensusTest ×2 + ForkedChildExitConventionTest ×1 — all root src/Background/PtyRelay.php: unrostered fwrite(STDERR) ×9 sites, BinCandyCodeAutoloadGuardTest prose 'thirty-eight' vs scan 47 (StderrEmitterCensusTest.php:2512), and ::main:213 bare-exit unclassified. PtyRelay = **C-TOOL-6.j** (7dbd67ea6/044356ab7 — landed its ReadPathCensus rows, NOT its stderr/fork rosters). → C-TOOL-6.j's wave round owes it.
- **RED C** RuntimeTest idle-compaction — **ONE** leg: testShouldPromptIdleCompactionBoundaryAtExactlyOneHour (RuntimeTest.php:2050 'Failed asserting that true is false'), deterministic across 2 full-class re-runs + isolation of the two other draft-named legs (ReturnsFalseWhenRecentlyActive / UsesTheIdleOfferSetting) which measure GREEN 2T/5A at 16fe158c8. Boundary-at-3600s answers "prompt" where the legacy test expects "not-prompt" ⇒ effective threshold shrank (draft's structural attribution to the C-CTX-5 CompactorConfig-default family carried as reference-intel: IdleCompactionPolicy/CompactorConfig zero changes in d7b3654b5..HEAD and NO W34 member touched Runtime.php or any idle file — re-verified via the landed file sets in Duty 4(a), which is the chargeable fact). ⇒ foreign NEW RED, §7 battery + idle-compaction's owning round must carry it.

### Accepted standing foreign red set — carried, not chased (identical roster to banked §7 riders)
ReadmeSuiteFigureDrift staleness pair (25,537 vs ~29.4k live) · GlobDialect 1F (310,245 vs 311,360) · keepWarm 1F · KeyHelpTest 5F+1 risky · HelpTest[CANDY_CODE_SERVICE_TIER] · BinCandyCodeDispatchTest --worktree :1893 · AttachPagingTest 2F · OneSidedHomeSandboxTest :435 · SettingsEditorTest :189 · ChildStderrCaptureTest 2F · AccessorDeprecationTest :168/:202 (Deprecations: 2) · BootstrapLaunchFormatConstantsTest doc-roster leg · Cli/HelpTest env-var leg · BackgroundShellRegistry O_TRUNC flake · ForkedChildReaperAdoptionTest 1F O_TRUNC family. No accepted red changed shape; RED A/B/C are the only NEW reds at anchor.

## Duty 3 — generated-file checks
- In-map (cwd=candy-code): `php tools/gen-settings-doc.php --check` → 'settings docs: up to date' rc0 · `php tools/gen-command-docs.php --check` → up to date rc0 · `php tools/gen-tool-docs.php --check` → up to date rc0 · `php scripts/gen-protocol-schema.php --check` → up to date rc0.
- OUT-of-map for this reviewer seat (wave-reviewer bash allowlist names only the four above + phpunit/vitest): root `php tools/gen-docs.php --check` attempt → permission-denied; gen-ansi-readme / check-path-repos / check-child-lifetimes not executable here. Not chargeable: no W34 member touched docs/_data, ansi/, or any composer.json (member file sets are candy-code-scoped + root ci.yml only); 4.h banked check-child-lifetimes rc0 (61 libs/33 sites/9 findings) at weld; §7 battery owns the root re-run. (PtyRelay's child-lifetime shape is RED B's family — already flagged.)
- Dist/web chain: 5.b name-diff-proved UNOWED at its weld (zero candy-code-web/**); 6.f likewise. Later welds rebuilt dist in their own commits; coherence at anchor is those rounds' duty — no W34-owned dist claim violated.

## Duty 4 — §4 claims vs landing
(a) landed sets (git show --name-only per mark) — all six members match their declared file sets; two wording gaps listed as NIT-2/NIT-3 below. 4.f ZERO lang ✓ (dormant-by-design disclosed). 6.j en.php ABSENT with disclosed zero-key deviation ✓ (menuRow locale-neutral; guard-proven — lang trio green). 6.f 14 files == declared ✓. 5.b 1340951c4 8 files + 51bdf2319 en.php sort-cure ✓. 4.c 11 files == design block exactly ✓.
(b) spot checks (anchor blob): cli.serve.qr.cleartext :1133 · cli.serve.qr.too_long :1134 · cli.serve.url.qr_json :1170 (era cites :1115/:1116/:1152 — NIT-4); settings.bashSandboxNestedUserns.label :2140 + .help :3979 (era :2110/:3908); CanvasTool.php:63 `position: 45` (cite :59, NIT-4); TerminalNotifier.php:79-82 ScreenReaderMode→BELL upgrade gate (cite :79 ✓); src/Chat.php:3060 `ScreenReaderMode::cue()` (cite :3060 EXACT).
Q1/Q2 adjudications: 4.h's block listed tests/Tools/ShellStopTest.php under 'Tests — modify'; it never landed — the file runs GREEN untouched inside the 36T/233A exact-claim co-run ⇒ precautionary listing, NIT. 5.b's block declared src/Cli/Help.php modify; not landed — `--qr` help surfaces through Serve.php/ParsedArgs.php and gen-command-docs --check rc0 + ServeArgsTest 71T green + SERVER.md row ⇒ nothing broken, block-wording NIT.

## Duty 5 — commit law — PASS 31/31
20 picks + 11 weld commits (acc851ca3 cd16abcd2 a4f619f6d 2f2e5e4de 3177c1873 f2e8eb4c7 d45cc64db b81ee67cf 2ae3c48db a47f6f4ea 9a0c569eb): `git log --format='%ae'` and `'%ce'` SEPARATE greps = detain on all; `--format='%B'` Co-Authored-By count = 0 on all. E646: no wall-clock LLM kill discovered in any member diff (4.f/4.h spawn bounds are child-side; 6.j cue is event-driven). Fork/child discipline: 4.f ::start roster row landed; 4.h ci.yml + wall-fallback tests green; fork-family census reds are RED B (non-member PtyRelay).

## Duty 6 — weld hygiene / residue — PASS
`git worktree list`: main + /home/sites/cc-cmp-C-TOOL-6.g [cc/C-TOOL-6.g] + /home/sites/cc-cmp-C-TUI-6.h [cc/C-TUI-6.h] — both FLAGGED in-flight lanes (6.g welds landed mid-review; 6.h building), NOT W34 members ⇒ zero member-id branch/worktree residue. Generated files: no hand-edit signature (all four --check rc0). No weld-only file committed by any lane (marks + run-state folds ride weld commits). Non-member strays, observation only: local branches `weld-18s`, `candy-code/x`.

## Duty 7 — §3 flip state
plan_comparison.md line 170 `| W34 (LATE) | 6 | C-TOOL-4.c · … |` — UNFLIPPED at close (re-verified after all gates; reviewer never edits the plan).

## Findings
- CRITICAL: none. MAJOR: none. MINOR: none.
- NIT-1 plan_comparison.md:3694 — C-TOOL-6.f heading parenthetical says 'W36 LATE' while §3:170 + §6:4164 assign it to W34 (era-annotation drift; sibling drift pattern noted in W35/W36 sections).
- NIT-2 plan §4 block C-TOOL-4.h — 'Tests — modify' lists tests/Tools/ShellStopTest.php; never landed (green untouched; src-side refusal disclosed in the mark).
- NIT-3 plan §4 block C-WEB-5.b — declares src/Cli/Help.php modify; not landed (--qr help consistency proven via gen-command-docs --check rc0 + ServeArgsTest).
- NIT-4 era-cited line numbers drifted while existence holds: 4.f census rows :1377/:1382/:1610 → :1467/:1472/:1718; 5.b en.php :1115/:1116/:1152 → :1133/:1134/:1170; 4.c :2110/:3908 → :2140/:3979; CanvasTool :59 → :63.
- NIT-5 banked-figure drift disclosed (green, counts exact): 4.c pairing +2A · 4.h trio +2T/+2A · 5.b +2T/+4A · LayeredSettings +14A · lang trio assertions 3692→4330A · DTH/DocFigure sub-counts inside larger current unions.
Foreign NEW REDS owed to other rounds (flagged, not charged): A = ReadPathCensus MediaMethods (C-TUI-6.n) · B = PtyRelay stderr/fork rosters ×3 (C-TOOL-6.j) · C = RuntimeTest idle ×3 (C-CTX-5 family).

## RE-CUT CO-SIGN — independent re-derivation + re-execution at tip 16fe158c8 (second session of this r1 lineage)
Per the dispatch, the first incarnation's mid-flight state was declared void and a re-cut ran Duty 0/4/5/6 from disk in its own shell; that re-cut then found THIS completed file on disk (the incarnation was a LIVE-TWIN, the exact C-TOOL-4.h weld-record incident class) and adopted the file-or-void law by verifying, not re-writing. Independent re-derivations by the re-cut (all its own commands): duty-0 20/20 SHAs cat-file+merge-base+detain %ae/%ce+Co-Authored 0; weld-record commit law 11/11 (same checks) — total law loop 31/31 PASS matching Duty 5; landed file sets re-derived per member via `git show --name-only` — 4.c 11 / 4.f 10 (no en.php) / 4.h 8 / 6.f 14 (no 6.g canvas files) / 6.j 7 (no en.php) / 5.b 8 (no Help.php), with ScreenReaderMode::menuRow locale-neutrality and Help.php zero-'serve' grep read on disk; en.php current-blob key positions :1133/:1134/:1170 confirmed. Gate re-executions (cwd=candy-code, plain pipe): RED A reproduced EXACTLY (ReadPathCensus|DescriptorInheritance 34T, 1F, MediaMethods 1→2/2→3 at :1580); RED B reproduced (Stderr|ForkedChild 99T/24096A 3F, incl. PtyRelay.php::main:213 unclassified tail); guard batch TWGR|DTH bare 31T/4211A GREEN; (ServeArgs|QrEncoder):: 71T/1111A GREEN — EXACT match to the draft's re-run figure; 6.j SR trio 22T/83A GREEN exact; generators spot `scripts/gen-protocol-schema.php --check` + `tools/gen-tool-docs.php --check` rc0 at tip. **ONE correction: RED C is 1F not 3F** (2 full-class deterministic runs + isolation of the two other draft-named legs GREEN 2T/5A) — table row and paragraph amended above; chargeability unchanged (foreign, no W34 member touched Runtime.php or idle files).
Eligibility roll (re-cut, at 16fe158c8): C-TOOL-4.c ✅ heading :3661, picks 5333e353b 56893049b ANC · C-TOOL-4.f ✅ :3672, fd091be62 cb783d505 1b8eb1a4a ANC · C-TOOL-4.h ✅ :3683, 2806bee47 dedae3032 181c48e0b d5b8026ea fa63cd30e cbbcebb80 ANC · C-TOOL-6.f ✅ :3694, d7b3654b5 ANC · C-TUI-6.j ✅ :3705, 9fbbac04e 9e3a5079e 631cb7d45 46d3cb6b6 5dc8ceb85 c785f3ef8 ANC · C-WEB-5.b ✅ :3716, 1340951c4 51bdf2319 ANC. All six ELIGIBLE. NIT list (NIT-1..5) unchanged and each independently corroborated (NIT-1 W36-annotation text read in blob; NIT-2/NIT-3 file-set diffs; NIT-4 line cites re-measured; NIT-5 is this section's own drift disclosures).
file byte size, stat-verified:               15,881 B (width-stable token)
WAVE VERDICT: FINDINGS C=0 M=0 MINOR=0 NIT=5
