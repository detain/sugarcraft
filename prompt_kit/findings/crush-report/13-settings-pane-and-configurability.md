# 13 — A settings pane for sugar-crush, and making its behaviour configurable (design report)

**What this is.** A design report, based on reading the source, for an in-TUI settings editor in `sugar-crush`. It covers:
- making the hard-coded behaviours below into settings;
- building the editor from existing SugarCraft libraries.

Nothing in the repo was changed. Paths are relative to `sugar-crush/` unless they start with `/` or with a sibling lib's directory (`candy-forms/…`). Line numbers come from the working tree on master @ `05db616f3` (2026-10-01).

**Inputs:**
- `00-sugar-crush-baseline.md` §1, §9, §10 (read in full);
- the §13 recommendations of reports 01–12 (scanned for every knob they propose);
- `src/Config/*`, `src/Cli/Bootstrap.php`, `src/Backend/EngineBackend.php`, `src/Chat.php`, `src/Tui/**`, `src/Palette/*`, `src/Commands/*`;
- `docs/SETTINGS.md`, `docs/ENVIRONMENT.md`, the five drift tests named in the brief, `README.md`;
- the source and READMEs of every `candy-*` / `sugar-*` / `honey-*` lib under `/home/sites/sugarcraft`.

**Labels:**
- **[V]** — verified in source by this author;
- **[I]** — inferred from reading, not run;
- **[P]** — proposal.

---

## 0. Summary

1. **No settings editor exists.** There is a *read-only* `Settings` sidebar (`src/Tui/Components/SettingsPane.php`, 137 lines) with eight rows: Provider, Model, Theme, Root, Session, Mouse, Mouse clicks, Streaming. Its footer says `read-only — /theme, /model` [V]. `Ctrl+,` focuses it (`src/Tui/KeyboardHandler.php:850`), and `Enter` on it opens the command palette (`KeyboardHandler::enterOpensPaletteDoor()`, `:297`) [V].
2. **Only three keys are ever written by the app** [V]:
   - `provider` and `theme`, through `Chat`'s `onConfigChange` closure (`src/Chat.php:14140`, `:14151`, `:16037`; wired at `src/Cli/Bootstrap.php:1215`);
   - `layout`, through `App::$onLayoutChange` (`src/App/App.php:724`).

   `ConfigWriteProducerDocumentationDriftTest::testTheConfigChangeCallbackEverReceivesExactlyTwoKeys()` pins this. Any editor must add a **separate, censused write door**, not widen `onConfigChange`.
3. **There are ~26 config keys today.** That is 16 layered keys, 4 trust keys, 2 permission keys, 3 `claudeMcp*` keys and `enabledSkills`. On top of them come 25 `SUGARCRUSH_*` env vars, 10 CLI flags, and package-level provider definitions in `.sugar-crush/config.dev.json`.
   - About 60% of the keys are scalar or enum and easy to edit in a UI.
   - The rest are lists of names, which a multi-select over discovered items handles.
   - A few are nested structures — `permissionRules`, `modelPrices`, `statusLine`, `layout`, `claudeMcpEnv`, providers — that need a table editor or should stay read-only.
4. **The settings files are already re-read every turn, inside the forked turn child** (`EngineBackend::runTurn()`, `src/Backend/EngineBackend.php:782`) [V]. A value written to disk therefore reaches the agent loop on the next turn with no extra plumbing.
   - Only `parallelToolCalls`, `parallelToolDeadlineSeconds` **and `maxOutputTokens`** are applied there (`:785-790`).
   - `docs/SETTINGS.md` "When a change takes effect" names only the first two — a small doc drift worth fixing in passing [V].
   - Everything else is consumed once at launch, so live apply needs explicit hooks: backend swap, `Chat` mutation, or a `Cmd`.
5. **At least 45 hard-coded behaviour constants should become settings** (§2). The competitor reports add about 30 knobs that do not exist yet (§2.3).
   - The most important are `maxToolSteps` (default 8, already a key but user-tier and launch-only), the 120 s no-progress watchdog, the compaction thresholds `70/85/95/10`, the tool output caps, the memory caps `12/4 KiB/512 B`, and the private WebSearch default host.
   - Then the competitor knobs: Bash timeout, auto-commit, lint and test commands, context pruning, doom-loop thresholds, sub-agent model and concurrency, steering mode, notifications.
6. **Every library the editor needs is already a dependency of sugar-crush** (§3):
   - `candy-forms` for fields, `Form`/`Group`, validators and `hydrate()`;
   - `candy-fuzzy` for search;
   - `candy-focus` for the focus ring;
   - `candy-mouse` for click zones;
   - `sugar-veil` for the confirm and diff modals;
   - `candy-sprinkles` for styles, borders and `Table`;
   - `candy-layout` for the dock and `Region`;
   - `candy-core` for `AtomicJsonFile`, `Cmd::enableMouse*/disableMouse` and `I18n\Lang`.

   `candy-forms`' README even names "edit settings" screens as the use case for `withValue()`/`hydrate()`. Optional extra dependencies are `sugar-diff` (save preview) and `sugar-toast` ("saved" / "restart required" toasts).
7. **Recommended design** [P]:
   - one **`SettingsSchema` registry** of `SettingDefinition` rows, used to generate or check the form, `docs/SETTINGS.md`'s key tables, the env cross-reference and the tier rules;
   - a **full-band `SettingsEditor` view**, the same pattern as the Agents dashboard, with category tabs, a field list, a detail/provenance panel and fuzzy search;
   - a **`SettingsWriter`** that writes the user tier to `config.json`, the project tier to `settings.local.json`, and holds a session tier in memory;
   - per-key **apply modes**: `live`, `next turn` or `restart`, shown as badges;
   - a **diff preview** before saving;
   - **trust edits are user-tier only**, take effect only after a restart, and the project tier can never change them.
8. **Phasing** (§5): P0 schema and drift (S) → P1 read-only viewer (M) → P2 editing and writer (M) → P3 live apply and session tier (M) → P4 promoting hard-coded constants in batches (L, incremental) → P5 sidebar and trust polish (S).

---

## 1. The current settings and config surface

### 1.1 Files and layers [V]

| Layer | Path | Reader | Writer | Gate |
|---|---|---|---|---|
| 1 | `<root>/.sugar-crush/settings.json` | `LayeredSettings::projectLayer()` (`src/Config/LayeredSettings.php`) filtered to `PROJECT_TIER_KEYS` | nobody (hand-authored) | root ∈ `trustedProjectSettings` **and** a root was named via `Bootstrap::useProjectRootForSettings()`; `ContainedPath` checks on dir and file |
| 2 | `<root>/.sugar-crush/settings.local.json` | same | nobody | same gate |
| 3 | `~/.sugar-crush/settings.json` | `LayeredSettings::userLayer()` filtered to `LAYERED_KEYS`; also the strict `Bootstrap::permissionSettingsLayer()` (`:5140`) for `permissionMode`/`permissionRules` | nobody — README: "`settings.json` is never written" | `$HOME` ownership checks |
| 4 | `~/.sugar-crush/config.json` (or `--config <path>`) | `Bootstrap::rawUserConfig()` **unfiltered**, plus the strict `permissionConfig()` (`:4923`) | `Bootstrap::writeUserConfig()` (`:3608`): atomic tempnam + rename, chmod 0600, **shallow** `array_merge` | — |
| pkg | `<package>/.sugar-crush/config.dev.json` | `ProviderFactory` (`src/Providers/ProviderFactory.php:72,174-298,466-508`), `Bootstrap::availableProviders()` (`:2992`) | nobody | `ContainedPath` |
| repo | `<root>/.sugar-crush/config.json` (e.g. `/home/sites/sugarcraft/.sugar-crush/config.json`) | **only** the DORMANT `Agents\WorktreeConfig` (`src/Agents/WorktreeConfig.php:53,181-185`) | nobody | — |

**Merge.** `LayeredSettings::merge()` is `array_merge($project, $userSettings, $userConfig)`: later wins, so layer 4 > 3 > 2 > 1. The merge is **key-by-key, not deep**, so a list in a higher layer replaces a lower list entirely.

**Read entry point.** `Bootstrap::readUserConfig()` (`:3376`) → `mergedConfig(true)` (`:3393`). The permission keys travel a separate, strict stack, `permissionConfigLayers()` (`:4972`), which refuses the launch on a malformed or world-writable file.

**Findings while mapping this:**
- `docs/ENVIRONMENT.md` ("Variables read from any config file") says `${VAR}` placeholders work "from `~/.sugar-crush/config.json`" under `providers{}`. But **nothing reads `providers` from the user config**: `availableProviders()` and `projectProviderConfig()` read only the package's `config.dev.json` [V]. User-defined providers are therefore impossible today, and the settings editor cannot offer them. Either fix this (§2.2, `providers` user tier) or correct the doc.
- The repo-root `.sugar-crush/config.json` carries:
  - `"name": "sugar-crush"`, which is read by nothing;
  - `worktreeCleanupPeriodDays`/`worktreeIncludeFile`, which are read only by the dormant `WorktreeConfig`;
  - `trustedProjectMcp`, which is **inert in that file**, because trust keys are honoured only from layer 4 [V by grep].

  The editor should list this file as "not a settings layer" so users do not try to edit it.
- `enabledSkills` is **not** in `LAYERED_KEYS`. It is answered by layer 4 only: `config.json` can set it and `~/.sugar-crush/settings.json` cannot (`Bootstrap::promptEnabledSkills()`, `:3837`) [V]. This asymmetry is surprising; the schema should either make the key layered or document the exception.

### 1.2 What reads which key, and when it takes effect [V]

| Key | Read at | Effective |
|---|---|---|
| `parallelToolCalls`, `parallelToolDeadlineSeconds`, `maxOutputTokens` | `EngineBackend::runTurn()` `:782-790`, **in the forked child**, every turn | **next turn**, automatically |
| `maxToolSteps` | `Bootstrap::withResolvedMaxToolSteps()` (`:2965`) at backend build | launch, and on `/model` provider switch. `EngineBackend::withMaxSteps()` (`:603`) exists, so it *could* be live |
| `provider` | `Bootstrap::backend()` (`:2755`), `selectedProviderName()` (`:7630`) | live via `/model` → `Chat::selectPaletteProvider()` (`:14106`), which **drops the Task tool and `rulesState`** (baseline §11.1 #8) |
| `theme` | `Bootstrap::chat()` `:1214` | live via `/theme` → `Chat::selectPaletteTheme()` (`:14149`) → `themeName` |
| `titleModel`, `summaryModel` | `Bootstrap::toollessBackend()` (`:7713`, `:7747`) at launch | restart. `Chat` holds `titleBackend`/`summaryBackend`, so it could be live if `Chat` gains setters |
| `instructions` | `Bootstrap::forcedInstructions()` (`:7281`) → `InstructionFileLoader` (`:7230`) at backend build | restart |
| `disabledSkills` | `Bootstrap::skillRegistry()` (`:3768`) | launch; rebuilt on a Ctrl+P provider switch |
| `enabledSkills` | `promptEnabledSkills()` (`:3839`) at backend build | restart |
| `disabledRules` | `Bootstrap::chat()` → `RulesState::new()` | launch seed; `/rules` toggles it live, for the session only |
| `allowedTools`, `disabledTools` | `Bootstrap::tools()` → `filterToolSet()` (`:6995`) | restart. `EngineBackend::withTools()` (`:377`) exists, so it could be live |
| `modelPrices` | `ProviderFactory` `:650`, when building the OpenAI provider | when the provider is (re)built |
| `statusLine` | `StatusLineCommand::configure($userConfig, $root)` in `Bootstrap::chat()` (`:1095`) | launch. `configure()` clears and re-sets, so calling it again would make it live |
| `layout` | `Bootstrap::app()` `:2478` | live; written by the shell |
| `permissionMode`, `permissionRules` | `Bootstrap::permissionGate()` (`:4807`) | launch. `EngineBackend::withPermissionGate()` (`:467`) exists, so it could be live |
| `trustedProject{Hooks,Mcp,Commands,Settings}` | `trustedProjectRoots()` (`:4636`), memoised in static arrays | **frozen for the process, by design** |
| `claudeMcpBinary/Args/Env` | `Bootstrap::claudeMcpGrant()` (`:6114`) | frozen at the first MCP launch |

### 1.3 What writes settings today [V]

| Writer | Keys | Doors |
|---|---|---|
| `Chat::$onConfigChange` → `Bootstrap::writeUserConfig()` | `provider` | palette "Switch model", `/model <name>` |
| same | `theme` | palette "Switch theme", `/theme <name>`, and `Chat.php:16037` (the theme command's own path) |
| `App::$onLayoutChange` → `writeUserConfig()` | `layout` | pane dock, move, undock, reset |
| `/rules`, `/budget` | none (**session only**) | `RulesCommandTest::testTogglingAPackLeavesTheConfigFileByteIdentical()` pins `/rules` |

**Census tests that a new writer must respect:**
- `tests/Config/ConfigWriteProducerDocumentationDriftTest.php`:
  - `:290` exactly `['provider','theme']` reach `onConfigChange`;
  - `:315` the callback may not be aliased or `call_user_func`'d;
  - prose rules about `docs/SETTINGS.md` and `README.md` naming every door.
- `tests/Config/TrustKeyDocumentationDriftTest.php`:
  - exactly four trust keys;
  - the `docs/SETTINGS.md` table lists every `LAYERED_KEYS` entry, and its "Project may set" column equals `PROJECT_TIER_KEYS` (`:303`, `:321`);
  - the README roster agrees (`:407`, `:495`).
- `tests/Config/ReadmeRosterDriftTest.php` — slash-command roster, layer-table order (`:321`), permission modes.
- `tests/Config/EnvRosterDriftTest.php` — every `getenv('SUGARCRUSH_*')` read has a row in `docs/ENVIRONMENT.md`, and vice versa.
- `tests/Commands/KeyBindingDriftTest.php` — every `KeyBindingRegistry` row has an observation that it does what it says (`:119`).
- `tests/Config/ThemePersistenceFramingTest.php`, `ReadmeSettingsTierClaimTest.php`, `GlobFigureDriftTest.php`, `DocFigureProseDriftTest.php` — prose pins on the same pages.

### 1.4 TUI surfaces related to settings [V]

- `Pane::Settings` (`src/Tui/Pane.php`): dock side Right, icon `⚙`, part of the Tab cycle. `src/Tui/Renderer.php:1317` renders it as `SettingsPane::render()`. `SettingsPane::settings(App)` returns label/value pairs, kept separate from `render()` so "a future full-width settings view can reuse the same single source of truth".
- `Ctrl+,` = `shell.settings` "Focus the settings pane" (`src/Commands/KeyBindingRegistry.php:498`). Many terminals cannot send `Ctrl+,` without the kitty keyboard protocol, which sugar-crush does push.
- The `Enter` palette door on any dockable pane with an empty draft (`KeyboardHandler.php:297`).
- The command palette (`src/Palette/PaletteAction.php` enum, 12 cases; `PaletteState`; `Chat::runRootPaletteAction()` `:14038`). Rows are **derived from `CommandRegistry::all()`** (`CommandSpec::paletteAction`), and so is the **F10 menu bar** (`src/Tui/Components/MenuBar.php:77` groups `CommandRegistry` labels by category). Adding one `CommandSpec` therefore adds a slash command, a palette row and a menu row in one place.
- Read-only reports: `/permissions` (mode, source, rules), `/notices`, `/mcp list` (`src/Tui/McpPanel.php` text renderer), `/rules` (list plus session toggle), `/budget` (show or set the session cap), `/keys`.
- Overlay chain in `src/Renderer.php:1440-1530`: key help → permission prompt → palette → session picker, composited with `Veil::new()->withBackdrop(50)->composite(…)` (`:1521`) and click-marked by `markPaletteItems`/`markSessionRows`.
- **Precedent for a full-band view.** When `Pane::Agents` is focused, `Tui\Renderer::renderAgentDashboard()` (`:498`, `:662`) replaces the band with `AgentDashboardPane`, drops the chat's mouse zones, and keeps the menu bar and status bar.
- **Mid-turn rule** (`Chat.php:7173-7182`, `:7606`, `:7618-7660`): slash commands are refused mid-turn (except `/exit`), while overlays may *open and browse* mid-turn. A mid-turn refusal closes the overlay it is written under.

### 1.5 Environment variables and CLI flags [V]

**CLI flags** (`src/Cli/ArgvParser.php`): `--root`, `--config`, `--model`, `--permission-mode`, `-c/--continue`, `--resume`, `-p/--prompt`, `--output-format`, `-h/--help`, `-v/--version`.

**Environment variables** (25, from `docs/ENVIRONMENT.md`, drift-pinned):

| Env var | Equivalent settings key today | Proposed key [P] |
|---|---|---|
| `SUGARCRUSH_PROVIDER` | `provider` | — |
| `SUGARCRUSH_MODEL` | **none** — no model is persisted (SETTINGS.md "There is no top-level `model` key") | `models` (map provider→model) |
| `SUGARCRUSH_TITLE_MODEL` / `_SUMMARY_MODEL` | `titleModel` / `summaryModel` | — |
| `SUGARCRUSH_MAX_COST` | none (`/budget` is session-only) | `maxCostUsd` |
| `SUGARCRUSH_PERMISSION_MODE` | `permissionMode` | — |
| `SUGARCRUSH_SESSION_RETENTION_DAYS` | none | `sessionRetentionDays` |
| `SUGARCRUSH_CONNECT_TIMEOUT` | none | `connectTimeoutSeconds` |
| `SUGARCRUSH_DISABLE_PARALLEL_TOOL_CALLS` | `parallelToolCalls` | — |
| `SUGARCRUSH_PARALLEL_TOOL_DEADLINE` | `parallelToolDeadlineSeconds` | — |
| `SUGARCRUSH_DISABLE_MOUSE`, `_MOUSE_CLICKS` | none | `mouse: on\|wheel\|off` |
| `SUGARCRUSH_DISABLE_PROMPT_SUGGESTIONS` | none | `promptSuggestions` |
| `SUGARCRUSH_BACKGROUND` | none | `terminalBackground: auto\|light\|dark` |
| `SUGARCRUSH_SEARCH_ENDPOINT` | none | `webSearch.endpoint` |
| `SUGARCRUSH_MCP_DISABLE` | none | `mcp.enabled` |
| `SUGARCRUSH_SHARE_UPLOAD_URL` | none (uploader is a stub) | later |
| `SUGARCRUSH_WORKTREES_DIR` | none (dormant) | later |
| `SUGARCRUSH_BACKEND_CMD`, `_STREAM` | none | stay env-only (shell-out plumbing) |
| `SUGARCRUSH_DISABLE_PROMPT_CACHE` | inert | stay |
| `SUGARCRUSH_DEBUG_{SKILLS,COMMANDS,RULES,STREAM}` | none | `debug.*` booleans (low priority; restart) |

### 1.6 Full inventory of existing setting keys

**Column meanings:**
- **Tiers:** `P` = project layers 1–2 (needs `trustedProjectSettings`); `U` = `~/.sugar-crush/settings.json`; `C` = `config.json` (layer 4).
- **Apply:** `NT` = next turn, automatically; `L` = live today; `L*` = could be live with a small hook (§4.6); `R` = restart; `F` = frozen per process by design.
- **UI:** `easy` = bool, enum, int or short string; `list` = multi-select over discovered names; `complex` = nested structure (table editor or read-only).

| # | Key | Type | Default | Tiers | Env / flag override | Apply | UI |
|---|---|---|---|---|---|---|---|
| 1 | `provider` | string ∈ `availableProviders()` (7 types + `config.dev.json` names) | none → env → persisted → `defaultProvider`/echo | U C | `SUGARCRUSH_PROVIDER` | L (backend swap; Task-tool bug) | easy (Select) |
| 2 | `theme` | enum `dark,light,dracula,tokyoNight,ansi,adaptive` (`src/Theme.php:60`) | `dark` (`Bootstrap.php:1214`) | P U C | — (`SUGARCRUSH_BACKGROUND` affects `adaptive`) | L | easy (Select) |
| 3 | `titleModel` | string (model id) | provider default | P U C | `SUGARCRUSH_TITLE_MODEL` | R (L*) | easy (Input + suggestions) |
| 4 | `summaryModel` | string | provider default | P U C | `SUGARCRUSH_SUMMARY_MODEL` | R (L*) | easy |
| 5 | `instructions` | list<glob> | `[]` | U C | — | R | list (free-text list) |
| 6 | `disabledSkills` | list<string> | `[]` | P U C | — | R (L* via skill-registry rebuild) | list (MultiSelect of discovered skills) |
| 7 | `disabledRules` | list<pack path> | `[]` | U C | — | R (`/rules` is live for the session) | list (MultiSelect of `RuleLoader` packs) |
| 8 | `parallelToolCalls` | bool | `true` | P U C | `SUGARCRUSH_DISABLE_PARALLEL_TOOL_CALLS` | NT | easy (Confirm) |
| 9 | `parallelToolDeadlineSeconds` | int > 0 | `90` (`Runtime.php:67`) | P U C | `SUGARCRUSH_PARALLEL_TOOL_DEADLINE` | NT | easy |
| 10 | `maxOutputTokens` | int > 0 \| unset | unset (provider default 4096, `CustomProvider.php:164`) | U C | — | NT (doc says otherwise) | easy (Input + "unset") |
| 11 | `modelPrices` | map<model,{input,output}> USD/1M | `{}` | U C | — | on provider build | complex (table editor, P4) |
| 12 | `allowedTools` | list<glob> \| unset | unset (= all) | U C | — | R (L*) | list (MultiSelect of tool names + free glob) |
| 13 | `disabledTools` | list<glob> | `[]` | P U C | — | R (L*) | list |
| 14 | `statusLine` | `{type:"command",command}` | unset | U C | — | R (L* via `StatusLineCommand::configure`) | easy (command Input; exec warning) |
| 15 | `layout` | `DockLayout` manifest | launch default | U C (shell-written) | — | L | not form-edited ("Reset layout" button) |
| 16 | `maxToolSteps` | int ≥ 1 | `8` (`EngineBackend.php:262`) | U C | — | R (L* via `withMaxSteps`) | easy (Input/Slider) |
| 17 | `permissionMode` | enum `default,accept-edits,plan,auto,dont-ask,bypass-permissions` | `bypass-permissions` (`Bootstrap.php:166`) | U C (strict reader) | `SUGARCRUSH_PERMISSION_MODE`, `--permission-mode` | R (L* via `withPermissionGate`) | easy (Select + warning) |
| 18 | `permissionRules` | list<{pattern, action: allow\|deny\|ask}> | `[]` | U C (strict) | — | R (L*) | complex (row editor, P4) |
| 19-22 | `trustedProjectHooks`, `…Mcp`, `…Commands`, `…Settings` | list<absolute path> | `[]` | **C only** (the `--config` file) | — | **F** | easy as per-project toggles; full list read-only |
| 23 | `claudeMcpBinary` | absolute executable path | unset | U C | — | F | easy (Input + existence validator) |
| 24 | `claudeMcpArgs` | list<scalar> | `["--mcp"]` | U C | — | F | list |
| 25 | `claudeMcpEnv` | map<string,string> (literal) | unset | U C | — | F | complex |
| 26 | `enabledSkills` | list<skill name> | `[]` | **C only** (not layered) | — | R | list (MultiSelect) |
| — | package `providers{}` / `defaultProvider` | provider configs | `dev-sglang` | package file | `${VAR}` interpolation | R | complex → read-only view |
| — | `hooks.yaml`, `.mcp.json`, rules/skills/commands/agents dirs | files | — | user / trusted project | `SUGARCRUSH_MCP_DISABLE` | R | read-only views + "open file" |

---

## 2. Hard-coded behaviour that should become settings

### 2.1 Selection rule [P]

Promote a constant when at least one of these holds:
- users plausibly need a different value (model or provider dependent, repo-size dependent, or a personal preference);
- a competitor exposes it;
- a §13 recommendation needs it.

Leave alone the protocol constants, kill/reap grace periods, poll intervals, frame limits and security caps whose only safe direction is down, unless the cap is reachable by users.

**Tier ceiling.** Every new key gets a `riskClass`. Only the classes `Cosmetic`, `Narrowing` and `Tuning` may be project-settable. `Spend`, `Exec`, `Security`, `Egress` and `Prompt` are user-only. This turns the prose argument in `docs/SETTINGS.md` ("no key whose meaningful direction is UP belongs to a checked-out repository") into a test.

### 2.2 Constants and env-only behaviours → proposed keys

**Columns:**
- **Apply:** `NT` = next turn (read in `runTurn` from merged config); `L` = live hook; `R` = restart.
- **Tier:** `P+U` = project-settable; `U` = user-only.

**Agent loop and provider**

| Behaviour | Where (file:line) | Proposed key | Type | Default | Tier | Apply |
|---|---|---|---|---|---|---|
| Step cap | `src/Backend/EngineBackend.php:262` (`maxSteps = 8`); `Bootstrap.php:2921` key | `maxToolSteps` (exists) → make NT by reading in `runTurn` | int 1–500 | 8 (reports 01/02/06/10 say raise to 50 together with a doom-loop guard) | U (Spend) | NT |
| No-progress watchdog | `EngineBackend.php:99` `COMPLETE_TIMEOUT_SECONDS = 120` | `turnIdleTimeoutSeconds` | int ≥ 30 | 120 | U | NT (parent reads it at fork) |
| Sub-agent turn cap | `src/Tools/BuiltIn/TaskTool.php:135`, `src/Agents/EngineExecutor.php:51` `DEFAULT_MAX_TURNS = 50` | `subagents.maxTurns` | int | 50 | U (Spend) | NT |
| Sub-agent concurrency | `src/Agents/AgentPoolConfig.php:22` `maxConcurrent = 5` (unused for Task; reports 07/10/11) | `subagents.maxConcurrent` | int 1–16 | 5 | U (Spend) | NT |
| Retry attempts / backoff | `src/Providers/TransientFailure.php:165` (3), `:174` (500 ms) | `provider.retryAttempts`, `provider.retryBaseBackoffMs` | int | 3 / 500 | P+U (Tuning) | NT |
| Connect timeout | `src/Providers/Concerns/HttpClientDefaults.php:116` (15 s), env only | `connectTimeoutSeconds` | float ≥ 0.001 | 15 | P+U | NT |
| Stream read-idle | `HttpClientDefaults.php:144` (3600 s) | `streamIdleTimeoutSeconds` | int | 3600 | U | NT |
| Default temperature / max_tokens | `src/Providers/CustomProvider.php:163-164,229-230`; `BedrockProvider.php:56-58` | `temperature` (per provider), `maxOutputTokens` (exists) | float 0–2 | 0.7 (SGLang per-family) | U | NT |
| Conversation model | env `SUGARCRUSH_MODEL` / `--model` only | `models` | map<provider,model> | `{}` | U | L (backend rebuild) |
| Spend cap | env `SUGARCRUSH_MAX_COST`; `/budget` is session-only | `maxCostUsd` | float > 0 \| unset | unset | U (Spend) | L (`Chat` field, same as `/budget`) |
| Prompt suggestions | env `SUGARCRUSH_DISABLE_PROMPT_SUGGESTIONS`; `Chat.php:390` history 12 | `promptSuggestions` | bool | true | P+U | L |
| Idle compaction offer | `src/Context/IdleCompactionPolicy.php:43` (3600 s), `:65` refill limit 3 | `compaction.idleOfferSeconds` | int \| 0 = off | 3600 | P+U | L |

**Context and compaction** (`src/Context/CompactorConfig.php:68-76`; `Chat` already accepts `?CompactorConfig` at `Chat.php:634` and uses it at `:1375`, but `Bootstrap::chat()` never passes one [V])

| Behaviour | Where | Key | Type | Default | Tier | Apply |
|---|---|---|---|---|---|---|
| Reminder threshold | `CompactorConfig.php:68` | `compaction.reminderPercent` | int 1–99 | 70 | P+U | L |
| Auto-compact threshold | `:69` | `compaction.autoPercent` | int | 85 | P+U | L |
| Foreground block | `:70` | `compaction.blockPercent` | int | 95 | P+U | L |
| Keep-recent pairs | `:71` | `compaction.keepRecent` | int ≥ 1 | 10 | P+U | L |
| Summary clips | `:74-76` | `compaction.summaryUserChars` / `…AssistantChars` / `…toolOutputChars` | int | 80 / 100 / 2000 | P+U | L |
| LLM vs heuristic | implicit: `summaryBackend !== null` | `compaction.mode: llm\|heuristic\|off` | enum | llm | P+U | L |
| Context-window fallback | `src/Context/ContextWindow.php:52` (100 000) | `contextWindowOverride` | int \| unset | unset | U | L |
| Token-estimate calibration clamp | `Chat.php:422,438` (1.0–3.0) | leave hard-coded | — | — | — | — |

Validation: `reminderPercent < autoPercent < blockPercent`, as a cross-field validator (§4.5).

**Tools**

| Behaviour | Where | Key | Type | Default | Tier | Apply |
|---|---|---|---|---|---|---|
| Bash/Grep/Glob/Lsp output cap | `src/Tools/Concerns/TruncatesOutput.php:123` (64 KiB) | `tools.outputCapBytes` | int 4 KiB–1 MiB | 65 536 | P+U | NT |
| Instruction-file section cap | `TruncatesOutput.php:295` (16 KiB) | `tools.instructionCapBytes` | int | 16 384 | P+U | NT |
| Read cap | `src/Tools/BuiltIn/Read.php:23` (1 MiB) | `tools.read.maxBytes` | int | 1 048 576 | P+U | NT |
| Glob match cap | `src/Tools/BuiltIn/Glob.php:49` (1000) | `tools.glob.maxMatches` | int | 1000 | P+U | NT |
| WebFetch cap / timeout / redirects | `src/Tools/BuiltIn/WebFetch.php:48-50` | `tools.webFetch.maxBytes`, `.timeoutSeconds` | int | 2 MiB / 30 | P+U | NT |
| WebSearch endpoint | `src/Tools/BuiltIn/WebSearch.php:52` (**private host `skynet2…`**) | `webSearch.endpoint` | URL \| unset | **unset → tool reports "not configured"** (fixes baseline §11.1 #6) | **U (Egress)** | NT |
| WebSearch results / timeout | `WebSearch.php:49-50` (30 s, 10) | `webSearch.maxResults`, `.timeoutSeconds` | int | 10 / 30 | P+U | NT |
| Interactive Bash idle | `src/Tools/Concerns/CapturesProcessOutput.php:227` (8 s) | `tools.bash.interactiveIdleSeconds` | float | 8 | P+U | NT |
| Bash timeout | absent (report 08 P1: `bashTimeoutSeconds`, 300) | `tools.bash.timeoutSeconds` | int \| 0 = none | 300 | U | NT |
| SugarCraft git cadence in Bash guidance | `src/Tools/BuiltIn/Bash.php:124-163` | `tools.bash.includeGitInstructions` (report 01) | bool | **false** (move the cadence to the repo's CLAUDE.md) | P+U | NT |
| Parallel tool deadline | `src/Runtime.php:67` | `parallelToolDeadlineSeconds` (exists) | — | — | — | — |
| Chat-native parallel timeout | `Chat.php:317` (30 s) | `tools.commandBackendParallelTimeoutSeconds` | int | 30 | P+U | L |
| MCP results uncapped | `src/Tools/McpToolBridge.php:587-622` | `mcp.resultCapBytes` | int | 65 536 | P+U | NT |
| Custom-command shell budget | `src/Commands/CommandSpec.php:138` (10 s), `:150` (16 KiB) | `commands.shellBudgetSeconds` | int | 10 | U (Exec) | L |
| Hook default timeout | `src/Hooks/ScriptHook.php:142` (60 s; per hook in yaml) | `hooks.defaultTimeoutSeconds` | float | 60 | U | R |

**Prompt, memory and rules**

| Behaviour | Where | Key | Type | Default | Tier | Apply |
|---|---|---|---|---|---|---|
| Memory block caps | `src/Context/MemoryBlock.php:118` (12), `:137` (4 KiB), `:162` (512 B) | `memory.promptMaxEntries`, `.promptMaxBytes`, `.entryMaxBytes` | int | 12 / 4096 / 512 | P+U | NT |
| Memory scopes in prompt | project only (`Runtime::memorySnapshot()` `:3606`) | `memory.promptScopes` | multi `project,user,agent` | `[project]` | U (Prompt) | NT |
| `/memory add` default scope | `user` (baseline §5) | `memory.defaultScope` | enum | **`project`** (so notes reach the prompt) | U | L |
| Project-memory note cap | `src/Context/ProjectMemoryWriter.php:58` (8 KiB) | `memory.projectNoteMaxBytes` | int | 8192 | P+U | L |
| Standing-rule budget | `src/Runtime.php:161` (64 KiB), `:171` (2 pointers) | `rules.standingMaxBytes` | int | 65 536 | U | NT |
| Repo map caps | `src/Context/RepoMapBlock.php:258` (8 KiB), `:290`, `:315` | `repoMap.enabled`, `.maxBytes` | bool / int | true / 8192 | P+U | NT |
| `<env>` git sections | `src/Context/EnvironmentBlock.php:223` (8 KiB diff), `:317` (4 KiB) | `env.gitDiffAfterWrites` (bool), `env.diffMaxBytes` | — | true / 8192 | P+U | NT |
| `@import` depth | `src/Context/ImportResolver.php:19` (4) | leave | — | — | — | — |
| Skill nudges | `src/Skills/SkillPathNudge.php:155` (8), `:107` (300 B) | `skills.pathNudges` (bool) | bool | true | P+U | NT |
| Launch notices to model | `src/Cli/Bootstrap.php:194` (24), `:212` (400) | `notices.transcriptLimit` | int | 24 | U | R |

**Sessions, UI and safety**

| Behaviour | Where | Key | Type | Default | Tier | Apply |
|---|---|---|---|---|---|---|
| Mouse | env only; `Chat::mouseMode()` `Chat.php:4945`; `programOptions()` `:6185` | `mouse` | enum `on,wheel,off` | on | P+U | **L** via candy-core `Cmd::enableMouseCellMotion()`/`Cmd::disableMouse()` (`candy-core/src/Cmd.php:379,394`) |
| Wheel lines | `Chat.php:466` (3) | `ui.scrollWheelLines` | int 1–20 | 3 | P+U | L |
| Double-Esc window | `Chat.php:357` (0.6 s) | `ui.doubleEscSeconds` | float | 0.6 | P+U | L |
| Collapsed tool-output rows / chars | `src/Renderer.php:478,480` (10, 2000); diff rows `:282` (24) | `ui.toolOutputPreviewLines`, `ui.diffPreviewRows` | int | 10 / 24 | P+U | L |
| Tool output expanded by default | collapsed, hard-coded | `ui.expandToolOutput` | bool | false | P+U | L |
| Palette MRU | `Chat.php:459` (8) | `ui.paletteMru` | int | 8 | P+U | L |
| Terminal background | env `SUGARCRUSH_BACKGROUND` | `terminalBackground` | enum `auto,light,dark` | auto | P+U | L |
| Session retention | env only | `sessionRetentionDays` | int 0–36500 | 0 | U | R (next launch) |
| Checkpoints per session | `src/Session/EnhancedSessionStore.php:285` (100) | `sessions.maxCheckpoints` | int | 100 | U | L |
| Auto-titles | always on | `sessions.autoTitle` | bool | true | P+U | L |
| Auto-mode breaker | `src/Permissions/PermissionGate.php:72-73` (3 / 20) | `permissions.autoStrikeLimit`, `.autoTotalLimit` | int | 3 / 20 | U (Security) | L* |
| Status line refresh | `src/Config/StatusLineCommand.php:103` (2.0 s; timeout derived) | `statusLine.refreshSeconds` | float ≥ 0.5 | 2.0 | U | L |
| MCP on/off | env `SUGARCRUSH_MCP_DISABLE` | `mcp.enabled` | bool | true | U | R |
| Debug flags | env `SUGARCRUSH_DEBUG_*` | `debug.skills`, `.commands`, `.rules`, `.stream` | bool | false | U | R |

### 2.3 Knobs that the competitor reports' §13 recommendations would introduce

The settings design should give each of these a ready slot and category, so a feature lands with its key, docs and form field in one step.

| Knob | Source (report §13) | Proposed key | Type / default | Tier |
|---|---|---|---|---|
| Doom-loop / repeat guard | 04 (`DOOM_LOOP_THRESHOLD=3`), 06 P0-1, 12 P1-3 `repeatToolThresholds` | `loopGuard.repeatThreshold`, `loopGuard.exclude` | int 3; list | P+U |
| Mid-turn steering | 01 P0-2, 06 P0-3, 07, 08 P0-2, 04 | `steering.mode` | enum `queue\|steer\|steer-at-boundary`, default `steer` once the backchannel exists | P+U |
| Interactive approval on the engine path → new default mode | 01, 02, 04 P0-3, 07 R? (change `Bootstrap.php:166`) | `permissionMode` (exists) default → `accept-edits` | enum | U |
| Read-only Bash profile | 04 P2-5 `readOnlyBash` | `permissions.readOnlyBashProfile` | bool | U |
| Context pruning (DCP) | 03: `contextPruning{mode,minContextTokens 60000,maxContextTokens 120000,nudgeFrequency 5,iterationNudgeThreshold 10,protectedTools,protectedFilePatterns,strategies.*}`, env `SUGARCRUSH_CONTEXT_PRUNING` | `contextPruning.*` (nested; the editor shows sub-fields) | mixed | U (`mode` P+U) |
| Mid-turn compaction | 07 (compact before every request at 90%), 10 | `compaction.midTurn` (bool), `compaction.midTurnPercent` | true / 90 | P+U |
| Tool-result spill | 10 R? `maxToolResultChars` 16000; 12 `maxInlineTokens` 12 500 | `tools.spillAboveChars` | int / 16000 | P+U |
| Auto-commit | 09 #4 `autoCommit: off\|turn\|edit` | `git.autoCommit` | enum `off` | U |
| Attribution | 01 `attribution.commit` / `.pr` | `git.attribution.commit`, `.pr` | string | U |
| Lint after edit | 09 `lintCommands` map | `lint.commands` | map ext→cmd | U (Exec) |
| Test after turn | 09 `testCommand`, `autoTest` | `test.command`, `test.auto` | string / bool false | U (Exec) |
| Architect/editor split, plan model | 09 `editorModel`, 05 `planModel` | `models.editor`, `models.plan` | string | U |
| Sub-agent model | 07 `subagentModel` | `subagents.model` | string \| inherit | U |
| Fallback models | 10 `fallbackModels` + breaker | `provider.fallbackModels` | list | U |
| MCP per-call timeout | 10 `toolTimeout` 30 s | `mcp.toolTimeoutSeconds` | int 30 | U |
| File checkpoints (shadow git) | 07 R4, 01 | `checkpoints.files` | bool | U |
| Notifications / bell | 02, 08 `SUGARCRUSH_BELL`, 09 `notificationsCommand`, 10 R20 | `notify.bell`, `notify.command` | bool / string | U (command = Exec) |
| Per-turn note | 08 `SUGARCRUSH_TURN_NOTE[_FILE]` | `turnNote.file` | path | U |
| Watch-files `AI!` comments | 09 #12 `watchFiles` | `watchFiles` | bool false | U |
| Edit fuzzy match | 04 `fuzzyThreshold` | `tools.edit.fuzzyThreshold` | float 1.0 | P+U |
| Prompt-cache breakpoints | 01, 05, 06, 08, 09, 10 (wire `CacheBreakpoints`) | `promptCache.enabled` (replaces inert env) | bool | U |
| Exclude dynamic sections | 01 `excludeDynamicSections` | `prompt.excludeDynamicSections` | bool | U |
| Cache keepalive | 09 `cacheKeepalivePings` | `promptCache.keepalivePings` | int 0 | U (Spend) |

---

## 3. SugarCraft libraries available for the UI

### 3.1 Already dependencies of sugar-crush

From `composer.json`: `candy-core`, `candy-forms`, `candy-sprinkles`, `candy-shine`, `candy-fuzzy`, `sugar-veil`, `sugar-mcp`, `candy-mosaic`, `candy-mouse`, `candy-layout`, `candy-focus`, `candy-kit`, plus `candy-pty` (dev only).

| Lib | Classes the editor would use | How sugar-crush uses it today |
|---|---|---|
| **candy-forms** (`SugarCraft\Forms\`) | `Field` interface (`candy-forms/src/Field.php`: `key/value/focus/blur/update/view/getTitle/getDescription/getError/isHidden/revalidate`) · `Field\Input` (`withValue`, `withPlaceholder`, `withCharLimit`, `withSuggestions`/`withFuzzySuggestions`, `withValidator`, `withValidateOn`) · `Field\Select` (`withOptions`, `withSelected`, `withEnum` — maps PHP enums like `PermissionMode` directly) · `Field\Confirm` (`withLabels`) · `Field\Slider` (min/max/step) · `Field\MultiSelect` (`withMin/withMax/withValue`) · `Field\Note` (read-only explanatory rows) · `Field\Text` (multi-line, for `instructions`) · `Form::groups(Group …)` with `nextGroup/prevGroup/activeGroup`, **`hydrate(array)`**, `values()`, `validateAll()`, `errors()`, `focusField(key)`, `withKeyMap(KeyMap)`, `withWidth/withHeight`, `withTheme(Theme)` · `Group::withTitle/withDescription/withHideFunc` · `HasReadonly::withReadonly()` (env-locked fields) · `HasDynamicLabels::withTitleFunc()` (i18n or source-aware labels) · `HasErrorHelp` · `Validator\{Required,Pattern,MinLength,MaxLength}` · `Theme` (title, description, focusedTitle, error, option, selectedOption, help, prompt styles) · `ItemList`, `Viewport`, `TextInput` | `TextArea` for the chat input; `ItemList` as the selection model of `SessionPicker` (`src/Tui/SessionPicker.php:13,153`) |
| **candy-fuzzy** | `Matcher\SmithWatermanMatcher`, `MatchResult`, `Highlighter` | palette and slash-popup filtering (`src/Commands/CommandRegistry.php`) |
| **candy-focus** | `FocusRing::ofStrict()/focus()/next()/previous()/disable()` | Tab cycle in `src/Tui/Pane.php` |
| **candy-mouse** | `Mark::zone(id, content)`, `Scanner::scan()/hit()/prefixed()`, `ZoneClickTracker` | every clickable row (palette, session rows, menu, pane tabs, dividers) |
| **sugar-veil** | `Veil::new()->withBackdrop(50)->composite(fg, bg, Position::CENTER, …)` | all overlays (`src/Renderer.php:1521`) |
| **candy-sprinkles** | `Style`, `Border::rounded()->withTitle()`, `Table\Table`, `Layout`, `Bar\StatusBar` + `Segment` | all chrome; `Table` in `src/Commands/TranscriptTable.php` |
| **candy-layout** | `Dock\DockLayout`, `Dock\Side`, `Region` | pane dock |
| **candy-core** | `Util\AtomicJsonFile` (flock + rename, `withPermissions(0600)`), `Util\Width::truncate/truncateMiddle`, `Cmd::enableMouseCellMotion/disableMouse`, `I18n\Lang`/`T`, `Subscriptions` | runtime; `AtomicJsonFile` only in the dormant `src/Session.php` |
| **candy-kit** | `StatusLine::success/error/warn/info` (✓ ✗ ⚠ ℹ glyph lines), `Section::header/rule/subHeader` | **unwired**; a deferred-wiring note in `composer.json` `extra` says it is reserved for `Help::screen()` |

`candy-kit`'s `StatusLine`/`Section` would make a natural first live use, for the editor's badges and section headers.

### 3.2 Not yet dependencies — fit assessment

| Lib | Useful classes | Requires | Verdict |
|---|---|---|---|
| `sugar-prompt` | façade: `class_alias` of `candy-forms` (`sugar-prompt/src/Form.php:10`) | candy-forms | **No** — use `candy-forms` directly (AGENTS.md "Façade = alias") |
| `sugar-bits` | `Tabs\Tabs` (real Model, `withLabels/withActive/withZoneManager`), `Help\Help` + `Key\Binding`/`KeyMap` (short and full help line) | candy-core, candy-forms, candy-sprinkles, **candy-zone**, honey-bounce | Optional. `Tabs` hit-tests through `candy-zone`'s `Manager`, while sugar-crush uses `candy-mouse`, so mouse support needs bridging. The `MenuBar` pane-tab strip (`MenuBar.php:226-229`, `Mark::zone`) already shows the house pattern. **Recommend a local `SettingsTabStrip`** of about 60 lines on `candy-mouse`. |
| `sugar-toast` | `Toast::new()->withPosition()->success()/warning()/info()`, `withDuration`, `pruneExpired`, `nextExpiry` | candy-core, candy-buffer | **Recommended (P3)** for "Saved to ~/.sugar-crush/config.json" and "Restart required for 2 settings". sugar-crush has no toast surface; notices today are transcript rows, which also go to the model. |
| `sugar-diff` | `Diff::compute(before, after)->unified(path)`, `addedLines/removedLines` | ext-mbstring only | **Recommended (P2)** for the save preview. sugar-crush has its own trait (`src/Tools/Concerns/BuildsUnifiedDiff.php:41`, private static), and `sugar-diff` was "extracted from sugar-crush". Its output renders through the existing `src/Tui/DiffGutter.php::forDiff()` for consistent colouring. Alternatively, lift the trait into a small public `Support\UnifiedDiff`; avoid a third copy. |
| `sugar-table` | `Table::fromColumns()->withRows()->withSelectable()->withStyleFunc()` | candy-buffer, candy-core, candy-sprinkles | Possible for the P4 `permissionRules`/`modelPrices` row editors; `candy-sprinkles` `Table` + `ItemList` is enough for v1 |
| `sugar-crumbs` | `NavStack::push/pop/view(' > ')` | candy-core, candy-mouse | Nice for nested objects (`contextPruning › strategies › staleReads`). P4. |
| `candy-lister` | tree list with prefixer/suffixer + fuzzy filter | candy-async, candy-buffer, candy-core, candy-fuzzy | Alternative category tree; overkill for ~10 categories |
| `sugar-dash` | `Components\Tabs\Tabs`, `Form\Toggle`, `Modal\Modal/ConfirmModal/Drawer/Wizard`, `Card\Badge` | **candy-pty in `require`**, sugar-toast, candy-buffer, candy-focus | **No.** It pulls `candy-pty` into sugar-crush's runtime `require` (it is dev-only today), and its components are `SizedItem::render()`, not TEA `Model`s. Its `Badge`/`Toggle` designs are a visual reference only. |
| `candy-hermit` | fuzzy overlay over a background view | candy-pty, candy-ansi, … | No (candy-pty) |
| `candy-zone` | bubblezone `Manager` | — | No (duplicates `candy-mouse`) |
| `sugar-readline`, `sugar-boxer`, `sugar-stickers`, `sugar-skate`, `candy-palette` | — | — | Not relevant. `candy-palette` is terminal colour-*profile* detection, not a command palette. `sugar-skate` is a KV store; settings belong in the existing JSON layers. |

### 3.3 Recommended composition

- `candy-forms` `Form` with one `Group` per category: hydrate the effective values, diff `values()` against them, run `validateAll()`.
- `candy-fuzzy` for search across title, key, description and enum options.
- `candy-mouse` zones for tabs, rows, badges and buttons.
- `candy-focus` for the three focus regions: tabs, fields, detail/actions.
- `sugar-veil` for the diff-preview and confirm modals.
- `candy-kit` `StatusLine` for the ✓ ⚠ ✗ badge glyphs.
- `candy-sprinkles` for the chrome, and `candy-core` `AtomicJsonFile` for writes.
- New dependencies: `sugar-diff` (P2) and `sugar-toast` (P3), each a `require` bump only (verify with `php tools/check-path-repos.php --no-lib-path-repos`).

---

## 4. Design

### 4.1 Principles

1. **One schema, many consumers.** The schema produces the form, the sidebar summary, the `docs/SETTINGS.md` key tables, the `LayeredSettings::LAYERED_KEYS`/`PROJECT_TIER_KEYS` constants (asserted equal), the env cross-reference, and the tier-ceiling test.
2. **The UI never grants trust from the project side.** The project tier cannot hold any `Security`, `Exec`, `Egress`, `Spend` or `Prompt` key. Trust keys are written only to layer 4 and only by the user's explicit confirmed action, and they apply on the next launch: the frozen-per-process rule stays.
3. **Provenance is always visible.** Every field shows where its effective value comes from: default, project, project-local, user settings, user config, session, env or flag. An env or flag lock makes the field read-only, with the variable named.
4. **Honest apply modes.** Each key declares `live`, `nextTurn` or `restart`, and the UI badges it. "Saved" never implies "applied".
5. **Nothing reaches the model.** Settings feedback uses toasts and the editor's own status line, never transcript rows, because those go to the provider. The exception is the existing launch notices.
6. **Mid-turn safe.** Browsing and saving are allowed mid-turn; live application that would mutate `Chat::$backend` is deferred to turn end (§4.8).

### 4.2 The schema [P]

All classes below are in `SugarCraft\Crush\Config\Settings\`, one type per file, final.

```php
final class SettingDefinition
{
    private function __construct(
        public readonly string $key,                 // dotted: "compaction.autoPercent"
        public readonly SettingType $type,           // Bool, Int, Float, Enum, String, Secret, Path, Url, StringList, Map, Json
        public readonly mixed $default,              // null = "unset" (distinct from 0/"")
        public readonly SettingCategory $category,
        public readonly RiskClass $riskClass,        // Cosmetic|Narrowing|Tuning|Spend|Exec|Security|Egress|Prompt
        public readonly bool $projectSettable,       // invariant: only Cosmetic|Narrowing|Tuning
        public readonly bool $layered,               // false => answered by config.json only (enabledSkills, trust*, claudeMcp*)
        public readonly ApplyMode $applyMode,        // Live|NextTurn|Restart|Frozen
        public readonly ?string $envVar,             // "SUGARCRUSH_PARALLEL_TOOL_DEADLINE"
        public readonly ?string $cliFlag,            // "--permission-mode"
        public readonly UiEditability $ui,           // Easy|List|Complex|ReadOnly|Hidden
        public readonly array $enumValues,           // list<string>, or a provider of them (closure-free: an OptionsSource enum)
        public readonly ?OptionsSource $optionsSource, // Themes|Providers|Skills|RulePacks|Tools|PermissionModes
        public readonly ?int $min, public readonly ?int $max,
        public readonly string $labelKey,            // i18n: "settings.compaction.autoPercent.label"
        public readonly string $helpKey,             // i18n: "settings.compaction.autoPercent.help"
        public readonly ?string $docAnchor,          // "SETTINGS.md#compaction"
        public readonly ?string $readerSymbol,       // "Bootstrap::resolvedMaxToolSteps" — doc column + drift check
    ) {}
    public static function new(string $key, SettingType $type, mixed $default): self { /* … */ }
    public function withCategory(SettingCategory $c): self { return $this->mutate(category: $c); }
    // … with*() for every field, via private mutate()
}
```

**Supporting types**, each in its own file:
- `SettingsSchema` (final, static registry: `all()`, `byKey()`, `inCategory()`, `projectTierKeys()`, `layeredKeys()`, `envMap()`);
- `SettingType`, `SettingCategory`, `RiskClass`, `ApplyMode`, `UiEditability`, `OptionsSource` (enums);
- `SettingValidator` (interface) with `RangeValidator`, `EnumValidator`, `AbsolutePathValidator`, `ExecutableValidator`, `UrlValidator`, `GlobListValidator`, `ThresholdOrderValidator` (cross-field).

`SettingsSchema::all()` holds the 26 existing keys from day one. New keys are added as their features land.

**Invariant tests** (P0):
- `LayeredSettings::LAYERED_KEYS === SettingsSchema::layeredKeys()` (for the subset that existed before; afterwards the constant is *derived*).
- `PROJECT_TIER_KEYS === SettingsSchema::projectTierKeys()`.
- No `projectSettable` key has a `riskClass` outside {Cosmetic, Narrowing, Tuning}.
- `titleModel`, `summaryModel` and `parallelToolDeadlineSeconds` are classified as Tuning, which preserves today's `PROJECT_TIER_KEYS` without changing behaviour.
- Every `envVar` named in the schema has a row in `docs/ENVIRONMENT.md` (this composes with `EnvRosterDriftTest`).
- Every `labelKey`/`helpKey` exists in `lang/en.php`.
- Every `readerSymbol` resolves to a real method, by reflection.

### 4.3 Resolution and provenance [P]

`SettingsResolver` (in `Config\Settings`) builds `ResolvedSetting { key, value, source: SettingSource, sourcePath: ?string, shadowed: list<SettingSource>, locked: bool, lockReason: ?string }`.

**Precedence** (highest first):

1. CLI flag
2. env var
3. **session overlay**
4. `config.json`
5. `~/.sugar-crush/settings.json`
6. `settings.local.json`
7. `settings.json` (project)
8. schema default

This is today's order, with the session overlay inserted above the files and below env. `LayeredSettings::projectKeySource()` already answers "which project file"; the resolver generalises that across all layers by reading each file once (reusing `LayeredSettings::readFile()` semantics: tolerant for settings, strict for permission keys).

**Session overlay.** `Bootstrap::$sessionSettings` (static, beside the existing static `$projectRootForSettings`) is merged by `mergedConfig()` above layer 4.
- Because `EngineBackend::completeAsync()` `pcntl_fork()`s *after* the parent mutates it, the turn child and every Task sub-agent inherit the overlay.
- Background daemons (`/bg`) are separate processes and do **not** inherit it. The UI states this on the Session tier.

### 4.4 Writing [P]

`SettingsWriter` (in `Config\Settings`) is a **new, separate door**, so the `onConfigChange` census in `ConfigWriteProducerDocumentationDriftTest:290` stays `['provider','theme']`. The editor's theme and provider fields still route through `Chat::selectPaletteTheme()`/`selectPaletteProvider()` in v1, so those two keys keep exactly one writer each.

**Target by tier:**

| Tier selector in UI | File | Allowed keys | Notes |
|---|---|---|---|
| **You (all projects)** — default | `Bootstrap::userConfigPath()` (`config.json`, or the `--config` file) | every `ui ≠ ReadOnly` key | Keeps README's "`settings.json` is never written" true, and the written value outranks `settings.json` so it sticks. If `settings.json` also sets the key, the UI shows "your settings.json value is now overridden". |
| **This project (local)** | `<root>/.sugar-crush/settings.local.json` | `projectSettable` only | Enabled only when the root is trusted (`Bootstrap::projectSettingsTrusted()`). Otherwise the tier is greyed out with "project settings are ignored until you trust this project" and a [Trust…] action. If a user-tier value shadows the key, a warning is shown. |
| **This project (shared, committed)** | `<root>/.sugar-crush/settings.json` | `projectSettable` only | Offered behind an "advanced" toggle; the diff preview names the file as git-tracked |
| **This session only** | in-memory overlay | every key whose `applyMode ≠ Frozen` | Nothing is written; cleared on exit |

**Write mechanics:**
- The whole file is read, the patch applied, then written with `AtomicJsonFile::new($path)->withPermissions(0600)->write()` (`candy-core/src/Util/AtomicJsonFile.php:167`; flock + same-dir temp + rename).
- **Dotted keys** (`compaction.autoPercent`) are stored as nested objects. That is a deliberate change from `writeUserConfig()`'s shallow merge, so the writer merges recursively, but only below keys the schema declares as objects.
- **Reset to default** deletes the key from the target file rather than writing the default, so a later change to the default is still picked up.
- **Strict keys** (`permissionMode`, `permissionRules`) are validated with the same parser `permissionGate()` uses *before* writing. The UI must never be able to write a value that would refuse the next launch.
- **Trust actions** append or remove `realpath($root)` in `trustedProject*` lists, in the layer-4 file only, after a Veil confirm modal that spells out what the grant enables (hooks run scripts, MCP spawns servers, commands run `` !`cmd` ``, settings let the repo set the project-tier keys). They are badged **"applies next launch"**.
- **Census:** `SettingsWriter::write()` is the only method in `src/` that writes a settings file besides `writeUserConfig()`. A new `SettingsWriterCensusTest` token-walks `src/` the way the existing census does and asserts:
  - writes reach files only through `SettingsWriter` (+ the two legacy closures);
  - every key it is called with is in `SettingsSchema`;
  - it refuses any non-`projectSettable` key on the project tiers (unit-tested by attempting `permissionMode`, `trustedProjectSettings`, `statusLine`).

### 4.5 Validation [P]

- **Per field:** the schema validators become candy-forms validators (`Input::withValidator(\Closure)`, `withValidateOn(ValidateOn::Change)`). `Select`/`MultiSelect` need no validator, because their options *are* the domain.
- **Cross-field:** `ThresholdOrderValidator` runs at save (`Form::validateAll()` plus a schema-level pass), e.g. `reminder < auto < block`, and `maxCostUsd > 0`.
- **Semantic warnings** are not errors and do not block the save:
  - `permissionMode` ∈ {default, accept-edits, plan, auto} on the TUI warns that "Ask verdicts are currently denied in the TUI (no approver)", until reports 01/04 P0 land;
  - `maxToolSteps > 30` with no `maxCostUsd` warns that there is no spend backstop (Sglang/Custom report $0);
  - `statusLine` and `test.command` warn that the command "runs on a timer / after turns".
- **Number parsing** mirrors `resolvedMaxToolSteps()`: nonsense values are refused in the UI rather than silently falling back to the default.

### 4.6 Applying changes [P]

On save, `SettingsEditor` emits `SettingsSavedMsg{changed: list<key>, tier}`. `Chat::applySettings(array $changed)` groups the keys by `ApplyMode`:

| Apply mode | Mechanism | Keys (initial) |
|---|---|---|
| **NextTurn** | nothing to do: the child reads the merged config at `runTurn()` start | `parallelToolCalls`, `parallelToolDeadlineSeconds`, `maxOutputTokens`, and after P4 plumbing `maxToolSteps`, `tools.*` caps, `memory.*`, `webSearch.*`, … |
| **Live — Chat state** | `Chat::mutate()` | `theme` (`themeName`), `maxCostUsd` (same field `/budget` sets), `promptSuggestions`, `compaction.*` (rebuild `ContextCompactor` from a new `CompactorConfig`, `Chat.php:1375`), `ui.*` |
| **Live — Cmd** | returned `Cmd` | `mouse` → `Cmd::enableMouseCellMotion()` / `Cmd::disableMouse()`; `statusLine` → re-run `StatusLineCommand::configure()` |
| **Live — backend swap** | `EngineBackend::withMaxSteps()` (`:603`), `withPermissionGate()` (`:467`), `withTools()` (`:377`), `withSkillRegistry()` (`:421`); `Chat::withBackend()` (`:6218`) | `maxToolSteps` (until it is NT), `permissionMode/Rules`, `allowedTools/disabledTools`, `disabledSkills`. **Deferred while `inFlight`** (§4.8). |
| **Live — provider** | the existing `selectPaletteProvider()` | `provider`, `models`. **Fix first:** `backendFor()` must receive `taskManager`/`taskPool`/`rulesState`, or the Task tool disappears (baseline §11.1 #8). The editor should not ship a provider field before that fix. |
| **Restart** | badge only | `instructions`, `enabledSkills`, `titleModel`/`summaryModel` (until `Chat` gains setters), `hooks.*`, `mcp.*`, `sessionRetentionDays`, `debug.*` |
| **Frozen** | badge "applies next launch" | trust keys, `claudeMcp*` |

**Feedback.** After a save, a `sugar-toast` alert summarises: "Saved 3 settings to ~/.sugar-crush/config.json · 2 apply now · 1 next turn · 1 needs restart". The editor's own footer keeps a persistent "⟳ restart needed: instructions, enabledSkills" until the process exits.

### 4.7 The editor UI [P]

#### Opening

| Door | Detail |
|---|---|
| `/settings [query]` (alias `/config`) | New `CommandSpec('settings', 'Edit settings', 'App', paletteAction: PaletteAction::OpenSettings, paletteLabel: 'Open settings', argumentHint: '[search]')`. This adds a slash command, a palette row and an F10 **App** menu row in one step. Add `settings` to `CommandRegistry::CONTROL_PLANE`, so a checkout's `.sugar-crush/commands/settings.md` cannot shadow the editor with a look-alike. `/settings compaction` opens with the search pre-filled. |
| `Ctrl+,` | Today it focuses the sidebar. Proposed: the first press focuses the sidebar (unchanged); **Enter on `Pane::Settings` opens the editor**, replacing the generic palette door for that one pane (`KeyboardHandler::enterOpensPaletteDoor()` `:297` gains an arm). A second `Ctrl+,` while the sidebar is focused also opens it. |
| Sidebar click | Click on the sidebar title or "Edit…" footer zone → open |
| Mid-turn | Allowed: the editor is an overlay-class view that writes no history. The `/settings` *slash* form is refused mid-turn like every slash command (`Chat.php:7178`); `Ctrl+,`, the palette row and the click still open it. |

#### Layout

The editor is a **full-band view**, following the `renderAgentDashboard()` precedent: menu bar and status bar stay, and the dock and chat are hidden while it is open.

On terminals narrower than 70 columns or shorter than 18 rows, it falls back to a **single column** (category select at the top, field list, detail toggled with `i`).

**Wide layout (≥ 100 cols), General tab:**

```
 File  Session  Model  App  Layout                                    [⚙ Settings]  tokyoNight
╭─ ⚙ Settings ────────────────────────────────────────────────────────────────────────────────────╮
│ Search: compact▏                                    Writing to: (•) You  ( ) Project  ( ) Session │
│ ─────────────────────────────────────────────────────────────────────────────────────────────── │
│  Model & Provider │ Agent loop │▌Context & Compaction▐│ Permissions │ Tools │ Memory │ More ▸   │
│ ─────────────────────────────────────────────────────────────────────────────────────────────── │
│  Compaction mode            llm ▾                  ● you      ⟳ live      │ Auto-compact at      │
│  Remind at                  70 %                   ○ default  ⟳ live      │ ───────────────────  │
│▸ Auto-compact at            80 %  (was 85)         ✎ edited   ⟳ live      │ When the estimated   │
│  Block new turns at         95 %                   ○ default  ⟳ live      │ context reaches this │
│  Keep recent exchanges      10                     ◆ project  ⟳ live      │ share of the model's │
│  Idle compaction offer      3600 s                 ○ default  ⟳ live      │ window, the next     │
│  Compact between steps      off                    ○ default  ⧗ next turn │ prompt is held and   │
│  Context window override    (provider: 1 048 570)  ○ default  ⟳ live      │ older exchanges are  │
│                                                                            │ summarised.          │
│                                                                            │                      │
│                                                                            │ key   compaction.    │
│                                                                            │       autoPercent    │
│                                                                            │ default 85           │
│                                                                            │ source  you →        │
│                                                                            │  ~/.sugar-crush/     │
│                                                                            │  config.json         │
│                                                                            │ also set in project  │
│                                                                            │  settings.json (90)  │
│                                                                            │  — overridden        │
│ ─────────────────────────────────────────────────────────────────────────────────────────────── │
│  1 unsaved change · must be > Remind (70) and < Block (95) ✓                                     │
│  [Ctrl+S] Save…  [Ctrl+Z] Undo field  [Ctrl+R] Reset to default  [Tab] Next  [Esc] Close  [?]   │
╰─────────────────────────────────────────────────────────────────────────────────────────────────╯
```

**Legend:**
- Provenance glyphs: `○ default` · `◆ project` · `● you` · `◐ session` · `⚑ env` · `⚐ flag` · `✎ edited` (unsaved).
- Apply badges: `⟳ live` · `⧗ next turn` · `↻ restart` · `❄ next launch`.
- Badges render through `candy-kit` glyph conventions; colours come from `Theme::shellSuccess/Warning/Info/Muted`.

**Env-locked field and a list field (Agent loop tab):**

```
│  Max tool steps per turn    8      ▕████░░░░░░░░░░░░▏ 1…100   ● you    ⟳ live (next turn)        │
│  No-progress watchdog       120 s                             ○ default ⧗ next turn              │
│  Parallel read-only tools   on                                ⚑ SUGARCRUSH_DISABLE_PARALLEL_…   │
│                             🔒 locked by environment — unset the variable to edit here            │
│  Parallel batch deadline    90 s                              ○ default ⧗ next turn              │
│  Spend cap (USD)            (none)                            ◐ session (/budget 5.00 → 5.00)    │
│  Sub-agent max turns        50                                ○ default ⧗ next turn              │
│  Sub-agent concurrency      5                                 ○ default ⧗ next turn              │
│  ⚠ 30+ steps with no spend cap: Sglang/Custom report $0, so nothing would stop a runaway turn.   │
```

**MultiSelect over discovered items (Tools tab):**

```
│  Disabled tools                                              ◆ project (.sugar-crush/settings.json)│
│    [ ] Bash   [ ] Read   [ ] Edit   [ ] Write   [ ] Glob   [ ] Grep                             │
│    [x] WebFetch   [x] WebSearch   [ ] doctor   [ ] Skill   [ ] Lsp   [ ] Task                    │
│    [ ] mcp__git__*            + add glob…                                                         │
│    ↻ restart — the tool set is assembled at launch                                                │
```

**Save preview** (a `sugar-veil` modal over the editor; the diff comes from `sugar-diff` and is rendered by `Tui\DiffGutter`):

```
                ╭─ Save 3 changes? ───────────────────────────────────────────╮
                │ ~/.sugar-crush/config.json                                    │
                │  @@ -4,6 +4,11 @@                                             │
                │     "theme": "tokyoNight",                                    │
                │ -   "maxToolSteps": 8,                                        │
                │ +   "maxToolSteps": 40,                                       │
                │ +   "compaction": {                                           │
                │ +       "autoPercent": 80                                     │
                │ +   },                                                        │
                │                                                               │
                │ Applies: ⟳ now 1 · ⧗ next turn 1 · ↻ restart 0                │
                │ ⚠ maxToolSteps 40 with no spend cap                           │
                │                                                               │
                │        [Enter] Save     [s] Save for this session only        │
                │        [Esc] Back to editing                                  │
                ╰───────────────────────────────────────────────────────────────╯
```

**Trust confirm** (Permissions › Project trust):

```
                ╭─ Trust /home/you/src/that-project for… ──────────────────────╮
                │ [x] Settings  lets .sugar-crush/settings{,.local}.json set:   │
                │               theme, titleModel, summaryModel, disabledSkills,│
                │               disabledTools, parallelToolCalls, …             │
                │ [ ] Hooks     runs scripts from .sugar-crush/hooks.yaml       │
                │ [ ] MCP       spawns servers named in .mcp.json               │
                │ [ ] Commands  lets !`cmd` run in .sugar-crush/commands/*.md   │
                │                                                               │
                │ Written to ~/.sugar-crush/config.json. ❄ Applies next launch  │
                │ — trust is frozen for the life of this process.               │
                │        [Enter] Grant   [Esc] Cancel                           │
                ╰───────────────────────────────────────────────────────────────╯
```

**Upgraded sidebar** (`SettingsPane`, keeping its read-back role):

```
╭ ⚙ settings ───────────╮
│ Provider               │
│   dev-sglang           │
│ Model                  │
│   …V4-Flash-0731       │
│ Permission mode        │
│   bypass-permissions ⚑ │
│ Steps / turn           │
│   8                    │
│ Compaction             │
│   70 / 85 / 95 %       │
│ ⟳ restart needed (1)   │
│ Enter: edit  /settings │
╰────────────────────────╯
```

The sidebar footer changes from `read-only — /theme, /model`; `tests/Tui/Components/SettingsPaneTest.php` and the README "Pane docking" paragraph (`README.md:790`) must change with it.

#### Categories and field types

| Tab (`SettingCategory`) | Fields (type → candy-forms field) |
|---|---|
| **Model & Provider** | `provider` (Select over `Bootstrap::availableProviders()`) · `models[provider]` (Input + fuzzy suggestions from `sugarcrush models`) · `titleModel`, `summaryModel` (Input + suggestions) · `maxOutputTokens` (Input int, "unset" chip) · `temperature` (Slider 0–2 step 0.05) · `provider.retryAttempts` · `connectTimeoutSeconds` · `streamIdleTimeoutSeconds` · providers list (**read-only** `Note` rows from `config.dev.json`, with "open file") |
| **Agent loop** | `maxToolSteps` (Slider/Input) · `turnIdleTimeoutSeconds` · `parallelToolCalls` (Confirm) · `parallelToolDeadlineSeconds` · `maxCostUsd` · `steering.mode` (Select) · `loopGuard.repeatThreshold` · `promptSuggestions` (Confirm) |
| **Context & Compaction** | `compaction.*` (Select mode, Inputs with range validators) · `contextWindowOverride` · `contextPruning.*` (when it lands) · `repoMap.*` · `env.*` · `tools.spillAboveChars` |
| **Permissions** | `permissionMode` (`Select::withEnum(PermissionMode::class)`) · `permissionRules` (P4 row editor; v1 read-only table identical to `/permissions`) · `permissions.readOnlyBashProfile` · auto-mode breaker ints · **Project trust** sub-section (four Confirm toggles for the *current root*, plus a read-only list of all trusted roots) |
| **Tools** | `allowedTools`, `disabledTools` (MultiSelect over `Bootstrap::unfilteredTools()` names + free glob) · output caps (Inputs) · `tools.bash.timeoutSeconds` · `tools.bash.includeGitInstructions` · `webSearch.endpoint` (Input + `UrlValidator`) · `webSearch.maxResults` · `mcp.resultCapBytes` · `mcp.toolTimeoutSeconds` |
| **Memory & Rules** | `memory.promptScopes` (MultiSelect) · `memory.defaultScope` (Select) · memory caps · `disabledRules` (MultiSelect over `RuleLoader` packs, showing path-relative names) · `rules.standingMaxBytes` · `instructions` (Text, one glob per line) |
| **Skills** | `enabledSkills`, `disabledSkills` (MultiSelect over `SkillRegistry`, badged by source: built-in, user, project, foreign) · `skills.pathNudges` |
| **Sub-agents** | `subagents.model`, `.maxTurns`, `.maxConcurrent` · presets (read-only list from `AgentPresetRegistry`) |
| **Git & Automation** (as features land) | `git.autoCommit`, `git.attribution.*`, `lint.commands` (Map row editor), `test.command`, `test.auto`, `checkpoints.files`, `watchFiles` |
| **Interface** | `theme` (Select, **live preview** while focused, reverted on Esc) · `terminalBackground` · `mouse` · `ui.*` · `statusLine.command` + `statusLine.refreshSeconds` · `notify.bell` / `notify.command` · `sessions.autoTitle`, `sessionRetentionDays`, `sessions.maxCheckpoints` |
| **Hooks & MCP** (read-only) | Hook list from `HookManager` (name, matcher, source file, timeout, disabled) · MCP servers from `McpClient` (name, transport, status, tools count, sanitised `mcp__` prefix, trust state) · "Open hooks.yaml / .mcp.json" actions that print the path |
| **Advanced** | `debug.*`, `notices.transcriptLimit`, `commands.shellBudgetSeconds`, `hooks.defaultTimeoutSeconds`, raw JSON view of the merged config (read-only `Viewport`) |

#### Interaction

**Keyboard.** These are new `KeyBindingRegistry` rows in a new context `CONTEXT_SETTINGS = 'Settings editor'`; each needs a `KeyBindingDriftTest` observation.

| Key | Action |
|---|---|
| `/` or typing in the search box | fuzzy filter across all categories (`SmithWatermanMatcher` over label, key, help, enum values). Results become a flat list grouped by category, with matched characters highlighted (`Highlighter`). `Enter` on a result jumps there (`Form::focusField(key)` after `nextGroup()` to its category). |
| `←/→` or `Ctrl+PgUp/PgDn` (when the tab strip is focused) | switch category (`Form::nextGroup/prevGroup`) |
| `Tab` / `Shift+Tab` | cycle focus: search → tabs → fields → actions (`FocusRing::ofStrict('search','tabs','fields','actions')`) |
| `↑/↓` | move between fields; `Enter`/`Space` edits or toggles (the field's own `update()`) |
| `Ctrl+S` | save → diff preview modal |
| `Ctrl+R` | reset the focused field to its default (removes the key from the target tier) |
| `Ctrl+Z` | revert the focused field to its loaded value |
| `Ctrl+T` | cycle the target tier (You → Project local → Session) |
| `i` | toggle the detail panel (narrow layout) |
| `Esc` | close. If there are unsaved edits, a Veil prompt offers `[s] save · [d] discard · [Esc] keep editing`. |
| `?` | key help for this context (existing `renderKeyHelp`) |

**Mouse** (`candy-mouse` zones, recorded with the `settings:` prefix and hit-tested via `Scanner::prefixed('settings:')`):
- click a tab → switch category;
- click a row → focus it; click a Confirm value → toggle it;
- click a provenance badge → reveal the source file path in the detail panel;
- click `[Save…]`, `[Reset]` or `[Close]`;
- the wheel scrolls the field list.

Clicks are suppressed when `Chat::mouseClicksEnabled()` is false, as elsewhere.

#### State model

- `SettingsEditor` (in `Tui\Settings`) is a TEA model held in `App::$settingsEditor` (nullable, like the skill picker).
- It holds: the `Form`; the `ResolvedSetting` snapshot it was hydrated from; the target tier; the search query and results; the focus ring; the unsaved-change map; and the modal state (none, preview or confirm).
- `App::update()` routes keys to it before `delegateToChat()` while it is open (`App.php:1064`, `:2057`), the same way the menu and picker claim keys. `KeyboardHandler::shellOwnsKeyboard()` gains a fourth state.
- Rendering: `Tui\Renderer` (`:498`) gains `if ($a->settingsEditor !== null) return self::renderSettingsEditor(...)`, which mirrors `renderAgentDashboard()`'s clipping and zone discipline (clear the chat zones, add the `settings:` zones).

### 4.8 Coexistence with the forked turn child [P]

- **The running turn is never affected.** The child forked before the save and read its config at `runTurn()` start (`EngineBackend.php:782`). A NextTurn key therefore applies to the *next* turn with no further work. The preview modal states this when `Chat::$inFlight` is set: "A turn is running — changes apply from the next turn."
- **Backend-swap keys are deferred.** If `inFlight` holds when `applySettings()` runs, the change set is parked in `Chat::$pendingSettingsApply` and applied in the same place queued prompts are released (`Chat::releaseQueuedPrompts()`, `:7778`), *before* the first queued prompt dispatches. This avoids swapping `$backend` under a promise that the in-flight turn's completion handlers still close over [I].
- **Chat-state live keys apply immediately, even mid-turn**, because they do not touch history: theme, mouse, `ui.*`, compaction thresholds. Compaction thresholds are only consulted in `submit()` anyway.
- **Session overlay and fork:** see §4.3. Task sub-agents inherit the overlay through the turn child; `/bg` daemons do not.
- **Mid-turn refusal rule:** the editor's Save writes no history, so it is exempt, the same way overlays "open and browse" mid-turn (`Chat.php:7622-7626`). Exempting it needs a sentence in that doc-block, plus a test.

### 4.9 i18n [P]

sugar-crush has **no `Lang` class and no `lang/` directory today** [V] (`grep Lang::t src` finds only prose). The editor is the right place to start:
- add `src/Lang.php` (`final class Lang extends \SugarCraft\Core\I18n\Lang { protected const NAMESPACE = 'crush'; protected const DIR = __DIR__ . '/../lang'; }`, the `candy-pty/src/Lang.php` pattern);
- add `lang/en.php` with every `settings.*.label/help` key, the category names, badges and button labels.

Other locales follow `LOCALES.md`, with a `LangParityTest` like the one `candy-palette/tests/` is adding in the working tree. Labels reach candy-forms through `withTitle(Lang::t($def->labelKey))`. Values with dynamic text (source paths) use `withTitleFunc()`.

### 4.10 Docs generated or checked from the schema [P]

- **`docs/SETTINGS.md`** gets a new section, "Every key", inside `<!-- settings:begin -->`/`<!-- settings:end -->` markers. The table is generated by `tools/gen-settings-doc.php` (or a `sugarcrush settings --markdown` subcommand) with the columns of §1.6. `SettingsSchemaDocDriftTest` regenerates the block and asserts it is byte-equal (the `ansi/README.md` `--check` pattern).
  - The hand-written prose and the existing "Which keys are layered" table stay. `TrustKeyDocumentationDriftTest` keeps pinning them, and now compares against `SettingsSchema` instead of the raw constants.
- **`docs/ENVIRONMENT.md`**: one new column, "Settings key", filled from `SettingDefinition::$envVar`. `EnvRosterDriftTest` is unchanged; the new test checks the column.
- **`README.md`**:
  - the "Settings files" table row 4 ("who wrote it") must name the editor;
  - the line "`settings.json` is never written" stays true;
  - the "Pane docking" paragraph changes;
  - the slash-command roster gains `/settings` (`ReadmeRosterDriftTest:237`).
- **`docs/SETTINGS.md` prose that becomes false** and must be rewritten in the same PR:
  - "exactly two keys are ever written through the chat's config-change door … a third, `layout`";
  - "Only `provider` and `theme` are ever written to `config.json`";
  - "When a change takes effect … only two keys".

  These sentences are pinned by `ConfigWriteProducerDocumentationDriftTest` (paragraph-scoped rules) and `ThemePersistenceFramingTest`. Follow those tests' retraction convention: rewrite in place, retract rather than delete where a test expects the retraction.

---

## 5. Implementation plan

### 5.1 New classes (one type per file)

| File | Type | Purpose |
|---|---|---|
| `src/Config/Settings/SettingDefinition.php` | final class | schema row (immutable, `new()` + `with*()` via `mutate()`) |
| `src/Config/Settings/SettingsSchema.php` | final class | static registry: `all()`, `byKey()`, `inCategory()`, `layeredKeys()`, `projectTierKeys()`, `envMap()` |
| `src/Config/Settings/SettingType.php` | enum | Bool, Int, Float, Enum, String, Secret, Path, Url, StringList, Map, Json |
| `src/Config/Settings/SettingCategory.php` | enum | tabs, with `labelKey()` and `order()` |
| `src/Config/Settings/RiskClass.php` | enum | with `projectSettableAllowed(): bool` |
| `src/Config/Settings/ApplyMode.php` | enum | Live, NextTurn, Restart, Frozen, with a `badge()` glyph |
| `src/Config/Settings/UiEditability.php` | enum | Easy, List, Complex, ReadOnly, Hidden |
| `src/Config/Settings/OptionsSource.php` | enum | Themes, Providers, Skills, RulePacks, Tools, PermissionModes, McpServers |
| `src/Config/Settings/SettingSource.php` | enum | Default, ProjectShared, ProjectLocal, UserSettings, UserConfig, Session, Env, Flag |
| `src/Config/Settings/ResolvedSetting.php` | final class | value + provenance + lock |
| `src/Config/Settings/SettingsResolver.php` | final class | layer reads → `ResolvedSetting` map; reuses `LayeredSettings` |
| `src/Config/Settings/SettingsWriter.php` | final class | tiered atomic writes, reset, trust grant; the new census door |
| `src/Config/Settings/SettingsTier.php` | enum | You, ProjectLocal, ProjectShared, Session |
| `src/Config/Settings/SettingValidator.php` | interface | `validate(mixed, array $all): ?string` |
| `src/Config/Settings/Validator/{Range,Enum,AbsolutePath,Executable,Url,GlobList,ThresholdOrder}Validator.php` | final classes | — |
| `src/Config/Settings/OptionsProvider.php` | final class | resolves an `OptionsSource` to live lists (theme names, providers, skills, rule packs, tools) |
| `src/Tui/Settings/SettingsEditor.php` | final class (TEA model) | Form + search + focus + modal state |
| `src/Tui/Settings/SettingsFieldFactory.php` | final class | `SettingDefinition` + `ResolvedSetting` → candy-forms `Field` |
| `src/Tui/Settings/SettingsTabStrip.php` | final class | category strip with `candy-mouse` zones |
| `src/Tui/Settings/SettingsDetailPanel.php` | final class | help, default, source, shadowing, badges |
| `src/Tui/Settings/SettingsSearch.php` | final class | fuzzy index and results |
| `src/Tui/Settings/SettingsSavePreview.php` | final class | per-file diff (sugar-diff) + apply summary, Veil-composited |
| `src/Tui/Settings/SettingsFormTheme.php` | final class | `Crush\Theme` → `Forms\Theme` adapter |
| `src/Tui/Settings/SettingsSavedMsg.php`, `SettingsClosedMsg.php`, `OpenSettingsMsg.php` | final classes | messages |
| `src/Lang.php` + `lang/en.php` | final class + data | i18n (§4.9) |
| `tools/gen-settings-doc.php` (or `Cli\Subcommands` `settings --markdown\|--check`) | script | doc generator |

### 5.2 Classes to modify

| File:line | Change |
|---|---|
| `src/Config/LayeredSettings.php` (`LAYERED_KEYS`, `PROJECT_TIER_KEYS`) | P0: assert equal to the schema. P2+: derive them (`SettingsSchema::layeredKeys()`), keeping the public constants for BC as computed `static` accessors. **Trap:** `TrustKeyDocumentationDriftTest` reads the constants, so update it in the same change. |
| `src/Cli/Bootstrap.php:3393` `mergedConfig()` | add the session overlay (`self::$sessionSettings`) above `rawUserConfig()`; add `useSessionSettings()` |
| `src/Cli/Bootstrap.php:1215` `chat()` | pass `CompactorConfig` built from the schema (fixes the never-passed `compactorConfig`); pass `onSettingsWrite: SettingsWriter`; build `SettingsResolver` once |
| `src/Cli/Bootstrap.php:2852` `backendFor()` | **fix**: accept and forward `taskManager`/`taskPool`/`rulesState` from `Chat` (prerequisite for a provider field) |
| `src/Cli/Bootstrap.php:2945` `resolvedMaxToolSteps()` | P4: make `maxToolSteps` NextTurn by reading it in `runTurn` (below) |
| `src/Backend/EngineBackend.php:776-790` `runTurn()` | read schema-backed NextTurn keys from `$userConfig` (`maxToolSteps`, caps, memory caps, `turnIdleTimeoutSeconds`). The idle watchdog lives in the **parent** (`:1444-1455`), so read it in `completeAsync()` before the fork. |
| `src/Backend/EngineBackend.php:99` | `COMPLETE_TIMEOUT_SECONDS` → default for `turnIdleTimeoutSeconds` |
| `src/Chat.php:634,677` (ctor) | add `?\Closure $onSettingsWrite`, `?CompactorConfig` already there; add `pendingSettingsApply` |
| `src/Chat.php` (new) `applySettings(array $changed): array` | the §4.6 table; deferral while `inFlight`; release at `:7778` |
| `src/Chat.php:4945` `mouseMode()`, `:4955` `mouseClicksEnabled()` | consult the resolved `mouse` setting after env |
| `src/Chat.php:8240` `dispatchCommand()` | `/settings`, `/config` → `OpenSettingsMsg` (App-level) |
| `src/Chat.php:14038` `runRootPaletteAction()` | `PaletteAction::OpenSettings` arm |
| `src/Chat.php:7618-7660` doc-block | add the settings editor to the "writes nothing → not refused" overlay rule |
| `src/Commands/CommandRegistry.php` (`all()`, `CONTROL_PLANE`) | new `settings` spec (+ alias `config`); add `settings` to `CONTROL_PLANE` |
| `src/Palette/PaletteAction.php` | `case OpenSettings = 'open_settings';` |
| `src/Commands/KeyBindingRegistry.php:55-71,498` | `CONTEXT_SETTINGS` + about 10 rows; reword `shell.settings` ("Focus the settings pane — again, or Enter, to edit") |
| `src/Tui/KeyboardHandler.php:297,850` | Enter-on-Settings opens the editor; second `Ctrl+,` opens; `shellOwnsKeyboard()` includes the editor |
| `src/App/App.php:267,1064,2057,2292` | `settingsEditor` field + `withSettingsEditor()`; route keys and mouse to it while open; `mutate()` arm |
| `src/Tui/Renderer.php:498` | `renderSettingsEditor()` full-band branch (`renderAgentDashboard()` pattern, `:662`) |
| `src/Tui/Components/SettingsPane.php` | rows from `SettingsResolver` (provider, model, mode, steps, compaction, restart-pending); new footer; "Edit…" zone |
| `src/Tools/BuiltIn/WebSearch.php:52` | no private default; `webSearch.endpoint` → env → unset ⇒ "not configured" error |
| `src/Tools/BuiltIn/Bash.php:124-163` | gate the SugarCraft cadence behind `tools.bash.includeGitInstructions` (default false) |
| `composer.json` | `require` `sugarcraft/sugar-diff` (P2), `sugarcraft/sugar-toast` (P3); remove the `candy-kit` deferred-wiring row once `StatusLine` is used (its own text says "Delete this row when the wiring lands") |

### 5.3 Tests to add

Unit and behaviour tests, all under `tests/`:
- `Config/Settings/SettingsSchemaTest.php` — unique keys; defaults satisfy their own validators; enum defaults ∈ values; `projectSettable ⇒ riskClass ∈ {Cosmetic, Narrowing, Tuning}`; `layeredKeys`/`projectTierKeys` equal the `LayeredSettings` constants; every `labelKey`/`helpKey` exists in `lang/en.php`; every `readerSymbol` exists (reflection).
- `Config/Settings/SettingsResolverTest.php` — the precedence matrix (8 sources × representative keys); an untrusted project contributes nothing; env lock; shadow detection; tolerant vs strict readers.
- `Config/Settings/SettingsWriterTest.php`:
  - atomic write, mode 0600, `--config` honoured;
  - nested merge; reset removes the key;
  - the project tier refuses `permissionMode`, `trustedProjectSettings`, `statusLine`, `maxToolSteps`;
  - an untrusted root refuses the project tier;
  - trust grants are written only to layer 4;
  - a strict-key round trip leaves the launch parseable.
- `Config/Settings/SettingsWriterCensusTest.php` — token-walk census (the `ConfigWriteProducerDocumentationDriftTest` pattern): only `SettingsWriter` + the two legacy closures write settings files; alias refusal.
- `Config/Settings/SettingsSchemaDocDriftTest.php` — the generated block in `docs/SETTINGS.md` is byte-equal; the ENVIRONMENT.md "Settings key" column matches `envMap()`.
- `Config/Settings/SessionOverlayForkTest.php` — the overlay reaches `EngineBackend::runTurn()` in a forked child (guard `pcntl`); `/bg` daemons do not see it.
- `Tui/Settings/SettingsEditorTest.php` — hydrate from resolved values; edit → dirty map; `Ctrl+R` reset; `Ctrl+S` → preview; `Esc` with dirty → prompt; tier cycling greys out non-project keys; env-locked fields are readonly; search jumps to the field across categories.
- `Tui/Settings/SettingsFieldFactoryTest.php` — each `SettingType` → the expected candy-forms field class and options; MultiSelect is populated from `OptionsProvider`.
- `Tui/Settings/SettingsEditorRenderTest.php` — golden frames (`candy-testing` `Assertions::assertGoldenAnsi()`) at 120×40 and 60×20 (narrow fallback); no line wider than cols; zones carry the `settings:` prefix.
- `Tui/Settings/SettingsEditorMouseTest.php` — tab, row and button clicks; clicks off → ignored.
- `Chat/ApplySettingsTest.php` — live keys mutate `Chat` and return the expected `Cmd`s (mouse); backend-swap keys deferred while `inFlight` and applied on release; the provider switch keeps the Task tool (regression for the `backendFor()` fix).
- `Commands/SettingsCommandTest.php` — `/settings`, `/config`, `/settings <query>`; refused mid-turn as a slash command but open via palette/`Ctrl+,`; a project command named `settings` is refused (control plane).

Updates to existing tests:
- `KeyBindingDriftTest` — observations for every new binding id.
- `SettingsPaneTest` — new rows and footer.
- `TrustKeyDocumentationDriftTest` — compare against the schema.
- `ConfigWriteProducerDocumentationDriftTest` — prose rules follow the SETTINGS.md rewrite; the census itself stays `['provider','theme']`.
- `ReadmeRosterDriftTest` — the `/settings` row.

**CI obligations** (CLAUDE.md "Gotchas"):
- Every new test file must get a row in `scripts/parallel-tests-durations.tsv`, as `path<TAB>seconds<TAB>testcount`. Unlisted files run in zero shards.
- Re-pin `tests/Config/Support/suite-figure.json` via `php tests/Config/Support/refresh-suite-figure.php <junit.xml>` from a full run. Today it reads 12 483 tests and 177 317 assertions.
- Update the README test-count headline from the same run (`ReadmeSuiteFigureDriftTest`).
- Suites that arm timers keep using `tests/bootstrap.php` (`LoopPin::pinStableClock()`). The editor tests are pure `update()`/`view()` and need no loop.

### 5.4 Phases

| Phase | Scope | Effort | Ships |
|---|---|---|---|
| **P0 — Schema & truth** | `SettingDefinition`, `SettingsSchema` with the 26 existing keys; `SettingsResolver`; invariant tests; generated key table in `docs/SETTINGS.md`; fix the doc drift (`maxOutputTokens` is next-turn; `providers` in user config is not read; `enabledSkills` is layer-4-only) | **S** (2–3 days) | no UI; docs and tests |
| **P1 — Read-only editor** | `/settings` + palette/menu row + `Ctrl+,`/Enter door; full-band view with tabs, search, provenance and badges; Hooks/MCP/Permissions read-only tabs; i18n scaffold; upgraded sidebar | **M** (4–6 days) | a viewer that answers "what am I running with, and why" |
| **P2 — Editing & writer** | `SettingsWriter` (You tier + Project-local), validation, reset, diff preview (sugar-diff), census test, SETTINGS.md/README rewrites; edit only NextTurn and existing keys (`parallel*`, `maxOutputTokens`, `titleModel`, `summaryModel`, `disabled*`, `instructions`, `statusLine`, `permissionMode`, `maxToolSteps`), with restart badges where needed | **M** (5–7 days) | editable settings |
| **P3 — Live apply & session tier** | `Chat::applySettings()`; `backendFor()` Task-tool fix; mouse/theme/compaction/maxSteps/permission/tool-set live; session overlay + fork test; sugar-toast feedback; trust-grant flow | **M** (5–7 days) | changes take effect without restart |
| **P4 — Promote hard-coded behaviour** | batches from §2.2, each with plumbing + schema rows + tests: (a) loop and timeouts (`turnIdleTimeoutSeconds`, `subagents.*`, retry, connect); (b) compaction; (c) tool caps + WebSearch endpoint + Bash git guidance; (d) memory/rules/env block; (e) UI knobs. Later the competitor knobs (§2.3) as their features land. Complex editors (`permissionRules`, `modelPrices`, `lint.commands`) via `sugar-table`/`ItemList` row editing and `sugar-crumbs` for nesting. | **L** (incremental, 1–2 days per batch) | configurability |
| **P5 — Polish** | narrow-terminal layout, project-shared tier, "open file in $EDITOR" (once `/edit` exists), import/export of a settings profile, per-provider `models` | **S** | — |

### 5.5 Risks and open questions

1. **Prose-pinned docs.** `docs/SETTINGS.md` and `README.md` are dense with paragraph-scoped drift rules: retractions must stay inside retracting paragraphs, and figures are re-derived. Rewriting "only two keys are written" will red several tests in non-obvious ways. Budget time for this and run `tests/Config/` alone after every doc edit.
2. **Census shape.** The existing census follows `$this->onConfigChange` tokens. If `SettingsWriter` ever routes `provider`/`theme`, the census and its docs must change together. Keeping them on the legacy closure in v1 avoids that.
3. **The provider switch drops the Task tool** (`Chat.php:14106` → `Bootstrap::backendFor()` without `taskManager`). Exposing provider or model in the editor before the fix would make the bug easier to hit. Fix it in P3 before enabling those fields.
4. **Nested keys vs shallow merge.** `LayeredSettings::merge()` is shallow, so a project file's `compaction:{keepRecent}` would be *replaced* by a user `compaction:{autoPercent}`. Either flatten keys on disk (`"compaction.autoPercent": 80`, simplest, and it works with the existing `only()` filter) or make the merge one level deep for schema-declared objects. **Recommendation: dotted flat keys on disk**, to avoid changing merge semantics that the docs explain at length.
5. **Trust UX.** Making trust easy to grant from a UI weakens the deliberate friction of hand-editing. Mitigations:
   - a per-capability checkbox modal that spells out each capability;
   - a grant written only for the current `realpath($root)` (no subtrees, matching today's rule);
   - effect only on the next launch;
   - the existing launch report of project-tier tool removals still fires.
6. **Strict-reader coupling.** A UI write to `config.json` that produces JSON the strict reader rejects would refuse the next launch. Round-trip each write through `permissionConfigLayers()`'s parser in the writer, and test it.
7. **Session overlay in a static.** The overlay is global process state, like `projectRootForSettings`. Tests that build several `Chat`s in one process must reset it (add it to whatever resets `StatusLineCommand::configure()` state between launches).
8. **`Ctrl+,` portability.** Without kitty keyboard reporting, many terminals cannot send `Ctrl+,`. The palette row, `/settings` and the menu row are the dependable doors; the README must not imply `Ctrl+,` is universal.
9. **Model-visible notices.** Resist emitting "setting changed" transcript rows. They would be sent to the provider every turn (baseline §3.1). Use toasts and the editor footer.
10. **Open question — model persistence.** `docs/SETTINGS.md` explicitly says no model is persisted. Adding `models{provider: id}` changes that contract, so confirm it with the maintainer. The alternative is to keep the model in the provider's own `config.dev.json` entry and offer only a session-tier model override.
11. **Open question — `~/.sugar-crush/settings.json` vs `config.json`.** This design writes `config.json` to keep the "settings.json is never written" invariant and the CLI-written-wins ordering. If the maintainer prefers `settings.json` as the editor's file, the precedence of the two must flip, which is a documented, test-pinned decision (`ReadmeRosterDriftTest:321`).

---

## Appendix A — Inventory of facts used

- `src/Config/LayeredSettings.php`:
  - `LAYERED_KEYS` (16): provider, theme, titleModel, summaryModel, instructions, disabledSkills, disabledRules, parallelToolCalls, parallelToolDeadlineSeconds, maxOutputTokens, modelPrices, allowedTools, disabledTools, statusLine, layout, maxToolSteps;
  - `PROJECT_TIER_KEYS` (7): theme, titleModel, summaryModel, disabledSkills, parallelToolCalls, parallelToolDeadlineSeconds, disabledTools;
  - `merge()` = `array_merge($project, $userSettings, $userConfig)`.
- `src/Cli/Bootstrap.php`:
  - `readUserConfig` `:3376`, `mergedConfig` `:3393`, `rawUserConfig` `:3457`, `writeUserConfig` `:3608`;
  - `permissionGate` `:4807`, `permissionConfig` `:4923`, `permissionConfigLayers` `:4972`;
  - `claudeMcpGrant` `:6114`, `filterToolSet` `:6995`, `forcedInstructions` `:7281`;
  - `titleBackend` `:7711`, `summaryBackend` `:7745`;
  - `resolvedMaxToolSteps` `:2945`, `withResolvedMaxToolSteps` `:2965`, `availableProviders` `:2992`;
  - `onConfigChange` wiring `:1215`, theme default `'dark'` `:1214`, `DEFAULT_PERMISSION_MODE` `:166`.
- `src/Backend/EngineBackend.php`:
  - `COMPLETE_TIMEOUT_SECONDS` `:99`, `maxSteps = 8` `:262`;
  - `withTools` `:377`, `withPermissionGate` `:467`, `withMaxSteps` `:603`;
  - per-turn config read `:782-790`, `userConfig()` `:1135`.
- `src/Chat.php`:
  - ctor `compactorConfig` `:634`, `onConfigChange` `:677`, `ContextCompactor` `:1375`;
  - `mouseMode` `:4945`, `programOptions` `:6185`, `withBackend` `:6218`;
  - mid-turn slash refusal `:7173-7182`, overlay refusal rule `:7618-7660`, `releaseQueuedPrompts` `:7778`;
  - `dispatchCommand` `:8240`, `runRootPaletteAction` `:14038`, `selectPaletteProvider` `:14106`, `selectPaletteTheme` `:14149`;
  - other constants `:317-570`.
- `src/Tui/Components/SettingsPane.php` (read-only, 8 rows); `src/Tui/Pane.php`; `src/Tui/KeyboardHandler.php:297,850`; `src/Tui/Renderer.php:498,662,1317`; `src/Renderer.php:1440-1530`; `src/Commands/KeyBindingRegistry.php:55-71,498`; `src/Commands/CommandRegistry.php`; `src/Palette/PaletteAction.php`; `src/Tui/Components/MenuBar.php:77`.
- Libraries:
  - `candy-forms/src/{Field.php,Form.php,Group.php,Field/*.php,HasReadonly.php,HasDynamicLabels.php,Theme.php,KeyMap.php}`; `candy-forms/README.md` ("Pre-filling fields … handy for 'edit settings' screens");
  - `candy-core/src/Util/AtomicJsonFile.php`, `candy-core/src/Cmd.php:379-394`, `candy-core/src/I18n/`;
  - `candy-mouse/src/{Mark,Scanner,ZoneClickTracker}.php`, `candy-focus/src/FocusRing.php`, `sugar-veil`, `candy-kit/src/{StatusLine,Section}.php`;
  - `sugar-diff/src/Diff.php`, `sugar-toast/src/Toast.php`, `sugar-bits/src/Tabs/Tabs.php` (candy-zone), `sugar-dash/composer.json` (requires candy-pty), `sugar-prompt/src/Form.php:10` (`class_alias`).
- Competitor knobs: reports 01 §13 (P0-2 steering, `includeGitInstructions`, attribution, `maxSteps`→50), 02 (`maxToolSteps`, doom loop, notifications), 03 (`contextPruning.*`, `SUGARCRUSH_CONTEXT_PRUNING`), 04 (`DOOM_LOOP_THRESHOLD`, `readOnlyBash`, `fuzzyThreshold`), 05 (`planModel`), 06 (doom-loop + raise steps, steering), 07 (`subagentModel`, `maxConcurrent`, mid-turn compaction 90%, checkpoints), 08 (`bashTimeoutSeconds` 300, `SUGARCRUSH_BELL`, `TURN_NOTE`), 09 (`lintCommands`, `autoCommit`, `testCommand`/`autoTest`, `editorModel`, `watchFiles`, notifications), 10 (`maxToolResultChars` 16000, `toolTimeout` 30 s, `fallbackModels`, concurrency cap), 11 (sub-agent caps 8/5), 12 (`repeatToolThresholds`, `maxInlineTokens`).
