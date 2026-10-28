# C-PRV-5.c — Non-blocking model-catalog prefetch — BUILD

Branch `cc/C-PRV-5.c` @ /home/sites/cc-cmp-C-PRV-5.c (base master b985fbe51). Commit: **e142cf955** "C-PRV-5.c: Non-blocking model-catalog prefetch" (author+committer detain@interserver.net verified by separate greps, zero trailers, tree clean, not pushed).

## What was implemented (per the design block)

- **src/App/ModelCatalogReadyMsg.php** (new): App\Msg carrying `provider` + `ids` (null = answered nothing); docblock explains merge-by-derivation and why the payload rides although the arm is inert.
- **src/Providers/OpenAiModelsEndpoint.php**: `fetchAsync(string $baseUrl, ?string $apiKey=null, ?callable $sender=null): PromiseInterface` — same route (`{base}/models`), same Bearer/Accept header set, same `decode()` containment, same never-throws contract (promise NEVER rejects: status refusal re-applied in-callback, transport/junk/throwing-seam all resolve null). Both bounds name `DISCOVERY_TIMEOUT_SECONDS` (connector connect + `Browser::withTimeout` total) — metadata read keeps its total bound; E646 untouched (no LLM call; the exemption is the file's existing named one). `$sender` is a transport-replacement seam (react/http v1.11's Browser takes no callable sender, so the seam takes the composed `($url, $headers)` exactly as the real Browser would).
- **src/Providers/OpenAIProvider.php**: `listModelsAsync(?callable $sender=null)` — askable door kept (keyless-public-root answers `resolve(null)` without constructing a read). **src/Providers/SglangProvider.php**: sibling, threads baseUrl/apiKey.
- **src/Providers/ModelCatalog.php**: `prefetchAsync(?HasModelCatalog $provider, ?callable $sender=null): ?PromiseInterface` — same three refusals as `prefetch()`, typed dispatch to the two async transports (custom + anon fakes answer null = stay sync), memo placeholder written BEFORE the dial so once-per-process holds across the async window (in-flight key = settled key; no parallel second dial), `then` performs the memo write before the value continues.
- **src/Providers/HasModelCatalog.php**: docblock-only (design region "?"): why the async sibling is NOT on the interface (widening would fatal CustomProvider + the suite's anon fakes) and that `prefetchAsync` is the sanctioned dispatcher answering null for the sync-only implementers. **No interface signature changed** — the design's letter says add it there, its risks-paragraph intent (don't break implementers) governs; this is the §6-honest reading (see Deviations).
- **src/Chat.php**: `paletteCatalogWake(Chat $next, string $name): ?\Closure` (region pickPaletteProvider) — engine-backend → HasModelCatalog → name match → `ModelCatalog::prefetchAsync`; returns `Cmd::promise(fn() => $promise->then(fn(?array $ids) => new ModelCatalogReadyMsg($name, $ids)))` or null. `pickPaletteProvider` (claimed region): wake first, sync `primeModelCatalog` byte-identical fallback when nothing async started; wake batches into the return (`Cmd::batch($cmd, $warm)`) on BOTH the no-reopen and reopen arms so the memo serves later opens; reopen gate unchanged (counts the derived base tier, exactly the cold-cache shape).
- **Dispatch arm**: `ModelCatalogReadyMsg instanceof` → `return [$this, null]` in **src/Chat/Handlers/HandlesSessionSurface.php** (after the C-SES-2 SessionPickerSearchMsg arm) with the ContextMeasuredMsg-style docblock (repaint comes from Program re-rendering after the update; rows re-derive from the memo).

## Guards moved (in-step, same commit)

- `tests/Chat/RouteTraitParityTest.php` FROZEN_ARM_ROSTER: `+ 'ModelCatalogReadyMsg'` (my arm).
- `+ 'TelemetryRetryTickMsg'` — **foreign base-red cured in-step** (C-SES-4.b weld a3bf74a61 landed that arm in the same handler file without its roster row; red at base b985fbe51, reproduced pristine via stash; cured here per the file's own SessionPickerSearchMsg precedent, disclosed for the welder).
- `ProviderConnectTimeoutTest` verified unchanged-green: the new async total bound names the existing `DISCOVERY_TIMEOUT_SECONDS` and the Browser construction cannot read as `new Client(` — no roster move needed.
- `tools/check-one-type-per-file.php`: 62 libs, 3194 files, 0 problems. `tools/check-child-lifetimes.php`: 0 problems (no new proc_open).

## Tests

- `tests/Providers/OpenAiModelsEndpointTest.php` (+6): fetchAsync route/header/credential pins through the seam; never-rejects five (http-500, transport reject, junk, empty rows, throwing seam); answer-on-settle-not-on-open (Deferred, incl. late failure = null); shared decode containment (escape sweep rides async); provider siblings keep the door (`$doorReached` false pins no-bytes-spent); `prefetchAsync` dispatch/in-flight-placeholder/failed-memoise/opt-out stand-down.
- `tests/Chat/PaletteCatalogAsyncTest.php` (+4, **NEW FILE — durations row owed by the gate**): first open answers at once on the base tier, wake batched (member-count pin vs the plain select), placeholder pins the in-flight window; ready-msg repaint derives the merged picker (mode/provider kept, cmd null, inert null-arrival and foreign-provider arrival, next open served from memo); failed answer changes nothing; sync polarities byte-identical (anon HasModelCatalog + echo backend carry no wake; refused switch unchanged). All network-free: loopback bases on random ports, loop never run.

## Gates (all cwd /home/sites/cc-cmp-C-PRV-5.c/candy-code, plain pipe, env -u LINES -u COLUMNS)

- `vendor/bin/phpunit -c phpunit.xml --filter 'OpenAiModelsEndpoint|ModelCatalog|PaletteCatalogAsync|ProviderConnectTimeout|RouteTraitParityTest'` → **199T/649A OK**
- `--filter 'Palette'` → 209T/3227A OK; `--filter 'ThemePicker|FullQuery|NewSessionCommand|Superset'` → 28T/464A OK
- `--filter 'Sglang|OpenAI|OpenAi|ModelMetadata|AccessorDeprecation'` → 807T OK/1S; `--filter 'GlobDialectDifferential|SlashDispatch'` → 34T OK
- `--filter 'SymbolCitationDrift|DuplicatedTestHelperDrift|DuplicatedDocBlockLine|SwallowingCatchCensus'` → 29T/16846A OK
- `--filter 'DocFigureProseDrift|EnvRosterDrift|ReadmeRosterDrift|TrustKeyDocumentationDrift|ConfigWriteProducerDrift|KeyBindingDrift|ChildWallClockBudget|OneSidedHomeSandbox'` → 388T/55415A OK
- `php -l` clean on all 11 touched files. The 2 PHPUnit deprecations every window shows are foreign (`tests/Config/AccessorDeprecationTest` private data providers), present at base.

## Deviations (2, both adjudicated §6-style, disclosed for review)

1. `listModelsAsync` is not added to the `HasModelCatalog` interface. 3 src implementers (CustomProvider — NOT in the step's file list) + 2 anon test doubles would fatal on an abstract member; the design's own region label for the interface was "?" and the plan's risk paragraph forbids breaking the sync-only implementers. The interface gains the docblock truth; `ModelCatalog::prefetchAsync` is the sanctioned typed dispatcher.
2. The "Chat.php update dispatch arm" lands in `HandlesSessionSurface.php` (the W7-split physical home of this family's arms) + its roster guard, not in `route()` itself — route() is pinned thin-chain-no-instanceof by RouteTraitParityTest, and `HandlesRunControl.php` is claimed by C-CTX-5.c.

## Claims honored

[chat:pickPaletteProvider(+wake), update dispatch arm] [hot:OpenAIProvider.php — additive method inside the listModels region]. No other lane's file touched; nothing staged outside the explicit list; suite-figure.json / durations.tsv / dist / plan files untouched; never pushed.
