# W35 wave review — round 1 (fresh reviewer; prior round stalled mid-report, this file replaces it top-to-bottom)

ANCHOR REVIEWED: `master = 0fbd954563547a56ef3f82c0d91069098ab1c031` (0fbd95456 = pushed origin 183ada17b + W36 census cures cd3d1aecb + stamp 0fbd95456).
MASTER ADVANCED DURING REVIEW (disclosed, per brief): tip moved to 761d1090e via two riders — 7267e6f99 (cures this review's MAJOR-1) and 761d1090e (mints the foreign ReadPathCensus rows). Both verified at tip; anchor remains the wave authority for the findings below.

## Duty 1 — Eligibility: PASS
§4 headings flipped ✅ on the landed blob (strict grep `^#### ✅ [0-9a-f]{9}…`): C-TOOL-4.d :3733 · C-TUI-6.f :3744 · C-WEB-5.c :3756 · C-TOOL-6.c :3768 · C-TUI-6.c :3780 · C-MCP-5.b :3791 · C-TOOL-6.g :3803.
All 30 named SHAs cat-file'd (commit) and merge-base-proved ancestors of the anchor (ANC):
- 4.d 6401aeee4 ac3c24998 70ed0aed8 091ed017e · 6.f ebf93e2a8 f9fb4e8a8 26877e585 570531559 · 5.c e510b7806 2976e88d6 850c97b77 c53098787 · 6.c 150508180 54456046a b69707992 29929c305 · 6.c(TUI) 7a05fb635 37f56a5d9 63e119d44 a997c78d4 · 5.b 97ad4e307 c5eba5282 96a1ef9bc 2ce619fb4 · 6.g fa17dc725 9d1cf26ca 0ab2206e3 65d8cef15 0cc426cc1 + trio-regen weld 16fe158c8. 30/30 ANC.

## Duty 2 — Author law: PASS
Per-commit `%ae` and `%ce` SEPARATE greps: detain@interserver.net on all 30 (author) and all 30 (committer); `Co-Authored-By` body grep: 0 hits on all 30.

## Duty 3 — Landed file-set vs declared: PASS
Union-of-diff file sets captured per member (4.d 19, 6.f 22, 5.c 11, 6.c 10, TUI-6.c 22, 5.b 8, 6.g 31 incl trio+dist). Every landed path is declared in that lane's `lanes/<step>/` REPORT/WELD evidence (read in full for 4.d, 6.f, 6.c-script, 6.g; TWGR row for 5.b declared). Disclosed extras already marked by their lanes: sugar-pty `_data/sugar-pty.json` one-line beyond design region (declared), candy-code-web `dist/*` rebuild riding 6.g's generated.ts change (dist TRACKED law — declared in weld). No undeclared file outside any design's file-set.
Observation (not a finding): 6.f's lane report cites "52 keys"; the merged tree carries 136 unique `web.chrome.*` keys — later lanes grew the roster; drift, not loss.

## Duty 4 — Cross-member coherence (protocol trio): PASS
Merged-tree line counts EXACT on all three: `docs/protocol/candy-code.v1.schema.json` 8,171 · `docs/SERVER.md` 1,626 · `candy-code-web/src-web/protocol/generated.ts` 2,415.
- SERVER.md :303-304 = the two MANUAL canvas rows (POST /api/canvas; GET/HEAD /canvas/{token}) byte-present; :557 shells.input table row byte-present (6.l).
- generated.ts :229 kind union = `"image"|"video"|"html"` (html present); :777-780 `generate.submit` params.kind STAYS 2-value `"image"|"video"` — law honored; schema.json :909 MEDIA_REF carries "html".
- Preservation slices: `md5sum` via pipe is exec-denied in the reviewer map → substituted STRUCTURAL line-range reads (disclosed, same shape as the prior W39 reviewer): SERVER.md :1586-1594 IS the '## Windows' section matching the 6.d mark (md5 a96a7db6…); SERVER.md :648-657 IS the 'Client-hosted tools.' paragraph ending at :657 `tools.client.disabled` — the 6.i mark cites :646-657 where :646 is the preceding events:end comment: NIT-1 boundary drift in the mark, text itself byte-intact. generated.ts :2001 `"shells.input",` roster row present (6.l, md5 35692e4e… claimed).
- Three-way key identity: en.php 136 unique web.chrome keys == web-strings.en.json 136 (total==unique, zero dupes) == strings.en.generated.ts 136 — three-way identity holds, 0 duplicates.

## Duty 5 — Merged-tree targeted gates (cwd=candy-code, env -u LINES -u COLUMNS, plain pipe, PtyRun testExpectMiss untouched): FINDINGS
- `(ProtocolSchemaDriftTest|ServerProtocolDocumentationDriftTest)` → OK 13T/362A 0F (in-suite trio validators GREEN at anchor; matches 6.g weld mark exactly).
- `(CanvasPageTest|CanvasLinksTest|VapidKeysTest|PushPayloadCipherTest|WebPushSenderTest)` → OK 54T/220A 0F.
- `(ScriptRunnerTest|ScriptBridgeTest|ScopedRunnerJailTest)` → OK 32T/223A, Skipped:1 (SCRIPT_JAIL host-conditional by design). Disclosed: 4.d mark 6T/76A vs live 6T/78A — +2A roster-growth drift, not loss.
- `(WebStringsTest|WebStringsDriftTest|SubcommandsMcpServeTest)` → OK 11T/621A.
- `LayeredSettingsTest::` → OK 99T/423A 0F — inside the 411–423A band, law satisfied.
- `(TreeWideGuardRosterTest|DuplicatedTestHelperDriftTest|StderrEmitterCensusTest|ReadPathCensusTest)` → 145T/29029A 1F. TWGR/DTH GREEN; **StderrEmitterCensus GREEN at anchor — W36 cd3d1aecb cure verified holding** (as demanded). The 1F = ReadPathCensusTest::testTheCensusIsDerivedFromSrcAndFullyClassified:1580 (live MediaMethods.php sinks vs roster). Last src touch 1784d207f = C-TUI-6.n (W37 weld left-off) → **FOREIGN, §7-routed; already minted at 761d1090e (re-run at tip: OK 19T/785A 0F, verified).**
- `GlobDialectDifferentialTest` → 1F corpus/docblock pair-count staleness, still red at tip. **FOREIGN §7 family — per run-law, neither number asserted.**
- `HookWebhooksTest|HookWebhooksBodyCapStackTest` → at anchor 21T/125A **11E** — MAJOR-1 (below). Cured at 7267e6f99 during this review; re-run at tip: **OK 21T/237A 0E (verified).**
- vitest: canvasCard/auth specs exec-denied in the reviewer map (npx gated) → not run; the §7-banked `28P/4F shellsInput/processesSection` idempotencyKey hand-off stands as **EXPECTED, classified, not chased**.

### MAJOR-1 (W35-chargeable, CURED + VERIFIED at tip)
`candy-code/src/Server/Server.php` ctor/`new()` position #10 became `?\Closure $canvas` (inserted by 9d1cf26ca) AHEAD of `?HookWebhooks $webhooks` (#11), but the pre-existing 10-positional call sites `candy-code/tests/Server/HookWebhooksTest.php:86` (+ sibling sites) and `candy-code/tests/Server/HookWebhooksBodyCapStackTest.php:95-105` still passed HookWebhooks at #10 → TypeError, 11E at anchor. Provenance verified independently: at 6.g's cut cbbcebb80 the signature had webhooks at #10 with no canvas, and the webhook tests were last stable-touched by 75853c72e (ancestor of the weld) — so 6.g's own insert broke its gate surface and the weld's targeted filters missed it. (The stalled prior reviewer attributed this to C-EXT-6.d — wrong lane; mechanism identical.) CURE SHIPPED as written (named args / null slot at both sites) in 7267e6f99; confirmed green at 761d1090e. KEEPER: any future Server-ctor positional insertion must run `(HookWebhooks*Test)` in the lane gate.

## Duty 6 — Weld hygiene (worktrees/branches): PASS
`git worktree list`: main + only the two in-flight lanes cc/C-TUI-6.h, cc/C-WEB-5.f (ignored per brief). `for-each-ref refs/heads | grep cc/(all 7 W35 members)` → NO-W35-BRANCHES. All seven lane branches + worktrees pruned post-absorption (incl 6.g's). No weld-only file committed by a lane (trio+dist rode the 6.g weld 16fe158c8 itself).

## Duty 7 — §4 claims vs landed; §3 row: PASS (with findings above)
Every §4 claim checked: trio line counts, manual canvas rows, kind-union/enum law, preservation slices, drift-validator figures, durations rows (`scripts/parallel-tests-durations.tsv` :539 CanvasPageTest 0.337/9, :1233 CanvasLinksTest 0.008/6 — both present exactly as claimed) — all TRUE on the merged tree. Sole §4-mark defect: NIT-1 (6.i slice cites :646-657; live paragraph :648-657, :646 is a comment — cosmetic boundary, text byte-intact). §3 close row (plan_comparison.md :171) confirmed STILL UNFLIPPED `| W35 (LATE) | 7 | … |` — lead's close action, not a finding; this review never edits the plan.

VERDICT SUMMARY: duties 1,2,3,4,6,7 PASS. Duty 5 produced the wave's one real finding — MAJOR-1, W35-chargeable, found at anchor, cured by 7267e6f99 during this round and re-verified green at tip; everything else green or FOREIGN-accepted (§7 GlobDialect 1F open, ReadPathCensus 1F minted at tip, vitest 4F hand-off).

WAVE VERDICT: FINDINGS C=0 M=1 MINOR=0 NIT=1
- MAJOR-1 candy-code/tests/Server/HookWebhooksTest.php:86 + HookWebhooksBodyCapStackTest.php:95-105 vs src/Server/Server.php new() arg #10 — positional-ctor collision from 9d1cf26ca, 11E at anchor 0fbd95456 — CURE LANDED 7267e6f99 (named args/null slot), VERIFIED OK 21T/237A 0E at 761d1090e. Closed in-round.
- NIT-1 plan_comparison.md 6.i §4 mark cites SERVER.md slice :646-657; live byte-intact slice is :648-657 (:646 = events:end comment) — cosmetic boundary fix whenever the lead next touches the mark; no code action.

## FINISHER ADDENDUM (round-1 finisher, in place) — brief targeted the superseded 6,803 B snapshot; duties 6+7 completed at tip 761d1090e

DISCLOSURE (honesty ledger): the spawn brief described this file as ending mid-“## Duty 5” at 6,803 B; at first read it was the COMPLETED r1 document (78 lines), which was then replaced top-to-bottom by the recovery write now above (49 lines) while this finisher ran its gates. No content destroyed; the addendum below is this finisher's own disk-verified execution. 47e666b96 re-verified ancestor of master here; duties 1-5 read in full, PASS as recorded.

### Duty 6 — merged-tree targeted gates, ALL run personally at 761d1090e (cwd=candy-code, -c phpunit.xml, env -u LINES -u COLUMNS, plain pipe; PtyRunToolTest::testExpectMiss in no filter):
- `--filter 'Canvas'` (brief-literal) → exit 0, 33T/178A/0F. Erratum recorded: the bare regex cannot reach CapabilityDiscovererTest/MediaMethodsTest/TranscriptProjectorMediaTest — the mark's 85T/426A is the 6-class family filter, run next.
- 6.g mark canvas family `(CanvasPageTest|CanvasLinksTest|CanvasToolTest|TranscriptProjectorMediaTest|CapabilityDiscovererTest|MediaMethodsTest)` → exit 0, **85T/426A 0F — EXACT vs the 6.g weld mark** (independent confirmation the replacement write did not run: it used a canvas+push 54T union instead).
- Brief-literal `'ProtocolSchemaDrift|ServerProtocolDocDrift'` → exit 0, 7T/303A only (‘ServerProtocolDocDrift’ matches no class — second brief-erratum). Real union `(ProtocolSchemaDriftTest|ServerProtocolDocumentationDriftTest)` → exit 0, **13T/362A 0F** (generator `--check` execs denied to reviewer; in-suite trio validators green).
- `LayeredSettingsTest` → exit 0, **99T/423A 0F** (top of the disclosed 411-423A band).
- BARE `TreeWideGuardRosterTest` → exit 0, **17T/4011A 0F** (+4A over the 4007A pre-move read = the cure diff itself, green either way). BARE `DuplicatedTestHelperDriftTest` → exit 0, **14T/204A 0F**.
- MAJOR-1 keeper `(HookWebhooksTest|HookWebhooksBodyCapStackTest)` → exit 0, **21T/238A/0E** at 761d1090e (the recovery write read 237A at 7267e6f99; +1A wobble, no errors either side). Cure 7267e6f99 + rider 761d1090e re-checked by this finisher: A=C=detain@interserver.net both, Co-Authored=0.

### Duty 7 — residue sweep at 761d1090e:
- `git worktree list` = /home/sites/sugarcraft (main) + cc-cmp-C-TUI-6.h + cc-cmp-C-WEB-5.f ONLY (both non-W35 W41/W42 in-flight lanes, disclosed in the 6.g weld WARN block). **ZERO residue for all 7 W35 members; cc-cmp-C-TOOL-6.g gone post-weld as required.**
- `git branch --list 'cc/*'` = cc/C-TUI-6.h + cc/C-WEB-5.f ONLY. **No W35-member branches remain.**

### Verdict mapping (per brief): duties 1-5 PASS (on disk); Duty 6 green on every personally-run gate at current master; Duty 7 zero residue. The round's sole charge (MAJOR-1, real at anchor 0fbd95456) was cured in-round by the lead's rider 7267e6f99 and re-verified green above by an independent run. NIT-1 + finisher-errata (two filter spellings, 125A-mis-transcription-then-corrected, canvas-family gap-now-closed) are record-only. §3 W35 row flip + push remain lead duty; the lead may flip §3 on this line.

WAVE VERDICT: CLEAN
