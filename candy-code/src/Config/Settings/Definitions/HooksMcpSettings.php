<?php

declare(strict_types=1);

namespace SugarCraft\Code\Config\Settings\Definitions;

use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Config\Settings\ApplyMode;
use SugarCraft\Code\Config\Settings\RiskClass;
use SugarCraft\Code\Config\Settings\SettingCategory;
use SugarCraft\Code\Config\Settings\SettingDefinition;
use SugarCraft\Code\Config\Settings\SettingDefinitionSet;
use SugarCraft\Code\Config\Settings\SettingType;
use SugarCraft\Code\Config\Settings\UiEditability;
use SugarCraft\Code\Config\Settings\Validator\ExecutableValidator;
use SugarCraft\Code\Lang;

/**
 * The "Hooks & MCP" category's keys. One file per category so a step adding a
 * key edits only its category's file (DH-KEYS); {@see \SugarCraft\Code\Config\Settings\SettingsSchema}
 * collects every set.
 */
final class HooksMcpSettings implements SettingDefinitionSet
{
    public static function category(): SettingCategory
    {
        return SettingCategory::HooksMcp;
    }

    public static function definitions(): array
    {
        return [
            // Step 3.E: extension => lint command (or false to turn a default
            // off) for the post-edit lint hook. User-tier only: every value is
            // a shell command the hook runs after an edit, so a project-tier
            // one would be code execution on clone-and-launch (`statusLine`'s
            // argument).
            SettingDefinition::new('lintCommands', SettingType::Map, [])
                ->withCategory(SettingCategory::HooksMcp)
                ->withRiskClass(RiskClass::Exec)
                ->withLayered()
                ->withUi(UiEditability::Complex)
                ->withLabel(Lang::t('settings.lintCommands.label'))
                ->withHelp(Lang::t('settings.lintCommands.help'))
                ->withReaderSymbol(Bootstrap::class . '::hooks')
                ->withReadBy('`Bootstrap::hooks()` → `LintRunner::withCommands()`'),
            // EDT-1: extension => post-edit format command for the format hook.
            // Same user-tier, same code-execution argument as `lintCommands`,
            // and the same map shape — but the command MUTATES the file, which
            // is why `FormatRunner` hashes around the run and the hook runs
            // ahead of the lint so the linter sees the formatted bytes.
            SettingDefinition::new('formatCommands', SettingType::Map, [])
                ->withCategory(SettingCategory::HooksMcp)
                ->withRiskClass(RiskClass::Exec)
                ->withLayered()
                ->withUi(UiEditability::Complex)
                ->withLabel(Lang::t('settings.formatCommands.label'))
                ->withHelp(Lang::t('settings.formatCommands.help'))
                ->withReaderSymbol(Bootstrap::class . '::hooks')
                ->withReadBy('`Bootstrap::hooks()` → `FormatRunner::withCommands()`'),
            // C-TOOL-5.b: the built-in formatter table behind the post-edit
            // format hook. Exec for `formatCommands`' reason plus lspAutoDetect's:
            // the table's values are binaries the operator never typed, chosen by
            // the file's extension, and they OVERWRITE the edited file — the
            // riskiest entry (the project's own node_modules prettier, whose
            // plugins are arbitrary JavaScript) is why the arm additionally
            // requires the project's hooks trust, and why no checkout may set
            // the bit itself.
            SettingDefinition::new(\SugarCraft\Code\Format\FormatRunner::AUTO_SETTINGS_KEY, SettingType::Bool, false)
                ->withCategory(SettingCategory::HooksMcp)
                ->withRiskClass(RiskClass::Exec)
                ->withLayered()
                ->withLabel(Lang::t('settings.formatAuto.label'))
                ->withHelp(Lang::t('settings.formatAuto.help'))
                ->withReaderSymbol(Bootstrap::class . '::hooks')
                ->withReadBy('`Bootstrap::hooks()` → `FormatRunner::withAuto()`'),
            // The bound a hook entry with no `timeout:` gets. config.json only:
            // a hook run is a SECURITY bound (a stuck hook freezes the CLI), so
            // no repository may raise it for the operator.
            SettingDefinition::new(\SugarCraft\Code\Hooks\ScriptHook::DEFAULT_TIMEOUT_SETTING, SettingType::Float, \SugarCraft\Code\Hooks\ScriptHook::DEFAULT_TIMEOUT_SECONDS)
                ->withCategory(SettingCategory::HooksMcp)
                ->withRiskClass(RiskClass::Tuning)
                ->withApplyMode(ApplyMode::Restart)
                ->withRange(1.0, 3600.0)
                ->withLabel(Lang::t('settings.hooksDefaultTimeoutSeconds.label'))
                ->withHelp(Lang::t('settings.hooksDefaultTimeoutSeconds.help'))
                ->withReaderSymbol(\SugarCraft\Code\Hooks\ScriptHook::class . '::defaultTimeoutSeconds')
                ->withReadBy('`HookConfig::parse()` → `ScriptHook::defaultTimeoutSeconds()`, as each hook file loads'),
            // E696. Narrowing, so a trusted project may set it like
            // `disabledTools`: a deny only ever removes servers, and the
            // key-level merge lets the user's own list replace a project's.
            SettingDefinition::new(\SugarCraft\Code\MCP\McpClient::DENY_SETTINGS_KEY, SettingType::StringList, [])
                ->withCategory(SettingCategory::HooksMcp)
                ->withRiskClass(RiskClass::Narrowing)
                ->withLayered()
                ->withProjectSettable()
                ->withApplyMode(ApplyMode::Restart)
                ->withUi(UiEditability::List)
                ->withLabel(Lang::t('settings.disabledMcpServers.label'))
                ->withHelp(Lang::t('settings.disabledMcpServers.help'))
                ->withReaderSymbol(Bootstrap::class . '::mcpClient')
                ->withReadBy('`Bootstrap::mcpClient()` → `McpClient::setDenyPatterns()`, at the first MCP launch'),
            // Roadmap N-P4g: the persistent CANDY_CODE_MCP_DISABLE. User tier
            // only — whether a checkout's `.mcp.json` runs is the trust list's
            // call, never the checkout's own settings file.
            SettingDefinition::new(Bootstrap::MCP_ENABLED_SETTING, SettingType::Bool, true)
                ->withCategory(SettingCategory::HooksMcp)
                ->withRiskClass(RiskClass::Exec)
                ->withLayered()
                ->withApplyMode(ApplyMode::Restart)
                ->withEnvVar(Bootstrap::MCP_DISABLE_ENV)
                ->withLabel(Lang::t('settings.mcp.enabled.label'))
                ->withHelp(Lang::t('settings.mcp.enabled.help'))
                ->withReaderSymbol(Bootstrap::class . '::mcpDisabled')
                ->withReadBy('`Bootstrap::mcpConfigDecision()` (so `mcpClient()` and `mcp list`) → `mcpDisabled()`'),
            // MCP-7: the per-server schema-token floor above which a server's
            // tools ride the mcp_list/mcp_call dispatcher pair instead of
            // first-class mcp__server__tool bridges. User tier only, like
            // mcp.enabled: how much of a model's window a checkout's servers
            // may occupy is the operator's call, never the checkout's.
            SettingDefinition::new(Bootstrap::MCP_LAZY_SETTING, SettingType::Int, Bootstrap::DEFAULT_LAZY_SCHEMA_TOKENS)
                ->withCategory(SettingCategory::HooksMcp)
                ->withRiskClass(RiskClass::Tuning)
                ->withLayered()
                ->withApplyMode(ApplyMode::Restart)
                ->withRange(0)
                ->withLabel(Lang::t('settings.mcp.lazySchemaTokens.label'))
                ->withHelp(Lang::t('settings.mcp.lazySchemaTokens.help'))
                ->withReaderSymbol(Bootstrap::class . '::mcpToolShape')
                ->withReadBy('`Bootstrap::mcpToolShape()` → `McpToolShape::derive()`, at the first MCP tool build of the launch'),
            SettingDefinition::new('claudeMcpBinary', SettingType::Path)
                ->withCategory(SettingCategory::HooksMcp)
                ->withRiskClass(RiskClass::Exec)
                ->withApplyMode(ApplyMode::Frozen)
                ->withValidators(ExecutableValidator::new())
                ->withLabel(Lang::t('settings.claudeMcpBinary.label'))
                ->withHelp(Lang::t('settings.claudeMcpBinary.help'))
                ->withReaderSymbol(Bootstrap::class . '::claudeMcpGrant'),
            SettingDefinition::new('claudeMcpArgs', SettingType::StringList)
                ->withCategory(SettingCategory::HooksMcp)
                ->withRiskClass(RiskClass::Exec)
                ->withApplyMode(ApplyMode::Frozen)
                ->withUi(UiEditability::List)
                ->withLabel(Lang::t('settings.claudeMcpArgs.label'))
                ->withHelp(Lang::t('settings.claudeMcpArgs.help'))
                ->withReaderSymbol(Bootstrap::class . '::claudeMcpGrant'),
            SettingDefinition::new('claudeMcpEnv', SettingType::Map)
                ->withCategory(SettingCategory::HooksMcp)
                ->withRiskClass(RiskClass::Security)
                ->withApplyMode(ApplyMode::Frozen)
                ->withUi(UiEditability::Complex)
                ->withLabel(Lang::t('settings.claudeMcpEnv.label'))
                ->withHelp(Lang::t('settings.claudeMcpEnv.help'))
                ->withReaderSymbol(Bootstrap::class . '::claudeMcpGrant'),
        ];
    }
}
