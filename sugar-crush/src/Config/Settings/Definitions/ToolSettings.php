<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Config\Settings\Definitions;

use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Config\Settings\OptionsSource;
use SugarCraft\Crush\Config\Settings\RiskClass;
use SugarCraft\Crush\Config\Settings\SettingCategory;
use SugarCraft\Crush\Config\Settings\SettingDefinition;
use SugarCraft\Crush\Config\Settings\SettingDefinitionSet;
use SugarCraft\Crush\Config\Settings\SettingType;
use SugarCraft\Crush\Config\Settings\UiEditability;
use SugarCraft\Crush\Lint\TestRunner;
use SugarCraft\Crush\Tools\BuiltIn\Bash;
use SugarCraft\Crush\Tools\Sandbox\Bubblewrap;

/**
 * The "Tools" category's keys. One file per category so a step adding a
 * key edits only its category's file (DH-KEYS); {@see \SugarCraft\Crush\Config\Settings\SettingsSchema}
 * collects every set.
 */
final class ToolSettings implements SettingDefinitionSet
{
    public static function category(): SettingCategory
    {
        return SettingCategory::Tools;
    }

    public static function definitions(): array
    {
        return [
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
            // User tier only: `off` is the direction that widens, and a
            // checked-out repository must not be able to take it.
            SettingDefinition::new('bashSandbox', SettingType::Enum, Bubblewrap::MODE_OFF)
                ->withCategory(SettingCategory::Tools)
                ->withRiskClass(RiskClass::Security)
                ->withLayered()
                ->withEnumValues(Bubblewrap::MODES)
                ->withLabel('Bash sandbox')
                ->withHelp('Linux only: run Bash inside bubblewrap, writable only in the working root (`no-network` also cuts the network). Refuses commands when bwrap cannot start.')
                ->withReaderSymbol(Bash::class . '::fromCatalog')
                ->withReadBy('`Bash::fromCatalog()` → `Bubblewrap::fromSetting()`'),
            // Step 3.H. Both user tier only: the command is shell the
            // after-the-turn run executes with no tool call and no gate in
            // the path, so a checkout must neither name it nor switch it on
            // (`lintCommands`' argument).
            SettingDefinition::new(TestRunner::SETTINGS_KEY, SettingType::String)
                ->withCategory(SettingCategory::Tools)
                ->withRiskClass(RiskClass::Exec)
                ->withLayered()
                ->withLabel('Test command')
                ->withHelp('Shell command that runs the project\'s tests, in the project root (`composer test`, `pytest -q`); what `autoTest` runs.')
                ->withReaderSymbol(Bootstrap::class . '::hooks')
                ->withReadBy('`Bootstrap::hooks()` → `TestRunner::withCommand()`'),
            SettingDefinition::new(TestRunner::AUTO_TEST_SETTINGS_KEY, SettingType::Bool, false)
                ->withCategory(SettingCategory::Tools)
                ->withRiskClass(RiskClass::Exec)
                ->withLayered()
                ->withLabel('Auto-test')
                ->withHelp('After a turn that edited a file, run `testCommand`; on failure the output goes back to the model, at most 3 times a turn.')
                ->withReaderSymbol(Bootstrap::class . '::hooks')
                ->withReadBy('`Bootstrap::hooks()` → `AutoTestHook`'),
        ];
    }
}
