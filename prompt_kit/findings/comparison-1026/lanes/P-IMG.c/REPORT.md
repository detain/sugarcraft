# P-IMG.c — Web: Generate entry in the top bar

DONE. Commit `26760f05b` on `cc/P-IMG.c` (base `da772ff56`), worktree `/home/sites/cc-cmp-P-IMG.c`. Author+committer detain@interserver.net (separate greps), zero Co-Authored trailers, never pushed/pulled.

## Changes
- `candy-code-web/src-web/App.vue` (+3): `usePanelsStore` import, `panels` const, topbar button `data-testid="generate-button"` immediately after the about button, `v-if="connection.canGenerateImage"`, click → `panels.show('generate')`. Panel store import precedes the connection import to keep the stores group sorted.
- NEW spec: `candy-code-web/src-web/components/__tests__/topbarGenerate.spec.ts` (4T) — GATE DUTY: needs a `scripts/parallel-tests-durations.tsv` / vitest roster row if the gate maintains one.

## Tests (all `npx vitest run --no-file-parallelism`, cwd candy-code-web)
- `topbarGenerate.spec.ts`: 4 passed (hidden on absent media; hidden on image:false; present beside about + adjacency order pin; click sets panels.active='generate', open=true).
- Mutation proof: capability `v-if` stripped → exactly the 2 hidden tests fail; file restored md5-verified.
- Regression: `statusBar.spec.ts` paired run — 2 files, 11 tests passed.
- `vue-tsc --noEmit` clean; `eslint` rc0 on both touched files.

## Constraints honored
- Only the design block's files touched; `git status` porcelain at commit = the two paths. No `dist/` commit (welder rebuilds), no suite-figure.json, no durations.tsv edit. Holds serialized hot file App.vue this step.

## Notes for orchestrator
- Gate uses `helloResult({ features: { ..., media: { image, video } } })` from `src-web/__tests__/fakes.ts` — the same shape `generatePanel.spec.ts` pins; C-WEB-3's video tab rides the same hello (canGenerateVideo already exported).
- Button deliberately gated ONLY on `connection.canGenerateImage` (brief's exact condition); hello is null on the login screen so it self-hides there — no `!onLogin` term added.
