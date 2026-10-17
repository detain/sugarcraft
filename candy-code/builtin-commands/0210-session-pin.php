<?php

declare(strict_types=1);

use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Commands\Specs\BuiltInCommand;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Palette\PaletteAction;

// P-A4: palette-only, like `new` — the picker's `p` without opening the picker.
// No slash spelling: `/session-pin` would be a second door to one bit of state.
return BuiltInCommand::new(CommandSpec::new(
    'session-pin',
    Lang::t('cmd.session-pin.description'),
    Lang::t('cmd.category.session'),
    paletteAction: PaletteAction::PinSession,
    paletteLabel: Lang::t('cmd.session-pin.label'),
    slashVisible: false,
));
