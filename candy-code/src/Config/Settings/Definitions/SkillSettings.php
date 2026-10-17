<?php

declare(strict_types=1);

namespace SugarCraft\Code\Config\Settings\Definitions;

use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Config\Settings\ApplyMode;
use SugarCraft\Code\Config\Settings\OptionsSource;
use SugarCraft\Code\Config\Settings\RiskClass;
use SugarCraft\Code\Config\Settings\SettingCategory;
use SugarCraft\Code\Config\Settings\SettingDefinition;
use SugarCraft\Code\Config\Settings\SettingDefinitionSet;
use SugarCraft\Code\Config\Settings\SettingType;
use SugarCraft\Code\Config\Settings\UiEditability;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Skills\SkillMatcher;

/**
 * The "Skills" category's keys. One file per category so a step adding a
 * key edits only its category's file (DH-KEYS); {@see \SugarCraft\Code\Config\Settings\SettingsSchema}
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
            SettingDefinition::new(SkillMatcher::SETTING_LISTING_MAX_BYTES, SettingType::Int, SkillMatcher::DEFAULT_LISTING_MAX_BYTES)
                ->withCategory(SettingCategory::Skills)
                ->withRiskClass(RiskClass::Prompt)
                ->withApplyMode(ApplyMode::NextTurn)
                ->withRange(1)
                ->withLabel(Lang::t('settings.skills.listingMaxBytes.label'))
                ->withHelp('Byte budget for the whole skill listing in the system prompt; skills past it collapse into one "N more skills" line.')
                ->withReaderSymbol(SkillMatcher::class . '::listForPrompt')
                ->withReadBy('`Runtime::systemPromptSections()` → `SkillMatcher::listForPrompt()`, per prompt build'),
        ];
    }
}
