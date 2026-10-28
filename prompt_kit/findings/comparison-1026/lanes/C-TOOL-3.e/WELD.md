# C-TOOL-3.e — WELD (2026-10-29, welder: cc-cmp subagent)

Base at weld: master eadec1a44 (weld: C-TOOL-2.d). Lane cc/C-TOOL-3.e (base cf0cfd0be), 2 commits. PROTOCOL LANE: generated trio + dist regen folded into this weld (single commit per lead's 2026-10-29 instruction).

## Pick map
- eef374f61 → a1a997b4f — shells.* protocol methods + web Processes section (src/Protocol/Methods/ShellsMethods.php NEW, tests/Protocol/ShellsMethodsTest.php NEW, Dispatcher.php, MethodSchemas/Definitions, web shells.ts store + ProcessesSection.vue + processesSection.spec.ts + gen-protocol.mjs + BackgroundPanel.vue; generated trio left uncommitted per regen law — proven absent from both commits by r2).
- 264ffa6d8 → eed6630d3 — review fixes round 1 (owner-filter arms, scope roster, cursor/refusal pins; zero PHP/schema files touched). r1 APPROVE 1 minor → fix → r2 CLEAN 0C/0M/0m.
- ✅ mark hash = final pick eed6630d3.

## Absorption proof
- `git cherry master cc/C-TOOL-3.e` → `- eef374f61…`, `- 264ffa6d8…` (both absorbed).
- patch-id --stable SAME per pair: f87faeb9… = f87faeb9… (eef374f61/a1a997b4f), ed1ead7b… = ed1ead7b… (264ffa6d8/eed6630d3).
- %ae=%ce=detain@interserver.net both picks, zero Co-Authored-By.

## Regen (merged tree, post-pick — trio written ONCE)
- `php scripts/gen-protocol-schema.php --write` (cwd candy-code) → rewrote docs/protocol/candy-code.v1.schema.json (+232) + docs/SERVER.md (+3: shells.list read / shells.output read / shells.stop write+confirm, scopes match ShellsMethods.php roster). No separate gen-server-docs exists — same script, per C-CTX-2.d precedent (lanes/W15/WELD.md).
- `npm run gen:protocol` (candy-code-web; node_modules present → npm ci skipped per lead) → src-web/protocol/generated.ts (+66).
- `npm run build` → dist TRACKED: assets index-BN34gdZx.js/BmMQOzik.css deleted, index-BuXqQAyT.js/gEeU5Skx.css added, index.html + manifest.json updated; build-info.json unchanged (deterministic).

## Merged-tree gates (cwd candy-code, env -u LINES -u COLUMNS, plain pipe, -c phpunit.xml)
- `--filter ShellsMethodsTest`: **7T/58A** OK (2 deprecations standing shape) — EXACT vs REVIEW-1/2.
- `tests/Protocol` dir: **120T/1163A** OK — EXACT.
- `--filter 'ProtocolSchemaDrift|ServerProtocolDocumentationDrift'`: **12T/341A** OK — EXACT (post-regen).
- vitest `--no-file-parallelism` processesSection.spec.ts + protocol/__tests__/generated.spec.ts + __tests__/dormantRoster.spec.ts: **3 files, 14T passed** (7+4+3). First dormantRoster run FALSE-RED (shells.* derived-dormant) — root-caused as STALE PERSISTENT TRANSFORM CACHE `node_modules/.vitest-cache` holding a pre-pick import.meta.glob snapshot (instrumented run passed; debug spec with identical logic passed; clearing .vite alone insufficient). After `rm -rf node_modules/.vitest-cache` the UNMODIFIED spec passes. NOT a tree defect; harness lesson recorded.
- Wider web battery `panels/__tests__` dir: **11 files, 96T/96A passed** (superset of builder's processesSection+panels+backgroundSchedules 24T).
- `npx vue-tsc --noEmit` rc=0 (builder precedent).

## Bookkeeping
- durations.tsv row ADDED (welder-only): `candy-code/tests/Protocol/ShellsMethodsTest.php	1.543	7` — junit-measured 1.543104 in a dedicated per-file run; tsv 1544→1545, asc-violations 4==4, set-identity +1, tie-adjacent to existing 1.543 row (landed at tsv line 200).
- suite-figure re-pin carry to closeout: +7T (ShellsMethodsTest) + generated-surface arms.

## Carries to closeout (§7)
- NIT (r1, untouched by fix, r2-confirmed closeout-only): cursor-before-lookup ordering in ShellsMethods output leg.
- NIT README wording carry (r1).
- Harness lesson: after cherry-picks that ADD files under src-web, vitest MUST be run with a cleared `node_modules/.vitest-cache` (or fresh checkout) or import.meta.glob roster specs false-red; WELD battery steps should include the cache clear.
