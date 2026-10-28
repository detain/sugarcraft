<?php

declare(strict_types=1);

namespace SugarCraft\Code\Config\Settings\Definitions;

use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Config\Settings\ApplyMode;
use SugarCraft\Code\Config\Settings\OptionsSource;
use SugarCraft\Code\Config\Settings\RiskClass;
use SugarCraft\Code\Config\Settings\SettingCategory;
use SugarCraft\Code\Config\Settings\SettingDefinition;
use SugarCraft\Code\Config\Settings\SettingDefinitionSet;
use SugarCraft\Code\Config\Settings\SettingType;
use SugarCraft\Code\Config\Settings\UiEditability;
use SugarCraft\Code\Context\InstructionFileLoader;
use SugarCraft\Code\Context\MemoryBlock;
use SugarCraft\Code\Context\ProjectMemoryWriter;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Memory\AutoMemoryConsolidator;
use SugarCraft\Code\Memory\DreamPass;
use SugarCraft\Code\Runtime;
use SugarCraft\Code\Skills\SkillPathNudge;

/**
 * The "Memory & Rules" category's keys. One file per category so a step adding a
 * key edits only its category's file (DH-KEYS); {@see \SugarCraft\Code\Config\Settings\SettingsSchema}
 * collects every set.
 *
 * THE MEMORY KEYS (roadmap N-P4d, Appendix N §2.2) are the operator's alone
 * and read from `config.json` only, like `contextPruning.mode`: the index caps
 * decide how much note text — repository-shipped notes included — becomes
 * system prompt, and auto-memory is a billed call on the operator's
 * credential. Each default IS the constant it replaced, cited, not restated.
 * The caps are next turn — read by the turn's Runtime — and the auto-memory
 * switches when a turn settles. The project-note cap is live: it is read as
 * each note is written.
 *
 * THE RULE AND NUDGE KEYS (roadmap N-P4d remainder) follow the same rule:
 * `rules.standingMaxBytes` decides how much standing-rule text becomes
 * system prompt, so it is the operator's alone; `skills.pathNudges` sits
 * beside it because its nudge is the skill twin of the path-scoped rule
 * nudge, and turning it off only removes text, so a trusted project may.
 */
final class MemoryRuleSettings implements SettingDefinitionSet
{
    public static function category(): SettingCategory
    {
        return SettingCategory::MemoryRules;
    }

    public static function definitions(): array
    {
        $cap = static fn (string $key, int $default, string $label, string $help): SettingDefinition => SettingDefinition::new($key, SettingType::Int, $default)
            ->withCategory(SettingCategory::MemoryRules)
            ->withRiskClass(RiskClass::Prompt)
            ->withApplyMode(ApplyMode::NextTurn)
            ->withRange(1)
            ->withLabel($label)
            ->withHelp($help)
            ->withReaderSymbol(MemoryBlock::class . '::withSettings')
            ->withReadBy('`Runtime::memorySnapshot()` → `MemoryBlock::withSettings()`, per turn');

        return [
            SettingDefinition::new(Runtime::SETTING_STANDING_MAX_BYTES, SettingType::Int, Runtime::MAX_STANDING_RULE_BYTES)
                ->withCategory(SettingCategory::MemoryRules)
                ->withRiskClass(RiskClass::Prompt)
                ->withApplyMode(ApplyMode::NextTurn)
                ->withRange(1)
                ->withLabel(Lang::t('settings.rules.standingMaxBytes.label'))
                ->withHelp(Lang::t('settings.rules.standingMaxBytes.help'))
                ->withReaderSymbol(Runtime::class . '::systemPromptSections')
                ->withReadBy('`Runtime::systemPromptSections()`, per prompt build'),
            SettingDefinition::new(SkillPathNudge::SETTING_PATH_NUDGES, SettingType::Bool, true)
                ->withCategory(SettingCategory::MemoryRules)
                ->withRiskClass(RiskClass::Narrowing)
                ->withLayered()
                ->withProjectSettable()
                ->withApplyMode(ApplyMode::NextTurn)
                ->withLabel(Lang::t('settings.skills.pathNudges.label'))
                ->withHelp(Lang::t('settings.skills.pathNudges.help'))
                ->withReaderSymbol(SkillPathNudge::class . '::enabled')
                ->withReadBy('`SkillPathNudge::forPaths()` → `enabled()`, per tool call'),
            SettingDefinition::new(ProjectMemoryWriter::SETTING_MAX_CONTENT_BYTES, SettingType::Int, ProjectMemoryWriter::MAX_CONTENT_BYTES)
                ->withCategory(SettingCategory::MemoryRules)
                ->withRiskClass(RiskClass::Tuning)
                ->withApplyMode(ApplyMode::Live)
                ->withRange(1)
                ->withLabel(Lang::t('settings.memory.projectNoteMaxBytes.label'))
                ->withHelp(Lang::t('settings.memory.projectNoteMaxBytes.help'))
                ->withReaderSymbol(ProjectMemoryWriter::class . '::maxContentBytes')
                ->withReadBy('`ProjectMemoryWriter::write()` and the Memory tool\'s `save` → `maxContentBytes()`, per note'),
            $cap(MemoryBlock::SETTING_MAX_ENTRIES, MemoryBlock::MAX_ENTRIES, Lang::t('settings.memory.promptMaxEntries.label'), Lang::t('settings.memory.promptMaxEntries.help')),
            $cap(MemoryBlock::SETTING_MAX_BYTES, MemoryBlock::MAX_BYTES, Lang::t('settings.memory.promptMaxBytes.label'), Lang::t('settings.memory.promptMaxBytes.help')),
            $cap(MemoryBlock::SETTING_MAX_ENTRY_BYTES, MemoryBlock::MAX_ENTRY_BYTES, Lang::t('settings.memory.entryMaxBytes.label'), Lang::t('settings.memory.entryMaxBytes.help')),
            $cap(MemoryBlock::SETTING_USER_MAX_ENTRIES, MemoryBlock::USER_MAX_ENTRIES, Lang::t('settings.memory.userMaxEntries.label'), Lang::t('settings.memory.userMaxEntries.help')),
            $cap(MemoryBlock::SETTING_USER_MAX_BYTES, MemoryBlock::USER_MAX_BYTES, Lang::t('settings.memory.userMaxBytes.label'), Lang::t('settings.memory.userMaxBytes.help')),
            SettingDefinition::new(AutoMemoryConsolidator::SETTING, SettingType::Bool, true)
                ->withCategory(SettingCategory::MemoryRules)
                ->withRiskClass(RiskClass::Spend)
                ->withApplyMode(ApplyMode::NextTurn)
                ->withEnvVar(AutoMemoryConsolidator::ENV_DISABLE)
                ->withLabel(Lang::t('settings.memory.autoConsolidate.label'))
                ->withHelp(Lang::t('settings.memory.autoConsolidate.help'))
                ->withReaderSymbol(AutoMemoryConsolidator::class . '::enabled')
                ->withReadBy('`AutoMemoryConsolidator::call()`, `DreamPass::call()` → `AutoMemoryConsolidator::enabled()`, as a turn settles'),
            SettingDefinition::new(DreamPass::SETTING_INTERVAL, SettingType::Int, DreamPass::INTERVAL_SECONDS)
                ->withCategory(SettingCategory::MemoryRules)
                ->withRiskClass(RiskClass::Spend)
                ->withApplyMode(ApplyMode::NextTurn)
                ->withRange(DreamPass::MIN_INTERVAL_SECONDS)
                ->withLabel(Lang::t('settings.memory.dreamIntervalSeconds.label'))
                ->withHelp(Lang::t('settings.memory.dreamIntervalSeconds.help'))
                ->withReaderSymbol(DreamPass::class . '::interval')
                ->withReadBy('`DreamPass::call()` → `interval()`, as a turn settles'),
            SettingDefinition::new(DreamPass::SETTING_PROPOSE_SKILLS, SettingType::Bool, false)
                ->withCategory(SettingCategory::MemoryRules)
                ->withRiskClass(RiskClass::Prompt)
                ->withApplyMode(ApplyMode::NextTurn)
                ->withLabel(Lang::t('settings.memory.dreamProposeSkills.label'))
                ->withHelp(Lang::t('settings.memory.dreamProposeSkills.help'))
                ->withReaderSymbol(DreamPass::class . '::proposesSkills')
                ->withReadBy('`DreamPass::call()` → `proposesSkills()`, as a turn settles'),
            SettingDefinition::new('instructions', SettingType::StringList, [])
                ->withCategory(SettingCategory::MemoryRules)
                ->withRiskClass(RiskClass::Prompt)
                ->withLayered()
                ->withUi(UiEditability::List)
                ->withLabel(Lang::t('settings.instructions.label'))
                ->withHelp(Lang::t('settings.instructions.help'))
                ->withReaderSymbol(Bootstrap::class . '::forcedInstructions'),
            // C-CTX-4.c: the flag widens ancestorRoot()'s climb past the
            // nearest .git toward (never into) $HOME; the tier split in
            // forcedInstructions()/projectForcedInstructions() is what makes
            // loadForced() accept home-anchored absolute and `~` spellings from
            // this operator's config alone. No withProjectSettable — a
            // checked-out repo may not widen its own containment.
            SettingDefinition::new(InstructionFileLoader::WALK_TO_ROOT_SETTINGS_KEY, SettingType::Bool, false)
                ->withCategory(SettingCategory::MemoryRules)
                ->withRiskClass(RiskClass::Prompt)
                ->withLayered()
                ->withLabel(Lang::t('settings.instructions.walkToRoot.label'))
                ->withHelp(Lang::t('settings.instructions.walkToRoot.help'))
                ->withReaderSymbol(Bootstrap::class . '::instructionLoader'),
            SettingDefinition::new('instructionsExclude', SettingType::StringList, [])
                ->withCategory(SettingCategory::MemoryRules)
                ->withRiskClass(RiskClass::Prompt)
                ->withLayered()
                ->withUi(UiEditability::List)
                ->withLabel(Lang::t('settings.instructionsExclude.label'))
                ->withHelp(Lang::t('settings.instructionsExclude.help'))
                ->withReaderSymbol(Bootstrap::class . '::excludedInstructions'),
            SettingDefinition::new('disabledRules', SettingType::StringList, [])
                ->withCategory(SettingCategory::MemoryRules)
                ->withRiskClass(RiskClass::Prompt)
                ->withLayered()
                ->withUi(UiEditability::List)
                ->withOptionsSource(OptionsSource::RulePacks)
                ->withLabel(Lang::t('settings.disabledRules.label'))
                ->withHelp(Lang::t('settings.disabledRules.help'))
                ->withReaderSymbol(Bootstrap::class . '::rulePacksToDisable')
                ->withReadBy('`Bootstrap::chat()` → `RulesState::new()`'),
            SettingDefinition::new('embeddingModel', SettingType::String)
                ->withCategory(SettingCategory::MemoryRules)
                ->withRiskClass(RiskClass::Spend)
                ->withLayered()
                ->withApplyMode(ApplyMode::NextTurn)
                ->withLabel(Lang::t('settings.embeddingModel.label'))
                ->withHelp(Lang::t('settings.embeddingModel.help'))
                ->withReaderSymbol(EngineBackend::class . '::completeAsync'),
        ];
    }
}
