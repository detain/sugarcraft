# Environment Variables

Every environment variable CandyCode reads, in one place. Until this page
existed the surface was documented piecemeal — three variables in
`./bin/candy-code --help`, one more in the README's "Mouse" section, two more
only in PHP docblocks, and the rest discoverable only by `grep`.

Four groups are listed separately because they behave differently:

- **App variables** (`CANDY_CODE_*`) configure CandyCode itself. Every one is
  optional; each row gives the behaviour when it is unset.
- **Provider credential variables** are read on your behalf when
  `ProviderFactory` builds a provider's *default* config. They are the
  vendors' own variable names, not ours, so they are spelled exactly
  as the vendor SDKs spell them.
- **Claude Code variables** are Claude Code's own relocation switches, read
  only by `/memory import claude` so it finds the memory Claude Code wrote.
- **Terminal colour variables** are ambient color variables consulted via the
  foundation palette — read by `sugar-core`'s `ColorProfile::detect()`, not by
  `src/` directly. See [Terminal colour variables](#terminal-colour-variables).

Environment variables are the highest-precedence configuration tier: they win
over a choice persisted to `~/.candy-code/config.json` — written by the Ctrl+P
palette's Switch Model row, by `/model <name>`, by the palette's Switch Theme
row, or by `/theme <name>`, all four of which reach the identical write — which
is what makes them the right override for scripting and CI.

---

## App variables

All are named `CANDY_CODE_*`. See
[Deprecated aliases](#deprecated-aliases) for the two that briefly differed.

| Variable | Default when unset | Description | Settings key |
|----------|--------------------|-------------|--------------|
| `CANDY_CODE_PROVIDER` | offline `EchoProvider`, or a provider persisted to `~/.candy-code/config.json` | Which LLM provider to use: `openai`, `anthropic`, `claude-code`, `codex`, `sglang`, `bedrock`, `vertex`, `gemini`, `custom`, or any provider name declared in the `.candy-code/config.dev.json` that ships inside the candy-code package (the repo's copy declares `dev-sglang`). That block points at `https://skynet2.interserver.net/v1` serving `Qwen/Qwen3.8-Flash-Next` with no auth. Its `reasoningEffort` is sanitized per request into the template's exact vocabulary — `xhigh` (default), `medium`, `low`, nothing else (an out-of-vocabulary effort answers with a clean 400). The optional `templateKwargs` key merges UNDER any per-request template kwargs and ships verbatim as the top-level `chat_template_kwargs` field the chat template reads; the repo's shipped policy is `{"preserve_thinking": false}`, which makes a history re-render drop the assistant turns' stored reasoning instead of paying those replayed tokens back every turn — the template's default (`true`) was measured to re-render them, so flip the value only if cross-turn reasoning continuity is worth the token cost. | `provider` |
| `CANDY_CODE_MODEL` | the model the `models` key persists for the selected provider, else that provider's default model | Pins the conversation model for whichever provider runs, overriding the persisted `models` entry and the provider default (e.g. `gpt-4o` for `openai`); `--model` outranks it. | `models` |
| `CANDY_CODE_SERVICE_TIER` | the `serviceTier` key persisted in `~/.candy-code/config.json`, else unset — the standard lane | Asks the provider's premium fast lane where the current provider+model pair shapes one: openai sends `service_tier: "priority"` on both its Chat and Responses arms, anthropic sends `speed: "fast"` on the fast-mode families only (`claude-opus-5-5`, and the preview `claude-opus-5` / `claude-opus-4-8` whose required `fast-mode-2026-02-01` beta header rides automatically). A pair that shapes nothing stays on the standard lane. Fast mode bills a premium no model-keyed price table can derive: turns on a tiered pair land on the ledger as unpriced, never as a guessed rate. Read at every engine build — launch and provider or model rebuild; an unrecognized value asks nothing. | `serviceTier` |
| `CANDY_CODE_TITLE_MODEL` | the `titleModel` key in `~/.candy-code/config.json`, else the provider type's cheap auxiliary default (Haiku-class on the Anthropic wires, `gpt-4o-mini` on openai), else the block's model | The cheap small model used to auto-name a session after its first exchange. Kept separate from `CANDY_CODE_MODEL` so naming never costs a full tool-capable agent turn. A run with no title backend (no provider — e.g. the `CANDY_CODE_BACKEND_CMD` tier) is never auto-titled: the title call never falls back to the conversation backend, which may be an agentic command or carry tools. `/rename` still names the session. | `titleModel` |
| `CANDY_CODE_SUMMARY_MODEL` | the `summaryModel` key in `~/.candy-code/config.json`, else the conversation's own model | The model that writes compaction summaries: `/compact`'s and the automatic 85% tier's exchange records, and the in-turn step summary. Unset, a summary is sent as the conversation's own request — same system prompt, tools and history, plus one instruction not to call tools — on the conversation's model, so it reuses the provider's prompt cache; naming another model here trades that cache hit and summary quality for a cheaper call. Either way the tools are advertised but never run, so a compaction cannot call a tool or raise a permission prompt. Read once at launch. It also picks the tool-less backend auto-memory consolidation runs on. Deliberately separate from `CANDY_CODE_TITLE_MODEL`'s cheap titling model: a compaction summary is what the model will be shown of the earlier conversation from then on, and a bad one is permanent context loss. A run with no provider at all still compacts, using the local heuristic. | `summaryModel` |
| `CANDY_CODE_ADVISOR_MODEL` | the `advisorModel` key in `~/.candy-code/config.json`; with neither set, no advisor backend is built | The model the `Advisor` tool consults for a second opinion on one question about the conversation: a tool-less completion on a bounded digest of the session transcript, never on the conversation's own tool-armed engine. Unlike `CANDY_CODE_TITLE_MODEL` there is no cheap auxiliary fallback — the point is a model you picked deliberately, so an unset value means the `Advisor` tool is not offered at all, not that it quietly dials the smallest model. Answers are billed: the completion's usage rides into the turn's spend sum, and the tool refuses itself once the `--max-cost`/`CANDY_CODE_MAX_COST` ceiling is reached. Read at launch. | `advisorModel` |
| `CANDY_CODE_MAX_COST` | unset — no cap | A spend ceiling for this launch, in US dollars (fractional allowed, e.g. `2.50`). A leading `$` and surrounding whitespace are accepted, so `$2.50` works. `/budget` sets, shows and clears the same ceiling at runtime. A **present but unusable** value stops the launch with exit 2 rather than being ignored — `5USD`, `five dollars`, `0`, `-5` and `1e309` (which is infinity, and would install a cap that never triggers) all refuse to start. Empty or unset is absence and means no cap. The asymmetry with `/budget 0`, which merely answers in the transcript, is deliberate: that refusal is *visible*, whereas this variable is read once at launch, so discarding it silently would hand the user an uncapped session they believed was capped. Enforcement refuses the **next** turn once the reported spend has reached the cap; it does not abort one in flight, so the final total can overshoot by that one turn's cost. It also gates `/compact`'s model-written summaries — the compaction still happens, on the local heuristic, and says so. It only ever acts on figures a provider actually reported, and a streamed turn commonly reports none — so this is a budget guard, not a spending control. Also settable as `maxCostUsd` in your own settings files (never a project's), the ceiling every launch starts with; the `--max-cost` flag beats this variable, which beats the setting, and a present `maxCostUsd` that is not a positive number refuses the launch the same way. | `maxCostUsd` |
| `CANDY_CODE_BACKEND_CMD` | unset — the provider is used directly | The **prose** shell-out: a command that reads JSON history on stdin and writes the reply to stdout, which is used **verbatim** — newlines, blank lines, lists and code fences all survive (a `trim()` at the two ends is the only transformation). Set it to avoid PHP provider SDKs entirely. Takes priority over a persisted provider choice, and over `CANDY_CODE_BACKEND_CMD_STREAM`. There is no completion deadline on this path; a completion can legitimately run for many minutes. See [The two shell-out variables](#the-two-shell-out-variables). | — |
| `CANDY_CODE_BACKEND_CMD_STREAM` | unset | The same shell-out under the **other** stdout contract, a **token stream** rather than prose: the command writes **one token per line**, the newline *between* two tokens is framing and is dropped, and a **blank line is a literal newline** in the answer (an unterminated empty remainder at EOF is nothing at all). So the protocol can express any string — but only through that blank-line rule. **The two variables are not interchangeable in either direction:** a wrapper written for `CANDY_CODE_BACKEND_CMD` (e.g. `curl … \| jq -r '.content[0].text'`, which emits the model's prose) comes back through this one with every newline it emitted gone and each blank line collapsed to a single newline — so a paragraph break, a list and a code fence do not survive. Use this only for a wrapper that really does emit one token per line, such as `curl -sN … \| jq -r '.message.content'` against Ollama's streaming endpoint. Ranked below `CANDY_CODE_BACKEND_CMD` (which wins when both are set) and above a persisted provider choice. No completion deadline either. See [The two shell-out variables](#the-two-shell-out-variables). | — |
| `CANDY_CODE_SEARCH_ENDPOINT` | unset — **no default**; `WebSearch` refuses every call, naming this variable, until it or the `webSearchEndpoint` setting is set | Search URL of the SearXNG instance the built-in `WebSearch` tool (and `/websearch`) queries, e.g. `https://searx.example.org/search`. There is deliberately no built-in default: the one that used to ship was a private host reached over cleartext `http://`, so every model-composed query — which routinely quotes code, file names and error text from your repository — crossed the network unencrypted to a third party, unprompted under the default `bypass-permissions`. Prefer an `https://` URL. An empty value counts as unset. A redirect from the endpoint is refused, never followed, so give the final URL. To keep one across launches, set the `webSearchEndpoint` setting instead (user tier only: a project file cannot choose where your queries go); this variable outranks it. Both are read when the tool is built, so a change applies from the next launch. | `webSearchEndpoint` |
| `CANDY_CODE_PERMISSION_MODE` | the `permissionMode` key in `~/.candy-code/config.json`, else `default` in the TUI and `bypass-permissions` for `-p` and background sessions | The launch's permission mode: `default`, `accept-edits`, `plan`, `auto`, `dont-ask` or `bypass-permissions`. Same vocabulary an agent preset's `permissionMode:` frontmatter uses, so `plan` means the same thing in both places. An **unrecognised value stops the launch** with exit 2 rather than being ignored — every fallback in the chain ends somewhere more permissive, so silently discarding a mode the user set on purpose is a fail-open. An empty value counts as unset and falls through to the config. The default **depends on the path**: the TUI starts in `default`, because every `Ask` there — the engine path's included — reaches the y/n/a modal; `-p` and the background-session daemon start in `bypass-permissions`, because their console approver asks on stderr at a terminal and *refuses* without one, so an asking default would refuse an unattended run's first edit. See [`PERMISSIONS.md`](PERMISSIONS.md#setting-the-mode). With no `permissionRules` configured, `bypass-permissions` is *identical* to having no gate — the `rm -rf /` circuit breaker refuses nothing that `ConfirmRemoveHook` does not already refuse more broadly and earlier. What it buys is a gate that is reachable and configurable. | `permissionMode` |
| `CANDY_CODE_SESSION_RETENTION_DAYS` | `0` — retention is **off**, nothing is ever pruned | A positive whole number of days. Each launch drops sessions untouched for at least that long and reports what it removed. **This row used to say the report goes "on stderr"; since round 42 it goes to both channels, split.** The one-line summary (`retention removed 3 unnamed sessions untouched for 30+ days (ids on stderr)`) is seeded into the transcript as well, because an interactive launch paints the alternate screen over stderr before you can read it and a deleted conversation is not something to learn about after the session ends; the per-session ids stay on stderr alone, because one transcript row per deleted session is a per-entry fan-out that buries the rows around it (launch notices are display-only — they never reach the model — so the cost is the user's scrollback, not tokens). See [`SETTINGS.md`](SETTINGS.md) for the routing rule. A session you have named is never pruned whatever its age, and neither is the session the launch is about to resume. Non-numeric and negative values read as `0`; values are capped at `36500` (100 years). Also settable as `sessionRetentionDays` in your own settings files (never a project's — it deletes your history), read at launch; the environment variable wins whenever it is set to anything non-empty, `0` included. | `sessionRetentionDays` |
| `CANDY_CODE_CONNECT_TIMEOUT` | `15.0` seconds | Connect-phase bound (in seconds, fractional allowed) for provider HTTP transports. This bounds establishing the connection only — it is **not** a total-request timeout, because a completion can legitimately run for many minutes. Non-numeric values, and anything below `0.001`, fall back to the default rather than disabling the bound — by way of the `connectTimeoutSeconds` setting, which such a value falls through to exactly as an unset one does. Read when a provider client is built (launch, provider switch). The streaming header wait is the separate `streamHeaderTimeoutSeconds` setting (default 120 s): the TCP pre-flight probe enforces this connect bound alone. | `connectTimeoutSeconds` |
| `CANDY_CODE_DISABLE_PARALLEL_TOOL_CALLS` | unset — a turn's read-only tool calls run concurrently | Set to any value other than empty or `0` to force every tool call in a turn to run sequentially. Also settable as `parallelToolCalls: false` in `~/.candy-code/config.json`; the environment variable wins. | `parallelToolCalls` |
| `CANDY_CODE_PARALLEL_TOOL_DEADLINE` | `90` seconds | Wall-clock ceiling for one batch of concurrently dispatched tool calls. Also settable as `parallelToolDeadlineSeconds` in `~/.candy-code/config.json`; the environment variable wins. | `parallelToolDeadlineSeconds` |
| `CANDY_CODE_CONTEXT_PRUNING` | the `contextPruning.mode` key in `~/.candy-code/config.json`, else `auto` | How a session prunes its context when it has not chosen with `/pruning`: `auto` prunes superseded rows at each turn start (one batch, only once it frees 20k tokens), lets the model prune its own finished outputs with `Prune`, and tags every tool result with its ref (`<ctx-ref r="N"/>`); `manual` prunes only on `/sweep` and keeps the tags; `off` runs no strategies, shows no tags and refuses `/sweep`. A value that names no mode is ignored. Read per turn. | `contextPruning.mode` |
| `CANDY_CODE_DISABLE_MOUSE` | unset — mouse tracking is on | Set to any value other than empty or `0` to turn mouse tracking off entirely: no wheel scrolling, no clickable tool calls, session tabs, palette rows or menu bar. The escape hatch for terminals whose own selection behaviour you would rather keep. Also settable as `mouse: false` in any settings file (a save in the settings view applies it at once); the environment variable wins. | `mouse` |
| `CANDY_CODE_DISABLE_MOUSE_CLICKS` | unset — clicks are handled | Set to any value other than empty or `0` to ignore click gestures while keeping wheel scrolling. Narrower than `CANDY_CODE_DISABLE_MOUSE`. Also settable as `mouseClicks: false` in any settings file; the environment variable wins. | `mouseClicks` |
| `CANDY_CODE_DISABLE_PROMPT_SUGGESTIONS` | unset — suggestions are on | Set to any value other than empty or `0` to stop the grayed next-message suggestion the empty input box shows after each turn (`→` accepts it). Each suggestion is one extra call on the `CANDY_CODE_TITLE_MODEL` backend — tool-less, so it can never run a tool or raise a permission prompt — shown the last 12 messages of the transcript (the `promptSuggestionHistory` setting); it counts towards the spend total and stops once `CANDY_CODE_MAX_COST` is reached. A run with no title backend (no provider) never makes the call. Also settable as `promptSuggestions: false` in any settings file (a save applies at once); either one turns suggestions off. | `promptSuggestions` |
| `CANDY_CODE_DISABLE_AUTO_MEMORY` | unset — auto-memory is on | Set to any value other than empty or `0` to stop auto-memory: the end-of-turn run, at most once every five minutes per project, that shows the new conversation to the `CANDY_CODE_SUMMARY_MODEL` backend — tool-less — and saves the durable facts it finds as memory notes tagged `auto-memory`. Each run is one extra call; it counts towards the spend total and stops once `CANDY_CODE_MAX_COST` is reached. It also stops the dream pass, the periodic engine turn that folds the compaction journal into those notes. See [`MEMORY.md`](MEMORY.md#auto-memory) and [Dream pass](MEMORY.md#dream-pass). | `memory.autoConsolidate` |
| `CANDY_CODE_DISABLE_PROMPT_CACHE` | unset — breakpoints are enabled | Set to any value other than empty or `0` to stop the `vertex` and `bedrock` providers marking prompt-cache breakpoints: no `cache_control` on Vertex's Claude requests, no `cachePoint` blocks on Bedrock's. Read once, when the provider is built (`ProviderFactory::promptCacheEnabled()`, through `CacheBreakpoints::disabledFromEnvironment()`), and it outranks the `promptCache` setting in [`SETTINGS.md`](SETTINGS.md), which is the persistent way to say the same thing. Cache tokens a provider still reports (Gemini 2.5 caches on its own) are priced either way. Turn it on when a model rejects the marks; expect every request to bill its whole prompt at the full input rate while it is on. | `promptCache` |
| `CANDY_CODE_DISABLE_MODEL_METADATA` | unset — the model database is on | Set to any value other than empty or `0` to stop candy-code reading and downloading its model database: LiteLLM's `model_prices_and_context_window.json`, cached at `~/.candy-code/cache/` and refreshed when it is more than 24 hours old (`ModelMetadata`). The database sizes and prices models the `openai` and `custom` providers have no built-in figure for — any model behind a `custom` gateway — between your own `contextWindow` / `modelPrices` settings, which always win, and the providers' built-in fallbacks (128,000 tokens and $0 for `custom`). The refresh is the one launch-time egress: a single GET to `raw.githubusercontent.com`, run in a detached background process so it never holds the terminal (inline, bounded to 3 s connect and 10 s total, on a build without `pcntl`). Offline is safe: a failed refresh keeps the old file and waits another day, and with no file the providers use their fallbacks. Turn it on for an air-gapped host, or when you would rather not have a third party see that candy-code launched. The cache lives only under a home directory this user owns; with none, the database is off. | — |
| `CANDY_CODE_DISABLE_SYMBOL_MAP` | unset — the symbol map is on | Set to any value other than empty or `0` to keep the symbol-level repo map out of the system prompt (`Context\SymbolMapBlock`, roadmap 5.5-5): the definitions the rest of the workspace references most, ranked by PageRank and captured once per session in the parent before a turn is forked. The capture costs a `git ls-files` and a stat per code file when the ranked map is cached under `~/.candy-code/cache/repomap/`, a few seconds when it is not, and skips itself past 3,000 code files, past its 4-second tokenizing budget (the next session finds the work cached) or without memory headroom. Turn it on for a checkout where you would rather the session start not pay for it; the `RepoMap` tool still draws a map on demand. | `symbolMap.enabled` |
| `CANDY_CODE_BACKGROUND` | unset — the terminal is asked directly over OSC 11, falling back to `COLORFGBG`, and to dark when neither says anything | Forces what the `adaptive` theme believes about the terminal's background: `light` or `dark`, case-insensitive and surrounding whitespace ignored. **Any other value is ignored** rather than treated as an error, so a typo falls through to detection instead of pinning the wrong palette. This is a statement, not a measurement, so it outranks *both* detection sources — including the terminal's own OSC 11 answer, which is otherwise authoritative. That ordering is what keeps this variable useful at all: most terminals do answer, so ranking the measurement first would leave it with nothing to override. Only consulted by the `adaptive` theme — a theme picked by name (`/theme light`) does not detect anything. Also settable as `terminalBackground: light` or `dark` in any settings file (`auto`, the default, detects; a save in the settings view repaints at once); the environment variable wins. | `terminalBackground` |
| `CANDY_CODE_WORKTREES_DIR` | `WorktreeConfig`'s `basePath`, itself defaulting to `.candy-code/worktrees/` | Base directory under which the git worktrees of `isolation: worktree` runs (and teammates) are created. Replaces the configured path outright. A `~/` prefix is expanded; a relative value is the project root's; a value containing `..` is rejected rather than resolved. | — |
| `CANDY_CODE_SHARE_UPLOAD_URL` | unset — and it no longer changes anything | RETIRED upload host: the uploader never had a backend and is deleted. `/share` always writes the export locally (`~/.candy-code/exports/`, or a path inside the project); when this key names a host the reply LOUDLY refuses it and names `/share serve`, which publishes a self-hosted HTML snapshot at `/s/{token}` on your own `candy-code serve`. The key is still read precisely so that refusal can happen. There is no upload of any kind. | — |
| `CANDY_CODE_DEBUG_SKILLS` | unset — skill-load failures are silent on stderr | Set to any value other than empty or `0` to put `SkillLoader`'s per-skip and per-refused-directory lines back on stderr. Off by default because the TUI renders to stdout under an alt screen and a skill scan also runs mid-session on the Ctrl+P provider switch, so a stray stderr line lands inside a frame the renderer believes it owns; the diagnostic is not lost when it is off — every skip is readable from `SkillManager::skipped()` and the launch prints one bounded summary line. Added to this table late: it was the one variable `src/` reads that this page did not list, which made the page's own "every environment variable" claim false. Also settable as `debug.skills: true` in your own settings files, read at launch; a variable set to anything non-empty wins, `0` included. | `debug.skills` |
| `CANDY_CODE_DEBUG_COMMANDS` | unset — command-discovery refusals are silent on stderr | Set to any value other than empty or `0` to put `CommandLoader`'s eight refusal and announcement lines back on stderr: the two tier-directory refusals, the per-file containment skip, the per-file parse failure, the per-file unnamed-bundle skip, the control-plane name refusal, the refused built-in shadow, and the accepted built-in override. Off by default for two reasons rather than one. Four of the eight are paired with a collector that `Bootstrap::chat()` drains onto `warnPermissionConfigInTranscript()`, which already writes a `candy-code: `-prefixed line to stderr AND seeds a transcript row — so the raw copy was the same sentence twice on one channel. Three more are per-file and are collected on `CommandLoader::skippedFiles()`, which was added alongside this flag precisely so gating them would not be a deletion. The eighth — the accepted-override announcement — is paired since that seam closed: `Bootstrap::reportCommandOverrides()` drains it onto the launch seam as part of ONE aggregate row, so the flag no longer owns that sentence outright. Turn it on when a command file is missing from the popup: the three per-file refusals are counted on the transcript's skip aggregate and named one by one here, and the accepted overrides are counted on the transcript's override aggregate and named one by one here. For the other four it adds a raw unprefixed copy rather than a fuller one — the transcript seam clips and caps its TRANSCRIPT ROW, not its stderr line, which reaches you whole either way. Also settable as `debug.commands: true` in your own settings files, read at launch; a variable set to anything non-empty wins, `0` included. | `debug.commands` |
| `CANDY_CODE_DEBUG_RULES` | unset — rule-discovery refusals are silent on stderr | Set to any value other than empty or `0` to put `RuleLoader`'s refusal lines back on stderr: a tier directory that escapes its anchor, a rules file that escapes the directory it lives in, a file past the per-tier count cap, and a file whose frontmatter fails to parse. Off by default because the TUI renders under an alt screen where a stray stderr line lands inside a frame the renderer owns. Unlike the command tier's refusals, the rules loader's are deliberately loader-local — nothing drains them at launch, so `RuleLoader::refusedPaths()` and `RuleLoader::skippedFiles()` are readable by an embedder but are not yet surfaced on the transcript; this flag is currently the only way a refusal reaches a terminal. Turn it on when a rules file does not appear in the assembled prompt. Also settable as `debug.rules: true` in your own settings files, read at launch; a variable set to anything non-empty wins, `0` included. | `debug.rules` |
| `CANDY_CODE_MCP_DISABLE` | unset — project MCP is enabled and `.mcp.json` decides | Set to `1`, `true` or `yes` (case-insensitive) to silence project MCP entirely: `Bootstrap::mcpConfigDecision()` answers `disabled` — its own status, not the config-less `absent` — so the panel, `doctor` and `mcp list` say switched-off rather than "declares no .mcp.json", nothing launches, no server child is ever spawned, and no trust opt-in notice is recorded. Unset, `0`, empty or ANY other value keeps MCP enabled — this is the one variable whose off-switch is an exact three-word vocabulary rather than "any value other than empty or 0"; the deliberate narrowness means a launcher (or a test) that cannot scrub its inherited environment still gets the documented default-on behaviour unless one of the three words was explicitly set. `scripts/parallel-tests.sh` sets it to `1` on the shard launch: K=8 shards run at the checkout root, where an operator's own trusted `.mcp.json` used to turn any suite sibling that walks the MCP launch funnel into a spawner of arbitrary servers. The persistent switch is the `mcp.enabled` setting in your own settings files (a project's cannot set it), read at launch: `false` silences project MCP the same way. Either one turns MCP off and neither turns it back on — the variable has no "on" word, so an environment without it leaves the setting to decide. | `mcp.enabled` |
| `CANDY_CODE_REDUCED_MOTION` | unset — the agent spinner animates | Set to `1`, `true` or `yes` (case-insensitive) to pin the agent-live spinner to one static frame: `Bootstrap::chat()` reads it once at launch and calls `AgentLiveRegistry::setStaticSpinner()`, and because every surface that draws the spinner (the transcript renderer, the activity line) indexes the glyph table through that one gate, all of them go static at once. Elapsed-seconds movement still repaints — only the glyph stops advancing. Unset, `0`, empty or any other word leaves the animation on. For motion-sensitive and reduced-motion users; there is no settings key, the switch is launch-only. | — |
| `CANDY_CODE_QUIET_LOGS` | unset — `error_log()` diagnostics flow to their configured destination as they always have | Set to `1`, `true` or `yes` (case-insensitive) to silence the error-log side-channel process-wide: every diagnostic line written through the `Support\Log` façade is suppressed at its one sink, the mid-session transcript seam and every stdout channel being untouched, and unset, `0`, empty or any other word keeps the flow (the narrow law — a typo is not a decision). For CI captures and stderr-piping launches where the diagnostics are noise rather than signal; the per-family `CANDY_CODE_DEBUG_*` flags above keep their own default-off meaning either way. There is no settings key, the switch is launch-only. | — |
| `CANDY_CODE_OTEL_TIMEOUT` | `3.0` seconds | Bounded wall-clock allowance for ONE OTLP/HTTP export POST when event export is on (see [OpenTelemetry export variables](#opentelemetry-export-variables)). Fractional; non-numeric, zero and negative values fall back to the default — a nonsense bound is treated as absence, never as "wait forever". This is a per-request HTTP timeout, not a program deadline: shutdown flush runs within it and nothing kills the process for it. | — |
| `CANDY_CODE_OTEL_BATCH_SIZE` | `64` | How many events the OTLP sink buffers before an export attempt, only meaningful with event export on ([OpenTelemetry export variables](#opentelemetry-export-variables)). Non-integer values read as the default; integers are clamped into `1`–`2048`. The batch also rides the span-retry path: a failed export re-queues at most this many events, so a permanently unreachable endpoint costs memory in batches, not per event. The retry runs — a 15-second tick, declared only while a refused batch waits, re-posts it asynchronously; `OTEL_EXPORTER_OTLP_ENDPOINT` gives the full shape. | — |
| `CANDY_CODE_DEBUG_STREAM` | unset — a throwing `onToken` embedder sink is detached silently | Set to any value other than empty or `0` to keep `Chat`'s "onToken observer threw, detaching it for this turn" line on stderr. Off by default because the line fires MID-TURN — the alternate screen has been up since launch, the turn completes normally either way, and the audience is the embedder who owns the throwing sink rather than the person at the terminal. The gate covers the REPORT only, never the DETACH: whatever this flag says, a sink that throws is dropped for the remainder of the turn so one bad delta cannot strand the reply or cost the rest of the turn. Turn it on when an embedder's `onToken` callback appears to simply stop receiving chunks. Also settable as `debug.stream: true` in your own settings files; a variable set to anything non-empty wins, `0` included. | `debug.stream` |
| `CANDY_CODE_SERVER_HOST` | the `server.host` key in `~/.candy-code/config.json`, else `127.0.0.1` | The address `candy-code serve` binds; `--host` wins over it. `localhost` and `::1` count as loopback; any other address is refused unless the launch also passes `--allow-remote`, because anyone who reaches the port and holds the token can run code as you. See [SERVER.md](SERVER.md). | `server.host` |
| `CANDY_CODE_SERVER_PORT` | the `server.port` key, else `7420` | The port `candy-code serve` binds; `--port` wins over it. `0` picks a free port, which the startup lines print. | `server.port` |
| `CANDY_CODE_SERVER_ALLOWED_ORIGINS` | the `server.allowedOrigins` key, else none — only the server's own origin | Comma-separated extra browser origins (`http(s)://host[:port]`) `serve` accepts on WebSocket upgrades and state-changing requests; `--allowed-origin` wins over it. The highest source that is set replaces the others rather than merging with them. | `server.allowedOrigins` |
| `CANDY_CODE_SERVER_ALLOWED_HOSTS` | the `server.allowedHosts` key, else none — only the loopback names, the bind address and, on a wildcard `--allow-remote` bind, this machine's own interface addresses | Comma-separated extra host names `serve` answers to (`host`, any port, or `host:port`; an IPv6 address in brackets) — a reverse proxy's public name, or a DNS name for this machine; `--allowed-host` wins over it. Any other `Host` is refused with `421 host_refused`, which names the host and this fix: the allow-list is the DNS-rebinding defence, so a name must be listed rather than any name answered. The highest source that is set replaces the others rather than merging with them. See [SERVER.md](SERVER.md#remote-access). | `server.allowedHosts` |
| `CANDY_CODE_SERVER_ALLOWED_IPS` | the `server.allowedIps` key, else none — every client address may connect | Comma-separated client IPs or CIDR ranges (`1.2.3.4,10.0.0.0/8,2001:db8::/32`) `serve` admits; `--allowed-ips` wins over it. Any other client is refused `403 ip_refused` before sign-in or any credential is looked at; loopback (`127.0.0.0/8`, `::1`) is always admitted, and behind a `server.trustedProxies` proxy the forwarded client address is the one checked. A malformed entry refuses startup (exit 2). The highest source that is set replaces the others rather than merging with them. See [SERVER.md](SERVER.md#limiting-who-may-connect---allowed-ips). | `server.allowedIps` |
| `CANDY_CODE_SERVER_WEB_ROOT` | unset — the installed `sugarcraft/candy-code-web` package's build, if any | A directory `serve` serves the web UI from instead; `--web-root` wins over it. Must exist. | — |
| `CANDY_CODE_SERVER_TOKEN` | unset — the token in `<state dir>/token`, minted on first `serve` | The owner token `serve` requires of every client, used instead of the stored one (for containers). At least 32 characters; a shorter value is refused at startup. Never printed — `serve token` refuses while it is set. `serve url` sends it to the running server's control socket, so set it there too when the server was started with it. | — |
| `CANDY_CODE_SERVER_DIR` | `~/.candy-code/server` | The directory `serve` keeps its state in: the `token`, the `server.json` discovery record, the `server.lock` singleton lock, the `control.sock` control socket and a detached server's `server.log`. One server runs per directory. Created `0700`; refused if it is a symlink, someone else's, or not exactly `0700`. `serve status`, `stop`, `logs` and `url` read it without creating it. | — |
| `CANDY_CODE_SERVER_PARENT_PID` | unset — no parent is watched | A process id `serve` exits with: checked every second, by pid and start time, so a recycled pid reads as gone. `--parent-pid` wins over it. A pid that is not running at startup is refused (exit `2`). For an editor or a TUI that starts a private server. | — |
| `CANDY_CODE_SD_BASE_URL` | unset — the media client refuses every generation with a message naming this variable | Base URL of the Stable Diffusion server the media transport dials — the A1111/SD.Next `sdapi` dialect or `sglang-diffusion` — e.g. `http://127.0.0.1:7860`. Precedence: an explicit code override, then this variable, then the layered `sd.baseUrl` setting (with the provider-shaped `mediaBaseUrl` key as its sibling fallback). The media transport dials its own egress pinned to the configured origin (`Media\Sd\UrlGuard`); it deliberately does NOT reuse `WebFetch`'s SSRF blocklist — a LAN-hosted SD server is the legitimate target here (plan W0 finding 3). | `sd.baseUrl` |
| `CANDY_CODE_MEDIA_RENDER_MODE` | unset — `auto`, which asks the terminal | The value half of the `ui.imageRenderMode` setting's env layer: `auto`, `sixel`, `kitty`, `iterm2`, `halfblock`, `half`, `ansi`, `quarterblock`, `quarter`, `ascii`, `ansi256`, `truecolor`, `chafa` or `symbols`. Read once at launch by `Bootstrap::applyMediaRenderMode()`, where a non-empty value outranks the merged `ui.imageRenderMode` setting (an empty one is absence, like every `CANDY_CODE_*` reader treats it), and the resolved word is handed to `ToolResult::setRenderMode()` — which fails an unknown word soft to `auto` rather than erroring, exactly as a bad settings-file value does. `/rendermode` switches the word mid-session (see [`MEDIA.md`](MEDIA.md)); this variable still re-asserts at launch and outranks the merged setting. | `ui.imageRenderMode` |
| `CANDY_CODE_ALLOW_ROOT_TESTS` | unset — the test suite **refuses to launch** at euid 0 | The escape hatch for `tests/bootstrap.php`'s root-run refusal. Set to exactly `1` to run the candy-code test suite as root; every other value (`true`, `0`, empty included) keeps the refusal, because taking the hatch has to be deliberate. The refusal and its two halves — uid 0, and a `TMPDIR` of `/`, which stays refused even with this set — are documented on `SugarCraft\Code\Support\RootRunGuard`. The suite is not a user-facing surface: this variable changes nothing about a `candy-code` launch. | — |

Every flag-style variable above (`CANDY_CODE_DISABLE_MOUSE`,
`CANDY_CODE_DISABLE_MOUSE_CLICKS`, `CANDY_CODE_DISABLE_PARALLEL_TOOL_CALLS`,
`CANDY_CODE_DISABLE_PROMPT_CACHE`, `CANDY_CODE_DISABLE_PROMPT_SUGGESTIONS`,
`CANDY_CODE_DISABLE_MODEL_METADATA`, `CANDY_CODE_DISABLE_AUTO_MEMORY`,
`CANDY_CODE_DISABLE_SYMBOL_MAP`)
treats unset, empty **and the literal string `0`** as "not set", so
`CANDY_CODE_DISABLE_MOUSE=0` reads as "leave the mouse on" rather than as
any-value-means-true.

### The two shell-out variables

They are two **different protocols**, which is the whole reason there are two
variables rather than one. `CANDY_CODE_BACKEND_CMD` is prose: stdout *is* the
answer. `CANDY_CODE_BACKEND_CMD_STREAM` is a token stream: one token per line,
the newline between tokens is framing, and a terminated blank line is a literal
newline in the answer. Neither can stand in for the other, and nothing tries to
guess which one it was handed. Measured on this tree against a wrapper printing
`"Para one line one.\nPara one line two.\n\nPara two.\n"`:

| variable | what CandyCode received |
|---|---|
| `CANDY_CODE_BACKEND_CMD` | `"Para one line one.\nPara one line two.\n\nPara two."` |
| `CANDY_CODE_BACKEND_CMD_STREAM` | `"Para one line one.Para one line two.\nPara two."` |

**Streaming is a live screen.** The streaming backend calls its callback once
per token, at the moment that token's newline lands on the pipe, and the TUI
repaints between them. That second half used to be false and is worth recording
because it was measured both ways: the read loop was synchronous and
`completeAsync()` ran the whole of it inside one ReactPHP `futureTick`, so the
event loop was blocked for the duration and the render tick could not run until
the answer had already resolved. Measured on this tree — `completeAsync()` under
a live `Loop::run()`, a 50ms periodic timer standing in for the render tick, and
a wrapper emitting six tokens 300ms apart:

| | before | after |
|---|---|---|
| callback invocations | 6, at 0.005s / 0.304s / 0.608s / 0.907s / 1.210s / 1.514s | 6, at 0.010s / 0.309s / 0.609s / 0.914s / 1.214s / 1.515s |
| loop ticks during the stream | **0** | **36** |

The same is true of `CANDY_CODE_BACKEND_CMD`, which had the same defect in a
worse form — its promise executor ran the blocking call immediately, so the
freeze started before the promise was even returned. Both are now drained from a
periodic timer on the event loop rather than read to completion in one go. (The
one-shot `-p` path passes no callback at all and blocks deliberately.)

**A long conversation no longer hangs a shell-out wrapper.** Both variables used
to hand the whole history to the command in one blocking `fwrite()` and only
then read its output. Past the kernel's ~64K pipe buffer that deadlocks against
any command that *echoes* its input — the shape of every streaming wrapper, and
of `cat` — because the parent is blocked writing a full stdin pipe while the
child is blocked writing a full stdout pipe, and neither path has a completion
deadline that would ever end it. Measured with `cat` as the command: a 64 KB
history returned, 130 KB returned, 200 KB hung until it was killed. Both paths
now interleave the write with the reads, `-p` included, which keeps parking in
the kernel at 0% CPU (0.03% before, 0.04% after, against a wrapper that thinks
for two seconds) and returns at 200 KB, 1 MB and 8 MB.

**Absence means unset, empty *or* whitespace-only,** for both variables. One
helper (`Bootstrap::backendCommandEnv()`) defines that, and every site that
either selects the tier or labels the run asks it, so the tier a launch selects
and the tier it reports can never disagree. This matters because
`export CANDY_CODE_BACKEND_CMD='   '` previously selected the shell-out tier,
ran `sh -c '   '`, exited 0 and returned an empty assistant message while
nothing warned — a run with no model, no answer and no complaint. There is no
command a caller could mean by a string of spaces, and reading it as absence is
the only reading that leaves the next tier reachable. The value is passed on
**untrimmed**, so a command with leading whitespace still runs as written.

**Windows: `bypass_shell` is no longer passed.** Both shell-out backends call
`proc_open()` with no options array. They previously passed
`['bypass_shell' => true]` — a Windows-only option — for both command shapes,
behind a branch whose two arms were identical, so relative to earlier releases a
Windows user loses that option on both shapes. What is known: the option is
Windows-only; it was **measured inert on Linux/PHP 8.3** (with it set, the
string `"printf a; printf b"` still went through `/bin/sh -c`, and the list
`["printf", "a;b"]` still exec'd directly — byte-identical either way); and
passing the command as an **array** does not need it in the first place, because
PHP then opens the process directly, without a shell, and escapes the arguments
itself (PHP 7.4 UPGRADING: "the process will be opened directly … PHP will take
care of any necessary argument escaping"). Nothing is claimed here about what
Windows does with a **string** command — that is not something this project can
run. If you are on Windows, pass your command as a list.

---

## Deprecated aliases

Two app variables originally carried an underscore after `SUGAR`, unlike every
other variable CandyCode reads.

The whole `SUGARCRUSH_*` prefix is itself a deprecated alias <!-- @rename-keep -->
of `CANDY_CODE_*` since the 2026-10 rename: `bin/candy-code` folds both
`SUGARCRUSH_*` and `SUGAR_CRUSH_*` onto `CANDY_CODE_*` at <!-- @rename-keep -->
startup and prints one deprecation line per old variable it finds. A variable
set under the newer name always wins — **when both are set the canonical name
wins** — which is what lets you add the new export to a shared profile before
removing the old one. The two per-variable readers below additionally accept
every spelling in their own fallback list, because they can run inside a
long-lived server process started before any fold happened.

| Legacy underscore spelling | Legacy glued spelling | Current |
|----------------------------|-----------------------|---------|
| `SUGAR_CRUSH_WORKTREES_DIR` | `SUGARCRUSH_WORKTREES_DIR` | `CANDY_CODE_WORKTREES_DIR` | <!-- @rename-keep -->
| `SUGAR_CRUSH_SHARE_UPLOAD_URL` | `SUGARCRUSH_SHARE_UPLOAD_URL` | `CANDY_CODE_SHARE_UPLOAD_URL` | <!-- @rename-keep -->

---

## OpenTelemetry export variables

Read by `OtelConfig::fromEnvironment()` when the chat funnel
(`Bootstrap::chat()` — its one live caller is the TUI launch) builds its
`Chat`, and by the headless `-p` path when `NonInteractive::run()` dials
the same `Bootstrap::observabilitySink()` seam — typed spans over the
session's own event DTOs (`src/Events/*`) exported as OTLP/HTTP-JSON to a
collector. The exporter is hand-rolled on the existing Guzzle dependency:
no `open-telemetry/*` packages join a terminal tree.
Export is **dark by default**: with no endpoint below, the sink is a
`NullEventSink`, nothing is built, and no code path performs I/O.

| Variable | Default when unset | Description |
|----------|--------------------|-------------|
| `OTEL_EXPORTER_OTLP_ENDPOINT` | unset — event export is entirely off | Base URL of an OTLP/HTTP collector, e.g. `http://localhost:4318`. Presence is the ONLY switch; an empty or whitespace value counts as unset. Spans post to `<endpoint>/v1/traces`: one `chat.turn` INTERNAL span per model step, opened by the step's `StepStarted` and closed by its `UsageUpdated` (or a `SpendCapBreached`), carrying token/cost totals and tool-activity attributes. Attributes are identifiers, counters and costs — never payload: no prompts, no tool arguments, no tool output, no media frames. The per-request bound is `CANDY_CODE_OTEL_TIMEOUT`; batching is `CANDY_CODE_OTEL_BATCH_SIZE`. Batched exports post NON-BLOCKING on the event loop (`React\Http\Browser`), so a slow or unreachable collector never stalls the TUI's key-handling turn; a batch the endpoint refuses is re-queued and retried by a 15-second tick declared only while that carry-over waits. The shutdown flush stays synchronous (Guzzle, bounded by `CANDY_CODE_OTEL_TIMEOUT`) and re-posts any body the loop never answered, since at `exit()` the loop may not run again — a response lost after the collector already stored the body therefore duplicates those spans, an accepted trade for telemetry. Unpriced models export their cost as `0.0` PLUS a `candy_code.model.unpriced` attribute naming the model, so a dashboard never mistakes "not priced" for "free". Each settled safety verdict rides the turn span as a `candy_code.safety_event` event: a denied, unanswered or hook-blocked tool finish carries `decision=deny` with the denial kind and the first word of the enum's own reason prefix (`Permission`, `Hook`) — never the reason body, which can quote command text — a question posed to a human carries `decision=ask` with the tool, prompt source and permission mode, and a rejected or cancelled answer carries `decision=reject` or `decision=cancel` with its kind. Approvals and unguarded runs emit nothing, and a span with no verdicts carries no events key at all, staying byte-identical to the pre-event payload. **Wired on three paths — chat TUI, headless `-p`, and serve.** The first two go through the single `Bootstrap::observabilitySink()` seam: `Bootstrap::chat()` threads the built sink into `Chat`, and a headless `-p` run dials the same static seam to fold its `$onEvent` observer's events, so a one-shot run exports the same spans an interactive turn does and its exit-time flush posts the remaining batch. serve takes the third shape (owner ruling §6.1, C-SES-4.d): `Bootstrap::workspace()` registers an `Observability\EventSinkFactory` as a workspace service only when the endpoint is lit — a dark serve process registers nothing — and each `Host\SessionHost` lazily builds its own sink through it. The per-session shape is not decoration: the encoder keys open turn spans by step number, and hosted sessions march over identical numbers, so one shared encoder would splice one session's usage onto another's span; each session's encoder instead tags its spans with a `session.id` resource attribute while both transports stay shared. Served turns fold into the sink non-blocking (the serve loop never waits on a POST); the tail of a turn leaves at host release and at the factory's one PID-guarded shutdown flush covering every live sink. |
| `OTEL_EXPORTER_OTLP_HEADERS` | unset — no extra headers | Comma-separated `key=value` pairs added to every export POST (the OTel-conventional auth/collection-header variable, e.g. `Authorization=Bearer …`). Pairs without `=` or with an empty key are dropped; the rest are sent verbatim. |

---

## Provider credential variables

These are read by `ProviderFactory` when it builds a provider's *default*
config — that is, when you select a provider by name and do not pass an
explicit config array. Supplying the credential in a config array instead
takes priority; nothing here is consulted in that case. The `claude-code`
launch additionally forwards the `ANTHROPIC_*` trio into the environment of
the `claude` binary it spawns.

The last three rows are not provider credentials but the same kind of thing
one level over: the hosted `WebSearch` backends read theirs from
`Tools\Web\Search\*Backend` when the `webSearchBackend` setting names their
service, and each key travels in its own request header. Each is read in the
backend's constructor — when the `WebSearch` tool is built — so a change
applies from the next launch, exactly like `webSearchEndpoint` beside them.
With the variable unset or empty the backend is unconfigured: every call is
refused by a message that names the variable and echoes no value of it.

| Variable | Provider | Description |
|----------|----------|-------------|
| `OPENAI_API_KEY` | `openai`, optionally `codex` | API key. Defaults to the empty string, which the provider will reject. The `codex` arm needs it only when the operator chooses key billing over `codex login`: otherwise the subscription token lives in `~/.codex`, untouched by this process. |
| `OPENAI_ORG_ID` | `openai` | Optional organization ID. Defaults to `null`. |
| `OPENAI_BASE_URL` | `openai`, `sglang`, `custom` | Base URL for the OpenAI-shaped endpoints — the counterpart of `ANTHROPIC_BASE_URL` for that family, aimed at gateways like OpenRouter or a self-hosted server. Defaults per type: the library's own for `openai`, `http://localhost:30000` for `sglang`, `http://localhost:8080` for `custom`. |
| `ANTHROPIC_API_KEY` | `anthropic`, `claude-code` | API key, sent as `x-api-key`. |
| `ANTHROPIC_AUTH_TOKEN` | `claude-code` | Alternative bearer credential, forwarded into the `claude` binary's environment. It only matters with the provider block's `bare: false`: a default bare spawn reads no OAuth token and no user state, and requires `ANTHROPIC_API_KEY` (or `apiKeyHelper`) instead. |
| `ANTHROPIC_BASE_URL` | `anthropic`, `claude-code` | API base URL, the root without `/v1`. Defaults to `https://api.anthropic.com`. The `anthropic` type sends to `<base>/v1/messages`; a base that already ends in `/v1` is used as it is. |
| `SGLANG_API_KEY` | `sglang` | Optional key for a self-hosted OpenAI-compatible endpoint. Defaults to `null`, which is correct for an unauthenticated local server. |
| `GCP_PROJECT_ID` | `vertex` | Google Cloud project ID. Defaults to the empty string. |
| `GEMINI_API_KEY` | `gemini` | API key for the native Gemini endpoint, sent as the `x-goog-api-key` header. Defaults to the empty string, which the provider will reject. |
| `GEMINI_BASE_URL` | `gemini` | API base URL. Defaults to `https://generativelanguage.googleapis.com`; the requests go to `<base>/v1beta/models/<model>:generateContent` (and `:streamGenerateContent?alt=sse`), and a base that already ends in `/v1` or `/v1beta` is used as it is. |
| `AZURE_OPENAI_API_KEY` | `azure` | API key for the Azure OpenAI resource, sent as the `api-key` header (never `Authorization`). Defaults to the empty string, which the provider will reject. The block still needs a `resource` or `baseUrl` to name the host. |
| `OPENROUTER_API_KEY` | `openrouter` | API key for OpenRouter, sent as a bearer token on the OpenAI-compatible wire. Defaults to the empty string, which the provider will reject. |
| `BRAVE_SEARCH_API_KEY` | `brave` (WebSearch backend) | API key for the Brave Search endpoint, sent as the `X-Subscription-Token` header. Read when the `WebSearch` tool is built, like `webSearchEndpoint` beside it, and only while `webSearchBackend` is `brave`; an empty value counts as unset. Without it every call is refused with a message naming this variable (and pointing back to `searxng`), which never echoes any key value. |
| `TAVILY_API_KEY` | `tavily` (WebSearch backend) | API key for the Tavily endpoint, sent as a bearer token in the `Authorization` header. Read at the same points as `BRAVE_SEARCH_API_KEY` while `webSearchBackend` is `tavily`; unset counts as unconfigured and refuses every call by this name. |
| `EXA_API_KEY` | `exa` (WebSearch backend) | API key for the Exa endpoint, sent as the `x-api-key` header. Read at the same points as `BRAVE_SEARCH_API_KEY` while `webSearchBackend` is `exa`; unset counts as unconfigured and refuses every call by this name. |

`bedrock` reads two variables of its own, both naming the region; credentials
stay the AWS SDK's ambient chain (`AWS_*` variables, `~/.aws/credentials`,
instance roles), resolved by `aws/aws-sdk-php` rather than by CandyCode.

| Variable | Provider | Description |
|----------|----------|-------------|
| `AWS_REGION` | `bedrock` | Region the request is signed against, consulted first. |
| `AWS_DEFAULT_REGION` | `bedrock` | Region fallback when `AWS_REGION` is unset; with neither set, the provider signs against its built-in `us-east-1`. |

`CUSTOM_PROVIDER_API_KEY` is the *default* variable name for
`CustomProvider::openAiCompatibleFromEnv()`, but that is a caller-supplied
parameter — pass a different name and that variable is read instead. It is
not consulted by the `custom` provider type's default config.

---

## Claude Code variables

Read by `/memory import claude` (`ForeignMemoryImporter`) so the import looks
where Claude Code itself keeps a project's memory. They are Claude Code's own
names and follow Claude Code's rules:

| Variable | Default when unset | Description |
|----------|--------------------|-------------|
| `CLAUDE_CONFIG_DIR` | `~/.claude` | Claude Code's relocated config directory; the import reads `<dir>/projects/<slug>/memory/` instead of `~/.claude/projects/<slug>/memory/`. Blank counts as unset. A **relative** value is refused (it would resolve inside the checkout the session runs in, a tree the repository chooses), and so is a directory that is world-writable or owned by another account — the same gate the derived `~/.claude` faces; the refusal is named in the command's reply and nothing is imported. |
| `CLAUDE_CODE_PROJECT_DIR_NAME` | the project slug (the absolute path with every non-alphanumeric character turned into `-`) | Replaces the slug outright — `<config dir>/projects/<name>/memory/`, with no fallback to the slug directory. As in Claude Code, it is honoured **only while `CLAUDE_CONFIG_DIR` is set**, and only for a name of 1–64 letters, digits, `_` and `-` that is not a reserved Windows device name (`CON`, `PRN`, `AUX`, `NUL`, `COM0`–`COM9`, `LPT0`–`LPT9`); otherwise it is ignored and the slug applies, which is where Claude Code wrote. |

---

## Variables read from any config file

`ProviderFactory` expands `${VAR}` and `${VAR:-default}` placeholders in
every string value of a provider block, so **any** environment variable can be
referenced from one. A `providers` **definition** is honoured from exactly two
places: the map in your own `~/.candy-code/config.json`, and the
`.candy-code/config.dev.json` that ships **inside the candy-code package**,
next to `src/` (the repo's copy declares `dev-sglang`). A `providers` map you
write in a project `.candy-code/config.json` is **ignored** — provider blocks
decide where your prompts and credentials are sent, so they are user-tier only
and a checked-out repository must never move that endpoint (see the never-from-
project list in `README.md`; project-side names still reach selection through the
shipped `config.dev.json` fixture, definitions never). Within the honoured two,
your own block defines a named provider the same way the shipped one does, and a
map of the same name in your file replaces the shipped entry — whole-map
replace, the same doctrine `models` and `modelPrices` live by. A block looks
like this:

```json
{
  "providers": {
    "my-endpoint": {
      "type": "sglang",
      "baseUrl": "${MY_SGLANG_URL:-http://localhost:30000}",
      "apiKey": "${MY_SGLANG_KEY}",
      "toolCallParser": "${CANDY_CODE_TOOL_CALL_PARSER}"
    }
  }
}
```

An unset variable with no `:-default` resolves to the empty string.

A value can also spell `${keychain:<name>}`: CandyCode then reads that entry
from the operating system's own secret store — `secret-tool lookup` on
freedesktop Linux, `security find-generic-password` on macOS — under the
service name `candy-code`, once per launch per name. Only the entry name
crosses to the helper in its argv; the secret rides the helper's stdout and
so stays out of the process listing the name is visible in. Any miss — the
entry does not exist, the helper is not installed, it does not answer within
five seconds, or the platform has no backend here at all (Windows among them)
— resolves to the empty string, the same shape an unset `${VAR}` takes, and
the reason is raised as a launch notice naming the entry rather than as a
startup failure.

Beyond the map, `OPENAI_BASE_URL` redirects the endpoint for the OpenAI-shaped
built-in types (`openai`, `sglang`, `custom`) without declaring a block at all —
the counterpart of `ANTHROPIC_BASE_URL` for that family, aimed at gateways like
OpenRouter or a self-hosted server.

`CANDY_CODE_TOOL_CALL_PARSER` is worth calling out because it appears in
discussions of CandyCode's environment surface but is **not** read by any
direct `getenv()` call. It works only through the placeholder mechanism above,
and only if you write that placeholder into one of your provider blocks
yourself — the shipped copy does not contain it. Its three valid values are `openai`,
`minimax-xml-fallback` and `dsml`; an unset variable resolves to the empty
string, which is treated as "key absent" rather than as a typo.

"Key absent" is not the same as `openai`. With no value the parser is derived
from the configured model — `dsml` for the DeepSeek-V4 family, `openai` for
everything else — so leaving the placeholder unset is the recommended state,
not a fallback to the OpenAI-only strategy.

---

## Terminal colour variables

Ambient color variables consulted via the foundation palette — read by
sugar-core's `ColorProfile::detect()` (called from `Renderer::applyAmbientColorProfile()`
once per launch), not from `candy-code/src/`, so they name no `CANDY_CODE_*`
prefix and the `EnvRosterDrift` scanner cannot see them. They gate the colour
depth of **every** `Style` the process mints afterwards:

| Variable | Used for |
|----------|----------|
| `NO_COLOR` | Colour off, decoration kept. When truthy the profile becomes `Ascii` — bold, faint and italic still ship, `38;2;…` sweeps never do — per [no-color.org](https://no-color.org). It outranks the two force variables below. Truthy means non-empty, not `0`, not `false`. |
| `FORCE_COLOR` | The escape-anyways switch. When truthy the ladder returns `TrueColor`, and a non-tty or `TERM=dumb` destination is treated as a terminal rather than silenced. |
| `CLICOLOR_FORCE` | The libcli-family spelling of the same switch as `FORCE_COLOR`; either one truthy forces the same way. |

Plain `CLICOLOR` (the libcli "disable colour without unsetting it" switch, no
`_FORCE`) is deliberately **not** consulted: this ladder reads only the three
variables above for colour decisions. `TERM`, `TERM_PROGRAM`, `COLORTERM`,
`WT_SESSION` and the CI-family variables ride the same ladder as capability
hints, but `TERM` and `TERM_PROGRAM` keep their own rows in the
[OS variables](#os-variables) table, where their multiplexer and dumb-terminal
meanings are described.

## OS variables

Read for their standard meanings, not as CandyCode settings:

| Variable | Used for |
|----------|----------|
| `HOME` (`USERPROFILE` on Windows) | Locating `~/.candy-code/`, expanding `~` in `@import` paths, and the environment handed to forked git commands. Falls back to `/tmp`. |
| `PATH` | Resolving MCP server executables and the git binary. |
| `VISUAL`, `EDITOR` | The editor `/editor` opens to compose a prompt — `VISUAL` first, then `EDITOR`, then `vi` (`notepad` on Windows). Run through the shell, so a value with arguments (`code --wait`) works. |
| `TMUX`, `TERM_PROGRAM` | Multiplexer detection (tmux vs iTerm2) for split-pane support. |
| `TERM` | Terminal capability hints. On a tty, a value of `dumb` earns a launch notice pointing at the one-shot paths, which render no interface a dumb terminal can mishandle. `TERM` and `TERM_PROGRAM` are also searched together for `kitty`, `ghostty` or `wezterm` — a name in either value is the flag that makes CandyCode try the kitty keyboard protocol negotiation, and it is the same probe that answers the macOS Option-as-Meta hint (see the row below for how the answer survives a multiplexer). |
| `KITTY_WINDOW_ID`, `GHOSTTY_RESOURCES_DIR`, `WEZTERM_PANE` | The multiplexer-transparent identity of a kitty-speaking terminal. Inside tmux or screen, `TERM` is rewritten to the `screen`/`tmux` family, so the Option-as-Meta probe also reads these — kitty exports `KITTY_WINDOW_ID`, ghostty `GHOSTTY_RESOURCES_DIR`, wezterm `WEZTERM_PANE`, and each survives the mux as a real environment value. A non-empty one means the terminal behind the mux negotiated (or was asked to) the kitty keyboard protocol, and the macOS hint stays silent. The hint itself appears once per candy-code version — the version it last announced for is stamped in `~/.candy-code/cache/mac-alt-notice`, and an upgrade re-arms it. |
| `COLORFGBG` | Background detection for the `adaptive` theme — the *last* resort, consulted when `CANDY_CODE_BACKGROUND` has not settled it **and** the terminal has not answered the OSC 11 background query the TUI sends at startup (it has not answered *yet* during the first frames after launch; it never will on a terminal that does not implement the query, over a pipe, or on the one-shot `-p`/`run` path, which starts no TUI and so never asks). The **last** `;`-separated field is the background — xterm/rxvt emit a three-field `fg;faint;bg` form as well as the usual two — and it is read as an xterm-256 palette index put through a luminance test, not matched against a list of "light" indices. Anything that is not a palette index at all (the literal `default`, an empty or malformed value, a number above 255) reads as dark. |
| `LANG`, `LC_ALL`, `LC_MESSAGES` | The launch locale. `Lang::useLaunchLocale()` (called by `bin/candy-code` before anything is printed) reads them in that precedence — `LC_ALL` first, then `LC_MESSAGES`, then `LANG` — takes the first non-empty answer, strips the encoding/modifier suffix (`fr_FR.UTF-8@euro` → `fr-fr`), and loads the matching `lang/<tag>.php` translation. A value of `C` or `POSIX` (encoding included, as in `C.UTF-8`) means the untranslated messages, English here; so does no variable at all, or a value that is not a language tag. |
| `DISPLAY`, `WAYLAND_DISPLAY` | Clipboard backend selection. When `WAYLAND_DISPLAY` names a compositor the image/text clipboard prefers `wl-copy`/`wl-paste`; when `DISPLAY` names an X server it prefers `xclip`/`xsel` (macOS takes `pbcopy`/`pngpaste` before either is consulted). On a headless box neither is set, so the host-tool branch silently does not exist and OSC 52 is the only copy path — worth knowing before `Ctrl+V` appears to do nothing in a container. |
| `CDPATH` | Permission defense, read by `Permissions\LeadingCd::landsInside()`. A relative path argument can land outside the project through a shell that changes directory via `CDPATH`, so when `CDPATH` is set and non-empty the checker refuses to vouch for a non-explicit relative path at all. Unsetting it is not required — the check degrades to conservative refusal, not to a hole. |
| `TMPDIR` | Sandbox temp normalisation. `Tools\Sandbox\Bubblewrap` reads it to decide what to bind or recreate inside the jail (a `$TMPDIR` under `/tmp` rides the private tmpfs; elsewhere it is bound writable when it exists). Note `sys_get_temp_dir()` does **not** consult a `putenv`'d `TMPDIR` — only the process-environment value seen at startup does — so a test that fiddles with it in-process is not moving the sandbox's view. |

## Build-time image arguments

`CANDY_CODE_REF` is the exception this page's opening sentence cannot cover: it
is a name the **image build** consumes and the running binary never reads. The
`Dockerfile` declares it as `ARG CANDY_CODE_REF=local` and echoes it inside the
`RUN` that installs the app, which ties that layer's cache key to a per-run
value so a CI build can never ship a cached feed snapshot frozen behind an
otherwise-constant instruction (see [`DOCKER.md`](DOCKER.md)). The reference the
stream actually resolved is recorded into `/opt/candy-code/BUILD-REF` inside the
image. Setting it in a runtime environment does nothing at all, so it earns no
row in the app table above — that table is oracle-enforced to carry exactly the
variables `src/` reads, and this page's prose is the sanctioned place to roster
the build-time name.

## See also

- [`SETTINGS.md`](SETTINGS.md) — the settings files these variables outrank,
  and which settings keys have an environment override.
- [`PERMISSIONS.md`](PERMISSIONS.md) — `CANDY_CODE_PERMISSION_MODE` and the
  modes it can name.
- [`SERVER.md`](SERVER.md) — the `CANDY_CODE_SERVER_*` variables.
- [`TROUBLESHOOTING.md`](TROUBLESHOOTING.md) — what a bad value does at launch.
- The [README](../README.md#documentation-index) — every other page.
