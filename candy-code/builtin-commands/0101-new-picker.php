<?php

declare(strict_types=1);

use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Commands\Specs\BuiltInCommand;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Palette\PaletteAction;

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
