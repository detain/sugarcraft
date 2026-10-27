# C-MCP-4.a — context.sources reports user-tier MCP rows and liveness

- Commit: `ab5d60359bc7beb96116bab9c22d32878240cbe9` on `cc/C-MCP-4.a` (base master `33d015d4a`), author+committer `detain@interserver.net` (separate %ae/%ce greps), zero Co-Authored trailers, tree clean, NOT pushed.
- Files: `candy-code/src/Host/ContextSources.php`, `candy-code/src/Protocol/Schema/Definitions.php`, `candy-code/tests/Protocol/ContextSourcesTest.php`, NEW `candy-code/tests/Protocol/ContextSourcesMcpUserTierTest.php`, `candy-code-web/src-web/components/panels/ContextPanel.vue`, `candy-code-web/src-web/components/panels/__tests__/contextPanel.spec.ts`. 6 files, +374/−30.

## What shipped
- mcp census now nine-key: rows gained `tier` ("project"|"user") and nullable `liveness` `{up, tools}`; mcp object gained `userStatus`/`userError`/`userSkipped`. User rows append after project rows; shadowed names never appear (mirrors McpDecision MCP-3 precedence — rows come from the real `McpDecision::mcpServerInventory`).
- `ContextSources::answer()` takes injectable `?array $mcpLiveness`; default reads `Bootstrap::mcpLivenessSnapshot($root)` — memo-only, launches nothing; null (no client held) answers null liveness on every row.
- Definitions.php mcp schema updated in-place (required key lists widened); web `ContextPanel.vue` MCP section lists user rows under a `User scope` heading with a liveness dot (up/down/unknown/cold) — one section, minimal markup for P-CTX-BREAKDOWN.

## Regen chain (§1.8 — trio + generated.ts RESTORED before commit)
1. Modified Definitions.php → drift RED pre-regen: `--filter 'ProtocolSchemaDrift|ServerProtocolDocumentationDrift'` → `Tests: 12, Assertions: 331, Failures: 1` (ProtocolSchemaDriftTest:39 quoting missing userStatus/userError/userSkipped/tier/liveness).
2. `php scripts/gen-protocol-schema.php --write` → same filter GREEN: `Tests: 12, Assertions: 332` (2 deprecations pre-existing process-level).
3. `node scripts/gen-protocol.mjs` → regenerated `generated.ts` (ContextSources.mcp block carries new keys; types fed ContextPanel.vue).
4. `git checkout -- candy-code/docs/protocol/candy-code.v1.schema.json candy-code-web/src-web/protocol/generated.ts` — welder regenerates and commits trio (+dist) at weld. SERVER.md was not touched by the generator this time.

## Gates (targeted only; plain pipe, cwd worktree candy-code, env -u LINES -u COLUMNS)
- `--filter 'ContextSources|ProtocolSchemaDrift|ServerProtocolDocumentationDrift'` at commit state WITH regenerated types present: `Tests: 26, Assertions: 459, OK (+2 pre-existing deprecations)`.
- New file alone: `Tests: 4, Assertions: 12` green (4 behaviours: user tier rows+order, shadowing, userError on undecodable user file, liveness cold-null/warm-filled incl. declared-but-unstarted row staying null).
- web vitest serial: `npx vitest run --no-file-parallelism contextPanel.spec.ts generated.spec.ts` → `Test Files 2 passed (2) / Tests 21 passed (21)`.
- `npx vue-tsc --build` → exactly the 6 foreign TS2554s (statusBar.spec.ts x2, reducer.spec.ts x4), zero new.
- five-guard filter: `Tests: 133, Assertions: 15788, Failures: 2` — the staleness pair only (README/artifact 25537 vs live 26236; base master itself pinned-stale, +4 are this lane's file — closeout re-pin owns it).
- `php -l` clean on all modified PHP files.

## DUR row for the new test file (welder/gate adds to scripts/parallel-tests-durations.tsv — lane never touched the TSV)
```
candy-code/tests/Protocol/ContextSourcesMcpUserTierTest.php	4.416	12
```

## Notes / not done
- Nothing outside declared files; suite-figure.json, durations.tsv, dist/, COMPARISON.md, plan_comparison.md untouched. Bootstrap/McpDecision read-only (mcpLivenessSnapshot already existed at Bootstrap.php:8202 — no fallback implementation needed).
- Post-restore, the drift arm is RED in the committed tree BY DESIGN (§1.8 regen-verify-restore; welder flips it green with the trio regeneration).
