# Adding a provider

A provider is one class that answers `complete()` and `completeStream()` for
an HTTP (or CLI-subprocess) API, plus its registration. Every provider in
`src/Providers/` follows the same contract; the pieces below are what the
engine actually consumes, and what breaks quietly when a provider omits them.

## The contract

`ProviderInterface` names the surface: `name()`, `complete(CompleteRequest
$request): CompleteResponse`, `completeStream(): \Generator`,
`embeddings()`, and the capability answers (`supportsStreaming()`,
`supportsFunctionCalling()`, `supportsVision()`, `supportsDocuments()`,
`supportsJsonSchema()`, `contextWindow()`, `costPer1kTokens()`). The
capability flags are load-bearing outside the class: JSON-schema requests are
refused at launch (`json_schema_unsupported`, exit 2) when
`supportsJsonSchema()` says no, and vision or document requests check theirs
before the call is ever built.

Attachments ride `AttachmentEncoding`, so a new provider answers the flags
against the shape its own body builder produces, not against the model
vendor's marketing page. C-TOOL-2.e: the native document legs are the
Anthropic Messages family (base64 `document` block), Bedrock Converse (RAW
`document` block, name shaved to the Converse charset), Gemini — including
Vertex's Gemini arm through the same shared encoders — (`application/pdf`
`inlineData`), and the OpenAI **chat** arm only (`file` part with a
`data:application/pdf` URL; the Responses arm shares `openAiContent()` but
maps only image parts, so it stays on the named decline, as do the SGLang
and Custom servers that speak neither document shape). Answer
`supportsDocuments()` true only when the body actually carries the part:
true-but-undeclared is a silent drop, declared-but-unbuilt is a wire error,
and either one is the bug the never-silent rule exists to refuse. The encoders
fail closed on the bytes themselves — a forged or oversized PDF (no `%PDF-`
head, or past `FileMentions::DOCUMENT_MAX_BYTES`) is named in the text on
every leg, never dropped and never inlined.

Reasoning-effort shaping is a separate opt-in: `ShapesReasoningEffort` adds
`shapesEffortFor(string $model): bool`, and the `/effort` surface consults it
through `EffortGate::verdict()` — a marked class whose predicate answers no
for the live model is refused on the wire rather than silently swallowing a
set value (that is why `gpt-4o` says so out loud now). The status phrase
comes from `EffortGate::shape()`, never from the command itself.
`ThinkingCapability` is the per-family table (thinking support, output
ceiling) the Anthropic/Gemini-armed predicates read from. CLI-spawning classes
shape by forwarding the tier as a flag — codex as
`-c model_reasoning_effort=<level>`, claude-code as `--effort <level>` — and
`BedrockProvider` spends it as an Anthropic
`additionalModelRequestFields.thinking` budget on its Claude ids. A provider
with no conceivable use for the tier (Custom, Echo) implements no marker at
all.

Return a `CompleteResponse` — a readonly DTO, constructed with named
arguments. The fields the engine consumes:

- `content`, `reasoning`, `toolCalls` — the reply body and any calls the
  model asked to run.
- `tokensUsed` / `costUsd` — the flat projection. Kept everywhere because
  older readers only sum a total.
- `usage` — the provider's own usage document parsed into
  `SugarCraft\Code\Usage`, buckets split (`promptTokens` is defined as
  `cacheRead + cacheCreation + input`). Pass the carrier whenever the wire
  reports split buckets; `Runtime::foldUsage()` prefers it and falls back to
  the flat projection only when a carrier measures nothing. A null means
  "not carried", never "zero" — and on streams, delta frames legitimately
  carry no usage, so the field stays null there. Chat's tier calibration
  reads `promptTokens()` through this carrier; without it, the estimate
  stays honest-but-looser.
- `truncated` — set it from the wire's stop field, never by guessing:
  OpenAI-style `finish_reason` of `length` (and `abort` on the Custom
  family), Anthropic `stop_reason: max_tokens`, Gemini `MAX_TOKENS`,
  Bedrock `max_tokens` only. A stream sets it on whichever frame carries
  the stop signal; every other frame keeps the default false. A stop reason
  that was never seen is `false`, not a cleanup proof — the field is each
  provider's statement about the frames it read. The engine ORs it across
  the turn's chunks, carries it to the transcript notice (`chat.turn.
  output_limit` in the TUI, a trailer line and typed JSON keys on the
  one-shot path), and never retries it — a length stop is a success the
  ceiling cut short.
- `isError` / `errorMessage` / `errorTransient` — error responses reported
  as values instead of thrown (the Custom and Vertex families do this)
  should carry `TransientFailure::isTransient()`'s verdict from the catch
  site, or `anthropicErrorIsTransient()`'s for structured error payloads on
  a 200 response. Null means unclassified, and the retry layer treats
  unclassified like permanent — an allow-list rule, so an unrecognised
  exception must not win a retry by silence.
- `errorContextOverflow` — `ContextOverflow`'s verdict, so the compaction
  tier reacts to a full window rather than retrying into it. Null means
  unclassified: the fallback reads the error wording instead, so a provider
  that never sets it still works.

## Registration

Add the type string to `ProviderFactory::availableTypes()` and a construction
arm in its `match`, with defaults in `defaultConfig()`. Names in
`.candy-code/config.dev.json`'s `providers` map resolve through the same
factory before a type is rejected, so a deployment can select a named
endpoint (`dev-sglang`) through `$CANDY_CODE_PROVIDER` with no code change.
A new built-in type must appear in the README's provider list and in
`docs/ENVIRONMENT.md`'s provider-variable tables at the same time — the env
roster guard diffs variable names against those tables, and an ambient
`OPENAI_*`-style name it cannot find there goes red.

An existing type may also split into two wires behind one block. The `openai`
arm's `api` key (documented in [SETTINGS.md](SETTINGS.md)) selects between the
Chat Completions path and the Responses path: one class, one registration, and
a route taken at the top of `complete()` and `completeStream()` before either
arm builds a request. A split like that must stay binary — an unknown
spelling throws by name when the provider is built, because a value that
reached the live object would answer neither arm's endpoint.

The converse precedent is a type that reuses an existing wire under its own
name (C-PRV-2.c): `azure` registers `OpenAIProvider` against the per-resource
Azure host with the key moved to the `api-key` header and the model catalog
closed (the `/models` shape does not exist there), and `openrouter` registers
a `CustomProvider` preset carrying the documented base plus the
`HTTP-Referer`/`X-Title` attribution pair, overridable per block through the
`headers` key a custom provider now also accepts. A reuse still needs its
own schema row, `defaultConfig()` arm, `REQUIRED_KEY_ENVS` hint, README row,
and — when it introduces a new environment name — the same-time
`docs/ENVIRONMENT.md` table entry the guard enforces.

The thinking-block carrier (`CompleteResponse::$thinkingBlocks`, replayed by
`AssistantMessage::thinkingBlocks()`) is owned by the WIRE that produced the
blocks, not by the store that persisted them: every parse seam collects only
shapes its own replay understands, tags them so the list can be shared
fleet-wide, and every replay seam filters by tag before re-sending. Anthropic
replays `thinking`/`redacted_thinking` verbatim; the Responses arm re-sends
its tagged `openai_reasoning_item` entries as `reasoning` items carrying the
encrypted content the endpoint asked for via `include` (C-PRV-2.b). A block
that cannot be replayed is never collected, and a block from another family's
wire is skipped, not rejected — a store replayed under a different provider
must degrade to text, not 400.

## Behavior laws a provider must not break

- **No blanket request timeouts on the LLM call path.** A provider must not
  install its own wall-clock kill on a model request; long thinking is a
  legitimate answer. Ceilings are REQUEST fields the user opts into
  (`maxOutputTokens` is the output-token ceiling, layered user-tier only;
  `maxCostUsd` caps spend) — not process deadlines.
- **Prompt-cache byte stability.** When a wire supports cache breakpoints,
  mark them through the existing seams (`MarksPromptCache`,
  `CacheBreakpoints`) and never reorder or re-serialize the stable prefix
  per request; the breakpoints are position promises to the provider's
  cache.
- **Fail loud on construction.** `ProviderFactory` throws
  `ProviderException` (with a stable `exitCode` property, not an invented
  status) for unknown names or missing credentials; `-p` turns that into
  exit 2. A provider that swallows a bad key and answers with an empty
  reply is the one shape never allowed.
- **Estimate calibration is engine-side.** Do not "help" the cost math by
  reporting a guess as a measurement — bill what the wire reported, and let
  Chat's factor clamp deal with estimates.

## Testing a new provider

`tests/Providers/` doubles the transport (a scripted HTTP response per wire)
and asserts the parse: split-bucket carriers, truncated-flag edges for each
stop spelling (including the frames that must NOT set it), transient
classification, and stream framing whose stop arrives only in the closing
frame. A new test file needs a `scripts/parallel-tests-durations.tsv` row
(see [CONTRIBUTING.md](CONTRIBUTING.md)) before the sharded gate will run it.

## See also

- [ARCHITECTURE.md](ARCHITECTURE.md) — where `Runtime` and `EngineBackend`
  sit between Chat and the provider.
- [CONTRIBUTING.md](CONTRIBUTING.md) — suite cadence and the drift guards.
- [ENVIRONMENT.md](ENVIRONMENT.md) — the provider selection and credential
  variables.
