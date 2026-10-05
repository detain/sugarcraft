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
use SugarCraft\Crush\Lang;

/**
 * The "Skills" category's keys. One file per category so a step adding a
 * key edits only its category's file (DH-KEYS); {@see \SugarCraft\Crush\Config\Settings\SettingsSchema}
 * collects every set.
 */
final class SkillSettings implements SettingDefinitionSet
{
    public static function category(): SettingCategory
    {
        return SettingCategory::Skills;
    }

    public static function definitions(): array
    {
        return [
            SettingDefinition::new('disabledSkills', SettingType::StringList, [])
                ->withCategory(SettingCategory::Skills)
                ->withRiskClass(RiskClass::Narrowing)
                ->withLayered()
                ->withProjectSettable()
                ->withUi(UiEditability::List)
                ->withOptionsSource(OptionsSource::Skills)
                ->withLabel(Lang::t('settings.disabledSkills.label'))
                ->withHelp('Skills removed from discovery; also wins over enabledSkills.')
                ->withReaderSymbol(Bootstrap::class . '::skillRegistry')
                ->withReadBy('`Bootstrap::chat()` → `skillRegistry()`'),
            SettingDefinition::new('enabledSkills', SettingType::StringList, [])
                ->withCategory(SettingCategory::Skills)
                ->withRiskClass(RiskClass::Prompt)
                ->withLayered()
                ->withUi(UiEditability::List)
                ->withOptionsSource(OptionsSource::Skills)
                ->withLabel(Lang::t('settings.enabledSkills.label'))
                ->withHelp('Skills whose full bodies ride the system prompt every turn.')
                ->withReaderSymbol(Bootstrap::class . '::promptEnabledSkills')
                ->withReadBy('`Bootstrap::backend()`, `backendFor()` → `promptEnabledSkills()`'),
        ];
    }
}
