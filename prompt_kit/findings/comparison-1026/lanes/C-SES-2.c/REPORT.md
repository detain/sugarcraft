# C-SES-2.c — DONE (commit 74dafe7c3 on cc/C-SES-2.c, base e6d225f68)
Files (12): SessionHub.php (list gains ?string $query → listSessionsFiltered(SessionQuery::new()->withBodySearch) — SessionRow objects converted via toArray() with bodySnippet attached separately, toArray left untouched; cursor paging shared), SessionMethods.php (list delegates to hub), ServerContext.php (summary gains snippet + spendUnpriced from row cost_unpriced; spentUsd falls back to cached row cost_usd when no live host), Definitions.php (D::USAGE +unpricedModel nullable; SESSION_SUMMARY +snippet +spendUnpriced), tests/Server/SessionListBodySearchTest.php NEW, tests/Host/SessionHubTest.php, reducer.ts (usage.updated latches state.unpriced from non-empty unpricedModel), sessions.ts (search(query) debounced 250ms → session.list{query}, stale-race guarded), SessionSidebar.vue (filter routes to server; snippet + spendLabel rows), reducer.spec.ts, statusBar.spec.ts, sidebar.spec.ts.
Regen run in-lane (php scripts/gen-protocol-schema.php --write → schema +15 lines; npm run gen:protocol → generated.ts) to exercise guards, then RESTORED before commit.
Gates: cwd candy-code --filter 'SessionMethods|SessionHub|ServerContext|ProtocolSchemaDrift|ServerProtocolDocumentationDrift' → OK, 21 tests/385 assertions, rc=0 (new file alone: 4T/17A). Web vitest serial stores+sidebar+statusBar+protocol → 177 passed (22 files). Five-guard → Tests: 133/15,348A, Failures: 2 = ONLY the staleness pair (pinned 25,537 vs live 25,658).
Deviations/limits:
1. Spawn prompt said "commit the generated files"; agent law + plan §1.8 say never commit the three generated protocol files — law/precedent wins: regenerated, guards run green WITH them, restored; welder regenerates. dist not touched.
2. PHPUnit Deprecations: 2 in PHP gate = pre-existing tests/Config/AccessorDeprecationTest.php private data providers (base-committed, out of lane).
3. Behavior note: the query arm uses SessionQuery::new() defaults → archived/subagent kinds now hidden from filtered results, and hits are FTS-ranked body matches (name-only matches no longer hit when FTS answers; LIKE degrade keeps name matching).
4. NEW file candy-code/tests/Server/SessionListBodySearchTest.php (4T) — gate owes it a scripts/parallel-tests-durations.tsv row; file never touched here.
suite-figure.json untouched. Author %ae/%ce detain 1/1, zero Co-Authored-By, never pushed.

## Round 2 fixes
Commit d89efd151 (tip, on 74dafe7c3). Single MINOR from REVIEW-1.md: deleted the stale docblock stacked above the C-SES-2.c block on list() — SessionHub.php old lines 186-193 (8 deletions, one file, zero additions). Verified exactly one docblock now describes list(). Docs-only; no new pin needed (existing SessionListBodySearchTest/SessionHubTest already cover the method).
Gates at d89efd151 (plain pipe, env -u LINES -u COLUMNS):
- --filter 'SessionMethods|SessionHub' alone: 8T/47A, 0F (brief's narrow shape).
- --filter 'SessionMethods|SessionHub|ServerContext|ProtocolSchemaDrift|ServerProtocolDocumentationDrift' (review-1 shape): 21T/384A, 1F = ONLY testTheSchemaFileAndTheServerDocTablesAreCurrent staleness arm, diff = exactly the three declared additions (spendUnpriced ×2 + unpricedModel) — pre-regen condition by design, welder cures by regen.
- Five-guard: 133T/15348A, 2F = ReadmeSuiteFigureDrift staleness pair only (pinned 25,537 vs live 25,658 — closeout re-pin duty).
Author %ae/%ce detain 1/1 (separate greps), zero Co-Authored-By, no caliber (no-hook), tree clean, never pushed.

