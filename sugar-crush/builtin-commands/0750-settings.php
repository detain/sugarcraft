<?php

declare(strict_types=1);

use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Commands\Specs\BuiltInCommand;
use SugarCraft\Crush\Lang;
use SugarCraft\Crush\Palette\PaletteAction;

// Roadmap N-P1. Opens the full-band settings view, which is SHELL state
// (App::$settingsEditor), so the handler only clears the box and sends
// OpenSettingsMsg over Chat's Cmd channel; the argument, if any, pre-fills the
// view's search (`/settings compaction`). Read-only in this phase — the row says
// what it shows rather than promising an editor. `/config` is the alias other
// CLIs teach. Both names are CONTROL_PLANE: a checkout's `settings.md` must not
// answer a key the user aimed at the app.
return BuiltInCommand::new(CommandSpec::new(
    'settings',
    Lang::t('cmd.settings.description'),
    Lang::t('cmd.category.app'),
    paletteAction: PaletteAction::OpenSettings,
    paletteLabel: Lang::t('cmd.settings.label'),
    argumentHint: Lang::t('cmd.settings.hint'),
))->withHandler('handleSettingsCommand')->withAliases('config');
