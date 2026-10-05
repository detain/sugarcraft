<?php

declare(strict_types=1);

use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Commands\Specs\BuiltInCommand;
use SugarCraft\Crush\Lang;

// The phase-3 gesture pair: keyboard twins of the mouse dock operations. The
// state they move lives on the shell, so both dispatch an App message over
// Chat's Cmd channel (see Chat::handlePaneCommand()); `pane` carries no
// paletteAction because its dock side-variants share the one verb and the two
// pseudo-rows (pane-dock-left/right) own the palette surface — `toggle` has no
// palette twin, it is a keyboard-only undock.
return BuiltInCommand::new(CommandSpec::new(
    'pane',
    Lang::t('cmd.pane.description'),
    Lang::t('cmd.category.layout'),
    argumentHint: Lang::t('cmd.pane.hint'),
))->withHandler('handlePaneCommand');
