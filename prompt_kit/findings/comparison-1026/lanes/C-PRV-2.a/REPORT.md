# C-PRV-2.a — OpenAI Responses wire (core) — DONE

Tip: bd2e0a2b164f54fc194b38cf431a6bd0d6fd6abd on cc/C-PRV-2.a (base 5ed26cf92). Author/committer detain verified, zero trailers. NOT pushed.

Landed: new trait src/Providers/Concerns/OpenAiResponsesWire.php (POST /v1/responses via
HttpClientDefaults — E646: connect-bound only, structurally pinned); OpenAIProvider routes
complete()/completeStream() to it on the ctor's new `api` prop (API_CHAT default byte-identical),
new `organization` (OpenAI-Organization header) + `responsesClient` test-seam props;
ProviderFactory: TYPE_SCHEMAS openai optional +'api', createOpenAI refuses unknown spellings by
name, fallback wrap inherits api; SETTINGS.md api paragraph + ADDING_A_PROVIDER.md two-arms law.
Wire: instructions/input items (message|function_call|function_call_output), flat tools through
normalizeToolSchema, store:false always, max_output_tokens + reasoning.effort gated by
shapesEffortFor (C-PRV-1.a table, round-down), temperature explicit-only, jsonSchema/topP/topK
dropped (=chat parity); SSE parses output_text.delta/arguments.delta/.completed with
.incomplete→truncated (E707) falling through for the usage frame; usage folds through parseUsage
(cached buckets, unpricedModel, cost).

NEW test file (gate owes durations row): candy-code/tests/Providers/OpenAiResponsesWireTest.php.
Gates (cwd candy-code, plain pipe, env -u LINES -u COLUMNS):
- --filter 'OpenAIProvider|ProviderFactory|OpenAiResponsesWire' → 258T/704A OK (new file alone 20T/92A).
- --filter '...DocFigure|GlobDialect|Stderr|ReadPath|DuplicatedHelper|SettingsSchema|EnvRoster|ConfigWriteProducer|TrustKey|LangParity|ReadmeRoster|ForeignAgentPreset' → 659T/71159A, 1F =
  DocFigure testAgentsAuthoringToolClaims ('thirty-seven' word map) — BASE-INHERITED, proven red at
  5ed26cf92 in the pristine main tree; TreeWideGuardRoster x2 likewise (LspInstallerTest scandir, 0
  roster rows at base — both proven). Not mine to cure; flagging to gate.
Red-before: M1 routes neutered → 13E+3F; M2 normalize unwrap → exactly the properties:{} pin;
M3 factory validation neutered → exactly the 'rest' refusal. All cp-restored md5-clean.
Deviations: scalar-args function_call replay carries rawArguments=NULL (argumentsError set);
fold-disclosure pins use unlisted model id (gpt-4o-mini is priced built-in).
