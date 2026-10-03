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
        ];
    }
}
