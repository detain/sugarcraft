# WAVE 20 — merged-tree review, round 1
Reviewer: cc-cmp wave-reviewer, 2026-10-28. Master at read time: e91eb1d47 (tip moved past review base 718aa187f by batch-13 doc/re-sync commits — all W20 claims re-verified at e91eb1d47). Read-only except this file (UNTRACKED per law).

## VERDICT: FINDINGS
- 2 MAJOR (W20-owned, both test-shape fixes, fix-hold CLEAN)
- 1 MAJOR (INHERITED from W19 C-CTX-2.f, already rostered foreign at batch-12 weld — route to lead/W19 fixer, fix-hold CLEAN)
- 5 NIT (plan-row overclaims + house-convention marks + one foreign census roster gap)

## 1. §4 mark re-derivation — PASS
All 10 members' marks exist; ancestors/landing verified (full map in compressed lane notes): C-SES-7.e 14bb7487f->landed 3b6c2ca12+61c984cec (weld bb8a83ce0); C-AGT-5.d d3d4b54f4 ANC (chain 3596fa061+60915338b+d3d4b54f4); C-AGT-6.c f2534f502->237224a7a (stat-identical, 13 files 853+/17-)+cure c36143800; C-CTX-3.a e80d84b40 ANC; C-CTX-3.d 8754885cb ANC; C-CTX-4.a ce48359fe->42939645a+603d2f167 (stat-identical); C-CTX-5.a b3f5cea29->aa8bdbeea+53b8e0fbc+972e0960b (notice-cap pin confirmed at CacheReusingSummaryBackendTest.php:116); C-EXT-3.b f5b8a273b->a42d85304+93e301ef2; C-EXT-3.d f227b2b56 ANC; C-EXT-4.c d19029a14->0992a65b3+ae8baac43.
Author identity on all 27 checked commits (marks + picks + welds + cures): `%ae` and `%ce` both detain@interserver.net; zero Co-Authored-By trailers. PASS.

## 2. Cross-lane union — PASS
Chat.php: CTX-3.a handleInstructionsCommand (:16343) + memoryOpenArm (:16279) + EXT-3.d draft arm in handleAgentsCommand (:13402/:13418) coexist; no dropped-theirs. lang/en.php duplicate-key scan: EMPTY. ServerConfig.php AGT-5.d webhooks shape (:90-143, validated-at-launch :255-260) fuses clean with AUT-6 scaffold; SES-7.e ephemeral lives SessionHub/SessionHost only — disjoint. Protocol trio: gen-protocol-schema --check rc0 at tip. README/tools:roster fusion + GlobDialect re-derive (ed5fbffed, 310,245=559x555) consistent.

## 3. Battery (targeted, cwd=candy-code, plain pipe) — quoted results
- SessionImportMethod|SessionArchiveMethods|SessionHubTest|HookWebhooks|ServerConfigWebhooks: OK 87T/2534A
- MainWorktree|ArgvParser|LaunchFlags|InstructionsCommand|MemoryRecallSources: OK 357T/1331A
- TurnContext|EnvironmentBlock|PromptPrefix|CacheReusingSummary|CompactionFallback|AgentsNewAccept|SkillsSearch|SkillMatcher: 5E — ALL confined to InstructionDiffTracker null-$seen (MAJOR-3); rest green
- CacheReusingSummaryBackend|CompactionFallbackParity|AgentsNewAcceptTest|SkillsSearchTest|SkillMatcherListingTest: OK 67T/388A
- BundleCommandNamespace|ForeignCommandDiscovery|BundleSurfaceWiring|CommandDocsDrift|ContextSources: OK 48T/258A
- ReadmeRosterDrift|DocFigureProseDrift|EnvRosterDrift|KeyBindingDrift|TrustKeyDrift|ConfigWriteProducerDrift|StderrEmitterCensus|BinCandyCodeAutoloadGuard: OK 496T/67980A
- vitest serial (--no-file-parallelism): dormantRoster + sessionsRowActions + sidebarActions + generated = 4 files 27 tests passed
- ReadmeSuiteFigureDrift: 2F = the staleness pair — EXPECTED per §7 (closeout re-pin carry).

## 4. Standing-red roster re-proof (content, vs W20 blame)
Filter ProjectTierRefusalInventory|ContainedPathInventory|ForeignAgentPresetWiring: 5F — all three classes are the adjudicated standing reds, identities reproduced in lane C-EXT-3.d REPORT:29 (pristine-base detached runs) and REVIEW-2:10. NIT: EXT-3.d's NewAgentPrompt.php adds 2 unclassified dot-path rows to an ALREADY-red census (identity unchanged; roster absorbed by whoever fixes the census — fixer note, not green->red).
Filter ChatHostLangCatalogue|CliLangCatalogue|PermissionsIntroDrift|DreamPass|KeyHelpTest|OneSidedHomeSandbox|SwallowingCatch: 11F. Attributions: ChatHost 1F, DreamPass:288 1F, KeyHelp 5F, PermissionsIntroDrift 1F, CliLangCatalogue 0F (cleared batch-13), ContainedPath 2F — all on the rostered standing set. TWO are W20 (see MAJOR-1/-2). OneSidedHomeSandbox actual-vs-expected adds EXACTLY 2 files: CacheReusingSummaryBackendTest (W20, MAJOR-2) and InstructionWalkPastRootTest (C-CTX-4.c d361720d9 — foreign, carried). SwallowingCatchSrcCensus SkillTool::execute bare-empty catch = C-EXT-2.c 8ad2de199 — foreign NIT (closeout roster).

## 5. Generators
candy-code: gen-command-docs --check "command docs: up to date"; gen-settings-doc "settings docs: up to date"; gen-tool-docs "tool docs: up to date"; gen-protocol-schema "protocol schema: up to date" — all rc0. Root gen-docs/gen-ansi pair NOT executable in this harness (bash permission map denies root-cwd php); W20 picks touch ZERO inputs of either (no docs/_data, no ansi/, all paths candy-code/candy-code-web) — verify at closeout.

## 6. durations.tsv — PASS
All 12 W20-minted test files have rows (SessionArchive 6.174/7, MainWorktree 6.058/17, SessionImport 5.974/9, CompactionFallbackParity 5.562/7, CacheReusingSummary 5.148/11, AgentsNewAccept 4.926/21, BundleCommandNamespace 4.878/9, HookWebhooksSignature 2.90/10, MemoryRecallSources 0.29/14, InstructionsCommand 0.107/11, TurnContextDate 0.028/7, SkillsSearch 0.006/16). Descending-order violations EXACTLY 4 (lines 1588/1589/1592/1596), none W20-adjacent; batch-13 minted 5 more rows since (violations still 4).

## 7. Findings
- MAJOR-1 (W20, C-AGT-6.c): tests/Cli/MainWorktreeTest.php:137 and :178 — `try { …; $this->fail(…); } catch (\RuntimeException $e)` swallows PHPUnit ExpectationFailedException (extends RuntimeException) = silent-green shape; reddens SwallowingCatchCensusTest::testNoTestSwallowsTheFailureStandingNextToIt (census was NOT in the batch-11 weld's 730T battery). FIX: capture-then-assert-after-try (house pattern, lane-cf/round-61 precedent). File touched by NO in-flight lane -> fix-hold CLEAN.
- MAJOR-2 (W20, C-CTX-5.a): tests/Backend/CacheReusingSummaryBackendTest.php lands in OneSidedHomeSandboxTest's migration backlog ("may only shrink") — one-sided HOME sandbox writes dev's real ~/.candy-code per shard reader. FIX: adopt HomeSandboxTrait (or move both putenv + $_SERVER spellings). No in-flight claim -> fix-hold CLEAN. (Guard was red pre-fix via C-CTX-4.c's file too — wave fixer should land its row in the same test-run but must NOT edit InstructionWalkPastRootTest.php: that's CTX-4.c's carry.)
- MAJOR-3 (INHERITED W19, not W20): src/Context/InstructionDiffTracker.php — capture() (~:240) never initializes $seen; zero-document loaders reach :337 `new self($frozen, $seen, $loader)` -> TypeError (5E: EnvironmentBlockTest x4, SystemPromptWiringTest env-reaches-provider). git diff 87f26fdf2..HEAD -- file EMPTY (no W20 touch); culprit 87f26fdf2 C-CTX-2.f; batch-12 weld cac93e391 already logged foreign. FIX: `$seen = [];` alongside `$frozen = [];` at capture() head + a zero-document pin. File claimed by NO in-flight lane (CTX-3.f owns MemoryWriter/MemoryEntry/MemoryTool/RulePathNudge; PRV-4.a owns Providers/*,Bootstrap,SessionHost,OtelConfig,ENVIRONMENT.md) -> fix-hold CLEAN, but any combined fix must avoid ENVIRONMENT.md + MemoryTool.php.
- NIT-1..4: plan §4 row overclaims — C-AGT-6.c row claims src/Cli/Help.php modify (landed without it; Help.php has no launch-flag roster on master; README covers the flag); C-EXT-3.b claims tests/Tools/BuiltInToolCorpusTest.php modify (untouched, green); C-EXT-3.d claims tests/Chat/PaletteSupersetDispatchTest.php modify (untouched — subcommand arms need no palette entry); C-EXT-4.c claims ForeignCommandDiscoveryTest+BundleSurfaceWiringTest modify (landed in neither pick; both green in battery). Lead may annotate rows at closeout.
- NIT-5: 6 of 10 §4 marks cite lane-branch SHAs not ancestors of master (14bb7487f, f2534f502, ce48359fe, b3f5cea29, f5b8a273b, d19029a14); weld messages carry the pick mapping; pre-existing house convention repo-wide in this plan (e.g. 963ddd0f6 C-TUI-5.d) — not a W20 regression.

## 8. Weld hygiene — PASS
No weld-only file committed by any lane; generated trio/dist regenerated not hand-edited (bb8a83ce0/61c984cec dist rebuild-by-design); no W20 worktree/branch left (lane branches pruned at welds per batch notes — `git branch --list 'cc/*'` check belongs to lead; my read showed none named cc-cmp/W20 in candy-code refs). E646: no blanket LLM timers in any W20 diff (caps are size/count/byte bounds); dormant code wired not deleted (MemoryRecallSources consumes SessionSearchIndex/CompactionJournal; webhook dedupe wired at handle()); fork/child discipline untouched by W20 picks (no proc_open changes).

## 9. Reachability hunt (TUI-1/SES-1 class) — PASS
- C-AGT-5.d signed-webhook: production entry `bin/candy-code serve` -> Cli/Serve.php:447 constructs HookWebhooks from ServerConfig->webhooks (validated at launch, ServerConfig.php:255-260) -> ApiController.php:62-63 dispatches POST /api/hooks/{name} -> HookWebhooks::handle() :123 signature verify + delivery-id dedupe. Default-off mount (null when unconfigured -> 404, documented). Reachable, gated, pinned (HookWebhooksSignatureTest 10 cases green).
- C-SES-7.e: session.archive/unarchive registered SessionMethods.php:103-104 (+ MethodSchemas:224, trio rc0); web store callers sessions.ts:151/:158 + SessionSidebar.vue actions (vitest green). session.import: server handler SessionMethods.php:524 wired; browser import dialog intentionally deferred — rostered in dormantRoster.spec.ts:27-29 with dated C-SES-7.e comment (guard green 27/27 vitest). No phantom surface.

## Fix-hold collision matrix (in-flight: MCP-2.c, EXT-5.b, AGT-4.b, CTX-3.f, CTX-3.e, PRV-4.a)
MAJOR-1 MainWorktreeTest.php: no claim. MAJOR-2 CacheReusingSummaryBackendTest.php: no claim (PRV-4.a claims Providers/*, not Backend tests). MAJOR-3 InstructionDiffTracker.php: no claim. AVOID in any wave fix: Chat.php (AGT-4.b), ENVIRONMENT.md + SessionHost + Providers/* + Bootstrap + OtelConfig (PRV-4.a), MemoryTool/MemoryWriter/MemoryEntry/RulePathNudge (CTX-3.f), MemoryCommand/ForeignMemoryImporter (CTX-3.e), palette files (EXT-5.b), web composer (MCP-2.c). None of the three fixes needs those files.
