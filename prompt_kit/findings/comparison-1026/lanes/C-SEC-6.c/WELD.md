# C-SEC-6.c — WELD (batch-13, 2026-10-28)
Picks: 737d5dba5 -> e9838c105 (build), 4a99481f4 -> 58e4899f1 (fix). SessionView.vue hot region: NO conflict arose
(C-MCP-2.c's W23 SessionView hunk and this lane's hunks merged clean; foreign lines verified intact in pick — keep-both law held).
Gates (merged tree): --filter 'PermissionSavedRulesMethods|PermissionFirstWins' 24T/14T-file OK exact (24T/101A);
file alone 14T/50A; vitest serial --no-file-parallelism 10 files 69T/69P byte-exact (rulesPanelSaved, rulesCatalog,
permissionCardExtras, permissionCardDiff, component+stores approvals, grid, stores/session, generated, dormantRoster);
ProtocolSchemaDrift|ServerProtocolDocumentationDrift 13T/362A OK on regenerated tree.
Regen trio on merged tree: gen-protocol-schema --write rewrote docs/protocol/candy-code.v1.schema.json (+81) +
docs/SERVER.md (permission.forget rows present — grep -c == 2); web npm run gen:protocol rewrote
src-web/protocol/generated.ts; dist rebuilt (index-DqOLwGWV.js 496.20kB) — dist+generated.ts ride the weld commit.
DUR minted: PermissionSavedRulesMethodsTest 3.40 14 (brief's ~7s estimate superseded by measured).
Standing pair unchanged: PermissionsIntroDrift + ForeignAgentPresetWiring (disclosed in lane REPORT as base-owned).
§4 cite: 4a99481f4. BRIEF.md staged untracked. Branch REPORT (round-1 re-derivation) kept over stashed round-0 mtree copy.
