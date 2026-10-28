# W10 wave review — WAVE-REVIEW-1

Repo /home/sites/sugarcraft · HEAD 4b771634d (weld: C-EXT-2.c) · 2026-10-28
VERDICT: FINDINGS (2 MAJOR wave-fix duties on W10's own picks, 1 MINOR bookkeeping, 1 MINOR attribution correction; all cheap to cure; everything else clean or standing non-W10)

## Scope — the 10 picks reviewed
C-CTX-6.a 4219a10c4 · C-EXT-2.c 8ad2de199 (+664f00d54, weld 4b771634d) · C-SEC-5.c 2a24daeba · C-TOOL-3.a 634fb94f0 (+a1760d1ec, weld bd6b14128) · C-AGT-7.b e359fc884 (+449b0f248; cure 30b7d4f08; NO ✅ mark) · C-CTX-1.c 7f6968f9f (+11e79fa31, weld 6b4de6a80) · C-CTX-2.e 14118b17d (weld bf4faee39) · C-MCP-4.a a6fa47900 (regen 9f02221a0, weld 604ccdc3d) · C-PRV-5.b e2e05598d (weld 854fdd2e9) · C-PRV-5.c 27757dd94 (+d86efaa29, weld dd4cddfb2)

## Claims vs landing — PASS
Every step's `git show --stat` matches its §4 file promise (details in prior lane reports). C-MCP-4.a committed no generated files; regen rode weld 9f02221a0 — correct per §2. C-AGT-7.b: ceremony lead-settled per run-state.json:667; picks on master, branch/worktree pruned.

## Generators — PASS
cwd candy-code, plain pipe: `php scripts/gen-protocol-schema.php --check` + `php tools/gen-command-docs.php --check` + `gen-tool-docs.php --check` + `gen-settings-doc.php --check` all 'up to date' rc0. Web: vitest serial `contextPanel.spec.ts` 19/19 pass; `generated.spec.ts` present at src-web/protocol/__tests__ (dist law: weld 9f02221a0 rebuilt).

## Touched-step gates — PASS
Batched phpunit filters at tip, all OK: [SessionHostIdleAuto|TrustDialog|ToolTailSlots|PtyRunToolProgress] 35T; [JsonSchemaEnvelope|NonInteractiveSessionPersistence|LaunchFlags|InstructionDiffTurnContext] 128T; [AutoCommitTurn|AutoMemoryConsolidationWiring|ContextSourcesMcpUserTier|ContextSources|ToollessBackendThinking] 27T; [PaletteCatalogAsync|OpenAiModelsEndpoint|SkillFork|SkillToolFork|AppSkillDispatch|TaskToolEngine|SkillMention|FrontmatterKeyAudit] 120T; [ProtocolSchemaDrift|ServerProtocolDocumentationDrift|DocFigureProseDrift|InertFrontmatterDocumentationDrift|LangParity|RouteTraitParity] 121T. AccessorDeprecationTest 90T OK at tip (ambient listing stale — not red). PtyRunToolTest never entered any filter (ambient-PTY reds).

## FINDING 1 — MAJOR: StderrEmitterCensusTest 3F is W10-CREATED, not standing (C-AGT-7.b §1.8 breach)
Attribution: `git show e359fc884` adds 2 fwrite(\STDERR sites to Cli/NonInteractive.php (json_schema_unapplied; session_save_failed relocated) and removes 1 → literal count 14→15 at the pick (verified 15 at 449b0f248, 51812d155, base b985fbe51, tip). ArgvParser 'candy-code: ' shapes 36→37 at e359fc884. The base label 'standing, C-MCP-2.a-era' was wrong for the delta: C-MCP-2.a left 14. Red arms at tip: roster NonInteractive 14/scan 15; shapes NonInteractive 10/scan 11 + ArgvParser 35/scan 36; derived arm BinCandyCodeAutoloadGuardTest prose 'thirty-three' vs scan 34 (asserted StderrEmitterCensusTest.php:2366).
Fix (cheap wave-fix): 3 roster rows (fwrite 14→15; shapes 10→11 and 35→36) + census decision sentence + autoload-guard word flip thirty-three→thirty-four.

## FINDING 2 — MAJOR: TreeWideGuardRosterTest 1-of-2 arms W10-CREATED (C-AGT-7.b r2fix)
449b0f248 added the directory glob `glob($path . '/{,.*}*', GLOB_BRACE)` at tests/Cli/JsonSchemaEnvelopeTest.php:305 region without a WALKS_A_DIRECTORY_THE_TEST_MADE row → walker-not-in-buckets + unresolved-walk-not-licensed arms red at tip.
Fix: one license row (file + exact normalized expression).
Standing half: MemoryRecallBlockTest RecursiveDirectoryIterator($this->dir…) — shape from 16266186d C-CTX-6.c (W9, weld 276ea3e9a); NOT W10 — keep, needs its own W9-era row by whoever owns that.

## FINDING 3 — MAJOR: five W10 test files missing from scripts/parallel-tests-durations.tsv → zero shards (§1.5 breach)
Rows never added (grep tsv + `git log -S` each): tests/Tools/ToolTailSlotsTest.php + tests/Tools/PtyRunToolProgressTest.php (634fb94f0), tests/Cli/TrustDialogTest.php (2a24daeba), tests/Context/ContextSourcesMcpUserTierTest.php (a6fa47900), tests/Cli/ToollessBackendThinkingTest.php (e2e05598d). Their welds carried only evidence/run-state (contrast d86efaa29 + 664f00d54 landing rows correctly).
Fix: add 5 durations rows (per-testcase sums) at next weld/closeout.

## FINDING 4 — MINOR: §4 bookkeeping — C-AGT-7.b heading lacks its ✅ <hash> mark (9/10 marked)
Landed per run-state ceremony (picks e359fc884+449b0f248). Stamp ✅ e359fc884 + a dated 'lead-settled, no evidence commit' note.

## FINDING 5 — MINOR: attribution error in run-state — ChatHostLangCatalogueTest seam not C-AGT-7.b
Only key red: chat.effort.launch_unshaped; sole introducing commit a992413b0 C-PRV-1.b (W3); asker LaunchContext.php:2109 outside the test's scan set. Red is byte-identical standing shape; keep but correct the attribution.

## Standing reds — NOT W10's (keep + record)
ReadmeSuiteFigureDrift staleness pair (live 26,500 vs pinned 25,537): re-pin is §7 closeout duty; lanes/gate forbid suite-figure.json edits. Grew partly from W10 tests but that is the designed flow. ProjectTierRefusalInventoryTest 2F: test file untouched b985fbe51..tip; no src diff introduced any `.dir` literal; unclassified dot-paths (AgentMemory.php×2, MacAltNotice.php) + count 48→50 = C-AGT-3.b-era pre-base shape; NOT W10's. TreeWideGuardRoster MemoryRecallBlockTest arm = W9.

## Weld hygiene / laws — PASS
No W10 worktree/branch residue (`cc/*` list empty; worktree list = main + in-flight W11-W16 lanes only). `git log --format='%ae %ce'` on all W10 picks/fixes/welds: detain@interserver.net both fields, zero Co-Authored trailers. Generated files regenerated at welds, never hand-edited. Dormant code wired not deleted: PRV-5.c async catalog via ModelCatalogReadyMsg dispatch arm; E646: connector timeout = discovery probe ceiling (ModelMetadata::DISCOVERY_TIMEOUT_SECONDS=10.0, established pattern), never a chat-completion wall-clock timeout. Fork discipline: only new proc_open sites are in JsonSchemaEnvelopeTest (autoload-dev — check-child-lifetimes walks src roots; each site terminates + proc_close at :305-322, :446-469) — accounted. No new key/lang/tool position collisions surfaced (TrustDialog lang keys rode SEC-5.c commit; gen-*-docs rc0).

## Wave-fix list (single follow-up commit acceptable)
1) StderrEmitterCensus 3 rows + decision sentence + BinCandyCodeAutoloadGuardTest thirty-four. 2) TreeWideGuardRoster WALKS_A_DIRECTORY_THE_TEST_MADE row for JsonSchemaEnvelopeTest glob. 3) durations.tsv 5 rows. 4) §4 ✅ e359fc884 on C-AGT-7.b heading + lead-settled note. 5) run-state attribution: ChatHostLangCatalogueTest → C-PRV-1.b.
