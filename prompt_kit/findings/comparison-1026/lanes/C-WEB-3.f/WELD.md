# C-WEB-3.f weld record (W12, protocol+web) — 2026-10-28

PICK 6174f871f → master **bf42820d8**, zero conflicts (MethodSchemas.php auto-merged);
patch-id 038d3b37272f9988c9de45c0209348b461a26953 identical both sides → no
conflict-resolution content to re-verify. Author law %ae/%ce separate greps =1/1, zero
trailers, `-x` trailer absent from body (bare -x line only in git cherry identity).

REGEN RIDER **63f2de5d9** `weld: C-WEB-3.f regenerate`: gen-protocol-schema.php --write
rewrote candy-code/docs/protocol/candy-code.v1.schema.json + candy-code/docs/SERVER.md;
npm run gen:protocol rewrote candy-code-web/src-web/protocol/generated.ts (generate.submit
gains kind enum + 7 optional video keys). npm run build DID change dist this time
(GeneratePanel.vue + App.vue ship in the web surface): index-R1TvqIcX.js +
index-CC3Upv5a.css replace index-BoMiRCkM.js + index-DPvz5jdK.css; index.html +
manifest.json re-hashed. 7 files total.

DURATIONS RIDER **52fa52a92**: row candy-code/tests/Protocol/GenerateVideoSubmitTest.php
0.522/5 (junit per-testcase sum at merged tip), inserted inside the 0.522 tie block after
Backend/EngineBackendFrameSocketInheritanceTest, before ClaudeCodeMcpClientTest 0.521.
Set-equality 1488→1489 (gained exactly this file, lost none); pick's ONLY new *Test.php
is GenerateVideoSubmitTest (create-mode census). MediaMethodsTest keeps its existing row
(1.211/14, modified-existing per precedent — legs widened, count moved 14→15 with one
rename, figure update is closeout domain). 4 pre-existing desc-violations unchanged.

GATES (merged tree, plain pipe, env -u LINES -u COLUMNS):
- php --filter 'MediaMethods|GenerateVideoSubmit': 21T/124A green. Per-file junit sums:
  GenerateVideoSubmitTest 5T/33A, MediaMethodsTest 15T/85A, BinCandyCodeWiringTest 1T/6A
  (unsuffixed-filter provider leg, known shape).
- ProtocolSchemaDriftTest|ServerProtocolDocumentationDriftTest: 12T/332A green vs the
  regenerated trio, and re-run green AFTER the MEDIA.md cure.
- vitest --no-file-parallelism: touched pair (generatePanel 14 + topbarGenerate 5) 19/19;
  FIX-1 shape 24/24 (pair + app.spec.ts); broad media sweep 51/51 (+ media + MediaCard);
  builder's 6-file protocol set 63/63. The lead-brief's "24/24 + 51/51" reproduces EXACTLY
  as the FIX-1 sets (verified, not assumed).
- vue-tsc --noEmit rc 0 zero errors (REPORT claim); vue-tsc --build: EXACTLY 19 standing
  errors (contextPanel.spec 8 / reducer.spec 4 / contextSources.ts 4 / statusBar.spec 2 /
  contextTrustRouting.spec 1) — zero new; seam-3 (pre-weld typecheck error on committed
  trio) cured by rider 63f2de5d9 landing before this gate.

MEDIA.md CURE (rode the final weld commit): :174 "(until C-WEB-3.f wired it here)" →
"(until the web Generate panel wired it here)" — REVIEW-1 finding 1 (only campaign-ID
token in shipped docs; grep of tests/ shows the literal only in TEST-COMMENT/docblock
prose (MediaMethodsTest:441, GenerateVideoSubmitTest:27/:121/:239), never a guard needle;
docs/ re-grep after cure: zero hits).

REVIEW-CHAIN NOTE: REVIEW-1 was FINDINGS (0C/0M/1 minor + 2 seams), not blanket CLEAN —
minor = MEDIA.md ID (cured above); seam-2 = App.vue:103 button gate, CLOSED by the lane's
own round-2 re-fix (FIX-1, amended tip 6174f871f) which ALSO exposed a phantom-cure
anomaly: a prior fixer round had claimed cure commit a0141a99c that never existed
(lead-cleared; branch was unmodified at 06463ea76 when round 2 started). FIX-1 root-caused
seam-2 to the TOP-BAR BUTTON (the reviewer's ~:443-455 GeneratePanel cite matched no line
of the 257-line file — panel tab-gating was already correct+pinned); cure widened
App.vue:103 to canGenerateImage||canGenerateVideo with in-step polarity flip of the
defect-pinning topbar spec + new video-only-entry chain pin, mutation-proven (gate revert
→ exactly 2 reds, md5 restore). Seam-3 expected-pre-weld, discharged by regen ordering.

For closeout (not welder scope): suite-figure live-enum +5T re-pin (reviewer roster row).
