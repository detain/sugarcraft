<?php

declare(strict_types=1);

namespace SugarCraft\Code\Config\Settings\Definitions;

use SugarCraft\Code\Backend\QueueMode;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Commands\KeyBindingRegistry;
use SugarCraft\Code\Config\Settings\ApplyMode;
use SugarCraft\Code\Config\Settings\OptionsSource;
use SugarCraft\Code\Config\Settings\RiskClass;
use SugarCraft\Code\Config\Settings\SettingCategory;
use SugarCraft\Code\Config\Settings\SettingDefinition;
use SugarCraft\Code\Config\Settings\SettingDefinitionSet;
use SugarCraft\Code\Config\Settings\SettingType;
use SugarCraft\Code\Config\Settings\UiEditability;
use SugarCraft\Code\Config\Settings\Validator\KeyBindingsValidator;
use SugarCraft\Code\Config\Settings\Validator\ThemeNameValidator;
use SugarCraft\Code\Config\StatusLineCommand;
use SugarCraft\Code\Host\ResumeCostEstimate;
use SugarCraft\Code\Host\TitleService;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Renderer;
use SugarCraft\Code\Session\EnhancedSessionStore;
use SugarCraft\Code\Session\SessionStore;
use SugarCraft\Code\Share\JsonlTranscriptWriter;
use SugarCraft\Code\Support\AiCommentWatcher;
use SugarCraft\Code\Theme;
use SugarCraft\Code\Tui\AwayTracker;
use SugarCraft\Code\Tui\ScreenReaderMode;
use SugarCraft\Code\Tui\TerminalBackground;
use SugarCraft\Code\Tui\TerminalNotifier;

/**
 * The "Interface" category's keys. One file per category so a step adding a
 * key edits only its category's file (DH-KEYS); {@see \SugarCraft\Code\Config\Settings\SettingsSchema}
 * collects every set.
 *
 * Roadmap N-P4g promoted the TUI's own constants to keys here — what Enter
 * does mid-turn (`queueMode`, decision D6), the mouse, the wheel step, the
 * double-Esc window, the palette's recent list, the collapsed preview sizes
 * and the checkpoint cap. Each default IS the constant it replaced, cited
 * rather than restated. They are read through
 * {@see \SugarCraft\Code\Config\Settings\UiSettings}, which holds them for
 * the per-frame readers and drops them on every save, so they are all LIVE.
 * All but the checkpoint cap are cosmetic and project-settable like `theme`;
 * the cap decides how much of the operator's session history is kept.
 */
final class InterfaceSettings implements SettingDefinitionSet
{
    public static function category(): SettingCategory
    {
        return SettingCategory::Interface;
    }

    public static function definitions(): array
    {
        return [
            SettingDefinition::new('theme', SettingType::Enum, 'dark')
                ->withCategory(SettingCategory::Interface)
                ->withRiskClass(RiskClass::Cosmetic)
                ->withLayered()
                ->withProjectSettable()
                ->withApplyMode(ApplyMode::Live)
                ->withValidators(ThemeNameValidator::new())
                ->withOptionsSource(OptionsSource::Themes)
                ->withLabel(Lang::t('settings.theme.label'))
                ->withHelp(Lang::t('settings.theme.help'))
                ->withReaderSymbol(Bootstrap::class . '::chat'),
            SettingDefinition::new('statusLine', SettingType::Map)
                ->withCategory(SettingCategory::Interface)
                ->withRiskClass(RiskClass::Exec)
                ->withLayered()
                // N-P3: a save re-runs `StatusLineCommand::reconfigure()`.
                ->withApplyMode(ApplyMode::Live)
                ->withUi(UiEditability::Complex)
                ->withLabel(Lang::t('settings.statusLine.label'))
                ->withHelp(Lang::t('settings.statusLine.help'))
                ->withReaderSymbol(StatusLineCommand::class . '::fromSettings')
                ->withReadBy('`Bootstrap::chat()`, `Chat::applySettings()` → `StatusLineCommand::fromSettings()`'),
            SettingDefinition::new('layout', SettingType::Json)
                ->withCategory(SettingCategory::Interface)
                ->withRiskClass(RiskClass::Cosmetic)
                ->withLayered()
                ->withApplyMode(ApplyMode::Live)
                ->withUi(UiEditability::ReadOnly)
                ->withLabel(Lang::t('settings.layout.label'))
                ->withHelp(Lang::t('settings.layout.help'))
                ->withReaderSymbol(Bootstrap::class . '::app')
                ->withReadBy('`Bootstrap::app()` → `App::$dock` via `DockLayout::fromArray()`'),
            // Roadmap 5.14a. Cosmetic and project-settable like `theme`: it
            // only decides which bytes tell THIS terminal a turn is over.
            SettingDefinition::new(TerminalNotifier::SETTINGS_KEY, SettingType::Enum, TerminalNotifier::OFF)
                ->withCategory(SettingCategory::Interface)
                ->withRiskClass(RiskClass::Cosmetic)
                ->withLayered()
                ->withProjectSettable()
                ->withEnumValues(TerminalNotifier::MODES)
                ->withLabel(Lang::t('settings.notify.label'))
                ->withHelp(Lang::t('settings.notify.help'))
                ->withReaderSymbol(TerminalNotifier::class . '::fromConfig')
                ->withReadBy('`Chat` (turn end, permission prompt) → `TerminalNotifier::fromConfig()`'),
            // Roadmap 5.14i. USER-only: it makes comments in the repository's
            // files prompts the agent acts on, so a checkout may never turn it on.
            SettingDefinition::new(AiCommentWatcher::SETTINGS_KEY, SettingType::Bool, false)
                ->withCategory(SettingCategory::Interface)
                ->withRiskClass(RiskClass::Prompt)
                ->withLayered()
                ->withLabel(Lang::t('settings.watchFiles.label'))
                ->withHelp(Lang::t('settings.watchFiles.help'))
                ->withReaderSymbol(AiCommentWatcher::class . '::enabled')
                ->withReadBy('`Chat::subscriptions()` → `AiCommentWatcher::enabled()`'),
            SettingDefinition::new('queueMode', SettingType::Enum, QueueMode::Steer->value)
                ->withCategory(SettingCategory::Interface)
                ->withRiskClass(RiskClass::Tuning)
                ->withLayered()
                ->withProjectSettable()
                ->withApplyMode(ApplyMode::Live)
                ->withEnumValues(array_map(static fn (QueueMode $m): string => $m->value, QueueMode::cases()))
                ->withLabel(Lang::t('settings.queueMode.label'))
                ->withHelp(Lang::t('settings.queueMode.help'))
                ->withReaderSymbol(QueueMode::class . '::onEnter')
                ->withReadBy('`Chat::submit()`, `SubmitOptions::effectiveDelivery()` → `QueueMode::onEnter()`'),
            // Cosmetic and project-settable like `theme`: it only says what
            // the `adaptive` theme should believe about the background.
            SettingDefinition::new(TerminalBackground::SETTINGS_KEY, SettingType::Enum, 'auto')
                ->withCategory(SettingCategory::Interface)
                ->withRiskClass(RiskClass::Cosmetic)
                ->withLayered()
                ->withProjectSettable()
                ->withApplyMode(ApplyMode::Live)
                ->withEnvVar(TerminalBackground::ENV_OVERRIDE)
                ->withEnumValues(TerminalBackground::SETTING_VALUES)
                ->withLabel(Lang::t('settings.terminalBackground.label'))
                ->withHelp(Lang::t('settings.terminalBackground.help'))
                ->withReaderSymbol(TerminalBackground::class . '::setting')
                ->withReadBy('`Theme::adaptive()`, the shell chrome → `TerminalBackground::isDark()`, `color()` → `setting()`'),
            SettingDefinition::new('mouse', SettingType::Bool, true)
                ->withCategory(SettingCategory::Interface)
                ->withRiskClass(RiskClass::Cosmetic)
                ->withLayered()
                ->withProjectSettable()
                ->withApplyMode(ApplyMode::Live)
                ->withEnvVar('CANDY_CODE_DISABLE_MOUSE')
                ->withLabel(Lang::t('settings.mouse.label'))
                ->withHelp(Lang::t('settings.mouse.help'))
                ->withReaderSymbol(Chat::class . '::mouseMode')
                ->withReadBy('`Chat::programOptions()` at launch, `Chat::applySettings()` on a save → `Chat::mouseMode()`'),
            SettingDefinition::new('mouseClicks', SettingType::Bool, true)
                ->withCategory(SettingCategory::Interface)
                ->withRiskClass(RiskClass::Cosmetic)
                ->withLayered()
                ->withProjectSettable()
                ->withApplyMode(ApplyMode::Live)
                ->withEnvVar('CANDY_CODE_DISABLE_MOUSE_CLICKS')
                ->withLabel(Lang::t('settings.mouseClicks.label'))
                ->withHelp(Lang::t('settings.mouseClicks.help'))
                ->withReaderSymbol(Chat::class . '::mouseClicksEnabled'),
            SettingDefinition::new('scrollWheelLines', SettingType::Int, Chat::SCROLL_WHEEL_LINES)
                ->withCategory(SettingCategory::Interface)
                ->withRiskClass(RiskClass::Cosmetic)
                ->withLayered()
                ->withProjectSettable()
                ->withApplyMode(ApplyMode::Live)
                ->withRange(1, 20)
                ->withLabel(Lang::t('settings.scrollWheelLines.label'))
                ->withHelp(Lang::t('settings.scrollWheelLines.help'))
                ->withReaderSymbol(Chat::class . '::scrollWheelLines'),
            SettingDefinition::new('doubleEscSeconds', SettingType::Float, Chat::DOUBLE_ESCAPE_WINDOW_SECONDS)
                ->withCategory(SettingCategory::Interface)
                ->withRiskClass(RiskClass::Cosmetic)
                ->withLayered()
                ->withProjectSettable()
                ->withApplyMode(ApplyMode::Live)
                ->withRange(0.2, 3.0)
                ->withLabel(Lang::t('settings.doubleEscSeconds.label'))
                ->withHelp(Lang::t('settings.doubleEscSeconds.help'))
                ->withReaderSymbol(Chat::class . '::doubleEscapeWindowSeconds'),
            // C-SES-7.d: the resume cost guard's line. 0 disables the notice
            // entirely; the default is a quarter of a dollar, chosen because
            // that is roughly what re-sending a long uncached afternoon costs
            // on a mid-tier priced model — a number worth a second Enter.
            SettingDefinition::new('resumeCostWarnUsd', SettingType::Float, ResumeCostEstimate::DEFAULT_WARN_USD)
                ->withCategory(SettingCategory::Interface)
                ->withRiskClass(RiskClass::Tuning)
                ->withLayered()
                ->withProjectSettable()
                ->withApplyMode(ApplyMode::Live)
                ->withRange(0.0, 100.0)
                ->withLabel(Lang::t('settings.resumeCostWarnUsd.label'))
                ->withHelp(Lang::t('settings.resumeCostWarnUsd.help'))
                ->withReaderSymbol(Chat::class . '::resumeCostHold'),
            SettingDefinition::new('paletteMru', SettingType::Int, Chat::PALETTE_MRU_LIMIT)
                ->withCategory(SettingCategory::Interface)
                ->withRiskClass(RiskClass::Cosmetic)
                ->withLayered()
                ->withProjectSettable()
                ->withApplyMode(ApplyMode::Live)
                ->withRange(0, 50)
                ->withLabel(Lang::t('settings.paletteMru.label'))
                ->withHelp(Lang::t('settings.paletteMru.help'))
                ->withReaderSymbol(Chat::class . '::paletteMruLimit'),
            SettingDefinition::new('diffPreviewRows', SettingType::Int, Renderer::DIFF_MAX_ROWS)
                ->withCategory(SettingCategory::Interface)
                ->withRiskClass(RiskClass::Cosmetic)
                ->withLayered()
                ->withProjectSettable()
                ->withApplyMode(ApplyMode::Live)
                ->withRange(4, 1000)
                ->withLabel(Lang::t('settings.diffPreviewRows.label'))
                ->withHelp(Lang::t('settings.diffPreviewRows.help'))
                ->withReaderSymbol(Renderer::class . '::diffPreviewRows'),
            SettingDefinition::new('diffStyle', SettingType::Enum, 'auto')
                ->withCategory(SettingCategory::Interface)
                ->withRiskClass(RiskClass::Cosmetic)
                ->withLayered()
                ->withProjectSettable()
                ->withApplyMode(ApplyMode::Live)
                ->withEnumValues(['auto', 'unified', 'split'])
                ->withLabel(Lang::t('settings.diffStyle.label'))
                ->withHelp(Lang::t('settings.diffStyle.help'))
                ->withReaderSymbol(Renderer::class . '::diffStyle'),
            SettingDefinition::new('toolOutputPreviewLines', SettingType::Int, Renderer::TOOL_OUTPUT_MAX_LINES)
                ->withCategory(SettingCategory::Interface)
                ->withRiskClass(RiskClass::Cosmetic)
                ->withLayered()
                ->withProjectSettable()
                ->withApplyMode(ApplyMode::Live)
                ->withRange(1, 200)
                ->withLabel(Lang::t('settings.toolOutputPreviewLines.label'))
                ->withHelp(Lang::t('settings.toolOutputPreviewLines.help'))
                ->withReaderSymbol(Renderer::class . '::toolOutputPreviewLines'),
            // Retention DELETES conversations, so it is the operator's own
            // switch: a checkout must not be able to prune their history.
            SettingDefinition::new(Bootstrap::SESSION_RETENTION_DAYS_SETTING, SettingType::Int, 0)
                ->withCategory(SettingCategory::Interface)
                ->withRiskClass(RiskClass::Tuning)
                ->withLayered()
                ->withApplyMode(ApplyMode::Restart)
                ->withEnvVar('CANDY_CODE_SESSION_RETENTION_DAYS')
                ->withRange(0, SessionStore::MAX_RETENTION_DAYS)
                ->withLabel(Lang::t('settings.sessionRetentionDays.label'))
                ->withHelp(Lang::t('settings.sessionRetentionDays.help'))
                ->withReaderSymbol(Bootstrap::class . '::sessionRetentionDays')
                ->withReadBy('`Bootstrap::sessionStore()` at launch → `sessionRetentionDays()`'),
            // The title model's two side-calls (roadmap N-P4g). Switching
            // either off only saves calls, so a project may (Narrowing); how
            // much history a suggestion is shown is input tokens on every
            // turn, so that one is the operator's alone.
            SettingDefinition::new(TitleService::AUTO_TITLE_SETTING, SettingType::Bool, true)
                ->withCategory(SettingCategory::Interface)
                ->withRiskClass(RiskClass::Narrowing)
                ->withLayered()
                ->withProjectSettable()
                ->withApplyMode(ApplyMode::Live)
                ->withLabel(Lang::t('settings.sessions.autoTitle.label'))
                ->withHelp(Lang::t('settings.sessions.autoTitle.help'))
                ->withReaderSymbol(TitleService::class . '::autoTitleEnabled')
                ->withReadBy('`Chat` turn dispatch → `TitleService::titleCall()` → `autoTitleEnabled()`'),
            SettingDefinition::new(TitleService::PROMPT_SUGGESTIONS_SETTING, SettingType::Bool, true)
                ->withCategory(SettingCategory::Interface)
                ->withRiskClass(RiskClass::Narrowing)
                ->withLayered()
                ->withProjectSettable()
                ->withApplyMode(ApplyMode::Live)
                ->withEnvVar('CANDY_CODE_DISABLE_PROMPT_SUGGESTIONS')
                ->withLabel(Lang::t('settings.promptSuggestions.label'))
                ->withHelp(Lang::t('settings.promptSuggestions.help'))
                ->withReaderSymbol(TitleService::class . '::promptSuggestionsEnabled')
                ->withReadBy('`Chat` turn settle → `TitleService::suggestionCall()` → `promptSuggestionsEnabled()`'),
            SettingDefinition::new(TitleService::PROMPT_SUGGESTION_HISTORY_SETTING, SettingType::Int, TitleService::PROMPT_SUGGESTION_HISTORY)
                ->withCategory(SettingCategory::Interface)
                ->withRiskClass(RiskClass::Spend)
                ->withLayered()
                ->withApplyMode(ApplyMode::Live)
                ->withRange(1, 100)
                ->withLabel(Lang::t('settings.promptSuggestionHistory.label'))
                ->withHelp(Lang::t('settings.promptSuggestionHistory.help'))
                ->withReaderSymbol(TitleService::class . '::promptSuggestionHistory')
                ->withReadBy('`TitleService::suggestionCall()` → `promptSuggestionHistory()`'),
            // C-TUI-3.g (GAP-UI-N4): the away recap's third title-model
            // side-call. Switching it off only saves calls, so a project
            // may (Narrowing) — and unlike the two above it defaults ON for
            // the Claude Code parity the design names, because the return
            // row is a feature, not a cost the user expects to opt into.
            SettingDefinition::new(AwayTracker::SETTING_KEY, SettingType::Bool, true)
                ->withCategory(SettingCategory::Interface)
                ->withRiskClass(RiskClass::Narrowing)
                ->withLayered()
                ->withProjectSettable()
                ->withApplyMode(ApplyMode::Live)
                ->withLabel(Lang::t('settings.sessions.awayRecap.label'))
                ->withHelp(Lang::t('settings.sessions.awayRecap.help'))
                ->withReaderSymbol(AwayTracker::class . '::recapEnabled')
                ->withReadBy('`Chat` focus regain → `HandlesRunControl::awayRecapOnFocusBack()` gate → `AwayTracker::recapEnabled()`'),
            SettingDefinition::new('expandToolOutput', SettingType::Bool, false)
                ->withCategory(SettingCategory::Interface)
                ->withRiskClass(RiskClass::Cosmetic)
                ->withLayered()
                ->withProjectSettable()
                ->withApplyMode(ApplyMode::Live)
                ->withLabel(Lang::t('settings.expandToolOutput.label'))
                ->withHelp(Lang::t('settings.expandToolOutput.help'))
                ->withReaderSymbol(Renderer::class . '::toolOutputExpandedByDefault')
                ->withReadBy('`Renderer::renderToolResults()`, `Chat::isToolOutputExpanded()` → `Renderer::toolOutputExpandedByDefault()`'),
            SettingDefinition::new('maxCheckpoints', SettingType::Int, EnhancedSessionStore::MAX_CHECKPOINTS_PER_SESSION)
                ->withCategory(SettingCategory::Interface)
                ->withRiskClass(RiskClass::Tuning)
                ->withLayered()
                ->withApplyMode(ApplyMode::Live)
                ->withRange(1, 10000)
                ->withLabel(Lang::t('settings.maxCheckpoints.label'))
                ->withHelp(Lang::t('settings.maxCheckpoints.help'))
                ->withReaderSymbol(EnhancedSessionStore::class . '::maxCheckpoints')
                ->withReadBy('`EnhancedSessionStore::saveCheckpoint()` → `maxCheckpoints()`'),
            // ISS-047: the key map as a setting. USER tier only (the default —
            // no withProjectSettable()): a checkout must not be able to change
            // the operator's keys, the same reasoning that keeps
            // `watchFiles` and the spend cap user-only. RELOAD apply mode
            // (plan_comparison TUI-4a): `Bootstrap::chat()` registers the
            // overrides at launch and `/keys reload` re-reads and re-applies
            // the saved file on the word — no watcher, no silent surprise.
            SettingDefinition::new('keybindings', SettingType::Map, [])
                ->withCategory(SettingCategory::Interface)
                ->withRiskClass(RiskClass::Tuning)
                ->withLayered()
                ->withApplyMode(ApplyMode::Reload)
                ->withUi(UiEditability::Complex)
                ->withValidators(KeyBindingsValidator::new())
                ->withLabel(Lang::t('settings.keybindings.label'))
                ->withHelp(Lang::t('settings.keybindings.help'))
                ->withReaderSymbol(KeyBindingRegistry::class . '::chordFor')
                ->withReadBy('`Bootstrap::chat()` → `KeyBindingRegistry::useOverrides()` → `aliasPress()` (via `effective()`) at `App::handleKey()`; `command:` ids split to `commandFor()` at the top of `App::handleKey()` (C-EXT-5) — re-applied mid-session by `/keys reload` → `Bootstrap::reloadKeybindings()` (TUI-4a)'),
            // TUI-3A: the composer's vim MODE (plan_comparison.md §3). USER
            // tier on purpose — like `keybindings` above it, a checked-out
            // project must not be able to flip the operator's own keystrokes;
            // unlike `keybindings` it is Live, because the arm re-syncs the
            // widget on the next keystroke rather than needing a reload. The
            // keys are a MODE, not bindings: nothing here lands in /keys and
            // the KeyBindingRegistry roster is untouched.
            SettingDefinition::new('vimMode', SettingType::Bool, false)
                ->withCategory(SettingCategory::Interface)
                ->withRiskClass(RiskClass::Cosmetic)
                ->withLayered()
                ->withApplyMode(ApplyMode::Live)
                ->withLabel(Lang::t('settings.vimMode.label'))
                ->withHelp(Lang::t('settings.vimMode.help'))
                ->withReaderSymbol(Chat::class . '::vimModeEnabled')
                ->withReadBy('`Chat::update()` key dispatch (arm re-sync + operator resolution, TUI-3A) → `ComposerVim` over the sugar-forms `TextArea` Vim layer (w5f)'),
            // C-TUI-6.i (plan_comparison.md §3): the screen-reader switch. USER
            // tier on purpose — like `vimMode` above it, an accessibility
            // setting belongs to the operator, not to a checked-out project;
            // and like `vimMode` it is Live, because `Chat::applySettings()`
            // drops the held values of every `UiSettings::KEYS` key on a
            // save and `screenReader` joins that list in this commit, so
            // the next read — the next spinner frame, the next settings
            // reopen — takes the new answer with no reload.
            SettingDefinition::new('screenReader', SettingType::Bool, false)
                ->withCategory(SettingCategory::Interface)
                ->withRiskClass(RiskClass::Cosmetic)
                ->withLayered()
                ->withApplyMode(ApplyMode::Live)
                ->withLabel(Lang::t('settings.screenReader.label'))
                ->withHelp(Lang::t('settings.screenReader.help'))
                ->withReaderSymbol(ScreenReaderMode::class . '::enabled')
                ->withReadBy('`AgentLiveRegistry::staticSpinner()` → `frameAt()` pins the agent spinner to its first frame (C-TUI-6.i); `ScreenReaderMode::border()` / `glyph()` / `menuRow()` / `cue()` reach the renderer and menus in C-TUI-6.j'),
            // C-SES-8.g (plan_comparison.md §3): the JSONL mirror switch. USER
            // tier on purpose — no withProjectSettable(): `transcript_path`
            // rides into hook STDIN and hooks are project-definable, so a
            // checkout that could flip this would be pointing operator-written
            // scripts at a journal it can then read; the same reasoning keeps
            // `watchFiles` and the spend cap user-only. RESTART apply mode:
            // the store and its mirror are built once at launch
            // ({@see Bootstrap::sessionStore()}), and the process flag behind
            // the hook key is set there, not re-read per event. The journal
            // itself is {@see JsonlTranscriptWriter} (C-SES-8.f); this lane
            // only decides WHEN it runs.
            SettingDefinition::new('transcriptJsonl', SettingType::Bool, false)
                ->withCategory(SettingCategory::Interface)
                ->withRiskClass(RiskClass::Tuning)
                ->withLayered()
                ->withApplyMode(ApplyMode::Restart)
                ->withLabel(Lang::t('settings.transcriptJsonl.label'))
                ->withHelp(Lang::t('settings.transcriptJsonl.help'))
                ->withReaderSymbol(Bootstrap::class . '::sessionStore')
                ->withReadBy('`Bootstrap::sessionStore()` → `EnhancedSessionStore::withJsonlMirror()` (the store mirror) and the `JsonlTranscriptWriter::enabled()` flag read by `ScriptHook::stdinPayload()` (`transcript_path`)'),
        ];
    }
}
