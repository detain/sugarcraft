# cc-audit-k1 — COMPARISON.md audit: PLAT · MOD · INS · MEM · PRM · CTX · SES · AGT

Auditor: cc-audit-k1 · tree: master @ 5e9195687 · 2026-10-26 · method: static reading of `candy-code/src`, `candy-code/builtin-commands`, `candy-code-web/src-web` (monorepo sources, not vendor). No tests were run. Line numbers are at this HEAD. Paths with no prefix are relative to `candy-code/`; `web:` means `candy-code-web/src-web/`.

## Summary

**Verdict: the matrix in scope is mostly right about the engine and mostly stale about candy-code-web.** The campaign shipped the web Context panel, agent catalog, session row menu and the `/model`·`/compact`·`/init`·`/goal` host bodies, but about 40 web cells still say "not shown / unused / ui_only". On the engine side there are a few real misses: the `gemini` provider, `instructionsExclude`, `/memory open`, the recall notice, `-p` persistence and the `/init` and `/compact` host bodies. Most Appendix A candy-code details in scope still describe the 2026-10-16 baseline. Many of them flatly contradict their own row's cell, and many carry "_2026-10-18 remediated_" or "_2026-10-26 superseded_" clutter.

| Count | Value |
|---|---|
| Rows audited | 156 (PLAT 10, MOD 28, INS 21, MEM 18, PRM 18, CTX 17, SES 18, AGT 26) |
| Cell changes | **68** (candy-code 19, candy-code-web 49). **26 change the glyph**: MOD-03cc, INS-20cc, MEM-09cc, MEM-10cc ↑✅; web MOD-11, MOD-12, INS-01/02/04/05, INS-15, INS-17, PRM-06/07/12, PRM-N3, CTX-04, SES-06, SES-08, AGT-03, AGT-06 ↑✅; MOD-22w ❌→🟡, SES-09w ❌→🟡, SES-17w 🧪→🟡, MOD-14w ❓→🟡, INS-06w ❓→❌ |
| Detail-only changes | **44** (cell unchanged; Appendix A bullet stale or cluttered) |
| Narrative fixes | **25** (§1 ×5, §4.1 ×5, §4.2 ×4, §4.4 ×4, §4.8 ×2, §4.9 ×3, §6 ×2) |
| Gaps | **44** |
| Dormant / half-wired code | **8** |

New findings not in the doc (all from static reading):
- (a) **Served sessions never drain the runtime-notice sink.** `Bootstrap::workspace()` arms it (`src/Cli/Bootstrap.php:1679`, called by serve at `src/Cli/Serve.php:592`). The only `drain()` is `src/Chat.php:18636`. So in the web the per-turn "Recalled N notes" row, the FallbackProvider switch notice and every other mid-turn runtime warning are dropped.
- (b) **Served sessions run no turn-end auto-memory consolidation, dream pass, prompt suggestion or auto-commit.** These are scheduled only from `src/Chat/Handlers/HandlesTurnCompletion.php:311-336`.
- (c) **The `/btw` side question is refused mid-turn on served sessions** by the busy gate at `src/Host/SessionHost.php:792-793`.

Checked and confirmed unchanged:
- §4.1 "Edit from UI ❌". `/memory open <id>` (`src/Chat.php:15628-15660`) opens memory notes only. No command opens CLAUDE.md or AGENTS.md (`EditorCommand::openFile` has one caller), so INS-16 ❌ stands.
- MEM-14 has no memory export.
- AGT-07 and AGT-20 are absent.

§2 scoreboard counts will move with the 26 glyph flips. They are out of this lane's scope; recount them at weld.

## Cell changes

### PLAT-03 · candy-code
- current: `🟡 Composer, self-built phar (`tools/build-phar.php`) and GHCR Docker image; no brew, npm, curl scrip`
- new: 🟡 Composer, signed release phar (GitHub releases, `candy-code/v*`) and GHCR Docker image; no brew, npm, curl installer or native binary
- detail: Composer `bin/candy-code`. `release-phar.yml` builds `candy-code.phar` and publishes it with a `.sha256` sidecar on every `candy-code/v*` tag; tags `candy-code/v0.1.0` and `v0.1.1` exist. From v0.1.1 on, releases also carry a minisign signature. `candy-code-image.yml` pushes `ghcr.io/detain/sugarcraft-candy-code` for `serve`. There is no Homebrew tap, npm package or curl/PowerShell installer. — _Evidence:_ `.github/workflows/release-phar.yml:1-30`; `.github/workflows/candy-code-image.yml:37,63-71`; `README.md:117-129`; `docs/DOCKER.md:10`; `git tag -l 'candy-code/*'`
- why: the phar is a published release artifact and the image is published; the old detail says "no image is published".

### PLAT-06 · candy-code-web
- current: `✅ Browser UI (desktop + responsive layout) over `candy-code serve`; sibling clients are `candy-code `
- new: ✅ Browser UI (desktop + responsive, installable PWA manifest) over `candy-code serve`; sibling clients: `candy-code attach` (TUI), ACP editors
- detail: cell change only; the existing detail stays valid. — _Evidence:_ `candy-code-web/index.html:7`; `candy-code-web/public/manifest.webmanifest`
- why: the PWA manifest (WEB-7b) shipped.

### PLAT-09 · candy-code
- current: `✅ — TUI first frame ≈0.6 s now that the DA1 terminal probe runs behind raw-mode entry (was ~450 ms s`
- new: ✅ TUI first frame ≈0.6 s (the DA1 probe runs after raw-mode entry); help, version and subcommands never build the TUI
- detail: cell unchanged otherwise. The ≈0.6 s figure is UNVERIFIED here (needs a tty measurement).
- why: drop the history voice ("now that…", "was ~450 ms slower").

### PLAT-10 · candy-code
- current: `🟡 i18n plumbing (Lang::t, locale from LC_ALL/LANG, ~2,100 externalised strings) but only English shi`
- new: 🟡 i18n plumbing (Lang::t, locale from LC_ALL/LANG, ~2,700 externalised strings) but only `lang/en.php` ships
- detail: `Lang::useLaunchLocale` runs first after autoload. Any non-en locale falls back to en because `lang/` holds only `en.php` (2,705 keys). — _Evidence:_ `lang/en.php` (`grep -c "=>"` → 2705); `ls lang/`
- why: the string count is stale.

### MOD-01 · candy-code-web
- current: `🟡 Inherited: works when the server's configured provider is anthropic/claude-code; no provider picke`
- new: 🟡 Inherited: usable when the server configures `anthropic`/`claude-code`; the status-bar provider select switches to it; keys stay server-side
- detail: The status bar fetches `server.info` providers and renders a `<select>`. Picking a provider runs `/model <provider>` on the host (`ModelHostCommand`, session-scoped), which takes that provider's configured default model. The browser never sees or sets credentials. — _Evidence:_ `web:components/StatusBar.vue:28-55,84-91`; `builtin-commands/0300-model.php:20-32`
- why: "no provider picker" is stale.

### MOD-02 · candy-code
- current: `🟡 — `max_completion_tokens`, omitted temperature and `reasoning_effort` for reasoning families; no R`
- new: 🟡 Chat Completions only: reasoning families get `max_completion_tokens` + `reasoning_effort`, no forced temperature; no Responses API
- detail: Non-reasoning models keep `temperature` (default 0.7) + `max_tokens`. Reasoning families send `max_completion_tokens`, send `temperature` only when the operator set one, and map `/effort` to `reasoning_effort`. There is no Responses-API arm; a ChatGPT subscription rides the separate `codex` type. — _Evidence:_ `src/Providers/OpenAIProvider.php:466-491`; `src/Providers/CodexProvider.php` (class docblock)
- why: drop the "—" remediation form. The detail's "always temperature 0.7 / gpt-5 400s" is stale.

### MOD-02 · candy-code-web
- current: `🟡 Inherited (server `openai` provider); not selectable from the web `
- new: 🟡 Inherited (server `openai` provider); selectable from the status-bar provider select when the server configures it
- detail: As MOD-01. — _Evidence:_ `web:components/StatusBar.vue:39-55`
- why: "not selectable" is stale.

### MOD-03 · candy-code
- current: `🟡 Gemini only via vertex (ADC, generateContent + streaming, thinkingBudget); no Gemini API-key type `
- new: ✅ Native `gemini` type (`GEMINI_API_KEY`, `x-goog-api-key`, generateContent + `?alt=sse`) beside Gemini-on-`vertex` (ADC); shared wire
- detail: `GeminiProvider` speaks `generativelanguage.googleapis.com` with the key in the `x-goog-api-key` header. It shares the `GeminiGenerateContentWire` lifted out of `VertexProvider`, so a prompt behaves the same on `gemini` and on `vertex`. It implements `ShapesReasoningEffort` (thinking budget) and `RebindsModel`, and `candy-code login gemini` stores its key. — _Evidence:_ `src/Providers/GeminiProvider.php:14-57`; `src/Providers/ProviderFactory.php:483,620-628,766,843`; `src/Cli/Subcommands.php:793-797,877`
- why: PRV-3 shipped a first-party Gemini API-key provider.

### MOD-03 · candy-code-web
- current: `🟡 Inherited (server `vertex` provider); not selectable from the web `
- new: 🟡 Inherited (server `gemini`/`vertex` providers); selectable from the status-bar provider select when configured
- detail: As MOD-01.
- why: stale.

### MOD-04 · candy-code-web
- current: `🟡 Inherited (server `bedrock` provider); not selectable from the web `
- new: 🟡 Inherited (server `bedrock` provider); selectable from the status-bar provider select when configured
- detail: As MOD-01.
- why: stale.

### MOD-06 · candy-code-web
- current: `🟡 Inherited via the server's `custom` provider config; not selectable from the web `
- new: 🟡 Inherited via the server's `custom`/named provider blocks; selectable from the status-bar select, not configurable from the web
- detail: As MOD-01.
- why: stale.

### MOD-07 · candy-code-web
- current: `🟡 Inherited (server `sglang`/custom local endpoints); not selectable from the web `
- new: 🟡 Inherited (server `sglang`/custom local endpoints); selectable from the status-bar provider select when configured
- detail: As MOD-01.
- why: stale.

### MOD-09 · candy-code
- current: `🟡 — No in-app OAuth flow; a Pro/Max subscription can ride the CLI's own login via the `bare: false` `
- new: 🟡 `candy-code login claude-code|codex` hands the terminal to `claude auth login`/`codex login`; `bare: false` uses it; no native OAuth or Copilot
- detail: `login <type>` passes subscription arms through to the vendor CLI with inherited stdio, so candy-code never touches the token. It stores API keys for openai/anthropic/gemini into the user config's `providers` block and refuses ambient types with the owner's name. A Claude Pro/Max login is used only when the `claude-code` block sets `bare: false` (default stays `--bare`). The `codex` type relays the Codex CLI's ChatGPT subscription. There is no native OAuth flow and no Copilot login. — _Evidence:_ `src/Cli/Subcommands.php:832-878`; `lang/en.php:1005-1007`; `src/Providers/ClaudeCodeProvider.php:30-37`; `src/Providers/CodexProvider.php` (class docblock)
- why: `candy-code login` (PRV-4) shipped; the cell still says "no in-app OAuth flow" as if nothing existed.

### MOD-10 · candy-code
- current: `✅ 9 provider types (+`gemini`, +`codex`) + echo + named blocks; live model catalog (`models all`, ca`
- new: ✅ 9 types (openai, anthropic, claude-code, codex, sglang, bedrock, vertex, gemini, custom) + echo + named blocks; `models all` catalog
- detail: `availableTypes()` lists the nine. `/model` and `models all` list ids from `/v1/models` (HasModelCatalog providers) plus the cached LiteLLM DB, lazily on open. — _Evidence:_ `src/Providers/ProviderFactory.php:481-484`; `src/Providers/HasModelCatalog.php`; `src/Providers/ModelCatalog.php`
- why: drop the "(+…)" delta voice. The old detail ("7 types, ≤3 ids, LiteLLM only sizes") is stale.

### MOD-11 · candy-code-web
- current: `❌ `/model` is client-only: `command.exec` answers -32030 `ui_only` (verified); status bar shows the `
- new: ✅ Status-bar provider `<select>` and typed `/model [provider [model]]` run `ModelHostCommand` on the server (session-scoped switch)
- detail: The `/model` spec carries `ModelHostCommand`, so `command.list` marks it `runsIn: server`. The host lists `Bootstrap::availableProviders()` and switches through the workspace's provider factory, scoped to the session. The persisted `models` write stays the terminal's. The status bar's select emits `/model <provider>` (or `<provider> <model>` for the current entry). — _Evidence:_ `builtin-commands/0300-model.php:20-32`; `src/Host/Commands/ModelHostCommand.php`; `web:components/StatusBar.vue:39-55,84-91`
- why: WEB-2 shipped.

### MOD-12 · candy-code-web
- current: `🟡 Per-agent models apply server-side; the Agents panel shows each run's step/tokens/cost, not its mo`
- new: ✅ Per-agent models apply server-side; the agent view header names each run's model beside status, step, tokens and cost
- detail: `subagent.*` events carry `model`. The tree store keeps it, and `AgentView` prints it in the run header. — _Evidence:_ `src/Protocol/Schema/EventSchemas.php:59`; `web:stores/agents/tree.ts:39,111`; `web:components/agents/AgentView.vue:95`
- why: the model is now shown.

### MOD-14 · candy-code-web
- current: `❓ Any server-side fallback is invisible; no event or notice names a model change `
- new: 🟡 Fallback applies to served turns, but its switch notice rides RuntimeNoticeSink, which serve never drains; the browser is not told
- detail: `FallbackProvider` reports a switch through `RuntimeNoticeSink::warn()` (error_log + sink). Serve arms the process sink in `Bootstrap::workspace()`, but the only drain is the TUI's `Chat`. No `drain()`/`notifyOnceWhenPending()` caller exists under `src/Host` or `src/Server`, so the row never reaches a served transcript. Static reading; not run-verified. — _Evidence:_ `src/Providers/FallbackProvider.php:105-112,457`; `src/Cli/Bootstrap.php:1610,1679`; `src/Cli/Serve.php:592`; `src/Chat.php:18636`
- why: now settled by code reading.

### MOD-15 · candy-code-web
- current: `❌ No effort/thinking control; e.g. `thinkingBudget` is risk class `spend`, which `settings.set` refu`
- new: ❌ No effort control: `/effort` has no host body (refused `ui_only`) and `reasoningEffort`/`thinkingBudget` are `spend`-class, refused remotely
- detail: The `/effort` spec has no `withHostCommand`. Both settings are `RiskClass::Spend`, which `settings.set` refuses over the wire. — _Evidence:_ `builtin-commands/0350-effort.php:15-19`; `src/Config/Settings/Definitions/ModelProviderSettings.php:123-125,141-143`
- why: name the real blocker; /effort exists in the TUI now.

### MOD-19 · candy-code
- current: `✅ — The native arm implements `MarksPromptCache` through `CacheBreakpoints`, so cache-health warning`
- new: ✅ `CacheBreakpoints` on native `anthropic` and Claude-on-Vertex, Bedrock `cachePoint`; OpenAI/SGLang cache server-side; CacheHealthWatch warns
- detail: The shared `AnthropicMessagesWire` applies `withCacheBreakpoints` (≤4 marks: system tail, last tool, tail chain) for both `anthropic` and the Vertex Anthropic arm. Bedrock marks system + last message. `promptCache` / `CANDY_CODE_DISABLE_PROMPT_CACHE` switch it off. `CacheHealthWatch` warns after 3 zero-cache replies. — _Evidence:_ `src/Providers/AnthropicProvider.php:39`; `src/Providers/Concerns/AnthropicMessagesWire.php:551-563`; `src/Providers/BedrockProvider.php:118-128`; `src/Backend/CacheHealthWatch.php:52-59`
- why: the cell described one arm only. The detail still cites the OpenAI-compat "not supported" relay.

### MOD-21 · candy-code-web
- current: `✅ `session.send` carries `images` alongside `text`; paste/drop deliver them (WEB-3/4/5) `
- new: ✅ Clipboard paste attaches PNG/JPEG/GIF/WebP via `session.send` `images` (server re-sniffs bytes); no drag-drop or file picker
- detail: `Composer.vue` reads pasted images (cap `MAX_PASTED_IMAGES`). `session.send` validates base64 + mime and `TurnMethods` sniffs the bytes. No component handles `drop`. — _Evidence:_ `web:components/Composer.vue:70-71,115-119`; `src/Protocol/Schema/MethodSchemas.php:211-219`; `src/Protocol/Methods/TurnMethods.php:170`
- why: no drop handler exists, and the cell carried plan IDs. The detail still says ❌ "a user cannot send one".

### MOD-22 · candy-code-web
- current: `❌ No PDF/document upload; only `@path` mentions of files already on the server are resolved `
- new: 🟡 No browser PDF upload; `@path` to a PDF already on the server rides as a document block (`FileMentions` `%PDF-` sniff)
- detail: Served prompts resolve mentions server-side. A `%PDF-` file within `DOCUMENT_MAX_BYTES` becomes an `AttachmentType::Document` sent as a document block on supporting providers. `session.send` accepts only `images`. — _Evidence:_ `src/Attachments/FileMentions.php:260-262,296-310`; `src/Host/TurnController.php:1060`; `src/Protocol/Schema/MethodSchemas.php:215`
- why: PDF delivery works through mentions.

### MOD-25 · candy-code
- current: `🟡 Env vars or plaintext apiKey in ~/.candy-code/config.json (0600, ${VAR} interpolation); no OS keyc`
- new: 🟡 Env vars or plaintext apiKey in ~/.candy-code/config.json (0600, ${VAR}; `candy-code login` writes it); no OS keychain
- detail: cell change only; the existing detail stays valid. — _Evidence:_ `src/Cli/Subcommands.php:937-990`
- why: `login` is now the documented writer.

### MOD-N1 · candy-code-web
- current: `🟡 Inherited Task `model` arg (sub-agent on another model, same provider); the run shows in the Agent`
- new: 🟡 Inherited Task `model` arg (sub-agent on another model, same provider); the agent view header names the run's model
- detail: No advisor surface. A delegated run appears as a Task card and tree node, and the header shows status, step, tokens, cost and model. — _Evidence:_ `web:components/agents/AgentView.vue:95`
- why: the "without its model" part is stale.

### MOD-N2 · candy-code-web
- current: `❌ No fast mode / service tier; the web has no model controls at all (status bar shows provider · mod`
- new: ❌ No fast mode or service tier (the engine has none); the status-bar provider select and `/model` are the only model controls
- detail: The engine sends no `service_tier`. The web's only model controls are the provider select and typed `/model` (ModelHostCommand). — _Evidence:_ `web:components/StatusBar.vue:84-91`; grep `service_tier` in `src/Providers` → none
- why: "no model controls at all" is stale.

### INS-01 · candy-code-web
- current: `🟡 Engine loads CLAUDE.md for served sessions; the web never lists or shows it — only `/context` repo`
- new: ✅ Engine loads CLAUDE.md for served sessions; the Context panel lists each loaded file with path, size and tier
- detail: `context.sources` prices the same memoized `Bootstrap::instructionLoader()` the prompt splices: ancestor, root, forced and personal docs, plus refused or deferred paths. `ContextPanel.vue` renders them as plain spans with no content viewer. — _Evidence:_ `src/Host/ContextSources.php:98-118,133-150`; `src/Protocol/Methods/ContextMethods.php:54-76`; `web:components/panels/ContextPanel.vue:96-118`; `web:components/panels/SidePanels.vue:102`
- why: WEB-3a shipped.

### INS-02 · candy-code-web
- current: `🟡 AGENTS.md: same — applied server-side, invisible in the web except as a `/context` section size `
- new: ✅ AGENTS.md: same — applied server-side and listed by path and size in the Context panel
- detail: As INS-01.
- why: stale.

### INS-03 · candy-code-web
- current: `🟡 Whatever vendor rule files the engine reads apply; the web lists none `
- new: 🟡 Whatever vendor files the engine reads apply and appear in the Context panel's instruction list; rule packs are not listed
- detail: As INS-01. The panel lists `loadDocuments()` + `loadPersonal()`; `RuleLoader` packs (`.claude/rules`, `.cursor/rules`) are not in it. — _Evidence:_ `src/Host/ContextSources.php:103-108`
- why: stale.

### INS-04 · candy-code-web
- current: `🟡 The server account's user-global instructions apply; not visible in the web `
- new: ✅ The server account's personal `~/.candy-code/AGENTS.md` (or `~/.claude/CLAUDE.md` stand-in) applies; the panel tags it `personal`
- detail: As INS-01. `loadPersonal()` rows carry `tier: personal`. — _Evidence:_ `src/Host/ContextSources.php:106-108,138-144`; `web:components/panels/ContextPanel.vue:104`
- why: stale.

### INS-05 · candy-code-web
- current: `🟡 Inherited if the engine supports a local override; not visible in the web `
- new: ✅ Inherited: `CLAUDE.local.md` is an engine FILENAMES tier and appears in the Context panel when loaded
- detail: As INS-01. — _Evidence:_ `src/Context/InstructionFileLoader.php:180`
- why: the engine does support it; the "if" is stale.

### INS-06 · candy-code-web
- current: `❓ Managed/enterprise instruction support is an engine question; the web surfaces nothing `
- new: ❌ No managed/enterprise instruction file (the engine has none)
- detail: The engine's policy file governs permissions and hooks only. — _Evidence:_ `src/Context/InstructionFileLoader.php:180` (no managed tier)
- why: now settled.

### INS-07 · candy-code-web
- current: `🟡 Inherited: discovery runs from the served root (or the workspace host's root); not shown `
- new: 🟡 Inherited: discovery runs from the served root (or the workspace host's root); ancestor files appear in the Context panel
- detail: As INS-01; `loadDocuments()` returns ancestor + root + forced docs. — _Evidence:_ `src/Context/InstructionFileLoader.php:461-479`
- why: stale.

### INS-12 · candy-code
- current: `✅ Read once per session at first prompt build; frozen until /clear, compaction, rewind or session sw`
- new: ✅ Read once at first prompt build and frozen until a refresh point; mid-session edits reach the model as a bounded diff in `<turn-context>`
- detail: The standing slab is a `SessionPromptMemo` PerSession layer, refreshed only at `/clear`, compaction, rewind or a session switch. `InstructionDiffTracker` fingerprints the root files at freeze and stat-checks them each turn. A change renders a bounded, PromptFence-escaped unified diff (or a name+size `Read` pointer) in the user-role `<turn-context>` tail, so the cached prefix never moves. Nested files arrive on touch. — _Evidence:_ `src/Context/InstructionDiffTracker.php:1-50`; `src/Runtime.php:5742-5754`; `src/Context/TurnContextBlock.php:105,209,283`; `src/Context/InstructionFileLoader.php:1653`
- why: CTX-3 shipped. Cell and detail both said edits are not seen until a refresh point.

### INS-14 · candy-code-web
- current: `❌ No startup banner or list of loaded files; the dashboard shows only the served root and versions `
- new: ❌ No startup banner; loaded instruction files are listed only on demand in the Context panel
- detail: The dashboard shows served root and versions. `/notices` prints launch warnings, and the Context panel lists files when opened. — _Evidence:_ `web:components/panels/ContextPanel.vue:96-108`; `src/Host/Commands/NoticesHostCommand.php`
- why: the "or list of loaded files" part is stale.

### INS-15 · candy-code-web
- current: `🟡 `/context` (server-run) prints the section breakdown incl. instruction and memory section sizes; n`
- new: ✅ Context panel (`context.sources`): instruction files with size and tier, "Not read" refusals, memory notes, skills, MCP, breakdown
- detail: The panel uses the same `ContextBreakdown` measurement as `/context`, structured and rendered as plain spans. Typed `/context` still prints the text report. There is no content viewer. — _Evidence:_ `web:components/panels/ContextPanel.vue:68-150`; `src/Host/ContextSources.php:133-160`; `web:stores/contextSources.ts:49`
- why: WEB-3a shipped. The detail's "verified: rows …" probe is stale.

### INS-17 · candy-code
- current: `✅ `/init [focus]` has the agent study the repo and write or improve root AGENTS.md (TUI only; refuse`
- new: ✅ `/init [focus]` has the agent study the repo and write or improve root AGENTS.md; served sessions run it too (InitHostCommand)
- detail: It sends `InitCommand::prompt()` as a user turn. The prompt reads existing AGENTS.md, CLAUDE.md, GEMINI.md, .cursorrules, .clinerules and copilot-instructions first; the gated Write tool does the writing. `InitHostCommand` lands the same prompt through the host's submit route. The new file reaches the prompt as a `<turn-context>` diff, or in full at the next refresh point. — _Evidence:_ `builtin-commands/2500-init.php:16-26`; `src/Host/Commands/InitHostCommand.php`; `src/Commands/InitCommand.php`
- why: WEB-2 gave /init a host body.

### INS-17 · candy-code-web
- current: `❌ `/init` is client-only: refused -32030 `ui_only` over the wire (verified) `
- new: ✅ Typed `/init [focus]` runs on the server (InitHostCommand) and starts the AGENTS.md-writing turn
- detail: As INS-17 candy-code. — _Evidence:_ `builtin-commands/2500-init.php:26`
- why: WEB-2.

### INS-18 · candy-code-web
- current: `🟡 Inherited truncation; any warning reaches the web only via `/notices` `
- new: 🟡 Inherited truncation; refused or deferred files are listed with reasons under the Context panel's "Not read"
- detail: `refusedPaths()` rides `context.sources` as `untrusted` and is rendered in the panel. — _Evidence:_ `src/Host/ContextSources.php:110-112,146`; `web:components/panels/ContextPanel.vue:111-118`
- why: stale.

### INS-19 · candy-code-web
- current: `❌ No trust dialog: untrusted project hooks/MCP/commands stay off per user-tier allow-lists; the web `
- new: ❌ Instruction files are not trust-gated (engine has none); the Context panel's trust prompt covers project `.mcp.json` only
- detail: `context.sources` raises a `kind: trust` ask when the root's `.mcp.json` is untrusted, answered by `context.trust`. The answer is session or user tier; project-tier remember is refused. It never gates CLAUDE.md or AGENTS.md. — _Evidence:_ `src/Protocol/Methods/ContextMethods.php:32-50,61-74`; `web:components/panels/ContextPanel.vue:160-172`
- why: "the web cannot grant trust" is stale.

### INS-20 · candy-code
- current: `❌ No exclusion control for instruction docs (rules have `disabledRules` and `/rules` toggles) `
- new: ✅ User-tier `instructionsExclude` globs skip root, ancestor, forced and on-touch docs, recorded as refusals; a repo cannot set it
- detail: `Bootstrap::instructionLoader()` passes `excludedInstructions()` (user config only) to the loader. One `excluded()` gate runs before containment on every repository-tier read loop and records `excluded by instructionsExclude` in `refusedPaths`, so the skip is announced. The personal tier is never silenced. — _Evidence:_ `src/Config/Settings/Definitions/MemoryRuleSettings.php:132-139`; `src/Cli/Bootstrap.php:8904-8910,8998-9013`; `src/Context/InstructionFileLoader.php:390-397,588-620,946`; `docs/SETTINGS.md:266,1141`
- why: INT-1(d) shipped. Cell and detail both say ❌.

### INS-20 · candy-code-web
- current: `🟡 Exclusion keys appear in the schema-driven Settings form, editable only when their risk class allo`
- new: 🟡 `instructionsExclude` shows read-only in the Settings form (prompt class, not remotely writable); excluded files appear under "Not read"
- detail: As INS-20 candy-code; remote writes are refused for `prompt` class. — _Evidence:_ `src/Config/Settings/Definitions/MemoryRuleSettings.php:134`; `web:components/panels/ContextPanel.vue:111-118`
- why: name the real key.

### MEM-02 · candy-code-web
- current: `🟡 Model-initiated saves (Memory tool) appear only as an ordinary tool card `
- new: 🟡 Served sessions run no turn-end auto-memory (scheduled only by the TUI); Memory-tool saves appear as plain tool cards
- detail: `AutoMemoryConsolidator::call` is scheduled only from the TUI's turn-completion handler. `SessionHost` has no equivalent, so a web-driven session saves memory only when the model calls `Memory`. Static reading. — _Evidence:_ `src/Chat/Handlers/HandlesTurnCompletion.php:311-319`; grep `AutoMemoryConsolidator` in `src/Host`, `src/Server` → none
- why: the cell implied auto-memory was inherited.

### MEM-08 · candy-code-web
- current: `🟡 Consolidation/dreaming not surfaced; `/skills` only lists dream-proposed skill drafts`
- new: 🟡 The dream pass runs only from the TUI's turn completion; served sessions never run it; `/skills proposed` lists drafts a TUI pass made
- detail: `DreamPass::call` is gated and scheduled only in `HandlesTurnCompletion`. — _Evidence:_ `src/Chat/Handlers/HandlesTurnCompletion.php:326-336`
- why: the cell implied the pass runs but is invisible.

### MEM-09 · candy-code
- current: `🟡 `/memory list/add/search/edit/delete/clear/import/log/restore` text subcommands; no TUI panel or e`
- new: ✅ `/memory list/add/search/edit/delete/clear/import/log/restore` plus `/memory open <id>` in `$EDITOR`; no browsing panel in the TUI
- detail: `MemoryCommand` is a host command, so it also works over `serve` except `open`, which needs a terminal and is refused there. In the TUI, `memoryOpenArm` resolves the id and hands the note file to `EditorCommand::openFile()` on the `Cmd::exec` channel. — _Evidence:_ `src/Chat.php:15609-15660`; `src/Host/Commands/MemoryCommand.php:65-79`; `src/Commands/EditorCommand.php:118`
- why: VIS-2b shipped the editor.

### MEM-10 · candy-code
- current: `🟡 — Pre-compaction flush now prints a saved-rows notice row; recall itself stays unannounced`
- new: ✅ Notices for auto-memory, dream-pass and pre-compaction-flush writes plus a per-turn "💭 Recalled N notes" row (count only, TUI)
- detail: `Runtime::announceMemoryRecall()` records `chat.memory.recalled_notice` into `RuntimeNoticeSink` whenever the recall set is non-empty and changed. `MemoryConsolidatedMsg` and `DreamPassCompletedMsg` append a notice when a note was saved, changed or removed, and the flush notice names its rows. Recall rows name no notes and link no files. — _Evidence:_ `src/Runtime.php:5975-5994`; `lang/en.php:1897-1898`; `src/Chat/Handlers/HandlesTurnCompletion.php:380-415`
- why: VIS-6 shipped.

### MEM-10 · candy-code-web
- current: `🟡 No "memory saved/recalled" toast; the user sees only the tool card `
- new: 🟡 Only tool cards: the recall row rides RuntimeNoticeSink, which serve never drains, and served sessions run no auto-memory or dream pass
- detail: The recall notice is recorded in the served turn child but never drained (only `Chat` drains). The consolidator and dream notices come from TUI-only scheduling. Static reading. — _Evidence:_ `src/Runtime.php:5991`; `src/Chat.php:18636`; `src/Cli/Bootstrap.php:1679`; `src/Chat/Handlers/HandlesTurnCompletion.php:311-336`
- why: give the cause, which also defines the gap.

### MEM-N1 · candy-code-web
- current: `✅ — Panel mutations route through `MemoryWriter`, so edits land in memory history like `/memory` wri`
- new: ✅ Panel writes commit to memory history (MemoryWriter); log/restore by typing `/memory log|restore`; no history view in the panel
- detail: `memory.add/edit/delete` route through `MemoryWriter`, so HOME-store writes commit like `/memory`. The `memory.log`/`memory.undo` methods exist but no web code requests them. — _Evidence:_ `src/Protocol/Methods/MemoryMethods.php:25-37,70-71,177-180`; grep `memory.log` in `src-web` → none
- why: the detail is stale ("no MemoryHistory commit … 'outside' subject"); the cell needs the true UI scope.

### PRM-05 · candy-code-web
- current: `🟡 Tool sets per mode/agent are server-side; `/context` reports tool-schema tokens; no tool list (`se`
- new: 🟡 Tool sets per mode/agent are server-side; the About dialog lists the server's tool names (`server.info`); no per-mode/per-agent view
- detail: `AboutModal` renders `info.tools` (count + names) from `server.info`. — _Evidence:_ `web:components/AboutModal.vue:45,88-91`
- why: "`server.info` unused" is stale.

### PRM-06 · candy-code-web
- current: `🟡 `/context` shows the `available-skills` section size; no skills list `
- new: ✅ Inherited listing; the Context panel lists skills by name with tier and enabled state
- detail: `context.sources.skills` comes from the workspace skill registry. — _Evidence:_ `src/Host/ContextSources.php:154-158`; `web:components/panels/ContextPanel.vue:132-140`
- why: WEB-3a.

### PRM-07 · candy-code-web
- current: `🟡 Inherited; `agents.list` is unused by the web `
- new: ✅ Inherited `<available-agents>` roster; the Agent catalog panel (`agents.list`) and About dialog list it with model and description
- detail: `AgentCatalogPanel` requests `agents.list` and shows provider · model, permission mode and description per preset. — _Evidence:_ `web:components/agents/AgentCatalogPanel.vue:26,51-52`; `web:components/panels/SidePanels.vue:100`; `web:components/AboutModal.vue:97-100`
- why: WEB-1c.

### PRM-12 · candy-code-web
- current: `🟡 `/context` runs server-side and prints the full breakdown (sections, tools, history, free, largest`
- new: ✅ Context panel renders the per-section breakdown and history share (`context.sources`); typed `/context` prints the full text report
- detail: As INS-15 web. — _Evidence:_ `web:components/panels/ContextPanel.vue:56-60,76-94`
- why: WEB-3a.

### PRM-N3 · candy-code-web
- current: `🟡 Inherited Maxims + prove-before-done prompt clauses; `/goal` (the judge loop) is refused `ui_only``
- new: ✅ Inherited Maxims + prove-before-done clauses; `/goal` and `/grind` run on the host, with the judge loop on SessionHost's turn settle
- detail: `GoalHostCommand` serves status, set and clear and the first round. `SessionHost` arms the title-model judge on its own turn settle through the shared `GoalLoop` decision table and stops on the TUI's exact conditions. — _Evidence:_ `builtin-commands/3400-goal.php:27`; `src/Host/SessionHost.php:1847-1930`; `src/Goal/GoalLoop.php`
- why: WEB-2 and W7a-3. Note: the comment at `builtin-commands/3400-goal.php:15-19` still says the continuation loop does not run headless. That comment is stale (see Dormant code).

### CTX-04 · candy-code
- current: `✅ `/compact [focus]` (LLM summary) and `/compact --self` (model's Compress, previewed in the permiss`
- new: ✅ `/compact [focus]` (LLM summary, TUI and served) and `/compact --self` (model's Compress, previewed; terminal only)
- detail: `CompactHostCommand` runs the same `CompactionService` fold on served sessions and refuses only `--self`. A typed `/compact` refuses on a SummarySafeguard HOLD. — _Evidence:_ `builtin-commands/2000-compact.php:16-27`; `src/Host/Commands/CompactHostCommand.php:11-47`
- why: the cell's trailing "TUI only" is stale (beyond the 100-char prefix). The detail says "no host command".

### CTX-04 · candy-code-web
- current: `❌ `/compact` is client-only — refused -32030 `ui_only` over the wire (verified); no compact button `
- new: ✅ Status-bar "compact" button and typed `/compact [focus]` run CompactHostCommand on the server; `--self` stays terminal-only
- detail: The button emits `/compact` (disabled while busy). — _Evidence:_ `web:components/StatusBar.vue:92-99`; `src/Host/Commands/CompactHostCommand.php:34-47`
- why: WEB-2.

### CTX-11 · candy-code-web
- current: `🟡 `/btw` is a server-run built-in; whether it answers mid-turn via `command.exec` was not verified (`
- new: 🟡 `/btw` runs on the host (BtwHostCommand) between turns only; `SessionHost::runCommand` refuses every built-in while a turn runs
- detail: The busy gate admits only workflow-control commands mid-turn, so the side question cannot be asked during the turn it is meant for. — _Evidence:_ `src/Host/SessionHost.php:787-793`; `src/Host/Commands/BtwHostCommand.php:49-54`
- why: now settled by code reading.

### CTX-N2 · candy-code
- current: `🟡 — Idle windows over the offer threshold get a compaction advice row in-session (TUI and served); a`
- new: 🟡 Opt-in `compaction.idleAuto` folds a session idle past `idleOfferSeconds` (1 h) when over the 85% tier; off by default, not TTL-timed
- detail: `IdleCompactionPolicy::shouldAutoCompact()` is shared by the TUI idle tick and a one-shot `SessionHost` timer. It fires only after the idle window, re-asks the 85%/cap verdict and never aborts in-flight work. With `idleAuto` off, a session idle past the window and over the whole window gets an advice row instead. No path times a fold to a provider cache TTL (5-min default marks). — _Evidence:_ `src/Context/IdleCompactionPolicy.php:130-154`; `src/Chat.php:14059-14160`; `src/Host/SessionHost.php:155-165,532-545,673-708`; `src/Config/Settings/Definitions/ContextSettings.php:177-180`
- why: CTX-5 shipped. The cell still says only an advice row exists.

### CTX-N2 · candy-code-web
- current: `🟡 — The host advisory is wired and the automatic tier summarises-then-refuses; there is no silent au`
- new: 🟡 Inherited: served sessions run the same opt-in `compaction.idleAuto` fold (SessionHost timer) and the over-window advisory
- detail: As CTX-N2 candy-code. The fold lands as `compaction.completed`, which the reducer renders as a "History compacted" notice. — _Evidence:_ `src/Host/SessionHost.php:673-708`
- why: "no silent auto-compact" is stale.

### SES-01 · candy-code
- current: `✅ SQLite WAL ~/.candy-code/session.db (0600), interned transcripts + checkpoints, saved within 0.5 s`
- new: ✅ SQLite WAL ~/.candy-code/session.db (0600), interned transcripts + checkpoints, saved within 0.5 s; `-p` runs saved and resumable
- detail: Transcript rows are interned into `checkpoint_blobs`, so a save writes only new data. A one-shot `-p` opens a real session row before the turn and records it after the answer, so `--resume`/`-c` and the JSON envelope's `session_id` work. — _Evidence:_ `src/Cli/NonInteractive.php:297-334,643-659`; `src/Session/EnhancedSessionStore.php`
- why: the cell's tail "-p not saved" is stale (AUT-2). The detail also says `-p` is not persisted.

### SES-06 · candy-code-web
- current: `🟡 `/fork` and `/branch` run server-side via `command.exec`; the `session.fork` RPC is unused; no For`
- new: ✅ Session row menu "Fork" calls `session.fork` and opens the copy; `/fork` and `/branch` also run server-side
- detail: `SessionSidebar` fork → `sessions.fork()` → `session.fork`, then `open(forked.id)`. — _Evidence:_ `web:components/SessionSidebar.vue:73-79,186`; `web:stores/sessions.ts:95-100`
- why: WEB-1a.

### SES-08 · candy-code-web
- current: `🧪 `session.export` (markdown/json/text) exists with generated TS types but no web control; `/share` `
- new: ✅ Session row menu "Export · Markdown/JSON/Text" calls `session.export` and downloads the file in the browser
- detail: `exportRow` downloads through a blob URL (revoked on the next export or unmount). — _Evidence:_ `web:components/SessionSidebar.vue:93-118,187-189`; `web:stores/sessions.ts:113-116`
- why: WEB-1a wired the dormant method.

### SES-09 · candy-code-web
- current: `❌ No public share URL; `/share` exports to a server-local file `
- new: 🟡 Typed `/share serve [days]` publishes a token page on this server and prints its URL (`/share revoke`); no Share button
- detail: `ShareHostCommand` runs `ShareCommand`, whose `serve` verb publishes into `ShareStore` and prints the URL. The `session.share`/`session.unshare` RPCs have no web caller. — _Evidence:_ `src/Host/Commands/ShareHostCommand.php`; `src/Commands/ShareCommand.php:154-200`; grep `session.share` in `src-web` → none
- why: SES-3 made a share URL reachable from the web composer.

### SES-15 · candy-code-web
- current: `🟡 Per-session spend in status bar, tiles and summaries; no per-turn/historical usage view `
- new: 🟡 Per-session spend in status bar, tiles and summaries; typed `/usage` (host) prints ledger rollups as text; no usage view
- detail: `UsageHostCommand` folds the durable `usage_records` ledger. — _Evidence:_ `src/Host/Commands/UsageHostCommand.php:12-66`
- why: /usage is reachable over the wire.

### SES-17 · candy-code-web
- current: `🧪 `sessions.remove` → `session.delete` exists in the store but no component calls it; no retention U`
- new: 🟡 Session row menu "Delete" (with confirm) calls `session.delete`; no retention controls (retention is server config)
- detail: `removeRow` confirms, then `sessions.remove()`. — _Evidence:_ `web:components/SessionSidebar.vue:86-91,191`; `web:stores/sessions.ts:90-93`
- why: WEB-1a.

### AGT-02 · candy-code
- current: `🟡 Alt+M toggles plan mode, /permissions mode <name> switches; no persona switch; palette "Switch age`
- new: 🟡 Alt+M toggles plan mode, `/permissions mode <name>` switches; no primary-agent or persona switch (palette "List agents" runs /agents)
- detail: The `/agents` palette row (action `SwitchAgent`) is labelled "List agents" and lists or inspects the roster. Nothing switches the main chat to a preset. — _Evidence:_ `builtin-commands/0800-agents.php`; `lang/en.php:1057,1073`; `src/Palette/PaletteAction.php:33`
- why: the label changed. The detail's "Switch agent … switches nothing (see ISSUES)" is stale.

### AGT-02 · candy-code-web
- current: `❌ No agent switcher; `/agents` (server-run) prints the roster as text `
- new: ❌ No agent switcher; the Agent catalog panel and `/agents` (server-run) only list the roster
- detail: As AGT-06 web.
- why: the catalog exists, but nothing switches.

### AGT-03 · candy-code-web
- current: `🟡 Built-ins (coder, reviewer, debugger, architect, tester, devops) run server-side; the web shows ru`
- new: ✅ Built-ins run server-side; the Agent catalog panel lists them with description, provider · model and permission mode
- detail: As PRM-07 web.
- why: "shows runs, not the catalog" is stale.

### AGT-04 · candy-code-web
- current: `🟡 Custom agent files apply server-side; not listed or editable in the web `
- new: 🟡 Custom agent files apply server-side and are listed in the Agent catalog panel; not editable from the web
- detail: As PRM-07 web.
- why: stale.

### AGT-05 · candy-code-web
- current: `🟡 Inherited; fields not shown `
- new: 🟡 Inherited; the catalog shows description, provider · model and permission mode, not tools, skills or isolation
- detail: — _Evidence:_ `web:components/agents/AgentCatalogPanel.vue:51-52`
- why: stale.

### AGT-06 · candy-code-web
- current: `🟡 Agents panel = live tree of THIS session's delegated runs (status, step, tokens, cost); `agents.li`
- new: ✅ Agents panel = live tree of this session's runs; the Agent catalog panel (`agents.list`) lists every preset with model and mode
- detail: As PRM-07 web.
- why: WEB-1c.

### Detail-only changes (cell unchanged)

Each line below is a replacement Appendix A DETAIL for the named bullet. The cell stays as it is.

- **PLAT-01 · candy-code** — cell unchanged, detail only: "Runs on stock php-cli 8.3. composer.json requires ext-sqlite3, pdo_sqlite, mbstring and intl, and suggests curl plus pcntl/posix/ffi, which `serve` needs. `doctor` opens a real sqlite3 and PDO handle and fails when either is missing. Interactive turns run in a pcntl-forked child streaming frames to the parent; without pcntl they run in-process and the screen updates only when they finish." — _Evidence:_ `composer.json:35-39,77-81`; `src/Cli/Subcommands.php:261-271`
- **PLAT-02 · candy-code** — cell unchanged, detail only: "`composer.json` declares MIT and `candy-code/LICENSE` (MIT) ships in the package dir, so the README badge resolves in monorepo and split clones alike." — _Evidence:_ `composer.json:5`; `LICENSE:1`
- **PLAT-05 · candy-code** — cell unchanged, detail only: "`candy-code self-update [--check] [--rollback] [--channel=stable|dev]` replaces a release phar after sha256 and minisign checks (signature required when a key is pinned). The default channel follows the running build. Releases before `v0.1.1` carry no stamp and must be upgraded by hand. Composer installs update with `composer update`. There is no background update check." — _Evidence:_ `src/Cli/SelfUpdate.php:18,48-54,244-309`; `README.md:120-129`
- **PLAT-07 · candy-code** — cell unchanged, detail only: "There is no telemetry code. `ModelMetadata` refreshes the LiteLLM price DB at most once per 24 h in a detached grandchild (`CANDY_CODE_DISABLE_MODEL_METADATA=1` turns it off). OTLP event export runs only when the user configures an endpoint. `self-update` contacts GitHub only when invoked. Share pages are served by the user's own `serve`." Drop the "_2026-10-26 superseded_" clause. — _Evidence:_ `src/Providers/ModelMetadata.php`; `docs/ENVIRONMENT.md:67-68,195`
- **MOD-01 · candy-code** — cell unchanged, detail only: "`AnthropicProvider` posts to `/v1/messages` through the shared `AnthropicMessagesWire` (the Vertex Anthropic arm's body, parse, usage and cost code), with `CacheBreakpoints` caching. It supports `thinkingBudget` extended thinking with signed-block replay, PDF document blocks and assistant prefill. Default model `claude-sonnet-4-6`." — _Evidence:_ `src/Providers/AnthropicProvider.php:13-39,207,287`; `src/Providers/Concerns/AnthropicMessagesWire.php:551-563`
- **MOD-13 · candy-code** — cell unchanged, detail only: drop the trailing "_2026-10-18 remediated:_" clause. Restate it as current fact: "unset `titleModel`/`summaryModel` take `ProviderFactory::AUXILIARY_MODEL_BY_TYPE` (haiku-4-5 / gpt-4o-mini class)." — _Evidence:_ `src/Providers/ProviderFactory.php:100-106`
- **MOD-15 · candy-code** — cell unchanged, detail only: "`/effort [level|auto]` sets the session's reasoning dial, applied live after a running turn ends. Providers implementing `ShapesReasoningEffort` map it to their native shape: Anthropic and Vertex thinking budgets, Gemini thinking budget, OpenAI `reasoning_effort`, SGLang `reasoningEffort`. Others (bedrock, claude-code, codex, custom, echo) refuse honestly. Presets carry `effort:` for delegated runs." — _Evidence:_ `builtin-commands/0350-effort.php`; `src/Chat.php:12234`; `src/Providers/{Anthropic,Gemini,OpenAI,Sglang,Vertex}Provider.php` (implements `ShapesReasoningEffort`)
- **MOD-17 · candy-code-web** — cell unchanged, detail only: drop "Rendering cost is O(n²) per reply (ISSUES)" and the "_remediated_" clause. Say: "streaming replies render incrementally; the settled tail is announced once."
- **MOD-18 · candy-code** — cell unchanged, detail only: replace "Weak spots: Retry-After ignored, ladder totals ≈1.5 s …" and the remediated tail with "`TransientFailure` honours `Retry-After` (un-jittered, capped), else full-jitter backoff below the ladder ceiling; AWS SDK retries still stack on top." — _Evidence:_ `src/Providers/TransientFailure.php`
- **MOD-20 · candy-code** — cell unchanged, detail only: replace "`VertexProvider::contextWindow` returns 200,000 for every model and ignores the setting" and the remediated tail with "a per-model Vertex `CONTEXT_WINDOWS` table and a working `contextWindow` override; unknown Vertex models report null rather than a 200k guess." — _Evidence:_ `src/Providers/VertexProvider.php` (`CONTEXT_WINDOWS`)
- **MOD-24 · candy-code** — cell unchanged, detail only (the detail glyph is 🧪 while the cell is ✅): "`--json-schema` is decoded in `NonInteractive` and applied via `Backend::withJsonSchema()`. Providers answering `supportsJsonSchema()` false refuse with exit 2 `json_schema_unsupported` before the first billed call." — _Evidence:_ `src/Cli/ArgvParser.php:668`; `src/Cli/NonInteractive.php:287,415-429`
- **MOD-10 · candy-code-web** — cell unchanged, detail only: drop the "_2026-10-26 superseded_" tail and the stale "17 of the 62 … 🧪" lead. Say: "`server.info` feeds the status-bar provider select and the About dialog (version, providers, tools, agents, uptime with `server.health`); per-provider model catalogs are not surfaced." — _Evidence:_ `web:components/StatusBar.vue:31`; `web:components/AboutModal.vue:45,83-110`
- **INS-03 · candy-code** — cell unchanged, detail only: "FILENAMES = CLAUDE.md, CLAUDE.local.md, AGENTS.md, GEMINI.md, .cursorrules, .clinerules, .github/copilot-instructions.md, all through the same containment, size, scrub, @import and dedup gates. `RuleLoader` adds `.claude/rules/**` and `.cursor/rules/**` (`.mdc` accepted) at user and project tiers with `[claude]`/`[cursor]` badges. Not read: `.windsurfrules`, `CONVENTIONS.md`, the `.clinerules/` directory form." — _Evidence:_ `src/Context/InstructionFileLoader.php:180`; `src/Context/RuleLoader.php:26-32,214-217`
- **INS-04 · candy-code** — cell unchanged, detail only: replace "Cross-tool user files … are not read" with "when `~/.candy-code/AGENTS.md` is absent, `~/.claude/CLAUDE.md` stands in (never both)." — _Evidence:_ `src/Context/InstructionFileLoader.php:485-497,524`
- **INS-05 · candy-code** — cell unchanged, detail only: "`CLAUDE.local.md` is the second FILENAMES entry, read at every level after CLAUDE.md, under the same gates." — _Evidence:_ `src/Context/InstructionFileLoader.php:180`
- **INS-08 · candy-code** — cell unchanged, detail only (drop the V1 "re-injected every turn … raw, no fence" correction and the dated tail): "`loadForPath` runs after Read, Edit, Write, ApplyPatch, Glob and Grep touches. It returns every not-yet-shown ancestor doc between the touched dir and the root in one outermost-first pass, each inside a path-naming fence with an escaped body. Shown-marks persist across forked turns and delegated transcripts." — _Evidence:_ `src/Context/InstructionFileLoader.php:1159-1240`
- **INS-09 · candy-code** — cell unchanged, detail only: drop "Inline-code and `user@x.md` forms are expanded, contrary to the docs (ISSUES). Forced docs get no import expansion." and the remediated tail. Say: "inline-code and email-like forms are not expanded; forced docs expand their own imports." — _Evidence:_ `src/Context/ImportResolver.php:27`
- **INS-10 · candy-code** — cell unchanged, detail only: replace "It does not read `.claude/rules`" with "Foreign `.claude/rules` and `.cursor/rules` trees load at user and project tiers (INT-2)." — _Evidence:_ `src/Context/RuleLoader.php:26-32`
- **INS-11 · candy-code** — cell unchanged, detail only: "`Bootstrap::forcedInstructions` → `loadForced`. Absolute and `~` patterns are refused loudly into `refusedPaths`, and forced files expand their own `@import`s. A project may not set the key." — _Evidence:_ `src/Context/InstructionFileLoader.php:1043-1075`
- **INS-14 · candy-code** — cell unchanged, detail only (the detail glyph is ❌): "`StartupSummary::render` replaces the empty-conversation placeholder with version, provider · model, mode, cwd · branch, the instruction files the prompt splices (name + bytes), memory/skill/MCP counts and a tip. Refusals and deferrals stay launch notices." — _Evidence:_ `src/Support/StartupSummary.php:31,105-140,275-290`; `src/Renderer.php:4223`
- **INS-15 · candy-code** — cell unchanged, detail only (the detail glyph is 🟡 and it says "No command lists the loaded paths"): "`/context` (also served) prints per-layer tokens and stability, and lists the instruction files read with sizes and refusals via `ContextBreakdown::$instructionFiles`." — _Evidence:_ `src/Commands/ContextCommand.php:157`; `src/Host/Commands/ContextHostCommand.php:31,50`
- **INS-19 · candy-code** — cell unchanged, detail only: drop "Nested docs are not escaped (ISSUES)" and the remediated tail. Say: "Trust lists cover hooks, MCP, `!cmd` in project commands and project settings. Instruction files are not trust-gated; every root, ancestor, forced, nested and personal doc is fenced and escaped." — _Evidence:_ `src/Context/PromptFence.php`
- **INS-N1 · candy-code** — cell unchanged, detail only: drop "Exception: nested on-touch docs (ISSUES)" and the remediated tail. On-touch docs are fenced and escaped too. — _Evidence:_ `src/Context/InstructionFileLoader.php:1159-1240`
- **INS-N1 · candy-code-web** — cell unchanged, detail only: replace "with the same exception SC has (nested on-touch docs, per a1) … `/context` gives section sizes only" with "including nested on-touch docs; the Context panel lists the whole-session files by path and size."
- **MEM-06 · candy-code** — cell unchanged, detail only: drop "The embedding HTTP call is synchronous there (ISSUES)" and the remediated tail. Say: "the embedding request runs in the forked turn child (blocking only when there is no child)."
- **MEM-08 · candy-code** — cell unchanged, detail only: drop "Side effect: it refreshes the main session's prompt memo (ISSUES)" and the remediated tail. The pass does not touch the main session's memo.
- **MEM-11 · candy-code** — cell unchanged, detail only: replace "Unbounded growth (ISSUES)" and the tail with "records are size-capped and the dream pass reads from a byte offset."
- **MEM-N2 · candy-code** — cell unchanged, detail only: drop "Labelling bug … (ISSUES)" and the tail. Say: "user-written and model-written repo notes carry their own author labels."
- **MEM-N2 · candy-code-web** — cell unchanged, detail only (the old detail contradicts the ✅ cell): "`memory.list/search` read both stores, repository rows first with `source: repo|home`. `memory.add` routes through `MemoryWriter`, so a project note lands in `<repo>/.candy-code/memory/project/`. `edit` and `delete` resolve ids via `MemoryWriter::locate()`." — _Evidence:_ `src/Protocol/Methods/MemoryMethods.php:25-37,53-54,67`
- **PRM-06 · candy-code** — cell unchanged, detail only: drop "The listing has no aggregate budget: 98 skills measured at 40 KB (ISSUES)" and the tail. Say: "the listing is bounded by `skills.listingMaxBytes` with per-line clips and ranked truncation."
- **PRM-07 · candy-code** — cell unchanged, detail only: "`TaskTool::promptGuidance()` appends a fenced `<available-agents>` roster (name, description, tools summary, source badge) to the spawn doctrine. It falls back to bare names only when the listing is empty. Unknown names are refused with the roster listed." — _Evidence:_ `src/Tools/BuiltIn/TaskTool.php:283-286,715-735`
- **PRM-12 · candy-code** — cell unchanged, detail only: drop "Omits the tool-guidance and plan-mode layers (ISSUES)" and the "naming … remains open" tail. Say: "every layer is shown, and the instruction files read are named."
- **CTX-01 · candy-code** — cell unchanged, detail only (the detail glyph is 🟡): "`TokenEstimate` is script-weighted. `ContextMeter` calibrates it against provider-reported usage (×1–3), and the 70/85/95 % tiers consume that calibrated estimate, which covers system prompt, tool schemas and tool-argument bytes." — _Evidence:_ `src/Context/ContextCompactor.php:175-194,262-272`; `src/Host/ContextMeter.php`
- **CTX-03 · candy-code** — cell unchanged, detail only: drop "Caveat: the tiers ignore system and tool tokens (ISSUES). Caps are not validated" and the tail.
- **CTX-N1 · candy-code-web** — cell unchanged, detail only (the detail is ❌ while the cell is ✅): "`SessionHost` keeps its own ahead-of-need slot (id, history fingerprint, summaries, safeguard hold), pre-fetched at the reminder tier and spliced at the auto tier when the fingerprint still matches." — _Evidence:_ `src/Host/SessionHost.php:145-152`
- **SES-02 · candy-code** — cell unchanged, detail only: drop the ISSUES-P2 description and the tail. Say: "`-c`/resume resolve through a cwd-scoped ladder; `-c` with `--resume` is refused (exit 2)."
- **SES-09 · candy-code** — cell unchanged, detail only (the detail is ❌ and cites the uploader): "`/share serve [days]` publishes an HTML snapshot into `ShareStore` (128-bit token, expiry) served by `serve`. `/share revoke [token]` kills it, `session.share`/`session.unshare` expose the same over RPC, and the published body runs through `SecretRedactor`." — _Evidence:_ `src/Commands/ShareCommand.php:24-67,154-240`; `src/Share/ShareStore.php`
- **SES-11 · candy-code-web** — cell unchanged, detail only: drop "Also broken by the j/k hotkey while typing (ISSUES)" and the tail.
- **SES-15 · candy-code** — cell unchanged, detail only (the detail says in-memory only, no cost column): "`SpendLedger` records every billed call into the durable `usage_records` table with the calling engine's model. Resume reseeds spend, and `/usage [session|all]` folds day × model and per-model rollups; unpriced models print as `≥` lower bounds." — _Evidence:_ `src/Host/SpendLedger.php`; `src/Host/Commands/UsageHostCommand.php:12-66`
- **SES-17 · candy-code** — cell unchanged, detail only: drop the "real DB: … all unprunable" evidence and the tail. Say: "retention keeps `title_source='user'` sessions (legacy rows judged by name) plus pinned and sub-agent rows, and counts real content rows."
- **AGT-03 · candy-code** — cell unchanged, detail only: "Grants: coder Read/Edit/Write/Grep/Glob/ApplyPatch/Bash/Skill; tester Read/Write/Edit/Grep/Glob/Bash/Skill + phpunit-master; reviewer, debugger, architect and devops are read-leaning; none lists `Task`, so built-ins do not nest." — _Evidence:_ `src/Agents/AgentDefinition.php:38-57,182-205`
- **AGT-04 · candy-code** — cell unchanged, detail only: replace "the project path comes first … a cloned repo's reviewer replaces both your own reviewer and the built-in" with "user tier first, then project; the first path claiming a name keeps it; shadowed files are reported at launch." — _Evidence:_ `src/Cli/Bootstrap.php:3126-3160`; `src/Agents/AgentPresetRegistry.php:65-72,226`
- **AGT-06 · candy-code** — cell unchanged, detail only (the detail says "No surface browses the roster"): "`/agents` renders every configured agent as a `TranscriptTable` (working first) with Description, Model, Tier and Status; `/agents <name>` details one; `/agents <run>` opens its Agent View." — _Evidence:_ `src/Commands/AgentsCommand.php:39-63,114-165`
- **AGT-07 · candy-code** — cell unchanged, detail only: drop the "_2026-10-26 superseded_" clause. Say: "no `builtin-commands/` spec creates agents; presets are written by hand."
- **AGT-08 · candy-code** — cell unchanged, detail only: replace "ends 'Current agent roster: …' with names only. No agent description or tool list ever reaches the model" with "carries the fenced `<available-agents>` roster (name, description, tools, source)." — _Evidence:_ `src/Tools/BuiltIn/TaskTool.php:715-735`
- **AGT-19 · candy-code** — cell unchanged, detail only: delete the "Gaps: …" sentences and the dated tail that the cell already contradicts (CSV skills, mcpServers mapping, boolean parsing and launch-notice verdicts all ship).

## Narrative fixes

- §1 L56: current "`/theme`, `/keys` and `/sessions` are terminal-only (`/model`, `/compact` and `/init` gained host bodies and now run over the wire)." → new "Beyond UI chrome (`/theme`, `/keys`, `/sessions`, panes), `/effort`, `/newrule`, `/compress`, `/budget`, `/review` and `/security-review` are terminal-only; `/model`, `/compact`, `/init`, `/goal`, `/grind` and `/btw` run over the wire."
- §1 L57: current "No panel shows loaded instructions, skills, MCP or hooks; only text commands (`/context`, `/mcp`, `/notices`) hint at them." → new "The Context panel lists loaded instruction files (path, size, tier), refused files, memory, skills and MCP status, with a project-trust prompt; hooks show only through `/hooks`. Runtime notices (memory recall, model fallback) never reach the browser: serve does not drain the notice sink."
- §1 L62 (Context & memory, *trails* cell): current "vim mode lacks counts, text objects and `cw`" → new "No managed-policy instructions, system-prompt append or output styles (Claude Code has all three); no per-sub-agent memory; no prompt-cache warming; served sessions skip turn-end auto-memory and the dream pass"
- §1 L66 (Providers, *trails* cell): current "`models all` catalog and `candy-code login [provider]` (Codex PKCE) have shipped (a Claude subscription can ride the CLI's own login via the `bare: false` provider option)" → new "No native subscription OAuth (`candy-code login` hands off to `claude auth login` / `codex login` or stores an API key); no Azure or OpenRouter type, no OpenAI Responses API, no OS keychain"
- §1 L69 (Surfaces, *trails* cell): current "Linux-first, no Windows; Composer-only install" → new "Linux-first, no Windows; Composer, release phar or Docker image only (no npm, brew or curl installer)"
- §4.1 L559 (When / refresh, candy-code cell): current "First prompt build of a session, memoised; re-read only on `/clear`, compaction, rewind or a session switch" → new "First prompt build of a session, memoised; re-spliced only on `/clear`, compaction, rewind or a session switch. Mid-session edits reach the model next turn as a bounded diff in the `<turn-context>` tail"
- §4.1 L562 (Inspect, candy-code-web cell): current "`/context` text only" → new "Context panel: instruction files with path, size and tier, refused files under \"Not read\", memory, skills, MCP; `/context` text too"
- §4.1 L564 (Generate): current "`/init` (TUI only) | ❌ refused (`ui_only`)" → new "`/init` (TUI and served) | ✅ typed `/init` runs on the host"
- §4.1 L569: current "Its nested path is now fenced and fork-safe. What remains is *visibility*: no tool lists the loaded files at startup (Claude Code only notes AGENTS.md and size warnings), and candy-code doesn't even name them in `/context`." → new "Its nested path is fenced and fork-safe, and it is also the most *visible*: the startup summary and `/context` name the loaded files, the web Context panel lists them, and `instructionsExclude` silences a file with a recorded refusal. What it lacks is an in-app editor for instruction files and a trust gate."
- §4.1 L570: current "Claude Code and OpenClaw at least name the files on demand (`/memory`, `/context list`)." → new "Claude Code and OpenClaw also name the files on demand (`/memory`, `/context list`), and both can open them for editing, which candy-code cannot."
- §4.2 L580 (User told?, candy-code cell): current "🟡 Notices for auto/dream writes and for the flush (names its saved rows); recall stays unannounced" → new "✅ Notices for auto/dream writes and the flush (names its saved rows) plus a per-turn \"Recalled N notes\" row (count only; TUI only, since serve never drains the notice sink)"
- §4.2 L581 (User UI, candy-code cell): current "`/memory list/add/search/edit/delete/log/restore` (text)" → new "`/memory list/add/search/edit/delete/clear/import/log/restore` (text) + `/memory open <id>` in `$EDITOR`"
- §4.2 L586: current "Claude Code's memory is simpler but the most *transparent*: it says when it saved or recalled something." → new "Claude Code's memory is simpler but the most *transparent*: its saved/recalled rows link the files. candy-code's TUI also reports saves and a recall count, but the web shows neither."
- §4.2 L587: current "Provenance labels were fixed (user and model notes in the repo store carry the right author, and the memory slot refreshes after `/memory add`)." → new "User and model notes in the repo store carry their own author labels, and the memory slot refreshes after `/memory add`."
- §4.4 L605 (Built-ins): current "coder, reviewer, debugger, architect, tester, devops (⚠ coder lacks Write; tester lacks Write+Edit) | runs only" → new "coder, reviewer, debugger, architect, tester, devops (coder and tester carry Write/Edit/Grep/Glob) | runs + Agent catalog panel"
- §4.4 L607 (Model sees catalog, candy-code cell): current "⚠ Names only (\"Current agent roster: architect, coder, …\")" → new "✅ Fenced `<available-agents>` roster in the Task guidance: name, description, tools summary, source badge"
- §4.4 L608 (User sees catalog, candy-code-web cell): current "Agents panel = this session's runs; catalog unused" → new "Agents panel = this session's runs; Agent catalog panel (`agents.list`) lists every preset with model and mode"
- §4.4 L613: current "For *finding* them the model still sees names only, though the user-facing gap closed on 2026-10-18: `/agents` now lists the full roster with descriptions." → new "For *finding* them it is at parity: the model gets a fenced roster with descriptions and tools, `/agents` lists the full roster, and the web has a catalog panel; only `@agent` mentions and an agent wizard are missing."
- §4.8 L666: current "Each step also gets a user-role `<turn-context>` tail: git status/diff after writes, changed-since-read files, memory recall, context % past 60 %, post-compaction re-injection." → new "Each step also gets a user-role `<turn-context>` tail: git status/diff after writes, changed-since-read files, instruction-file diffs, memory recall, context % past 60 %, post-compaction re-injection."
- §4.8 L668: current "Explicit cache breakpoints exist only for Claude-on-Vertex and Bedrock. ⚠ The `anthropic` provider uses Anthropic's OpenAI-compatible endpoint, which **does not cache**." → new "Explicit cache breakpoints on the native `anthropic` arm and Claude-on-Vertex (`CacheBreakpoints`, ≤4 marks) and Bedrock (`cachePoint`); OpenAI and SGLang cache prefixes server-side; `CacheHealthWatch` warns on repeated zero-cache replies."
- §4.9 L686 (Counting, candy-code cell): current "Three figures: calibrated status-bar estimate, provider-anchored in-turn budget, and ⚠ history-only text estimate for the 70/85/95 % tiers (misses system prompt + tool schemas, so tiers fire late)" → new "Script-weighted estimate calibrated against provider-reported usage; the 70/85/95 % tiers and the status bar read the same calibrated figure (system prompt, tool schemas and tool arguments included); the in-turn step budget is provider-anchored"
- §4.9 L687 (Auto trigger, candy-code cell): current "Tiers 70/85/95 %, absolute caps 100k/150k, in-turn step budget, overflow retry" → new "Tiers 70/85/95 %, absolute caps 100k/150k, in-turn step budget, overflow retry; opt-in idle fold (`compaction.idleAuto`) after an hour idle"
- §4.9 L688 (Method, candy-code cell): current "Model summary with six facets + merged `<session-state>`; summaries **pre-fetched ahead of need** using the cached prefix; re-inject files, plan, skills, git, todos" → new "Model summary with six facets + merged `<session-state>`; summaries **pre-fetched ahead of need** using the cached prefix; `SummarySafeguard` audits headings, asks and identifiers (HOLD → heuristic fold); re-inject files, plan, skills, git, todos"
- §6 L772: current "most formerly unmoved protocol methods are called from the web (files.read, workflow.status and workspace.close still have no web caller)" → new "most formerly unmoved protocol methods are called from the web (files.read, workflow.status, workspace.close, memory.log, memory.undo, session.share and session.unshare still have no web caller)"
- §6.2 L791: current "6. **One-per-turn \"Recalled N notes\" row** — count-only, uiOnly, riding the `RuntimeNoticeSink` pipeline with zero renderer work." → new "6. **One-per-turn \"Recalled N notes\" row** — count-only, uiOnly, riding the `RuntimeNoticeSink` pipeline with zero renderer work (TUI only: `serve` arms the sink but nothing drains it)."

## Gaps (input for the new plan)

Hot-file legend: **(HOT)** marks a serialization-hot file. The protocol chain is `src/Protocol/**` → `docs/protocol/candy-code.v1.schema.json` (`scripts/gen-protocol-schema.php --check`) → `web:protocol/generated.ts` (`npm run gen:protocol -- --check`). Every new test file must land in `scripts/parallel-tests-durations.tsv` and refresh `tests/Config/Support/suite-figure.json`.

### GAP-MEM-10w · Served sessions drop every runtime notice (also MOD-14 web)
- have / missing: The TUI drains `RuntimeNoticeSink` on a subscription tick (`src/Chat.php:18636`). Serve arms the same process sink in `Bootstrap::workspace()` but never drains it, so recall rows, fallback switches and mid-turn tool or parser warnings vanish in the web. Claude Code shows "Recalled N memories" on every surface.
- approach: In `SessionHost`, arm `workspace->notices->notifyOnceWhenPending()` on the serve loop and project drained rows as `Message::notice` into the session transcript, the same shape `TurnRunner` events use, so the reducer's existing notice rows render them. Keep one sink per session as the NoticeSink docblock intends: serve hosts several sessions per process, so route by session id or construct a NoticeSink per `SessionHost`.
- files: modify `src/Host/SessionHost.php`, `src/Host/WorkspaceContext.php`, `src/Diagnostics/NoticeSink.php` (per-session instance), `src/Host/TranscriptProjector.php`; create `tests/Host/ServedRuntimeNoticeDrainTest.php`
- guards/docs: `docs/SERVER.md` notice section; `docs/MEMORY.md` VIS-6 paragraph; suite-figure + durations
- effort: M; deps: none

### GAP-MEM-02w · Turn-end auto-memory, dream pass and suggestions on served sessions (also MEM-08 web)
- have / missing: `AutoMemoryConsolidator`, `DreamPass`, prompt suggestion and auto-commit are scheduled only in `HandlesTurnCompletion.php:304-336`. Served sessions get none of them. Claude Code and OpenClaw run auto-memory on every surface.
- approach: Follow the GoalLoop precedent ("one decision table, two hosts"). Extract the settle-time side work into a pure `Host\TurnSettleWork` gate that returns the work to schedule. Chat keeps its Cmds; SessionHost runs the same calls on its settle and appends the `::notice()` rows. Bill each call through `SpendLedger::account()` with the model of the engine that ran it, as the TUI does.
- files: create `src/Host/TurnSettleWork.php`, `tests/Host/TurnSettleWorkTest.php`; modify `src/Chat/Handlers/HandlesTurnCompletion.php`, `src/Host/SessionHost.php`
- guards/docs: `docs/MEMORY.md` (auto-memory / dream "where it runs"), `docs/SERVER.md`; `tests/Config/MemoryDocumentationDriftTest.php`
- effort: M; deps: GAP-MEM-10w (for the notices to show)

### GAP-CTX-11w · `/btw` mid-turn on served sessions
- have / missing: `BtwHostCommand` works, but `SessionHost::runCommand` refuses every built-in while busy (`:792-793`), so the web cannot ask a side question during a turn. Claude Code, OC2 and OpenClaw answer `/btw` mid-turn.
- approach: Add a `runsWhileBusy` marker on `CommandSpec` (or an allow-list beside `isWorkflowControl`) for BtwHostCommand. It already returns `holdingTurn()` results and uses the title backend only, so it never touches the turn's slot.
- files: modify `src/Host/SessionHost.php`, `src/Commands/CommandSpec.php`, `builtin-commands/3500-btw.php`; create `tests/Host/BtwMidTurnTest.php`
- guards/docs: `docs/SERVER.md` mid-turn routing table; `docs/COMMANDS.md` via `tools/gen-command-docs.php` if spec fields render
- effort: S; deps: none

### GAP-MOD-15w · `/effort` and cache readout in the web (also MOD-19 web, MOD-N3 web)
- have / missing: `/effort` has no host body, and the web shows no cache-hit figure although `usage.updated` carries cache fields. Claude Code and OpenCode show both.
- approach: Write an `EffortHostCommand` mirroring `ModelHostCommand` (session-scoped `EngineBackend::withReasoningEffort`, no settings write). Add a status-bar cache-% span in `StatusBar.vue` reading the reducer's usage state.
- files: create `src/Host/Commands/EffortHostCommand.php`, `tests/Host/EffortHostCommandTest.php`; modify `builtin-commands/0350-effort.php`, `web:components/StatusBar.vue`, `web:stores/session.ts`, `lang/en.php` **(HOT)**
- guards/docs: `docs/COMMANDS.md` (gen), any "N host commands" roster prose (ARCHITECTURE), web unit tests
- effort: S; deps: none

### GAP-MOD-22w · PDF and document upload from the browser
- have / missing: PDFs reach the model only by `@path` of a server-side file. Paste supports images only, with no drag-drop or file picker. Claude Code web accepts files.
- approach: Extend `session.send` with `documents[]` (base64, `%PDF-` sniff via `FileMentions::sniffDocument`, `DOCUMENT_MAX_BYTES`) as a protocol ride. Add a drop target and file picker to `Composer.vue`.
- files: modify `src/Protocol/Schema/MethodSchemas.php` **(HOT chain)**, `src/Protocol/Methods/TurnMethods.php` **(HOT chain)**, `docs/protocol/candy-code.v1.schema.json`, `web:protocol/generated.ts`, `web:components/Composer.vue`, `web:lib/paste.ts`
- guards/docs: both gen `--check`s; `tests/Config/ServerProtocolDocumentationDriftTest.php`; `docs/SERVER.md`
- effort: M; deps: one free protocol wave

### GAP-PLAT-03 · Install channels (curl installer, Homebrew tap)
- have / missing: Composer, release phar and GHCR image ship. Every competitor has a one-line installer and brew.
- approach: Add a `tools/install.sh` that downloads the latest release phar plus `.sha256`/`.minisig` and verifies with the same key `SelfUpdate` pins. Generate a Homebrew formula (php dependency) into a tap repo from `release-phar.yml`.
- files: create `candy-code/tools/install.sh`, `candy-code/tests/Cli/InstallScriptTest.php`; modify `.github/workflows/release-phar.yml`, `README.md` **(HOT)**, `docs/DOCKER.md`
- guards/docs: `ReadmeRosterDriftTest` (Install section), durations
- effort: M; deps: none

### GAP-PLAT-04 · Native Windows
- have / missing: The TUI supports Linux and best-effort macOS; serve needs pcntl, posix and FFI. Every competitor runs natively on Windows.
- approach: The no-fork blocking fallback already exists. Port PTY through sugar-pty's Windows FFI (ConPTY, PHP 8.4+). Make `ProcessContainment` Windows-aware. Gate `serve` refusal stays.
- files: modify `src/Cli/Bootstrap.php` **(HOT)**, `src/Tools/BuiltIn/{Bash,PtyRun}.php`, `src/Support/ProcessContainment.php`, `.github/workflows/ci.yml` (`WINDOWS_LIBS`)
- guards/docs: README platform banner **(HOT)**, `tools/check-child-lifetimes.php` roster
- effort: L; deps: none

### GAP-PLAT-05 · Update notification
- have / missing: `self-update` is manual. Claude Code, OpenCode and OpenClaw check or notify automatically.
- approach: Do a daily `SelfUpdate --check` equivalent in a detached grandchild, using the `ModelMetadata` refresh pattern and caching the verdict in `~/.candy-code/cache/`. `StartupSummary` paints an "update available" line; opt out with an env var. Phar installs only.
- files: modify `src/Cli/SelfUpdate.php`, `src/Support/StartupSummary.php`, `lang/en.php` **(HOT)**, `docs/ENVIRONMENT.md` **(HOT)**
- guards/docs: `EnvRosterDriftTest`; the PLAT-07 telemetry cell (new network call)
- effort: M; deps: none

### GAP-PLAT-10 · UI locales (candy-code and web)
- have / missing: `Lang` plumbing covers 2,705 keys but only `en.php` ships, and the web chrome is hard-coded English. OpenClaw ships 21 locales.
- approach: Ship one community locale file (`lang/<code>.php`) under `LangParityTest`. For the web, WEB-7c: serve the `Lang` catalogue subset via `server.info` or a `lang.get` method, with key-by-key `en` fallback.
- files: create `lang/<code>.php`; modify `web:**/*.vue` (chrome), `src/Protocol/Methods/ServerMethods.php` **(HOT chain)**
- guards/docs: `tests/LangParityTest.php`; protocol gens
- effort: L; deps: owner ruling (WEB-7c was deferred to backlog)

### GAP-MOD-02 · OpenAI Responses API
- have / missing: Chat Completions only. OpenCode and OpenClaw use Responses (reasoning items, built-in tools).
- approach: Add an `api: responses` option on the `openai` block. A new `OpenAiResponsesWire` trait does body shaping and SSE parse over the vendor client's Responses resource. Reuse `ShapesReasoningEffort` and the `CacheHealthWatch` usage fold.
- files: create `src/Providers/Concerns/OpenAiResponsesWire.php`, `tests/Providers/OpenAiResponsesWireTest.php`; modify `src/Providers/OpenAIProvider.php`, `src/Providers/ProviderFactory.php`
- guards/docs: `docs/SETTINGS.md` (gen via `tools/gen-settings-doc.php`), `docs/ADDING_A_PROVIDER.md`
- effort: L; deps: none

### GAP-MOD-05 / MOD-06 · Azure OpenAI and OpenRouter types
- have / missing: Both are reachable only through `custom` (undocumented). OpenCode and OpenClaw ship first-class types.
- approach: Add `azure` (OpenAIProvider with `{resource}.openai.azure.com/openai/v1/` and an `api-key` header) and `openrouter` (CustomProvider preset with `HTTP-Referer`/`X-Title` and HasModelCatalog `/models`) to `availableTypes()`, `defaultConfig()` and the env map. Extend `candy-code login` `LOGIN_KEY_ENVS`.
- files: modify `src/Providers/ProviderFactory.php`, `src/Cli/Subcommands.php` **(HOT headless)**, `lang/en.php` **(HOT)**, `docs/ENVIRONMENT.md` **(HOT)**, `README.md` **(HOT)**
- guards/docs: `EnvRosterDriftTest`, `ReadmeRosterDriftTest`, `docs/ADDING_A_PROVIDER.md`
- effort: M; deps: none

### GAP-MOD-09 · In-app subscription OAuth
- have / missing: `login` hands off to vendor CLIs. Claude Code and OpenClaw log in natively; OpenCode handles Copilot and ChatGPT.
- approach: Lift the MCP OAuth primitives (`OAuthPkce`, `OAuthLoopbackFlow`, `OAuthDiscovery`, the `McpLoginFork` pattern) into a shared `Auth\` store. Add ChatGPT PKCE for an `openai`-subscription arm and a Copilot device flow. Claude subscription stays CLI-only (vendor terms).
- files: create `src/Auth/{OAuthTokenStore,DeviceFlow}.php`; modify `src/MCP/McpAuthStore.php`, `src/Cli/Subcommands.php` **(HOT headless)**, `src/Providers/ProviderFactory.php`
- guards/docs: `docs/ENVIRONMENT.md`, README login section **(HOT)**, `tools/check-child-lifetimes.php`
- effort: L; deps: owner ruling (PRV-4 tier 3 was scoped out)

### GAP-MOD-25 · OS keychain for provider keys
- have / missing: Keys are plaintext in a 0600 config. Claude Code and OpenClaw use the keychain.
- approach: Add a `${keychain:<name>}` interpolation form beside `${VAR}` in ProviderFactory, resolved through `secret-tool` or `security` with `escapeshellarg`. `candy-code login --keychain` writes there.
- files: modify `src/Providers/ProviderFactory.php`, `src/Cli/Subcommands.php` **(HOT headless)**; create `src/Support/Keychain.php`, tests
- guards/docs: `docs/SETTINGS.md`, `ProtectFilesHook` roster
- effort: M; deps: none

### GAP-MOD-N1 · Advisor tool
- have / missing: The agent can only spawn a full sub-agent on another model. Claude Code has an advisor consult.
- approach: Add an `Advisor` tool that sends a transcript digest (BtwHostCommand's digest) plus a question to a configured `advisorModel` on the tool-less backend (`Bootstrap::toollessBackend`). Bill it through `SpendLedger`.
- files: create `src/Tools/BuiltIn/AdvisorTool.php`, tests; modify the tool roster in `src/Cli/LaunchContext.php`, `src/Config/Settings/Definitions/ModelProviderSettings.php`
- guards/docs: `ReadmeRosterDriftTest` (tool roster), `tools/gen-tool-docs.php`, `docs/SETTINGS.md`
- effort: M; deps: none

### GAP-MOD-N2 · Fast mode / priority service tier
- have / missing: None. Claude Code and OpenClaw have a fast toggle.
- approach: Add a `serviceTier` setting (Spend class, Live) mapped per provider (OpenAI `service_tier`, Anthropic `service_tier`), plus a `/fast` toggle beside `/effort`. Providers without a tier refuse honestly.
- files: modify `src/Providers/{OpenAI,Anthropic}Provider.php`, `src/Config/Settings/Definitions/ModelProviderSettings.php`; create `builtin-commands/0360-fast.php`; modify `src/Chat.php` **(HOT)**, `lang/en.php` **(HOT)**
- guards/docs: `docs/COMMANDS.md` gen, `ReadmeRosterDriftTest`, `docs/SETTINGS.md`
- effort: S–M; deps: none

### GAP-MOD-N3 · Cache warming
- have / missing: Marks use a 5-min TTL and nothing keeps them warm. OC2 warms.
- approach: Add an opt-in `promptCache.keepWarm` that uses the existing idle timers (Chat `IdleCompactTickMsg`, SessionHost `idleAutoTimer`) to send a `max_tokens: 1` request over the frozen prefix just before the TTL, capped N times. `CacheHealthWatch` records the warm hits.
- files: modify `src/Chat.php` **(HOT)**, `src/Host/SessionHost.php`, `src/Backend/CacheHealthWatch.php`, `src/Config/Settings/Definitions/ContextSettings.php`
- guards/docs: `docs/CONTEXT.md`, `docs/SETTINGS.md`
- effort: M; deps: none

### GAP-MOD-23 · Audio / voice
- have / missing: None. OpenClaw has TTS and STT, Claude Code has dictation.
- approach: Add a push-to-talk record via `arecord`/`sox` and transcribe through an OpenAI-compatible `/audio/transcriptions` endpoint into the composer. The web uses MediaRecorder plus a new `audio.transcribe` method.
- files: create `src/Media/Transcriber.php`; modify `src/Chat.php` **(HOT)**, protocol chain **(HOT)**
- guards/docs: `KeyBindingDriftTest`, `docs/MEDIA.md`
- effort: L; deps: none (low priority)

### GAP-INS-16 · Edit instruction files from the UI (CC + web)
- have / missing: `/memory open <id>` edits memory notes only, and there is no `#` quick-add. Claude Code's `/memory` opens CLAUDE.md; OpenClaw has a file editor.
- approach: Generalize `Chat::memoryOpenArm` into an `/instructions [open <n|path>]` command. It lists `loadDocuments()` + `loadPersonal()` (the same rows as `ContextSources::priceInstructionFiles`) and opens the pick via `EditorCommand::openFile()`. A `#text` composer prefix appends to personal `~/.candy-code/AGENTS.md` after confirmation. Edits reach the model through `InstructionDiffTracker`. For the web, add an edit affordance on Context-panel rows through a new write-scoped `context.instructionWrite`, jailed to listed paths.
- files: create `builtin-commands/2405-instructions.php`, `src/Commands/InstructionsCommand.php`; modify `src/Chat.php` **(HOT)**, `lang/en.php` **(HOT)**, `web:components/panels/ContextPanel.vue`, protocol chain **(HOT)** for the web half
- guards/docs: `docs/COMMANDS.md` gen, `ReadmeRosterDriftTest`, `KeyBindingDriftTest` (if `#` binds), `docs/MEMORY.md`
- effort: M (TUI) + M (web); deps: none

### GAP-INS-06 · Managed / enterprise instructions
- have / missing: The policy file covers permissions and hooks only. Claude Code reads `/etc/claude-code/CLAUDE.md`.
- approach: Add a managed tier read from the same root `PolicySnapshot`/`readPolicyFile` uses (e.g. `/etc/candy-code/AGENTS.md`). Fence it first with a "managed policy" preamble. It is never excludable by `instructionsExclude`.
- files: modify `src/Context/InstructionFileLoader.php`, `src/Cli/Bootstrap.php` **(HOT)**, `src/Runtime.php` **(HOT)** (section order), `src/Host/ContextSources.php` (tier `managed`)
- guards/docs: `docs/MEMORY.md`, `docs/PROMPT_ENGINEERING.md`, `ContainedPathInventoryTest` roster, `BaseSystemPromptTest`
- effort: M; deps: none

### GAP-INS-07 · Parent walk past the git root
- have / missing: The walk stops at the nearest `.git` and never reads `$HOME`. Claude Code walks to the filesystem root; OpenCode walks to the worktree root.
- approach: Add a user-tier `instructions.walkToRoot` bool that lets `ancestorRoot()` continue above `.git` up to (not including) `$HOME`, under the same containment and budget.
- files: modify `src/Context/InstructionFileLoader.php:845-880`, `src/Config/Settings/Definitions/MemoryRuleSettings.php`
- guards/docs: `docs/MEMORY.md` discovery sentence, `docs/SETTINGS.md`
- effort: S; deps: none

### GAP-INS-11 · URL and absolute instruction sources
- have / missing: `instructions` globs are repo-relative; absolute and `~` patterns are refused. OpenCode accepts URLs and absolute paths.
- approach: Allow absolute and `~` user-tier patterns through a new ContainedPath anchor. URL entries go through the WebFetch SSRF guard, cached per session, fenced as untrusted.
- files: modify `src/Context/InstructionFileLoader.php:1043-1075`, `src/Cli/Bootstrap.php` **(HOT)**
- guards/docs: `ContainedPathInventoryTest::ROUTED_CALL_SITES`, `docs/SETTINGS.md`, `docs/MEMORY.md`
- effort: M; deps: none

### GAP-INS-08w · Instruction-load visibility in the web (also INS-12 web)
- have / missing: The web never hears that a nested file was loaded on touch or that a root file changed mid-session. OpenCode shows "↳ Loaded"; OC2 shows "Instructions updated".
- approach: Emit the existing `loadForPath` hit and the `InstructionDiffTracker` change as notice rows. They ride GAP-MEM-10w's drain, or add a `context.changed` event that auto-reloads the Context panel.
- files: modify `src/Context/InstructionFileLoader.php`, `src/Context/InstructionDiffTracker.php`, `web:stores/contextSources.ts`
- guards/docs: `docs/SERVER.md`
- effort: S; deps: GAP-MEM-10w

### GAP-MEM-11 / MEM-12 · Searchable past sessions and journal for the model
- have / missing: The compaction journal feeds only the dream pass, and FTS5 session search is user-only. OpenClaw has daily logs plus `memory_search` over sessions.
- approach: Add a `recall` source `sessions` to `MemoryTool` backed by `SessionSearchIndex` (bm25, snippet), plus a `journal` view of `CompactionJournal`. The web search box gains a "sessions" scope.
- files: modify `src/Tools/BuiltIn/MemoryTool.php`, `src/Session/SessionSearchIndex.php`, `src/Memory/CompactionJournal.php`, `src/Protocol/Methods/MemoryMethods.php` **(HOT chain)**
- guards/docs: `tools/gen-tool-docs.php`, `docs/MEMORY.md`
- effort: M; deps: none

### GAP-MEM-13 · Sub-agent memory (wire the inert preset `memory:` key)
- have / missing: The preset `memory:` key is parsed and read by nothing (`FrontmatterKeyAudit::INERT`). Claude Code has per-agent memory.
- approach: Map `memory: project|user|local` to a per-preset `MemoryScope::Agent` sub-key. TaskTool injects a `MemoryBlock` index for that scope into the child prompt, and `MemoryWriter` routes the child's saves there. Remove `memory` from `INERT[AGENT]` in the same step.
- files: modify `src/Tools/BuiltIn/TaskTool.php`, `src/Agents/AgentPreset.php`, `src/Context/MemoryBlock.php`, `src/Memory/MemoryWriter.php`, `src/Support/FrontmatterKeyAudit.php:100-123`
- guards/docs: `tests/Config/InertFrontmatterDocumentationDriftTest.php`, `docs/AGENTS_AUTHORING.md`, `docs/MEMORY.md`
- effort: M; deps: none

### GAP-MEM-14 · Memory export and prompt-visible imports
- have / missing: Imports land in the agent scope (not in the index or recall), and there is no export. OpenClaw imports into prompt-visible memory.
- approach: Add `/memory import … --scope project|user` routed through `MemoryWriter`, and `/memory export [path]` writing a markdown bundle (0600, jailed like `/export`).
- files: modify `src/Host/Commands/MemoryCommand.php`, `src/Memory/ForeignMemoryImporter.php`, `lang/en.php` **(HOT)**
- guards/docs: `docs/MEMORY.md`, `docs/COMMANDS.md` gen
- effort: S–M; deps: none

### GAP-MEM-N3 · Standing intents
- have / missing: Only `paths:` rules and hooks exist. OpenClaw stores event-conditioned intents.
- approach: Add an `intent` memory type with a `when:` trigger (path glob, tool name, keyword). `RulePathNudge` and the `Trigger` classes in `src/Context/Triggers` already evaluate path and keyword triggers; consult intent notes at the same tool-time seam. The model saves them via `Memory save type=intent`.
- files: modify `src/Context/RulePathNudge.php`, `src/Context/Triggers/*`, `src/Tools/BuiltIn/MemoryTool.php`, `src/Memory/MemoryEntry.php`
- guards/docs: `docs/MEMORY.md`, `tools/gen-tool-docs.php`
- effort: M–L; deps: none

### GAP-PRM-09 · System-prompt append
- have / missing: There is deliberately no flag (PROMPT_ENGINEERING says so). Claude Code has `--append-system-prompt`.
- approach: Add a user-tier, headless-only `--append-system-prompt <text|@file>` spliced as a `<user-rules>`-fenced section, never as an override. Owner ruling needed against the documented doctrine.
- files: modify `src/Cli/ArgvParser.php`, `src/Cli/ParsedArgs.php`, `src/Cli/NonInteractive.php`, `bin/candy-code` **(all HOT headless)**, `src/Runtime.php` **(HOT)**
- guards/docs: README CLI table **(HOT)**, `ReadmeJsonErrorContractDriftTest`, `docs/PROMPT_ENGINEERING.md`
- effort: S–M; deps: owner ruling

### GAP-PRM-10 · Output styles
- have / missing: Rulebooks toggled with `/rules <name>` are the nearest thing. Claude Code and OpenClaw have output styles or personas.
- approach: Add `/output-style <name>` over a `~/.candy-code/styles/*.md` tier loaded by `RuleLoader` as a rulebook kind (one active at a time, persisted per session). The web lists styles in the Context panel.
- files: modify `src/Context/RuleLoader.php`, `src/Context/RulesState.php`, `src/Chat.php` **(HOT)**; create `builtin-commands/1405-output-style.php`
- guards/docs: `docs/COMMANDS.md` gen, `docs/MEMORY.md` rules tiers
- effort: M; deps: none

### GAP-PRM-13 · IDE context injection
- have / missing: Only ACP resource blocks. Claude Code, OpenCode and OC2 ship IDE extensions with selection and diagnostics.
- approach: Implement ACP `session/update` client capabilities for selections, plus a minimal VS Code extension speaking ACP (out-of-repo package).
- files: modify `src/Acp/AcpServer.php`
- guards/docs: `docs/SERVER.md` ACP section
- effort: L; deps: none

### GAP-PRM-14 · Date freshness and knowledge-cutoff note
- have / missing: The date is frozen at session start and there is no cutoff note. Claude Code and OpenClaw have both.
- approach: Put "Current date" into `<turn-context>` when it differs from `<env>` (no prefix move), and add a per-family cutoff line from `ModelMetadata`.
- files: modify `src/Context/TurnContextBlock.php`, `src/Context/EnvironmentBlock.php`, `src/Providers/ModelMetadata.php`
- guards/docs: `PromptPrefixByteStabilityTest` family, `docs/PROMPT_ENGINEERING.md`
- effort: S; deps: none

### GAP-PRM-15 · Prompt dump
- have / missing: There is no raw system-prompt or request dump. Claude Code and OpenClaw can print it.
- approach: Add `/context --raw [path]` writing the assembled `Runtime::buildSystemPrompt()` plus tool schemas to a 0600 file (jailed like `/export`), shared by TUI and host.
- files: modify `src/Commands/ContextCommand.php`, `src/Host/Commands/ContextHostCommand.php`, `src/Runtime.php` **(HOT)** (accessor only)
- guards/docs: `docs/COMMANDS.md` gen, `docs/PROMPT_ENGINEERING.md`
- effort: S–M; deps: none

### GAP-PRM-N2 · Project references
- have / missing: One root per session; Grep and Glob are jailed to it. Claude Code, OpenCode and OC2 accept extra dirs.
- approach: Add a user-tier `references: [{path, name}]` advertised in `<env>`, widen the `PathJail` allow-set for Read, Grep and Glob, and summarize each reference with `RepoMapBlock`.
- files: modify `src/Context/EnvironmentBlock.php`, `src/Agents/PathJail.php`, `src/Tools/BuiltIn/{Grep,Glob}.php`, `src/Cli/Bootstrap.php` **(HOT)**
- guards/docs: `docs/SETTINGS.md`, `docs/PERMISSIONS.md`
- effort: M–L; deps: none

### GAP-CTX-N2 · Cache-TTL-timed idle compaction
- have / missing: `compaction.idleAuto` is opt-in, waits one hour and fires only over the 85% tier. Claude Code compacts idle long chats before cache expiry by default.
- approach: Key the idle fold to the provider cache TTL (from `MarksPromptCache`) and to a size floor below the tier. Default on for providers that cache, with an owner ruling on the spend.
- files: modify `src/Context/IdleCompactionPolicy.php`, `src/Chat.php` **(HOT)**, `src/Host/SessionHost.php`
- guards/docs: `docs/CONTEXT.md`, `docs/SETTINGS.md`
- effort: M; deps: owner ruling

### GAP-SES-10 · Session import
- have / missing: There is no inverse of the JSON export and no foreign transcript import. OpenCode, OC2 and OpenClaw import sessions.
- approach: Add `candy-code session import <file>` reading the `ExportCommand`/`ShareSession` JSON and Claude Code JSONL into `EnhancedSessionStore` as a new session, with secrets redacted.
- files: modify `src/Cli/Subcommands.php` **(HOT headless)**; create `src/Session/SessionImporter.php`, tests
- guards/docs: README CLI roster **(HOT)**, `ReadmeRosterDriftTest`
- effort: M; deps: none

### GAP-SES-11 · Content search: web, and for the model
- have / missing: The TUI has FTS5. The web filter is client-side over names only, although `session.list` already takes `query`.
- approach: The web passes the filter text as `session.list {query}` (server-side FTS5 via `withBodySearch`). Model search is covered by GAP-MEM-11/12.
- files: modify `web:stores/sessions.ts`, `web:components/SessionSidebar.vue`, `src/Protocol/Methods/SessionMethods.php` (confirm the query reaches `SessionSearchIndex`)
- guards/docs: web unit tests
- effort: S; deps: none

### GAP-SES-12 · Concurrent live sessions in the TUI
- have / missing: One live session per window; tab switches are refused mid-turn. Claude Code, OC2 and OpenClaw run several.
- approach: Keep one `TurnRunner` per `SessionTabState` entry so background tabs keep streaming (SessionHost already does this per session in serve).
- files: modify `src/Chat.php` **(HOT)**, `src/Host/SessionTabState.php`, `src/Host/SessionSwitch.php`, `src/Renderer.php` **(HOT)**
- guards/docs: `KeyBindingDriftTest`, README
- effort: L; deps: none

### GAP-SES-14 · Push a local session to a server (teleport)
- have / missing: `attach` drives server sessions, but a local session cannot be handed over. Claude Code, OC2 and OpenClaw can.
- approach: Add `/handoff --remote <url>` that uploads the session row and transcript through a new `session.import` method (shared with GAP-SES-10's importer), then re-attaches.
- files: protocol chain **(HOT)**, `src/Cli/Attach.php`, `src/Host/Commands/HandoffHostCommand.php`
- guards/docs: protocol gens, `docs/SERVER.md`
- effort: M; deps: GAP-SES-10

### GAP-SES-15w / SES-17w · Usage view and retention controls in the web
- have / missing: `/usage` is text only and retention is not visible.
- approach: Add a `usage.rollup` read method over `UsageCommand::compose` data and a small Usage panel. Expose `sessionRetentionDays` in the Settings form if its risk class allows a remote write.
- files: protocol chain **(HOT)**, create `web:components/panels/UsagePanel.vue`
- guards/docs: protocol gens
- effort: M; deps: one protocol wave

### GAP-SES-N1 · Incognito sessions
- have / missing: Every session is persisted. OpenClaw has ephemeral sessions.
- approach: Add `--incognito` / `/incognito` (and a `session.create {ephemeral}` param). It skips `EnhancedSessionStore` writes and the auto-memory, dream and flush passes for that session and marks the status bar.
- files: modify `src/Cli/{ArgvParser,ParsedArgs,NonInteractive}.php` + `bin/candy-code` **(HOT headless)**, `src/Chat.php` **(HOT)**, `src/Host/SessionHost.php`, protocol chain **(HOT)**
- guards/docs: README CLI table **(HOT)**, `docs/SERVER.md`, protocol gens
- effort: M; deps: none

### GAP-AGT-01 / AGT-02 · Primary agent modes and switch (CC + web)
- have / missing: A single main agent; "modes" are permission modes. Claude Code, OpenCode and OC2 switch primary agents (Tab, `/agent`).
- approach: Add `/agent use <preset>` to run the main chat under a preset's prompt, tools and model (reusing TaskTool's preset resolution and `EngineBackend::withTools`), plus a Tab-cycle binding. The web gets a preset `<select>` beside the mode select, via a host body.
- files: modify `src/Chat.php` **(HOT)**, `src/Backend/EngineBackend.php`, `src/Commands/AgentsCommand.php`, `src/Host/Commands/AgentsHostCommand.php`, `web:components/StatusBar.vue`
- guards/docs: `KeyBindingDriftTest`, `docs/COMMANDS.md` gen, `docs/AGENTS.md`
- effort: L; deps: none

### GAP-AGT-07 · Agent creation wizard
- have / missing: None. Claude Code, OpenCode and OpenClaw generate agents.
- approach: Add `/agents new [focus]` mirroring `NewRulePrompt`: a canned prompt asks the agent to Write `.candy-code/agents/<name>.md` with frontmatter validated by `FrontmatterKeyAudit` (approval required). Give it a host body so the web gets it free.
- files: create `src/Host/Commands/NewAgentPrompt.php`; modify `builtin-commands/0800-agents.php` or create `0810-newagent.php`, `lang/en.php` **(HOT)**
- guards/docs: `docs/COMMANDS.md` gen, `docs/AGENTS_AUTHORING.md`
- effort: S; deps: none

### GAP-AGT-20 · @agent mention
- have / missing: `@` covers files, diff, session and url only. Claude Code, OpenCode and OC2 accept `@agent`.
- approach: Reserve `@agent:<name>` in `ContextMentions` (before path lookup). It rewrites to a fenced "delegate this to <name> via Task" directive, and the mention popup completes roster names.
- files: modify `src/Attachments/ContextMentions.php`, `src/Tui/MentionIndex` (popup source), `src/Chat.php` **(HOT)**
- guards/docs: `docs/AGENTS.md`, README mentions section **(HOT)**
- effort: M; deps: none

### GAP-AGT-N3 · Cross-session messaging
- have / missing: SendMessage is intra-session; `bg.inject` is a manual one-way inject. Claude Code and OpenClaw message across sessions.
- approach: Extend `SendMessage` with `session:<id>` targets that write into `BackgroundSupervisor`'s inbox or `SessionHost::submit` (queued delivery), signed like the mailbox.
- files: modify `src/Tools/BuiltIn/SendMessageTool.php`, `src/Background/BackgroundSupervisor.php`, `src/Agents/Live/AgentInbox.php`
- guards/docs: `tools/gen-tool-docs.php`, `docs/AGENTS.md`
- effort: M; deps: none

### GAP-AGT-N4 · Thread-bound sub-agent sessions
- have / missing: The child has its own Agent View transcript but no channel or thread binding (OpenClaw ✅, chat-app specific).
- approach: Low value without chat channels. Defer until a channel surface exists.
- files: —
- guards/docs: —
- effort: L; deps: chat-channel surface

### GAP-AGT-02w · Agent switcher in the web
- have / missing: The catalog lists presets but nothing switches.
- approach: Rides GAP-AGT-01.
- files: see GAP-AGT-01
- guards/docs: web tests
- effort: S after AGT-01; deps: GAP-AGT-01

## Dormant code

1. **Served runtime-notice sink is armed but never drained.** `Bootstrap::workspace()` arms the process sink (`src/Cli/Bootstrap.php:1679`) for serve (`src/Cli/Serve.php:592`, `src/Server/Workspace/WorkspaceHostProcess.php:137`). `SessionHost` passes `workspace->notices` to `TurnRunner::start()` only for `beginTurn()` (`src/Host/SessionHost.php:1785`, `src/Host/TurnRunner.php:354`). The sole `drain()` is `src/Chat.php:18636`. Wiring needed: GAP-MEM-10w.
2. **Agent preset `memory:` key** is parsed, carried onto the roster row and read by nothing (`src/Support/FrontmatterKeyAudit.php:100-123`, `INERT[AGENT]['memory']`). Wiring needed: GAP-MEM-13.
3. **Agent preset `color:` key** is in the same state (`src/Support/FrontmatterKeyAudit.php:122-123`). Wiring needed: the Agent View header and `AgentStrip` colour, plus the web tree node colour from `agents.list`.
4. **`memory.log` / `memory.undo` RPCs** are registered with no web caller (`src/Protocol/Methods/MemoryMethods.php:70-71`). Wiring needed: a History tab in `web:components/panels/MemoryPanel.vue` listing revisions with an Undo button (write scope).
5. **`session.share` / `session.unshare` RPCs** have no web caller. Wiring needed: a "Share…" item in the `SessionSidebar.vue` row menu showing the URL and revoke.
6. **Auto-memory, dream pass, prompt suggestion and auto-commit are half-wired** (TUI only): scheduled only in `src/Chat/Handlers/HandlesTurnCompletion.php:304-336`, with no served counterpart. Wiring needed: GAP-MEM-02w.
7. **Stale source docblock (not code):** `builtin-commands/3400-goal.php:15-19` still says the `/goal` continuation loop "rides the TUI's turn settle, which a headless host does not run … wiring the judge loop server-side is a follow-up". W7a-3 wired it (`src/Host/SessionHost.php:1847-1930`). Truth-flip the comment.
8. Outside scope, noted in passing: a skill's `model` is read only by `App::dispatchSkill()`, which has no production caller, and `context: fork` has no fork executor (`src/Support/FrontmatterKeyAudit.php:103-106`). Hand to the SKL lane.
