# C-SES-8.a — WELD-REPORT (live journal; welder on master, sole writer)

## Preflight (2026-10-30)
- master tip d79502693 (== prompt). Branch cc/C-SES-8.a tip 2d1463e80 (cat-file: commit), 4 picks off merge-base 574e69f1d — matches prompt exactly.
- REVIEW-3.md CLEAN 0C/0M/0m. Lane carries: trio regen + dist rebuild + generated.spec.ts scope pin (MINOR-2 weld-deferred by design); DUR row + suite-figure +9T = §7 carry (recorded in run-state weld_notes, files untouched per WEB-5.a/SES-7.b precedent).
- master..lane surface: Protocol Dispatcher/MethodSchemas + web SidePanels/panels.ts have ZERO master drift since lane cut (git log probe above) → clean picks expected.
- No generated file committed in lane diff (forbidden-set grep per REVIEW-3 = 0). Node v24.21.0 + npm 12.0.2 present; candy-code-web/node_modules present in main tree.
- run-state anomaly noted at fold time: lead's live ledger records a PRIOR phantom welder (fabricated 75277a61a/47e7b2a4d) but directs "adopt carries in a weld commit, NOT re-pick" — and at THIS session start master ref was d79502693 with no SES-8.a commits and a clean trio, so the picks below are the first real ones (no duplicate-pick hazard; ledger entry preserved verbatim by the fold).

## Step 1 — picks: DONE, zero conflicts
- b95a0e181→e3cb19622, 06352783a→6ae5c4b4e, 4a1f4643d→5368f7e8f, 2d1463e80→94432cfd1 (all cat-filed from live cherry-pick output).

## Step 2 — trio regen + dist + scope pin: DONE
- gen-protocol-schema --write: schema.json +165, SERVER.md +1 (usage.rollup method row); --check then reports up to date. Other three generators: zero drift ("up to date").
- npm ci (fresh, registry PONG; old node_modules kept as .pre-weld-bak until commit lands) + npm run gen:protocol: generated.ts +44 incl. `"usage.rollup": "read"`.
- generated.spec.ts: added deferred scope pin METHOD_SCOPES['usage.rollup']==='read' (fixer MINOR-2 weld-carry) — the ONLY hand-edit here is the TEST, never a generated file.
- npm run build: OK (dist bundles index-AI_UWYxu.js / index-DJcRbGDm.css replace the old pair; index.html + manifest.json rewritten).

## Step 3 — merged-tree gates: DONE, green
- UsageMethods 9T/54A OK (2 baseline deprecations family).
- ProtocolSchemaDrift|ServerProtocolDocumentationDrift 13T/362A OK — pre-regen staleness pair GREEN post-regen (weld-regen law proven exactly as §1.8 plans).
- vitest --no-file-parallelism (vitest caches cleared per lane carry #4): generated + dormantRoster + usagePanel + panels = 4 files, 24/24 pass.
- vue-tsc --build: 21 errors == pristine-base set (contextPanel/statusBar/contextSources/reducer family, zero lane files); ZERO UsagePanel lines — the 38 `keyof Methods` staleness errors vanished on regen, reviewer prediction confirmed.
- GlobDialect|DuplicatedTestHelperDrift 28T/568A OK.
- ReadmeSuiteFigureDrift 4T/2F = standing staleness pair ONLY (pinned 25,537 vs live 28,499; live already carries this lane's +9T). NOT re-pinned per standing rule; §7 carry recorded.

## Step 4 — run-state fold: DONE
- Re-read freshest copy immediately pre-write; moved C-SES-8.a weld_pending→welded (weld_pending now [C-SES-8.d]); welded_count 242→243 == len(welded) asserted; master_tip 94432cfd1 (final stamp re-syncs per self-hash law); weld_notes['C-SES-8.a'] full carry note (DUR row UsageMethodsTest 9T/~11.5s + suite-figure/README +9T = §7 regen).
