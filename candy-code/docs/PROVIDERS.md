# Providers

A provider is the model API candy-code sends each turn to. This page has the
setup for every provider type, the environment variables each one reads, and the
defaults that apply when you leave a key out. The short version is in the
project README; the [environment reference](ENVIRONMENT.md) has the full table
for every variable candy-code reads, and [CREDENTIALS.md](CREDENTIALS.md) covers
where a key is stored.

## Choosing a provider

Pick a type, set its key, and start the app. With no provider configured,
candy-code runs an offline echo backend, so nothing is sent anywhere.

```sh
CANDY_CODE_PROVIDER=anthropic ANTHROPIC_API_KEY=sk-ant-... candy-code
```

`CANDY_CODE_PROVIDER` names the type, and `CANDY_CODE_MODEL` overrides the
model. Both are read at launch. The `provider` setting is the persistent form.

| Type | Talks to | Credential | Default model |
|---|---|---|---|
| `openai` | OpenAI's API | `OPENAI_API_KEY` | `gpt-4o` |
| `azure` | An Azure OpenAI resource | `AZURE_OPENAI_API_KEY` | `gpt-4o` (a placeholder; name your deployment) |
| `anthropic` | Anthropic's Messages API | `ANTHROPIC_API_KEY` | `claude-sonnet-4-6` |
| `claude-code` | The local `claude` CLI, as a subprocess | the CLI's own login, or `ANTHROPIC_API_KEY` | `claude-sonnet-4-6` |
| `codex` | The local `codex` CLI, as a subprocess | `codex login`, or `OPENAI_API_KEY` | `gpt-5.1-codex-mini` |
| `sglang` | A self-hosted SGLang server | none by default; `SGLANG_API_KEY` if the server asks | `Qwen/Qwen3.8-Flash-Next-FP8` (the server's served model is sent instead) |
| `bedrock` | Amazon Bedrock | the AWS SDK's own credential chain | `us.anthropic.claude-sonnet-4-6` |
| `vertex` | Claude on Google Vertex AI | Google application default credentials | `claude-sonnet-4-6` |
| `gemini` | Google's native Gemini API | `GEMINI_API_KEY` | `gemini-2.5-flash` |
| `openrouter` | OpenRouter's OpenAI-compatible API | `OPENROUTER_API_KEY` | `openai/gpt-4o` |
| `custom` | Any OpenAI-compatible endpoint you name | `apiKey` in the block, if the endpoint needs one | `gpt-4o` |

A provider block is a JSON object with a `type` key and the keys that type
accepts. Blocks live under `providers.<name>` in `~/.candy-code/config.json`. An
unknown `type` is refused at launch, and the refusal lists the types the app
does know.

## Per-engine setup

Every type below states its required keys, the environment variables it reads
when a key is not in the block, and what it sends when you leave a key out.
Required keys must be present and non-empty, or the launch stops and names the
missing key (and the environment variable to set, where there is one).

### `openai`

- **Required:** `apiKey`. Reads `OPENAI_API_KEY`; the default is an empty string,
  which the provider rejects.
- **Optional:** `organization` (from `OPENAI_ORG_ID`, default unset), `baseUrl`
  (from `OPENAI_BASE_URL`, default is the SDK's own `api.openai.com`), `model`
  (default `gpt-4o`), `api`, `modelPrices`, `contextWindow`, `supportsVision` and
  `fallbackModels`.
- `api` picks the wire. Unset or `"chat"` keeps the Chat Completions path, and
  `"responses"` sends every request to the Responses endpoint.
- Set `baseUrl` to point this type at a gateway or a compatible server.

### `azure`

- **Required:** `apiKey`, from `AZURE_OPENAI_API_KEY`. The key is sent in the
  `api-key` header.
- **Required, one of:** `resource` (your Azure OpenAI resource name) or
  `baseUrl`. With only `resource`, the host is
  `https://<resource>.openai.azure.com/openai/v1/`. If both are set, `baseUrl`
  wins.
- **Model:** Azure names deployments yourself, so `model` must be your deployment
  name. The default `gpt-4o` is a placeholder.
- **Optional:** `api` (`"chat"` or `"responses"`, the same choice as `openai`),
  `modelPrices`, `contextWindow`, `supportsVision` and `fallbackModels`.

### `anthropic`

- **Required:** `apiKey`, from `ANTHROPIC_API_KEY`, sent as `x-api-key`.
- **Optional:** `baseUrl`, from `ANTHROPIC_BASE_URL`, default
  `https://api.anthropic.com`. The root is given without `/v1`, and a base that
  already ends in `/v1` is used as it is. `model` defaults to `claude-sonnet-4-6`.
- `thinkingBudget` is accepted for extended thinking. `modelPrices`,
  `contextWindow` and `fallbackModels` are accepted as well.

### `claude-code`

- **Required:** `claudePath`, the `claude` binary to start. The default is
  `claude`, found on `PATH`.
- **Optional:** `model` (default `claude-sonnet-4-6`), `bare`, `isolate`,
  `fallbackModels`.
- The subprocess receives `ANTHROPIC_API_KEY`, `ANTHROPIC_AUTH_TOKEN` and
  `ANTHROPIC_BASE_URL` from the environment. A default bare spawn reads no OAuth
  token and no user state, so it needs `ANTHROPIC_API_KEY` (or an
  `apiKeyHelper`). Set `bare: false` to let the CLI use its own login.

### `codex`

- **Required:** `codexPath`, the `codex` binary. The default is `codex`.
- **Optional:** `model` (default `gpt-5.1-codex-mini`), `sandbox`, `isolate`,
  `fallbackModels`.
- There is no `apiKey` key on purpose. Subscription auth comes from `codex login`.
  If you bill by key, `OPENAI_API_KEY` in the environment is inherited by the
  subprocess.

### `sglang`

- **Required:** `baseUrl` and `model`. `baseUrl` defaults to `OPENAI_BASE_URL`,
  then `http://localhost:30000`. `model` defaults to
  `Qwen/Qwen3.8-Flash-Next-FP8`.
- The model in the block is a fallback. The provider asks the server which model
  it is serving and sends that name, so a redeploy does not leave a stale id in
  your config. Another id in the block is sent as written.
- `apiKey`, from `SGLANG_API_KEY`, defaults to none, which suits an
  unauthenticated local server.
- `reasoningEffort` and `toolCallParser` are unset by default and derived from
  the model. The DeepSeek-V4 family gets its own reasoning and parser defaults;
  other models get the OpenAI-shaped parser. Set either key to override.
- **Also accepts:** `templateKwargs`, `discoverServerInfo` (`false` turns off the
  server-info reads), `supportsVision`, `fallbackModels`, `contextWindow`, and the
  media keys `mediaKinds`, `mediaBaseUrl` and `mediaApiKey`, which
  [MEDIA.md](MEDIA.md#configuration) describes.

### `bedrock`

- **Required:** `region`. The bare default is `us-east-1`, and a block that
  omits `region` launches there. To take the region from the environment, write
  it as a placeholder, `"region": "${AWS_REGION}"`. If `AWS_REGION` is unset, the
  placeholder expands to empty and the launch refuses, naming `AWS_REGION`; write
  `"${AWS_REGION:-us-east-1}"` to fall back. The factory reads no other `AWS_*`
  variable for the region.
- **Optional:** `model` (default `us.anthropic.claude-sonnet-4-6`, an
  inference-profile id, which on-demand Claude 4.x throughput requires),
  `modelPrices`, `promptCache`, `fallbackModels`, `contextWindow`.
- Credentials are not in the block. The provider uses the AWS SDK's standard
  credential chain, so the usual `AWS_*` profile and role settings apply.

### `vertex`

- **Required:** `projectId`, from `GCP_PROJECT_ID`.
- **Optional:** `location` (default `us-central1`), `model` (default
  `claude-sonnet-4-6`), `thinkingBudget`, `modelPrices`, `promptCache`,
  `fallbackModels`, `contextWindow`.
- Credentials come from Google's application default chain:
  `GOOGLE_APPLICATION_CREDENTIALS`, then `gcloud` configuration, then the metadata
  server.

### `gemini`

- **Required:** `apiKey`, from `GEMINI_API_KEY`, sent as the `x-goog-api-key`
  header. `GOOGLE_API_KEY` is not read.
- **Optional:** `baseUrl`, from `GEMINI_BASE_URL`, default
  `https://generativelanguage.googleapis.com`. `model` defaults to
  `gemini-2.5-flash`. `thinkingBudget` is accepted, and so are `modelPrices`,
  `fallbackModels` and `contextWindow`.
- This is the API-key front door. For Claude on Google Cloud, use `vertex`.

### `openrouter`

- **Required:** `apiKey`, from `OPENROUTER_API_KEY`, sent as a bearer token.
- **Defaults:** `baseUrl` is `https://openrouter.ai/api/v1`, and the request
  carries the `HTTP-Referer` and `X-Title: candy-code` attribution headers.
  `model` defaults to `openai/gpt-4o`. OpenRouter model ids are `vendor/model`.
- `headers` replaces the attribution pair key by key. `baseUrl` overrides the
  default for a regional endpoint.
- **Also accepts:** `supportsStreaming`, `supportsFunctionCalling`, `extraBody`,
  `headers`, `supportsVision`, `modelPrices`, `contextWindow`, `fallbackModels`,
  and the media keys `mediaKinds`, `mediaBaseUrl` and `mediaApiKey`, which
  [MEDIA.md](MEDIA.md#configuration) describes.

### `custom`

- **Required:** `name`, `baseUrl` and `model`. `baseUrl` defaults to
  `OPENAI_BASE_URL`, then `http://localhost:8080`. `model` defaults to `gpt-4o`.
- **Optional:** `apiKey` (no default), `supportsStreaming` and
  `supportsFunctionCalling` (both default to `true`), `extraBody`, `headers`,
  `supportsVision`, `modelPrices`, `contextWindow`, `fallbackModels`, and the
  media keys `mediaKinds`, `mediaBaseUrl` and `mediaApiKey`, which
  [MEDIA.md](MEDIA.md#configuration) describes.
- Use `custom` for any server that speaks the OpenAI chat wire and is not one of
  the named types above.

## Native clients and subscription routes

The `anthropic` type key is the native Messages API client. `ProviderFactory::createAnthropic()` builds a dedicated `AnthropicProvider`: `x-api-key`/`anthropic-version` headers, a native `/v1/messages` body shared with Claude-on-Vertex through `AnthropicMessagesWire`, tools as native `tool_use` blocks, prompt caching marked by default, and a per-model Claude context table. `claude-code` remains the route for subscription login (`bare: false`, which drives the `claude` binary), and `CANDY_CODE_BACKEND_CMD` for shell relays. On that route the block's `isolate` key (default **true**) keeps the `bare: false` child from inheriting the user's `~/.claude` state: the binary is launched with `--setting-sources project,local`, `--strict-mcp-config` and `--disable-slash-commands`, and the host's Shift+Tab permission mode rides down as `--permission-mode` — narrowed only, never widened past what the host granted (unless the launch falls back, `fallbackModels`: the `claude-code` child then sits behind the wrapper and the mirror stands down). What the trio cannot cover: `claude`'s CLAUDE.md auto-discovery and auto-memory load under no switch except `--bare` itself (probed: `claude --help`, 2.1.312), so a `bare: false` launch says so once in a launch notice, and `isolate: false` restores the full user state — the documented escape if the CLI's own settings are part of the workflow you are subscribing to. The `codex` route takes the same key the same way: under `isolate` (default true) the `codex exec` child runs with `--ignore-user-config` — a foreign `config.toml`'s hooks, profiles and MCP servers drop out while authentication keeps using `CODEX_HOME` (probed: `codex exec --help`, 0.154.0) — and the spawn never defers its sandbox: `-s` always rides, the block's `sandbox` key when set else `workspace-write`, forced to `read-only` while the host holds plan or dont-ask, never widened by a host mode, `danger-full-access` reaching the wire only from your own configured spelling. A schema-bearing request also spills its JSON Schema to a `0600` temp file riding `--output-schema`, removed on every exit path; the route still answers `supportsJsonSchema()` false — a flag listed in `--help` is not yet a record of constrained decoding through a live login, so the flip is owed to a probe, not to a code change.

## Model metadata and fallback models

Context windows and prices for models a provider has no built-in figure for come from a **model database**: LiteLLM's `model_prices_and_context_window.json`, cached at `~/.candy-code/cache/` for 24 hours and refreshed in a detached background process, so it never holds the terminal (`ModelMetadata`). The `openai` and `custom` types consult it, in this order: your own `contextWindow` / `modelPrices` (the provider block's keys, else the user-tier settings), then the database, then the provider's built-in table. That is what sizes a hosted model behind a `custom` gateway. A `custom` model the database does not list — a self-hosted id — keeps 128,000 tokens and a real $0; give it a `contextWindow` to size it. Matching is exact (case-insensitive, or LiteLLM's own `provider/id` form), never fuzzy, so a self-hosted model is not billed at a hosted model's price because their names overlap. Offline, a failed refresh keeps the old file and waits a day; `CANDY_CODE_DISABLE_MODEL_METADATA=1` turns the database off entirely — see [`ENVIRONMENT.md`](ENVIRONMENT.md). This read is one of two launch-time egresses, and neither is an LLM call: the other is the once-a-day release-list check phar builds make to see whether a newer `candy-code` exists, behind its own switch, `CANDY_CODE_NO_UPDATE_CHECK=1`.

**Fallback models.** A provider block may name `fallbackModels`: other model ids on the same provider, tried in order (`"fallbackModels": ["gpt-4o-mini"]`, or a single string). `ProviderFactory` then wraps the provider in a `FallbackProvider`, which re-sends a request to the next model when it fails in one of the two ways another model can fix: a transient failure still failing after the provider's own retries (overloaded, rate-limited, a 5xx, a dropped connection), or a context-window overflow — and an overflow only moves to a model the model database knows to have a larger window, or whose size it does not know. Every other failure is raised exactly as before. On the streaming path the switch happens only before the first chunk arrives; after that the reply is that model's, and a failure mid-reply is the turn's to recover. Each switch puts one notice in the transcript naming both models and why, and a transient switch keeps the fallback first for 60 seconds — the status bar names it while it answers — before the original model is tried again (an overflow does not, since the next request may fit). Each fallback is the same block with only `model` replaced, so it is sized and priced as itself. No timeout is added to any call.

## SGLang: parsers, reasoning effort and server reads

The `sglang` type accepts an optional `toolCallParser` key, with three values:

- `'openai'` — read the server's parsed `tool_calls[]` array, and nothing else.
- `'minimax-xml-fallback'` — the same, but when that array is absent, recover
  MiniMax's raw `<minimax:tool_call>` XML out of the message content.
- `'dsml'` — the same, but recover DeepSeek-V4's native DSML markup
  (`<｜DSML｜tool_calls>`) instead.

All three read `tool_calls[]` first, so the two fallbacks cost one `isset` on a
correctly configured server. They matter only if your SGLang deployment was
launched *without* `--tool-call-parser`, which leaves the model's native
tool-call syntax unparsed in the content — and the DeepSeek-V4 model card's own
documented launch command omits that flag, which is why `dsml` exists.

**Omit the key** and the parser is derived from the model: `dsml` for the
DeepSeek-V4 family, `openai` for everything else. That is why
`defaultConfig('sglang')` reports `toolCallParser: null` rather than a name — a
stamped literal would keep applying after you edited `model` to another family.
This differs from `reasoningEffort` in no way; both are model-derived for the
same reason.

The setting applies to **both** the batch `complete()` path and the streaming
path. On the streaming path the fallback runs over the reassembled content once
the response ends, and only when the structured `delta.tool_calls[]` route
produced nothing, so it cannot duplicate a call. A recovered envelope is cut
out of the assistant's text on both paths, so the markup is neither shown nor
sent back to the model next to the structured call. To make that possible on
the streaming path, `minimax-xml-fallback` and `dsml` hold text back from the
screen from the first bytes that could open an envelope: a chunk ending in a
partial marker or a line break waits for the next one, and anything after a
complete marker waits for the end of the response. Text that turns out not to
be a recovered call is then shown unchanged. `openai` holds nothing back.

It also accepts an optional `reasoningEffort` key — SGLang's top-level
`reasoning_effort` request field. One of `none`, `minimal`, `low`, `medium`,
`high`, `xhigh`, `max`, or a float. Omit it and the value is derived from the
model: `max` for the DeepSeek-V4 family, and nothing at all for any other
model.

A misspelled **level name** is refused when the provider is built, so a typo in
a config file fails immediately. A **number** is not: the float range is the
server's (`0.0`–`0.99` inclusive when measured, `le: 0.99`) and is deliberately
not re-checked locally, since a later SGLang may widen it. The catch is that
JSON has no float/int distinction to lean on here — a whole number is read as a
float, so `"reasoningEffort": 1` becomes `1.0`, the one value just outside that
bound, and every request then fails with an HTTP 400 from the server rather than
at startup. Write `0.99`, not `1`.

Configure the **real model id**, not a `--served-model-name` alias. The
DeepSeek-V4 defaults above are selected by matching `deepseek-v4` in the `model`
string, so a server launched as `--served-model-name default` reports `default`
from `/v1/models`, and copying that into `model` silently gets you the legacy
`temperature = 0.7`, no `top_p`, no `reasoning_effort` and a 196,608-token
context window while you are in fact talking to DeepSeek-V4. There is no way to
detect that from the id alone — but the server can say, see below.

The provider also asks the server about itself: once per session it reads
`GET /model_info` and `GET /server_info` from the server root (`baseUrl` minus
`/v1`), with a 3-second bound, and fails soft. From them it takes the context
window (`min(context_length, max_req_input_len − 4096)`, so the compaction
tiers follow the deployment rather than a transcription of it) and the default
`max_tokens` (`min(262144, the room the prompt leaves)`; `maxOutputTokens`
still wins). When the reads fail, per-family figures stand in: 262144 output
tokens for DeepSeek-V4 and Qwen3.8, 4096 for anything else. The same read
names the served model and its `--tool-call-parser`: a served model of another
family than `model`, or a server with no tool-call parser while no textual
fallback is armed, raises a one-time transcript notice. The chat's status bar
ends with the served model's id (the configured `model` until the server has
named one) whenever the row has room left over — it is the bar's
lowest-priority piece, shortened to the part after the last `/` and then
ellipsised before it is dropped. Set
`"discoverServerInfo": false` in the provider block to skip the reads.

That default exists because an *absent* `reasoning_effort` is not neutral. On
DeepSeek-V4-Flash, a request without it comes back with `reasoning_content:
null` and the model's thinking written straight into `content` — so the
reasoning ends up in the reply the user reads instead of the collapsible
thinking pane. Sending a level moves it back to `reasoning_content`, which is
where `CompleteResponse::$reasoning` reads from.

The default `sglang` model is **whatever the server serves**. With no model
named (the generic `sglang` config, no `--model`, no `$CANDY_CODE_MODEL`), the
provider reads `served_model_name` from `/model_info` and addresses every
request to it, picking that model's sampling, reasoning-effort and tool-call
parser defaults; the served-model notice above does not fire, since there is no
configured model to contradict. Only when the server cannot be asked does it
fall back to `Qwen/Qwen3.8-Flash-Next-FP8` — a placeholder pinned to whatever
the maintainer's test endpoint happened to serve when it was written, nothing
more. A model you do name is sent as named. Once the served name is
known — read by the TUI process itself, or reported back by the first turn —
the Settings pane's `Model` row shows it instead of the fallback id.

The DeepSeek-V4 family also gets `temperature = 1.0` plus `top_p = 0.95` when
the request offers tools / `1.0` when it does not — the model card's own figures
for agentic and non-agentic use. MiniMax-M2.x is unaffected by all of the above: name it as the
`model` and the provider keeps its previous `temperature = 0.7`, sends no
`top_p`, and sends no `reasoning_effort` unless you set one explicitly. A
per-request override lives on `CompleteRequest::$reasoningEffort`.

## Keys every block may carry

- `model`: the model id sent on each request.
- `fallbackModels`: model ids of the same provider, tried in order when a request
  fails transiently or overflows its context. A single string is a one-model list.
  Blanks and duplicates are dropped.
- `contextWindow`, `modelPrices` and `supportsVision` describe the model you name.
  The per-type lists above say which types accept them.

## Placeholders in block values

Any string value in a block may be a placeholder, so one config file can serve
several machines without holding secrets:

- `${VAR}` takes the value of the environment variable `VAR`.
- `${VAR:-default}` takes `default` when `VAR` is unset or empty.
- `${keychain:<name>}` takes a secret from the OS keychain. It is resolved before
  the environment pass, and a miss gives an empty string.

A placeholder whose variable is unset and has no default becomes an empty
string. For a required key, the launch then stops and names the key, so a
missing secret fails loudly rather than sending an empty key.

## Environment variables

Each variable below is read when the provider block takes it from the
environment. The defaults apply when the variable is unset or empty.

| Variable | Type | What it sets | Default when unset |
|---|---|---|---|
| `OPENAI_API_KEY` | `openai`, `codex` | The OpenAI key. For `codex`, only when you bill by key. | `openai`: empty, which the provider rejects; `codex`: not read by the factory, and the subprocess inherits it if set |
| `OPENAI_ORG_ID` | `openai` | The organization id. | none |
| `OPENAI_BASE_URL` | `openai`, `sglang`, `custom` | The endpoint for OpenAI-shaped types. | `openai`: the SDK's host; `sglang`: `http://localhost:30000`; `custom`: `http://localhost:8080` |
| `AZURE_OPENAI_API_KEY` | `azure` | The Azure OpenAI key. | empty, which the provider rejects |
| `ANTHROPIC_API_KEY` | `anthropic`, `claude-code` | The Anthropic key. | `anthropic`: empty, which the provider rejects; `claude-code`: passed to the `claude` subprocess as an empty value, not refused |
| `ANTHROPIC_BASE_URL` | `anthropic`, `claude-code` | The Anthropic root, without `/v1`. | `anthropic`: `https://api.anthropic.com`; `claude-code`: the `claude` CLI's own endpoint |
| `ANTHROPIC_AUTH_TOKEN` | `claude-code` | A bearer credential passed to the `claude` subprocess. | unset |
| `SGLANG_API_KEY` | `sglang` | The key for an authenticated SGLang server. | none |
| `GCP_PROJECT_ID` | `vertex` | The Google Cloud project. | empty, which the provider rejects |
| `GEMINI_API_KEY` | `gemini` | The Gemini API key. | empty, which the provider rejects |
| `GEMINI_BASE_URL` | `gemini` | The Gemini API root. | `https://generativelanguage.googleapis.com` |
| `OPENROUTER_API_KEY` | `openrouter` | The OpenRouter key. | empty, which the provider rejects |
| `AWS_REGION` | `bedrock` | The region, but only when the block's `region` is a placeholder that names it, `"${AWS_REGION}"` or `"${AWS_REGION:-<default>}"`. The factory does not read it otherwise. | with `"${AWS_REGION}"`, empty: the launch refuses. With a `:-<default>` form, the default is used. A block that omits `region` uses `us-east-1`. |
| `AWS_DEFAULT_REGION` | `bedrock` | Not read by the provider factory. A directly built `BedrockProvider` with an empty region falls back to it when `AWS_REGION` is unset. | `us-east-1` |

The provider-selection variables `CANDY_CODE_PROVIDER` and `CANDY_CODE_MODEL` are
described in the [environment reference](ENVIRONMENT.md#app-variables).

## Troubleshooting

- **The launch stops naming a missing key.** Set the variable it names, or put
  the key in the block. A key stored in the keychain shows up here as an empty
  value if the lookup misses.
- **`azure` refuses with no host.** Give the block a `resource` or a `baseUrl`.
- **`sglang` answers with a 404 on the model name.** The server may serve a
  different model than the block names. The provider sends the served model
  when it can read it, so check the server's model list and the block's `model`.
- **A subscription CLI does not start.** Check `claudePath` or `codexPath` and
  run the CLI once by hand to finish its login.

## See also

- [CREDENTIALS.md](CREDENTIALS.md): `candy-code login`, the keychain, and how a key is found at launch.
- [ENVIRONMENT.md](ENVIRONMENT.md): every environment variable candy-code reads.
- [USAGE.md](USAGE.md): what each provider's calls cost, and the spend cap.
- [ADDING_A_PROVIDER.md](ADDING_A_PROVIDER.md): the contract a new provider type implements.
- [CONTEXT.md](CONTEXT.md): context windows and what happens when a turn overflows one.
