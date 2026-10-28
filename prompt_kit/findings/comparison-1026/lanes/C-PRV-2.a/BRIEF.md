# C-PRV-2.a: OpenAI Responses wire (core)

- wave ? · area prv-ctx · kind gap · effort M · effort ? · phase normal
- item: C-PRV-2 (sources: GAP-MOD-02, p1-miss: OpenAI Responses API)
- deps: C-PRV-1.a
- serialized: [docs:SETTINGS.md] [docs:ADDING_A_PROVIDER.md] [hot:candy-code/src/Providers/OpenAIProvider.php] [hot:candy-code/src/Providers/ProviderFactory.php]
- ruling: none
- risks: with no live key, the SSE event shapes are fixture-pinned. The trait docblock records what was measured and what was not.

## Files
- create: `candy-code/src/Providers/Concerns/OpenAiResponsesWire.php`, `candy-code/tests/Providers/OpenAiResponsesWireTest.php`
- modify: `candy-code/src/Providers/OpenAIProvider.php` (constructor `api`, `complete()`, `completeStream()`), `candy-code/src/Providers/ProviderFactory.php` (`TYPE_SCHEMAS['openai']` optional `api`, `createOpenAI()`)
- tests: 
- create OpenAiResponsesWireTest:
- body pins;
- an SSE fixture parses to text plus tool calls;
- the usage/cached-token fold;
- a Guzzle options pin: `connect_timeout` set, no `timeout`.
- modify `candy-code/tests/Providers/ProviderFactoryTest.php`: `api` accepts `chat|responses` and refuses anything else.
- docs/guards: `candy-code/docs/SETTINGS.md` (provider block `api` prose), `candy-code/docs/ADDING_A_PROVIDER.md`

## Approach

- Today there is Chat Completions only. The vendored `openai-php/client` ^0.10 has no Responses resource.
- Add an opt-in `api: "responses"` key on `openai` blocks. A new trait, `Concerns\OpenAiResponsesWire`, speaks `POST /v1/responses` over Guzzle through `HttpClientDefaults`. E646: connect timeout only, no total timeout.
- Request: `instructions`; `input` items (message, `function_call`, `function_call_output`); tools as `{type:function}`; `store:false`; `max_output_tokens`; `reasoning.effort` via C-PRV-1.a's predicate.
- SSE parse: `response.output_text.delta`, `response.function_call_arguments.delta`/`.done`, `response.completed`. Usage, including `input_tokens_details.cached_tokens`, is folded into the usage `CacheHealthWatch` reads.
- `OpenAIProvider::complete()`/`completeStream()` route to the trait when `api === responses`; `baseUrl` and `organization` are honoured.
