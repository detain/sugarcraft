<?php

declare(strict_types=1);

use SugarCraft\Code\Commands\Specs\CommandArguments;
use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Commands\Specs\BuiltInCommand;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Palette\PaletteAction;

// P-A4, corrected by CC-UXD-03: the picker's `p` without opening the picker,
// on both doors — the palette arm and the typed `/session-pin` run the same
// `Chat::togglePinCurrentSession()` (the no-session line answers when there is
// nothing to pin). A second door to one bit of state is exactly the point: the
// keyboard user gets the verb without the mouse trip through the picker.
return BuiltInCommand::new(CommandSpec::new(
    'session-pin',
    Lang::t('cmd.session-pin.description'),
    Lang::t('cmd.category.session'),
    paletteAction: PaletteAction::PinSession,
    paletteLabel: Lang::t('cmd.session-pin.label'),
))->withHandler('togglePinCurrentSession', CommandArguments::None);
