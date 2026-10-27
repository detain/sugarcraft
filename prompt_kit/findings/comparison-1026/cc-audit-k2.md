# cc-audit-k2 — COMPARISON.md audit: SKL, CMD, HK, PLG, MCP, TL, EDT, SH (+ §1, §4.3, §4.5–4.7, §6)

Audited against HEAD 5e9195687 (2026-10-26). Static reading only; no tests were run. Three slices were audited in parallel: SKL/CMD/HK plus the narrative (lead), MCP/PLG/EDT plus §4.7, and TL/SH. Their results are merged below. Overlapping narrative edits (§1 L57, L64, L65; §6.5 L820) are reconciled into one replacement each. Where the slices disagreed on §6.5 L820, the EDT slice wins: CRLF preservation is fixed in code, so EDT-10 says so.

## Summary

**Verdict: in this scope the doc is materially stale, mostly in the candy-code-web column and in the Appendix A bullets. A handful of ✅ cells also overstate.**

- **Counts.** 120 rows checked (SKL 15, CMD 13, HK 10, PLG 12, MCP 16, TL 31, EDT 12, SH 11). The audit proposes:
  - 83 cell changes;
  - 34 detail-only changes;
  - 32 narrative fixes;
  - 54 gap entries (two are cross-references to a shared item);
  - 10 dormant or broken-path items.
- **Confirmed contradictions** (all fixed below):
  - §4.5 says `.claude/commands` and `.opencode/commands` are not read. `ForeignCommandDiscovery` reads them, and they also reach the web.
  - §4.5 calls the palette a "curated 19-action subset" while §6.2 calls it a "superset". Both are wrong: the palette has 49 rows, covering 42 of the 57 slash commands plus 7 palette-only actions.
  - §4.6 says hook payloads are `CRUSH_*` env vars with stdin closed. Stdin actually carries a six-key Claude-shaped JSON payload.
  - §4.3 says skills are invoked by `$name` only, but `/skill-name` works in the TUI.
  - §1 says no web panel shows skills or MCP. The Context panel lists both.
  - The §4.6 `/hooks` claim is TRUE; the stale part is the HK-10 Appendix bullet ("❌ not among the 55 spec files").
- **Downgrades — cells that overstate:**
  - **HK-07 ✅→🟡.** In a Claude settings file, any non-`command` hook type, or any entry key beyond type/command/timeout (`async`, `statusMessage`, `if`), makes `hooks()` refuse the whole launch. That includes `~/.claude/settings.json`, which is always read. Exit 1 also denies, where Claude Code proceeds.
  - **MCP-11 ✅→🟡.** `McpClient::setAgentPreset()` has no caller, so `mcp_call`/`mcp_list` ignore a preset's `mcpServers`.
  - **TL-19 and TL-20 ✅→🟡.** The model sees Read's images only on the Anthropic and Vertex-Claude wires, and it reads PDFs only via `pdftotext`.
  - **TL-27 web ✅→🟡.** There is no read-only tool set in plan mode.
  - **MCP-14 is false as written.** MCP image and audio parts are described in text, never sent to the model as images.
  - **MCP-12 "by default" overstates.** Deferred loading applies only to servers above the 8,000-token floor.
- **Upgrades — cells that understate:**
  - SKL-06 🟡→✅ (VIS-5).
  - SKL-09 ❌→🟡 (`bundles install`).
  - TL-22 ❌→🟡 (`/loop` and `/schedules`, though the model still has no tool).
  - About 25 web cells flip up. The causes are the Context panel, server-run `/skills` and `/hooks`, foreign and bundle commands in `command.list`, `!cmd` answering `handled` with transcript events, and the `tool.progress` live tail.
- **Live defects found while auditing:**
  - Served sessions and `command.list` call `CommandLoader::loadAll($root)` without `projectCommandsTrusted`, so a trusted project's built-in override works in the TUI but not the web.
  - A Read image in the web becomes a MediaCard whose `media.fetch` cannot serve it (traced in code, not run).
  - SH-03's candy-code cell contains a Grep sentence instead of Bash timeouts.
  - The TUI receives `ToolProgress` frames but never paints them.
- **Formatting.** Several cells exceed 130 characters, and several cells join the next cell as `text| ` (no space before the pipe). Replacements here respect the 130-character limit.
- **§2 scoreboard.** It must be re-derived with its own parser after these flips land; the SKL/CMD/HK figures are under Narrative fixes. The dated notes under the scoreboard and the Appendix A lead-in (L851, "dated supersession pointers") break the current-state voice rule.
## Cell changes

### SKL-01 · candy-code-web
- current: `🟡 Inherited; skills are invisible in the web`
- new: ✅ Inherited; the Context panel lists each loaded skill with its tier (built-in/user/project) and a disabled flag
- detail: SKILL.md parsing is the engine's. The web's Context panel (`context.sources`) lists every registered skill by name, `SkillOrigin` tier and `disabledSkills` state; descriptions and format badges are not sent. — _Evidence:_ `candy-code/src/Host/ContextSources.php:153-157`; `candy-code-web/src-web/components/panels/ContextPanel.vue:132-142`; `candy-code-web/src-web/components/panels/SidePanels.vue:102`
- why: WEB-3a's Context panel ships a skills section; "invisible" is stale.

### SKL-02 · candy-code
- current: `✅ built-in (src/Skills/BuiltIn) < project .candy-code/skills < user ~/.candy-code/skills. No plugin `
- new: ✅ built-in < project < user `.candy-code/skills`, plus foreign trees and plugin-bundle `skills/` (lowest within each tier)
- detail: `SkillManager::loadAll()` walks tiers built-in < project < user; inside a tier, bundle trees register first, then `.agents`, `.claude`, `.opencode`, then native, so the last write wins and every displaced skill is recorded for the launch notice. Bundles come from `~/.candy-code/bundles`, `<root>/.candy-code/bundles` and the user-tier `pluginPaths` list. Symlinks are followed but confined; the walk stops at depth 7 or 2000 directories. — _Evidence:_ `src/Skills/SkillManager.php:113-170`; `src/Cli/Bootstrap.php:2273-2290,4952-4955`; `src/Skills/SkillLoader.php:189,205`
- why: PLG-1 bundles feed skills; "No plugin directories" is false.

### SKL-02 · candy-code-web
- current: `🟡 Inherited; not listed`
- new: ✅ Inherited discovery; the Context panel lists every loaded skill with its tier
- detail: Served sessions use the workspace registry built by `Bootstrap::skillRegistry()`, and `context.sources` exposes it to the panel. — _Evidence:_ `candy-code/src/Host/ContextSources.php:153-157`; `candy-code-web/src-web/components/panels/ContextPanel.vue:132-142`
- why: skills are listed now.

### SKL-03 · candy-code-web
- current: `🟡 Inherited; not listed`
- new: 🟡 Inherited; the Context panel lists imported skills by tier but without the [claude]/[opencode]/[bundle] badge
- detail: `ContextSources` sends `tier` (a `SkillOrigin` value: built-in/user/project) and never the `SkillSource` format, so a `.claude/skills` import looks like a native one in the browser. — _Evidence:_ `candy-code/src/Host/ContextSources.php:153-157`; `src/Skills/SkillSource.php:16-36`
- why: "not listed" is stale; the provenance gap is the real partial.

### SKL-04 · candy-code
- current: `✅ One-line <available-skills> listing in the system prompt each turn; body via Skill tool; asset loa`
- new: cell unchanged, detail only
- detail: The listing sits inside a fenced `<available-skills>` section under a preamble and a "MUST load" mandate, each line badged with its tier, escaped, clipped and budgeted by `skills.listingMaxBytes`. Skills enabled from the Ctrl+S picker ride as standing system-prompt sections and leave the listing. A `paths:` match announces a skill once through `SkillPathNudge` in Read/Edit/Glob/Grep/Write results. `SkillLoader::loadSkillAsset()` has no production caller. — _Evidence:_ `src/Runtime.php:4856-4870`; `src/Skills/SkillListingSection.php`; `src/Skills/SkillMatcher.php`; `src/Skills/SkillLoader.php:1054`
- why: cited Runtime lines (4706-4710) moved; content otherwise holds.

### SKL-04 · candy-code-web
- current: `🟡 Inherited; `/context` shows only the available-skills section size`
- new: ✅ Inherited; the Context panel lists the skills and the prompt layers' token sizes
- detail: The panel renders `context.sources` breakdown sections (per-layer token estimates) and the skills list; the body still loads only through the Skill tool server-side. — _Evidence:_ `candy-code-web/src-web/components/panels/ContextPanel.vue:80-92,132-142`
- why: the web now shows more than one section size.

### SKL-06 · candy-code
- current: `🟡 — `$name` attaches to the skill and reaches the model (picker bodies resolve at prompt build); the`
- new: ✅ `/skill-name args` rewrites into the `$name args` channel (built-ins and command files win the name); `$name` Tab-completes
- detail: After `dispatchCommand()` and the file-command branch decline a draft, `Chat::skillInvocationDraft()` rewrites a typed `/name args` (colon names walked longest-first) into `$name args`, so `SkillMentions` applies the registry gate, `$ARGUMENTS` substitution, the attachment and the `✨ Loaded skill:` row. Disabled or non-user-invocable skills never dispatch; an unclaimed `/word` is refused locally with a did-you-mean. The `/` popup lists no skills. — _Evidence:_ `src/Chat.php:9572-9605,11296-11365,11418-11460`; `src/Host/TurnController.php:1054-1056`; `src/Chat.php:16153-16166`
- why: VIS-5 shipped; the cell and detail still say there is no `/skill-name`.

### SKL-06 · candy-code-web
- current: `❌ Skills are not in `command.list`; a `/skill-name` typed in the web goes to the model as prose`
- new: 🟡 `$name` typed in the web loads the skill server-side (no completion); `/skill-name` is sent to the model as prose
- detail: `session.send` resolves mentions by default, and `TurnController::userTurnMessage()` runs `SkillMentions` against the workspace registry, so `$name args` works from the browser. `SessionHost::admit()` has no VIS-5 rewrite: a `/word` no built-in or command file claims falls through to `admitPrompt()` as prose. `command.list` carries no skill rows. — _Evidence:_ `candy-code/src/Host/SessionHost.php:892-933,1702-1710`; `candy-code/src/Protocol/Methods/TurnMethods.php:191-198`; `candy-code/src/Protocol/Methods/CommandMethods.php:48-60`
- why: `$name` already reaches skills from the web; ❌ understates.

### SKL-07 · candy-code
- current: `✅ — Skills pane = names; `/skills` lists the installed, invocable table with tiers and disabled/shad`
- new: ✅ Skills pane lists names; `/skills` prints the installed table (tier, disabled, shadowed); drafts sit behind `/skills proposed`
- detail: The pane shows the enabled list, or else the available names dimmed. Bare `/skills` (or `/skills installed`) prints a `TranscriptTable` of every invocable skill with tier, enabled flag, shadow count and description; `/skills proposed|accept|reject` manage dream drafts. The `/` popup lists no skills; `CANDY_CODE_DEBUG_SKILLS=1` names skipped files. — _Evidence:_ `src/Tui/Components/SkillsPane.php:67-91`; `src/Host/Commands/SkillsHostCommand.php:15-30,60-66`; `builtin-commands/2410-skills.php`
- why: cell was 150 chars; detail said `/skills` covers drafts only and carried a dated "remediated" tail.

### SKL-07 · candy-code-web
- current: `❌ No skills list anywhere; `/skills` (server-run) lists dream-proposed drafts only`
- new: ✅ Context panel lists skills (name, tier, disabled); server-run `/skills` prints the installed table; no toggles
- detail: `/skills` has a host body, so `command.exec` runs the same installed-table listing the TUI prints; the Context panel shows the registry as structure. Neither can enable or disable a skill. — _Evidence:_ `builtin-commands/2410-skills.php`; `candy-code/src/Host/Commands/SkillsHostCommand.php:60-66`; `candy-code-web/src-web/components/panels/ContextPanel.vue:132-142`
- why: both halves of the ❌ are stale.

### SKL-08 · candy-code-web
- current: `🟡 Inherited; not shown`
- new: ✅ Inherited; the Context panel lists the 8 bundled skills under the built-in tier
- detail: The eight `src/Skills/BuiltIn/` skills register in served sessions and appear in the panel with tier `built-in`. — _Evidence:_ `src/Skills/BuiltIn/`; `src/Skills/SkillOrigin.php:33`; `candy-code/src/Host/ContextSources.php:153-157`
- why: now shown.

### SKL-09 · candy-code
- current: `❌ No registry, marketplace or install command`
- new: 🟡 No registry or marketplace; `candy-code bundles install <git-url|path>` installs a plugin bundle whose `skills/` load
- detail: `bundles install` stages a Claude-format bundle into `~/.candy-code/bundles/` (bounded git clone with `protocol.ext.allow=never`, or a path copy) and `bundles list` shows what the reader sees; its `skills/` join discovery on the next launch. There is no index, search or update command. — _Evidence:_ `src/Cli/Subcommands.php:1464-1480,1604-1625`; `src/Cli/ParsedArgs.php:56`; `src/Skills/SkillManager.php:115`
- why: an install command exists now.

### SKL-11 · candy-code-web
- current: `🟡 Inherited; not shown`
- new: ❌ Inherited: allowed-tools / disallowed-tools are inert server-side too
- detail: The engine lists both keys in `FrontmatterKeyAudit::INERT` and no tool-scoping code reads them, so a served session inherits nothing to show. — _Evidence:_ `src/Support/FrontmatterKeyAudit.php:120-131`
- why: a 🟡 for inheriting an absent feature is inconsistent with the ❌ engine cell.

### SKL-12 · candy-code-web
- current: `🟡 Inherited; not shown`
- new: 🟡 Inherited; the Context panel lists only skills that passed gating, never the gated-out ones or why
- detail: Gating runs at registration (`SkillRegistry::register()` refuses unmet `requires`/`os`); `ContextSources` lists `all()`, which excludes the refused ones, and sends no `unavailable()` reasons. — _Evidence:_ `src/Skills/SkillRegistry.php:214-238`; `candy-code/src/Host/ContextSources.php:153-157`
- why: "not shown" is stale; the remaining partial is the missing reasons.

### SKL-13 · candy-code-web
- current: `❓ Engine question; the web shows no skill list to refresh`
- new: 🟡 Inherited: the served root's roster is built once per serve process; the Context panel's reload re-reads it, not disk
- detail: `serve` builds one workspace per root at launch (`Bootstrap::workspace()` → `skillRegistry()`); the panel's reload calls `context.sources` again over the same registry. Bodies are re-read from disk per Skill call, as in the TUI. — _Evidence:_ `candy-code/src/Cli/Serve.php:592`; `candy-code/src/Host/ContextSources.php:153-157`; `src/Tools/BuiltIn/SkillTool.php:226`
- why: ❓ is settleable from code.

### SKL-14 · candy-code
- current: `🟡 The dream pass may propose up to 3 drafts (opt-in `memory.dreamProposeSkills`); /skills accept or `
- new: 🟡 Opt-in `memory.dreamProposeSkills` lets the dream pass propose ≤3 drafts; `/skills accept|reject` reviews them; no wizard
- detail: Drafts land in `~/.candy-code/skills-proposed/`; `accept` validates with the loader and is user-only (no tool reaches it). — _Evidence:_ `src/Memory/DreamPass.php:107,156-160`; `src/Skills/ProposedSkills.php:15`; `src/Host/Commands/SkillsHostCommand.php:15-30`
- why: cell was 132 chars (trim only).

### SKL-15 · candy-code
- current: `🧪 `context: fork` is parsed, but the fork executor App::dispatchSkill has no caller, so fork skills `
- new: cell unchanged, detail only
- detail: `isContextFork()` is consulted only by `App::dispatchSkill()` (no production caller) and by the picker's status text; the Skill tool loads every skill inline. `model` and `effort` skill keys are likewise inert. — _Evidence:_ `src/App/App.php:2528-2560,4758-4767`; `src/Tools/BuiltIn/SkillTool.php:208-250`; `src/Support/FrontmatterKeyAudit.php:120-131`
- why: cited App lines (2213-2240, 2409) moved.

### SKL-15 · candy-code-web
- current: `🟡 Inherited; a forked skill shows as sub-agent activity if it delegates`
- new: 🧪 Inherited: `context: fork` is parsed but runs inline (App::dispatchSkill has no caller), so nothing forks
- detail: Served sessions use the same Skill tool, so a fork skill loads inline exactly as in the TUI. — _Evidence:_ `src/App/App.php:2528-2560`; `src/Tools/BuiltIn/SkillTool.php:208-250`
- why: the old text implied a working fork path.

### CMD-01 · candy-code
- current: `✅ 64 built-ins (+6 aliases) from builtin-commands/ spec files, e.g. /compact /rewind /undo /diff /bg`
- new: cell unchanged, detail only
- detail: Slash commands: /new /sessions /model /effort /share /export /exit(quit) /theme /settings(config) /agents(agent) /mcp /keys /help /permissions /notices /status /context(tokens) /sweep /pruning /compress /decompress /recompress /hooks /rules /newrule /pane /layout /editor /compact /clear /budget /usage /workflow /memory /skills /init /branch /rename /rewind /undo /redo /diff /review /security-review /bg(background) /schedules(schedule) /loop /fork /websearch /goal /grind /btw /handoff /generate /sendto /play /rendermode. Palette/menu-only: new-picker, session-pin, session-delete, docs, pane-dock-left, pane-dock-right, generate-form. Absent vs Claude Code: /plan (Alt+M instead), /cost (/budget + /usage instead). Dispatch is table-driven; slash commands are refused mid-turn except /exit and /btw. — _Evidence:_ `builtin-commands/` (64 files, 6 `withAliases`); `src/Commands/Specs/BuiltInCommands.php`
- why: detail listed /loop /schedule /hooks /review /status as absent.

### CMD-01 · candy-code-web
- current: `🟡 57 built-ins listed: 41 run server-side via `command.exec`, 16 refused `ui_only` (theme, keys, hel`
- new: 🟡 57 built-ins listed: 41 run server-side via `command.exec`, 16 refused `ui_only` (theme, keys, help, review, editor…)
- detail: `command.list` marks a built-in `runsIn:"server"` iff its spec has a host body. Terminal-only: new, sessions, effort, exit, theme, settings, keys, help, compress, newrule, pane, layout, editor, budget, review, security-review. — _Evidence:_ `candy-code/src/Protocol/Methods/CommandMethods.php:48-60,116-119`; `builtin-commands/*.php` (`withHostCommand`, 41 files)
- why: cell was 131 chars and the detail's server-run list predates compact/model/init/goal/grind/export/hooks/status/usage/schedules/loop.

### CMD-02 · candy-code
- current: `✅ Markdown files in ~/.candy-code/commands and <root>/.candy-code/commands; project overrides user f`
- new: ✅ ~/.candy-code/commands + <root>/.candy-code/commands (project wins; built-in override needs trust); foreign/bundle trees below
- detail: `CommandLoader::loadAll()` merges foreign (`.claude`/`.opencode`/bundle) < built-in < user < project. A project file replacing a built-in needs `trustedProjectCommands`; refusals and accepted shadows are reported at launch and file rows carry `[user]`/`[project]` badges. The 9 CONTROL_PLANE names are never file-overridable. — _Evidence:_ `src/Commands/CommandLoader.php:605-660`; `src/Commands/CommandRegistry.php:74`
- why: cell was 215 chars and omitted the foreign floor; detail carried a dated "remediated" tail.

### CMD-02 · candy-code-web
- current: `✅ Project/user command files are listed in `/` completion and run server-side exactly as typing `/na`
- new: ✅ Native, foreign and bundle command files list in `/` completion and run server-side; trusted built-in overrides are TUI-only
- detail: `command.list` and `SessionHost` both call `commandLoader->loadAll($root)` without the workspace's `projectCommandsTrusted`, so a trusted project's `undo.md` replaces `/undo` in the TUI but the web still runs the built-in. — _Evidence:_ `candy-code/src/Protocol/Methods/CommandMethods.php:131`; `candy-code/src/Host/SessionHost.php:241-244`; `src/Chat.php:1796-1800`; `src/Host/WorkspaceContext.php:79`
- why: "exactly as typing" is not true for trusted built-in overrides; the foreign floor also reaches the web.

### CMD-04 · candy-code
- current: `🟡 description, argument-hint live; model, subtask parsed but inert; no allowed-tools/agent keys (fla`
- new: 🟡 description, argument-hint live; opencode `template:` fills an empty body; model/subtask inert; allowed-tools/agent not enforced
- detail: `allowed-tools`, `disallowed-tools` and `agent` are KNOWN keys (no did-you-mean) but nothing acts on them; `model` and `subtask` are listed INERT and named in the launch row. Display fields are sanitized. — _Evidence:_ `src/Support/FrontmatterKeyAudit.php:63-76,88,132-135`; `src/Commands/CommandSpec.php` (`fromFile`, `template`)
- why: INT-3 made the Claude keys known, so "flagged unknown" is wrong.

### CMD-07 · candy-code
- current: `✅ "/" popup with fuzzy matching and highlighting over built-ins and custom commands; Tab completes`
- new: cell unchanged, detail only
- detail: `slashCommandRows()` merges built-in specs with the file map (a file row wins its name); file rows carry `[user]`/`[project]` tier and `[claude]`/`[opencode]`/`[bundle]` source badges. Skills are not listed. — _Evidence:_ `src/Chat.php:16153-16166`; `src/Renderer.php:6024-6025`; `src/Commands/CommandSpec.php:443-448`
- why: detail said the popup shows no tier and carried a dated "remediated" tail.

### CMD-08 · candy-code
- current: `🟡 Ctrl+P (Ctrl+K in shell) palette: 19 actions + provider/model/theme pickers; not every command, no`
- new: 🟡 Ctrl+P (Ctrl+K in shell): 42 of 57 slash commands + 7 palette-only rows + pickers; arg-taking and file commands absent
- detail: Palette rows are the 49 specs carrying a `paletteAction`; most dispatch through the generic `/<name>` tail. Fifteen slash commands stay slash-only by recorded judgement (effort, status, pane, usage, review, security-review, bg, schedules, loop, fork, websearch, btw, generate, sendto, play), and command files and MCP prompts never appear. — _Evidence:_ `src/Commands/CommandRegistry.php:200-209`; `src/Chat.php:17227`; `tests/Chat/PaletteSupersetDispatchTest.php:40-90`
- why: "19 actions" is stale and it is not a superset.

### CMD-09 · candy-code
- current: `✅ prompts/list merges commands (`/mcp__server__prompt`, McpPromptCommands)`
- new: cell unchanged, detail only
- detail: `McpPromptCommands::specs()` turns each server's `prompts/list` into `/mcp__server__prompt` rows merged under every disk file (project > user > MCP prompt) once at Chat construction; `prompts/get` runs in a forked child at submission, and a fetched body's `!cmd` needs `trustedProjectCommands`. TUI only. — _Evidence:_ `src/Chat.php:1785-1800,11101-11102`; `src/Host/TurnController.php:538-550,610-618`; `src/Commands/McpPromptCommands.php:49,71,130`
- why: detail still said ❌ with a dated "superseded" note.

### CMD-10 · candy-code
- current: `🟡 Bundle-supplied commands`
- new: 🟡 Bundle `commands/` trees import at the foreign floor, badged [bundle]; no /plugin:name namespace or code-registered commands
- detail: `ForeignCommandDiscovery::bundleDirs()` adds each bundle's `commands/` tree, walked first inside its tier so plain foreign trees win a name; the whole foreign floor loses to built-ins and native files. — _Evidence:_ `src/Commands/ForeignCommandDiscovery.php:110-155`; `src/Commands/CommandLoader.php:620-624`
- why: the cell said nothing about the limits; the detail said ❌.

### CMD-10 · candy-code-web
- current: `➖ No plugin system`
- new: 🟡 Inherited: bundle commands list as `file` rows in `/` completion and the palette and run server-side; no badge
- detail: The same loader feeds `command.list` and the served host. — _Evidence:_ `candy-code/src/Protocol/Methods/CommandMethods.php:131`; `candy-code-web/src-web/components/panels/CommandPalette.vue:48-60`
- why: ➖ is wrong once bundles exist.

### CMD-11 · candy-code-web
- current: `🟡 `!cmd` runs on the server, but `session.send` answers -32009 "refused" and its output rows emit no`
- new: ✅ `!cmd` runs on the server (deny rules and plan mode refuse); its running and output rows arrive as transcript events
- detail: `SessionHost::runBang()` answers `TurnTicket::handled()` (`admitted:"handled"`), marks the session busy, and announces the running notice and the result row as `message.created`. Output arrives at completion. — _Evidence:_ `candy-code/src/Host/SessionHost.php:1527-1567`; `candy-code/src/Protocol/Methods/TurnMethods.php:214-216`
- why: ISS-056 fixed the refusal and the missing events.

### CMD-12 · candy-code
- current: `🟡 The `keybindings` setting rebinds chords per declared binding id (launch-validated, collisions ann`
- new: 🟡 `keybindings` rebinds or unbinds (null) declared binding ids, `/keys reload` re-applies; slash/custom commands get no binding
- detail: Overrides accept Ctrl/Alt/Shift chords and lone function keys; a `null` value drops the shipped chord; `/keys reload` re-registers the map mid-session; collisions are announced and `/keys` shows effective rows. Binding ids name actions, not slash or file commands. — _Evidence:_ `src/Commands/KeyBindingRegistry.php:239-262,281`; `src/Chat.php:11947`; `src/Config/Settings/Validator/KeyBindingsValidator.php`
- why: TUI-4 shipped unbind and hot-reload; cell was 248 chars and says "no unbind, no hot-reload".

### CMD-N1 · candy-code
- current: `✅ `.claude/` and `.opencode/` commands are read (ForeignCommandDiscovery), merged at lowest preceden`
- new: cell unchanged, detail only
- detail: `ForeignCommandDiscovery` walks `.claude/commands` and `.opencode/command(s)` in the project and home (plus `~/.config/opencode/command(s)`), and bundle trees; `CommandLoader::loadAll()` merges them below built-ins, so `.claude/commands/exit.md` simply loses. opencode `template:` fills an empty body; Claude `allowed-tools`/`agent` load silently and are not enforced. — _Evidence:_ `src/Commands/ForeignCommandDiscovery.php:110-191`; `src/Commands/CommandLoader.php:605-624`; `src/Support/FrontmatterKeyAudit.php:63-76`
- why: detail said ❌ ("walks only the two .candy-code trees").

### CMD-N1 · candy-code-web
- current: `❌ Not supported by the engine (only .candy-code/commands trees are read); the `/` popup lists those `
- new: ✅ Inherited: foreign command files ride `command.list` as `file` rows and run server-side (no origin badge)
- detail: `command.list` describes file rows with `source:"file"` only, so the browser cannot tell a `.claude` import from a native file. — _Evidence:_ `candy-code/src/Protocol/Methods/CommandMethods.php:131,141-150`; `candy-code-web/src-web/components/Composer.vue:54-58`
- why: the engine reads the foreign trees.

### HK-01 · candy-code-web
- current: `🟡 Hooks run server-side for served sessions; the web has no hooks view`
- new: 🟡 Hooks run server-side for served sessions; server-run `/hooks` prints the chain as text; no hooks panel
- detail: `/hooks` has a host body (`HooksHostCommand`), so `command.exec` returns the same read-only table. — _Evidence:_ `builtin-commands/1390-hooks.php`; `candy-code/src/Host/Commands/HooksHostCommand.php:26-42`
- why: a text view exists.

### HK-02 · candy-code
- current: `✅ 12 events: Pre/PostToolUse, Stop, SubagentStop, SessionStart/End, UserPromptSubmit, Pre/PostCompac`
- new: cell unchanged, detail only
- detail: All 12 events have production dispatch sites; UserPromptSubmit and SessionStart also fire for `-p` and background sessions. Naming an unknown event in `hooks.yaml` stops the launch; an unknown event in a Claude settings file (e.g. `Notification`) is skipped and recorded in one launch notice. — _Evidence:_ `src/Hooks/HookEvent.php:31-74`; `src/Cli/NonInteractive.php:789-829`; `src/Background/BackgroundSessionRunner.php:634-662`; `src/Hooks/ClaudeSettingsHooks.php:193-201`
- why: detail said TUI/serve only for UserPromptSubmit/SessionStart.

### HK-06 · candy-code
- current: `🟡 ~/.candy-code/hooks.yaml always; project hooks.yaml + `.claude/settings.json` hooks only when thei`
- new: 🟡 User hooks.yaml + ~/.claude/settings.json; project hooks.yaml + .claude/settings(.local).json only if trusted; no managed tier
- detail: `hookFiles()` reads `~/.candy-code/hooks.yaml` and `~/.claude/settings.json` always (both must not be world-writable or foreign-owned), and the project's `.candy-code/hooks.yaml`, `.claude/settings.json` and `.claude/settings.local.json` only under `trustedProjectHooks`. Files add, never override. New project entries are quarantined for a launch after the user's policy changed. — _Evidence:_ `src/Cli/Bootstrap.php:6238-6262,6022-6040,6109-6136`; `src/Hooks/ClaudeSettingsHooks.php:82`
- why: cell omitted `~/.claude/settings.json` and `settings.local.json` and was 142 chars.

### HK-07 · candy-code
- current: `✅ — Claude-shaped JSON payload rides stdin as well as env, and `.claude/settings.json` hooks are rea`
- new: 🟡 Reads .claude settings `hooks` + CC stdin JSON/envelope; exit 1 denies; non-command types or extra keys refuse the launch
- detail: `ClaudeSettingsHooks` flattens `hooks` into `cc:{event}:{index}` rows through `HookConfig::parse()`. Scripts get six stdin keys (session_id, cwd, hook_event_name, tool_name, tool_input, tool_response) plus `CRUSH_*` env, and the JSON envelope is honoured. Divergences: exit 1 denies (Claude Code proceeds); unknown events are skipped and recorded; any `type` other than `command`, any entry key beyond type/command/timeout (`async`, `statusMessage`, `if`…) or group key beyond matcher/hooks throws, and `hooks()` turns that into a refused launch (exit 2) — including from `~/.claude/settings.json`. — _Evidence:_ `src/Hooks/ClaudeSettingsHooks.php:95,98,193-201,256-262`; `src/Hooks/ScriptHook.php:631-654,1067-1078`; `src/Cli/Bootstrap.php:5957-5970`
- why: the old detail still said ❌/stdin closed; the cell overstates parity given launch-refusing entry keys and exit-1 semantics.

### HK-10 · candy-code
- current: `🟡 `/hooks` is a read-only browser; no edit UI`
- new: cell unchanged, detail only
- detail: `/hooks` (spec 1390, palette row) prints every registered hook by event with matcher, disabled/built-in state and unevaluable matchers, read from `HookManager::inspect()`; it never registers, disables or runs a hook. — _Evidence:_ `builtin-commands/1390-hooks.php`; `src/Commands/HooksCommand.php:10-60`; `src/Host/Commands/HooksHostCommand.php:26-42`
- why: detail said ❌ "not among the 55 spec files".

### HK-10 · candy-code-web
- current: `❌ No hooks management UI`
- new: 🟡 Server-run `/hooks` prints the chain (event, matcher, state) as text; no panel or edit UI
- detail: Same host body as the TUI over `command.exec`; the web palette offers it as a no-argument command. — _Evidence:_ `candy-code/src/Host/Commands/HooksHostCommand.php:26-42`; `candy-code-web/src-web/components/panels/CommandPalette.vue:48-60`
- why: matches Claude Code's own 🟡 read-only browser level.

### MCP-01 · candy-code-web
- current: `🟡 Inherited: a trusted `.mcp.json` stdio server starts server-side; invisible in the web`
- new: 🟡 Inherited: stdio servers start server-side; the Context panel lists project `.mcp.json` servers (no liveness, no user tier)
- detail: Served sessions inherit the launch's stdio client. The web Context panel (`context.sources`) lists each declared project server with its transport and a `trusted` badge read from the config census, not from liveness, and drops the user-tier rows the census computes. — _Evidence:_ `candy-code-web/src-web/components/panels/ContextPanel.vue:144-158`; `candy-code/src/Host/ContextSources.php:147-173`; `candy-code/src/Cli/McpDecision.php:295-340`
- why: "invisible in the web" is false since the WEB-3 Context panel.

### MCP-02 · candy-code-web
- current: `🟡 Inherited; invisible`
- new: 🟡 Inherited; the Context panel badges a project server's `sse` transport, nothing more
- detail: Same census row as MCP-01: name + transport + trust badge; no stream state. — _Evidence:_ `candy-code-web/src-web/components/panels/ContextPanel.vue:150-155`; `candy-code/src/Host/ContextSources.php:162-171`
- why: transport is now shown.

### MCP-03 · candy-code
- current: `🟡 — Body buffered and no GET stream; HTTP timeouts are configurable per call instead of the old fixe`
- new: 🟡 POST replies are buffered whole and no GET stream is opened; per-entry `toolTimeout` (default 120 s) plus a 10 s connect bound
- detail: `HttpMcpServer` POSTs every message with `Accept: application/json, text/event-stream`, reads the reply buffered (not `stream => true`, measured hostile), re-initialises when a session-bearing request gets 404, sends a session DELETE on stop, never follows redirects, and advertises protocol 2024-11-05. No standalone GET listener exists, so server-pushed notifications/requests outside a reply are never received. — _Evidence:_ `src/MCP/HttpMcpServer.php:14-17,42,84,92,304-322,558-575,700-735`
- why: "instead of the old fixed 30 s total" is history voice; buffering/no-GET verified.

### MCP-03 · candy-code-web
- current: `🟡 Inherited; invisible`
- new: 🟡 Inherited; the Context panel badges a project server's `http` transport, nothing more
- detail: As MCP-02. — _Evidence:_ `candy-code-web/src-web/components/panels/ContextPanel.vue:150-155`
- why: transport is now shown.

### MCP-04 · candy-code
- current: `✅ — A 401 raises a typed auth-required failure; `/mcp login` forks the browser PKCE flow itself and `
- new: ✅ PKCE loopback login with scopes + refresh (`mcp auth login`; TUI `/mcp login` forks it); a 401 is a typed auth-required failure
- detail: `candy-code mcp auth login <server>` runs PKCE against a 127.0.0.1 loopback listener (300 s human-wait bound), supports scopes, stores tokens in `~/.local/share/candy-code/mcp-auth.json` and refreshes ahead of expiry; no RFC 8707 `resource` parameter. In the TUI `/mcp login <server>` forks that CLI arm (`McpLoginFork`) and announces the URL; `/mcp reconnect` restarts servers behind a config-drift gate. The shipped notice says tokens attach at the next launch, although `HttpMcpServer::requestHeaders()` re-reads the store per request (stat-signature cache) — live same-session pickup UNVERIFIED. — _Evidence:_ `src/MCP/OAuthLoopbackFlow.php:51,215-239`; `src/Chat.php:19810-19843`; `src/Support/McpLoginFork.php:12-28`; `src/MCP/HttpMcpServer.php:962-975`; `src/MCP/OAuthClientRegistration.php:365-386,904-913`; `lang/en.php:1257`
- why: 154 chars, and "`/mcp reconnect` re-attaches (token lands next launch)" contradicts itself.

### MCP-04 · candy-code-web
- current: `❓ `/mcp` is server-run; whether `/mcp auth` OAuth can complete from a browser was not verified`
- new: 🟡 Inherited tokens attach server-side, but served `/mcp login` only prints the shell command; the login fork is TUI-only
- detail: A served `/mcp` runs `McpAuthHostCommand` → `McpAuthCommand::execute()`, whose `login` arm prints the `candy-code mcp auth login …` guidance; only `Chat::handleMcpAuthCommand` intercepts `login <server>` to fork the flow, and the listener binds 127.0.0.1 on the server host, so a remote browser cannot finish it. Tokens stored by a host-side CLI login do ride served http servers. — _Evidence:_ `src/Host/Commands/McpAuthHostCommand.php:19-26`; `src/Commands/McpAuthCommand.php:167-173,308-313`; `src/Chat.php:19810-19820`; `lang/en.php:1264`
- why: ❓ settled by reading the dispatch.

### MCP-06 · candy-code
- current: `✅ listResources/readResource; `@mcp:` resource mentions feed context`
- new: ✅ `@mcp:<server>:<uri>` mentions attach text resources (fenced as data, 256 KiB clip) in the TUI; serve and `-p` refuse them
- detail: `McpClient::listResources()/readResource()` over the stdio/http/sse providers; `ContextMentions` resolves `@mcp:<server>:<uri>` at submit, framed as untrusted data, binary refused, text clipped at 256 KiB, saved with the transcript. Only Chat passes the MCP reader into `TurnController::userTurnMessage()`; served sessions and headless runs build the scanner without it and meet the refusal notice. — _Evidence:_ `src/Attachments/ContextMentions.php:113-155,398-414`; `src/Chat.php:9925-9935`; `src/Host/SessionHost.php:1702-1710`; `docs/MCP.md:824-845`
- why: TUI-only scope missing; Appendix still says ❌ + dated superseded note.

### MCP-07 · candy-code
- current: `✅ prompts/list + prompts/get drive `/mcp__server__prompt` commands`
- new: ✅ `prompts/list` rows become `/mcp__server__prompt` commands (`prompts/get` on Enter, disk files win); TUI only
- detail: `McpPromptCommands::specs()` turns the launch-frozen `prompts/list` roster into file-shaped specs merged into Chat's command map (project-file > user-file > mcp-prompt); `prompts/get` runs at submission; `` !`cmd` `` inside a fetched body needs `trustedProjectCommands`. SessionHost reads only `CommandLoader` rows, so served sessions have no MCP prompt commands. — _Evidence:_ `src/Commands/McpPromptCommands.php:8-40`; `src/Chat.php:1796,11101-11102`; `src/Host/TurnController.php:539-552`; `src/Host/SessionHost.php:241-244`
- why: scope qualification; Appendix still ❌ + superseded note.

### MCP-08 · candy-code-web
- current: `❓ Not verified; the protocol has no elicitation event, so the web could only show it as a permission`
- new: ❌ Engine has none, and the protocol carries no elicitation or sampling event
- detail: Nothing to surface: every transport advertises empty client capabilities and skips server-initiated frames (MCP-08 candy-code). — _Evidence:_ `candy-code-web/src-web/protocol/generated.ts:1829-1865` (EVENT_KINDS has no elicitation/sampling event)
- why: engine absence is settled, so ❓ is no longer warranted.

### MCP-09 · candy-code
- current: `✅ User scope `~/.candy-code/mcp.json` + project `<root>/.mcp.json` (trustedProjectMcp, per-server fi`
- new: ✅ User `~/.candy-code/mcp.json` + project `.mcp.json` (trustedProjectMcp, per-server pins; project wins); no local/managed tier
- detail: Two tiers: the user file starts without repo trust (owner-checked home path); the project `.mcp.json` needs `trustedProjectMcp` plus per-server fingerprint pins; the project wins a shared name. `disabledMcpServers` globs, `mcp.enabled` and `CANDY_CODE_MCP_DISABLE` switch servers off at launch. — _Evidence:_ `src/Cli/McpDecision.php:61-81,123-136`; `src/Cli/Bootstrap.php:7703,7716,8015-8021`; `src/MCP/McpTrustPins.php:34,51`
- why: 140 chars; Appendix still 🟡 + superseded note.

### MCP-09 · candy-code-web
- current: `🟡 Inherited`
- new: 🟡 Inherited; an untrusted project `.mcp.json` can be granted from the Context panel's trust prompt (applies next launch)
- detail: `context.sources` mints a server-scope `permission.asked` with `kind:"trust"` when the project census is untrusted; `context.trust` writes the user's `trustedProjectMcp` and records pins via `Bootstrap::trustProjectMcp()`. — _Evidence:_ `src/Protocol/Methods/ContextMethods.php:48-75`; `src/Protocol/TrustAsks.php:33-34,120-124`; `lang/en.php:1125`
- why: WEB-3b trust prompt not reflected.

### MCP-10 · candy-code-web
- current: `🟡 `/mcp` (server-run) prints the project config status as text; no status/reconnect UI`
- new: 🟡 Context panel lists project servers (transport, trust; no liveness/user tier); `/mcp` verbs answer as text
- detail: The panel reads the config census, not `mcpLivenessSnapshot()`, and keeps only project rows. `/mcp list|add|remove|reconnect` run through `McpAuthHostCommand` and print transcript text; `login` prints shell guidance. — _Evidence:_ `src/Host/ContextSources.php:147-173`; `candy-code-web/src-web/components/panels/ContextPanel.vue:144-158`; `src/Host/Commands/McpAuthHostCommand.php:19-26`
- why: WEB-3 Context panel shipped; old detail ("Status: none") is stale.

### MCP-11 · candy-code
- current: `✅ — A preset's `mcpServers` allowlist is enforced with or without a `tools:` list (empty `tools: []``
- new: 🟡 `mcpServers` allowlist strips a preset's `mcp__` bridges, but `mcp_call`/`mcp_list` still reach every deferred server
- detail: `AgentManager::resolveGrantedTools()` removes `McpToolBridge` instances whose server is outside the preset's `mcpServers` (with or without `tools:`). The deferred dispatchers are not filtered: `McpCallTool` calls `McpClient::callTool()` on the launch client, which production builds unrestricted with no preset, so `isServerAllowed()` passes any non-denied server; `McpClient::setAgentPreset()` has no caller. Static read, no test run. — _Evidence:_ `src/Agents/AgentManager.php:1503-1533,1665`; `src/Tools/BuiltIn/McpCallTool.php:131-156`; `src/MCP/McpClient.php:178-181,861-870,888-899`; `src/Cli/Bootstrap.php:8015-8030`
- why: the ✅ claim does not hold for servers past the lazy floor; Appendix carries dated remediation clutter.

### MCP-12 · candy-code
- current: `✅ Deferred loading by default: McpToolShape token floor + `mcp_list`/`mcp_call` discovery tools; `ea`
- new: ✅ Servers whose schemas pass `mcp.lazySchemaTokens` (8000) sit behind `mcp_list`/`mcp_call`; small or `eager: true` stay bridged
- detail: `McpToolShape::derive()` splits the launch roster once per memo key: per server, schemas under the floor keep first-class `mcp__server__tool` bridges; larger servers are reached only through `mcp_list` (roster from the frozen descriptors) and `mcp_call`; the pair is declared only when something was deferred. Deny rules bind both spellings. — _Evidence:_ `src/MCP/McpToolShape.php:14-37,58-98`; `src/Cli/Bootstrap.php:7724,7731,8325-8330`; `src/Permissions/PermissionRule.php:530-536`
- why: "by default" overstates it — a typical small config is never deferred.

### MCP-14 · candy-code
- current: `✅ — Embedded resource and image/audio parts render with their payloads (text bodies inline, bytes to`
- new: ✅ 64 KiB cap (`mcpResultCapBytes`); embedded resource text inline (8 KiB); image/audio/blob parts named by mime and size only
- detail: `McpToolBridge::renderPart()` inlines an embedded resource's text up to `MAX_RESOURCE_TEXT_BYTES` (8192) and announces image/audio/blob parts as `[image: <mime>, N base64 bytes not shown]`; no image reaches the vision channel. The whole render is clipped at 65,536 bytes unless `mcpResultCapBytes` overrides. — _Evidence:_ `src/Tools/McpToolBridge.php:111,598-606,815-863,866-910`; `src/Tools/Concerns/TruncatesOutput.php:155`; `src/Tools/ToolLimits.php:276-279`
- why: "bytes to the image channel" is false; 166 chars.

### MCP-N1 · candy-code-web
- current: `🟡 Inherited pins for the server's MCP launches; a changed server silently stays off (`/notices` only`
- new: 🟡 Inherited pins; an untrusted project can be granted from the Context panel, but a changed server needs CLI `mcp trust`
- detail: The WEB-3b trust prompt fires only while the census says the project is untrusted; once trusted, a pin mismatch leaves that server off with a `/notices` line and no browser path to re-approve. — _Evidence:_ `src/Protocol/Methods/ContextMethods.php:61-75`; `src/Cli/McpDecision.php:343-350`
- why: old detail says a browser user cannot re-approve at all.

### PLG-01 · candy-code
- current: `🟡 Bundles: Claude-format plugin dirs import as skills/commands/agents; hooks declined at the wire; f`
- new: 🟡 Claude-format bundles (`.claude-plugin/plugin.json`) add skills, commands and agents via existing loaders; hooks/MCP declined
- detail: `BundleDiscovery` scans `<root>/.candy-code/bundles` (project tier), `~/.candy-code/bundles` and `pluginPaths`; `Bootstrap::bundles()` feeds `CommandLoader::useBundles()`, `SkillManager::loadAll()` and the foreign agent preset registry at the lowest precedence seat, badged `[bundle]`. Bundle hooks are never registered and a bundle `.mcp.json` is never auto-started (launch notice names both). — _Evidence:_ `src/Cli/Bootstrap.php:1764,2240-2300,2831,4955`; `src/Skills/SkillSource.php:22,34`; `docs/PLUGINS.md:49-93`
- why: "file-based surfaces as before" is history voice; MCP decline omitted; Appendix says ❌ —.

### PLG-01 · candy-code-web
- current: `❌ No web plugin/extension mechanism`
- new: 🟡 Inherited: bundle commands reach `/` completion and bundle skills/agents load server-side; no web-side extension mechanism
- detail: The served workspace's `CommandLoader` carries the bundles, and both `command.list` and SessionHost read it. — _Evidence:_ `src/Protocol/Methods/CommandMethods.php:131`; `src/Host/SessionHost.php:241-244`; `src/Cli/Bootstrap.php:1764`
- why: bundle content does reach served sessions.

### PLG-03 · candy-code-web
- current: `➖ No plugins`
- new: 🟡 Inherited: bundle skills, commands and agents apply to served sessions
- detail: As PLG-01 candy-code-web. — _Evidence:_ `src/Cli/Bootstrap.php:1764,4955`
- why: "No plugins" is false.

### PLG-08 · candy-code-web
- current: `✅ Consumes the server event stream (34 event types) with gap-free per-session `seq` cursors`
- new: ✅ Consumes the server event stream (35 event types) with gap-free per-session `seq` cursors
- detail: cell unchanged otherwise; `EVENT_KINDS` lists 35 names including the ephemeral `tool.progress`. — _Evidence:_ `candy-code-web/src-web/protocol/generated.ts:1829-1865`
- why: count drift.

### EDT-01 · candy-code-web
- current: `🟡 Inherited; invisible`
- new: 🟡 Inherited; the Edit card's output names the stage that matched
- detail: Hook- and matcher-annotated result text rides `tool.finished` and the card prints it under the diff. — _Evidence:_ `src/Tools/BuiltIn/Edit.php:108`; `candy-code-web/src-web/components/ToolCard.vue:39,81-82`
- why: not invisible.

### EDT-03 · candy-code-web
- current: `🟡 — Inherited; permission cards still show raw JSON tool arguments`
- new: 🟡 Edit/Write asks show a client-side diff of the echoed strings (no file context); ApplyPatch asks stay raw JSON
- detail: `askDiffPreview()` diffs `old_string`→`new_string` (or each `edits[]` pair) and shows Write content as a whole-file add, failing soft to the JSON dump; the server's dry-run `previewDiff()` is not sent. — _Evidence:_ `candy-code-web/src-web/components/PermissionCard.vue:32-37,112-114`; `candy-code-web/src-web/lib/lineDiff.ts:36-76`
- why: WEB-5a-ii shipped; "raw JSON" is stale.

### EDT-04 · candy-code-web
- current: `🟡 Inherited; invisible`
- new: 🟡 Inherited; the format report rides the Edit card's output
- detail: PostToolUse context is folded into the result before `ToolFinished`, which the card prints. — _Evidence:_ `src/Runtime.php:3405-3440`; `candy-code-web/src-web/components/ToolCard.vue:39,81-82`
- why: not invisible.

### EDT-05 · candy-code-web
- current: `🟡 Inherited; invisible`
- new: 🟡 Inherited; the lint report rides the Edit card's output
- detail: As EDT-04. — _Evidence:_ `src/Runtime.php:3405-3440`; `candy-code-web/src-web/components/ToolCard.vue:81-82`
- why: not invisible.

### EDT-06 · candy-code-web
- current: `🟡 Inherited; invisible`
- new: 🟡 Inherited; LSP errors ride the Edit card's output
- detail: As EDT-04. — _Evidence:_ `src/Cli/Bootstrap.php:5869-5872`; `candy-code-web/src-web/components/ToolCard.vue:81-82`
- why: not invisible.

### EDT-08 · candy-code
- current: `✅ RepoMap tool: PageRank over symbol references (PHP tokenizer, Universal Ctags for other languages)`
- new: ✅ RepoMap tool: PageRank over symbol references (PHP tokenizer; Universal Ctags otherwise), token-budgeted and cached
- detail: cell unchanged in substance; detail unchanged. — _Evidence:_ `src/Tools/BuiltIn/RepoMapTool.php:54`
- why: 131 chars (limit 130).

### EDT-09 · candy-code-web
- current: `❓ Engine question; no protocol event for external file changes`
- new: 🟡 Inherited: served turns list read files changed on disk; no live watcher, no protocol event
- detail: `EngineBackend` adds `ReadLedger::notice()` to every turn's context block, served turns included; there is no file-change event and no AI-comment watch in serve. — _Evidence:_ `src/Backend/EngineBackend.php:1841`; `src/Tools/ReadLedger.php:230,266`
- why: engine question settled.

### EDT-10 · candy-code
- current: `🟡 Atomic writes keep mode/owner/hardlinks; fuzzy stages keep CRLF but exact match inserts LF into CR`
- new: 🟡 Atomic writes keep mode/owner/hardlinks; Edit, Write and ApplyPatch keep a pure-CRLF file CRLF; no BOM handling
- detail: `IndentShift::inheritEndings()` converts a replacement's bare LF to CRLF when the file is purely CRLF — the exact stage (`EditMatcher`) and Write overwrite both call it; ApplyPatch uses `normalizeEndings()` on the assembled file. Mixed or LF files are written as given. No BOM detection or preservation exists in the edit path. — _Evidence:_ `src/Tools/Edit/IndentShift.php:59-100`; `src/Tools/Edit/EditMatcher.php:111`; `src/Tools/BuiltIn/Write.php:294-300`; `src/Tools/BuiltIn/ApplyPatch.php:388`; `src/Support/AtomicFileWriter.php`
- why: ISS-062 fixed the exact-match LF injection; cell and Appendix repro are stale.

### EDT-N1 · candy-code-web
- current: `🟡 Inherited when the server user's config enables autoTest + testCommand; both are exec-class, so re`
- new: 🟡 Inherited when the server user's config sets autoTest + testCommand (exec-class keys, read-only in the web Settings form)
- detail: unchanged except evidence: `Bootstrap::hooks()` registers AutoTestEditHook + AutoTestHook. — _Evidence:_ `src/Cli/Bootstrap.php:5908-5918`; `src/Config/Settings/Definitions/ToolSettings.php:93-108`
- why: 132 chars.

### MCP-02 · candy-code
- current: `✅ SSE transport supported (SseMcpServer, origin-locked; redirects refused)`
- new: cell unchanged, detail only
- detail: `SseMcpServer` speaks the 2024-11-05 HTTP+SSE transport: one GET event stream on a raw deadline-bounded socket, POSTs via Guzzle; the server's `endpoint` event must share the configured URL's origin or is refused before any POST; a dropped stream fails the in-flight call and retries a bounded backoff burst; `type: "sse"` entries (Claude dialect) route here. — _Evidence:_ `src/MCP/SseMcpServer.php:13-60`; `src/MCP/McpClient.php:760-775`
- why: Appendix bullet still says ❌ with a dated "superseded" note.

### MCP-08 · candy-code
- current: `❌ No sampling, elicitation, roots, logging or list_changed. Server-initiated requests (even ping) are`
- new: cell unchanged, detail only
- detail: All three transports send `capabilities: {}` in `initialize`; the stdio read loop skips method-bearing frames (server requests and notifications) while waiting for its reply id, so a server `ping`/`roots/list`/`elicitation/create` is never answered. — _Evidence:_ `../sugar-mcp-client/src/StdioMcpServer.php:408-414,1142-1156`; `src/MCP/HttpMcpServer.php:525-527`; `src/MCP/SseMcpServer.php:583`
- why: evidence lines drifted (399/1143 → 413/1150).

### MCP-10 · candy-code
- current: `🟡 /mcp list/add/remove/login/reconnect + McpPanel liveness; no runtime enable/disable`
- new: cell unchanged, detail only
- detail: `/mcp` has five verbs; `list` opens with the project-inventory panel (declared servers, up/down, tool/resource counts, config-changed-since-launch) then the auth table; `reconnect` stops/starts the session client only when the config is unchanged and reports tool-roster drift without re-deriving it; `login <server>` forks the CLI flow. Enabling/disabling a server needs `disabledMcpServers` and a relaunch. — _Evidence:_ `src/Commands/McpAuthCommand.php:163-300,322-350`; `src/Tui/McpPanel.php:82-100`
- why: Appendix carries a dated superseded note and the stale `--root` ISSUES remark.

### MCP-13 · candy-code
- current: `❌ Does not act as an MCP server (the in-process `type: git` server serves candy-code itself; serve an`
- new: cell unchanged, detail only
- detail: No `mcp serve` verb exists (`mcp` verbs are list/auth/import/trust); docs/MCP.md describes `GitMcpServer` correctly as an in-process `type: git` entry the client uses. — _Evidence:_ `src/Cli/Subcommands.php:1952-1965,2587`; `docs/MCP.md:885-887`
- why: "README and MCP.md overclaim a server mode (ISSUES)" no longer holds.

### PLG-02 · candy-code
- current: `🟡 `bundles install` (git/path); no registry or marketplace`
- new: cell unchanged, detail only
- detail: `candy-code bundles list|install <path|git-url>`; install refuses a manifest-less dir, never overwrites, and clones with `git -c protocol.ext.allow=never clone --depth 1 --no-tags` as a contained child into `~/.candy-code/bundles/<name>` only. No index, search, update or marketplace. — _Evidence:_ `src/Cli/Subcommands.php:1464-1520,1616,1726-1750`; `docs/PLUGINS.md:95-113`
- why: Appendix says `❌ — —`, contradicting the 🟡 cell.

### PLG-03 · candy-code
- current: `🟡 Bundles add skills, commands and agents (not hooks, MCP or themes)`
- new: cell unchanged, detail only
- detail: Surfaces map onto the existing loaders at the lowest precedence seat; hooks are declined by design, a bundle `.mcp.json` is offered only through `mcp import` (prints, writes nothing); no themes, providers or tools. — _Evidence:_ `src/Cli/Bootstrap.php:2253-2262`; `docs/PLUGINS.md:72-93`
- why: Appendix says `➖ — —`.

### PLG-04 · candy-code
- current: `🟡 Embedders can pass PHP Tool objects (EngineBackend::withTools, Chat::registerTool); shipped binary`
- new: cell unchanged, detail only
- detail: The catalog is the attribute scan of `src/Tools/BuiltIn`; `EngineBackend::withTools()` and `Chat::registerTool()` are embedder seams with no caller in the shipped binary (`registerTool` adds a callback without a schema, so the model sees it only if the embedder's backend advertises it). — _Evidence:_ `src/Backend/EngineBackend.php:792`; `src/Chat.php:8899-8916`; `src/Tools/Catalog/ToolCatalog.php`
- why: evidence `Chat.php:10546` / `EngineBackend.php:741` drifted.

### PLG-05 · candy-code-web
- current: `🟡 Generated TS protocol types + `CandyCodeClient` (reconnect, resume, idempotent resend) are reusab`
- new: cell unchanged, detail only
- detail: `generated.ts` (1,865 lines) is generated from the PHP JSON Schema; `CandyCodeClient` lives in `protocol/client.ts`; the package is `"private": true`. — _Evidence:_ `candy-code-web/src-web/protocol/generated.ts`; `candy-code-web/src-web/protocol/client.ts:76`; `candy-code-web/package.json:4`
- why: line count 1,586 → 1,865.

### PLG-N1 · candy-code
- current: `❌ No plugin/mod system; extension is file-based (hooks, commands, skills, agents, MCP); `statusLine``
- new: cell unchanged, detail only
- detail: Bundles are read as content, never loaded as code. Hooks are out-of-process scripts that can deny, ask or rewrite tool input but not draw UI; `statusLine` runs a command whose output fills a status-bar segment; `Chat::registerTool` is an embedder seam with no `bin/` caller. Nothing adds a pane, rewrites TUI events or registers a tool in-process. — _Evidence:_ `src/Chat.php:8899-8916`; `src/Config/Settings/Definitions/InterfaceSettings.php:65-72`; `docs/PLUGINS.md:1-10`
- why: "There is no plugin loader" predates the bundle reader; evidence drifted.

### EDT-02 · candy-code
- current: `🟡 Stale-file refusal by content hash in Edit, Write and ApplyPatch, shared across forks; no read-befo`
- new: cell unchanged, detail only
- detail: `ReadLedger` hashes what Read saw and what Edit/Write/ApplyPatch (and the post-edit formatter) wrote; a later write to a file whose bytes changed is refused with "Read it again"; ≤256 entries, merged across forks; a never-read file is never refused. — _Evidence:_ `src/Tools/ReadLedger.php:33-37,80,163,230`; `src/Tools/BuiltIn/Edit.php:501`; `src/Tools/BuiltIn/Write.php:281`; `src/Hooks/BuiltIn/PostEditFormatHook.php:117-120`
- why: evidence drifted; formatter recording missing.

### EDT-03 · candy-code
- current: `✅ — The approval modal previews real diffs (dry-run against disk via `BuildsUnifiedDiff`), shows full`
- new: cell unchanged, detail only
- detail: The approval modal calls `Edit/Write/ApplyPatch::previewDiff()` — a dry run against the file on disk — and shows the unified diff with full arguments; `v` expands tall content; after the write the tool row renders the diff. — _Evidence:_ `src/Renderer.php:7255-7262`; `src/Tools/BuiltIn/Edit.php:573`; `src/Tools/BuiltIn/Write.php:406`; `src/Tools/BuiltIn/ApplyPatch.php:679`
- why: Appendix keeps the pre-fix 80-char-clip text plus a dated remediation note.

### EDT-04 · candy-code
- current: `🟡 Opt-in auto-format after edits (`formatCommands` + PostEditFormatHook, before lint feedback); defa`
- new: cell unchanged, detail only
- detail: User-tier `formatCommands` (extension → command) builds a `FormatRunner`; when non-empty, `PostEditFormatHook` registers before the lint hook, hashes bytes before the run, restores on a corrupting result, and records the formatted bytes into `ReadLedger` so they never read as stale. No built-in formatter table. — _Evidence:_ `src/Cli/Bootstrap.php:5834-5848`; `src/Format/FormatRunner.php:35-42,206`; `src/Hooks/BuiltIn/PostEditFormatHook.php:117-120`
- why: Appendix says ❌ "No formatter setting or hook".

### EDT-05 · candy-code
- current: `✅ Post-edit lint: `php -l` by default plus a user-tier `lintCommands` map per extension; the report i`
- new: cell unchanged, detail only
- detail: PostToolUse for Write/Edit/ApplyPatch via the bounded spawn path, 30 s per lint, never refuses; a quoted `"{file}"` placeholder is unquoted and the path `escapeshellarg`ed, so it is not injectable. — _Evidence:_ `src/Cli/Bootstrap.php:5857-5861`; `src/Lint/LintRunner.php:22-27,65,149-153,215-216`
- why: "A quoted `{file}` placeholder makes it injectable (ISSUES P2)" is fixed (ISS-011).

### EDT-07 · candy-code
- current: `🟡 Hand-written `lsp` config plus opt-in auto-detect (`lspAutoDetect`, default off, AUTO_TABLE probe)`
- new: cell unchanged, detail only
- detail: `LspLauncher` starts the hand-written `lsp` map; with user-tier `lspAutoDetect` it consults `AUTO_TABLE` fingerprints (composer/php→intelephense, tsconfig→typescript, pyproject→pyright, go.mod→gopls, Cargo.toml→rust-analyzer) gated on binary presence; explicit entries win; ≤8 servers, 2 s handshake each; nothing is downloaded. — _Evidence:_ `src/LSP/LspLauncher.php:27-28,83,95,107,213-237`; `src/Cli/Bootstrap.php:8456-8459`
- why: Appendix says ❌.

### EDT-N2 · candy-code
- current: `✅ opt-in `watchFiles`: `AI!`/`AI?` comments saved mid-session become prompts (Aider-style)`
- new: cell unchanged, detail only
- detail: unchanged text; evidence re-pointed. — _Evidence:_ `src/Support/AiCommentWatcher.php:9-75`; `src/Chat.php:18283-18288`
- why: `Chat.php:18472-18477` drifted.

### TL-04 · candy-code-web
- current: `🟡 No MultiEdit in the server's tool list; ApplyPatch covers batch edits and renders as a card + diff`
- new: ✅ Edit `edits` batches and ApplyPatch run server-side; cards show the +N −M diff and the ask card previews every hunk
- detail: Served sessions build the same tool roster as the TUI, so Edit's all-or-nothing `edits` list and ApplyPatch both run; `tool.finished` carries the result diff (DiffView + stats) and the Edit permission card mints one diff per `edits` pair client-side, falling back to raw JSON. — _Evidence:_ `candy-code/src/Cli/LaunchContext.php:2010-2022` (served `backendFor` → `tools()`); `candy-code/src/Tools/BuiltIn/Edit.php:137-146`; `candy-code/src/Host/TranscriptProjector.php:279`; `candy-code-web/src-web/lib/lineDiff.ts:36-52`; `candy-code-web/src-web/components/ToolCard.vue:21,81`
- why: Edit's `edits` list IS the multi-edit and it reaches served sessions; "no MultiEdit" judged the wrong name.

### TL-05 · candy-code
- current: `✅ ApplyPatch: Codex/opencode `*** Begin Patch` format (add/update/move/delete), hunks found by content, all-or-nothing with`
- new: ✅ ApplyPatch: Codex/opencode `*** Begin Patch` (add/update/move/delete), hunks found by content, all-or-nothing rollback
- detail: cell unchanged in substance; Appendix A detail stays as is.
- why: current cell is 132 chars (>130 limit).

### TL-08 · candy-code-web
- current: `❌ No dedicated list-directory tool in the server's tool list (the human-only `fs.listDirs` picker is not a model tool)`
- new: ✅ Ls runs server-side in served sessions (same tool roster); generic card
- detail: `Ls` is catalog-built with no launch condition, and served sessions take their tools from the same `LaunchContext::tools()` → `ToolCatalog::build()` chain as the TUI, so the model has it in every served turn; the browser shows it as a generic tool card. — _Evidence:_ `candy-code/src/Tools/BuiltIn/Ls.php:61,102-104`; `candy-code/src/Cli/LaunchContext.php:1681,2010-2022`
- why: Ls shipped (position 36) and reaches served sessions.

### TL-09 · candy-code-web
- current: `✅ Bash card with command, output and exit (output only once finished)`
- new: ✅ Bash card with command, output and exit; a live tail streams into the card while it runs (`tool.progress`)
- detail: Bash binds the turn's `tool.progress` emitter and sends a frame-capped tail of its output while it runs; the server relays it as a non-durable session event and ToolCard paints it as a "live tail" `<pre>` until `tool.finished` replaces the card. — _Evidence:_ `candy-code/src/Tools/BuiltIn/Bash.php:51,117-119,530-534`; `candy-code/src/Host/TranscriptProjector.php:421`; `candy-code-web/src-web/protocol/generated.ts:1764,1856`; `candy-code-web/src-web/stores/reducer.ts:509-514`; `candy-code-web/src-web/components/ToolCard.vue:44,69-74`
- why: "output only once finished" is stale since `tool.progress` shipped.

### TL-10 · candy-code-web
- current: `🟡 PtyRun exists server-side; no live output in the web (nothing streams before `tool.finished`)`
- new: ✅ Inherited: run_in_background, ShellOutput and ShellStop run server-side as cards; no shell list or kill control in the UI
- detail: Served sessions get Bash `run_in_background` plus ShellOutput/ShellStop because `tools()` threads the launch's BackgroundShellRegistry into the catalog build; their calls render as ordinary cards, and foreground Bash streams a live tail. The registry is one table per server process and workspace root (`forSession('main')`), so concurrent served sessions in one root share it. — _Evidence:_ `candy-code/src/Cli/LaunchContext.php:690-694,1708`; `candy-code/src/Cli/Bootstrap.php:8396-8406`; `candy-code/src/Tools/BuiltIn/ShellOutput.php:50,66`; `candy-code/src/Tools/BuiltIn/ShellStop.php:56,72`
- why: the cell describes the pre-SHL era (PtyRun only, no streaming); the background-shell tools exist server-side.

### TL-16 · candy-code
- current: `✅ Lsp tool: definition/references/hover/symbols/code-actions/diagnostics; read-only class; needs a manual `lsp` server config`
- new: ✅ Lsp tool: definition/references/hover/symbols/code-actions/diagnostics; read class; `lsp` config or opt-in `lspAutoDetect`
- detail: Zero-indexed positions (the description says so); with no server every call returns an error, never an empty answer. Servers come from the hand-written `lsp` map or, when the user-tier `lspAutoDetect` bool is on, from a fingerprint table probed against PATH (explicit entries win per language). — _Evidence:_ `candy-code/src/Tools/BuiltIn/LspTool.php:59,78-83,170`; `candy-code/src/LSP/LspLauncher.php:27,95,163,237`
- why: "needs a manual `lsp` server config" is stale since lspAutoDetect shipped.

### TL-17 · candy-code-web
- current: `✅ PermissionCard renders a button per choice (WEB-5a-iii); the note field stays for free-form answers`
- new: ✅ PermissionCard renders a button per AskUser choice; the note field stays for free-form answers
- detail: AskUser rides the permission channel; the card reads the real choices from `arguments.options` and answers `once` with the exact option string, keeping the note field as the free-text path. — _Evidence:_ `candy-code-web/src-web/components/PermissionCard.vue:40-49,85,130-137`; `candy-code/src/Tools/BuiltIn/AskUserTool.php:71`
- why: lane tag in a current-state cell; Appendix A still carries the stale 🟡 "— —".

### TL-19 · candy-code
- current: `✅ — Read answers images to vision-capable models (native parts) after the 8 KiB magic sniff; PDFs ride document blocks`
- new: 🟡 Read carries in-cap images (TUI paints them); the model sees pixels only on Anthropic and Vertex-Claude, else a caption
- detail: Read sniffs PNG/JPEG/GIF/WebP in an 8 KiB head and returns an image-bearing result up to the 5 MiB display cap (the TUI mosaic ladder paints it). Only the Anthropic Messages wire puts those bytes in a `tool_result` image block (≤256 KiB, vision-gated); OpenAI-family, SGLang, Bedrock and Gemini wires send the caption alone by design. Read never carries PDFs (see TL-20). — _Evidence:_ `candy-code/src/Tools/BuiltIn/Read.php:726-746,799-807`; `candy-code/src/Providers/Concerns/AnthropicMessagesWire.php:89,838-858`; `candy-code/src/Providers/VertexProvider.php:400-403`; `candy-code/src/Providers/Concerns/GeminiGenerateContentWire.php:339`
- why: "vision-capable models" overstates (one wire family); "PDFs ride document blocks" is false for Read.

### TL-19 · candy-code-web
- current: `🟡 Images a tool reads are not rendered; only media-store artifacts get a MediaCard`
- new: 🟡 A Read image becomes a MediaCard whose fetch fails (media.fetch serves only the session media store); generated media render
- detail: `tool.finished` projects Read's `imagePath` as a media ref (artifactId = basename stem), so the card renders a MediaCard; `media.fetch` resolves ids and paths only inside the session MediaStore, so a workspace image answers `media_artifact_not_found` (code-traced, not run live; a stored artifact sharing the stem would show the wrong image). GenerateImage/Video artifacts load normally. — _Evidence:_ `candy-code/src/Tools/BuiltIn/Read.php:745`; `candy-code/src/Host/TranscriptProjector.php:301-303,351-364`; `candy-code/src/Support/MediaStore.php:752-756`; `candy-code/src/Protocol/Methods/MediaMethods.php:499-509,521-524`; `candy-code-web/src-web/components/ToolCard.vue:86-87`
- why: Read now carries image paths, which changes what the web does (it tries and fails rather than showing nothing).

### TL-20 · candy-code
- current: `✅ `@`-mentions send PDFs as document blocks on PDF-capable providers (`FileMentions` → `AttachmentType::Document`, carried via`
- new: 🟡 `@`-mentioned PDFs ride document blocks on Anthropic and Vertex-Claude only; Read answers a PDF with a `pdftotext` hint
- detail: A user's `@file.pdf` attaches up to 20 MB as a `document` block where `supportsDocuments()` is true (Anthropic, Vertex with a Claude model; Bedrock, Gemini, OpenAI-family and SGLang decline). The model cannot read a PDF on its own: Read returns a notice (its "mention the file" advice is a user action) and the only model road is Bash `pdftotext`. — _Evidence:_ `candy-code/src/Tools/BuiltIn/Read.php:750-763`; `candy-code/src/Attachments/FileMentions.php:64`; `candy-code/src/Providers/AnthropicProvider.php:230-233`; `candy-code/src/Providers/VertexProvider.php:411-413`; `candy-code/src/Providers/BedrockProvider.php:445-451`
- why: 212-char cell; ✅ overstates — every competitor's Read reads PDFs, candy-code's model-side path is pdftotext only.

### TL-20 · candy-code-web
- current: `❓ Engine question; the web would show only the text output`
- new: 🟡 Inherited: `@file.pdf` in the web composer takes the same document-block road; Read gives only a `pdftotext` hint
- detail: `session.send` resolves mentions by default, and the served submit path runs the same `FileMentions::resolve`, so a typed `@path.pdf` becomes a document attachment on the same providers as the TUI. — _Evidence:_ `candy-code/src/Protocol/Methods/TurnMethods.php:191,198`; `candy-code/src/Host/TurnController.php:1059-1060,1078-1082`
- why: ❓ is settleable from code.

### TL-22 · candy-code
- current: `❌ No cron/scheduling tool`
- new: 🟡 No model-callable cron tool; users schedule recurring prompts with `/loop` and `/schedules` (serve is the cron host)
- detail: `Schedule`/`ScheduleRegistry` (`~/.candy-code/schedules.json`) are evaluated inside the TUI background pump and the served host's background tick, spawning background sessions when due; `/loop <interval> <prompt>` and `/schedules` (alias `/schedule`) manage them. No tool in the catalog lets the model create one. — _Evidence:_ `candy-code/builtin-commands/2910-schedules.php`; `candy-code/builtin-commands/2920-loop.php`; `candy-code/src/Chat.php:18528`; `candy-code/src/Host/BackgroundEvents.php:229`; `candy-code/src/Cli/Bootstrap.php:1910`
- why: scheduling shipped as user commands; the row grades the capability, missing piece is the model tool.

### TL-22 · candy-code-web
- current: `❌ No cron/scheduling tool`
- new: 🟡 `/loop` and `/schedules` run server-side via command.exec; no model tool and no schedule panel
- detail: Both specs carry host commands, so `command.list` marks them server-runnable and the web runs them as typed commands; schedules fire in serve's background tick. — _Evidence:_ `candy-code/builtin-commands/2910-schedules.php`; `candy-code/builtin-commands/2920-loop.php` (`withHostCommand`); `candy-code/src/Host/BackgroundEvents.php:229`
- why: as TL-22 candy-code.

### TL-27 · candy-code-web
- current: `✅ Picking `plan` in the status bar applies the server's read-only tool set to the next turns`
- new: 🟡 Picking `plan` in the status bar sets the session's mode; the gate refuses writes, the tool list is unchanged
- detail: The status-bar picker calls `session.setMode`, a permission-mode change; EngineBackend's per-turn tool list filters only the context-ledger tools, so plan mode refuses writes at the gate rather than offering a read-only roster. — _Evidence:_ `candy-code-web/src-web/components/StatusBar.vue:8,16`; `candy-code/src/Protocol/Methods/SessionMethods.php:56`; `candy-code/src/Backend/EngineBackend.php:3151,3180-3190`
- why: the cell claims a mode-scoped tool set the engine (TL-27 cc 🟡) does not have.

### TL-28 · candy-code-web
- current: `🟡 GenerateImage/GenerateVideo artifacts render as MediaCards (lazy fetch, lightbox); no generate form (`generate.submit` unused)`
- new: ✅ Generate panel submits image/video jobs (`generate.submit`/`status`); artifacts render as MediaCards (lazy fetch, lightbox)
- detail: GeneratePanel (a SidePanels tab) drives `generate.submit` and polls `generate.status` through `stores/generate.ts`; finished artifacts and model-run GenerateImage/Video results load through `media.fetch` into MediaCards. — _Evidence:_ `candy-code-web/src-web/components/panels/SidePanels.vue:13,103`; `candy-code-web/src-web/stores/generate.ts:6-9,24-26`; `candy-code/src/Protocol/Methods/MediaMethods.php:100-111`
- why: the Generate form shipped.

### TL-N1 · candy-code-web
- current: `✅ Inherited PtyRun for served sessions; its card shows the final transcript/screen at tool.finished (nothing streams while it runs)`
- new: ✅ Inherited PtyRun for served sessions; its card shows the final transcript/screen at tool.finished (no live tail)
- detail: PtyRun is in the served roster and its steps pass the same approval chain as Bash; the permission card shows the raw arguments, steps included. PtyRun emits no `tool.progress` (only Bash does), so the card fills at finish. — _Evidence:_ `candy-code/src/Tools/BuiltIn/PtyRun.php:50`; `candy-code/src/Permissions/PermissionGate.php:622,900`; `candy-code/src/Backend/EngineBackend.php:3218`; `candy-code-web/src-web/components/ToolCard.vue:44`
- why: 131-char cell; Appendix A still says "the unseen-keystrokes P0 from a4 applies", which is fixed.

### TL-N3 · candy-code
- current: `❌ No code-mode tool; nearest is Workflow (a model-authored YAML plan of staged sub-agents with output interpolation) plus plain`
- new: ❌ No code-mode tool; nearest is Workflow (model-authored YAML of staged sub-agents with output interpolation) plus Bash
- detail: No built-in tool runs a script that can call agent tools or MCP namespaces. Workflow composes whole sub-agent stages (`{{stage.output}}`), each its own agentic run; Bash runs real scripts but cannot reach the agent's tools or MCP servers; parallel calls in one message are the only round-trip saver. — _Evidence:_ `candy-code/src/Tools/BuiltIn/WorkflowTool.php:59`; `candy-code/src/Tools/BuiltIn/Bash.php:50`; `candy-code/src/Tools/Catalog/ToolCatalog.php:197-210`
- why: 132-char cell; Appendix A's "The 30 built-in tools" count is stale (36 catalog classes).

### SH-02 · candy-code-web
- current: `🟡 Inherited`
- new: ❌ Inherited: every served Bash call is a fresh `bash -c`
- detail: Served turns run the same Bash tool; there is no persistent shell to inherit. — _Evidence:_ `candy-code/src/Tools/BuiltIn/Bash.php:198,234`; `candy-code/src/Cli/LaunchContext.php:2010-2022`
- why: inheriting an absent feature cannot be partial.

### SH-03 · candy-code
- current: `✅ — Grep is bounded: wall-clock timeout plus max-output bytes`
- new: ✅ Bash 120 s default / 600 s max (settings rebind per turn), per-call `timeout`, output kept on timeout; PtyRun 30/300 s
- detail: `bashTimeoutSeconds`/`bashMaxTimeoutSeconds` rebind per turn; the model passes `timeout` per call (clamped to the max). On timeout the group is killed and the output so far returns with a "[timed out after N s …]" marker. Grep has its own 60 s wall clock; LLM calls carry no total timeout. — _Evidence:_ `candy-code/src/Tools/BuiltIn/Bash.php:102-103,407,505,598-607,695-696`; `candy-code/src/Tools/ToolLimits.php:109,112`; `candy-code/src/Tools/BuiltIn/PtyRun.php:60,62`; `candy-code/src/Tools/BuiltIn/Grep.php:50`
- why: the cell holds a Grep sentence pasted into the Bash-timeouts row.

### SH-04 · candy-code-web
- current: `🟡 Background panel manages `/bg` sessions (output, stop, inject); no per-shell-process list or kill`
- new: 🟡 Background shells run server-side (ShellOutput/ShellStop cards); the Background panel lists `/bg` sessions only, no shells
- detail: The model can start, poll and kill background shells in served sessions; the human has no list or kill control for them (BackgroundPanel covers `/bg` agent sessions). — _Evidence:_ `candy-code/src/Cli/LaunchContext.php:1708`; `candy-code-web/src-web/components/panels/BackgroundPanel.vue`
- why: the cell omits that background shells now exist server-side.

### SH-06 · candy-code
- current: `🟡 Opt-in bubblewrap (`bashSandbox` on/no-network): ro `/`, writable root+/tmp+$TMPDIR, policy paths ro; Linux; Bash only`
- new: 🟡 Opt-in bubblewrap (`bashSandbox` on/no-network) for Bash and PtyRun: ro `/`, writable root+tmp; Linux; hooks/LSP/MCP unjailed
- detail: `--unshare-all --die-with-parent`, `.git/hooks`, `.git/config`, `.candy-code` and `.mcp.json` bound read-only; fails closed when bwrap cannot start; user tier only. It wraps foreground and `run_in_background` Bash and PtyRun's pty spawn (argv form, no `--new-session`); hooks, lint/test runners, LSP and MCP children run unsandboxed. A write jail, not a read jail. — _Evidence:_ `candy-code/src/Tools/Sandbox/Bubblewrap.php:10-56`; `candy-code/src/Tools/BuiltIn/Bash.php:186,569,577-588`; `candy-code/src/Tools/BuiltIn/PtyRun.php:176,430`; `candy-code/src/Support/ProcessContainment.php:588-598`
- why: "Bash only" is stale — PtyRun is jailed too; Appendix A still says PtyRun runs unsandboxed.

### SH-07 · candy-code
- current: `🟡 The `no-network` sandbox mode removes network for Bash only; no domain allowlist or egress proxy`
- new: 🟡 The `no-network` sandbox mode removes network for Bash and PtyRun only; no domain allowlist or egress proxy
- detail: Without the sandbox there is no network control beyond the auto-mode classifier and WebFetch's ask class/rules. — _Evidence:_ `candy-code/src/Tools/Sandbox/Bubblewrap.php:53-56`; `candy-code/src/Tools/BuiltIn/PtyRun.php:176,430`
- why: same PtyRun jail fact as SH-06.

### SH-08 · candy-code
- current: `🟡 The tool row shows running/done with elapsed time; output arrives only at completion; no live stdout streaming`
- new: 🟡 TUI row shows running/done with elapsed time but paints no live output; Bash's live tail reaches only web and stream-json
- detail: Bash emits frame-capped `ToolProgress` tails while it runs (foreground and `interactive`); Chat records them and deliberately paints nothing, while serve relays them as `tool.progress` and `--output-format stream-json` writes them as NDJSON. PtyRun and other tools emit no progress. — _Evidence:_ `candy-code/src/Tools/BuiltIn/Bash.php:530-534,590-596`; `candy-code/src/Chat.php:3702-3711,4230-4237`; `candy-code/src/Cli/StreamJsonEmitter.php:123-129`
- why: the engine now streams; the TUI renderer is the missing piece.

### SH-08 · candy-code-web
- current: `❌ Shell output appears only at `tool.finished`; there is no progress/stream event, so long commands look frozen`
- new: ✅ Foreground Bash streams a live tail into its running card (ephemeral `tool.progress`, frame-capped); other tools at finish
- detail: `tool.progress` is a non-durable session event (never replayed; `tool.finished` stays the whole-payload contract); the reducer keeps the newest tail per card with a seq guard and ToolCard shows it as "live tail · older output dropped" while running. — _Evidence:_ `candy-code/src/Host/SessionEvent.php:48`; `candy-code/src/Host/TranscriptProjector.php:421`; `candy-code-web/src-web/protocol/generated.ts:1764,1856`; `candy-code-web/src-web/stores/reducer.ts:70-72,509`; `candy-code-web/src-web/components/ToolCard.vue:44,69-74`
- why: `tool.progress` shipped; the cell and its Appendix A (schema "no tool.progress") are stale.

### TL-01 · candy-code
- current: `✅ Read: numbered pages (2000 lines / 50 KiB), offset+limit, outline of long files, 1 MiB per-call ceiling, jailed to root`
- new: cell unchanged, detail only
- detail: Returns `N: text` lines with a next-offset footer; a long file read without offset/limit gets an LSP or regex outline (≤200 rows / 8 KiB). Binary files answer with a note instead of mojibake: in-cap images ride the result (TL-19), PDFs get a `pdftotext` hint (TL-20). Line counting stops at an 8 MiB scan budget (footer then says `of ≥N`); jailed via `PathJail::resolve`, records stat + hash into the shared ReadLedger. — _Evidence:_ `candy-code/src/Tools/BuiltIn/Read.php:46,54,62,93,101-102,423-427,726-763`
- why: Appendix "Gaps: no image/PDF … support (mojibake) … scans and hashes the whole file" is stale.

### TL-07 · candy-code
- current: `✅ — Dual-engine grep: ripgrep when on PATH, GNU grep fallback; wall-clock timeout and output bound`
- new: cell unchanged, detail only
- detail: `rg` runs when present at boot (with `--hidden --no-ignore --binary --with-filename`, the tool's own IgnoreRules post-filter deciding visibility), GNU `grep -rn` otherwise; the schema states the active dialect. Option injection is closed and .env/key files are never opened; 60 s wall clock (`grepTimeout`) and an output bound. No case-insensitive, context-line, count or files-only modes. — _Evidence:_ `candy-code/src/Tools/BuiltIn/Grep.php:50,72,238-260,281-283,391,945-960`; `candy-code/src/Cli/LaunchContext.php:2018`
- why: Appendix is still the 🟡 pre-SHL-4 text ("rgAvailable only adds a hint", "never cut off").

### TL-08 · candy-code
- current: `✅ Ls tool (StreamingDirectoryLister-backed) in the model's tool set`
- new: cell unchanged, detail only
- detail: `Ls` (Read class, ParallelSafe) lists one directory name-sorted with `/`-marked directories over `StreamingDirectoryLister`; dotfiles and .gitignore'd entries are hidden unless `include_hidden`, the list caps at 1000 with a PARTIAL marker, and paths are jailed like Glob. — _Evidence:_ `candy-code/src/Tools/BuiltIn/Ls.php:61-62,73,145,231-259,321`
- why: Appendix still 🟡 "StreamingDirectoryLister has no callers".

### TL-10 · candy-code
- current: `✅ Bash `run_in_background` with ShellOutput polling and ShellStop kill (BackgroundShellRegistry)`
- new: cell unchanged, detail only
- detail: `run_in_background: true` starts the (cd-guarded, sandbox-wrapped) command through the containment spawn, records pid = pgid, streams stdio to owner-only files and answers a `sh-xxxxxxxx` id at once; ShellOutput reads from a byte cursor, ShellStop sends TERM→grace→KILL to the group. Shells are killed at 1800 s and at teardown; output is capped at 64 MiB. — _Evidence:_ `candy-code/src/Tools/BuiltIn/Bash.php:422,577-588`; `candy-code/src/Background/BackgroundShellRegistry.php:142,164,982`; `candy-code/src/Tools/BuiltIn/ShellOutput.php:83-92`; `candy-code/src/Tools/BuiltIn/ShellStop.php:20-22,82`
- why: Appendix still ❌ "orphaned and untracked … only Task sub-agents".

### TL-18 · candy-code-web
- current: `✅ PlanExit card renders the plan through MessageMarkdown with approval actions`
- new: cell unchanged, detail only
- detail: The PlanExit ask carries the plan text in `arguments.plan`; the card renders it (plus the summary) through MessageMarkdown above the normal approve/reject actions. — _Evidence:_ `candy-code/src/Tools/BuiltIn/PlanExitTool.php:181-186`; `candy-code-web/src-web/components/PermissionCard.vue:56-68,108-110`
- why: Appendix still 🟡 "— —".

### TL-26 · candy-code
- current: `✅ Parallel tool calls on by default; ParallelSafe tools run in concurrent forks; writers run one at a time`
- new: cell unchanged, detail only
- detail: `parallelToolCalls` (default true) and `parallelToolDeadlineSeconds`. ParallelSafe: Read, Glob, Grep, Ls, WebFetch, WebSearch, Task and BoardRead; Lsp, Workflow and MCP bridges run alone (MCP is a deliberate barrier). Parallel Task members relay permission asks to the modal. — _Evidence:_ `candy-code/src/Config/Settings/Definitions/AgentLoopSettings.php:34`; `candy-code/src/Tools/BuiltIn/Ls.php:62`; `candy-code/src/Tools/BuiltIn/TaskTool.php:204`; `candy-code/src/Tools/McpToolBridge.php:82,545`
- why: Appendix roster lists Lsp/Workflow/MCP as ParallelSafe (false) and omits Ls.

### TL-N1 · candy-code
- current: `✅ PtyRun runs a program on a real pty and replays keystroke steps with expect checks; 30/300 s`
- new: cell unchanged, detail only
- detail: Up to 64 steps, named keys (`<Enter>`, `<C-x>`), `\xHH` escapes, substring or `/regex/` expects, cols/rows, 64 KiB default capture (raisable to 1 MiB), transcript or final-screen output, TERM-then-KILL reaping. Steps pass the same breaker, rules, classifier and Bash-shared rule subject as `command`, and the bwrap jail applies. — _Evidence:_ `candy-code/src/Tools/BuiltIn/PtyRun.php:50,60-62,83,176,430`; `candy-code/src/Permissions/PermissionGate.php:622,900,1249-1258`; `candy-code/src/Permissions/PermissionRule.php:197`; `candy-code/src/Permissions/SafetyClassifier.php:376`
- why: Appendix "steps escape every safety layer (see ISSUES P0/P1)" is fixed history.

### SH-04 · candy-code
- current: `✅ — Background shell (TL-10); timeout kills reach `&` children via the recorded process group`
- new: cell unchanged, detail only
- detail: The deadline kill targets the process-group id recorded at spawn (setsid makes child pid = pgid) and reports the kill as confirmed only when `kill(-pgid, 0)` answers ESRCH, so `cmd &` children die with the group. Background shells: see TL-10. — _Evidence:_ `candy-code/src/Tools/Concerns/CapturesProcessOutput.php:222-224,369-412`
- why: Appendix still ❌ with the old "sleep 61 survives" repro.

### SH-10 · candy-code
- current: `✅ Heuristic layers: rm -rf / or ~ breaker (all modes), ConfirmRemoveHook, protect-files, auto classifier, optional LLM reviewer`
- new: cell unchanged, detail only
- detail: The breaker reads quote-removed words, prefixes and abbreviated flags; ConfirmRemoveHook catches rm -r/-f, find -delete, shred and dd of= and stands down in bypass. PtyRun `steps` go through the same breaker, rules, classifier and session grants as a Bash `command`. — _Evidence:_ `candy-code/src/Permissions/PermissionGate.php:622,900,1249-1258`; `candy-code/src/Hooks/BuiltIn/ConfirmRemoveHook.php`
- why: Appendix mixes the old "PtyRun steps pass unseen" with a dated "_2026-10-18 remediated:_" tail.

### SH-N1 · candy-code
- current: `✅ All children: setsid detach, non-interactive + credential-stripped env, drained pipes; fd CI gate`
- new: cell unchanged, detail only
- detail: SECRET_ENV_PATTERNS plus provider key names, overridable with `secretEnvAllowlist`; the repo-wide `tools/check-child-lifetimes.php` gate accounts every `proc_open` site and currently reports 0 problems (61 libs, 33 sites measured 2026-10-26). — _Evidence:_ `candy-code/src/Support/ProcessContainment.php:81-143`; `tools/check-child-lifetimes.php` (run output)
- why: Appendix figure "60 libs, 28 proc_open sites" is stale.

### SH-N1 · candy-code-web
- current: `✅ Inherited: every child a served turn spawns goes through ProcessContainment (setsid, non-interactive, credential-stripped env)`
- new: cell unchanged, detail only
- detail: Workspace-host processes also receive their secrets on stdin, not argv. — _Evidence:_ `candy-code/src/Support/ProcessContainment.php:56`; `candy-code/src/Server/Workspace/WorkspaceHostProcess.php`
- why: drop the "(a6)" audit-lane tag.

## Narrative fixes
- §1 L57: current "No panel shows loaded instructions, skills, MCP or hooks; only text commands (`/context`, `/mcp`, `/notices`) hint at them." → new "The Context panel lists loaded instruction files, memory, skills (name and tier) and project MCP servers (no liveness); hooks appear only as the server-run `/hooks` text table, and `@mcp:` mentions, MCP prompt commands, `/mcp login` and `/skill-name` do not work in served sessions." (merged with the MCP slice: user-tier MCP rows are computed but not sent — `src/Host/ContextSources.php:158-172`)
- §1 L64 (Extensibility, "leads" cell): current "Reads foreign **agents** live (`.claude/agents`, `.opencode/agents`; no competitor does) as well as foreign skills (`.claude`, `.agents`, `.opencode`); MCP server fingerprint pinning" → new "Reads foreign **agents** live (`.claude/agents`, `.opencode/agents`; no competitor does) as well as foreign skills (`.claude`, `.agents`, `.opencode`) and foreign commands (`.claude/commands`, `.opencode/command(s)`); MCP server fingerprint pinning"
- §1 L64 (Extensibility, "trails" cell): current "no plugin registry or marketplace (bundles import Claude-format plugins); MCP resources, prompts, SSE, user scope and deferred loading have since shipped (no elicitation/sampling/server mode); 12 hook events vs Claude Code's 33; `.claude/` and `.opencode/` commands are read" → new "no plugin registry or marketplace (bundles import Claude-format plugins); no MCP elicitation, sampling, roots or server mode, and MCP resources and prompts are TUI-only; 12 hook events vs Claude Code's 33, and a Claude settings file with non-`command` hook types or newer entry keys refuses the launch; no forked or tool-restricted skills"
- §1 L56: current "`/theme`, `/keys` and `/sessions` are terminal-only (`/model`, `/compact` and `/init` gained host bodies and now run over the wire)." → new "16 slash commands are terminal-only (`/theme`, `/keys`, `/sessions`, `/review`, `/effort`, …); `/model`, `/compact`, `/init`, `/hooks` and `/skills` run over the wire."
- §4.3 L593 (Discovery, candy-code): current "built-in < project `.candy-code/skills` < user `~/.candy-code/skills` (user wins, shadowing reported) + **7 foreign trees** (`.claude`, `.agents`, `.opencode`, `~/.config/opencode`), badged by origin" → new "built-in < project `.candy-code/skills` < user `~/.candy-code/skills` (user wins, shadowing reported) + **7 foreign trees** (`.claude`, `.agents`, `.opencode`, `~/.config/opencode`) + plugin-bundle `skills/`, badged by origin"; candy-code-web cell "—" → "Inherited; the Context panel lists loaded skills by tier"
- §4.3 L596 (User invokes): candy-code current "`$name` mention (one turn, Tab completes); the Ctrl+S picker reaches the model — enabling ships the skill's prompt on the following turns" → new "`/skill-name args` or a `$name` mention (one turn, Tab completes); the Ctrl+S picker enables a skill's prompt for the following turns"; candy-code-web current "❌" → new "🟡 `$name` resolves server-side; `/skill-name` is sent as prose"
- §4.3 L597 (User sees list): candy-code-web current "❌" → new "✅ Context panel (name, tier, disabled) + server-run `/skills` table"
- §4.3 L599 (Registry): candy-code current "❌" → new "🟡 none; `bundles install <git-url|path>` installs Claude-format plugin bundles"
- §4.5 L620: current "**Not** read: `.claude/commands`, `.opencode/commands` (even though foreign agents and skills are imported)." → new "Foreign `.claude/commands` and `.opencode/command(s)` trees (project, home, `~/.config/opencode`) and plugin-bundle `commands/` import at the lowest precedence — they never shadow a built-in or native file — badged `[claude]`/`[opencode]`/`[bundle]` in the `/` popup. MCP prompts list as `/mcp__server__prompt`."
- §4.5 L621: current "Ctrl+P palette is a curated 19-action subset. F10 menu bar." → new "Ctrl+P palette: 49 rows — 42 of the 57 slash commands plus 7 palette-only actions — and provider/model/theme pickers; 15 argument-taking or read-out commands (`/effort`, `/loop`, `/fork`, `/review`, `/status`, …) and command files stay slash-only. F10 menu bar."
- §4.5 L622 (append): after "…fires mainly on the colon form (`/key:status`)." add "A `/name` that a user-invocable skill claims is rewritten into the `$name` channel instead of refused."
- §4.5 L624: current "**candy-code-web**: 57 built-ins listed; 41 run server-side, 16 refused as terminal-only (`/theme`, `/keys`, `/help`, `/sessions`, …). ⚠ `!cmd` runs but reports \"refused\"." → new "**candy-code-web**: 57 built-ins listed; 41 run server-side, 16 refused as terminal-only (`/theme`, `/keys`, `/help`, `/sessions`, `/review`, …). Native, foreign and bundle command files list and run server-side; MCP prompts and `/skill-name` are TUI-only. `!cmd` runs on the server and its rows arrive as transcript events; a Ctrl+K palette lists sessions, panels and server-runnable commands."
- §4.6 L635: current "| Payload | `CRUSH_*` env vars (stdin closed) |" → new "| Payload | `CRUSH_*` env vars plus Claude-shaped JSON on stdin (session_id, cwd, hook_event_name, tool_name, tool_input, tool_response) |"
- §4.6 L638: current "| Claude-Code compat | ⚠ Docs claim it; in practice CC stdin scripts receive nothing, and exit 1 means deny |" → new "| Claude-Code compat | 🟡 Reads `hooks` from `~/.claude/settings.json` and trusted project `.claude/settings(.local).json` (command type only — other types or newer entry keys refuse the launch); unknown events skipped and recorded; exit 1 denies where Claude Code proceeds |"
- §6.2 L788: current "**Read-only `/hooks` and `/mcp reconnect`** — reconnect restarts from launch-time entry bytes and reports roster drift rather than re-deriving mid-session (cache law)." → new "**Read-only `/hooks` and `/mcp reconnect`** — reconnect refuses when the MCP config changed since launch, otherwise stops and restarts every server and reports a changed tool roster without re-deriving it mid-session (cache law)." (Evidence: `src/Commands/McpAuthCommand.php:202-247,275-290`)
- §6.2 L789: current "**The Ctrl+P palette is a superset of the slash roster** — per-row judgement; slash-only survivors carry reasoned rows in `PaletteSupersetDispatchTest`." → new "**The Ctrl+P palette covers 42 of the 57 slash commands plus 7 palette-only rows** — per-row judgement; the 15 slash-only commands carry reasoned rows in `PaletteSupersetDispatchTest`; command files are not in the palette."
- §6.2 L790: append to the item: " TUI only: served sessions still send `/skill-name` as prose (`$name` works there)."
- §6.4 L806: current "**Hooks:** stdin payload delivery, CC event-name handling, relative-command resolution and the shadowing/trust precedence shipped first; reading the `hooks` block of `.claude/settings.json` has shipped — `cc:{event}:{index}` rows through the same `HookConfig::parse` chain, project files gated on `trustedProjectHooks`, the `Notification` event skip-and-recorded." → new "**Hooks:** scripts get Claude-shaped JSON on stdin, and the `hooks` block of `~/.claude/settings.json` (always) and the project's `.claude/settings(.local).json` (under `trustedProjectHooks`) loads as `cc:{event}:{index}` rows through the same `HookConfig::parse` chain; unknown events (e.g. `Notification`) are skipped and recorded. Open: non-`command` hook types and newer entry keys (`async`, `statusMessage`, `if`) refuse the launch instead of being skipped, and exit 1 denies where Claude Code proceeds."
- §2 L84-86 (scoreboard, derived): with the glyph flips above, SKL becomes candy-code ✅9 🟡4 🧪1 ❌1 (SKL-06 🟡→✅, SKL-09 ❌→🟡; was ✅8 🟡4 🧪1 ❌2) and candy-code-web ✅5 🟡7 🧪1 ❌2 (was 🟡11 ❌3 ❓1); CMD stays candy-code ✅8 🟡4 and becomes candy-code-web ✅6 🟡4 ❌2 (CMD-10 ➖→🟡, CMD-11 🟡→✅; was ✅5 🟡4 ❌2 ➖1); HK becomes candy-code ✅5 🟡5 (HK-07 ✅→🟡) and candy-code-web 🟡10 (HK-10 ❌→🟡). Re-derive with the documented parser after all slices land rather than hand-editing.
- §1 L65: current "opt-in bubblewrap sandbox for Bash that fails closed by default" → new "opt-in bubblewrap sandbox for Bash and PtyRun that fails closed by default"
- §6.5 L819: current "`LS` built on the former `StreamingDirectoryLister` (position 36, `includeHidden`), Read answering images and PDFs across all three tiers (tool-result image blocks gated on `supportsVision`, PDFs riding `AttachmentType::Document`)" → new "`Ls` built on the former `StreamingDirectoryLister` (`include_hidden`), Read carrying in-cap images (the model sees them on the Anthropic wire; other wires get a caption) and answering PDFs with a `pdftotext` hint (only user `@`-mentions ride `AttachmentType::Document`), Bash's live output tail over `tool.progress` (web and stream-json)"
- §6.5 L819: current "The binary notice and Grep bounds shipped earlier" → new "Still open: model-side PDF reading, tool-result images on non-Anthropic wires, a live shell tail in the TUI, HTML→Markdown in WebFetch, a notebook tool, a model-callable schedule tool, a network allowlist"
- §1 L65 (Tools & safety, "trails" cell): current "Auto mode is a regex classifier (with an opt-in LLM reviewer now fed every call), where Claude Code ships a trained classifier; background shell, opt-in auto-format and opt-in LSP auto-detect have since shipped" → new "Auto mode is a regex classifier (with an opt-in LLM reviewer fed every call), where Claude Code ships a trained classifier; auto-format and LSP auto-detect are opt-in, with no bundled formatter table and no LSP auto-install; no notebook, browser-control or code-mode tool; WebFetch returns raw HTML; the model reads PDFs only via `pdftotext`; the TUI paints no live shell output" (merged from the TL/SH and EDT slices)
- §4.7 L644: current "Covers tools and resources over stdio, Streamable HTTP and legacy SSE (origin-locked), with a strong trust model:" → new "Covers tools, resources (`@mcp:` mentions) and prompts (`/mcp__server__prompt`) over stdio, Streamable HTTP (buffered replies, no GET stream) and legacy SSE (origin-locked), with a strong trust model:"
- §4.7 L645: current "Deferred loading by default: a McpToolShape token floor plus `mcp_list`/`mcp_call` discovery keep schemas out of every turn; `eager: true` opts back in." → new "Deferred loading per server: a server whose schemas exceed `mcp.lazySchemaTokens` (8000) is reached through `mcp_list`/`mcp_call`; smaller servers and `eager: true` entries keep first-class bridges. A preset's `mcpServers` allowlist does not yet scope the dispatchers."
- §4.7 L646: current "User-level `~/.candy-code/mcp.json` scope ships; still missing: elicitation, sampling, roots and MCP-server mode." → new "User-level `~/.candy-code/mcp.json` plus project `.mcp.json` (project wins); missing: elicitation, sampling, roots, list_changed, answering server pings, runtime enable/disable and MCP-server mode."
- §4.7 L647: current "OAuth drives a forked browser PKCE flow from `/mcp login` (`/mcp reconnect` after); tokens attach at the next launch." → new "OAuth is PKCE over a loopback listener (`candy-code mcp auth login`); the TUI's `/mcp login <server>` forks that flow and prints the URL, and `/mcp reconnect` restarts servers when the config is unchanged."
- §4.7 L649: current "**candy-code-web**: MCP calls are generic cards; `/mcp` prints config status only." → new "**candy-code-web**: MCP calls are generic cards; the Context panel lists project servers (transport, trust) and offers a trust prompt for an untrusted project; `/mcp` verbs answer as text; `@mcp:` mentions, MCP prompt commands and `/mcp login` are TUI-only."
- §6.4 L809: current "**Plugin bundles:** shipped — Claude-format bundles" → new "**Plugin bundles:** Claude-format bundles" (and end the bullet with "`bundles list|install <path|git-url>` read and install them; there is no registry or marketplace." in place of "`bundles list|install <path|git-url>` is the MVP; the registry remains open.")
- §6.5 L815: current "| MCP | **Shipped:** `@mcp:<server>:<uri>` resource mentions, …| Configurable timeouts, 401 surfacing, embedded resource/image rendering and correct `claude-mcp` args shipped earlier |" → new "| MCP | No elicitation, sampling, roots or answered server pings; no GET stream on Streamable HTTP; no MCP-server mode; no runtime enable/disable; `@mcp:`, prompt commands and `/mcp login` are TUI-only; `mcpServers` presets do not scope `mcp_call` | Handle server-initiated frames in sugar-mcp-client; wire `McpClient::setAgentPreset()` for sub-agents; pass the MCP provider and prompt specs into SessionHost; `mcp serve` over the tool catalog |"
- §6.5 L816: current "| Plugins / marketplace | **Partially shipped:** Claude-format bundle reader + `bundles list|install` (git clone bounded, owned-dest) | the registry tier (Claude Code 315-entry marketplace, OpenCode npm plugins, OpenClaw ClawHub parity) remains open |" → new "| Plugins / marketplace | Bundles carry content only (skills, commands, agents); no registry/marketplace, no code-defined tools, no in-process plugin API (Claude Code 315-entry marketplace, OpenCode npm plugins, OpenClaw ClawHub) | A `marketplace.json` index over `bundles install`; file-defined script tools through the exec gate |"
- §6.5 L820: current "| Editing | **Shipped:** `formatCommands` + `PostEditFormatHook` recording into `ReadLedger` (…) | `--allow-root`-style opt-in posture per plan |" → new "| Editing | Auto-format and LSP auto-detect are opt-in with no bundled formatter table (OpenCode ships 26) and no LSP auto-install; no BOM preservation; web asks lack a file-context diff | Default formatter table gated on binary presence (LspLauncher's probe seam); BOM inherit beside `IndentShift::inheritEndings`; send `previewDiff()` in `permission.asked` |"

## Gaps (input for the new plan)

### GAP-SKL-06 · `/skill-name` in served sessions
- have / missing: TUI rewrites typed `/name args` into `$name` (`Chat::skillInvocationDraft`); served sessions send it as prose and `command.list` has no skill rows. Claude Code and OpenCode 1.x ✅ (`/skill-name` everywhere, skills in the `/` menu).
- approach: lift `Chat::skillInvocationDraft()` into a pure `Skills\SkillSlashInvocation::draft(string $text, ?SkillRegistry, callable $isBuiltIn): ?string`; call it from `Chat::submit()` (unchanged precedence) and from `SessionHost::admit()` after `$this->command($text)` returns null and before `admitPrompt()`. Optionally add `source:"skill"` rows to `command.list` (user-invocable skills only) so web completion and the palette can offer them.
- files: create `src/Skills/SkillSlashInvocation.php`; modify `src/Chat.php` (hot), `src/Host/SessionHost.php`, optionally `src/Protocol/Methods/CommandMethods.php` + `src/Protocol/Schema/*` → docs/protocol → `candy-code-web/src-web/protocol/generated.ts` (hot chain), `candy-code-web/src-web/components/Composer.vue`.
- guards/docs: docs/SKILLS.md invocation section; any protocol schema drift test for the `source` enum; web `components.spec.ts`.
- effort: M; deps: none.

### GAP-SKL-09 · Skill / bundle registry
- have / missing: `bundles install <git-url|path>` and `bundles list`; no index, search or update. Claude Code (plugin marketplaces) and OpenClaw (ClawHub) ✅.
- approach: same work item as GAP-PLG-02 — skills arrive inside bundles, so a marketplace reader that resolves to `Subcommands::bundlesInstall()` closes SKL-09 too; no separate lane.
- files: see GAP-PLG-02.
- guards/docs: see GAP-PLG-02.
- effort: (counted in GAP-PLG-02); deps: none.

### GAP-SKL-10 · Skill resources
- have / missing: Skill result names the base dir; Read is jailed to the project root so a user-tier skill's `scripts/` are reachable only via Bash; `SkillLoader::loadSkillAsset()` is dormant. Claude Code (`${CLAUDE_SKILL_DIR}`), OpenCode (base dir + file list, skill dirs pre-allowed) ✅.
- approach: wire `loadSkillAsset()` into `SkillTool` as an optional `asset` argument (containment-checked read of a file under the skill dir), list up to 10 sampled resource paths in the result (OpenCode parity), and substitute `${SKILL_DIR}`/`${CLAUDE_SKILL_DIR}` in bodies.
- files: modify `src/Tools/BuiltIn/SkillTool.php`, `src/Skills/SkillLoader.php`, `src/Tools/Catalog/ToolCatalog.php` (schema; hot roster), docs/SKILLS.md.
- guards/docs: `tools/gen-tool-docs.php` regen; prompt-stability pins for the Skill tool schema.
- effort: M; deps: none.

### GAP-SKL-11 · Skill `allowed-tools` / `disallowed-tools`
- have / missing: both keys parsed and listed INERT. Claude Code ✅ (pre-approve for the turn, remove while active).
- approach: when `SkillTool` loads a skill, record a turn-scoped grant on the `PermissionGate` (allowed-tools → allow verdict for matching `Tool(prefix:*)` rules until the turn ends; disallowed-tools → deny), reusing the existing rule grammar and the session-rule seam; clear at turn end.
- files: modify `src/Tools/BuiltIn/SkillTool.php`, `src/Permissions/PermissionGate.php`, `src/Runtime.php` (hot), `src/Support/FrontmatterKeyAudit.php` (drop the two INERT rows), docs/SKILLS.md.
- guards/docs: FrontmatterKeyAudit INERT ↔ docs Inert-row guard; permission-mode reader census if the gate gains a reader.
- effort: L; deps: none.

### GAP-SKL-13 · Skill hot reload
- have / missing: roster read once per launch (once per serve process). Claude Code, OpenCode 2, OpenClaw ✅.
- approach: `/skills reload` re-running `Bootstrap::skillRegistry()` into the session's registry; because the listing sits in the frozen system prompt, deliver roster changes as a `<turn-context>` delta through the CTX-3 instruction-diff seam rather than rewriting the prefix; serve: the same command per workspace.
- files: modify `src/Host/Commands/SkillsHostCommand.php`, `src/Chat.php` (hot), `src/Runtime.php` (hot), `lang/en.php` (hot), docs/SKILLS.md.
- guards/docs: `PromptPrefixByteStabilityTest` family; docs/COMMANDS.md regen (`tools/gen-command-docs.php`) if a sub-command is added.
- effort: M; deps: none.

### GAP-SKL-14 · Skill-creation helper
- have / missing: dream-pass drafts only. Claude Code and OpenClaw ✅ (bundled skill-creator).
- approach: ship a ninth bundled skill `skill-creator` (SKILL.md format, frontmatter rules, gating, where files go) and a `/skills new <name>` scaffold that writes a draft through `ProposedSkills` so `accept` stays the only promotion path.
- files: create `src/Skills/BuiltIn/skill-creator/SKILL.md`; modify `src/Host/Commands/SkillsHostCommand.php`, `lang/en.php` (hot), docs/SKILLS.md, README.md (hot) skill count.
- guards/docs: any bundled-skill count pin (README/DocFigure), `tools/gen-command-docs.php`.
- effort: S; deps: none.

### GAP-SKL-15 · Forked skills
- have / missing: `context: fork` parsed; `App::dispatchSkill()` has no caller. Claude Code ✅.
- approach: in `SkillTool::execute()`, when `SkillRegistry::isContextFork()` is true, run the body through the same sub-agent path `TaskTool` uses (EngineExecutor/EngineBackend loop, preset `agent:` if named, skill `model`/`effort` honoured) and return the child's result; retire the App-only route by making `App::dispatchSkill()` delegate to it.
- files: modify `src/Tools/BuiltIn/SkillTool.php`, `src/Tools/BuiltIn/TaskTool.php` (shared helper), `src/App/App.php`, `src/Support/FrontmatterKeyAudit.php` (drop `context`, `model`, `effort` INERT rows), docs/SKILLS.md.
- guards/docs: INERT ↔ docs guard; tool docs regen if the Skill schema changes.
- effort: M; deps: none.

### GAP-CMD-01 · Terminal-only built-ins in the web
- have / missing: 16 slash commands refuse `ui_only` in the web (incl. `/review`, `/security-review`, `/effort`, `/budget`, `/help`, `/new`). Claude Code/OpenCode ✅ across their surfaces.
- approach: add host bodies for the session-scoped ones (`ReviewHostCommand` submitting the same `@diff` turn, `EffortHostCommand` over the PRV-2 mapping, `BudgetHostCommand` over `SpendLedger`, `HelpHostCommand` rendering `CommandRegistry`); admission flips by construction via `runsIn`.
- files: create `src/Host/Commands/{Review,Effort,Budget,Help}HostCommand.php`; modify the four `builtin-commands/` spec rows (registry, hot), docs/COMMANDS.md (generated).
- guards/docs: `tools/gen-command-docs.php`; any "41 host commands" roster pins.
- effort: M; deps: none.

### GAP-CMD-02 · Served sessions ignore `trustedProjectCommands`
- have / missing: `SessionHost` and `command.list` call `loadAll($root)` without the workspace's trust flag, so a trusted project's built-in override runs in the TUI but not the web.
- approach: pass `$workspace->projectCommandsTrusted` to both `loadAll()` calls; add a served-host test mirroring the TUI precedence pin.
- files: modify `src/Host/SessionHost.php:241-244`, `src/Protocol/Methods/CommandMethods.php:131`.
- guards/docs: none beyond a new test (add it to `scripts/parallel-tests-durations.tsv` and refresh the suite figure).
- effort: S; deps: none.

### GAP-CMD-04 · Command frontmatter `model` / `agent` / `subtask` / `allowed-tools`
- have / missing: only description/argument-hint/template act. Claude Code and OpenCode 2 ✅.
- approach: `model` → one-turn model override through the existing `/model` effect seam; `agent`/`subtask: true` → run the expansion as a Task sub-agent with that preset (TaskTool/EngineExecutor path); `allowed-tools` → the same turn-scoped grant as GAP-SKL-11.
- files: modify `src/Commands/CommandSpec.php`, `src/Host/TurnController.php`, `src/Chat.php` (hot), `src/Support/FrontmatterKeyAudit.php`, docs/COMMANDS.md.
- guards/docs: INERT ↔ docs guard; `tools/gen-command-docs.php`.
- effort: L; deps: GAP-SKL-11 for the grant seam.

### GAP-CMD-08 · Palette is not a full superset
- have / missing: 49 rows; 15 arg-taking slash commands, command files and MCP prompts absent. OpenCode/OpenClaw ✅ (all actions).
- approach: add a generic arm for `CommandSpec`s without a `paletteAction`: selecting one pre-fills `/<name> ` in the composer (the web palette already does this for arg-taking commands), and source rows from `slashCommandRows()` so file commands and MCP prompts appear with their badges.
- files: modify `src/Chat.php` (palette region, hot), `src/Palette/PaletteState.php`, `tests/Chat/PaletteSupersetDispatchTest.php` survivor roster, `MenuBarLabelDerivationTest` roster.
- guards/docs: docs/KEYS or COMMANDS palette prose; `tools/gen-command-docs.php`.
- effort: M; deps: none.

### GAP-CMD-09 · MCP prompts in served sessions
- have / missing: `/mcp__server__prompt` rows merge only into `Chat` (`src/Chat.php:1796`); the web cannot list or run them. Claude Code/OpenCode ✅.
- approach: same work item as GAP-MCP-07 (merge `McpPromptCommands::specs()` into `SessionHost` and `command.list`); no separate lane.
- files: see GAP-MCP-07.
- guards/docs: see GAP-MCP-07; also truth-flip the "TUI-only" sentence in docs/MCP.md.
- effort: (counted in GAP-MCP-07); deps: GAP-MCP-06.

### GAP-CMD-10 · Plugin command namespacing
- have / missing: bundle commands import flat at the foreign floor; Claude Code ✅ (`/plugin:name`), OpenCode 2/OpenClaw ✅ (registered commands).
- approach: name bundle rows `/<bundle>:<file>` in `ForeignCommandDiscovery::bundleDirs()` (the colon form already parses), keeping the flat alias only when unclaimed.
- files: modify `src/Commands/ForeignCommandDiscovery.php`, `src/Commands/CommandLoader.php`, docs/PLUGINS.md, docs/COMMANDS.md.
- guards/docs: `tools/gen-command-docs.php`; PLUGINS.md drift guard if present.
- effort: S; deps: none.

### GAP-HK-03 · HTTP and prompt hook kinds
- have / missing: shell commands only. Claude Code ✅ (command, http, mcp_tool, prompt, agent).
- approach: `HookConfig` accepts `type: http` → new `HttpHook implements BoundedHookInterface` POSTing `ScriptHook::stdinPayload()`'s JSON through the SSRF-guarded fetcher, reading the same envelope; `type: prompt` → `PromptHook` asking the auxiliary/title backend for an allow/deny JSON verdict (the `ExecReviewer` seam). `ClaudeSettingsHooks` maps those types instead of refusing.
- files: create `src/Hooks/HttpHook.php`, `src/Hooks/PromptHook.php`; modify `src/Hooks/HookConfig.php`, `src/Hooks/ClaudeSettingsHooks.php`, `src/Cli/Bootstrap.php` (hot), docs/HOOKS.md, `lang/en.php` (hot).
- guards/docs: HOOKS.md source-table drift guard; `tools/check-child-lifetimes.php` unaffected (no proc_open).
- effort: L; deps: GAP-HK-07 (the tolerant parser) first.

### GAP-HK-07 · Claude settings files can refuse the launch
- have / missing: any non-`command` type, entry key beyond type/command/timeout or group key beyond matcher/hooks in `~/.claude/settings.json` (read always) exits the launch with code 2; exit 1 denies. Claude Code is the reference.
- approach: treat a foreign file like the unknown-event path: skip-and-record unsupported types and unknown entry keys (one aggregated launch notice, same `skippedEvents` channel), keep the loud refusal for the native `hooks.yaml`; honour `timeout`, map `statusMessage` to the running row; give `cc:` rows Claude's exit-1 semantics (non-blocking: allow + notice) via a dialect flag on `ScriptHook`.
- files: modify `src/Hooks/ClaudeSettingsHooks.php`, `src/Hooks/ScriptHook.php`, `src/Cli/Bootstrap.php` (hot; launch notice drain), `lang/en.php` (hot), docs/HOOKS.md.
- guards/docs: HOOKS.md drift guard; the bounded launch-notice roster (cap) if a new aggregate row is added.
- effort: M; deps: none.

### GAP-HK-08 · Hook log view
- have / missing: verdicts appear only inside tool rows and notices; Claude Code ✅ (`systemMessage`, hook error notices, status message).
- approach: keep a bounded ring of recent hook verdicts in `HookRegistry::executeHooks()` (event, hook name, verdict, ms, first line of output) and add `/hooks log`; surface an envelope `systemMessage` as a uiOnly notice row through `RuntimeNoticeSink`.
- files: modify `src/Hooks/HookRegistry.php`, `src/Hooks/HookManager.php`, `src/Commands/HooksCommand.php`, `src/Host/Commands/HooksHostCommand.php`, `lang/en.php` (hot), docs/HOOKS.md.
- guards/docs: `tools/gen-command-docs.php` (sub-command); ARCHITECTURE dispatch-arm count if a new arm lands.
- effort: M; deps: none.

### GAP-HK-06 · Managed hook tier
- have / missing: user + trusted project only. Claude Code ✅ (managed settings, plugin and frontmatter hooks).
- approach: an admin-owned `/etc/candy-code/hooks.yaml` (root-owned, non-writable check via `requirePrivatePolicyFile` adapted for uid 0) loaded first and not disable-able by user/project files.
- files: modify `src/Cli/Bootstrap.php` (`hookFiles`, hot), `src/Hooks/HookManager.php`, docs/HOOKS.md.
- guards/docs: HOOKS.md source-table drift guard; ReadPathCensus row for the new path.
- effort: S; deps: none.

### GAP-MCP-03 · Streamable HTTP GET stream and streamed replies
- have / missing: POST + buffered reply, session id, 404 re-init, DELETE. Missing: the optional GET listener (server-pushed notifications/requests) and incremental SSE reply reads (progress); Claude Code and OpenCode ✅.
- approach: reuse `SseMcpServer`'s deadline-bounded raw-socket stream reader and `SseLineParser` inside `HttpMcpServer` for an opt-in GET channel opened after `initialize`; keep the buffered POST path as default; route GET frames into the server-request handler from GAP-MCP-08.
- files: modify `src/MCP/HttpMcpServer.php`, `src/MCP/SseLineParser.php` (share), `docs/MCP.md`; create `tests/MCP/HttpMcpServerGetStreamTest.php`.
- guards/docs: DocFigure MCP transport arms (`tests/Config/DocFigureProseDriftTest.php`), `tools/check-child-lifetimes.php` unaffected (no proc_open); new test file → `scripts/parallel-tests-durations.tsv` + `suite-figure.json`.
- effort: L; deps: GAP-MCP-08 (frame handler) first.

### GAP-MCP-04 · OAuth from the browser
- have / missing: CLI PKCE + TUI fork; served `/mcp login` prints shell guidance. Claude Code ✅ `/mcp` OAuth.
- approach: add a `login` arm to `McpAuthHostCommand` that, on a served host, uses `McpLoginFork::arm()` and returns the authorize URL as a reply row; add a paste-back leg (`/mcp login <server> --code <url>`) so a remote browser can finish without reaching 127.0.0.1, completing through `OAuthLoopbackFlow`'s exchange.
- files: modify `src/Host/Commands/McpAuthHostCommand.php`, `src/MCP/OAuthLoopbackFlow.php`, `src/Support/McpLoginFork.php`, `lang/en.php` (hot), `docs/MCP.md`, `docs/SERVER.md` (hot).
- guards/docs: `/mcp` verb roster fence in DocFigure; `tools/gen-command-docs.php` if the hint changes.
- effort: M; deps: none.

### GAP-MCP-06 · `@mcp:` mentions in served sessions and `-p`
- have / missing: TUI only; `SessionHost` calls `userTurnMessage()` without `$mcpProvider`. Claude Code / OpenCode ✅.
- approach: pass a lazy `fn() => Bootstrap::mcpClient($root)` provider from `SessionHost::submit…` (and the NonInteractive turn path) exactly as `Chat::mcpClientProvider()` does; drop the "TUI-only" paragraph in MCP.md.
- files: modify `src/Host/SessionHost.php`, `src/Cli/NonInteractive.php` (headless set, hot), `docs/MCP.md`.
- guards/docs: DocFigure `@mcp` parity legs (d41cd8cb6 pinned both wiring vocabularies) must flip in-step.
- effort: S; deps: none.

### GAP-MCP-07 · MCP prompt commands in served sessions
- have / missing: Chat merges `McpPromptCommands::specs()`; SessionHost and `command.list` read only `CommandLoader`. Claude Code / OpenCode ✅.
- approach: in `SessionHost` ctor and `CommandMethods` list, merge `McpPromptCommands::specs($provider)` under file rows (same precedence as `Chat.php:1796`); materialize at submit via `McpPromptCommands::materialize()` in `TurnController`.
- files: modify `src/Host/SessionHost.php`, `src/Protocol/Methods/CommandMethods.php` (protocol chain), `src/Host/TurnController.php`; `candy-code-web` needs no change (`/` popup lists `command.list`).
- guards/docs: `docs/COMMANDS.md` via `tools/gen-command-docs.php` if it lists sources; protocol schema unchanged.
- effort: S–M; deps: GAP-MCP-06 (shared provider).

### GAP-MCP-08 · Server-initiated requests (ping, roots, elicitation)
- have / missing: empty capabilities; server requests skipped unanswered. Claude Code ✅ elicitation; OpenCode v2 🟡.
- approach: in `sugar-mcp-client` `StdioMcpServer` read loop, hand method-bearing frames with an id to an injectable `?\Closure $onServerRequest`; candy-code answers `ping` → `{}`, `roots/list` → the project root, `elicitation/create` → a `PermissionGate`/AskUser ask (TUI modal, web `permission.asked`), everything else → JSON-RPC -32601. Mirror in `HttpMcpServer`/`SseMcpServer` reply parsing; advertise only the capabilities implemented.
- files: modify `sugar-mcp-client/src/StdioMcpServer.php`, `src/MCP/StdioMcpServer.php`, `src/MCP/HttpMcpServer.php`, `src/MCP/SseMcpServer.php`, `src/Permissions/` ask path, protocol (`src/Protocol/**` → `docs/protocol` → `generated.ts`, hot) if elicitation surfaces in the web.
- guards/docs: `McpMessageWireShapeTest`, docs/MCP.md capability section.
- effort: L; deps: none.

### GAP-MCP-10 · Runtime enable/disable + web liveness
- have / missing: enable/disable only via `disabledMcpServers` + relaunch; web panel has no liveness/user tier. Claude Code / OpenCode ✅ toggles.
- approach: `/mcp disable|enable <server>` add a session deny pattern on the live `McpClient` (calls refused at `isServerAllowed()`; roster unchanged per cache law) and offer `--persist` writing `disabledMcpServers` in user config; in `ContextSources` add `liveness` from `Bootstrap::mcpLivenessSnapshot()` and the `userServers` rows.
- files: modify `src/Commands/McpAuthCommand.php`, `src/MCP/McpClient.php`, `src/Host/ContextSources.php`, `src/Protocol/Schema/MethodSchemas.php` (protocol chain, hot), `candy-code-web/src-web/components/panels/ContextPanel.vue`, `lang/en.php` (hot), `builtin-commands/0900-mcp.php` (spec row, hot).
- guards/docs: `/mcp` five-verb fence in DocFigure; `tools/gen-command-docs.php`.
- effort: M; deps: none.

### GAP-MCP-11 · Preset `mcpServers` scoping for `mcp_call`/`mcp_list`
- have / missing: bridges filtered; dispatchers reach every deferred server for a scoped sub-agent.
- approach: wire the dormant `McpClient::setAgentPreset()` in the sub-agent child (fork-local copy) before tools run, so `callTool()`'s `isServerAllowed()` enforces the allowlist; make `McpListTool` filter its roster by `McpRouter::serverAllowed()`.
- files: modify `src/Agents/AgentManager.php` or the TaskTool child bootstrap, `src/Tools/BuiltIn/McpListTool.php`, `docs/AGENTS.md`; create `tests/Agents/McpDispatcherPresetScopeTest.php`.
- guards/docs: new test → durations TSV + suite figure.
- effort: S; deps: none.

### GAP-MCP-13 · Act as an MCP server
- have / missing: no server mode; Claude Code and OpenClaw ✅.
- approach: `candy-code mcp serve` (stdio) exposing the read-only catalog tools (Read/Glob/Grep/Ls/RepoMap) through `ToolCatalog` + `ToolBuildContext`, JSON-RPC framing from `sugar-mcp-client` `McpMessage`, every call through `PermissionGate` at a non-interactive floor (exec-class refused).
- files: create `src/MCP/McpServeHost.php`; modify `src/Cli/Subcommands.php` (verb roster 2587), `bin/candy-code`/`src/Cli/ArgvParser.php` only if flags (headless set, hot), `docs/MCP.md`, `README.md` (hot).
- guards/docs: completion roster + help screen tests; DocFigure `mcp` verb list.
- effort: L; deps: none.

### GAP-MCP-14 · MCP images to vision models
- have / missing: image/audio parts announced by size; Claude Code returns images to the model.
- approach: in `McpToolBridge::replyResult()` attach decoded image parts to the `ToolResult` image channel already used by Read's tool-result image blocks (gated on `supportsVision()`), keeping the size line for non-vision providers.
- files: modify `src/Tools/McpToolBridge.php`, `src/Tools/BuiltIn/McpCallTool.php` (shares replyResult).
- guards/docs: PromptStability pins for image-bearing results; docs/MCP.md output section.
- effort: M; deps: none.

### GAP-MCP-15 · Importer that writes
- have / missing: `mcp import` prints only (never-write law); Claude Code `add-from-claude-desktop` and OpenClaw migrate write.
- approach: opt-in `--write user` appending translated entries into `~/.candy-code/mcp.json` through `AtomicJsonFile` (user tier only, refuse name collisions); add per-project `~/.claude.json` `projects[<root>].mcpServers` as a source.
- files: modify `src/Cli/Subcommands.php` (headless set, hot), `src/MCP/McpForeignTranslate.php`, `src/Cli/ParsedArgs.php` (SUBCOMMAND_FLAGS, hot), `docs/MCP.md`.
- guards/docs: DIRECT_SITES / write-site census credits `mcp import` one writer today — re-pin; `ReadPathCensus`.
- effort: S–M; deps: owner ruling (relaxes the never-write law).

### GAP-MCP-N1 · Re-approve a changed server from the browser
- have / missing: trust prompt only for an untrusted project; pin mismatch on a trusted root needs CLI.
- approach: add a second `TrustAsks` target (`mcp-pin`) minted by `ContextMethods::sources()` when `McpClient::refusedServers()` is non-empty; `context.trust` answers it through `Bootstrap::trustProjectMcp()` (re-records pins, next launch).
- files: modify `src/Protocol/TrustAsks.php`, `src/Protocol/Methods/ContextMethods.php`, `src/Protocol/Schema/*` → `docs/protocol` → `generated.ts` (protocol chain, hot), `ContextPanel.vue`, `lang/en.php` (hot).
- guards/docs: protocol schema regen (`scripts/gen-protocol-schema.php`).
- effort: M; deps: none.

### GAP-PLG-02 · Marketplace/registry
- have / missing: path/git install only; Claude Code marketplaces, OpenCode npm, OpenClaw ClawHub ✅.
- approach: `bundles marketplace add <git-url>` + `bundles install <name>@<marketplace>` reading Claude's `marketplace.json`, resolving to the existing bounded clone in `bundlesInstall`; `bundles update` re-clones by recorded source.
- files: modify `src/Cli/Subcommands.php` (hot), `src/Bundles/BundleDiscovery.php`; create `src/Bundles/Marketplace.php`; `docs/PLUGINS.md`, `README.md` (hot).
- guards/docs: `ParsedArgs::SUBCOMMAND_FLAGS`, completion roster, DocFigure subcommand fence.
- effort: M–L; deps: none.

### GAP-PLG-04 · Code-defined custom tools in the shipped binary
- have / missing: MCP only; OpenCode `.opencode/tools`, Claude Code mods ✅.
- approach: user-tier script tools: `~/.candy-code/tools/<name>.json` (schema + argv) built as a catalog-shaped `Tool` spawning via `ProcessContainment::spawnSpec`, exec permission class, never from project tier.
- files: create `src/Tools/ScriptTool.php`, `src/Tools/ScriptToolLoader.php`; modify `src/Tools/Catalog/ToolCatalog.php` (position roster, hot), `src/Cli/Bootstrap.php` (hot), `docs/` tool docs via `tools/gen-tool-docs.php`.
- guards/docs: `tools/check-child-lifetimes.php` roster row; ToolCatalog position pins.
- effort: L; deps: owner ruling.

### GAP-PLG-05 · Published SDK
- have / missing: protocol types + `CandyCodeClient` private; Claude/OpenCode SDKs ✅.
- approach: split `candy-code-web/src-web/protocol/` into a publishable `@sugarcraft/candy-code-client` package (types generated from `docs/protocol/candy-code.v1.schema.json`).
- files: create `candy-code-web/packages/client/package.json`; modify `candy-code-web/package.json`, `docs/SERVER.md` (hot).
- guards/docs: generated.ts freshness check.
- effort: M; deps: none.

### GAP-EDT-02 · Optional read-before-write
- have / missing: stale-hash refusal only; Claude Code enforces read-before-edit.
- approach: user-tier `requireReadBeforeWrite` bool: Edit/Write/ApplyPatch refuse an existing file with no `ReadLedger` entry.
- files: modify `src/Tools/ReadLedger.php`, `src/Tools/BuiltIn/{Edit,Write,ApplyPatch}.php`, settings definition + `tools/gen-settings-doc.php` output.
- guards/docs: `LayeredSettings` roster, SETTINGS.md.
- effort: S; deps: none.

### GAP-EDT-03 · Web ask diff with file context
- have / missing: client-side string diff; ApplyPatch raw JSON.
- approach: compute `Edit/Write/ApplyPatch::previewDiff($root, $args)` when the host raises the ask and ship it as an optional `diff` field on `permission.asked`; `PermissionCard` prefers it over `askDiffPreview()`.
- files: modify `src/Host/` ask emission, `src/Protocol/Schema/EventSchemas.php` → `docs/protocol` → `generated.ts` (protocol chain, hot), `candy-code-web/src-web/components/PermissionCard.vue`.
- guards/docs: protocol regen; web unit tests.
- effort: M; deps: none.

### GAP-EDT-04 · Built-in formatter defaults
- have / missing: `formatCommands` only; OpenCode 26 built-ins.
- approach: opt-in `formatAuto` using a default extension→formatter table in `FormatRunner` gated on binary presence through the injectable probe seam `LspLauncher` already uses; explicit `formatCommands` entries win.
- files: modify `src/Format/FormatRunner.php`, `src/Cli/Bootstrap.php` (hot), settings definition, `docs/SETTINGS.md`, `docs/HOOKS.md`.
- guards/docs: `tools/gen-settings-doc.php`; settings roster tests.
- effort: M; deps: none.

### GAP-EDT-07 · LSP auto-install
- have / missing: auto-detect without download; OpenCode 1.x auto-installs.
- approach: `candy-code lsp install <language>` (explicit, not automatic) fetching pinned, checksum-verified releases into `~/.candy-code/lsp/bin`, which `AUTO_TABLE` probes first.
- files: modify `src/LSP/LspLauncher.php`, `src/Cli/Subcommands.php` + `ParsedArgs::SUBCOMMANDS` (headless set, hot); create `src/LSP/LspInstaller.php`.
- guards/docs: DocFigure subcommand fence, help screen.
- effort: L; deps: owner ruling (download policy).

### GAP-EDT-10 · BOM preservation
- have / missing: CRLF inheritance only; OpenCode preserves BOM.
- approach: `IndentShift::inheritBom()` beside `inheritEndings()`: strip a UTF-8 BOM before matching and re-prepend on write in `EditMatcher`, Write overwrite and `ApplyPatch::normalizeEndings` callers.
- files: modify `src/Tools/Edit/IndentShift.php`, `src/Tools/Edit/EditMatcher.php`, `src/Tools/BuiltIn/Write.php`, `src/Tools/BuiltIn/ApplyPatch.php`.
- guards/docs: tool docs via `tools/gen-tool-docs.php` only if descriptions change.
- effort: S; deps: none.

Rows with no competitor ✅ and no separate client work (TL-14, SH-02; web 🟡 "Inherited/invisible" marks on TL-16, SH-01, SH-03, SH-06, SH-07, SH-10) are omitted; their engine gaps below cover them.

### GAP-TL-12 · WebFetch returns raw HTML
- have / missing: SSRF-pinned fetch of raw bytes (2 MiB wire / 64 KiB to model); missing HTML→Markdown/text conversion that Claude Code, both OpenCodes and OpenClaw do (`WebFetch.php:268` says "verbatim without converting HTML").
- approach: new `HtmlToMarkdown` (ext-dom `DOMDocument` + libxml, drop script/style/nav/svg, keep headings/links/lists/code/tables) applied when Content-Type is text/html; `format: "markdown"|"raw"` arg (default markdown); cap applies after conversion; untrusted-content framing unchanged.
- files: create `candy-code/src/Tools/Web/HtmlToMarkdown.php`, `candy-code/tests/Tools/Web/HtmlToMarkdownTest.php`; modify `candy-code/src/Tools/BuiltIn/WebFetch.php` (description + schema + body path).
- guards/docs: `php tools/gen-tool-docs.php --write` (ToolDocDriftTest); prompt-prefix byte-stability pins that snapshot tool schemas; `candy-code/tests/Config/Support/suite-figure.json` + `scripts/parallel-tests-durations.tsv` for the new test file.
- effort: M; deps: none.

### GAP-TL-13 · Web search needs a self-hosted SearXNG
- have / missing: WebSearch against `CANDY_CODE_SEARCH_ENDPOINT`/`webSearchEndpoint` only, no default (`WebSearch.php:68-69,136-138`); Claude Code ships provider-side search, OpenCode 2 and OpenClaw ship hosted backends.
- approach: `SearchBackend` interface with SearXNG (today's code), Brave/Tavily/Exa API-key backends chosen by a `webSearchBackend` setting; optionally the Anthropic server-side `web_search` tool when the provider is AnthropicProvider (a server-tool leg in `AnthropicMessagesWire`).
- files: create `candy-code/src/Tools/Web/SearchBackend.php` + one class per backend; modify `candy-code/src/Tools/BuiltIn/WebSearch.php`, `candy-code/src/Config/Settings/Definitions/ToolSettings.php`, `candy-code/src/Tools/ToolLimits.php`, `candy-code/lang/en.php` (hot), `candy-code/README.md` + `candy-code/docs/ENVIRONMENT.md` (hot).
- guards/docs: EnvRosterDriftTest (new API-key env vars), `tools/gen-settings-doc.php`, HelpTest env screen, gen-tool-docs.
- effort: M (L with the Anthropic server tool); deps: none.

### GAP-TL-15 · No notebook tool
- have / missing: no `.ipynb` handling anywhere in `src/` (grep 0); Claude Code ships NotebookEdit.
- approach: `NotebookEdit` (Write class, next free position 41) — replace/insert/delete by `cell_id`, JSON round-trip preserving metadata/outputs, `AtomicFileWriter::replace`, ReadLedger staleness, PathJail; Read renders `.ipynb` as numbered cell pages instead of raw JSON.
- files: create `candy-code/src/Tools/BuiltIn/NotebookEdit.php`, tests; modify `candy-code/src/Tools/BuiltIn/Read.php`, `candy-code/src/Runtime.php` (`WRITE_CAPABLE_TOOL_NAMES`, hot).
- guards/docs: the catalog position roster (attribute only — `ToolCatalog` discovers it), gen-tool-docs (README "thirty-one" count anchors + Capabilities roster), `tests/Integration/PharCompatTest.php` census, suite-figure + durations tsv.
- effort: M; deps: none.

### GAP-TL-19 · Read images: non-Anthropic wires and the web card
- have / missing: TUI paints Read images; model sees them only on the Anthropic wire; web MediaCard for a Read image cannot fetch (code-traced). Competitors ✅ everywhere.
- approach: (1) web: copy an in-cap Read image into the session `MediaStore` at projection time (or project `media` only for paths `MediaStore::pathFor()` owns) so `media.fetch` serves it; (2) model: Gemini `functionResponse` + `inlineData` parts and OpenAI Responses `input_image` in `function_call_output` where the endpoint supports it, keeping the documented decline for chat-completions `role: tool`.
- files: modify `candy-code/src/Host/TranscriptProjector.php` (mediaRefs), `candy-code/src/Support/MediaStore.php`, `candy-code/src/Providers/Concerns/GeminiGenerateContentWire.php`, `candy-code/src/Providers/OpenAIProvider.php`; protocol shape unchanged.
- guards/docs: PromptStability pins for image-bearing prompts; `docs/MEDIA.md`.
- effort: S (web half) / M (wire half); deps: none.

### GAP-TL-20 · The model cannot read a PDF
- have / missing: user `@file.pdf` → document block on Anthropic/Vertex-Claude; Read gives a notice whose "mention the file" advice the model cannot act on; every competitor's Read reads PDFs.
- approach: mirror the image path — `ToolResult` gains document bytes, `AnthropicMessagesWire` emits a `document` block inside `tool_result` when `supportsDocuments()`; elsewhere Read runs `pdftotext` itself when `Bootstrap::capabilityPresent('pdftotext')` and pages the text with a `pages` arg; fix the notice wording.
- files: modify `candy-code/src/Tools/BuiltIn/Read.php`, `candy-code/src/ToolResult.php`, `candy-code/src/Providers/Concerns/AnthropicMessagesWire.php`, `candy-code/src/Runtime.php` (field threading, hot).
- guards/docs: ReadBinaryScan test polarity, gen-tool-docs (Read description), PharCompat if a new capability probe.
- effort: M; deps: none.

### GAP-TL-21 · No browser control
- have / missing: none; Claude Code (Claude in Chrome) and OpenClaw ✅.
- approach: ship a documented Playwright/Chrome-DevTools MCP preset installable via `mcp import` first (zero engine code); an in-tree `Browser` tool over CDP only if demand proves it.
- files: modify `candy-code/docs/MCP.md` (preset), optionally `candy-code/src/Cli/Subcommands.php` (preset installer, headless set — hot).
- guards/docs: DocFigure MCP arms if MCP.md counts move.
- effort: S (preset) / L (native tool); deps: none.

### GAP-TL-22 · No model-callable schedule tool
- have / missing: `/loop` + `/schedules` over `ScheduleRegistry`; Claude Code `CronCreate/Delete/List` and OpenClaw cron tool ✅.
- approach: `Schedule` tool (Ask class, position 41+) with create/list/delete over the same registry, the dont-ask floor and due-count bounds the commands use; handle via `ToolBuildContext`.
- files: create `candy-code/src/Tools/BuiltIn/ScheduleTool.php`; modify `candy-code/src/Tools/Catalog/ToolBuildContext.php`, `candy-code/src/Cli/LaunchContext.php` (threading the workspace's `ScheduleRegistry` service), `candy-code/src/Runtime.php` (write-capable pin).
- guards/docs: the permissionMode-reader census roster that rosters ScheduleRegistry, gen-tool-docs, suite figure.
- effort: M; deps: none.

### GAP-TL-23 · Messaging stops at the agent's own tree
- have / missing: SendMessage/Subagents/InterruptAgent/Team/BoardPost inside one tree; Claude Code reaches other sessions, OpenClaw channels.
- approach: `SendMessage` gains a `session:<id>` target routed through the existing `bg.inject` / `SessionHost` submit path, gated as Ask and limited to sessions of the same workspace.
- files: modify `candy-code/src/Tools/BuiltIn/SendMessageTool.php`, `candy-code/src/Host/SessionHost.php`.
- guards/docs: gen-tool-docs, protocol docs if a method moves.
- effort: M; deps: none.

### GAP-TL-24 · No canvas
- have / missing: none; Claude Code `Artifact`, OpenClaw canvas ✅.
- approach: `Canvas` tool writing HTML into the session `MediaStore` (new media kind) rendered by the web in a sandboxed iframe (CSP `sandbox`, no same-origin); TUI shows a path.
- files: create `candy-code/src/Tools/BuiltIn/CanvasTool.php`, `candy-code-web/src-web/components/CanvasCard.vue`; modify the protocol chain `src/Protocol/**` → `docs/protocol` → `candy-code-web/src-web/protocol/generated.ts` (hot), CSP in `src/Server/Http/Responses.php`.
- guards/docs: protocol schema drift, web unit tests.
- effort: L; deps: none.

### GAP-TL-25 · No computer use
- have / missing: none; OpenClaw ✅, Claude Code 🟡 (macOS preview).
- approach: low priority; MCP preset route as for TL-21.
- files: `candy-code/docs/MCP.md`.
- guards/docs: none.
- effort: S (doc) / L (native); deps: none.

### GAP-TL-27 · Plan mode keeps the full tool list
- have / missing: the gate refuses writes in plan mode, but the roster is unchanged; every competitor scopes the tool set.
- approach: in `EngineBackend::turnTools` drop `Runtime::WRITE_CAPABLE_TOOL_NAMES` while the gate's mode is Plan (PlanExit stays); accept one prompt-cache break per mode switch and document it beside the cache law.
- files: modify `candy-code/src/Backend/EngineBackend.php`, `candy-code/src/Runtime.php` (hot), `candy-code/docs/PERMISSIONS.md` (hot).
- guards/docs: PromptPrefixByteStability family (mode-switch carve-out), PermissionsIntroDriftTest ("tool list is the same in every mode" sentence).
- effort: M; deps: none.

### GAP-TL-N3 · No code-mode tool
- have / missing: Workflow + Bash only; OpenCode 2 and OpenClaw ✅.
- approach: a `Script` tool running a constrained PHP/JS child whose only I/O is a JSON-RPC tool bridge over a socket pair (the fork+socket pattern EngineBackend uses for workers), every inner call through the same PermissionGate + PreToolUse chain.
- files: create `candy-code/src/Tools/BuiltIn/ScriptTool.php`, `candy-code/src/Tools/Script/*`; modify `candy-code/src/Backend/EngineBackend.php`, `tools/check-child-lifetimes.php` roster (new proc_open site).
- guards/docs: check-child-lifetimes, gen-tool-docs, permission parity tests.
- effort: L; deps: none.

### GAP-SH-01 · No input into a running pty across calls
- have / missing: `interactive: true` (private pty, 8 s idle ceiling, no input) and PtyRun (scripted steps in one call); OpenClaw `process send-keys` ✅.
- approach: background shells with a pty (`run_in_background` + `interactive`) plus a `ShellSend` tool writing keystrokes to the shell's pty master; reuse `PtyDriver` and `BackgroundShellRegistry`; keystrokes go through the PtyRun step gate.
- files: create `candy-code/src/Tools/BuiltIn/ShellSend.php`; modify `candy-code/src/Background/BackgroundShellRegistry.php`, `candy-code/src/Tools/BuiltIn/Bash.php` (lift the background+interactive refusal at :515-518).
- guards/docs: check-child-lifetimes, WRITE_CAPABLE/ShellSubjectTools roster, gen-tool-docs.
- effort: L; deps: none.

### GAP-SH-04 · No human view of background shells; shared table in serve
- have / missing: model-only ShellOutput/ShellStop; no `/shells` list or kill in TUI or web (Claude Code `/tasks`, Ctrl+B ✅); serve shares one registry per process+root across sessions.
- approach: `/shells` built-in (list/output/stop) with a host command so the web gets it free through `command.exec`, plus a Background-panel tab; key the registry by session id in serve (`forSession($sessionId)` instead of `'main'`).
- files: create `candy-code/builtin-commands/29xx-shells.php`, `candy-code/src/Host/Commands/ShellsCommand.php`; modify `candy-code/src/Cli/Bootstrap.php:8396-8406` + `candy-code/src/Cli/LaunchContext.php:690-694` (hot), `candy-code/src/Chat.php` handler (hot), `candy-code/lang/en.php`, `candy-code-web/src-web/components/panels/BackgroundPanel.vue`.
- guards/docs: `tools/gen-command-docs.php` (docs/COMMANDS.md), BuiltInCommands roster pin, MenuBarLabelDerivationTest, PaletteSupersetDispatchTest survivor roster, README roster.
- effort: M; deps: none.

### GAP-SH-05 · Browser cannot type into a running command
- have / missing: web ❌; OpenClaw `process input` ✅.
- approach: a `shell.input` protocol method feeding GAP-SH-01's `ShellSend` path, human-originated and gated like a permission answer.
- files: modify the protocol chain (hot), `candy-code-web/src-web/components/ToolCard.vue`.
- guards/docs: protocol schema drift, generated.ts.
- effort: M; deps: GAP-SH-01.

### GAP-SH-06 · Sandbox breadth
- have / missing: Linux bwrap for Bash/PtyRun; no macOS Seatbelt, hooks/lint/test/LSP/MCP unjailed; Claude Code and OpenClaw ✅.
- approach: a `SandboxBackend` seam with Bubblewrap + `sandbox-exec` profile; apply the prefix at `ProcessContainment::spawnSpec` for hook/lint/test runners behind the same setting.
- files: create `candy-code/src/Tools/Sandbox/Seatbelt.php`, `SandboxBackend.php`; modify `candy-code/src/Support/ProcessContainment.php`, `candy-code/src/Config/Settings/Definitions/ToolSettings.php`, `candy-code/docs/PERMISSIONS.md` (hot).
- guards/docs: gen-settings-doc, BashSandboxTest family, check-child-lifetimes.
- effort: L; deps: none.

### GAP-SH-07 · No network allowlist
- have / missing: all-or-nothing `no-network`; Claude Code and OpenClaw proxy with domain lists ✅.
- approach: an in-process CONNECT proxy (ReactPHP) bound to a unix socket bind-mounted into the bwrap namespace, consulting the WebFetch domain rules; `bashSandbox: "allowlist"` mode.
- files: create `candy-code/src/Tools/Sandbox/EgressProxy.php`; modify `candy-code/src/Tools/Sandbox/Bubblewrap.php`, ToolSettings, `candy-code/docs/PERMISSIONS.md` (hot).
- guards/docs: gen-settings-doc, sandbox tests, check-child-lifetimes.
- effort: L; deps: GAP-SH-06 seam helps.

### GAP-SH-08 · TUI paints no live shell output
- have / missing: Bash emits `ToolProgress`; Chat records and discards it; web and stream-json show it; every competitor shows live output.
- approach: a `ToolTailSlots` store beside `PreviewSlots` (Chat.php:3697), set from the two ToolProgress folds and cleared on ToolFinished; Renderer paints the last N lines under the running placeholder (display-cell budgeted, one line per row); add `AcceptsToolProgress` to PtyRun via its chunk callback.
- files: create `candy-code/src/Tui/ToolTailSlots.php`; modify `candy-code/src/Chat.php` (hot), `candy-code/src/Renderer.php` (hot), `candy-code/src/Tools/BuiltIn/PtyRun.php`.
- guards/docs: renderer golden tests, over-wide-line invariant tests.
- effort: M; deps: none.

## Dormant code
- `src/App/App.php:2528` `dispatchSkill()` — no production caller; `context: fork` skills run inline. Wiring: call it (or a shared helper) from `SkillTool` when `SkillRegistry::isContextFork()` is true (GAP-SKL-15).
- `src/Skills/SkillLoader.php:1054` `loadSkillAsset()` — containment-checked stage-3 loader with no caller. Wiring: an `asset` argument on the Skill tool (GAP-SKL-10).
- `src/App/App.php:2362` `applySkillsToSystemPrompt()` and `src/App/App.php:2389` `findSkillsForTask()` — no production caller (Runtime builds the listing through `SkillMatcher`). Wiring: route the shell App's enabled-skill prompt assembly through them or fold them into `SkillMatcher` callers.
- `src/Skills/SkillManager.php:236,256,275,292,313` `skillsForTask()`/`skillsForPaths()`/`applyToApp()`/`enable()`/`disable()` — embedder accessors with no production caller. Wiring: `applyToApp`/`enable`/`disable` are the natural backing for a `/skills enable|disable <name>` command (and a web toggle) that writes `disabledSkills`.
- Inert frontmatter (parsed, carried, read by nothing): skill `allowed-tools`, `disallowed-tools`, `model`, `effort`, `context`; command `model`, `subtask` (`src/Support/FrontmatterKeyAudit.php:120-135`). Wiring: GAP-SKL-11, GAP-SKL-15, GAP-CMD-04.
- `src/MCP/McpClient.php:139,167,178-181,831-832,895-896` — `McpClient::setAgentPreset()` and the preset-routed `listTools()`/`isServerAllowed()` branch have no production caller (`Bootstrap.php:8015` always builds the client unrestricted, no preset). Wiring: set the preset on the forked sub-agent's client copy so `mcp_call` honours `mcpServers` (GAP-MCP-11).
- `src/Cli/McpDecision.php:303-307,336-339` — the census computes `userServers`/`userSkipped`/`userError` for `~/.candy-code/mcp.json`, but `ContextSources::sources()` maps only the project `servers` (`src/Host/ContextSources.php:158-172`), so the web never shows user-tier servers. Wiring: add a user-tier block to `context.sources` + ContextPanel (GAP-MCP-10).
- `src/Attachments/ContextMentions.php:125,153-155` + `src/Commands/McpPromptCommands.php` — reachable from the TUI only; the served (`src/Host/SessionHost.php:1702-1710,241-244`) and headless paths never receive the MCP reader or prompt specs. Wiring: GAP-MCP-06/07.
- `candy-code/src/Chat.php:3702-3711` and `:4230-4237` — `ToolProgress` frames reach the TUI fold and are recorded but never painted ("The TUI paints no live tail"). Wiring: a slot store + Renderer leg under the running tool placeholder (GAP-SH-08).
- Broken live path (not dormant): `candy-code/src/Host/TranscriptProjector.php:301-303` projects Read's workspace `imagePath` as a media ref that `candy-code/src/Protocol/Methods/MediaMethods.php:499-509` cannot serve, so the web shows a failing MediaCard (GAP-TL-19, code-traced, not run live).
