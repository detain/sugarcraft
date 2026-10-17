<?php

declare(strict_types=1);

use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Commands\Specs\BuiltInCommand;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Palette\PaletteAction;

// P-A4: palette-only. Deleting is the picker's two-press `d`, which shows what
// goes with the session (its sub-agent children) before anything is removed, so
// this row opens the picker and says how rather than deleting blind.
return BuiltInCommand::new(CommandSpec::new(
    'session-delete',
    Lang::t('cmd.session-delete.description'),
    Lang::t('cmd.category.session'),
    paletteAction: PaletteAction::DeleteSession,
    paletteLabel: Lang::t('cmd.session-delete.label'),
    slashVisible: false,
));
