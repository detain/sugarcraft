<?php

declare(strict_types=1);

use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Commands\Specs\BuiltInCommand;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Palette\PaletteAction;

// See 1700-pane-dock-left.php.
return BuiltInCommand::new(CommandSpec::new(
    'pane-dock-right',
    Lang::t('cmd.pane-dock-right.description'),
    Lang::t('cmd.category.layout'),
    paletteAction: PaletteAction::DockPaneRight,
    paletteLabel: Lang::t('cmd.pane-dock-right.label'),
    slashVisible: false,
));
