<?php

declare(strict_types=1);

use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Commands\Specs\BuiltInCommand;
use SugarCraft\Crush\Lang;
use SugarCraft\Crush\Palette\PaletteAction;

// Palette-only (and so the Session menu's): `/new`'s folder picker, for the
// surfaces that cannot type a slash command. Typed, it is `/new`.
return BuiltInCommand::new(CommandSpec::new(
    'new-picker',
    Lang::t('cmd.newpicker.description'),
    Lang::t('cmd.category.session'),
    paletteAction: PaletteAction::NewSessionPicker,
    paletteLabel: Lang::t('cmd.newpicker.label'),
    slashVisible: false,
));
