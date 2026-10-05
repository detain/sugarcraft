<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Config\Settings\Definitions;

use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Config\Settings\ApplyMode;
use SugarCraft\Crush\Config\Settings\OptionsSource;
use SugarCraft\Crush\Config\Settings\RiskClass;
use SugarCraft\Crush\Config\Settings\SettingCategory;
use SugarCraft\Crush\Config\Settings\SettingDefinition;
use SugarCraft\Crush\Config\Settings\SettingDefinitionSet;
use SugarCraft\Crush\Config\Settings\SettingType;
use SugarCraft\Crush\Config\Settings\UiEditability;
use SugarCraft\Crush\Context\MemoryBlock;
use SugarCraft\Crush\Context\ProjectMemoryWriter;
use SugarCraft\Crush\Memory\AutoMemoryConsolidator;
use SugarCraft\Crush\Memory\DreamPass;
use SugarCraft\Crush\Runtime;
use SugarCraft\Crush\Skills\SkillPathNudge;

/**
 * The "Memory & Rules" category's keys. One file per category so a step adding a
 * key edits only its category's file (DH-KEYS); {@see \SugarCraft\Crush\Config\Settings\SettingsSchema}
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
                ->withLabel('Standing rules: bytes')
                ->withHelp('Byte budget, framed and escaped, for the rule files spliced whole into every prompt; a rule past it is named by one pointer line instead.')
                ->withReaderSymbol(Runtime::class . '::systemPromptSections')
                ->withReadBy('`Runtime::systemPromptSections()`, per prompt build'),
            SettingDefinition::new(SkillPathNudge::SETTING_PATH_NUDGES, SettingType::Bool, true)
                ->withCategory(SettingCategory::MemoryRules)
                ->withRiskClass(RiskClass::Narrowing)
                ->withLayered()
                ->withProjectSettable()
                ->withApplyMode(ApplyMode::NextTurn)
                ->withLabel('Skill path nudges')
                ->withHelp('When a tool touches a file a path-scoped skill covers, remind the model that skill exists, once per skill.')
                ->withReaderSymbol(SkillPathNudge::class . '::enabled')
                ->withReadBy('`SkillPathNudge::forPaths()` → `enabled()`, per tool call'),
            SettingDefinition::new(ProjectMemoryWriter::SETTING_MAX_CONTENT_BYTES, SettingType::Int, ProjectMemoryWriter::MAX_CONTENT_BYTES)
                ->withCategory(SettingCategory::MemoryRules)
                ->withRiskClass(RiskClass::Tuning)
                ->withApplyMode(ApplyMode::Live)
                ->withRange(1)
                ->withLabel('Project note: max bytes')
                ->withHelp('Largest one project memory note may be when it is written to the repository\'s .sugar-crush/memory/.')
                ->withReaderSymbol(ProjectMemoryWriter::class . '::maxContentBytes')
                ->withReadBy('`ProjectMemoryWriter::write()` → `maxContentBytes()`, per note'),
            $cap(MemoryBlock::SETTING_MAX_ENTRIES, MemoryBlock::MAX_ENTRIES, 'Memory index: notes', 'Most notes the prompt\'s memory index lists, newest first; user notes count inside it.'),
            $cap(MemoryBlock::SETTING_MAX_BYTES, MemoryBlock::MAX_BYTES, 'Memory index: bytes', 'Byte budget for the memory index\'s note lines; the user-note budget is lowered to fit inside it.'),
            $cap(MemoryBlock::SETTING_MAX_ENTRY_BYTES, MemoryBlock::MAX_ENTRY_BYTES, 'Memory index: bytes per note', 'Longest one note\'s index line may be before it is shown truncated; lowered to the user-note budget if over it.'),
            $cap(MemoryBlock::SETTING_USER_MAX_ENTRIES, MemoryBlock::USER_MAX_ENTRIES, 'Memory index: user notes', 'Most of your cross-project (user-scope) notes listed first, inside the note count.'),
            $cap(MemoryBlock::SETTING_USER_MAX_BYTES, MemoryBlock::USER_MAX_BYTES, 'Memory index: user-note bytes', 'Byte budget for the user-scope lines, inside the total budget.'),
            SettingDefinition::new(AutoMemoryConsolidator::SETTING, SettingType::Bool, true)
                ->withCategory(SettingCategory::MemoryRules)
                ->withRiskClass(RiskClass::Spend)
                ->withApplyMode(ApplyMode::NextTurn)
                ->withEnvVar(AutoMemoryConsolidator::ENV_DISABLE)
                ->withLabel('Auto-memory')
                ->withHelp('Save durable facts from finished turns into memory, and fold the compaction journal into it (the dream pass); each is a billed call.')
                ->withReaderSymbol(AutoMemoryConsolidator::class . '::enabled')
                ->withReadBy('`AutoMemoryConsolidator::call()`, `DreamPass::call()` → `AutoMemoryConsolidator::enabled()`, as a turn settles'),
            SettingDefinition::new(DreamPass::SETTING_INTERVAL, SettingType::Int, DreamPass::INTERVAL_SECONDS)
                ->withCategory(SettingCategory::MemoryRules)
                ->withRiskClass(RiskClass::Spend)
                ->withApplyMode(ApplyMode::NextTurn)
                ->withRange(DreamPass::MIN_INTERVAL_SECONDS)
                ->withLabel('Dream pass interval (s)')
                ->withHelp('Least time between two dream passes for one project; each pass is a billed, read-only turn.')
                ->withReaderSymbol(DreamPass::class . '::interval')
                ->withReadBy('`DreamPass::call()` → `interval()`, as a turn settles'),
            SettingDefinition::new(DreamPass::SETTING_PROPOSE_SKILLS, SettingType::Bool, false)
                ->withCategory(SettingCategory::MemoryRules)
                ->withRiskClass(RiskClass::Prompt)
                ->withApplyMode(ApplyMode::NextTurn)
                ->withLabel('Dream pass: propose skills')
                ->withHelp('Let the dream pass propose skills as drafts in ~/.sugar-crush/skills-proposed; none is live until you /skills accept it.')
                ->withReaderSymbol(DreamPass::class . '::proposesSkills')
                ->withReadBy('`DreamPass::call()` → `proposesSkills()`, as a turn settles'),
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
            SettingDefinition::new('embeddingModel', SettingType::String)
                ->withCategory(SettingCategory::MemoryRules)
                ->withRiskClass(RiskClass::Spend)
                ->withLayered()
                ->withApplyMode(ApplyMode::NextTurn)
                ->withLabel('Embedding model')
                ->withHelp('Embedding model for the per-turn memory recall; unset ranks notes by keyword only.')
                ->withReaderSymbol(EngineBackend::class . '::completeAsync'),
        ];
    }
}
