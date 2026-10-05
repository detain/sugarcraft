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
use SugarCraft\Crush\Memory\AutoMemoryConsolidator;
use SugarCraft\Crush\Memory\DreamPass;

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
 * Every one is next turn: the caps are read by the turn's Runtime, the
 * auto-memory switches when a turn settles.
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
