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
