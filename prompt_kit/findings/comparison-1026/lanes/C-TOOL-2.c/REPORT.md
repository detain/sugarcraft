# Lane report — C-TOOL-2.c: Anthropic server-side web_search backend

Branch `cc/C-TOOL-2.c`, base master `1525b1832`. Tip SHA is this commit (recorded by the weld pick).

## What landed

`webSearchBackend` gains the value `anthropic`. When it is chosen AND the
provider is the direct Anthropic Messages wire, the local `WebSearch` tool
declaration is swapped IN PLACE for Anthropic's server tool
`{type:"web_search_20250305", name:"web_search", max_uses:<webSearchMaxResults>}`
— search runs provider-side inside the same reply. On any other provider
(and on the Vertex/Gemini Anthropic-shaped wires) `WebSearch` refuses with a
named reason; `/websearch` prints the same refusal through the tool result.

- `src/Tools/Web/Search/SearchBackends.php` — `BACKEND_ANTHROPIC` const,
  `NAMES` append, and a match arm returning an anonymous-class refusal
  sentinel (`isConfigured()=false`, so the existing WebSearch refusal arm
  feeds `/websearch` byte-for-byte; `search()` fails closed the same text).
- `src/Providers/Concerns/AnthropicMessagesWire.php` — server-tool consts,
  two null-default hooks (`anthropicServerWebSearchTool`,
  `anthropicServerWebSearchResume`) so Vertex/Gemini never swap or resume;
  in-place swap in `formatAnthropicTools`; error-arm + block-walk lifted
  into `anthropicErrorFrame`/`anthropicMessageLeg` (carried-null error frame
  byte-identical to before); `server_tool_use`/`web_search_tool_result`
  blocks NEVER surface as client ToolCalls — result URLs are harvested and
  folded into a UI-only Sources notice on the display-only `reasoning`
  ticker; `pause_turn` continuation loop in `parseAnthropicResponse`
  resends the partial assistant content VERBATIM (raw blocks incl. server
  blocks), bounded by `MAX_PAUSED_TURN_CONTINUATIONS = 8` LEGS — a count,
  not a clock (E646); stream: `parseAnthropicChunk` gains a 5th by-ref
  `$pauseState` (4-arg callers untouched) accumulating verbatim resume
  blocks + sources + the search count on the terminal `message_delta`
  carrier only; `anthropicStreamWebSearchFrame` lands the continuation as
  ONE unary terminal frame plus the Sources notice.
- `src/Providers/AnthropicProvider.php` — `supportsServerWebSearch()`
  capability (reads the setting at call-shape time, so a mid-session flip
  lands next request), the two hook overrides, `complete()` passes the
  request body for continuation, `completeStream()` threads `$pauseState`
  and yields the closing server frame.
- `src/Usage.php` — `webSearchRequests` int tail field (UNPRICED extra:
  never enters totalTokens/costUsd; spend reads `>=`); full pair-law
  treatment (`new/reported/plus/mutate/toArray/fromArray`; absent→0,
  non-int frame refused). `parseAnthropicUsage` reads
  `usage.server_tool_use.web_search_requests`.
- `docs/SETTINGS.md` — `php tools/gen-settings-doc.php --write` reports
  "up to date": the row prints the default, not the enum list, so the
  widening is a zero-diff for the doc; nothing to commit there.

## Files changed

- `candy-code/src/Tools/Web/Search/SearchBackends.php`
- `candy-code/src/Providers/Concerns/AnthropicMessagesWire.php`
- `candy-code/src/Providers/AnthropicProvider.php`
- `candy-code/src/Usage.php`
- `candy-code/tests/UsageTest.php` (in-step pair flips + new count method)
- `candy-code/tests/Providers/AnthropicServerWebSearchTest.php` (NEW)
- `candy-code/tests/Providers/PromptPrefixByteStabilityTest.php` (new
  anthropic tool-list carve-out method: swap frozen byte-identical across
  steps; searxng shape proven different)
- `prompt_kit/findings/comparison-1026/lanes/C-TOOL-2.c/REPORT.md`

## NEW test files (weld durations rows owed)

- `candy-code/tests/Providers/AnthropicServerWebSearchTest.php` — 8 methods.

## Guards run (targeted, plain pipe, cwd candy-code)

- `--filter 'AnthropicServerWebSearchTest'` → 8T/52A OK.
- `--filter 'PromptPrefixByteStabilityTest'` → 10T/102A OK (was 9).
- `--filter 'UsageTest|SearchBackendsTest'` → 58T/293A OK.
- `--filter 'AnthropicProviderTest|VertexProviderTest'` (mid-lane) → 173T/485A OK.
- `--filter '...DocFigureProseDrift...'` broad batch → 775T/85,703A, 3F —
  all three diagnosed (below); re-runs green: DuplicatedTestHelperDrift +
  GlobDialect + new file → 35T/617A OK.
- Final batch → 687T/81,595A, 4F, all four EXPECTED:
  - `ReadmeSuiteFigureDriftTest` staleness pair — live enumeration grew by
    this lane's new methods; suite-figure.json/README re-pin is a WELD duty
    (lane never touches those files).
  - `TreeWideGuardRosterTest` walker-roster pair — PRE-EXISTING inherited
    reds from foreign commit `6485cb636` (`Cli/ServeArgsTest.php`
    `scandir($this->tlsDir)`): proven unrelated by removing this lane's new
    test file and re-running — identical 2F.
- SettingsSchemaDocDriftTest green in batch; `gen-settings-doc --write` zero diff.
- The 2 `PHPUnit Deprecations` are the pre-existing runner baseline (also
  present on untouched suites).

## Disclosures / deviations

1. `src/Tools/BuiltIn/WebSearch.php` and `src/Config/Settings/Definitions/ToolSettings.php`
   needed ZERO edits (design listed them): the refusal rides the tool's
   existing `!isConfigured()` arm, and the enum definition derives
   `withEnumValues(SearchBackends::NAMES)`, so widening `NAMES` auto-widens
   the setting — the `assertSame(NAMES, enumValues)` pin stays green
   untouched.
2. `lang/en.php` `settings.webSearchBackend.help` still enumerates only the
   HTTP backends — the key is NOT in this step's files list (and the
   sixteen-locale parity law would demand all locales or none). Seam for a
   follow-up doc micro.
3. Streamed continuation arrives as ONE unary terminal frame (pre-pause
   deltas already streamed; nothing re-streamed). Disclosed in code comment.
4. Pause bound is a LEG COUNT (8), never a wall-clock kill — E646
   compliant; if the provider keeps pausing, the notice states the answer
   may be incomplete.
5. The search count rides ONLY the terminal `message_delta` carrier (the
   start carrier deliberately stays 0); the test pins
   `Usage::sum(frames)->webSearchRequests == 2` for a paused stream —
   exactly-once.
6. The Sources notice rides the display-only `reasoning` ticker, never
   `content` — history replays text only (Anthropic stores no citation
   blocks), and the `tool_use`/`server_tool_use` never-ToolCall law is
   pinned both batch and stream.
7. Notice prose is raw English — provider-wire-parse precedent (E27
   streamed-JSON error), not `Lang::t`: a new key would need 16 locale
   files this step does not own. Disclosed.
8. A continuation leg that errors returns the error frame CARRYING the
   merged prior usage (dollars/tokens of answered legs are not lost); the
   partial text of prior legs is disclosed-loss (error frames carry no
   content channel today).
9. The `anthropic` sentinel is an anonymous class in the factory match arm —
   keeps `files.create` strictly the test file and one-type-per-PSR-4 clean.
10. `docs/SETTINGS.md` listed by design but generator-verified zero-diff
    (row prints defaults not value lists) — nothing to commit for it.
