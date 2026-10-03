<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Config\Settings;

use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Config\StatusLineCommand;
use SugarCraft\Crush\Config\Settings\Validator\AbsolutePathValidator;
use SugarCraft\Crush\Config\Settings\Validator\ExecutableValidator;
use SugarCraft\Crush\Permissions\PermissionMode;
use SugarCraft\Crush\Providers\ProviderFactory;
use SugarCraft\Crush\Runtime;
use SugarCraft\Crush\Theme;

/**
 * Every settings key sugar-crush reads from a settings file, described once.
 *
 * ONE SCHEMA, MANY CONSUMERS: the settings editor's form, the generated key
 * table in `docs/SETTINGS.md`, the "Settings key" column of
 * `docs/ENVIRONMENT.md`, and the tier rules. In this first phase the tier
 * constants in {@see \SugarCraft\Crush\Config\LayeredSettings} stay the source
 * the merge filters on and the schema is ASSERTED equal to them
 * ({@see \SugarCraft\Crush\Tests\Config\Settings\SettingsSchemaTest}); deriving
 * the constants from here is a later step, because their doc-blocks are pinned.
 *
 * WHAT IS IN IT: every key that some reader in `src/` actually consults — the
 * layered keys, the two strict permission keys, the four trust lists, the
 * three `claudeMcp*` grant keys and `enabledSkills`. Nothing speculative: a key
 * joins when its reader does, for the reason `LayeredSettings::LAYERED_KEYS`
 * gives — a key nothing reads is worse than a missing one, because it looks
 * configurable. Package provider definitions (`config.dev.json`) are not
 * settings keys and are not here.
 */
final class SettingsSchema
{
    /** @var list<SettingDefinition>|null */
    private static ?array $all = null;

    private function __construct()
    {
    }

    /**
     * Every definition, in category order and then declaration order.
     *
     * @return list<SettingDefinition>
     */
    public static function all(): array
    {
        if (self::$all !== null) {
            return self::$all;
        }

        $definitions = self::definitions();
        $seen = [];
        foreach ($definitions as $definition) {
            if (isset($seen[$definition->key])) {
                throw new \LogicException("settings key '{$definition->key}' is defined twice");
            }

            $seen[$definition->key] = true;
        }

        usort(
            $definitions,
            static fn (SettingDefinition $a, SettingDefinition $b): int => $a->category->order() <=> $b->category->order(),
        );

        return self::$all = $definitions;
    }

    public static function byKey(string $key): ?SettingDefinition
    {
        foreach (self::all() as $definition) {
            if ($definition->key === $key) {
                return $definition;
            }
        }

        return null;
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_map(static fn (SettingDefinition $d): string => $d->key, self::all());
    }

    /** @return list<SettingDefinition> */
    public static function inCategory(SettingCategory $category): array
    {
        return array_values(array_filter(
            self::all(),
            static fn (SettingDefinition $d): bool => $d->category === $category,
        ));
    }

    /**
     * The keys `LayeredSettings` merges from the lower layers.
     *
     * @return list<string>
     */
    public static function layeredKeys(): array
    {
        return self::keysWhere(static fn (SettingDefinition $d): bool => $d->layered);
    }

    /**
     * The layered keys a trusted project may contribute.
     *
     * @return list<string>
     */
    public static function projectTierKeys(): array
    {
        return self::keysWhere(static fn (SettingDefinition $d): bool => $d->layered && $d->projectSettable);
    }

    /**
     * Environment variable → the settings key it outranks.
     *
     * @return array<string, string>
     */
    public static function envMap(): array
    {
        $map = [];
        foreach (self::all() as $definition) {
            if ($definition->envVar !== null) {
                $map[$definition->envVar] = $definition->key;
            }
        }

        ksort($map);

        return $map;
    }

    /** @return list<string> */
    private static function keysWhere(\Closure $predicate): array
    {
        return array_values(array_map(
            static fn (SettingDefinition $d): string => $d->key,
            array_filter(self::all(), $predicate),
        ));
    }

    /** @return list<SettingDefinition> */
    private static function definitions(): array
    {
        return [
            // ---- Model & Provider -------------------------------------------
            SettingDefinition::new('provider', SettingType::String)
                ->withCategory(SettingCategory::ModelProvider)
                ->withRiskClass(RiskClass::Egress)
                ->withLayered()
                ->withApplyMode(ApplyMode::Live)
                ->withEnvVar('SUGARCRUSH_PROVIDER')
                ->withOptionsSource(OptionsSource::Providers)
                ->withLabel('Provider')
                ->withHelp('Which LLM provider the session talks to; every prompt is sent to its host.')
                ->withReaderSymbol(Bootstrap::class . '::selectedProviderName')
                ->withReadBy('`Bootstrap::selectedProviderName()`, `backend()`'),
            SettingDefinition::new('titleModel', SettingType::String)
                ->withCategory(SettingCategory::ModelProvider)
                ->withRiskClass(RiskClass::Spend)
                ->withLayered()
                ->withEnvVar('SUGARCRUSH_TITLE_MODEL')
                ->withLabel('Title model')
                ->withHelp('Model that names sessions and writes prompt suggestions; unset uses the provider default.')
                ->withReaderSymbol(Bootstrap::class . '::titleBackend'),
            SettingDefinition::new('summaryModel', SettingType::String)
                ->withCategory(SettingCategory::ModelProvider)
                ->withRiskClass(RiskClass::Spend)
                ->withLayered()
                ->withEnvVar('SUGARCRUSH_SUMMARY_MODEL')
                ->withLabel('Summary model')
                ->withHelp('Model that writes /compact summaries; unset uses the provider default.')
                ->withReaderSymbol(Bootstrap::class . '::summaryBackend'),
            SettingDefinition::new('maxOutputTokens', SettingType::Int)
                ->withCategory(SettingCategory::ModelProvider)
                ->withRiskClass(RiskClass::Spend)
                ->withLayered()
                ->withApplyMode(ApplyMode::NextTurn)
                ->withRange(1)
                ->withLabel('Max output tokens')
                ->withHelp('Per-request output ceiling; unset sends no override and the provider default applies.')
                ->withReaderSymbol(EngineBackend::class . '::complete'),
            SettingDefinition::new('modelPrices', SettingType::Map, [])
                ->withCategory(SettingCategory::ModelProvider)
                ->withRiskClass(RiskClass::Spend)
                ->withLayered()
                ->withUi(UiEditability::Complex)
                ->withLabel('Model prices')
                ->withHelp('USD per 1M tokens per model ({"input": …, "output": …}), for the spend total and cap.')
                ->withReaderSymbol(ProviderFactory::class . '::userTierModelPrices')
                ->withReadBy('`ProviderFactory::createOpenAI()`, `createVertex()`, `createBedrock()` → `userTierModelPrices()`'),
            SettingDefinition::new('extraBody', SettingType::Map)
                ->withCategory(SettingCategory::ModelProvider)
                ->withRiskClass(RiskClass::Egress)
                ->withLayered()
                ->withUi(UiEditability::Complex)
                ->withLabel('Extra request body')
                ->withHelp('Top-level request fields added to every `custom` provider request.')
                ->withReaderSymbol(ProviderFactory::class . '::createCustom')
                ->withReadBy('`ProviderFactory::createCustom()` → `CustomProvider`'),
            SettingDefinition::new('thinkingBudget', SettingType::Int)
                ->withCategory(SettingCategory::ModelProvider)
                ->withRiskClass(RiskClass::Spend)
                ->withLayered()
                ->withRange(0)
                ->withLabel('Thinking budget')
                ->withHelp('Gemini thinking-token budget on the `vertex` provider; thinking tokens bill as output.')
                ->withReaderSymbol(ProviderFactory::class . '::createVertex')
                ->withReadBy('`ProviderFactory::createVertex()` → `VertexProvider`'),
            SettingDefinition::new('promptCache', SettingType::Bool, true)
                ->withCategory(SettingCategory::ModelProvider)
                ->withRiskClass(RiskClass::Spend)
                ->withLayered()
                ->withEnvVar('SUGARCRUSH_DISABLE_PROMPT_CACHE')
                ->withLabel('Prompt cache')
                ->withHelp('Whether the `vertex` and `bedrock` providers mark prompt-cache breakpoints.')
                ->withReaderSymbol(ProviderFactory::class . '::promptCacheEnabled')
                ->withReadBy('`ProviderFactory::createVertex()`, `createBedrock()` → `promptCacheEnabled()`'),

            // ---- Agent loop ---------------------------------------------------
            SettingDefinition::new('parallelToolCalls', SettingType::Bool, true)
                ->withCategory(SettingCategory::AgentLoop)
                ->withRiskClass(RiskClass::Tuning)
                ->withLayered()
                ->withProjectSettable()
                ->withApplyMode(ApplyMode::NextTurn)
                ->withEnvVar('SUGARCRUSH_DISABLE_PARALLEL_TOOL_CALLS')
                ->withLabel('Parallel tool calls')
                ->withHelp('Run a turn\'s read-only tool calls concurrently.')
                ->withReaderSymbol(EngineBackend::class . '::complete'),
            SettingDefinition::new('parallelToolDeadlineSeconds', SettingType::Int, Runtime::PARALLEL_TOOL_DEADLINE_SECONDS)
                ->withCategory(SettingCategory::AgentLoop)
                ->withRiskClass(RiskClass::Tuning)
                ->withLayered()
                ->withProjectSettable()
                ->withApplyMode(ApplyMode::NextTurn)
                ->withEnvVar('SUGARCRUSH_PARALLEL_TOOL_DEADLINE')
                ->withRange(1)
                ->withLabel('Parallel tool deadline (s)')
                ->withHelp('Wall-clock ceiling for one batch of concurrently dispatched tool calls.')
                ->withReaderSymbol(EngineBackend::class . '::complete'),
            SettingDefinition::new('maxToolSteps', SettingType::Int)
                ->withCategory(SettingCategory::AgentLoop)
                ->withRiskClass(RiskClass::Spend)
                ->withLayered()
                ->withRange(1)
                ->withLabel('Max tool steps')
                ->withHelp('Provider calls one turn may make; unset keeps the engine default.')
                ->withReaderSymbol(Bootstrap::class . '::resolvedMaxToolSteps')
                ->withReadBy('`Bootstrap::backend()` → `resolvedMaxToolSteps()`'),

            // ---- Context ------------------------------------------------------
            SettingDefinition::new('contextWindow', SettingType::Json)
                ->withCategory(SettingCategory::Context)
                ->withRiskClass(RiskClass::Tuning)
                ->withLayered()
                ->withLabel('Context window')
                ->withHelp('Token window override: a count for the provider\'s model, or {"<model>": tokens}.')
                ->withReaderSymbol(ProviderFactory::class . '::createOpenAI')
                ->withReadBy('`ProviderFactory::createOpenAI()` → `OpenAIProvider::contextWindow()`'),

            // ---- Permissions --------------------------------------------------
            SettingDefinition::new('permissionMode', SettingType::Enum, PermissionMode::BypassPermissions->value)
                ->withCategory(SettingCategory::Permissions)
                ->withRiskClass(RiskClass::Security)
                ->withStrict()
                ->withEnvVar('SUGARCRUSH_PERMISSION_MODE')
                ->withCliFlag('--permission-mode')
                ->withEnumValues(array_map(static fn (PermissionMode $m): string => $m->value, PermissionMode::cases()))
                ->withOptionsSource(OptionsSource::PermissionModes)
                ->withLabel('Permission mode')
                ->withHelp('How tool calls are gated. Never taken from a project file.')
                ->withReaderSymbol(Bootstrap::class . '::permissionGate'),
            SettingDefinition::new('permissionRules', SettingType::Json, [])
                ->withCategory(SettingCategory::Permissions)
                ->withRiskClass(RiskClass::Security)
                ->withStrict()
                ->withUi(UiEditability::Complex)
                ->withLabel('Permission rules')
                ->withHelp('Ordered allow / deny / ask rules matched against each tool call.')
                ->withReaderSymbol(Bootstrap::class . '::permissionGate'),
            SettingDefinition::new('secretEnvAllowlist', SettingType::StringList, [])
                ->withCategory(SettingCategory::Permissions)
                ->withRiskClass(RiskClass::Security)
                ->withLayered()
                ->withUi(UiEditability::List)
                ->withLabel('Secret env allowlist')
                ->withHelp('Credential-shaped variable names (or globs) Bash, Grep and script hooks still inherit.')
                ->withReaderSymbol(Bootstrap::class . '::installSecretEnvAllowlist')
                ->withReadBy('`Bootstrap::tools()` → `installSecretEnvAllowlist()`'),
            SettingDefinition::new('trustedProjectHooks', SettingType::StringList, [])
                ->withCategory(SettingCategory::Permissions)
                ->withRiskClass(RiskClass::Security)
                ->withApplyMode(ApplyMode::Frozen)
                ->withUi(UiEditability::List)
                ->withValidators(AbsolutePathValidator::new())
                ->withLabel('Trusted project hooks')
                ->withHelp('Project roots whose .sugar-crush/hooks.yaml may run.')
                ->withReaderSymbol(Bootstrap::class . '::trustedRootsForThisProcess'),
            SettingDefinition::new('trustedProjectMcp', SettingType::StringList, [])
                ->withCategory(SettingCategory::Permissions)
                ->withRiskClass(RiskClass::Security)
                ->withApplyMode(ApplyMode::Frozen)
                ->withUi(UiEditability::List)
                ->withValidators(AbsolutePathValidator::new())
                ->withLabel('Trusted project MCP')
                ->withHelp('Project roots whose .mcp.json servers may be launched.')
                ->withReaderSymbol(Bootstrap::class . '::projectMcpIsTrusted'),
            SettingDefinition::new('trustedProjectCommands', SettingType::StringList, [])
                ->withCategory(SettingCategory::Permissions)
                ->withRiskClass(RiskClass::Security)
                ->withApplyMode(ApplyMode::Frozen)
                ->withUi(UiEditability::List)
                ->withValidators(AbsolutePathValidator::new())
                ->withLabel('Trusted project commands')
                ->withHelp('Project roots whose slash commands may run !`cmd` shell blocks.')
                ->withReaderSymbol(Bootstrap::class . '::projectCommandShellIsTrusted'),
            SettingDefinition::new('trustedProjectSettings', SettingType::StringList, [])
                ->withCategory(SettingCategory::Permissions)
                ->withRiskClass(RiskClass::Security)
                ->withApplyMode(ApplyMode::Frozen)
                ->withUi(UiEditability::List)
                ->withValidators(AbsolutePathValidator::new())
                ->withLabel('Trusted project settings')
                ->withHelp('Project roots whose .sugar-crush/settings*.json may set the project-tier keys.')
                ->withReaderSymbol(Bootstrap::class . '::projectSettingsTrusted'),

            // ---- Tools --------------------------------------------------------
            SettingDefinition::new('allowedTools', SettingType::StringList)
                ->withCategory(SettingCategory::Tools)
                ->withRiskClass(RiskClass::Security)
                ->withLayered()
                ->withUi(UiEditability::List)
                ->withOptionsSource(OptionsSource::Tools)
                ->withLabel('Allowed tools')
                ->withHelp('Whitelist of tool names or globs; unset offers every tool.')
                ->withReaderSymbol(Bootstrap::class . '::filterToolSet')
                ->withReadBy('`Bootstrap::tools()` → `filterToolSet()`'),
            SettingDefinition::new('disabledTools', SettingType::StringList, [])
                ->withCategory(SettingCategory::Tools)
                ->withRiskClass(RiskClass::Narrowing)
                ->withLayered()
                ->withProjectSettable()
                ->withUi(UiEditability::List)
                ->withOptionsSource(OptionsSource::Tools)
                ->withLabel('Disabled tools')
                ->withHelp('Tool names or globs removed from the model-facing tool set.')
                ->withReaderSymbol(Bootstrap::class . '::filterToolSet')
                ->withReadBy('`Bootstrap::tools()` → `filterToolSet()`'),

            // ---- Memory & Rules -----------------------------------------------
            SettingDefinition::new('instructions', SettingType::StringList, [])
                ->withCategory(SettingCategory::MemoryRules)
                ->withRiskClass(RiskClass::Prompt)
                ->withLayered()
                ->withUi(UiEditability::List)
                ->withLabel('Forced instructions')
                ->withHelp('Globs of files whose contents become authoritative system-prompt text.')
                ->withReaderSymbol(Bootstrap::class . '::forcedInstructions'),
            SettingDefinition::new('disabledRules', SettingType::StringList, [])
                ->withCategory(SettingCategory::MemoryRules)
                ->withRiskClass(RiskClass::Prompt)
                ->withLayered()
                ->withUi(UiEditability::List)
                ->withOptionsSource(OptionsSource::RulePacks)
                ->withLabel('Disabled rule packs')
                ->withHelp('User-tier rule packs kept out of the prompt from the first turn.')
                ->withReaderSymbol(Bootstrap::class . '::rulePacksToDisable')
                ->withReadBy('`Bootstrap::chat()` → `RulesState::new()`'),

            // ---- Skills -------------------------------------------------------
            SettingDefinition::new('disabledSkills', SettingType::StringList, [])
                ->withCategory(SettingCategory::Skills)
                ->withRiskClass(RiskClass::Narrowing)
                ->withLayered()
                ->withProjectSettable()
                ->withUi(UiEditability::List)
                ->withOptionsSource(OptionsSource::Skills)
                ->withLabel('Disabled skills')
                ->withHelp('Skills removed from discovery; also wins over enabledSkills.')
                ->withReaderSymbol(Bootstrap::class . '::skillRegistry')
                ->withReadBy('`Bootstrap::chat()` → `skillRegistry()`'),
            SettingDefinition::new('enabledSkills', SettingType::StringList, [])
                ->withCategory(SettingCategory::Skills)
                ->withRiskClass(RiskClass::Prompt)
                ->withUi(UiEditability::List)
                ->withOptionsSource(OptionsSource::Skills)
                ->withLabel('Enabled skills')
                ->withHelp('Skills whose full bodies ride the system prompt every turn.')
                ->withReaderSymbol(Bootstrap::class . '::promptEnabledSkills'),

            // ---- Git & Automation ---------------------------------------------
            SettingDefinition::new('includeGitInstructions', SettingType::Bool, true)
                ->withCategory(SettingCategory::Git)
                ->withRiskClass(RiskClass::Narrowing)
                ->withLayered()
                ->withProjectSettable()
                ->withLabel('Git instructions')
                ->withHelp('Whether the Bash tool\'s generic commit guidance rides the system prompt.')
                ->withReaderSymbol(Bootstrap::class . '::tools')
                ->withReadBy('`Bootstrap::tools()` → `Bash::withGitGuidance()`'),
            SettingDefinition::new('attribution', SettingType::Map)
                ->withCategory(SettingCategory::Git)
                ->withRiskClass(RiskClass::Prompt)
                ->withLayered()
                ->withUi(UiEditability::Complex)
                ->withLabel('Attribution')
                ->withHelp('{"commit": "…", "pr": "…"}: the trailer and PR line the git guidance asks for.')
                ->withReaderSymbol(Bootstrap::class . '::tools')
                ->withReadBy('`Bootstrap::tools()` → `Bash::withGitGuidance()`'),

            // ---- Interface ----------------------------------------------------
            SettingDefinition::new('theme', SettingType::Enum, 'dark')
                ->withCategory(SettingCategory::Interface)
                ->withRiskClass(RiskClass::Cosmetic)
                ->withLayered()
                ->withProjectSettable()
                ->withApplyMode(ApplyMode::Live)
                ->withEnumValues(Theme::names())
                ->withOptionsSource(OptionsSource::Themes)
                ->withLabel('Theme')
                ->withHelp('Colour theme; /theme and the palette change it live.')
                ->withReaderSymbol(Bootstrap::class . '::chat'),
            SettingDefinition::new('statusLine', SettingType::Map)
                ->withCategory(SettingCategory::Interface)
                ->withRiskClass(RiskClass::Exec)
                ->withLayered()
                ->withUi(UiEditability::Complex)
                ->withLabel('Status line command')
                ->withHelp('{"type": "command", "command": "…"}: a shell command whose output paints the status bar.')
                ->withReaderSymbol(StatusLineCommand::class . '::fromSettings')
                ->withReadBy('`Bootstrap::chat()` → `StatusLineCommand::fromSettings()`'),
            SettingDefinition::new('layout', SettingType::Json)
                ->withCategory(SettingCategory::Interface)
                ->withRiskClass(RiskClass::Cosmetic)
                ->withLayered()
                ->withApplyMode(ApplyMode::Live)
                ->withUi(UiEditability::ReadOnly)
                ->withLabel('Pane layout')
                ->withHelp('The docked-pane manifest; written by the shell when panes move.')
                ->withReaderSymbol(Bootstrap::class . '::app')
                ->withReadBy('`Bootstrap::app()` → `App::$dock` via `DockLayout::fromArray()`'),

            // ---- Hooks & MCP --------------------------------------------------
            SettingDefinition::new('claudeMcpBinary', SettingType::Path)
                ->withCategory(SettingCategory::HooksMcp)
                ->withRiskClass(RiskClass::Exec)
                ->withApplyMode(ApplyMode::Frozen)
                ->withValidators(ExecutableValidator::new())
                ->withLabel('Claude MCP binary')
                ->withHelp('Absolute path of the `claude` binary the claude-mcp transport may spawn.')
                ->withReaderSymbol(Bootstrap::class . '::claudeMcpGrant'),
            SettingDefinition::new('claudeMcpArgs', SettingType::StringList)
                ->withCategory(SettingCategory::HooksMcp)
                ->withRiskClass(RiskClass::Exec)
                ->withApplyMode(ApplyMode::Frozen)
                ->withUi(UiEditability::List)
                ->withLabel('Claude MCP arguments')
                ->withHelp('Arguments for that binary; unset uses the transport default.')
                ->withReaderSymbol(Bootstrap::class . '::claudeMcpGrant'),
            SettingDefinition::new('claudeMcpEnv', SettingType::Map)
                ->withCategory(SettingCategory::HooksMcp)
                ->withRiskClass(RiskClass::Security)
                ->withApplyMode(ApplyMode::Frozen)
                ->withUi(UiEditability::Complex)
                ->withLabel('Claude MCP environment')
                ->withHelp('Literal environment variables handed to that binary.')
                ->withReaderSymbol(Bootstrap::class . '::claudeMcpGrant'),
        ];
    }
}
