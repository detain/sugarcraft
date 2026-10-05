<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Config\Settings\Definitions;

use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Config\Settings\RiskClass;
use SugarCraft\Crush\Config\Settings\SettingCategory;
use SugarCraft\Crush\Config\Settings\SettingDefinition;
use SugarCraft\Crush\Config\Settings\SettingDefinitionSet;
use SugarCraft\Crush\Config\Settings\SettingType;
use SugarCraft\Crush\Config\Settings\UiEditability;
use SugarCraft\Crush\LSP\LspLauncher;
use SugarCraft\Crush\Lang;
use SugarCraft\Crush\Workspace\AutoCommitter;

/**
 * The "Git & Automation" category's keys. One file per category so a step adding a
 * key edits only its category's file (DH-KEYS); {@see \SugarCraft\Crush\Config\Settings\SettingsSchema}
 * collects every set.
 */
final class GitSettings implements SettingDefinitionSet
{
    public static function category(): SettingCategory
    {
        return SettingCategory::Git;
    }

    public static function definitions(): array
    {
        return [
            SettingDefinition::new('includeGitInstructions', SettingType::Bool, true)
                ->withCategory(SettingCategory::Git)
                ->withRiskClass(RiskClass::Narrowing)
                ->withLayered()
                ->withProjectSettable()
                ->withLabel(Lang::t('settings.includeGitInstructions.label'))
                ->withHelp('Whether the Bash tool\'s generic commit guidance rides the system prompt.')
                ->withReaderSymbol(Bootstrap::class . '::tools')
                ->withReadBy('`Bootstrap::tools()` → `Bash::withGitGuidance()`'),
            SettingDefinition::new('attribution', SettingType::Map)
                ->withCategory(SettingCategory::Git)
                ->withRiskClass(RiskClass::Prompt)
                ->withLayered()
                ->withUi(UiEditability::Complex)
                ->withLabel(Lang::t('settings.attribution.label'))
                ->withHelp('{"commit": "…", "pr": "…"}: the trailer and PR line the git guidance asks for.')
                ->withReaderSymbol(Bootstrap::class . '::tools')
                ->withReadBy('`Bootstrap::tools()` → `Bash::withGitGuidance()`'),
            // Step 3.F. Filed under "Git & Automation" with the other
            // after-the-edit automation, since one category may not span two
            // definition files. Exec and never project-settable: starting a
            // language server is code execution, as `.mcp.json` is.
            SettingDefinition::new(LspLauncher::SETTINGS_KEY, SettingType::Map)
                ->withCategory(SettingCategory::Git)
                ->withRiskClass(RiskClass::Exec)
                ->withLayered()
                ->withUi(UiEditability::Complex)
                ->withLabel(Lang::t('settings.lsp.label'))
                ->withHelp('{"php": {"command": "intelephense", "args": ["--stdio"]}}: servers for post-edit diagnostics, Read outlines and the Lsp tool.')
                ->withReaderSymbol(Bootstrap::class . '::lspClient')
                ->withReadBy('`Bootstrap::lspClient()` → `LspLauncher::fromConfig()`'),
            // Step 3.G. Exec and never project-settable: a commit runs the
            // repository's own hooks, and a checkout must not be able to turn
            // on commits into the operator's history.
            SettingDefinition::new(AutoCommitter::SETTINGS_KEY, SettingType::Enum, AutoCommitter::MODE_OFF)
                ->withCategory(SettingCategory::Git)
                ->withRiskClass(RiskClass::Exec)
                ->withLayered()
                ->withEnumValues(AutoCommitter::MODES)
                ->withLabel(Lang::t('settings.autoCommit.label'))
                ->withHelp('off, turn (one commit per turn, message from the title model) or edit (one per Write/Edit); /undo reverts it.')
                ->withReaderSymbol(Bootstrap::class . '::hooks')
                ->withReadBy('`Bootstrap::hooks()` → `AutoCommitHook`; `Chat` (turn mode)'),
        ];
    }
}
