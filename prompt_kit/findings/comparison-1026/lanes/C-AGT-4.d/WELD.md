# C-AGT-4.d — WELD record (batch-18o, 2026-10-29)

- Base: master d9a3dc110 (origin c72edd4a1 already an ancestor; no FF-pull needed — master ahead of origin, no push this batch).
- Picks (zero-conflict, order fde5f268b → 1b9baf4a0 → 73a589cae):
  - fde5f268b -> 7c87beae6 (salvaged first cut, 14 files 604+/26−)
  - 1b9baf4a0 -> c384ca46e (popup guard + docs rows, 5 files)
  - 73a589cae -> cd2062466 (r1 MAJOR cure, tests-only TurnControllerMcpSeamTest +30)
  - Authors carried: %ae AND %ce = detain@interserver.net on all 3 (separate greps), zero Co-Authored trailers (pre- and post-pick).
- Review chain: REVIEW-1 1 MAJOR / 1 MINOR → FIX-1 (73a589cae) → REVIEW-2 CLEAN 0C/0M/0 (verified on disk before welding).
- SessionView.vue deviation (web prop-plumbing): KEPT per REVIEW-2 CLEAN ("DECLARED deviation ... exactly the one the brief anticipated" — r1 verified, r2 carried).
- Gates at merged tree (cwd=candy-code -c phpunit.xml env -u LINES -u COLUMNS plain pipe, linked vendor):
  - lane filter ContextMentions|AgentMention|NonInteractiveMentions|SessionHost|TurnController|DocFigureProseDrift|MentionFilePopup: 273 tests / 5031 assertions / 0F (2 pre-existing AccessorDeprecationTest deprecations). Lane-tip reported 269 — +4T are neighbor class-wide rows landed since the lane cut, attributed.
  - guard family ReadmeSuiteFigureDrift|DocFigureProseDrift|GlobDialectDifferential|ChildWallClockBudget|DuplicatedTestHelperDrift|SymbolCitationDrift: 143T/35191A, sole reds = ReadmeSuiteFigureDrift staleness pair (pinned 25537 vs live 28379) — EXPECTED standing state, suite-figure.json untouched (closeout duty; lane +11T carry included).
  - vitest full serial (--no-file-parallelism, web files moved): 88 test files / 710 tests PASSED, rc0.
- Protocol trio: lane diff touches no Protocol/Schema/DTO paths — no gen-protocol-schema, no gen:protocol; docs/protocol/** + src/generated.ts byte-untouched (git status verified).
- dist: rebuilt on merged tree (npm run build) — hashed asset swap index-CDJzcAVO.css→index-B9-EoUEn.css, index-ssB6B1oX.js→index-uLpWc6LM.js + index.html + manifest.json, rides the weld commit per plan dist-rule (line 111) and REPORT DUR carry; lane commits contained zero dist hunks (diff-verified).
- durations.tsv: no new phpunit test FILES in the lane diff (all 7 touched Test.php pre-existing and rostered) — no rows minted.
- run-state: folded from lead's freshest live working copy (sha256 61939ca0...); welded += C-AGT-4.d → 236; C-AGT-4.d cleared from weld_pending (it had left inflight earlier); all other lead keys (incl C-SEC-7.c weld_pending row, tick 331) preserved; exact tip rides the meta follow-up (a commit cannot contain its own SHA).
- §4 mark ✅ 73a589cae written; §3 W22 row NOT flipped — all 10 W22 steps are now welded, the wave loop (§1.6) is the lead's next actor.
