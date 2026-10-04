<?php

declare(strict_types=1);

use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Commands\Specs\BuiltInCommand;
use SugarCraft\Crush\Palette\PaletteAction;

// P-A4: palette-only, like `new` — the picker's `p` without opening the picker.
// No slash spelling: `/session-pin` would be a second door to one bit of state.
return BuiltInCommand::new(CommandSpec::new(
    'session-pin',
    'Pin the current session to the front of the list, or unpin it',
    'Session',
    paletteAction: PaletteAction::PinSession,
    paletteLabel: 'Pin or unpin session',
    slashVisible: false,
));
