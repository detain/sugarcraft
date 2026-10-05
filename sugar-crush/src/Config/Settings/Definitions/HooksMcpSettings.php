<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Config\Settings\Definitions;

use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Config\Settings\ApplyMode;
use SugarCraft\Crush\Config\Settings\RiskClass;
use SugarCraft\Crush\Config\Settings\SettingCategory;
use SugarCraft\Crush\Config\Settings\SettingDefinition;
use SugarCraft\Crush\Config\Settings\SettingDefinitionSet;
use SugarCraft\Crush\Config\Settings\SettingType;
use SugarCraft\Crush\Config\Settings\UiEditability;
use SugarCraft\Crush\Config\Settings\Validator\ExecutableValidator;

/**
 * The "Hooks & MCP" category's keys. One file per category so a step adding a
 * key edits only its category's file (DH-KEYS); {@see \SugarCraft\Crush\Config\Settings\SettingsSchema}
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
                ->withLabel('Lint commands')
                ->withHelp('Post-edit lint command per file extension ({"php": "…", "js": false}); `php -l` is built in.')
                ->withReaderSymbol(Bootstrap::class . '::hooks')
                ->withReadBy('`Bootstrap::hooks()` → `LintRunner::withCommands()`'),
            // The bound a hook entry with no `timeout:` gets. config.json only:
            // a hook run is a SECURITY bound (a stuck hook freezes the CLI), so
            // no repository may raise it for the operator.
            SettingDefinition::new(\SugarCraft\Crush\Hooks\ScriptHook::DEFAULT_TIMEOUT_SETTING, SettingType::Float, \SugarCraft\Crush\Hooks\ScriptHook::DEFAULT_TIMEOUT_SECONDS)
                ->withCategory(SettingCategory::HooksMcp)
                ->withRiskClass(RiskClass::Tuning)
                ->withApplyMode(ApplyMode::Restart)
                ->withRange(1.0, 3600.0)
                ->withLabel('Hook timeout (s)')
                ->withHelp('Seconds a hook entry with no `timeout:` may run before it is killed and counted as a refusal.')
                ->withReaderSymbol(\SugarCraft\Crush\Hooks\ScriptHook::class . '::defaultTimeoutSeconds')
                ->withReadBy('`HookConfig::parse()` → `ScriptHook::defaultTimeoutSeconds()`, as each hook file loads'),
            // E696. Narrowing, so a trusted project may set it like
            // `disabledTools`: a deny only ever removes servers, and the
            // key-level merge lets the user's own list replace a project's.
            SettingDefinition::new(\SugarCraft\Crush\MCP\McpClient::DENY_SETTINGS_KEY, SettingType::StringList, [])
                ->withCategory(SettingCategory::HooksMcp)
                ->withRiskClass(RiskClass::Narrowing)
                ->withLayered()
                ->withProjectSettable()
                ->withApplyMode(ApplyMode::Restart)
                ->withUi(UiEditability::List)
                ->withLabel('Disabled MCP servers')
                ->withHelp('`.mcp.json` server names or globs (`untrusted_*`) that are never started, listed or called, for the main agent and every sub-agent.')
                ->withReaderSymbol(Bootstrap::class . '::mcpClient')
                ->withReadBy('`Bootstrap::mcpClient()` → `McpClient::setDenyPatterns()`, at the first MCP launch'),
            // Roadmap N-P4g: the persistent SUGARCRUSH_MCP_DISABLE. User tier
            // only — whether a checkout's `.mcp.json` runs is the trust list's
            // call, never the checkout's own settings file.
            SettingDefinition::new(Bootstrap::MCP_ENABLED_SETTING, SettingType::Bool, true)
                ->withCategory(SettingCategory::HooksMcp)
                ->withRiskClass(RiskClass::Exec)
                ->withLayered()
                ->withApplyMode(ApplyMode::Restart)
                ->withEnvVar(Bootstrap::MCP_DISABLE_ENV)
                ->withLabel('Project MCP servers')
                ->withHelp('Start the MCP servers a trusted project\'s .mcp.json names; off starts none, as if the file were absent.')
                ->withReaderSymbol(Bootstrap::class . '::mcpDisabled')
                ->withReadBy('`Bootstrap::mcpConfigDecision()` (so `mcpClient()` and `mcp list`) → `mcpDisabled()`'),
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
