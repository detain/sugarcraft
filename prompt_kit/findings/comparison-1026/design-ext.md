# Design: EXT area (skills, commands, hooks, plugins)

Items: C-EXT-1, C-EXT-2, C-EXT-3, C-EXT-4, C-EXT-5, C-EXT-6 (LATE), C-EXT-7. Every path below was checked against the live tree on 2026-10-26: each MODIFY path exists and each CREATE path does not. Paths are repo-root-relative.

## Summary

| step id | title | effort | serialized tags | deps |
|---|---|---|---|---|
| C-EXT-1.a | CC hook entries: accept `statusMessage`/`async`, Claude exit-code dialect | M | [docs:HOOKS.md] [lang:hooks.notice.] | none |
| C-EXT-1.b | Skip-and-record unsupported CC entries, file-qualified names, `~/.claude/settings.json` in policy freshness | M | [bootstrap:hookFileEntries,checkPolicyFreshness,reportSkippedClaudeSettingsEvents] [docs:HOOKS.md] | C-EXT-1.a |
| C-EXT-7.a | `/hooks` reads the live chain; bundle decline notices see Claude manifest keys | S | [docs:PLUGINS.md] [lang:cmd.hooks.] | none |
| C-EXT-7.b | `bundles install`: refuse dot names, strip `.git` after clone | S | [headless] [docs:PLUGINS.md] [lang:cli.bundles.install.] | C-SEC-1 |
| C-EXT-2.a | Skill resources: `asset` loads, resource listing, `${CLAUDE_SKILL_DIR}` | M | [docs:SKILLS.md] | none |
| C-EXT-2.b | Honour skill `allowed-tools`/`disallowed-tools`/`model`/`effort` for the active turn | M | [runtime:gate,run step loop] [chat:gateToolCall] [docs:SKILLS.md] [docs:README.md] | none (ruling R-EXT-1) |
| C-EXT-2.c | Forked skills (`context: fork`) via the turn-bound Task tool | M | [docs:SKILLS.md] [docs:README.md] [lang:tui.app.] | C-EXT-2.a, C-EXT-2.b |
| C-EXT-2.d | `/skills enable\|disable` + picker toggle through SkillManager | M | [docs:SKILLS.md] [docs:SETTINGS.md] [docs:COMMANDS.md] [lang:host.skills.,cmd.skills.,tui.app.] | none |
| C-EXT-2.e | Command frontmatter `model`/`agent`/`subtask` | M | [chat:submit custom-command branch] [docs:COMMANDS.md] [lang:host.turn.] | C-EXT-2.b |
| C-EXT-3.a | `/skills reload` delivered as a turn-context roster delta | M | [runtime:turnContext] [docs:SKILLS.md] [docs:COMMANDS.md] [lang:host.skills.reload.] | none |
| C-EXT-3.b | `skills_search` tool + an honest listing-overflow line | M | [catalog] [docs:README.md,ARCHITECTURE.md,AGENTS_AUTHORING.md,PERMISSIONS.md,SETTINGS.md (gen-tool-docs)] [docs:SKILLS.md] | none |
| C-EXT-3.c | Bundled `skill-creator` + `/skills new <name>` scaffold | S | [docs:SKILLS.md] [docs:README.md] [docs:COMMANDS.md] [lang:host.skills.new.] | C-EXT-2.b, C-EXT-2.c |
| C-EXT-3.d | `/agents new` drafts, `/agents accept` promotes | M | [chat:handleAgentsCommand] [commands:0800-agents.php] [docs:AGENTS_AUTHORING.md] [docs:COMMANDS.md] [lang:host.agents.new.,cmd.agents.] | none |
| C-EXT-3.e | Self-learning skills: SkillWorkshop tool + versioned drafts + history/undo | M | [catalog] [docs:gen-tool-docs pages] [docs:SKILLS.md] [lang:host.skills.history.] | C-EXT-3.c (ruling R-EXT-2) |
| C-EXT-5.a | PaletteCatalog core (action + prefill rows) | S | none | none |
| C-EXT-5.b | Palette shows every slash command, file command and MCP prompt | M | [chat:palette — paletteItemLabels, runPaletteAction, runRootPaletteAction] [docs:COMMANDS.md] [lang:palette.] | C-EXT-5.a |
| C-EXT-5.c | Key-bindable slash commands (`command:<name>`) | M | [renderer:renderKeyHelp] [docs:SETTINGS.md] [lang:keys.command.] | none |
| C-EXT-4.a | Marketplace core: manifest reader, registry, provenance, search, recommender | M | none | none |
| C-EXT-4.b | `bundles marketplace add/list/remove/update`, `install name@mkt`, `update`, `search`, `recommend` | M | [headless] [docs:PLUGINS.md] [docs:README.md] [lang:cli.bundles.] | C-EXT-4.a, C-EXT-7.b, C-SEC-1, C-SEC-3 |
| C-EXT-4.c | `/<bundle>:<cmd>` namespacing | S | [docs:PLUGINS.md] [docs:COMMANDS.md] | C-SEC-3 |
| C-EXT-4.d | Contextual bundle recommendations at launch | S | [bootstrap:chat() launch drain, reportBundleRecommendations (new), launch-cap census map] [lang:bundles.recommend.] | C-EXT-4.a, C-EXT-4.b |
| C-EXT-6.a | Hook `systemMessage` shown as a notice | S | [docs:HOOKS.md] [lang:hooks.notice.] | C-EXT-1.a |
| C-EXT-6.b | Hook log file + `/hooks log` (LATE) | M | [commands:1390-hooks.php] [docs:HOOKS.md] [docs:COMMANDS.md] [lang:cmd.hooks.log.] | C-EXT-7.a |
| C-EXT-6.c | `type: http` hooks (LATE) | M | [docs:HOOKS.md] [lang:hooks.http.] | C-EXT-1.b |
| C-EXT-6.d | `type: prompt` hooks (LATE) | M | [bootstrap:hooks] [docs:HOOKS.md] [lang:hooks.prompt.] | C-EXT-6.c |

---

### C-EXT-1.a — CC hook entries: accept `statusMessage`/`async`, Claude exit-code dialect
- item: C-EXT-1 (sources: INT-4-P1, GAP-HK-07, k2-sum HK-07 ✅→🟡)
- kind: fix · MAJOR · effort M
- approach:
  - **Root cause.** `candy-code/src/Hooks/ClaudeSettingsHooks.php:98` has `ENTRY_KEYS = ['type','command','timeout']`, and `:247-255` refuses the whole launch on any other key. Claude Code writes `statusMessage` and `async` routinely. Separately, `ScriptHook.php:782-811` maps every exit code except 0/3/4 to DENY, so a CC hook that exits 1 blocks the call where Claude Code proceeds.
  - **Fix.**
    - The parser accepts `statusMessage`, mapped onto the native `description`, which `/hooks` shows.
    - The parser accepts `async: true`. candy-code has no detached hook runner, so the entry runs inline within its timeout and its verdict is advisory: never a block.
    - After `HookConfig::parse()`, the parser stamps each CC row with `dialect: 'claude'` and `advisory: bool`. Native `hooks.yaml` cannot spell either key, because `HookConfig::ENTRY_KEYS` refuses them.
    - `ScriptHook::fromConfig()` reads both flags. Under the claude dialect, 0 allows or reads the envelope and 2 blocks. Any other code becomes allow plus a human notice through `RuntimeNoticeSink::record()`, which crosses forks. Advisory rows downgrade every non-allow the same way.
    - The notice helper is a small private method that C-EXT-6.a reuses.
  - Unknown types and other keys still refuse in this step; C-EXT-1.b turns them into skip-and-record. That keeps every commit from skipping anything silently.
- files.create: none
- files.modify: `candy-code/src/Hooks/ClaudeSettingsHooks.php` (ENTRY_KEYS, `parse()` row build, post-parse dialect stamp), `candy-code/src/Hooks/ScriptHook.php` (constructor flags, `fromConfig()`, the exit `match` at :782), `candy-code/lang/en.php` (`hooks.notice.*`), `candy-code/docs/HOOKS.md` ("What is read from a Claude settings file", "The exit-code contract")
- tests: create `candy-code/tests/Hooks/ScriptHookClaudeDialectTest.php` (exit 1 and exit 5 allow with a notice; exit 2 denies; an advisory row never denies; native rows unchanged). Modify `candy-code/tests/Hooks/ClaudeSettingsHooksTest.php` (`statusMessage` becomes the description; `async` sets advisory; the native validator is still the one used).
- docs/guards: `docs/HOOKS.md`. Check whether `DocFigureProseDriftTest`'s HOOKS.md entry-key pin covers the CC bullets, and move it if so. No child-lifetime change.
- serialized: [docs:HOOKS.md] [lang:hooks.notice.]
- deps: none
- phase: normal
- ruling: none
- risks: on serve the exit-1 notice shows only after C-CTX-2's notice drain lands.

### C-EXT-1.b — Skip-and-record unsupported CC entries, file-qualified names, `~/.claude/settings.json` in policy freshness
- item: C-EXT-1 (sources: INT-4-P1, INT-4-P2, INT-4-P3, GAP-HK-07, p1-miss: CC hook `type: prompt|agent`)
- kind: fix · MAJOR · effort M
- approach:
  - **Root causes.**
    - `ClaudeSettingsHooks.php:257-265` refuses the launch for any non-`command` type.
    - `:269` names rows `cc:{event}:{index}` with a per-file index, so a user `PreToolUse` plus a trusted project `PreToolUse` collide. `HookManager.php:156-160` throws on that, and `tests/Cli/BootstrapHookFileTest.php:866-878` pins the refusal.
    - `Bootstrap::checkPolicyFreshness()` (`Bootstrap.php:4391-4460`) fingerprints only `config.json` and `hooks.yaml` (INT-4-P2).
    - `docs/HOOKS.md:59-74` still claims refusals the code no longer makes (INT-4-P3).
  - **Fix.**
    - `prompt`, `agent`, `http` and `mcp_tool` types, plus unknown entry keys (`if`, `once`, …), are skipped and recorded in a `skippedEntries()` accumulator holding type or key, event and file.
    - `reportSkippedClaudeSettingsEvents()` widens its single aggregate row to name skipped events, types and keys. The launch-cap census entry stays at 1; the format constant changes.
    - `fromFile($path, string $scope)` names rows `cc:{user|project|local}:{event}:{index}`. `local` comes from `settings.local.json`. `hookFileEntries()` passes `user` for `userClaudeSettingsFile()`.
    - The policy snapshot gains a `userClaudeSettings` fingerprint that feeds `$userChanged`. `PolicySnapshot::VERSION` goes 1→2; a version mismatch reads as null, so the one-time name churn quarantines nothing.
- files.create: none
- files.modify: `candy-code/src/Hooks/ClaudeSettingsHooks.php` (type and key skip, accumulator, scope naming), `candy-code/src/Cli/Bootstrap.php` (`hookFileEntries`, `checkPolicyFreshness`, `reportSkippedClaudeSettingsEvents`, `CLAUDE_SETTINGS_SKIPPED_EVENTS_NOTICE_FORMAT`), `candy-code/src/Config/PolicySnapshot.php` (VERSION, record key), `candy-code/docs/HOOKS.md` (naming paragraph, type/key bullets, the Notification/unknown-event bullet)
- tests: create none. Modify:
  - `candy-code/tests/Cli/BootstrapHookFileTest.php`: flip :866 so both rows register; add the `settings.json` + `settings.local.json` pair and a name-format pin.
  - `candy-code/tests/Hooks/ClaudeSettingsHooksTest.php`
  - `candy-code/tests/Cli/ClaudeSettingsHooksSkipRecordTest.php`
  - `candy-code/tests/Cli/BootstrapLaunchFormatConstantsTest.php`
  - `candy-code/tests/Cli/PolicyFreshnessQuarantineTest.php`: an edit to `~/.claude/settings.json` raises the drift notice.
  - `candy-code/tests/Config/PolicySnapshotTest.php`
  - `candy-code/tests/Config/DocFigureProseDriftTest.php`: the INT-4-P3 prose pin.
- docs/guards: HOOKS.md. `BootstrapLaunchNoticeCapCensusTest` must stay at 1 for this drain, and the step asserts it unchanged.
- serialized: [bootstrap:hookFileEntries,checkPolicyFreshness,reportSkippedClaudeSettingsEvents] [docs:HOOKS.md]
- deps: C-EXT-1.a
- phase: normal
- ruling: none
- risks: the VERSION bump silently resets the snapshot once, which is intended. Any user script that greps `cc:PreToolUse:0` breaks.

### C-EXT-7.a — `/hooks` reads the live chain; bundle decline notices see Claude manifest keys
- item: C-EXT-7 (sources: VIS-3-P2, PLG-1-P4; VIS-4-P1 is closed in C-EXT-5.b)
- kind: fix · MINOR/NIT · effort S
- approach:
  - **VIS-3-P2.** `HooksHostCommand.php:30` calls `Bootstrap::hooks()`, which re-runs `installSecretEnvAllowlist` (:5830), `lspClient` (:5869) and `TeamManager::useLaunchHooks` (:5983). That makes the "read-only" `/hooks` command a launch-chain rebuild with process-global side effects.
    - Fix: inspect `$context->workspace?->hooks`, the launch `HookManager` built in `Bootstrap::workspace()` and reachable from both the TUI and SessionHost CommandContext.
    - A null workspace reports "not measured: no launch hook chain" and rebuilds nothing.
  - **PLG-1-P4.** `Bundle.php:173-187` `mcpFile()` reads only the `mcp` key, and `declared()` (:235-257) drops non-string values. A Claude `plugin.json` with an inline `hooks` object or `mcpServers` therefore loads with no decline notice.
    - Fix: `hooksPaths()` includes the manifest path when `hooks` is a non-empty non-path value.
    - `mcpFile()` reads `mcpServers`. A string is a path. An inline object returns the manifest path, which is where the servers are declared.
    - The Bootstrap notices at :2296-2318 then fire unchanged.
- files.create: none
- files.modify: `candy-code/src/Host/Commands/HooksHostCommand.php` (`run`), `candy-code/src/Bundles/Bundle.php` (`mcpFile`, `hooksPaths`, docblock), `candy-code/lang/en.php` (`cmd.hooks.*` one key), `candy-code/docs/PLUGINS.md` ("The two declined surfaces")
- tests: create `candy-code/tests/Host/Commands/HooksHostCommandLiveChainTest.php` (reads the workspace manager; `Bootstrap::hooks()` is never called; null workspace gives "not measured"). Modify `candy-code/tests/Bundles/BundleDeclinedSurfacesTest.php` (an inline `hooks` object and a `mcpServers` string or object are both declared).
- docs/guards: PLUGINS.md only.
- serialized: [docs:PLUGINS.md] [lang:cmd.hooks.]
- deps: none
- phase: normal
- ruling: none
- risks: an embedder with no workspace loses the `/hooks` table. That is intended: no rebuild.

### C-EXT-7.b — `bundles install`: refuse dot names, strip `.git` after clone
- item: C-EXT-7 (sources: PLG-1-P5)
- kind: fix · NIT · effort S
- approach: `Subcommands.php:1692` accepts a leading-dot manifest name, which `BundleDiscovery::scanContainer`'s glob never finds, so the bundle installs invisibly. The git arm keeps `.git` while the path arm strips it (docblock :1606-1612). Fix:
  - refuse names that start with `.`;
  - after a successful clone and manifest validation, remove `<scratch>/.git` with C-SEC-1's no-follow tree removal before the rename;
  - update the docblock.
- files.create: none
- files.modify: `candy-code/src/Cli/Subcommands.php` (`bundlesInstall`), `candy-code/lang/en.php` (`cli.bundles.install.*`), `candy-code/docs/PLUGINS.md` ("Installing and listing")
- tests: modify `candy-code/tests/Bundles/BundleInstallTest.php` (dot-name refusal; a cloned install has no `.git`).
- docs/guards: PLUGINS.md. No new spawn site.
- serialized: [headless] [docs:PLUGINS.md] [lang:cli.bundles.install.]
- deps: C-SEC-1 (same `bundlesInstall`/`copyBundleTree` region, and the removal must be the hardened one)
- phase: normal
- ruling: none
- risks: a manifest-less dot-named directory installed earlier stays invisible; this step only stops new ones.

### C-EXT-2.a — Skill resources: `asset` loads, resource listing, `${CLAUDE_SKILL_DIR}`
- item: C-EXT-2 (sources: GAP-SKL-10, k2-dorm `loadSkillAsset`)
- kind: dormant-wire · effort M
- approach:
  - **What's dormant.** `SkillLoader::loadSkillAsset()` (`SkillLoader.php:1054`, containment-checked through `ContainedPath::within`) has no caller. Read is jailed to the project root, so a user-tier skill's `scripts/` and `references/` are unreachable except by Bash.
  - **Fix.**
    - The Skill tool gains an optional `asset` argument: a relative path under `scripts/`, `references/` or `assets/`. The tool returns that file through `loadSkillAsset()` instead of the body, under the same two header lines.
    - Body results list up to 10 sampled resource paths, the OpenCode behaviour.
    - `${CLAUDE_SKILL_DIR}` and `${SKILL_DIR}` in a body become the base dir.
    - `SkillTool::invokedIn()` skips `asset` calls, so ReinjectionPlan never re-injects an asset as a body.
- files.create: none
- files.modify: `candy-code/src/Tools/BuiltIn/SkillTool.php` (`description`, `inputSchema`, `execute`, `invokedIn`), `candy-code/src/Skills/SkillLoader.php` (a resource-listing helper beside `loadSkillAsset`), `candy-code/docs/SKILLS.md` (the Skill tool and resources section)
- tests: create `candy-code/tests/Tools/BuiltIn/SkillToolAssetTest.php` (an asset loads; traversal is refused; outside the asset subdirs is refused; the listing is capped at 10; placeholder substitution; asset calls are excluded from `invokedIn`). Modify `candy-code/tests/Tools/BuiltIn/SkillToolTest.php` (schema), `candy-code/tests/Skills/SkillLoaderTest.php`.
- docs/guards: SKILLS.md. Re-pin any tool-schema byte figure in `tests/Providers/PromptStabilityTest.php` if the Skill schema moves it. The gen-tool-docs roster renders name and gloss only, so it does not move. Re-check `tests/Support/ReadPathCensusTest.php` now that the read path is live.
- serialized: [docs:SKILLS.md]
- deps: none
- phase: normal
- ruling: none
- risks: the Skill schema change moves the cached tool prefix once.

### C-EXT-2.b — Honour skill `allowed-tools`/`disallowed-tools`/`model`/`effort` for the active turn
- item: C-EXT-2 (sources: GAP-SKL-11, k2-dorm inert frontmatter, k1-dorm#8)
- kind: dormant-wire · effort M (upper)
- approach:
  - **What's inert.** `FrontmatterKeyAudit.php:120-135` lists four skill keys as INERT; nothing on a live path reads them. Two traps:
    - `Skill::$effort` defaults to `'medium'` when absent (`Skill.php:24`), so "declared" cannot be told from "default". Add a nullable or `effortSet` sentinel, per the house law.
    - The eight built-ins declare `allowed-tools: "Read,Grep,Bash"` and `effort: high`.
  - **Design.** Create `ActiveSkillEffects`. It is pure and derived from the turn's own messages since the last real user prompt: skills loaded by successful `Skill` calls (`SkillTool::invokedIn`) and by `$name` mentions (`TurnContextBlock::invokedSkillsIn`, :467), resolved through the registry. It yields:
    - allow rules (CC grammar through `PermissionRule::fromClaudePrefixDialect`, :1305);
    - deny rules;
    - the latest declared model;
    - the latest declared effort.
  - **Gate.** `PermissionGate::withTurnGrants($allow, $deny)` returns a clone. Deny rules refuse before the mode, like a configured deny. Allow rules only answer an Ask, with the session-rule semantics of `withSessionRules()` at :389.
  - **Wiring.**
    - `Runtime::gate()` (:3136) evaluates through a gate carrying the turn's effects, using the PermissionGateHook swap idiom that mode switches use.
    - The `Runtime::run()` step loop applies the effects' model and effort to the next request.
    - `Chat::gateToolCall()` (:4664) applies the grants on the TUI-side path.
    - The four SKILL rows leave INERT.
  - **Stateless on purpose.** Skill and gate calls can run in forked children, so in-memory turn state would be lost.
- files.create: `candy-code/src/Skills/ActiveSkillEffects.php`
- files.modify: `candy-code/src/Permissions/PermissionGate.php` (`withTurnGrants`, evaluate order), `candy-code/src/Runtime.php` (`gate`, the `run` step loop), `candy-code/src/Chat.php` (`gateToolCall`), `candy-code/src/Skills/Skill.php` + `candy-code/src/Skills/SkillFrontmatter.php` (declared-effort sentinel), `candy-code/src/Support/FrontmatterKeyAudit.php` (drop four SKILL INERT rows; rewrite the docblock), `candy-code/docs/SKILLS.md` (inert rows :265-268, diagnostics :644), `candy-code/README.md` (:2370 inert sentence), `candy-code/src/Skills/BuiltIn/api-design/SKILL.md`, `candy-code/src/Skills/BuiltIn/composer-wizard/SKILL.md`, `candy-code/src/Skills/BuiltIn/laravel-best-practices/SKILL.md`, `candy-code/src/Skills/BuiltIn/php-best-practices/SKILL.md`, `candy-code/src/Skills/BuiltIn/phpunit-master/SKILL.md`, `candy-code/src/Skills/BuiltIn/security-audit/SKILL.md`, `candy-code/src/Skills/BuiltIn/symfony-best-practices/SKILL.md`, `candy-code/src/Skills/BuiltIn/testing-strategies/SKILL.md` (owner ruling: trim each built-in's `allowed-tools`/`effort` to what it truly needs; no Bash pre-approval unless the skill runs shell)
- tests: create `candy-code/tests/Skills/ActiveSkillEffectsTest.php`, `candy-code/tests/Permissions/PermissionGateTurnGrantsTest.php` (deny wins; allow answers only an Ask; never lifts a configured deny), `candy-code/tests/Runtime/RuntimeSkillEffectsTest.php` (a grant from step 1 applies at step 2; the model and effort switch from the next step; a new user prompt clears them). Modify `candy-code/tests/Support/FrontmatterKeyAuditTest.php`, `candy-code/tests/Config/InertFrontmatterDocumentationDriftTest.php`, `candy-code/tests/Skills/BuiltInSkillsTest.php` (effort default/sentinel), `candy-code/tests/Skills/SkillFrontmatterTest.php`.
- docs/guards: the INERT ↔ SKILLS.md guard moves in-step. README :2370.
- serialized: [runtime:gate,run step loop] [chat:gateToolCall] [docs:SKILLS.md] [docs:README.md] [hot:candy-code/src/Permissions/PermissionGate.php] [hot:candy-code/src/Support/FrontmatterKeyAudit.php]
- deps: none
- phase: normal
- ruling: R-EXT-1. Does `allowed-tools` pre-approval apply only to user-tier skills and only to argument-scoped rules (never bare `Bash`/`Write`), with project, bundle and foreign tiers able only to narrow? And are the built-ins' `allowed-tools: Read,Grep,Bash` / `effort: high` stripped?
- risks: without R-EXT-1, a model can load a repo-supplied skill to pre-approve Bash: a permission escalation. Do not run this step before the ruling.

### C-EXT-2.c — Forked skills (`context: fork`) via the turn-bound Task tool
- item: C-EXT-2 (sources: GAP-SKL-15, k2-dorm `dispatchSkill`, k1-dorm#8)
- kind: dormant-wire · effort M
- approach:
  - **What's dormant.** `App::dispatchSkill()` (`App.php:2528`) has no production caller; it is a single-shot pool run. `SkillTool::execute()` loads every skill inline.
  - **SkillTool fork path.**
    - SkillTool gains `withForkDelegate(TaskTool)`. `EngineBackend::turnTools()` (:3148) binds every SkillTool to the turn's BOUND Task copy after its loop, so the engine, permission relay, emitter and spend all ride the Task path.
    - For an `isContextFork()` skill, SkillTool calls a new non-schema `TaskTool::runForkedSkill()`. That method reuses `runOnEngine` with the skill's `agent` (default roster preset `coder`), its model and effort, and the body plus args as the prompt.
    - SkillTool becomes `ExemptFromParallelDeadline`.
    - With no delegate bound, the skill loads inline with a one-line note.
  - **Mentions.** A `$name` or `/name` mention of a fork skill attaches a directive to invoke it through the Skill tool, not the inline body.
  - **Skill frontmatter.** `agent` joins KNOWN for skills; `context` leaves INERT.
  - **`App::dispatchSkill()` consolidation.** Its Agent construction (env block at root, skill model) moves into a shared `SkillFork`, and `dispatchSkill()` delegates to it. It stays an embedder API; nothing is deleted.
  - **Status text.** The App picker's `tui.app.skill_fork` status is reworded to say the skill runs forked when invoked.
- files.create: `candy-code/src/Skills/SkillFork.php`
- files.modify: `candy-code/src/Tools/BuiltIn/SkillTool.php`, `candy-code/src/Tools/BuiltIn/TaskTool.php` (`runForkedSkill`), `candy-code/src/Backend/EngineBackend.php` (`turnTools` post-pass), `candy-code/src/App/App.php` (`dispatchSkill`, `handleSelectSkill` status), `candy-code/src/Skills/SkillMentions.php`, `candy-code/src/Skills/Skill.php` + `candy-code/src/Skills/SkillFrontmatter.php` (`agent`), `candy-code/src/Support/FrontmatterKeyAudit.php` (KNOWN skill `agent`; drop INERT `context`), `candy-code/lang/en.php` (`tui.app.skill_fork`), `candy-code/docs/SKILLS.md` ("The context field today", row :271), `candy-code/README.md` (:2370 "no fork executor")
- tests: create `candy-code/tests/Tools/BuiltIn/SkillToolForkTest.php` (the fork runs on the bound Task; model and effort forwarded; an unbound tool falls back inline), `candy-code/tests/Skills/SkillForkTest.php`. Modify `candy-code/tests/App/AppSkillDispatchTest.php` (delegation stays green), `candy-code/tests/Skills/SkillMentionTest.php`, `candy-code/tests/Support/FrontmatterKeyAuditTest.php`, `candy-code/tests/Tools/TaskToolEngineTest.php`.
- docs/guards: the INERT ↔ docs guard. The Skill tool description changes, but gen-tool-docs renders name and gloss only, so it does not move.
- serialized: [docs:SKILLS.md] [docs:README.md] [lang:tui.app.] [hot:candy-code/src/Backend/EngineBackend.php] [hot:candy-code/src/Tools/BuiltIn/TaskTool.php] [hot:candy-code/src/App/App.php] [hot:candy-code/src/Support/FrontmatterKeyAudit.php]
- deps: C-EXT-2.a (SkillTool), C-EXT-2.b (model/effort semantics, FrontmatterKeyAudit)
- phase: normal
- ruling: none
- risks: an unbound TaskTool refuses. The test must use the copy `turnTools()` binds, not the launch instance.

### C-EXT-2.d — `/skills enable|disable` + picker toggle through SkillManager
- item: C-EXT-2 (sources: k2-dorm SkillManager accessors)
- kind: dormant-wire · effort M
- approach: `SkillManager::enable()`/`disable()` (`SkillManager.php:292-320`) have no caller. `App::handleSelectSkill()` (`App.php:4735-4785`) re-implements enable inline and can never disable. Changes:
  - `/skills disable <name>` and `/skills enable <name>` write `disabledSkills` (user tier) through the workspace `SettingsWriter`, the RenderModeHostCommand idiom.
  - The same commands flip the live registry (`$context->workspace->skills` → `SkillRegistry::disable/enable`), so the Skill tool and `$name` mentions honour the change at once. The frozen listing catches up next session, or through C-EXT-3.a's delta.
  - The App picker routes through `SkillManager::enable()`, and re-selecting an enabled skill calls `SkillManager::disable()`.
  - `applyToApp()` stays an embedder API and is not deleted.
  - Serve gets the change free through `SkillsHostCommand`.
- files.create: none
- files.modify: `candy-code/src/Host/Commands/SkillsHostCommand.php` (`run` verbs), `candy-code/src/App/App.php` (`handleSelectSkill`), `candy-code/lang/en.php` (`host.skills.*`, `cmd.skills.hint`, `tui.app.*`), `candy-code/docs/SKILLS.md`, `candy-code/docs/SETTINGS.md` ("Saving from the settings view": a new writer door), `candy-code/docs/COMMANDS.md` (regen through `tools/gen-command-docs.php`)
- tests: create `candy-code/tests/Host/Commands/SkillsEnableDisableTest.php` (persisted plus live; an unknown name is refused). Modify `candy-code/tests/Host/Commands/SkillsHostCommandTest.php`, `candy-code/tests/App/AppSkillDispatchTest.php` (toggle off), `candy-code/tests/Config/Settings/SettingsWriterCensusTest.php` (the new `write()` caller).
- docs/guards: SettingsWriterCensusTest and SETTINGS.md move in-step; gen-command-docs.
- serialized: [docs:SKILLS.md] [docs:SETTINGS.md] [docs:COMMANDS.md] [lang:host.skills.,cmd.skills.,tui.app.] [hot:candy-code/src/Host/Commands/SkillsHostCommand.php] [hot:candy-code/src/App/App.php]
- deps: none
- phase: normal
- ruling: none
- risks: one launch's registry is shared by its forked children, so a disable mid-turn takes effect from the next turn.

### C-EXT-2.e — Command frontmatter `model`/`agent`/`subtask`
- item: C-EXT-2 (sources: GAP-CMD-04, k2-dorm inert command keys)
- kind: dormant-wire · effort M
- approach:
  - **What's inert.** `CommandSpec.php:205-210` parses `model` and `subtask`, both INERT (`FrontmatterKeyAudit.php:131-134`). `agent` is KNOWN but not parsed. `TurnController::expandCustomCommand()` (:448) expands into the current conversation on the current model.
  - **Fix.**
    - CommandSpec gains `agent`.
    - When `subtask: true` or `agent:` is set, `expandCustomCommand()` wraps the expansion in a fenced delegation directive: Task, with `agent` (named, else `coder`) and the command `model`. That is one implementation for the TUI, serve and `-p`.
    - An inline `model:` without subtask runs that ONE turn on `backend->withModel()`. A model the provider cannot serve falls back to the session model with a notice, the Task rule. This happens in Chat's custom-command submit branch (Chat.php ~9520-9560) and in `SessionHost::admitExpanded()` (:942).
    - The COMMAND `model` and `subtask` rows leave INERT.
- files.create: none
- files.modify: `candy-code/src/Commands/CommandSpec.php` (`agent`), `candy-code/src/Host/TurnController.php` (`expandCustomCommand`), `candy-code/src/Chat.php` (submit custom-command branch), `candy-code/src/Host/SessionHost.php` (`admitExpanded`), `candy-code/src/Support/FrontmatterKeyAudit.php` (COMMAND rows), `candy-code/lang/en.php` (`host.turn.*`), `candy-code/docs/COMMANDS.md` (frontmatter rows :120-121 and the KNOWN-not-INERT paragraph)
- tests: create `candy-code/tests/Host/TurnControllerCommandRoutingTest.php` (directive shape per key), `candy-code/tests/Chat/CustomCommandModelOverrideTest.php` (one turn only; restored after). Modify `candy-code/tests/Host/SessionHostCommandsTest.php`, `candy-code/tests/Commands/CommandLoaderTest.php` (agent parse), `candy-code/tests/Support/FrontmatterKeyAuditTest.php`, `candy-code/tests/Config/InertFrontmatterDocumentationDriftTest.php`.
- docs/guards: the INERT ↔ COMMANDS.md guard.
- serialized: [chat:submit custom-command branch] [docs:COMMANDS.md] [lang:host.turn.] [hot:candy-code/src/Host/SessionHost.php] [hot:candy-code/src/Host/TurnController.php] [hot:candy-code/src/Support/FrontmatterKeyAudit.php]
- deps: C-EXT-2.b (FrontmatterKeyAudit and InertFrontmatter guard ordering)
- phase: normal
- ruling: none
- risks: a subtask relies on the model calling Task when told to. The directive is pinned, but compliance is not guaranteed.

### C-EXT-3.a — `/skills reload` delivered as a turn-context roster delta
- item: C-EXT-3 (sources: GAP-SKL-13, OC-new#6)
- kind: gap · effort M
- approach:
  - **Problem.** The roster is read once per launch, and the listing sits in the frozen system prompt.
  - **Core.**
    - `SkillRegistry::replaceAll()` swaps the skill set in place and keeps the disabled set.
    - A new `SkillRosterDiffTracker` copies `InstructionDiffTracker`'s contract. It captures the roster the frozen listing named, as name → description hash. `recheck()` renders a bounded delta (added, with a `SkillPromptLine` line; removed; changed).
    - TurnContextBlock gains `withSkillRosterDiff()`.
  - **Wiring.**
    - `Runtime::turnContext()` (:5710) adds that section, memoised per session the way `instructionDiff()` (:5742) is. The delta rides the tail row only, so the prefix bytes never move.
    - `/skills reload` re-runs `SkillManager::loadAll($root, Bootstrap::bundles($root))` into a fresh registry and `replaceAll()`s the live `$context->workspace->skills`. The reply counts added and removed skills. Serve gets the same per workspace.
    - TUI forks inherit the reload from the next turn.
- files.create: `candy-code/src/Skills/SkillRosterDiffTracker.php`
- files.modify: `candy-code/src/Skills/SkillRegistry.php` (`replaceAll`), `candy-code/src/Context/TurnContextBlock.php` (section and `render`), `candy-code/src/Runtime.php` (`turnContext`, new `skillRosterDiff`), `candy-code/src/Host/Commands/SkillsHostCommand.php` (`reload`), `candy-code/lang/en.php` (`host.skills.reload.*`, `cmd.skills.hint`), `candy-code/docs/SKILLS.md`, `candy-code/docs/COMMANDS.md` (gen)
- tests: create `candy-code/tests/Skills/SkillRosterDiffTrackerTest.php`, `candy-code/tests/Host/Commands/SkillsReloadTest.php`. Modify `candy-code/tests/Skills/SkillRegistryTest.php`, `candy-code/tests/Context/TurnContextBlockTest.php`, `candy-code/tests/Providers/PromptPrefixByteStabilityTest.php` (assert the prefix is unchanged after a reload).
- docs/guards: SKILLS.md; gen-command-docs.
- serialized: [runtime:turnContext] [docs:SKILLS.md] [docs:COMMANDS.md] [lang:host.skills.reload.] [hot:candy-code/src/Host/Commands/SkillsHostCommand.php]
- deps: none
- phase: normal
- ruling: none
- risks: unverified that `workspace->skills` is the same object as the engine's registry (the comment at `Bootstrap.php:2373` says one registry). Thread it if not.

### C-EXT-3.b — `skills_search` tool + an honest listing-overflow line
- item: C-EXT-3 (sources: GAP-SKL-14 context, CLAW-new#2)
- kind: gap · effort M
- approach:
  - **Root cause.** `SkillMatcher.php:58` writes the overflow line `"- %d more skills; call Skill to list them"`. The Skill tool's own description (`SkillTool.php:84-99`) says it "does not itself list, search, or run skills". A listing over `skills.listingMaxBytes` therefore points the model at a tool that cannot help.
  - **Fix.** A new `SkillsSearch` tool (`skills_search`, Read class, ParallelSafe, BuildsFromCatalog over `ToolBuildContext->skills`):
    - lexical ranking over name, description, `paths` and a bounded body head;
    - model-invocable and enabled skills only;
    - results carry the top K keys with their tier badge and a coverage line ("searched N of M"), with repo text through `SkillPromptLine::field`.
  - The overflow line now names `skills_search`.
- files.create: `candy-code/src/Tools/BuiltIn/SkillsSearch.php`
- files.modify: `candy-code/src/Skills/SkillMatcher.php` (`MORE_LINE_FORMAT`, notice text), `candy-code/docs/SKILLS.md` (listing budget). gen-tool-docs output: `candy-code/README.md`, `candy-code/docs/ARCHITECTURE.md`, `candy-code/docs/AGENTS_AUTHORING.md`, `candy-code/docs/PERMISSIONS.md`, `candy-code/docs/SETTINGS.md`.
- tests: create `candy-code/tests/Tools/BuiltIn/SkillsSearchTest.php`. Modify `candy-code/tests/Skills/SkillMatcherListingTest.php`, `candy-code/tests/Tools/BuiltInToolCorpus.php` / `candy-code/tests/Tools/BuiltInToolCorpusTest.php`, `candy-code/tests/Config/DocFigureProseDriftTest.php` (the built-in tool count, if spelled).
- docs/guards: `tools/gen-tool-docs.php --write`, ReadmeRosterDriftTest, ToolDescriptionSentenceFloorTest.
- serialized: [catalog] (the next free position, which the orchestrator assigns: 41 if this is the first new tool) [docs:README.md] [docs:ARCHITECTURE.md] [docs:AGENTS_AUTHORING.md] [docs:PERMISSIONS.md] [docs:SETTINGS.md] [docs:SKILLS.md]
- deps: none
- phase: normal
- ruling: none
- risks: a new tool schema moves the cached tool prefix once.

### C-EXT-3.c — Bundled `skill-creator` + `/skills new <name>` scaffold
- item: C-EXT-3 (sources: GAP-SKL-14)
- kind: gap · effort S
- approach:
  - Ship a ninth built-in skill, `skill-creator`. It teaches the `SKILL.md` format and the KNOWN keys from `FrontmatterKeyAudit` with their now-honoured meaning (fork, `allowed-tools`, `${CLAUDE_SKILL_DIR}`). It also covers gating and tiers, and the rule that drafts go through `/skills accept`.
  - `/skills new <name> [description]` writes a scaffold draft with `ProposedSkills::propose()`, so `accept` stays the only promotion path. The reply prints the draft path.
- files.create: `candy-code/src/Skills/BuiltIn/skill-creator/SKILL.md`
- files.modify: `candy-code/src/Host/Commands/SkillsHostCommand.php` (`new`), `candy-code/lang/en.php` (`host.skills.new.*`, `cmd.skills.hint`), `candy-code/docs/SKILLS.md` (built-in table :32 "eight" → nine), `candy-code/README.md` (:2370 "Ships 8 built-ins"), `candy-code/docs/COMMANDS.md` (gen)
- tests: create `candy-code/tests/Host/Commands/SkillsNewTest.php`. Modify `candy-code/tests/Skills/BuiltInSkillsTest.php` (the ninth roster row).
- docs/guards: README and SKILLS.md built-in counts; gen-command-docs.
- serialized: [docs:SKILLS.md] [docs:README.md] [docs:COMMANDS.md] [lang:host.skills.new.] [hot:candy-code/src/Host/Commands/SkillsHostCommand.php]
- deps: C-EXT-2.b, C-EXT-2.c (the skill must teach the keys as they actually behave)
- phase: normal
- ruling: none
- risks: the built-in is listed to every project, which costs listing bytes.

### C-EXT-3.d — `/agents new` drafts, `/agents accept` promotes
- item: C-EXT-3 (sources: GAP-AGT-07)
- kind: gap · effort M
- approach:
  - **Why the GAP's plan fails.** The GAP proposes a canned prompt that Writes `.candy-code/agents/<name>.md`. `ProtectFilesHook::WRITE_ONLY_PATTERNS` (`ProtectFilesHook.php:244`) denies that path outright, because a preset's `permissionMode:` is a self-grant. The agent therefore cannot write it.
  - **Design.**
    - Create a `NewAgentPrompt`, mirroring `NewRulePrompt`. It asks the agent to draft `.candy-code/agents-proposed/<name>.md` (an ordinary, gated write), validated against `FrontmatterKeyAudit::KNOWN[AGENT]`, never overwriting, and listing the existing presets.
    - `/agents accept <name>` is a host-side, user-initiated step. It validates the frontmatter, prints the policy keys (`permissionMode`, `tools`, `mcpServers`) and moves the file into `.candy-code/agents/`.
    - `/agents proposed` lists the drafts.
  - **Routing.** In the TUI, `Chat::handleAgentsCommand()` (:12930) sends `new` to `withInputBuf(prompt)->submit()`, the `/newrule` idiom. On serve, `AgentsHostCommand` returns a SubmitPrompt effect for `new` and handles `accept`/`proposed` itself.
- files.create: `candy-code/src/Host/Commands/NewAgentPrompt.php`
- files.modify: `candy-code/src/Host/Commands/AgentsHostCommand.php`, `candy-code/src/Chat.php` (`handleAgentsCommand`), `candy-code/builtin-commands/0800-agents.php` (argumentHint), `candy-code/lang/en.php` (`host.agents.new.*`, `cmd.agents.*`), `candy-code/docs/AGENTS_AUTHORING.md`, `candy-code/docs/COMMANDS.md` (gen)
- tests: create `candy-code/tests/Host/Commands/AgentsNewAcceptTest.php` (the prompt shape; accept validates, shows policy keys and moves; refuses an existing name). Modify `candy-code/tests/Chat/PaletteSupersetDispatchTest.php` only if the `/agents` bare form changes (it should not).
- docs/guards: gen-command-docs; AGENTS_AUTHORING.md.
- serialized: [chat:handleAgentsCommand] [commands:builtin-commands/0800-agents.php] [docs:AGENTS_AUTHORING.md] [docs:COMMANDS.md] [lang:host.agents.new.,cmd.agents.] [hot:candy-code/src/Host/Commands/AgentsHostCommand.php]
- deps: none
- phase: normal
- ruling: none
- risks: collides with C-AGT-4 (`/agent use`, Tab cycle) on AgentsHostCommand and `handleAgentsCommand`. Put the two in different waves.

### C-EXT-3.e — Self-learning skills: SkillWorkshop tool + versioned drafts + history/undo
- item: C-EXT-3 (sources: CLAW-new#1, GAP-SKL-14)
- kind: gap · effort M
- approach:
  - **Tool.** A `SkillWorkshop` tool (Write class, so it is asked in default mode) lets the model create or patch a skill during a turn.
  - **Store.** Writes land in a versioned store, `LearnedSkills`. It wraps `ProposedSkills`; each revision is a `LearnedSkillRevision` kept under `history/<n>.md`, with bounded revisions and archive. Names, descriptions and bodies use `ProposedSkills`' caps and sanitizer.
  - **Commands.** `/skills history <name>` and `/skills undo <name>` are new.
  - **Background review.** The dream pass already drafts skills (`memory.dreamProposeSkills`) and stays the review leg, so no new trigger is added.
  - **Default.** Workshop output stays a draft behind `/skills accept`. R-EXT-2 decides whether it may go live with announce-and-undo, as OpenClaw does.
- files.create: `candy-code/src/Skills/LearnedSkills.php`, `candy-code/src/Skills/LearnedSkillRevision.php`, `candy-code/src/Tools/BuiltIn/SkillWorkshop.php`
- files.modify: `candy-code/src/Host/Commands/SkillsHostCommand.php` (`history`, `undo`), `candy-code/lang/en.php` (`host.skills.history.*`, `host.skills.undo.*`), `candy-code/docs/SKILLS.md`. gen-tool-docs pages as in C-EXT-3.b.
- tests: create `candy-code/tests/Skills/LearnedSkillsTest.php`, `candy-code/tests/Tools/BuiltIn/SkillWorkshopTest.php`, `candy-code/tests/Host/Commands/SkillsHistoryUndoTest.php`. Modify `candy-code/tests/Tools/BuiltInToolCorpus.php`.
- docs/guards: gen-tool-docs, ReadmeRosterDriftTest, ReadPathCensusTest (a new user-tier path).
- serialized: [catalog] [docs:README.md] [docs:ARCHITECTURE.md] [docs:AGENTS_AUTHORING.md] [docs:PERMISSIONS.md] [docs:SETTINGS.md] [docs:SKILLS.md] [lang:host.skills.history.] [hot:candy-code/src/Host/Commands/SkillsHostCommand.php]
- deps: C-EXT-3.c
- phase: normal
- ruling: R-EXT-2. May a model-written skill go live without `/skills accept` (OpenClaw's announce-and-undo), or do workshop skills stay drafts?
- risks: live learned skills persist model-authored instructions into every later session, a prompt-injection persistence vector.

### C-EXT-5.a — PaletteCatalog core (action + prefill rows)
- item: C-EXT-5 (sources: GAP-CMD-08)
- kind: gap · effort S
- approach: a pure `PaletteCatalog::rows(list<CommandSpec> $slashRows): list<PaletteEntry>`. It emits every `PaletteAction` row, with order and labels unchanged. It then adds one Prefill row per slash-visible spec that has no `paletteAction`: built-in survivors, file commands with their tier badge, and MCP prompts badged `[mcp]`. A Prefill label is `/<name> <argument-hint>`. The slash prefix means it can never collide with an action label. Rows are deduped. `PaletteEntryKind` is an enum, in its own file.
- files.create: `candy-code/src/Palette/PaletteCatalog.php`, `candy-code/src/Palette/PaletteEntry.php`, `candy-code/src/Palette/PaletteEntryKind.php`
- files.modify: none
- tests: create `candy-code/tests/Palette/PaletteCatalogTest.php`
- docs/guards: none (one type per file)
- serialized: none
- deps: none
- phase: normal
- ruling: none
- risks: none

### C-EXT-5.b — Palette shows every slash command, file command and MCP prompt
- item: C-EXT-5 + C-EXT-7 VIS-4-P1 (sources: GAP-CMD-08, VIS-4-P1)
- kind: gap · effort M
- approach:
  - **Root cause.** `Chat::paletteItemLabels()`'s default arm (:16790) lists only `PaletteAction::all()`, and `runPaletteAction()` (:10491) throws on any other label. The 15 `SLASH_ONLY_SURVIVORS` (`PaletteSupersetDispatchTest.php:57-90`), file commands and MCP prompts therefore never appear.
  - **Fix.**
    - The default arm uses `PaletteCatalog::rows($this->slashCommandRows())`.
    - A Prefill entry in `runPaletteAction`/`runRootPaletteAction` (:17152) closes the palette and writes `/<name> ` into a BLANK draft, caret at the end.
    - A non-blank draft is kept, with a one-line notice: the draft-preservation law.
    - Prefill is allowed mid-turn, because it only edits the draft. MRU records it.
  - **VIS-4-P1.** The `PaletteAction.php:47-54` docblock is reworded to the full-superset truth. The COMMANDS.md palette paragraph changes too.
- files.create: none
- files.modify: `candy-code/src/Chat.php` (`paletteItemLabels`, `runPaletteAction`, `runRootPaletteAction`), `candy-code/src/Palette/PaletteAction.php` (docblock), `candy-code/lang/en.php` (`palette.*`), `candy-code/docs/COMMANDS.md` (palette paragraph)
- tests: create `candy-code/tests/Chat/PalettePrefillRowsTest.php` (every survivor, file command and MCP prompt is reachable; a blank draft is filled; a non-blank draft is kept; mid-turn allowed). Modify `candy-code/tests/Chat/PaletteSupersetDispatchTest.php` (a superset assertion over all slash-visible rows; the survivor reasons stay).
- docs/guards: COMMANDS.md. COMPARISON.md CMD-08 flips at closeout, not in-lane.
- serialized: [chat:palette — paletteItemLabels, runPaletteAction, runRootPaletteAction] [docs:COMMANDS.md] [lang:palette.]
- deps: C-EXT-5.a
- phase: normal
- ruling: none
- risks: the palette label memo (`paletteFuzzyMatches`) keys on the label list, and the list now varies per session. That is fine; it is already invalidated by list identity.

### C-EXT-5.c — Key-bindable slash commands (`command:<name>`)
- item: C-EXT-5 (sources: CC-new#12 key-bindable slash commands)
- kind: gap · effort M
- approach:
  - **Setting.** `keybindings` accepts `"command:<name>": "<chord>"`. `KeyBindingsValidator::validate()` (:42) checks the name syntactically (colon allowed, for bundle and MCP names) and the chord with the existing parser. `collisionNotices()` reports a collision with a shipped chord.
  - **Registry.** `KeyBindingRegistry::useOverrides()` splits `command:` ids into a command map whose memo is cleared in `resetDerived()`, so `KeysReloadTest`'s memo census stays honest. It adds `commandFor(KeyMsg)` and `commandBindings()`.
  - **Dispatch.** `App::handleKey()` (:3500) consults `commandFor()` before `honourKeybindingAliases()`. A hit calls `$this->chat->runCommand('/'.$name)`, the menu-bar idiom (App.php:4058), so mid-turn refusal and draft preservation come for free.
  - **Display.** `/keys` lists the user's command bindings under their own heading. `/keys reload` re-applies them.
  - The static `KeyBindingRegistry::all()` rows are untouched, so `KeyBindingDriftTest` does not move.
- files.create: none
- files.modify: `candy-code/src/Commands/KeyBindingRegistry.php`, `candy-code/src/Config/Settings/Validator/KeyBindingsValidator.php`, `candy-code/src/App/App.php` (`handleKey`), `candy-code/src/Renderer.php` (`renderKeyHelp`), `candy-code/src/Config/Settings/Definitions/InterfaceSettings.php` (:291 readBy), `candy-code/lang/en.php` (`keys.command.*`), `candy-code/docs/SETTINGS.md` (keybindings section ~:1407-1500, then `tools/gen-settings-doc.php` for the row at :302)
- tests: create `candy-code/tests/Commands/KeyBindingCommandBindingTest.php`, `candy-code/tests/App/CommandKeybindingDispatchTest.php`. Modify `candy-code/tests/Commands/KeysReloadTest.php`, `candy-code/tests/Cli/KeybindingCollisionNoticeTest.php`.
- docs/guards: SETTINGS.md through gen-settings-doc; the KeysReload memo census.
- serialized: [renderer:renderKeyHelp] [docs:SETTINGS.md] [lang:keys.command.] [hot:candy-code/src/App/App.php] [hot:candy-code/src/Commands/KeyBindingRegistry.php]
- deps: none
- phase: normal
- ruling: none
- risks: these bindings work only under the bin/candy-code App shell, not in a bare embedded Chat. Document that.

### C-EXT-4.a — Marketplace core: manifest reader, registry, provenance, search, recommender
- item: C-EXT-4 (sources: GAP-PLG-02, GAP-SKL-09, p1-miss plugin registry tier, CC-new#12 recommendations)
- kind: gap · effort M
- approach:
  - **`Marketplace`.** Parses Claude's `.claude-plugin/marketplace.json`: name, owner, `plugins[]` with a source (relative path, `{source:github,repo}` or `{source:url|git,url}`), description, version, keywords/tags/category. It validates names with the bundle-name rule and resolves relative sources inside the checkout through `ContainedPath` (absolute or escaping sources are refused).
  - **`MarketplaceRegistry`.** A user-tier record, `~/.candy-code/marketplaces.json` (name → source, pinned sha, addedAt), with checkouts under `~/.candy-code/marketplaces/<name>/`. It follows the `HomeDirectory::owned()` law and writes atomically.
  - **`BundleSource`.** The install provenance record, `<bundle>/.candy-code-source.json`.
  - **`BundleRecommender`.** Offline: it matches root manifest signals (composer.json, package.json, Dockerfile, `*.tf`, go.mod) against entry keywords and reports at most 3 bundles that are not installed.
  - **Search and pin.** Search is lexical. The SHA pin is read from `.git/HEAD`/packed-refs before `.git` is stripped, so no new spawn site is needed.
- files.create: `candy-code/src/Bundles/Marketplace.php`, `candy-code/src/Bundles/MarketplaceEntry.php`, `candy-code/src/Bundles/MarketplaceRegistry.php`, `candy-code/src/Bundles/BundleSource.php`, `candy-code/src/Bundles/BundleRecommender.php`
- files.modify: none
- tests: create `candy-code/tests/Bundles/MarketplaceTest.php`, `candy-code/tests/Bundles/MarketplaceRegistryTest.php`, `candy-code/tests/Bundles/BundleSourceTest.php`, `candy-code/tests/Bundles/BundleRecommenderTest.php`
- docs/guards: ReadPathCensusTest and ContainedPathInventoryTest rows for the new user-tier reads; one type per file.
- serialized: none
- deps: none (core only; nothing installs)
- phase: normal
- ruling: none (default: no marketplace pre-added)
- risks: Claude's manifest format drifts upstream, so tolerate unknown keys.

### C-EXT-4.b — `bundles marketplace add/list/remove/update`, `install name@mkt`, `update`, `search`, `recommend`
- item: C-EXT-4 (sources: GAP-PLG-02, GAP-SKL-09)
- kind: gap · effort M
- approach: `Subcommands::bundles()` (:1464) gains these verbs:
  - **`marketplace add <git-url|path>`.** Clones with the EXISTING `bundlesCloneDefault()` spawn into a scratch dir, validates, pins the SHA, strips `.git` and renames into place.
  - **`marketplace list`** and **`marketplace remove`**.
  - **`marketplace update [<name>]`.** Re-clones, prints added and removed entries, and moves the pin.
  - **`install <plugin>@<marketplace>`.** A relative source copies through C-SEC-1's hardened `copyBundleTree`; a git source uses the existing clone arm. It writes a `BundleSource` record.
  - **`update [<bundle>]`.** Re-installs from the recorded `BundleSource` into a scratch dir, then swaps: the old copy moves aside and is removed with no-follow.
  - **`search <q>`** and **`recommend`**, with text or `--output-format json`.
  - The step also updates the completion roster (`Subcommands.php:2586`) and `cli.bundles.usage`.
  - There is no new spawn site, so the child-lifetime roster does not change. Verify that.
- files.create: none
- files.modify: `candy-code/src/Cli/Subcommands.php` (`bundles`, new verb methods, completion roster), `candy-code/lang/en.php` (`cli.bundles.*`), `candy-code/docs/PLUGINS.md` ("Installing and listing" → a Marketplaces section), `candy-code/README.md` (:97 row, the :2131 CLI block)
- tests: create `candy-code/tests/Bundles/BundleMarketplaceCliTest.php` (with the clone seam `$bundlesInstallSpawn`), `candy-code/tests/Bundles/BundleUpdateTest.php`. Modify `candy-code/tests/Bundles/BundleInstallTest.php`.
- docs/guards: `ParsedArgs::SUBCOMMAND_FLAGS` if a flag is added; a DocFigure subcommand fence if one pins `bundles`.
- serialized: [headless] [docs:PLUGINS.md] [docs:README.md] [lang:cli.bundles.]
- deps: C-EXT-4.a, C-EXT-7.b, C-SEC-1, C-SEC-3
- phase: normal
- ruling: none
- risks: the update swap must never follow links. It depends on C-SEC-1's removal.

### C-EXT-4.c — `/<bundle>:<cmd>` namespacing
- item: C-EXT-4 (sources: GAP-CMD-10)
- kind: gap · effort S
- approach:
  - **Root cause.** `ForeignCommandDiscovery::bundleDirs()` (:143-155) and `walk()` (:217-229) key bundle rows by bare file name. Two bundles that both ship `review.md` collapse last-write-wins.
  - **Fix.** Bundle rows are keyed `<bundle>:<file>`. `TurnController::resolveCustomCommand()` (:404-437) tries the whole name before the colon-argument form, so `/<bundle>:<cmd>` resolves directly. The flat alias is kept only when no other row claims that name. The bundle name is threaded through `bundleDirs()` onto the spec.
- files.create: none
- files.modify: `candy-code/src/Commands/ForeignCommandDiscovery.php` (`bundleDirs`, `walk`), `candy-code/src/Commands/CommandLoader.php` (name stamp), `candy-code/docs/PLUGINS.md`, `candy-code/docs/COMMANDS.md`
- tests: create `candy-code/tests/Commands/BundleCommandNamespaceTest.php`. Modify `candy-code/tests/Commands/ForeignCommandDiscoveryTest.php`, `candy-code/tests/Bundles/BundleSurfaceWiringTest.php`.
- docs/guards: gen-command-docs if the table lists file rows.
- serialized: [docs:PLUGINS.md] [docs:COMMANDS.md] [hot:candy-code/src/Commands/ForeignCommandDiscovery.php]
- deps: C-SEC-3 (rewrites the bundle-tier provenance in the same method)
- phase: normal
- ruling: none
- risks: a user's muscle-memory flat names survive only while they are unclaimed.

### C-EXT-4.d — Contextual bundle recommendations at launch
- item: C-EXT-4 (sources: CC-new#12 plugin recommendations)
- kind: gap · effort S
- approach: a new `Bootstrap::reportBundleRecommendations()` in the `chat()` launch drain block (~:1524) emits ONE aggregate row from `BundleRecommender`, for example "bundles matching this project: X@Y — candy-code bundles install X@Y". It obeys these limits:
  - silent when no marketplace has been added;
  - shown once per project per marketplace pin, using a stamp in `marketplaces.json`;
  - offline: it never touches the network at launch.
- files.create: none
- files.modify: `candy-code/src/Cli/Bootstrap.php` (`chat()` drain block, new `reportBundleRecommendations`, the launch-cap census map at ~:290-330), `candy-code/lang/en.php` (`bundles.recommend.*`)
- tests: create `candy-code/tests/Cli/BundleRecommendationNoticeTest.php`. Modify `candy-code/tests/Cli/BootstrapLaunchNoticeCapCensusTest.php`.
- docs/guards: the cap census row (1); PLUGINS.md mention is reported for the C-EXT-4.b owner or the weld.
- serialized: [bootstrap:chat() launch drain, reportBundleRecommendations (new), launch-cap census map] [lang:bundles.recommend.]
- deps: C-EXT-4.a, C-EXT-4.b
- phase: normal
- ruling: none
- risks: a stat walk over the root on launch must stay bounded, with a fixed file list.

### C-EXT-6.a — Hook `systemMessage` shown as a notice
- item: C-EXT-6 (sources: GAP-HK-08 systemMessage half)
- kind: gap · effort S
- approach:
  - **Root cause.** `ScriptHook.php:1633-1643` treats `systemMessage` as "HONORED BY REMOVAL": it is parsed and then dropped, because no human surface existed. `HOOKS.md:628` says "not read".
  - **Fix.** A string `systemMessage` is clipped and control-escaped, then recorded through C-EXT-1.a's hook-notice helper (`RuntimeNoticeSink::record`). It is human-only and never reaches model context. `suppressOutput` does not hide it, matching Claude Code.
  - **Why this sliver is phase normal.** It is S effort, needs no ruling, and is independent of the http and prompt kinds and of the log.
- files.create: none
- files.modify: `candy-code/src/Hooks/ScriptHook.php` (`fromEnvelope`), `candy-code/lang/en.php` (`hooks.notice.system_message`), `candy-code/docs/HOOKS.md` ("JSON on stdout" :628)
- tests: create `candy-code/tests/Hooks/ScriptHookSystemMessageNoticeTest.php`. Modify `candy-code/tests/Hooks/ScriptHookTest.php` (:651: still absent from `additionalContext`, now present as a notice).
- docs/guards: HOOKS.md
- serialized: [docs:HOOKS.md] [lang:hooks.notice.]
- deps: C-EXT-1.a
- phase: normal
- ruling: none
- risks: served sessions show it only after C-CTX-2's notice drain.

### C-EXT-6.b — Hook log file + `/hooks log` (LATE)
- item: C-EXT-6 (sources: GAP-HK-08)
- kind: gap · effort M
- approach:
  - **Why a file.** Hooks run in forked turn and tool children, so an in-memory ring in the parent never sees them. That makes this M, not the cheap S sliver, so it stays LATE.
  - **`HookLog`.** A bounded JSONL file, `~/.candy-code/hooks-log.jsonl`. It uses AuditHook's pattern: flock append, rotate one generation, and the redactor. Each entry (`HookLogEntry`) holds ts, session, event, hook name, verdict, ms and the first line of the message.
  - **Writer.** `HookRegistry::executeHooks()` (:459) appends one entry per configured, non-built-in hook run.
  - **Reader.** `/hooks log [n]`, in HooksCommand and HooksHostCommand (the live chain from C-EXT-7.a), reads this session's tail.
  - The spec row gains an argumentHint. The description's first clause stays fixed, so `MenuBarLabelDerivationTest` holds.
- files.create: `candy-code/src/Hooks/HookLog.php`, `candy-code/src/Hooks/HookLogEntry.php`
- files.modify: `candy-code/src/Hooks/HookRegistry.php` (`executeHooks`), `candy-code/src/Commands/HooksCommand.php`, `candy-code/src/Host/Commands/HooksHostCommand.php`, `candy-code/builtin-commands/1390-hooks.php` (argumentHint, comment), `candy-code/lang/en.php` (`cmd.hooks.log.*`), `candy-code/docs/HOOKS.md` (new "The hook log" section), `candy-code/docs/COMMANDS.md` (gen)
- tests: create `candy-code/tests/Hooks/HookLogTest.php`, `candy-code/tests/Hooks/HookLogConcurrentAppendTest.php`, `candy-code/tests/Host/Commands/HooksLogCommandTest.php`. Modify `candy-code/tests/Commands/HooksCommandTest.php`.
- docs/guards: gen-command-docs; ReadPathCensusTest (the log path).
- serialized: [commands:builtin-commands/1390-hooks.php] [docs:HOOKS.md] [docs:COMMANDS.md] [lang:cmd.hooks.log.] [hot:candy-code/src/Hooks/HookRegistry.php]
- deps: C-EXT-7.a
- phase: LATE
- ruling: none
- risks: a write on every hook run must never fail the hook (fail-open bookkeeping) and must never write secrets (redact first).

### C-EXT-6.c — `type: http` hooks (LATE)
- item: C-EXT-6 (sources: GAP-HK-03)
- kind: gap · effort M
- approach:
  - **Shared extraction.** The envelope reader moves out of ScriptHook's private `envelope`/`fromEnvelope`/`malformedEnvelope` into `HookEnvelope`. The stdin payload moves out of ScriptHook's private `stdinPayload` (:1067) into `HookPayload`. ScriptHook delegates to both; this is consolidation, not deletion.
  - **`HttpHook`.** Implements BoundedHookInterface and POSTs the payload JSON to `url`:
    - only http and https; no redirects followed; response capped;
    - headers interpolate `$VAR` only from `allowedEnvVars`;
    - a 2xx JSON envelope is read through `HookEnvelope`; a 2xx with a non-JSON body allows;
    - an error under the claude dialect allows with a notice; under the native dialect it denies;
    - bounded by the hook timeout (not an LLM call, so E646 does not apply).
  - **Config and loading.** HookConfig gains `type` (`command` by default), `url`, `headers` and `allowedEnvVars`, with per-type key validation. `HookManager::loadEntries()` builds the hook by type. ClaudeSettingsHooks maps `type: http` instead of skip-recording it.
  - **Trust.** Http hooks carry the same trust as command hooks from the same file.
- files.create: `candy-code/src/Hooks/HttpHook.php`, `candy-code/src/Hooks/HookEnvelope.php`, `candy-code/src/Hooks/HookPayload.php`
- files.modify: `candy-code/src/Hooks/ScriptHook.php`, `candy-code/src/Hooks/HookConfig.php`, `candy-code/src/Hooks/HookManager.php` (`loadEntries`), `candy-code/src/Hooks/ClaudeSettingsHooks.php`, `candy-code/lang/en.php` (`hooks.http.*`), `candy-code/docs/HOOKS.md` (file-format keys, CC section)
- tests: create `candy-code/tests/Hooks/HttpHookTest.php` (a transport seam, no live server), `candy-code/tests/Hooks/HookEnvelopeTest.php`. Modify `candy-code/tests/Hooks/HookConfigTest.php`, `candy-code/tests/Hooks/ClaudeSettingsHooksTest.php`, `candy-code/tests/Hooks/ScriptHookJsonStdoutTest.php`, `candy-code/tests/Config/DocFigureProseDriftTest.php` (HOOKS.md entry-key pin).
- docs/guards: the HOOKS.md entry-key pin; one type per file.
- serialized: [docs:HOOKS.md] [lang:hooks.http.] [hot:candy-code/src/Hooks/ScriptHook.php] [hot:candy-code/src/Hooks/HookConfig.php] [hot:candy-code/src/Hooks/HookManager.php]
- deps: C-EXT-1.b
- phase: LATE
- ruling: none
- risks: a project-tier http hook can exfiltrate the payload. That is the same reach as a project command hook, gated by `trustedProjectHooks`.

### C-EXT-6.d — `type: prompt` hooks (LATE)
- item: C-EXT-6 (sources: GAP-HK-03, p1-miss CC hook `type: prompt`)
- kind: gap · effort M
- approach:
  - **`PromptHook`.** Implements HookInterface, deliberately not Bounded. Under E646 the verdict call carries only the provider connect timeout, as `ExecReviewer` does. It makes one tool-less completion on the title backend:
    - a fixed system prompt plus the hook's `prompt`, with `$ARGUMENTS` replaced by the fenced, untrusted payload;
    - the reply must be `{"ok":bool,"reason":string}`;
    - `ok:false` denies with the reason;
    - an unparseable reply or a missing backend: claude dialect allows with a notice, native denies;
    - `model` is honoured when the title provider can serve it.
  - **Config and loading.** HookConfig gains `prompt` and `model` for `type: prompt`. ClaudeSettingsHooks maps it.
  - **Backend wiring.** A `HookManager::withPromptBackend(Backend)` setter, installed by `Bootstrap::hooks()` (:5826) from `titleBackend()`.
  - **Timeout.** A `timeout` key is accepted but never applied to the LLM call. HOOKS.md says so.
- files.create: `candy-code/src/Hooks/PromptHook.php`
- files.modify: `candy-code/src/Hooks/HookConfig.php`, `candy-code/src/Hooks/HookManager.php`, `candy-code/src/Hooks/ClaudeSettingsHooks.php`, `candy-code/src/Cli/Bootstrap.php` (`hooks`), `candy-code/lang/en.php` (`hooks.prompt.*`), `candy-code/docs/HOOKS.md`
- tests: create `candy-code/tests/Hooks/PromptHookTest.php` (fake backend; no total timeout set), `candy-code/tests/Cli/BootstrapPromptHookWiringTest.php`. Modify `candy-code/tests/Hooks/HookConfigTest.php`, `candy-code/tests/Hooks/ClaudeSettingsHooksTest.php`, `candy-code/tests/Config/DocFigureProseDriftTest.php`.
- docs/guards: the HOOKS.md entry-key pin.
- serialized: [bootstrap:hooks] [docs:HOOKS.md] [lang:hooks.prompt.] [hot:candy-code/src/Hooks/HookConfig.php] [hot:candy-code/src/Hooks/HookManager.php] [hot:candy-code/src/Hooks/ClaudeSettingsHooks.php]
- deps: C-EXT-6.c
- phase: LATE
- ruling: none
- risks: every matched tool call spends title-backend tokens. Whether that spend counts against `/budget` follows the ExecReviewer precedent; confirm in-lane.

## Notes for the orchestrator

- **Owner order.**
  - C-EXT-1 lands before C-EXT-6: 6.a needs 1.a, and 6.c needs 1.b.
  - C-EXT-4.b waits for C-SEC-1 and C-SEC-3, and 4.c waits for C-SEC-3.
  - C-EXT-7.b waits for C-SEC-1, which edits the same `bundlesInstall`/`copyBundleTree` region.
  - No normal-phase step depends on a LATE step. 6.a is the only C-EXT-6 sliver split out as normal; the hook log is M because it must survive forks, so it stays LATE.
- **Hot files.** Serialize these across waves:
  - `src/Host/Commands/SkillsHostCommand.php`: 2.d, 3.a, 3.c, 3.e.
  - `docs/SKILLS.md`: 2.a, 2.b, 2.c, 2.d, 3.a, 3.b, 3.c, 3.e.
  - `docs/HOOKS.md`: 1.a, 1.b, 6.a–6.d.
  - `README.md`: 2.b, 2.c, 3.b, 3.c, 3.e, 4.b. The sentence at :2370 is shared.
  - `src/Support/FrontmatterKeyAudit.php`: 2.b, 2.c, 2.e.
  - `src/Tools/BuiltIn/SkillTool.php`: 2.a, 2.c.
  - `src/App/App.php`: 2.c, 2.d, 5.c.
  - `src/Hooks/ClaudeSettingsHooks.php`: 1.a, 1.b, 6.c, 6.d.
  - `src/Hooks/ScriptHook.php`: 1.a, 6.a, 6.c.
  - `src/Cli/Subcommands.php`: 7.b, 4.b, plus C-SEC-1.
  - `docs/PLUGINS.md`: 7.a, 7.b, 4.b, 4.c.
  - `docs/COMMANDS.md`: many steps; gen-command-docs regenerates it once per wave.
- **Cross-area hot files.**
  - `src/Permissions/PermissionGate.php`: 2.b vs C-SEC-6 and C-SEC-7.
  - `src/Backend/EngineBackend.php` and `src/Tools/BuiltIn/TaskTool.php`: 2.c vs C-AGT-3, C-AGT-4 and C-PRV-3.
  - `src/Host/TurnController.php` and `src/Host/SessionHost.php`: 2.e vs C-SEC-3, C-SEC-4, C-WEB-2 and C-CTX-2.
  - `src/Host/Commands/AgentsHostCommand.php` and Chat `handleAgentsCommand`: 3.d vs C-AGT-4.
  - `src/Commands/KeyBindingRegistry.php`: 5.c vs C-TUI-3 (verbose-toggle key).
  - `src/Commands/ForeignCommandDiscovery.php`: 4.c vs C-SEC-3.
- **[catalog]:** 3.b and 3.e each add a tool, so assign positions with the other areas' new tools. Positions 36–40 are taken.
- **Rulings.**
  - **R-EXT-1 (gates 2.b).** Skill `allowed-tools` pre-approval: user tier only, argument-scoped only, and narrowing-only for other tiers? Strip the built-ins' `allowed-tools: Read,Grep,Bash` and `effort: high`? Without a ruling, a repo skill could pre-approve Bash.
  - **R-EXT-2 (gates 3.e).** May model-written skills go live without `/skills accept`?
- **Corrections to evidence.**
  - GAP-AGT-07's plan has the agent Write `.candy-code/agents/`. `ProtectFilesHook::WRITE_ONLY_PATTERNS` denies that path, so 3.d drafts to `agents-proposed/` and promotes through `/agents accept`.
  - SkillMatcher's overflow line points at a tool that cannot list; 3.b fixes that.
  - `tests/Bundles/BundleTest.php`, cited by PLG-1-P4, does not exist; 7.a uses `BundleDeclinedSurfacesTest.php`.
- **Unverified.**
  - That `workspace->skills` is the same registry object as the engine's (3.a, 2.d).
  - The PermissionGateHook swap mechanics inside `Runtime::gate()` (2.b).
  - That nothing else consumes `SubmitPrompt` from TUI `runHostCommand` (3.d uses a Chat handler, so it is safe either way).
- **New test files to add to the durations TSV at the weld.**
  - Hooks: `ScriptHookClaudeDialectTest`, `ScriptHookSystemMessageNoticeTest`, `HookLogTest`, `HookLogConcurrentAppendTest`, `HttpHookTest`, `HookEnvelopeTest`, `PromptHookTest`.
  - Host/Commands: `HooksHostCommandLiveChainTest`, `HooksLogCommandTest`, `SkillsEnableDisableTest`, `SkillsReloadTest`, `SkillsNewTest`, `SkillsHistoryUndoTest`, `AgentsNewAcceptTest`.
  - Tools/BuiltIn: `SkillToolAssetTest`, `SkillToolForkTest`, `SkillsSearchTest`, `SkillWorkshopTest`.
  - Skills: `ActiveSkillEffectsTest`, `SkillForkTest`, `SkillRosterDiffTrackerTest`, `LearnedSkillsTest`.
  - Permissions and Runtime: `PermissionGateTurnGrantsTest`, `RuntimeSkillEffectsTest`.
  - Host and Chat: `TurnControllerCommandRoutingTest`, `CustomCommandModelOverrideTest`, `PalettePrefillRowsTest`.
  - Palette, Commands and App: `PaletteCatalogTest`, `KeyBindingCommandBindingTest`, `CommandKeybindingDispatchTest`, `BundleCommandNamespaceTest`.
  - Bundles: `MarketplaceTest`, `MarketplaceRegistryTest`, `BundleSourceTest`, `BundleRecommenderTest`, `BundleMarketplaceCliTest`, `BundleUpdateTest`.
  - Cli: `BundleRecommendationNoticeTest`, `BootstrapPromptHookWiringTest`.
