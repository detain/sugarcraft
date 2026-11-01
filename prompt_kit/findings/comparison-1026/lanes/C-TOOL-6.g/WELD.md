# C-TOOL-6.g — WELD RECORD (2026-11-01, welder B2)

Welded master range: 16d1a94e8 → 16fe158c8 (5 picks + one regen commit). §4 mark + this evidence land in the follow commit.

## Twin-writer disclosure
The earlier continuing incarnation of this welder session committed the five picks (fa17dc725, 9d1cf26ca, 0ab2206e3 @ 02:19:44; 65d8cef15, 0cc426cc1 @ 02:20:09) and the trio regen + dist (16fe158c8 @ 02:22:18) across a context-compression boundary. This finishing pass independently RE-VERIFIED every claim against disk (git cherry, line-multiset equality, slice md5s, all gates, build determinism) and executed the remaining duties: §4 mark, evidence, wave closes W37–W40, push. Zero duplication; one serialized writer lineage.

## Absorption proof
`git cherry master cc/C-TOOL-6.g`: 3 '-' (9d1cf26ca/0ab2206e3/0cc426cc1 patch-id identical) + 2 '+' BOTH content-proven:
- 3fd9e10c3→65d8cef15: +/− line multisets IDENTICAL (patch-id false-positive from context; zero conflict resolution existed).
- 111c077e6→fa17dc725: differs ONLY by the MEDIA.md paragraph re-wrap; landed text coherent ("kind: html — the third case of MediaKind, vocabulary for artifacts at rest, never a generative capability").
Law greps: %ae=detain count 1 and %ce=detain count 1 per landed pick, SEPARATE greps, 5/5; Co-Authored-By 0.

## Trio regen (committed 16fe158c8, re-verified here)
- docs/protocol/candy-code.v1.schema.json 8,170→8,171 lines; +2/−1 single hunk: MEDIA_REF kind enum gains "html" (~:903).
- docs/SERVER.md 1,624→1,626 lines; +2 insertions ZERO deletions. The two MANUAL canvas rows survive VERBATIM at :303-304 (after `POST /api/hooks/{name}`, before `GET /ws`). Zero-deletion diff proves every pre-existing line byte-intact.
- candy-code-web/src-web/protocol/generated.ts 2,415 lines unchanged count; exactly +1/−1 at :227 (kind union image|video|html). generate.submit param enum stays 2-value BY DESIGN.
- dist rebuilt (npm run build on pre-seeded node_modules; NEVER npm ci): assets index-D7O4eLLT.js/index-BmdNjbGT.css → index-C5qC4V7n.js/index-YMJl61cb.css; index.html+manifest.json+sw.js staged. Build re-run by this pass REPRODUCED identical hashes; porcelain zero after commit.

## Preservation (STOP-conditions not met — all md5s equal on merged tree)
- 6.d `## Windows` slice (:1586-1594) = a96a7db6dc0ad643b0681b63e2b94582 MATCH.
- 6.i "Client-hosted tools." para (:646-657) = 761aa2a0cd2b919a76eddc697383c7ec MATCH.
- 6.l shells.input state: schema :1568/:1946/:2001, SERVER table :557, generated.ts :2001 row md5 35692e4e504f5e625381b251a9ac71a2 BYTE-EQUAL.

## Gates (own quotes, merged tree, cwd candy-code, env -u LINES -u COLUMNS plain pipe)
- A4 WELD-MUST-GREEN pairing (BashSandboxTest|BubblewrapHardeningTest):: ONE process → OK 39T/192A GREEN (db1441a61-cured shape).
- A3 (ProtocolSchemaDriftTest|ServerProtocolDocumentationDriftTest):: → OK 13T/362A GREEN-AT-REGEN.
- A7 LayeredSettingsTest:: → OK 99T/423A 0F (0F law holds; 423A inside disclosed 411-423A drift band).
- Own canvas family filter → OK 85T/426A GREEN (lane said 89T/450A; merged per-class CanvasPageTest 9T + CanvasLinksTest 6T — lane's 10+5 per-class split off-by-one, same 15T total; welder quotes own run).
- Guard batch (DTH|TWGR|ReadPathCensus|GlobDialect|StderrEmitter|SwallowingCatch|DocFigure) → 252T/33718A 5F, all classified: StderrEmitterCensus ×2 accepted standing; GlobDialect 1F = NIT-1 (asserted NEITHER number); SwallowingCatch = NIT-2 foreign (ClaudeSettingsImportTest.php:232 at cbbcebb80); ReadPathCensus = NIT-3 NEW BASE-PROVEN foreign red (1784d207f 6.n audio sites lack roster bump; identical at 16d1a94e8 pre-weld; routed to lead).
- BARE TreeWideGuardRosterTest → OK 17T/4007A. BARE DuplicatedTestHelperDriftTest → OK 14T/204A.
- en.php python dupscan → 3,671 keys, 0 dups.
- Vitest serial: lane 3 files (canvasCard/components/auth specs) → 3 files 43T PASS. 6.l trio (shellsInput+processesSection+ansi) → 28 passed / 4 failed = EXACTLY the §7(1) idempotencyKey hand-off state (four named deep-equal legs). Report-not-cure.
- Exclusions honored: PtyRunToolTest::testExpectMiss, RegistryTest::testAStdoutThatShrinksAlone, ForkedChildReaper/Sessions BackgroundShellRegistryTest.

## §7 hand-offs listed (never fixed at weld)
1. durations.tsv NEW rows: CanvasPageTest 9T, CanvasLinksTest 6T (battery mints); canvasCard.spec 7T vitest note.
2. PathGlob 310,245→311,360 corpus re-derive + GlobDialect docblock figure (NIT-1).
3. SwallowingCatch :232 cure (NIT-2, ClaudeSettingsImport lane welder).
4. ReadPathCensus roster verdicts for 6.n audio sites (NIT-3, lead ledger).
5. VITEST-28/4 regen-aware flip rider open.
6. RESTRICTED_TOOL_NAMES += 'Script' LEAD rider pending.
7. web.yml gen:strings LEAD rider pending.
8. suite-figure/README pair re-pin carries this weld's +15T/2-new-file class-share (disclosed; forbidden files untouched).

## Inherited trio state for next welder
schema 8,171 / SERVER.md 1,626 (canvas rows :303-304 must survive) / generated.ts 2,415; pairing 39T/192A expected; same 28/4 trio state expected until §7(1) lands.

## Cleanup
After absorption proof: `git worktree remove ../cc-cmp-C-TOOL-6.g` + `git branch -D cc/C-TOOL-6.g` (done at milestone commit; see §4 mark block).
