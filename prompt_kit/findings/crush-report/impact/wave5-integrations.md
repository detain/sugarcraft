# Impact: wave5-integrations

Scope: roadmap 5.9–5.14 (ACP, model-family prompts, smart-approve, bwrap sandbox, model metadata + fallback, small UX split into 5.14a–l), Part IX 15b-14 (i18n), and the three LIVE checks. Checked against master `574e4cccb`. Paths are relative to `sugar-crush/`. Line ranges are method extents, measured with `token_get_all`. `Chat.php` has 19,220 lines.

## Summary table

| ID | Status | Size | Depends on | Hotspot regions (file::method :lines) | Other files modified | New files (proposed, incl. tests) | Docs + drift tests forced | Cross-lib |
|---|---|---|---|---|---|---|---|---|
| 5.9-1 ACP MVP | OPEN | M | — (see note) | `Cli/Bootstrap::withConsolePermissionPrompt` :3289-3298 (pattern for the approver, which needs no edit); `EngineBackend::withPermissionApprover` :746 (read-only use) | `Cli/ParsedArgs::SUBCOMMANDS` :55; `Cli/Subcommands::dispatch` :75-92, `SUBCOMMAND_DESCRIPTIONS` :1155, completion; `Cli/Help::screen` :36; `McpMessage` (int ids are coerced to string at `parse()` :78, which needs an id-preserving variant) | `src/Acp/{AcpServer,AcpSession,AcpPermissionBridge,AcpUpdateMapper,StopReasonMap}.php`, `src/Cli/Acp.php`, `tests/Acp/{AcpHandshakeTest,AcpPromptStreamTest,AcpPermissionBridgeTest}.php` | README "### Subcommands" :431 fence (`ReadmeRosterDriftTest::testTheSubcommandFenceNamesEveryVerbTheParserAccepts`); `tests/Cli/HelpTest.php`; new docs section "ACP" in `docs/SERVER.md` if O-3 has created it, otherwise in `docs/ARCHITECTURE.md` | — |
| 5.9-2 ACP on SessionHub | OPEN | M | 5.9-1, O-2g; O-3b only for `--connect` | none in Chat (it rides the `Host/SessionHub` API) | `src/Acp/*`; `ToolResult` (`diff` :116 already exists, so oldText/newText are optional) | `tests/Acp/{AcpCancelTest,AcpSessionLoadTest}.php` | same docs section (cancel, `session/load`, diffs) | — |
| 5.10 | OPEN | S–M | after 1.A (snapshot tests); 0.3 is disjoint (Bash guidance) | `Runtime::basePrompt` :4239-4360 (make it family-aware **inside slot 1**); `Runtime::systemPromptSections` :3457-3796 (pass the model and family in; no new slot) | `Context/Sections/MaximsSection::BODY` :82-104 (Execution Bias and Promised Work maxims); `Providers/SglangProvider::modelFamily` :807-819 (reuse public `isDeepSeekV4` :2036 and `isQwen3Next` :2055, add MiniMax) | `src/Providers/ModelFamily.php` (enum), `src/Context/Sections/FamilyPrompt.php` (per-family text, including lazy/overeager), `tests/Context/ModelFamilyPromptTest.php` | `tests/BaseSystemPromptTest.php`, `tests/Context/Sections/MaximsSectionTest.php` (voice scan), `tests/Integration/SystemPromptWiringTest.php`, the 1.A prompt-snapshot goldens; `docs/PROMPT_ENGINEERING.md` "## The eleven slots" :13 (changes only if a 12th slot is added, which would also force `ArchitectureAssemblyOrderTest` + ARCHITECTURE.md "assembly order") | — |
| 5.11-1 MCP readOnlyHint | OPEN | S | — | `PermissionGate::evaluateAuto` :461-501 (an `mcp__*` call with `readOnlyHint` from a trusted server → Allow), `::isReadOnlyTool` :920-923 (plan mode) | `MCP/McpTool` ctor :44-49 + `fromArray` :51 (`annotations`); `Tools/McpToolBridge` (expose the hint) | `tests/Permissions/McpReadOnlyHintTest.php` | `docs/PERMISSIONS.md` "### What `auto` classifies" :246; README MCP bullet :1311 ("agree in five and diverge under `plan`" becomes false); `docs/MCP.md` | — |
| 5.11-2 LLM exec reviewer | PARTIAL (the deterministic `SafetyClassifier` and the 3-strike/20-total breaker exist, `PermissionGate` :91-92; there is no LLM reviewer) | M | 1.C (Ask must be answerable in the TUI); N-P0 (schema row) | `PermissionGate::__construct` :102-123 (`?ExecReviewer`), `::evaluateAuto` :461-501 (category ≠ null → reviewer verdict; security category → force Ask, not Deny) | `Cli/Bootstrap::permissionGate` :5740-5806 (inject reviewer on `titleBackend()` :8850); `Config/LayeredSettings::LAYERED_KEYS` :410 (`autoReview`, user-tier only: it costs money) | `src/Permissions/ExecReviewer.php`, `src/Permissions/ReviewVerdict.php`, `tests/Permissions/ExecReviewerTest.php` | `docs/PERMISSIONS.md` :246 + "### `auto`'s circuit breaker" :269; `docs/SETTINGS.md` key table :138 + count :196; README layered roster :219 ("twenty-one", pinned by `DocFigureProseDriftTest`) | — |
| 5.12 | OPEN (no sandbox code; `/usr/bin/bwrap` exists on this host) | M | 0.4 (same `Bash::execute` + `runCaptured`) | none in hotspots | `Tools/BuiltIn/Bash::execute` :193-252 (wrap `$cmd` in a `bwrap` argv prefix: ro-bind `/`, rw-bind root or worktree, `--unshare-net` optional); `Tools/Concerns/CapturesProcessOutput::runCaptured` :154 + `runCapturedInteractive` :387 (Pty path); `LayeredSettings` (`bashSandbox`, user-tier) | `src/Tools/Sandbox/Bubblewrap.php`, `tests/Tools/BashSandboxTest.php` (skips without bwrap, so add it to `tests/Support/SuiteSkipRoster`) | `docs/PERMISSIONS.md` new "## Sandbox" section; SETTINGS.md :138/:196; README :219; ENVIRONMENT.md only if an env override is added (`EnvRosterDriftTest`) | — (no new `proc_open` site, so `check-child-lifetimes` is unaffected) |
| 5.13a model metadata DB | OPEN (`CustomProvider::contextWindow` :237-240 is a fixed `128_000`; `costPer1kTokens` :242-245 returns `0.0`, not null; `createCustom` :1289-1306 takes no `contextWindow` setting) | M | N-P0 (schema rows) | — | `Providers/CustomProvider` :237-245; `ProviderFactory::createCustom` :1289-1306 and `createOpenAI` (:645 override precedence: setting > DB > table); `OpenAIProvider` `PRICE_TABLE` fallback | `src/Providers/ModelMetadata.php` (litellm `model_prices_and_context_window.json`, cache `~/.sugar-crush/cache/`, 24 h TTL, offline-safe), `tests/Providers/ModelMetadataTest.php` | ENVIRONMENT.md "## App variables" :27 (opt-out or URL env var; `EnvRosterDriftTest`); SETTINGS.md `contextWindow` row :190 + prose :319 ("sizes the `openai` provider" becomes wider); README "## Providers" :1149 | — |
| 5.13b FallbackProvider | OPEN | M | 2.7 (shared failure classification); N-P3b (per-model `/model`) | — | `ProviderFactory::create` :113-160 (wrap when a provider block has `fallbackModels`; the turn child rebuilds providers from the same config array, `Bootstrap` ~:2807-2840, so it is inherited) | `src/Providers/FallbackProvider.php` (switch only before the first token, on `TransientFailure`/`ProviderException`), `tests/Providers/FallbackProviderTest.php` | SETTINGS.md (provider-block key) + README "## Providers"; `docs/ARCHITECTURE.md` provider prose | — |
| 5.14a bell / OSC 9 | OPEN (no bell or OSC 9 anywhere; `Cmd::raw` exists in candy-core `Cmd.php` :147) | S | 1.C for the approval-wait half | `Chat::route` :2082-3121 (AssistantMsg settle arm at the top, :2084); `Chat::requestPermission` :3242-3546 | `LayeredSettings` (`notify: off\|bell\|osc9`) | `tests/Chat/TurnEndNotificationTest.php` | SETTINGS.md :138/:196; README :219 | — |
| 5.14b `/btw` | OPEN | S–M | 1.B (`uiOnly` row) or an overlay | `Chat::submit` :8764-9152 (let `/btw` past the in-flight refusal ~:8787-8800); `Chat::dispatchCommand` :10337-10430; template `Chat::schedulePromptSuggestion` :11274-11315 (side query on `titleBackend`) | `Commands/CommandRegistry::all`; `Chat::READ_ONLY_COMMANDS` :9378 | `tests/Chat/BtwCommandTest.php` | README "### Slash commands" :895 (`ReadmeRosterDriftTest::testTheSlashCommandRosterIsExactlyWhatTheRegistryAdvertises`); `docs/COMMANDS.md` "## The built-in commands" :274 (`CommandsTableTakesColumnDriftTest`); `SlashDispatchTest` | — |
| 5.14c `/handoff` | OPEN | M | 2.4/2.5 (summary template); P-A sessions | `Chat::dispatchCommand`; `Chat::buildSummarizationRequest` :13050-13123 (reuse); `Chat::handlePaletteNewSession` :16955-16983 (seed the new session) | `CommandRegistry::all` | `tests/Chat/HandoffCommandTest.php` | same 3 slash-command pins | — |
| 5.14d `/newrule` | OPEN (`/rules` lists and toggles only) | S | 0.8 (the agent may not write `.sugar-crush/rules/`; the parent writes after the user confirms) | `Chat::dispatchCommand`; `Chat::handleRulesCommand` :12351-12366 neighbour | `Commands/RulesCommand` (a `new` verb, or a separate command) | `tests/Commands/NewRuleCommandTest.php` | slash pins; `docs/COMMANDS.md` "## The `/rules` command" :420 | — |
| 5.14e `/init` | OPEN | S | — | `Chat::dispatchCommand` (a canned prompt dispatched as a turn, the same path as `expandCustomCommand` :10087-10110) | `CommandRegistry::all` | `tests/Chat/InitCommandTest.php` | slash pins; `docs/MEMORY.md` "## Instruction files" :340 | — |
| 5.14f `/share` local export | OPEN (`ShareUploader::upload` always throws, Part II #35) | S | — | `Chat::handleShareCommand` :12141-12159, `::shareResponse` :12188 | `Commands/ShareCommand` (write a file instead of uploading); `Share/ShareSession` (+`html`); `Util/Exporter` (+`toHtml`); `Share/ShareUploader` (keep as an opt-in remote, or mark dormant) | `tests/Commands/ShareCommandTest.php` (rewrite: 9 tests assert "not implemented") | ENVIRONMENT.md `SUGARCRUSH_SHARE_UPLOAD_URL` row :53 + alias :155 (`EnvRosterDriftTest`); COMMANDS.md row :290 (Takes `[format] [path]`); `CommandRegistry` description + `argumentHint` | — |
| 5.14g `!cmd` | OPEN (no `!` prefix handling in `submit`) | S–M | — | `Chat::submit` :8764-9152 (branch after `readOnlyRefusal` ~:8808, before custom-command expansion); output appended as a user-context row | — (run with `Cmd::exec(captureOutput: true)` or the `Bash` tool class under the cd guard) | `tests/Chat/BangShellTest.php` | README "## Using the TUI" :740 (new subsection); `docs/PERMISSIONS.md` (the user's own command is not gated: say why) | — |
| 5.14h `/editor` | OPEN | S | — | `Chat::dispatchCommand`; a reply handler that loads the temp file into `inputBuf` | `CommandRegistry::all`; `READ_ONLY_COMMANDS` | `tests/Chat/EditorCommandTest.php` | slash pins; ENVIRONMENT.md "## OS variables" :240 (`EDITOR`/`VISUAL`; `EnvRosterDriftTest` pins only `SUGARCRUSH_*`, so this is a doc edit only). No key binding: Ctrl+G is taken by `shell.group-input` | candy-core `Cmd::exec` :193 (use only) |
| 5.14i watch-files `AI!` | OPEN | M | — (1.A turn context is the clean injection point) | `Chat::subscriptions` :17159-17286 (poll tick) | `LayeredSettings` (`watchFiles`, off by default) | `src/Support/AiCommentWatcher.php`, `tests/Support/AiCommentWatcherTest.php` | SETTINGS.md :138/:196; README :219 | — |
| 5.14j personal + alias instruction files | OPEN (`loadRoot` reads only `<root>/CLAUDE.md` and `AGENTS.md`, :291-294; the ancestor walk stops at `$HOME`; `instructions` skips matches outside the repo) | S–M | — | `Runtime::planInstructionDocuments` :3918-3977 (user doc first, under the same budget) | `Context/InstructionFileLoader::loadRoot` :277-379, the ancestor filename list :669, `loadForPath` :856 (aliases `.cursorrules`, `GEMINI.md`, `.clinerules`) | `tests/Context/PersonalInstructionFileTest.php`, `InstructionAliasTest.php` | `docs/MEMORY.md` "## Instruction files" :340 + "## On-disk layout summary" :408; README Capabilities prose | — |
| 5.14k skill `requires` gating | OPEN | S | — | — | `Skills/SkillFrontmatter::fromParsed`; `Skills/Skill` ctor :16-34 + `parse` :69-121; `Skills/SkillRegistry` (filter, with a skip reason in `SkillManager::skipped()`) | `tests/Skills/SkillRequiresGatingTest.php` | `docs/SKILLS.md` "## Frontmatter" :125 + "## Diagnostics" :467 | — |
| 5.14l `$skill` per-turn | OPEN (Ctrl+S `App::handleSelectSkill` enables a skill for the whole session; nothing does it for one turn) | S–M | — | `Chat::userTurnMessage` :9171-9186 (resolve `$name` beside `@`), `Chat::mentionTokenAtCaret` :16320-16333 / `completeMention` :16342-16360 (if `$` completion is added) | `Skills/SkillRegistry` (lookup with the user-invocable filter) | `src/Skills/SkillMentions.php`, `tests/Skills/SkillMentionTest.php` | `docs/SKILLS.md` "## Invoking a skill" :415; a new completion binding forces `KeyBindingRegistry` + README "### Keys" :746 (`KeyBindingDriftTest`) | — |
| 15b-14-1 i18n infra | OPEN (no `lang/`) | S | **all roadmap steps** (deferred by decision) | — | `composer.json` (`autoload` already covers `src/`) | `src/Lang.php` (copy of `candy-pty/src/Lang.php`, `NAMESPACE='crush'`), `lang/en.php`, `tests/LangParityTest.php` (copy `candy-palette/tests/LangParityTest.php`) | root `LOCALES.md` | candy-core `I18n/{Lang,T}` (use only) |
| 15b-14-2 CLI strings | OPEN | M | 15b-14-1 | `Cli/Bootstrap` launch notices | `Cli/{Help,Subcommands,ArgvParser,NonInteractive}` (~150 strings) | — | `HelpTest`, README Subcommands fence: tests must run under `en` | — |
| 15b-14-3 registry strings | OPEN | S–M | 15b-14-1 | — | `Commands/{CommandRegistry,KeyBindingRegistry}` descriptions (~110) | — | `ReadmeRosterDriftTest`, `KeyBindingDriftTest`, `CommandsTableTakesColumnDriftTest` compare against English and must pin `en` | — |
| 15b-14-4 TUI strings | OPEN | L | 15b-14-1 | `Chat` (whole file, ~110 multi-word literals plus interpolations), `Renderer`, `App/App` | `Tui/*`, `Palette/*` | — | `NoRawAnsiInTranscriptTest`, golden ANSI snapshots | — |

## Per-step notes

**5.9.** Status: **disagree with server-web.md's dependency.** ACP needs no Host extraction for an MVP. The headless path already runs `Backend::complete($history, $onToken, $onEvent)` synchronously (`NonInteractive::run` :165, :285), and `EngineBackend::withPermissionApprover` takes a blocking `\Closure(ToolCall, HookResult): bool`. `HeadlessPermissionPrompt` is the model for that closure. So 5.9-1 maps:
- `session/prompt` → `complete()`;
- `onToken` → `session/update` `agent_message_chunk`;
- `onEvent` → `tool_call`/`tool_call_update`;
- approver → `session/request_permission`. The approver blocks on stdin, so other messages that arrive meanwhile must be buffered.

The MVP cannot `session/cancel` mid-step because `complete()` takes no CancellationToken; it acknowledges the cancel and stops at the next step. That gap is why 5.9-2 moves ACP onto O-2g's `SessionHub`.

Risks:
- **id typing:** `McpMessage::parse()` stringifies integer ids, while ACP clients send integer ids. Add an id-preserving variant rather than changing MCP behaviour.
- **stdout discipline:** every stray `echo` or stderr notice breaks framing. Reuse `NonInteractive`'s stdout guards.

Keep server-web's naming: retire O-8b and use 5.9.

**5.10.** Keep the slot count at eleven. Branch inside `basePrompt()` on `ModelFamily`, and add the two maxims to `MaximsSection::BODY`. A new slot would force ARCHITECTURE.md, PROMPT_ENGINEERING.md "eleven" and `ArchitectureAssemblyOrderTest`.

The family must be the *served* model (`ReportsServedModel::servedModel()`) so that SGLang auto-discovery picks the right text. It must stay byte-stable per session (Static stability), or it re-breaks 1.A's cache prefix.

Land after 1.A, so the prompt-snapshot goldens are refreshed once.

**5.11.** Split:
- **5.11-1 (`readOnlyHint`)** is independent. The hint is server-asserted, so honour it only for servers in `trustedProjectMcp` or user-tier servers.
- **5.11-2 (reviewer):**
  - Today a classified dangerous call is **Deny** in auto (`evaluateAuto` :500) and only becomes Ask after 3 strikes. "Security findings force Ask" is therefore a policy flip, and it is only safe after 1.C: in the TUI, an Ask is still a refusal.
  - The reviewer runs in the turn child, as a blocking call on `titleBackend`. Give it a hard connect timeout, but per the memory rule set no total-request cap; fail to Ask if it errors.
  - Wrap the transcript snippet in `PromptFence`, because it is untrusted.

**5.12.**
- Use a bwrap prefix on the existing `bash -c` string. A user-tier setting enables it on Linux only, and launch fails loud if bwrap is missing (dsh rule).
- The interactive Pty branch needs the same prefix.
- Interplay with `withWorktreeRoot()`: rw-bind the jail root, not the project root.
- Rebase on 0.4 (timeout, setsid group kill), which edits the same two methods.

**5.13.**
- **a.** The fetch is egress at launch. It must be cached, best-effort and never block the TUI. An env opt-out is needed for air-gapped hosts. Precedence: user `contextWindow`/`modelPrices` > DB > built-in tables.
- **b.** Fall back only before the first streamed token; after it, 2.7's "continue" path owns recovery. Report the switch as a notice, so it is not silent (Part II #37).

**5.14.** Each item is S apart from c and i (M). They are disjoint except for `dispatchCommand` (b, c, d, e, h) and `submit` (b, g). Land them as two bundles: commands {b, c, d, e, h, f} and input/prompt {g, j, k, l}, with a and i separate.

**15b-14.**
- Estimate: about 1,000 unique multi-word literals in the UI layers (Chat, Renderer, App, Tui, Commands, Cli, Palette). There are about 2,600 across all of `src/`, but tool descriptions, prompt text and tool errors are model-facing and must stay English.
- Target catalogue: roughly 700–900 keys.
- The drift guards compare docs to English registry text, so every guard must force `en`.

**LIVE checks** (no network calls made here):

| Check | Script / command | Pass condition | Credentials on this host |
|---|---|---|---|
| LIVE-A15 Bedrock | new `scripts/provider-cache-live-probe.php --provider=bedrock`, modelled on `scripts/qwen-live-smoke.php`: `ProviderFactory::create(defaultConfig('bedrock'))` with `promptCache` on; send the same request twice with a system prompt of at least 1,024 tokens | call 1 `Usage::$cacheCreationTokens > 0`; call 2 `$cacheReadTokens > 0` | `~/.aws/credentials` has a `[default]` profile. Bedrock model access for `us.anthropic.claude-sonnet-4-6` is unverified. No `AWS_*` env vars are set. |
| LIVE-A15 Vertex | same script with `--provider=vertex` and a **current** Claude model. The default `claude-3-sonnet@20240229` (`ProviderFactory` :453/:1273) is outdated and should be overridden. | same as above | **none**: no `GCP_PROJECT_ID`, no `GOOGLE_APPLICATION_CREDENTIALS`, no gcloud config, no metadata server |
| LIVE-A21b | same script with `--provider=vertex --model=gemini-2.5-flash --thinking-budget=1024` and a small `maxTokens` | the request is accepted (no 400 on `thinkingConfig`); `reasoningTokens` is reported; the reply text is non-empty and not truncated by the thinking spend | **none** (GCP) |
| LIVE-CH | same script with `--cache-health` and `promptCache: false` (or a prompt under the cache minimum): three consecutive requests, each `Usage` fed to `new CacheHealthWatch()->observe()` | both buckets are reported as **0, not null**, and the notice fires **once**, on the 3rd reply. The report says "one reply", but `observeCacheHealth` needs 3 consecutive replies. | Bedrock maybe (as above); Vertex none |

## Stale claims

| Claim | Evidence |
|---|---|
| 5.9 ACP "depends on O-2 Host extraction" (server-web) | Not needed for an MVP: the synchronous `Backend::complete` + `withPermissionApprover` path already serves `-p` (`HeadlessPermissionPrompt`). Only cancel and multi-session need O-2g. |
| 5.11 "escalate after 3 denials" as new work | Already there: `STRIKE_THRESHOLD = 3`, `TOTAL_BLOCK_THRESHOLD = 20` → Ask (`PermissionGate` :91-92, :492-497). Only the LLM verdict is new. |
| 5.10 "per-model-family" needs family detection | `SglangProvider::isDeepSeekV4` / `isQwen3Next` are already `public static` (:2036/:2055); only MiniMax is missing. |
| Part II #35 "WebSearch has no default endpoint" listed as a defect | That is now deliberate (`WebSearch.php` :52 "THERE IS NO DEFAULT ENDPOINT", audit F-W3(b)). Only the `/share` stub and the null LSP client remain from #35. |
| LIVE-CH "needs a real reply" (singular) | `CacheBreakpoints::observeCacheHealth` fires on the third consecutive zero report, so it needs 3 replies. |
| 5.14 "`$skill` per-turn injection" as wholly new | A session-scoped equivalent exists: Ctrl+S picker → `App::handleSelectSkill` (enables for the conversation). Only per-turn `$name` syntax is missing. |

## Shared files

| File | Steps and regions |
|---|---|
| `src/Chat.php` | `dispatchCommand` :10337-10430 (5.14b, c, d, e, h); `submit` :8764-9152 (5.14b in-flight gate ~:8787-8800, 5.14g `!` branch ~:8808); `READ_ONLY_COMMANDS` :9378-9382 (5.14b, h, f); `route` :2082 AssistantMsg arm + `requestPermission` :3242-3546 (5.14a); `userTurnMessage` :9171-9186 + `completeMention` :16342 (5.14l); `handleShareCommand` :12141-12159 (5.14f); `subscriptions` :17159-17286 (5.14i); whole file (15b-14-4, **last**) |
| `src/Commands/CommandRegistry.php::all` | 5.14b, c, d, e, h (new rows); 5.14f (description/hint); 15b-14-3 |
| README.md | "### Slash commands" :895 (5.14b–h); layered roster :219 + count word (5.11-2, 5.12, 5.14a, 5.14i); "### Subcommands" :431 (5.9-1); MCP bullet :1311 (5.11-1); "## Providers" :1149 (5.13) |
| `docs/COMMANDS.md` | built-in table :274 (5.14b–h, f); read-only list :333 (5.14b, h); `/rules` :420 (5.14d) |
| `docs/SETTINGS.md` | key table :138 + count :196 (5.11-2, 5.12, 5.13a/b, 5.14a, 5.14i) |
| `src/Config/LayeredSettings.php::LAYERED_KEYS` :410 | 5.11-2, 5.12, 5.13a, 5.14a, 5.14i (one key each; serialise or batch; coordinate with N-P0 schema rows) |
| `src/Permissions/PermissionGate.php::evaluateAuto` :461-501 | 5.11-1 and 5.11-2 (land 5.11-1 first) |
| `src/Providers/ProviderFactory.php` | `create` :113-160 (5.13b); `createCustom` :1289-1306 + `createOpenAI` (5.13a) |
| `docs/PERMISSIONS.md` | :246/:269 (5.11); new "Sandbox" (5.12); `!cmd` note (5.14g) |
| `src/Cli/{ParsedArgs,Subcommands,Help}.php` | 5.9-1 only here; shared cross-batch with O-3a/O-4a/O-8a |
| `scripts/parallel-tests-durations.tsv` + `tests/Config/Support/suite-figure.json` | every step that adds a test file (all except the LIVE script) |
