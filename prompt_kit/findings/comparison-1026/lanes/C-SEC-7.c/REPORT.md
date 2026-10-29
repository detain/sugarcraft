# C-SEC-7.c — plan-mode roster drop — LANDED

Branch `cc/C-SEC-7.c` @ /home/sites/cc-cmp-C-SEC-7.c
- tip (cat-file): `873354b785efc6648aeff8d8255e4892cd0c24e8` — "C-SEC-7.c: plan mode drops gate-refused declarations from the advertised roster; Write/Bash/PlanExit kept" (6 files, +521/−15)
- skeleton #1: `2bc6bb703a6b04ac3be5b4b3a389669f89c35d1a` (stub replaced in #2; markTestIncomplete gone)
- authors %ae+%ce both detain@interserver.net, zero Co-Authored-By (verified separately).

## What shipped
`EngineBackend::turnTools()` end-block (immediately before `return $tools;`, hunk-disjoint
from the C-MCP-3.d arming and channel-door lines — read current shape first, co-tenant
law honored): while `permissionGate->mode() === Plan`, the advertised roster keeps a tool
iff its name is in `PLAN_MODE_ADVERTISED_NAMES = ['Write','Bash','PlanExit']` (private
const, docblocked) or `!$gate->refuses(new ToolDeclaration($tool->name()))` — the SAME
shared decision path dispatch walks. Dropped: Edit, Task, ApplyPatch, every unhinted
mcp__*. Kept naturally: read-only + TodoWrite/WebFetch (Plan allows). No-gate and every
other mode: byte-identical old behavior.

## Pins (all mutation-discriminating, cp+md5 restores clean)
- NEW `tests/Backend/PlanModeToolRosterTest.php` 11T (DUR row owed): plan roster table,
  name-for-name roster⇔refuses() coherence law, all-other-modes full list, no-gate,
  keep-list beats Deny, Deny hides its tool in plan, Allow restores Edit, Deny-under-
  Default does NOT hide (mode-scoping), fabricated Edit → "Tool not found: Edit" + never
  ran, kept Write on non-plan target → refusal still names mode 'plan', kept Write on
  `.candy-code/plans/plan.md` → actually runs (keep promise is real).
- `PromptPrefixByteStabilityTest::testAModeSwitchIsTheOnePrefixBreakTheRosterDropCosts`
  (modified file): within-plan turns byte-stable incl. tool block; switch adds Edit back,
  drops the `# Plan mode` slot; all rows after message 0 ride unchanged.
- M1 filter neuter → 5F; M2 keep-list drop → 6F; M3 mode-guard drop → 2F (exactly the
  mode-scoping legs).

## Guards moved in-step (§1.8)
- `EngineBackendPermissionGateTest::testADenyingGateReportsTheRefusalToTheModel` flipped
  to the new shape ("Tool not found: Edit"), with the mode-wording pin relocated to the
  kept-Write leg in the new file. **Deviation**: file not in the brief's declared list —
  forced by the drop; reviewer re-gate please confirm honest.
- Docs: `PERMISSIONS.md` :487-488 sentence flipped (deny-hides + cache-break + keep trio,
  all rows inside the one paragraph); `PROMPT_ENGINEERING.md` item 12 rewritten with the
  one-break-per-switch carve-out beside the cache law.

## Gate lines (cwd=candy-code -c phpunit.xml, plain pipe)
- `--filter 'PlanMode|Permission|EngineBackend|PromptPrefixByteStability|PermissionsIntro|ForeignAgentPresetWiring'`: **2624T / 2F** — both reds reproduced at base `4dd8c20ce` (detached re-run: same 2F): PermissionsIntroDriftTest::testThePatternToolRosterIsTheWholeCatalog (SEC-6.b-era 'Schedule' row missing from intro roster) + ForeignAgentPresetWiringTest:472 (reader census roster grew with welded foreign files). Foreign-inherited, not this lane.
- guard families `GlobDialect|DuplicatedTestHelperDrift|DocFigure|SymbolCitation|DuplicatedDocBlockLine|ChildWallClock|SwallowingCatch`: **145T / 0F**.
- ReadmeSuiteFigureDrift staleness pair: expected (live 28,288 vs pinned 25,537 — closeout re-pin duty, +12T from this lane among accumulated welded deltas).
- php -l clean ×4 touched PHP files. E646: no timers added. GlobDialect: no glob-shaped literals added (guard green).

## DUR carry list (gate adds; lane forbidden from touching)
- `candy-code/tests/Backend/PlanModeToolRosterTest.php` — NEW phpunit file, needs durations.tsv row (or runs zero shards).
- modified existing files need no rows.

## SEAMS for the lead (NOT touched — out of declared files)
1. **Stale prompt claim**: `src/Context/Sections/PlanModeSection.php` BODY says "The tool
   list is the same in every mode, so a tool being offered does not mean it may run." —
   now false-by-construction under plan. Micro-flip + needle move in
   `tests/Context/Sections/PlanModeSectionTest.php:55` (the real pin; the brief's
   "PermissionsIntroDriftTest" attribution was wrong — intro ends at the first `---`,
   line 17). Suggest C-SEC-7.d micro.
2. `docs/PERMISSIONS.md` intro roster missing 'Schedule' (PermissionsIntroDriftTest red at
   base) — pre-existing, belongs to SEC-6.b closer's re-pin or gate.
3. Observation: `McpToolWiringTest` plan legs drive via `Bootstrap::tools` directly (not
   turnTools) — unaffected today; their docblocks describe the old world if someone later
   routes the advertisement through the same door.
4. turnTools actually at :3401 (brief cited :3151); refuses() at :679 (brief cited the
   :564 docblock). Task drops in plan — deliberate consequence (bound delegation engine
   carries the same gate and enforces anyway).
