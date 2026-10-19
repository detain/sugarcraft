<?php

declare(strict_types=1);

use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Commands\Specs\BuiltInCommand;
use SugarCraft\Code\Lang;

// The phase-3 gesture pair: keyboard twins of the mouse dock operations. The
// state they move lives on the shell, so both dispatch an App message over
// Chat's Cmd channel (see Chat::handlePaneCommand()); `pane` carries no
// paletteAction because its dock side-variants share the one verb and the two
// pseudo-rows (pane-dock-left/right) own the palette surface — `toggle` has no
// palette twin, it is a keyboard-only undock.
// VIS-4 ruling: stays slash-only — bare '/pane' prints usage; the palette-expressible actions are the dock-left/dock-right rows.
return BuiltInCommand::new(CommandSpec::new(
    'pane',
    Lang::t('cmd.pane.description'),
    Lang::t('cmd.category.layout'),
    argumentHint: Lang::t('cmd.pane.hint'),
))->withHandler('handlePaneCommand');
