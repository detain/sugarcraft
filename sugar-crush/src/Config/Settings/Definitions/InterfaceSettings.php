<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Config\Settings\Definitions;

use SugarCraft\Crush\Backend\QueueMode;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Config\Settings\ApplyMode;
use SugarCraft\Crush\Config\Settings\OptionsSource;
use SugarCraft\Crush\Config\Settings\RiskClass;
use SugarCraft\Crush\Config\Settings\SettingCategory;
use SugarCraft\Crush\Config\Settings\SettingDefinition;
use SugarCraft\Crush\Config\Settings\SettingDefinitionSet;
use SugarCraft\Crush\Config\Settings\SettingType;
use SugarCraft\Crush\Config\Settings\UiEditability;
use SugarCraft\Crush\Config\StatusLineCommand;
use SugarCraft\Crush\Renderer;
use SugarCraft\Crush\Session\EnhancedSessionStore;
use SugarCraft\Crush\Support\AiCommentWatcher;
use SugarCraft\Crush\Theme;
use SugarCraft\Crush\Tui\TerminalNotifier;

/**
 * The "Interface" category's keys. One file per category so a step adding a
 * key edits only its category's file (DH-KEYS); {@see \SugarCraft\Crush\Config\Settings\SettingsSchema}
 * collects every set.
 *
 * Roadmap N-P4g promoted the TUI's own constants to keys here — what Enter
 * does mid-turn (`queueMode`, decision D6), the mouse, the wheel step, the
 * double-Esc window, the palette's recent list, the collapsed preview sizes
 * and the checkpoint cap. Each default IS the constant it replaced, cited
 * rather than restated. They are read through
 * {@see \SugarCraft\Crush\Config\Settings\UiSettings}, which holds them for
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
                ->withEnumValues(Theme::names())
                ->withOptionsSource(OptionsSource::Themes)
                ->withLabel('Theme')
                ->withHelp('Colour theme; /theme and the palette change it live.')
                ->withReaderSymbol(Bootstrap::class . '::chat'),
            SettingDefinition::new('statusLine', SettingType::Map)
                ->withCategory(SettingCategory::Interface)
                ->withRiskClass(RiskClass::Exec)
                ->withLayered()
                // N-P3: a save re-runs `StatusLineCommand::reconfigure()`.
                ->withApplyMode(ApplyMode::Live)
                ->withUi(UiEditability::Complex)
                ->withLabel('Status line command')
                ->withHelp('{"type": "command", "command": "…", "refreshSeconds": 2}: a shell command whose output paints the status bar, re-run every refreshSeconds (2 to 3600, default 2).')
                ->withReaderSymbol(StatusLineCommand::class . '::fromSettings')
                ->withReadBy('`Bootstrap::chat()`, `Chat::applySettings()` → `StatusLineCommand::fromSettings()`'),
            SettingDefinition::new('layout', SettingType::Json)
                ->withCategory(SettingCategory::Interface)
                ->withRiskClass(RiskClass::Cosmetic)
                ->withLayered()
                ->withApplyMode(ApplyMode::Live)
                ->withUi(UiEditability::ReadOnly)
                ->withLabel('Pane layout')
                ->withHelp('The docked-pane manifest; written by the shell when panes move.')
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
                ->withLabel('Notifications')
                ->withHelp('off, bell (BEL) or osc9 (a desktop notification): sent when a turn ends and when the agent waits for an approval.')
                ->withReaderSymbol(TerminalNotifier::class . '::fromConfig')
                ->withReadBy('`Chat` (turn end, permission prompt) → `TerminalNotifier::fromConfig()`'),
            // Roadmap 5.14i. USER-only: it makes comments in the repository's
            // files prompts the agent acts on, so a checkout may never turn it on.
            SettingDefinition::new(AiCommentWatcher::SETTINGS_KEY, SettingType::Bool, false)
                ->withCategory(SettingCategory::Interface)
                ->withRiskClass(RiskClass::Prompt)
                ->withLayered()
                ->withLabel('Watch files for AI comments')
                ->withHelp('Send a prompt when a saved file holds a comment ending in AI! (make a change) or AI? (answer a question); plain AI comments ride along as context. Polled while the chat is idle; only comments saved after launch fire, each once.')
                ->withReaderSymbol(AiCommentWatcher::class . '::enabled')
                ->withReadBy('`Chat::subscriptions()` → `AiCommentWatcher::enabled()`'),
            SettingDefinition::new('queueMode', SettingType::Enum, QueueMode::Steer->value)
                ->withCategory(SettingCategory::Interface)
                ->withRiskClass(RiskClass::Tuning)
                ->withLayered()
                ->withProjectSettable()
                ->withApplyMode(ApplyMode::Live)
                ->withEnumValues(array_map(static fn (QueueMode $m): string => $m->value, QueueMode::cases()))
                ->withLabel('Enter while a turn runs')
                ->withHelp('steer (the agent reads it at its next step), followup (sent after the turn) or interrupt (stop the turn at its next step, then send). Tab always queues.')
                ->withReaderSymbol(QueueMode::class . '::onEnter')
                ->withReadBy('`Chat::submit()`, `SubmitOptions::effectiveDelivery()` → `QueueMode::onEnter()`'),
            SettingDefinition::new('mouse', SettingType::Bool, true)
                ->withCategory(SettingCategory::Interface)
                ->withRiskClass(RiskClass::Cosmetic)
                ->withLayered()
                ->withProjectSettable()
                ->withApplyMode(ApplyMode::Live)
                ->withEnvVar('SUGARCRUSH_DISABLE_MOUSE')
                ->withLabel('Mouse')
                ->withHelp('Report the mouse to sugar-crush (clicks, wheel, selection); off gives the terminal its own copy-on-select back.')
                ->withReaderSymbol(Chat::class . '::mouseMode')
                ->withReadBy('`Chat::programOptions()` at launch, `Chat::applySettings()` on a save → `Chat::mouseMode()`'),
            SettingDefinition::new('mouseClicks', SettingType::Bool, true)
                ->withCategory(SettingCategory::Interface)
                ->withRiskClass(RiskClass::Cosmetic)
                ->withLayered()
                ->withProjectSettable()
                ->withApplyMode(ApplyMode::Live)
                ->withEnvVar('SUGARCRUSH_DISABLE_MOUSE_CLICKS')
                ->withLabel('Mouse clicks')
                ->withHelp('Clicks and drags act on what they hit; off keeps the wheel and ignores clicks.')
                ->withReaderSymbol(Chat::class . '::mouseClicksEnabled'),
            SettingDefinition::new('scrollWheelLines', SettingType::Int, Chat::SCROLL_WHEEL_LINES)
                ->withCategory(SettingCategory::Interface)
                ->withRiskClass(RiskClass::Cosmetic)
                ->withLayered()
                ->withProjectSettable()
                ->withApplyMode(ApplyMode::Live)
                ->withRange(1, 20)
                ->withLabel('Wheel step (lines)')
                ->withHelp('Transcript lines one wheel tick scrolls.')
                ->withReaderSymbol(Chat::class . '::scrollWheelLines'),
            SettingDefinition::new('doubleEscSeconds', SettingType::Float, Chat::DOUBLE_ESCAPE_WINDOW_SECONDS)
                ->withCategory(SettingCategory::Interface)
                ->withRiskClass(RiskClass::Cosmetic)
                ->withLayered()
                ->withProjectSettable()
                ->withApplyMode(ApplyMode::Live)
                ->withRange(0.2, 3.0)
                ->withLabel('Esc Esc window (s)')
                ->withHelp('How quickly the second Esc must follow the first to cancel a running turn.')
                ->withReaderSymbol(Chat::class . '::doubleEscapeWindowSeconds'),
            SettingDefinition::new('paletteMru', SettingType::Int, Chat::PALETTE_MRU_LIMIT)
                ->withCategory(SettingCategory::Interface)
                ->withRiskClass(RiskClass::Cosmetic)
                ->withLayered()
                ->withProjectSettable()
                ->withApplyMode(ApplyMode::Live)
                ->withRange(0, 50)
                ->withLabel('Palette recent items')
                ->withHelp('How many recently used palette rows are remembered and ranked first; 0 remembers none.')
                ->withReaderSymbol(Chat::class . '::paletteMruLimit'),
            SettingDefinition::new('diffPreviewRows', SettingType::Int, Renderer::DIFF_MAX_ROWS)
                ->withCategory(SettingCategory::Interface)
                ->withRiskClass(RiskClass::Cosmetic)
                ->withLayered()
                ->withProjectSettable()
                ->withApplyMode(ApplyMode::Live)
                ->withRange(4, 1000)
                ->withLabel('Diff preview rows')
                ->withHelp('Rows of an Edit or Write diff shown in the transcript before the rest is summarised.')
                ->withReaderSymbol(Renderer::class . '::diffPreviewRows'),
            SettingDefinition::new('toolOutputPreviewLines', SettingType::Int, Renderer::TOOL_OUTPUT_MAX_LINES)
                ->withCategory(SettingCategory::Interface)
                ->withRiskClass(RiskClass::Cosmetic)
                ->withLayered()
                ->withProjectSettable()
                ->withApplyMode(ApplyMode::Live)
                ->withRange(1, 200)
                ->withLabel('Tool output preview lines')
                ->withHelp('Lines of a collapsed tool result shown in the transcript; Ctrl+O expands the rest.')
                ->withReaderSymbol(Renderer::class . '::toolOutputPreviewLines'),
            SettingDefinition::new('maxCheckpoints', SettingType::Int, EnhancedSessionStore::MAX_CHECKPOINTS_PER_SESSION)
                ->withCategory(SettingCategory::Interface)
                ->withRiskClass(RiskClass::Tuning)
                ->withLayered()
                ->withApplyMode(ApplyMode::Live)
                ->withRange(1, 10000)
                ->withLabel('Checkpoints kept per session')
                ->withHelp('Turn checkpoints /rewind can return to, per session; the oldest are pruned past this.')
                ->withReaderSymbol(EnhancedSessionStore::class . '::maxCheckpoints')
                ->withReadBy('`EnhancedSessionStore::saveCheckpoint()` → `maxCheckpoints()`'),
        ];
    }
}
