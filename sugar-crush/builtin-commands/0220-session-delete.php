<?php

declare(strict_types=1);

use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Commands\Specs\BuiltInCommand;
use SugarCraft\Crush\Palette\PaletteAction;

// P-A4: palette-only. Deleting is the picker's two-press `d`, which shows what
// goes with the session (its sub-agent children) before anything is removed, so
// this row opens the picker and says how rather than deleting blind.
return BuiltInCommand::new(CommandSpec::new(
    'session-delete',
    'Open the session list to delete a session',
    'Session',
    paletteAction: PaletteAction::DeleteSession,
    paletteLabel: 'Delete session…',
    slashVisible: false,
));
