<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Config\Settings\Definitions;

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
use SugarCraft\Crush\Theme;
use SugarCraft\Crush\Tui\TerminalNotifier;

/**
 * The "Interface" category's keys. One file per category so a step adding a
 * key edits only its category's file (DH-KEYS); {@see \SugarCraft\Crush\Config\Settings\SettingsSchema}
 * collects every set.
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
                ->withHelp('{"type": "command", "command": "…"}: a shell command whose output paints the status bar.')
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
        ];
    }
}
