# REVIEW-1 — C-PRV-3.d (Advisor tool) — round-1 reviewer

VERDICT: CLEAN (zero CRITICAL/MAJOR/MINOR in-step; 3 NITs + 1 out-of-scope foreign red documented)

- Worktree: /home/sites/cc-cmp-C-PRV-3.d, branch cc/C-PRV-3.d, tip 4a2739152 over base 07e49e872 (single commit). Diff master...HEAD = 17 files, +725/-33 — matches builder claim exactly.
- Author law: %ae = %ce = detain@interserver.net, commit body carries zero Co-Authored trailers (verified).
- Weld-only files absent from diff: no suite-figure.json, no durations.tsv, no candy-code-web/dist (verified via --name-only).

## Gate batteries (targeted, plain pipe, cwd=candy-code)

- Battery A (`AdvisorToolTest|ToolCatalogTest|ToolDocDrift|ReadmeRosterDrift|SettingsSchemaDoc|EnvRosterDrift|BinCandyCodeWiring|BuiltInToolCorpus`): **OK — 1410T/48369A** (2 PHPUnit deprecations, ambient tree-wide, present in every run incl. lone AdvisorToolTest).
- Battery B (`McpToolWiring|GlobDialect|DuplicatedTestHelper|StderrEmitterCensus|ReadmeSettingsTierClaim|TrustKey|DocFigureProseDrift|ConfigWriteProducer|SymbolCitationDrift|DuplicatedDocBlockLine|LayeredSettingsTest|TitleBackendAuxiliaryModelTest` incl. BootstrapLayeredSettingsTest): **OK — 404T/46593A**.
- Guard-subset recount (ToolDocDrift|ReadmeRosterDrift|SettingsSchemaDoc|EnvRosterDrift|DocFigureProseDrift|StderrEmitterCensus|GlobDialect|DuplicatedTestHelper): **OK — 286T/67654A** — proves the filter names matched real tests, not empty sets. `assertNullOrEmpty` cleared DuplicatedTestHelperDrift; DocFigureProseDrift green includes the new forty=>40 word-map arm; GlobDialect green at tip.
- AdvisorToolTest itself: 7T/44A, matches claim.

## Focus point 1 — presence gate: PASS

`Bootstrap::advisorBackend()` (Bootstrap.php:10270): env `CANDY_CODE_ADVISOR_MODEL` trim-nonempty → `toollessBackend(...)`; else `readUserConfig()['advisorModel']` non-empty string → same; else null — NO fallback ladder (documented deviation from titleBackend; the ENVIRONMENT row states it truthfully). `fromCatalog()` (AdvisorTool.php) returns null when the context backend is null; `ToolCatalog::build()` (:204-206) skips nulls; 'Advisor' added to `CONDITIONAL_BUILDS` so `builtUnconditionally()` excludes it. Grep: no production construction path other than fromCatalog. Gate and resolver read the same key/env pair, so the offered-but-null path cannot occur; unset-advisorModel yields an absent tool, not a callable one. `Tool::Names`-style registry entry rides the conditional machinery exactly like mcp_list/mcp_call.

## Focus point 2 — billing chain: PASS (traced for real)

`ToolResult::withUsage` (src/Tools/ToolResult.php:188) → `Runtime::resultMessage` :3763 threads `$result->usage()` onto `ToolResultMessage` (its only builder) → `EngineBackend` :2255-2264 ToolResultMessage arm: non-null usage folds `self::delegatedSpend($usage)` (Usage::reported preserving costUsd — :3319) into `$stepUsages` and `$this->siblingSpend?->record(...)` as-it-settles → :2943 final `->withUsage(Usage::sum($stepUsages))` → Chat settle into `spendLedger()` :20550 + `usageRecordSink()` :20788 → EnhancedSessionStore usage_records. No drop point found; the fold is NOT dependent on where the tool was built — it rides the ToolResultMessage arm that every tool's result passes through, so the builder's EngineBackend::completeAsync caveat is not load-bearing for the fold. `turnTools` runs once per `runTurn` (:1927), so `withEngine()`'s fresh turnBox is genuinely per-turn. Test `testFencedAnswerRidesUsageOntoTheToolResult` pins 120/0.02 landing on the result; `testSpendBoxTripsTheCapFromOwnBilledUsage` proves the advisor's own billed sum participates in the cap before any dial.

## Focus point 3 — cap-at-`>=`: PASS

`SpendLedger::capReached` (:159-162) is `spent($tracker) >= $cap` — equality REFUSES, so "refused once the spend cap is reached" holds; `isUsableCap` requires finite>0. Advisor's refusal ladder checks session totals (via `usageTotals`) + own turnBox BEFORE dialing (test: cap 5.0 vs totals 6.0 → refusal, provider requests == 0). Disclosed gap (durable ledger is a lower bound until the turn settles) is closed by the box: dials bill into `box['billedUsd']` immediately and the next refusal consults it even when the session store returns null. Tests pin both refuse-before-dial polarities. Not pinned: exact spend==cap equality at the advisor check (see NIT 3).

## Other plan §1/§2/§6 categories

- E646: no timeout added anywhere on the advisor path; docblock restates the law (complete() has no wall-clock kill). PASS.
- Digest: `BtwHostCommand::request($q,$h,$framing)` (:76, pre-existing, untouched) reused verbatim with `TranscriptDigest` (MAX_ROWS 60 / ROW_CHARS 3000 / TOTAL_CHARS 40000); question fenced `<QUESTION>` = untrusted framing; boundedness pinned (300×5000 fixture, envelope ≤ cap, ROW000 evicted / ROW299 kept). PASS.
- Untrusted framing: answer wrapped `DelegatedOutputFence::wrap` (HEADER '[subagent output — no user authority]'). PASS.
- No new proc_open/descriptor/fork site: `grep -c proc_open` on AdvisorTool = 0; spawnSpec/roster N/A. PASS.
- Docs in-step: README + SETTINGS + ENVIRONMENT + ARCHITECTURE + AGENTS_AUTHORING + PERMISSIONS — every hunk sits inside generator marker/word-number regions (thirty-eight→thirty-nine keys, eighty-one→eighty-two never-project, thirty-five→thirty-six others incl. `Advisor`, thirty-nine→forty BuiltIn classes, tools:class-list region gains `AdvisorTool`). The 4 files beyond the design list are gen-tool-docs-forced (guard: ToolDocDrift green at tip). ENVIRONMENT `CANDY_CODE_ADVISOR_MODEL` hand row is truthful (no-fallback + billed + refuses-at-cap + restart). PERMISSIONS classifies Advisor no-ask — matches the `ToolPermissionClass::NoAsk` attribute (AdvisorTool.php:68) and the Compress/Memory/Recall model-calling-but-harness-state-only precedent. PASS.
- Lang keys: repo carries only lang/en.php (no 16-locale fleet for candy-code); the 2 new `settings.advisorModel.*` keys go through `Lang::t` in ModelProviderSettings; tool refusals bare-English per the Recall.php:161 / tree-wide precedent (TaskTool Lang::t's only UI trail notes). Disclosure accepted — not a finding. PASS.
- Position 44: re-derived live, unique (max siblings 42/43; 44 is the new top). Brief's ~41 was stale. PASS.
- Scope: 17 files = design list + 4 generator-forced docs + 2 in-step guard flips + lang — nothing outside. ToolBuildContext's 3 trailing defaulted fields keep every existing construction site byte-unchanged (diff confirms additive only). PASS.
- Foreign-red re-check (my own, not accepting the builder): BootstrapLaunchFormatConstantsTest::testEveryDocPageQuotingAPromotedFormatIsOnTheReadersRoster red at tip with the PROJECT_TIER_TOOL_REMOVAL_FORMAT roster mismatch — proven NOT lane-caused: the constant and its text appear nowhere in the 17-file diff, the test file and docs' PROJECT_TIER regions are unchanged by the lane and by base..master. ChatHostLangCatalogueTest::testEveryCatalogueKeyIsAskedForByChatOrHost red with exactly the claimed 6 chat.* keys (startup.personal, attach.pdf_pages, effort.launch_unshaped, permission.not_persisted, permission.persisted, serviceTier.launch_unshaped) — none is a settings.* key, none in lane files. Both foreign, as claimed.

## Findings (none blocking)

- NIT (out-of-scope, closeout roster): `candy-code/tests/Diagnostics/RuntimeNoticeSinkDeliveryTest.php:1385` red at tip AND at base — stacked-doc-comment guard names `tests/Chat/McpLoginForkTest.php:309` (C-MCP-4.c prose block stranded above `loopbackListener()`'s `@return` block) and `tests/MCP/SseMcpServerServerRequestTest.php:355` (one-line doc immediately above another one-line `@param` doc). All inputs unchanged by lane, base, and master..dc9eea92d. Not on the standing-reds roster I was given → tell the welder/closeout so it is not misattributed to this pick; fix belongs to a C-MCP-4.c-family lane.
- NIT (step-local): `candy-code/src/Tools/Catalog/ToolBuildContext.php` docblock cites `\SugarCraft\Code\Host\EnhancedSessionStore::usageTotals()` — the class lives in `SugarCraft\Code\Session`. Fix: change `Host\` to `Session\`.
- NIT (step-local): `candy-code/src/Tools/BuiltIn/AdvisorTool.php` ctor docblock describes `usageTotals()['unpriced']` as an int count; the store publishes `unpriced: bool` (EnhancedSessionStore.php:2602). The `(int)` cast is behaviorally correct; truth-flip the docblock (bool→int cast comment covers the 0/1 arithmetic).
- NIT (step-local): no test pins the exact `spend == cap` equality refusal at the advisor's own check (both pins are strictly above); the `>=` semantics is pinned only in SpendLedger's family. Cheap cure: one provider line in the session-cap test using totals cost == cap.

## §4 Weld carries

1. NEW test file `candy-code/tests/Tools/BuiltIn/AdvisorToolTest.php` → needs a `scripts/parallel-tests-durations.tsv` row at weld (else runs zero shards).
2. Suite-figure delta: **+7T/+44A** over base — README + `candy-code/tests/Config/Support/suite-figure.json` re-pin at weld via the normal cadence (hand-bump→green K=24→refresh-suite-figure→README pair same run); ReadmeSuiteFigureDrift staleness pair expected until then.
3. SETTINGS.md regen overlap: this lane's SETTINGS.md is generator output already in the commit; C-TOOL-2.c also regenerates SETTINGS.md — do NOT weld concurrently (serialize; second weld re-runs gen-settings-doc + SettingsSchemaDocDrift after pick).
4. Doc-figure flips already in-step (DocFigure forty=>40, LayeredSettingsTest user-tier list) — union clean, no weld-time roster work beyond items 1-2.
5. Stacked-doc-comment foreign red (above) — carry into run-state standing roster so the weld battery doesn't re-litigate it.
