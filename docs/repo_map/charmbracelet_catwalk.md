# charmbracelet/catwalk

## Metadata
- URL: https://github.com/charmbracelet/catwalk
- Language: Go
- Stars: ~687 (as of mid-2026)
- License: MIT
- Description: A database of Crush-compatible AI inference providers and models. Catwalk serves as the provider/model registry for the [Crush](https://github.com/charmbracelet/crush) shell — a community-maintained, typed Go library that wraps LLM APIs with a consistent interface.

## Feature List
- **Provider Registry**: Centralized database of 35+ LLM providers (OpenAI, Anthropic, Google Gemini, Azure, Bedrock, VertexAI, xAI, DeepSeek, Groq, Cerebras, OpenRouter, HuggingFace, Vercel, and many more)
- **Model Metadata**: Rich per-model data including context windows, pricing per 1M tokens (input/output/cached), reasoning capability flags, supported modalities (text/image), and default max tokens
- **HTTP API Server**: Lightweight Go HTTP server (`:8080`) with endpoints: `GET /v2/providers` (JSON list of all providers), `GET /providers` (deprecated), `GET /healthz`, `GET /metrics` (Prometheus)
- **ETag Caching**: Smart HTTP ETag support so clients avoid re-downloading unchanged provider data
- **Provider Generator CLIs**: One `cmd/<provider>/main.go` per provider that fetches live model lists from that provider's API and generates the corresponding `internal/providers/configs/<provider>.json`
- **Offline/Embedded Access**: `pkg/embedded` package provides `GetAll()` for offline access to all provider data (no network required)
- **Prometheus Instrumentation**: Request counter metric at `catwalk_providers_requests_total`
- **Provider Type Taxonomy**: Typed provider categories — `openai`, `openai-compat`, `openrouter`, `vercel`, `anthropic`, `google`, `azure`, `bedrock`, `google-vertex`
- **Reasoning Model Support**: First-class support for reasoning/effort models with levels (`low`, `medium`, `high`, `xhigh`) and `default_reasoning_effort`
- **Cached Token Pricing**: Model configs include both regular and cached prompt pricing (`cost_per_1m_in`, `cost_per_1m_in_cached`, etc.)
- **Configurable Default Models**: Each provider has `default_large_model_id` and `default_small_model_id` for sensible out-of-the-box defaults
- **CI-Automated Updates**: GitHub Actions workflow (`update.yml`) can regenerate all provider configs on a schedule

## Key Classes and Methods

### `pkg/catwalk/provider.go`
- `Type` (type alias `string`) — Provider type constants: `TypeOpenAI`, `TypeOpenAICompat`, `TypeOpenRouter`, `TypeVercel`, `TypeAnthropic`, `TypeGoogle`, `TypeAzure`, `TypeBedrock`, `TypeVertexAI`
- `InferenceProvider` (type alias `string`) — Provider ID constants: `InferenceProviderOpenAI`, `InferenceProviderAnthropic`, `InferenceProviderGemini`, `InferenceProviderDeepSeek`, etc. (35 total)
- `Provider` struct — Holds provider metadata: `Name`, `ID`, `APIKey`, `APIEndpoint`, `Type`, `DefaultLargeModelID`, `DefaultSmallModelID`, `Models []Model`, `DefaultHeaders`
- `ModelOptions` struct — Per-model optional params: `Temperature`, `TopP`, `TopK`, `FrequencyPenalty`, `PresencePenalty`, `ProviderOptions`
- `Model` struct — Model definition: `ID`, `Name`, `CostPer1MIn`, `CostPer1MOut`, `CostPer1MInCached`, `CostPer1MOutCached`, `ContextWindow`, `DefaultMaxTokens`, `CanReason`, `ReasoningLevels`, `DefaultReasoningEffort`, `SupportsImages`, `Options`
- `KnownProviders()` — Returns all known `InferenceProvider` values
- `KnownProviderTypes()` — Returns all known `Type` values

### `pkg/catwalk/client.go`
- `Client` struct — HTTP client for talking to a catwalk server: `baseURL`, `httpClient *http.Client`
- `New()` / `NewWithURL(url string)` — Constructor; reads `CATWALK_URL` env var or falls back to `localhost:8080`
- `GetProviders(ctx, etag string)` — Fetches all providers from the server, returns `[]Provider`, uses ETag for cache validation, returns `ErrNotModified` when unchanged

### `internal/providers/providers.go`
- `providerRegistry []ProviderFunc` — Slice of 35 provider factory functions registered at init
- `GetAll() []catwalk.Provider` — Calls every factory in the registry and returns the aggregated list
- `loadProviderFromConfig([]byte)` — Unmarshals a single embedded JSON config into a `Provider` struct
- Per-provider factory functions: `openAIProvider()`, `anthropicProvider()`, `geminiProvider()`, `deepSeekProvider()`, `openRouterProvider()`, etc.

### `pkg/embedded/embedded.go`
- `GetAll() []catwalk.Provider` — Thin wrapper around `providers.GetAll()` for offline use

### `main.go`
- `providersHandler(w, r)` — HTTP handler for `GET /v2/providers`, uses ETag, supports `HEAD`
- `providersHandlerDeprecated(w, r)` — HTTP handler for legacy `/providers` endpoint
- `main()` — Sets up `http.ServeMux` with all routes, creates `http.Server` with timeouts, listens on `:8080`

### Per-Provider Generator CLIs (`cmd/<name>/main.go`)
Each follows the same pattern: fetch models from the provider's API → transform to `catwalk.Provider` → `json.MarshalIndent` → write to `internal/providers/configs/<name>.json`.
Key patterns include:
- `selectBestEndpoint([]Endpoint)` — Chooses best endpoint by uptime (>90%), tool support, context length
- `isBetterEndpoint(candidate, current *Endpoint)` — Lexicographic comparison of tool support, context length, uptime
- `roundCost(float64)` — Rounds cost to 5 decimal places: `math.Round(v*1e5)/1e5`
- `getPricing(Model) ModelPricing` — Converts per-token pricing to per-million-tokens

## Notable Algorithms / Named Patterns
- **Endpoint Selection Algorithm** (`cmd/openrouter/main.go:selectBestEndpoint`): Picks the best model endpoint from multiple providers by filtering on status ≥ 0 and uptime ≥ 90%, then lexicographically preferring tool support → larger context window → higher uptime
- **Cost Rounding**: `math.Round(v*1e5)/1e5` — 5-decimal-place rounding to avoid floating point noise in JSON (e.g., `0.09999999999999999` becomes `0.1`)
- **ETag-based Caching**: Uses `charmbracelet/x/etag` to compute-content ETags; server sends `304 Not Modified` when client sends matching `If-None-Match`
- **Go Embed for Static Config**: Provider configs are embedded at compile time via `//go:embed configs/*.json` directives in `internal/providers/providers.go`
- **Provider Registry Pattern**: Factory function registry (`[]ProviderFunc`) that `GetAll()` iterates — allows adding new providers without modifying `GetAll()` itself
- **Per-Provider ID Prefixing**: HuggingFace models use provider-prefixed IDs like `moonshotai/Kimi-K2.5:fireworks-ai` to disambiguate which backend serves them

## Strengths
- **Comprehensive Provider Coverage**: 35+ providers with hundreds of models covering OpenAI, Anthropic, Google, Azure, open-source (Llama, DeepSeek, Qwen, etc.), and niche providers
- **Excellent Documentation**: `CRUSH.md` documents code style, adding new providers, update workflow, and manual procedures
- **Automated Model Fetching**: Each provider has a generator CLI that hits the provider's live API, so model lists stay current without manual curation
- **Type Safety**: Strong Go types for provider IDs, model metadata, pricing — reduces runtime errors
- **Thoughtful Defaults**: Every provider has `default_large_model_id` and `default_small_model_id` pre-selected
- **Reasoning Model Support**: First-class handling of reasoning/effort levels before most competitor registries had this
- **CI-Ready**: `Taskfile.yaml` with `gen:all` task, GitHub Actions workflow to regenerate all configs on schedule
- **Clean Architecture**: Separation between types (`pkg/catwalk`), registry (`internal/providers`), server (`main.go`), and generators (`cmd/*`)
- **Prometheus Metrics**: Built-in request counting for observability
- **MIT Licensed**: Permissive open source license

## Weaknesses
- **No Actual API Proxying**: Catwalk only serves metadata — it does not forward/proxy LLM inference requests. Clients must implement their own API calls using the metadata
- **Static JSON Snapshots**: Provider configs are embedded at compile time; the server must be recompiled/redeployed to pick up new models or prices
- **No Write API**: There is no endpoint to update provider configs — changes require a full `go run cmd/<provider>/main.go` + PR workflow
- **Manual ETag Invalidation**: When configs change, there's no versioning — the server just re-hashes the JSON on startup (`init()`)
- **Incomplete Error Handling**: Several provider generators silently skip models with `fmt.Printf("Warning: ...` rather than returning errors for collection
- **Model Override Hacks**: The `synthetic` provider has a large `applyModelOverrides()` switch statement to patch missing metadata — fragile long-term
- **Limited Testing**: Only one test — `TestValidDefaultModels` — validates that `default_large_model_id` and `default_small_model_id` exist in each provider's model list
- **No Authentication**: The HTTP server has no authentication — any client can fetch provider data or hit `/metrics`
- **Environment Variable Config**: Provider API keys and endpoints are stored as `$ENV_VAR` strings in JSON configs — requires runtime env var wiring, not secrets management
- **No Rate Limiting or Quotas**: The HTTP server exposes no rate limiting

## SugarCraft Mapping

Catwalk is a **provider registry / model database**, not a TUI component. It does not map directly to any existing SugarCraft library, because SugarCraft ports are Elm-architecture TUI primitives (buttons, inputs, spinners, stylers, etc.). However, a SugarCraft port could be useful in a few indirect ways:

| SugarCraft Lib | Relationship | Rationale |
|---|---|---|
| `sugar-bits` (TUI components) | **Potential consumer** | Could use catwalk model metadata to build a model picker / selector TUI component (e.g., a dropdown listing all available providers and models with context window and pricing info) |
| `sugar-charts` (data viz) | **Potential consumer** | Could visualize pricing comparisons across models/providers using catwalk's `cost_per_1m_in/out` data |
| `candy-log` (logging) | **Potential consumer** | The catwalk server uses `log.Printf` — a SugarCraft `CandyLog` integration would improve observability |
| `candy-metrics` (Prometheus) | **Already aligned** | Catwalk already exposes Prometheus metrics (`catwalk_providers_requests_total`); a PHP port could use `CandyMetrics` for the same pattern |
| `candy-core` (TUI runtime) | **Reference architecture** | The Elm-style `Model/Update/View` pattern used in `candy-core` is similar to how catwalk organizes provider state — could inform a model-selector component architecture |

**No direct port candidate exists today** because catwalk's core value proposition (a structured provider/model database) is fundamentally a data-management problem, not a TUI rendering problem. The closest analog would be a **SugarCraft model-selector** or **AI provider browser** TUI component that _uses_ catwalk-style metadata — but that would be a consumer of the data, not a port of catwalk itself.

## Analysis

Catwalk is a focused, well-architected tool in the Charm ecosystem. Its sole job is to be the **authoritative registry of LLM providers and models** for the Crush shell. The design is clean: provider configs are JSON files embedded at compile time, served by a minimal HTTP server with ETag caching and Prometheus metrics. Each provider has a generator CLI that fetches the live model list from the provider's own API, transforms it to the canonical `catwalk.Provider` structure, and writes the JSON — this makes it genuinely community-maintainable without requiring central curation of model lists.

The type system is the real backbone. `InferenceProvider` and `Type` as typed string constants prevent typos at compile time. The `Model` struct captures everything needed to make an informed provider selection: context window, pricing (with cached-prompt variants), reasoning capability flags with effort levels, and whether images are supported. This is a level of metadata rigor that most provider lists (including many commercial ones) don't bother with.

The project is not trying to do too much. It doesn't proxy inference requests — that responsibility stays with Crush. It doesn't have authentication on its HTTP endpoints — the assumption is a trusted network or a separate auth layer in front. The biggest limitation is that provider configs are compiled-in at build time; a server restart is required to pick up new models or price changes. But given the CI workflow (`update.yml`) that can regenerate all configs on a schedule, this is an acceptable trade-off for a metadata service. The community-maintenance model is Catwalk's strongest feature — anyone can run `go run cmd/openrouter/main.go` to refresh the OpenRouter model list and submit a PR.
