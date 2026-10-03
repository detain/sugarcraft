<?php

declare(strict_types=1);

use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Commands\Specs\BuiltInCommand;
use SugarCraft\Crush\Palette\PaletteAction;

// See 1700-pane-dock-left.php.
return BuiltInCommand::new(CommandSpec::new(
    'pane-dock-right',
    'Dock the focused pane to the right',
    'Layout',
    paletteAction: PaletteAction::DockPaneRight,
    paletteLabel: 'Dock pane right',
    slashVisible: false,
));
