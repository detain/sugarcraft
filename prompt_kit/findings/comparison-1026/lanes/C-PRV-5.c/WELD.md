# C-PRV-5.c — WELD (2026-10-28)

Pick: e142cf955 → master 27757dd94. Zero conflicts; stable patch-id 4368ae9a identical both sides; `git cherry master cc/C-PRV-5.c` = `-` (single commit, absorbed). Worktree + branch pruned after this weld commit.

## What landed

Non-blocking model-catalog prefetch: `OpenAiModelsEndpoint::fetchAsync` (Guzzle async, 3.0 s metadata budget preserved via DISCOVERY_TIMEOUT_SECONDS, connect-only bound — E646 clean), `listModelsAsync` on OpenAI + Sglang providers (NOT on `HasModelCatalog` — deviation 1 adjudicated REVIEW-1 §2: CustomProvider implements the interface and is outside the step's file list; interface carries the truth docblock, typed dispatch in `ModelCatalog::prefetchAsync` mirrors it), `ModelCatalog::prefetchAsync`, new `App/ModelCatalogReadyMsg.php`, `Chat::paletteCatalogWake` + `pickPaletteProvider` batch arms.

## §4-mark mapping note (weld duty b)

The plan's serialized claim read `[chat:update dispatch arm (ModelCatalogReadyMsg)]`; the arm physically lives in `src/Chat/Handlers/HandlesSessionSurface.php` (deviation 2, REVIEW-1 §3): `Chat::route()` is structurally frozen thin-chain/no-instanceof by `RouteTraitParityTest` (wording predates the W7 trait split; placing it in Chat.php would redden the guard). Precedent family: ContextMeasuredMsg/SessionPickerSearchMsg arms in the same handler.

## Design consequence (weld duty c)

Async first-open counts BASE rows for the palette reopen-gate: the palette returns at once with base tiers, and the memo serves every later open with the fetched tiers. Disclosed, accepted (REVIEW-1 §8c).

## Durations (weld duty a)

`tests/Chat/PaletteCatalogAsyncTest.php` row 0.239/4T landed in preceding scripts commit d86efaa29 (junit per-testcase sum at merged tip; tie group 0.239 path-asc → first of the three, ahead of Renderer/StatusBar row; body-desc + set-equality 1470+1=1471 held).

## Gates on merged tree (cwd candy-code, plain pipe, env -u LINES -u COLUMNS)

- `--filter 'OpenAiModelsEndpoint|ModelCatalog|PaletteCatalogAsync|ProviderConnectTimeout|RouteTraitParityTest'` → OK 199T/649A, 2 foreign PHPUnit deprecations (reviewer-exact).
- `--filter 'Palette'` → OK 209T/3227A (reviewer-exact).
- `--filter 'RouteTraitParityTest'` alone → OK 4T/44A (in-step guard roster rows both live).

## Regeneration

None required (no `src/Protocol/`, no schema/dist touch). Verified anyway: gen-command-docs/gen-tool-docs/gen-settings-doc `--write` wrote zero diffs; gen-protocol-schema `--check` up to date.

## Review record

REVIEW-1.md CLEAN (0C/0M, NITs → weld-cures, all three duties above discharged). suite-figure.json untouched; standing base-inherited reds (StderrEmitterCensus, TreeWideGuardRoster, staleness pair, ChatHostLangCatalogue) not in scope.
