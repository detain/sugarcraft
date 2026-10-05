<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Config\Settings\Definitions;

use SugarCraft\Crush\Config\Settings\ApplyMode;
use SugarCraft\Crush\Config\Settings\RiskClass;
use SugarCraft\Crush\Config\Settings\SettingCategory;
use SugarCraft\Crush\Config\Settings\SettingDefinition;
use SugarCraft\Crush\Config\Settings\SettingDefinitionSet;
use SugarCraft\Crush\Config\Settings\SettingType;
use SugarCraft\Crush\Config\Settings\UiEditability;
use SugarCraft\Crush\Config\Settings\Validator\ThresholdOrderValidator;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Context\CompactorConfig;
use SugarCraft\Crush\Context\EnvironmentBlock;
use SugarCraft\Crush\Context\IdleCompactionPolicy;
use SugarCraft\Crush\Context\Pruning\PruningMode;
use SugarCraft\Crush\Context\RepoMapBlock;
use SugarCraft\Crush\Context\SymbolMapBlock;
use SugarCraft\Crush\Providers\ProviderFactory;

/**
 * The "Context" category's keys. One file per category so a step adding a
 * key edits only its category's file (DH-KEYS); {@see \SugarCraft\Crush\Config\Settings\SettingsSchema}
 * collects every set.
 *
 * THE COMPACTION KEYS (roadmap N-P4b, Appendix N §2.2) are dotted flat keys
 * on disk (`"compaction.autoPercent": 80`), Appendix N §5.5's recommendation,
 * so the shallow layer merge never replaces one with another. Each default IS
 * the {@see CompactorConfig} constructor default it describes, read off a
 * fresh {@see CompactorConfig::new()} rather than restated. `Bootstrap::chat()`
 * hands the session one config built from them and a save rebuilds it
 * (`Chat::applySettings()`), so in the TUI they apply LIVE, to the next
 * prompt; a backend handed none — `-p`, an embedder — reads them itself, and
 * a server session reads them when it starts. The `contextPruning.*`
 * reminders ride the same config into the engine with each dispatch, so
 * they apply NEXT TURN. Every one but the breaker's limit is Tuning a
 * trusted project may set (Appendix N §2.2): a tier moved either way costs
 * the session time or context quality, never a refusal it could not get out
 * of — only the blocking percentage refuses, it is bounded at 99%, and
 * `compaction.mode: off` still leaves `/compact` to the person. The
 * breaker's limit is Spend and the operator's: raised, it lets a session
 * pay for more compactions that buy nothing.
 */
final class ContextSettings implements SettingDefinitionSet
{
    public static function category(): SettingCategory
    {
        return SettingCategory::Context;
    }

    public static function definitions(): array
    {
        $defaults = CompactorConfig::new();
        $order = ThresholdOrderValidator::new([
            CompactorConfig::SETTING_REMINDER_PERCENT,
            CompactorConfig::SETTING_AUTO_PERCENT,
            CompactorConfig::SETTING_BLOCK_PERCENT,
        ]);
        $readBy = '`Bootstrap::chat()`, `Chat::applySettings()`, `EngineBackend::compactorConfig()` → `CompactorConfig::fromSettings()`';
        $tier = static fn (string $key, int $default, string $label, string $help): SettingDefinition => SettingDefinition::new($key, SettingType::Int, $default)
            ->withCategory(SettingCategory::Context)
            ->withRiskClass(RiskClass::Tuning)
            ->withLayered()
            ->withProjectSettable()
            ->withApplyMode(ApplyMode::Live)
            ->withRange(1, 99)
            ->withValidators($order)
            ->withLabel($label)
            ->withHelp($help)
            ->withReaderSymbol(CompactorConfig::class . '::fromSettings')
            ->withReadBy($readBy);
        $tuning = static fn (string $key, int $default, string $label, string $help): SettingDefinition => SettingDefinition::new($key, SettingType::Int, $default)
            ->withCategory(SettingCategory::Context)
            ->withRiskClass(RiskClass::Tuning)
            ->withLayered()
            ->withProjectSettable()
            ->withApplyMode(ApplyMode::Live)
            ->withRange(1)
            ->withLabel($label)
            ->withHelp($help)
            ->withReaderSymbol(CompactorConfig::class . '::fromSettings')
            ->withReadBy($readBy);
        $nudge = static fn (string $key, int $default, string $label, string $help): SettingDefinition => SettingDefinition::new($key, SettingType::Int, $default)
            ->withCategory(SettingCategory::Context)
            ->withRiskClass(RiskClass::Tuning)
            ->withLayered()
            ->withProjectSettable()
            ->withApplyMode(ApplyMode::NextTurn)
            ->withRange(1)
            ->withLabel($label)
            ->withHelp($help)
            ->withReaderSymbol(CompactorConfig::class . '::nudgePolicy')
            ->withReadBy($readBy . ' → `nudgePolicy()`, the engine\'s step loop');
        $cap = static fn (string $key, ?int $default, string $label, string $help): SettingDefinition => SettingDefinition::new($key, SettingType::Int, $default)
            ->withCategory(SettingCategory::Context)
            ->withRiskClass(RiskClass::Tuning)
            ->withLayered()
            ->withProjectSettable()
            ->withApplyMode(ApplyMode::Live)
            ->withRange(0)
            ->withLabel($label)
            ->withHelp($help)
            ->withReaderSymbol(CompactorConfig::class . '::fromSettings')
            ->withReadBy($readBy);

        return [
            $tier(CompactorConfig::SETTING_REMINDER_PERCENT, $defaults->reminderThreshold, 'Reminder at (%)', 'Window share at which the "consider compacting" reminder and the ahead-of-need background summary start; below the automatic tier.'),
            $tier(CompactorConfig::SETTING_AUTO_PERCENT, $defaults->backgroundCompactionThreshold, 'Auto-compact at (%)', 'Window share at which older exchanges are condensed automatically before the prompt is sent.'),
            $tier(CompactorConfig::SETTING_BLOCK_PERCENT, $defaults->foregroundBlockingThreshold, 'Block input at (%)', 'Window share past which a prompt is refused until space is freed; the only tier that refuses.'),
            $tuning(CompactorConfig::SETTING_KEEP_RECENT, $defaults->recentPreserveCount, 'Keep recent exchanges', 'Most recent user/assistant exchanges a compaction keeps in full.'),
            $tuning(CompactorConfig::SETTING_SUMMARY_USER_CHARS, $defaults->summaryUserMaxChars, 'Summary: user chars', 'Characters of a user message the heuristic summary line keeps.'),
            $tuning(CompactorConfig::SETTING_SUMMARY_ASSISTANT_CHARS, $defaults->summaryAssistantMaxChars, 'Summary: assistant chars', 'Assistant replies longer than this become "[exchanged information]" in a heuristic summary line.'),
            $tuning(CompactorConfig::SETTING_TOOL_OUTPUT_CHARS, $defaults->toolOutputMaxChars, 'Summary: tool output chars', 'Characters of a condensed exchange\'s assistant half the summariser model is shown.'),
            $cap(CompactorConfig::SETTING_REMINDER_TOKENS, $defaults->reminderTokens, 'Reminder cap (tokens)', 'Absolute cap on the reminder tier: it fires at min(percentage, this). 0 turns the cap off.'),
            $cap(CompactorConfig::SETTING_AUTO_TOKENS, $defaults->backgroundCompactionTokens, 'Auto-compact cap (tokens)', 'Absolute cap on the automatic tier and the in-turn step budget. 0 turns the cap off.'),
            $cap(CompactorConfig::SETTING_BLOCK_TOKENS, $defaults->foregroundBlockingTokens, 'Block cap (tokens)', 'Absolute cap on the blocking tier; unset by default. Setting it lets a cap refuse prompts.')
                ->withDefaultText('unset (no cap)'),
            SettingDefinition::new(CompactorConfig::SETTING_MODEL_TOKEN_CAPS, SettingType::Map, [])
                ->withCategory(SettingCategory::Context)
                ->withRiskClass(RiskClass::Tuning)
                ->withLayered()
                ->withProjectSettable()
                ->withApplyMode(ApplyMode::Live)
                ->withUi(UiEditability::Complex)
                ->withLabel('Per-model caps')
                ->withHelp('{"<model>" or "<provider>/<model>": {"reminderTokens", "autoTokens", "blockTokens"}} overriding the three caps; 0 clears one.')
                ->withReaderSymbol(CompactorConfig::class . '::fromSettings')
                ->withReadBy($readBy . ' → `forModel()`'),
            // Roadmap N-P4b remainder: the idle offer, the summary mode and
            // the thrash breaker, each the constant or behaviour it replaced.
            SettingDefinition::new(CompactorConfig::SETTING_IDLE_OFFER_SECONDS, SettingType::Int, $defaults->idleOfferSeconds)
                ->withCategory(SettingCategory::Context)
                ->withRiskClass(RiskClass::Tuning)
                ->withLayered()
                ->withProjectSettable()
                ->withApplyMode(ApplyMode::Live)
                ->withRange(0)
                ->withLabel('Offer /compact after idle (s)')
                ->withHelp('A session past its whole context window that sat untouched this long is offered /compact instead of sending the prompt. 0 never offers.')
                ->withReaderSymbol(IdleCompactionPolicy::class . '::shouldPrompt')
                ->withReadBy($readBy . ' → `Chat::shouldPromptIdleCompaction()` → `IdleCompactionPolicy::shouldPrompt()`'),
            SettingDefinition::new(CompactorConfig::SETTING_MODE, SettingType::Enum, $defaults->mode)
                ->withCategory(SettingCategory::Context)
                ->withRiskClass(RiskClass::Tuning)
                ->withLayered()
                ->withProjectSettable()
                ->withApplyMode(ApplyMode::Live)
                ->withEnumValues(CompactorConfig::MODES)
                ->withLabel('Compaction mode')
                ->withHelp('llm: the summary model writes the summaries (the heuristic is its fallback); heuristic: local one-line summaries, never a model call; off: nothing compacts on its own, /compact still works and the blocking tier still refuses.')
                ->withReaderSymbol(CompactorConfig::class . '::summarisesWithModel')
                ->withReadBy($readBy . ' → `autoCompacts()`, `summarisesWithModel()`'),
            SettingDefinition::new(CompactorConfig::SETTING_REFILL_LIMIT, SettingType::Int, $defaults->refillLimit)
                ->withCategory(SettingCategory::Context)
                ->withRiskClass(RiskClass::Spend)
                ->withLayered()
                ->withApplyMode(ApplyMode::Live)
                ->withRange(1)
                ->withLabel('Thrash breaker limit')
                ->withHelp('Automatic compactions in a row that may come straight back over their tier, prompt unsent, before the next prompt is refused instead of compacted again.')
                ->withReaderSymbol(IdleCompactionPolicy::class . '::thrashTripped')
                ->withReadBy($readBy . ' → `IdleCompactionPolicy::thrashTripped()`'),
            // Roadmap 3.B-4 / N-P4b: the model's context reminders. Read with
            // the compaction keys and handed to the engine's step loop through
            // `CompactorConfig::nudgePolicy()`; a min above the max is ignored
            // as a pair, so neither half of a typo moves alone.
            $nudge(CompactorConfig::SETTING_NUDGE_MIN_TOKENS, $defaults->nudgeMinContextTokens, 'Reminders from (tokens)', 'Context size below which the model is never reminded to prune; must not exceed the hard-reminder size.'),
            $nudge(CompactorConfig::SETTING_NUDGE_MAX_TOKENS, $defaults->nudgeMaxContextTokens, 'Hard reminder at (tokens)', 'Context size above which the newest row carries the stronger "prune now" reminder.'),
            $nudge(CompactorConfig::SETTING_NUDGE_FREQUENCY, $defaults->nudgeFrequency, 'Rows between reminders', 'New rows a reminder waits for after the last one, so it does not repeat on every step.'),
            $nudge(CompactorConfig::SETTING_NUDGE_ITERATIONS, $defaults->nudgeIterationThreshold, 'Reminder after tool results', 'Tool results since the last prompt after which a long tool loop is reminded to prune.'),
            // Whether the model's Compress is offered unprompted. config.json
            // only, like contextPruning.mode: DCP #611 — under pressure a model
            // compresses content still needed — so only the person turns it on.
            SettingDefinition::new(CompactorConfig::SETTING_COMPRESS, SettingType::Enum, \SugarCraft\Crush\Tools\BuiltIn\Compress::MODE_DEFAULT)
                ->withCategory(SettingCategory::Context)
                ->withRiskClass(RiskClass::Tuning)
                ->withApplyMode(ApplyMode::NextTurn)
                ->withEnumValues(CompactorConfig::COMPRESS_MODES)
                ->withLabel('Model compression')
                ->withHelp('manual offers Compress only on a turn you start with /compress, one call; auto offers it on every turn where the model may prune.')
                ->withReaderSymbol(CompactorConfig::class . '::offersCompressUnprompted')
                ->withReadBy('`Chat::applySettings()`, `EngineBackend::gatedLedgerTools()` → `CompactorConfig::offersCompressUnprompted()`'),
            // Roadmap N-P4d: the persisted form of SUGARCRUSH_DISABLE_SYMBOL_MAP.
            // config.json only, like the env switch it mirrors; captured once
            // per session, so it applies to the next one.
            SettingDefinition::new(SymbolMapBlock::SETTING, SettingType::Bool, true)
                ->withCategory(SettingCategory::Context)
                ->withRiskClass(RiskClass::Narrowing)
                ->withApplyMode(ApplyMode::Restart)
                ->withEnvVar(SymbolMapBlock::SYMBOL_MAP_OPT_OUT_ENV)
                ->withLabel('Symbol map')
                ->withHelp('Put the ranked symbol-level repo map in the system prompt, captured once per session.')
                ->withReaderSymbol(SymbolMapBlock::class . '::disabledBySettings')
                ->withReadBy('`SymbolMapBlock::capture()` → `disabledBySettings()`, at the session\'s first turn'),
            // Roadmap N-P4d: the repo map and the `<env>` git sections. Each is
            // laid over the session's capture once per turn by Runtime, so a
            // saved change applies next turn. Switching a block off only
            // removes text, so a trusted project may; raising a byte cap
            // bills more input on every request, so that half is the
            // operator's (the toolOutputCapBytes argument).
            SettingDefinition::new(RepoMapBlock::SETTING_ENABLED, SettingType::Bool, true)
                ->withCategory(SettingCategory::Context)
                ->withRiskClass(RiskClass::Narrowing)
                ->withLayered()
                ->withProjectSettable()
                ->withApplyMode(ApplyMode::NextTurn)
                ->withLabel('Repo map')
                ->withHelp('Put the map of where code lives (packages and PSR-4 source directories) in the system prompt.')
                ->withReaderSymbol(RepoMapBlock::class . '::enabledBySettings')
                ->withReadBy('`Runtime::repoMapSnapshot()` → `RepoMapBlock::enabledBySettings()`, per turn'),
            SettingDefinition::new(RepoMapBlock::SETTING_MAX_BYTES, SettingType::Int, RepoMapBlock::MAX_SECTION_BYTES)
                ->withCategory(SettingCategory::Context)
                ->withRiskClass(RiskClass::Spend)
                ->withLayered()
                ->withApplyMode(ApplyMode::NextTurn)
                ->withRange(RepoMapBlock::MAX_ENTRY_BYTES)
                ->withLabel('Repo map: bytes per section')
                ->withHelp('Byte budget for each of the repo map\'s two sections; entries past it are counted, not listed.')
                ->withReaderSymbol(RepoMapBlock::class . '::withSettings')
                ->withReadBy('`Runtime::repoMapSnapshot()` → `RepoMapBlock::withSettings()`, per turn'),
            SettingDefinition::new(EnvironmentBlock::SETTING_GIT_DIFF_AFTER_WRITES, SettingType::Bool, true)
                ->withCategory(SettingCategory::Context)
                ->withRiskClass(RiskClass::Narrowing)
                ->withLayered()
                ->withProjectSettable()
                ->withApplyMode(ApplyMode::NextTurn)
                ->withLabel('Git diff after writes')
                ->withHelp('After a step that wrote files, show the staged and unstaged git diffs in the turn context.')
                ->withReaderSymbol(EnvironmentBlock::class . '::withSettings')
                ->withReadBy('`Runtime::environmentSnapshot()` → `EnvironmentBlock::withSettings()`, per turn'),
            SettingDefinition::new(EnvironmentBlock::SETTING_DIFF_MAX_BYTES, SettingType::Int, EnvironmentBlock::DIFF_MAX_BYTES)
                ->withCategory(SettingCategory::Context)
                ->withRiskClass(RiskClass::Spend)
                ->withLayered()
                ->withApplyMode(ApplyMode::NextTurn)
                ->withRange(EnvironmentBlock::MIN_DIFF_MAX_BYTES)
                ->withLabel('Git diff: bytes per section')
                ->withHelp('Bytes each of the two git diff sections keeps before it is truncated with a note.')
                ->withReaderSymbol(EnvironmentBlock::class . '::withSettings')
                ->withReadBy('`Runtime::environmentSnapshot()` → `EnvironmentBlock::withSettings()`, per turn'),
            // Roadmap N-P4d: the launch-notice shelf. config.json only, read
            // while the launch records its notices, so it applies at restart.
            SettingDefinition::new(Bootstrap::SETTING_LAUNCH_NOTICE_LIMIT, SettingType::Int, Bootstrap::LAUNCH_NOTICE_LIMIT)
                ->withCategory(SettingCategory::Context)
                ->withRiskClass(RiskClass::Tuning)
                ->withApplyMode(ApplyMode::Restart)
                ->withRange(0)
                ->withLabel('Launch notices in transcript')
                ->withHelp('Most launch warnings seated as transcript rows; the rest are counted in one "and N more" row, and stderr carries them all.')
                ->withReaderSymbol(Bootstrap::class . '::launchNoticeLimit')
                ->withReadBy('`Bootstrap::warnPermissionConfigInTranscript()` → `launchNoticeLimit()`, at launch'),
            SettingDefinition::new('contextWindow', SettingType::Json)
                ->withCategory(SettingCategory::Context)
                ->withRiskClass(RiskClass::Tuning)
                ->withLayered()
                ->withLabel('Context window')
                ->withHelp('Token window override: a count for the provider\'s model, or {"<model>": tokens}.')
                ->withReaderSymbol(ProviderFactory::class . '::createOpenAI')
                ->withReadBy('`ProviderFactory::createOpenAI()`, `createAnthropic()`, `createCustom()` → each provider\'s `contextWindow()`'),
            // Roadmap 3.B-2: the default for every session that has not set
            // its own with `/pruning`. User config only — a cloned project
            // must not be able to turn a person's pruning on or off.
            SettingDefinition::new(PruningMode::SETTING, SettingType::Enum, PruningMode::DEFAULT->value)
                ->withCategory(SettingCategory::Context)
                ->withRiskClass(RiskClass::Tuning)
                ->withApplyMode(ApplyMode::NextTurn)
                ->withEnvVar(PruningMode::ENV)
                ->withEnumValues(array_map(static fn (PruningMode $m): string => $m->value, PruningMode::cases()))
                ->withLabel('Context pruning')
                ->withHelp('auto prunes superseded rows at each turn start; manual only on /sweep; off shows no ref tags. /pruning overrides it per session.')
                ->withReaderSymbol(PruningMode::class . '::configured')
                ->withReadBy('`Host\\TurnRunner::start()` and `/pruning` → `PruningMode::configured()`'),
        ];
    }
}
