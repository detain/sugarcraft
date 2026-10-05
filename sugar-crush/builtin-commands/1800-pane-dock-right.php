<?php

declare(strict_types=1);

use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Commands\Specs\BuiltInCommand;
use SugarCraft\Crush\Lang;
use SugarCraft\Crush\Palette\PaletteAction;

// See 1700-pane-dock-left.php.
return BuiltInCommand::new(CommandSpec::new(
    'pane-dock-right',
    Lang::t('cmd.pane-dock-right.description'),
    Lang::t('cmd.category.layout'),
    paletteAction: PaletteAction::DockPaneRight,
    paletteLabel: Lang::t('cmd.pane-dock-right.label'),
    slashVisible: false,
));
