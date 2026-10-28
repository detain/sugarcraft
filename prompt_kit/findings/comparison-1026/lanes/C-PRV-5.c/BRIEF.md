# BRIEF — C-PRV-5.c: Non-blocking model-catalog prefetch

- area: prv-ctx
- src: design-prv-ctx.md
- item: C-PRV-5 (sources: PRV-1-P1)
- kind: fix · MINOR · effort M
- phase: normal
- ruling: none
- wave: None | late: False
- deps: none
- serialized (claims): [chat:pickPaletteProvider, update dispatch arm (ModelCatalogReadyMsg)] [hot:candy-code/src/Providers/OpenAIProvider.php]

## Files to create
`candy-code/src/App/ModelCatalogReadyMsg.php`, `candy-code/tests/Chat/PaletteCatalogAsyncTest.php`

## Files to modify
`candy-code/src/Providers/OpenAiModelsEndpoint.php`, `candy-code/src/Providers/HasModelCatalog.php`, `candy-code/src/Providers/ModelCatalog.php`, `candy-code/src/Providers/OpenAIProvider.php` (`listModelsAsync()`), `candy-code/src/Providers/SglangProvider.php` (`listModelsAsync()`), `candy-code/src/Chat.php` (`pickPaletteProvider()`, update dispatch arm for the new Msg)

## Declared regions
{"candy-code/src/Providers/OpenAiModelsEndpoint.php": ["?"], "candy-code/src/Providers/HasModelCatalog.php": ["?"], "candy-code/src/Providers/ModelCatalog.php": ["?"], "candy-code/src/Providers/OpenAIProvider.php": ["listModelsAsync()"], "candy-code/src/Providers/SglangProvider.php": ["listModelsAsync()"], "candy-code/src/Chat.php": ["pickPaletteProvider()"]}

## Tests
create PaletteCatalogAsyncTest (the palette returns at once with the base tiers; the Msg merges the live ids; a failed fetch changes nothing); modify `candy-code/tests/Providers/OpenAiModelsEndpointTest.php` (async path)

## Docs/guards
none

## Approach (verbatim)

- Root cause: `Chat::pickPaletteProvider()` (`Chat.php:17060`, call at :17129) calls `ModelCatalog::prefetch()` → `OpenAiModelsEndpoint::fetch()`, a blocking Guzzle GET with a 3 s budget (`OpenAiModelsEndpoint.php:63-89`). On a slow base this freezes keys and repaint once per process.
- Fix: add `OpenAiModelsEndpoint::fetchAsync()` over `React\Http\Browser` (already required, `react/http ^1.11`), keeping the 3 s metadata budget. This is not an LLM call, so E646 does not apply.
- Add `HasModelCatalog::listModelsAsync()` on OpenAI and SGLang, and `ModelCatalog::prefetchAsync()` returning a promise.
- The palette returns immediately with the base tiers plus a `Cmd` that resolves to a new `App\ModelCatalogReadyMsg`. Chat's update arm then merges the live ids into the open picker.

## Risks
the CLI `models` listing keeps the synchronous `fetch()`. Only the TUI path goes async.
