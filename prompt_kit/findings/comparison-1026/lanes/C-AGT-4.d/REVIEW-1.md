# C-AGT-4.d — REVIEW-1 (round 1, 2026-10-29)

VERDICT: FINDINGS — 0 CRITICAL / 1 MAJOR / 1 MINOR (step-local) / 0 else.

Tip 1b9baf4a0 (fde5f268b + 1b9baf4a0 over 4dd8c20ce), 16 files +635/-27, tree clean. Authors: 2/2 %ae AND %ce detain@interserver.net (separate greps), zero Co-Authored.

## Verified good (disk-checked, not assumed)

- Bans held: `git diff 4dd8c20ce..HEAD --` EMPTY for src/Hooks, SendMessageTool, SessionMailbox, suite-figure.json, durations.tsv, candy-code-web/dist, lang/**. No lang keys added (notices are raw-English sprintf, sibling-leg house pattern; REPORT's "zero lang touches" is true).
- Section-disjointness: Chat.php hunks only :10338-10365 + :17743-17790; AGT-5.f webhook region (:10618-10634) untouched; AGT-4.e files untouched; NonInteractive edits at run()-scanner + historyFrom, not the :846 co-tenant band.
- Contract shipped on disk: KEYWORD/MENTION_PATTERN `agent:[^\s":]+` colon-form reserved before mcp leg; known→framed directive attachment, unknown/no-roster/throwing-roster→notice-never-guess; TurnController `?\Closure $agentRoster = null` tail chains `->withAgentRoster()`; Chat + SessionHost::admitPrompt + both NonInteractive sites pass live-manager closures (WorkspaceContext::agentManager public readonly ?AgentManager, AgentManager::get :311, Bootstrap::agentManager :2600 — all exist); TUI popup agent: rows + rosterless close; web Composer.vue client-only roster prop (spec-pinned), SessionView.vue wiring = DECLARED deviation, exactly the one the brief anticipated; refusal-costs-nothing pinned (TurnControllerTest no-roster leg keeps notes.txt attaching); popup-row-honesty pinned via real AgentManager in MentionFilePopupTest.
- Mutation-reason: roster thread cut at ContextMentions/TurnController → many reds; at either NonInteractive site → NonInteractiveMentionsTest red (run() real --root -p pin + historyFrom pin); at refusal semantics → ContextMentionsTest + TurnControllerTest red; at popup rows → MentionFilePopupTest red. ONLY the Chat/SessionHost sites are unpinned (MAJOR-1).
- Carries: +11T exact (26→31, 10→12, 7→10, 17→18; DocFigure 92→92 in-method; McpResource 7→7 wording flip). Zero new test files → no durations row owed — both true.
- Gates reproduced: mandated filter `ContextMentions|Mentions|DocFigure|Readme|TurnController|Attachments` → 344T/5920A/3F = staleness pair BY DESIGN (25,537-vs-28,287, closeout re-pin) + AttachPagingTest ×1 — fleet-proven foreign standing red (revert-proved in C-AGT-5.d REPORT; allowed-list in W3/WAVE-REVIEW-1; also reproduces at main-tree master d83e4eed4). REPORT's claimed filters: 269T/5009A OK exact; 329T/5609A OK exact. vitest serial composerFilesImages.spec.ts 15/15 exact.
- Base-14F Chat-family claim: /tmp/opencode/agt4d-base-fails.txt lists exactly the 14 names at base; consistent with batch3/run-state standing reds (ChatHostLangCatalogue, DreamPass etc. documented foreign across sibling lanes). Accepted.

## FINDINGS

1. **MAJOR — no regression pin for the Chat/SessionHost roster thread.** `grep -rn '@agent:' tests/` hits only ContextMentionsTest, TurnControllerTest, MentionFilePopupTest, NonInteractiveMentionsTest; `agentRoster` appears in NO other test. Deleting `agentRoster: $manager …` at src/Chat.php:10365 or `agentRoster: $agentManager …` at src/Host/SessionHost.php:2311 keeps the ENTIRE suite green while TUI/serve silently degrade every `@agent:` send to "no agent roster is open" notices next to popups that still offer the rows — the exact popup-vs-scanner agreement the lane's own new docblock promises, and the exact drift class the existing `TurnControllerMcpSeamTest::testBothHostsFeedTheOneControllerArmingSite` (C-MCP-2.a) exists for mcpProvider.
   **Fix (step-local, test-side only):** in tests/Host/TurnControllerMcpSeamTest.php add a parallel test mirroring that precedent: `assertStringContainsString('agentRoster: $manager', substr($chat, strpos($chat, '$this->turnController()->userTurnMessage('), 800))` and `assertStringContainsString('agentRoster: $agentManager', substr($host, strpos($host, '$turns->userTurnMessage('), 800))` — widen the window to ≥800 chars (the agentRoster lines sit past the current 400-char slice) — plus `assertSame(1, substr_count($controller, '->withAgentRoster('))`. Mutation-prove: strip each call-site arg, the new test must redden, restore clean.

2. **MINOR (step-local) — gate claim truthfulness: "vue-tsc --noEmit: rc=0" is vacuous** (REPORT.md Gates). The root tsconfig.json is `"files": []` + references, so a bare `vue-tsc --noEmit` type-checks nothing and exits 0. The meaningful gate `npm run typecheck` at tip shows 19 unique TS errors (21 lines incl. the dual-build contextSources duplicates) — all in files the lane never touches (contextSources/dormRoster/statusBar/contextPanel/reducer/contextTrustRouting), matching the documented standing base-residual family ("20-error base-residual … NOT lane" batch3 note; 19-standing in C-TUI-6.k). Zero lane-attributable errors; claim wording, not code, is the finding.
   **Fix:** REPORT Gates line → `npm run typecheck: 19 unique standing errors, zero in lane files (matches batch3 base-residual family; root tsconfig files:[] makes bare --noEmit vacuous)`. Welder glance: confirm post-weld error-set delta-empty vs base.

## Notes (no action)

- Twin-writer process note (REPORT): tip bundles the salvage twin's Composer.vue `!props.agentRoster` guard + spec call-shape fixes — content judged law-conform on review; both commits detain/zero-trailers; no duplicate commits observed on disk.
- Deviation ledger: SessionView.vue prop-plumbing — disclosed, brief-anticipated, keep at weld.
- Non-ASCII roster names unscreenable in rankPaths — future roster rule, correctly report-only.
