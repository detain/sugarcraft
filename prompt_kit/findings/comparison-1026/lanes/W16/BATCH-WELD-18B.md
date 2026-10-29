# BATCH-WELD-18B (welder-18b, 2026-10-29)

Base: master 103a385aa (weld-18a close record). Branch: weld-18b @ /home/sites/cc-cmp-weld-18b.
Lanes (wave-then-listed): C-CTX-3.c (W21, 2 commits c33e189af, 93c08ab87; base 86f80fef8), C-MCP-3.d (W22, chain c3de5c442→9b34f63bd→925d39ad0→e0ff98c4c; base 6742a2ad3). Both r2 CLEAN on disk (lanes/*/REVIEW-2.md).

## Picks
- [x] C-CTX-3.c: c33e189af→0b7cac1c4, 93c08ab87→5ad8f8572
  - CONFLICT (trivial, resolved by keeping BOTH): MethodSchemas.php top-of-file `use` import block — HEAD had `use ...\Hooks\BuiltIn\AuditLogReader;` (landed by a later wave), lane adds `use ...\Context\InstructionFileLoader;` at the same spot. Both kept, alphabetical. Schema-body entries auto-merged. Real concurrency claim intact (disjoint method regions).
- [x] C-MCP-3.d: c3de5c442→ab4d3bcb5, 9b34f63bd→2678de8a9, 925d39ad0→51340c0c3, e0ff98c4c→5aeb66912 (all zero-conflict; EngineBackend/TurnInbox/McpCallTool hunks auto-merged beside landed MCP-2.c/4.d work)

## Gates (merged tree, worktree cwd=candy-code, env -u LINES -u COLUMNS plain pipe; vitest serial --no-file-parallelism)
- [x] php -l 15 touched files: clean.
- [x] filter ContextMethods|ContextInstruction → 12T green (within 61T/2F run where the 2F are only ContainedPathInventory arms below).
- [x] ContainedPathInventory 2 arms RED at base 103a385aa too (identical 49T/83A/2F, testTheRoutedCallSiteInventory + testTheHandSpelledInventoryIncludingItsStatedExclusion) → disclosed foreign base-red, left.
- [x] ReadPathCensus: 19T/1F merged == base — same single hunk Chat.php|file_get_contents 4-vs-3 (W18 fixer domain; 18a cured takeIpcPayload, preset-mode-key row NOT cured here per brief). MCP-3.d +8 registry-sink assertions classify clean (694→702A).
- [x] filter McpBackground|McpToolProgress|TurnInbox|DescriptorInheritanceGuard|TreeWideGuardRoster → 76T/9141A exit 0.
- [x] ForkedChildReaperAdoptionTest: identical 1 arm red at base and merged (testNoDirectoryWithUnreapedForksIsUnaccountedFor, 6T/37A/1F) → disclosed foreign base-red (C-TOOL-3.c debt), left.
- [x] ProtocolSchemaDrift|ServerProtocolDocumentationDrift → 13T/362A green AFTER rider regen (judged on merged+regenerated tree per §1.8).
- [x] vitest serial: composerQuickAdd + composerMcpMentions + contextPanelEdit + dormantRoster + generated → 5 files / 27 tests PASS (Composer specs coexist with landed MCP-2.c @mcp popup).
- [x] ReadmeSuiteFigureDrift staleness pair red by design (2 arms; +26T live enum from new files; re-pin = §7 closeout duty, untouched).
- Standing untouched: all other §7-era standing reds per run-state (MenuBar/CommandFailureTranscript/PermissionsIntroDrift/ForeignAgentPresetWiring/HelpTest etc. — none in the run filters above).

## Riders
- [x] a82e19a4e: protocol trio regen (gen-protocol-schema --write → schema.json +68, SERVER.md +1 line; gen-command-docs/gen-tool-docs/gen-settings-doc --write → zero drift) + npm run gen:protocol (generated.ts +25) + npm run build once → dist (manifest+index.html+assets, old pair deleted), determinism re-build verified, amend-folded to land all 9 files.
- [x] 678ff50a0: DUR rows x2, scripts/parallel-tests-durations.tsv 1618→1620 (sorted-by-duration insert; measured green single-file runs):
  - candy-code/tests/MCP/McpBackgroundCallTest.php 3.141/15 (OK 15T/123A)
  - candy-code/tests/Protocol/ContextInstructionWriteTest.php 1.536/11 (OK 11T/54A)

## Ledger — DONE
- [x] §4 marks: C-CTX-3.c + C-MCP-3.d (heading ✅ with pick maps, per 18a format). No §3 flip: W21 (8 unwelded besides 3.c) and W22 (9 unwelded besides 3.d) stay open — established format = §3 touched only at wave close.
- [x] Evidence: lanes/C-CTX-3.c + lanes/C-MCP-3.d full dirs (main-tree copies authoritative, byte-identity proven pre-merge) + lanes/W16/BATCH-WELD-18B.md — all landed in meta.
- [x] run-state: welded += C-CTX-3.c C-MCP-3.d (227→229, sorted insert); weld_pending cleared of both → {}; tip=678ff50a0 at meta ed5e7aa83, stamped to ed5e7aa83 by follow-up one-liner cc2f9d387 (commit can't carry own sha).
- [x] FF main tree: 103a385aa → cc2f9d387 (lead run-state dirt backed up to /tmp/opencode/lead-1029/run-state-pre-ff18b-lead.json and reverted for the FF; lead untracked dirs survive). origin/master stays a612fb008, master ahead 24 → NO PUSH, no wave closes.

## Absorption proofs (git cherry master <lane>, post-FF)
- cc/C-MCP-3.d: 4/4 '-' (c3de5c442 9b34f63bd 925d39ad0 e0ff98c4c).
- cc/C-CTX-3.c: 93c08ab87 '-'; c33e189af '-' by CONTENT (git-cherry '+' = patch-id context drift — MethodSchemas.php use-block conflict resolution kept BOTH imports; diff of +/- lines vs pick 0b7cac1c4 = IDENTICAL; master blob carries InstructionFileLoader + AuditLogReader at :7-8).

## Prune — DONE (post-FF, after proofs)
- [x] cc/C-CTX-3.c + /home/sites/cc-cmp-C-CTX-3.c
- [x] cc/C-MCP-3.d + /home/sites/cc-cmp-C-MCP-3.d
- [x] /home/sites/cc-cmp-C-MCP-3d-base (reviewer base fixture)
- [x] /home/sites/cc-cmp-weld-18b worktree (branch weld-18b kept at cc2f9d387)
- [x] live untouched: C-MCP-4.a C-AGT-4.d C-SES-7.g C-AGT-5.f C-SEC-8.f C-PRV-4.b C-AGT-4.e + weld-18 (18a-era)
