<?php

declare(strict_types=1);

use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Commands\Specs\BuiltInCommand;
use SugarCraft\Crush\Lang;
use SugarCraft\Crush\Palette\PaletteAction;

// Palette-only twins of the two dock sides, mirroring the `docs` row's pattern:
// the slash surface stays one command that says what it takes, the palette gets
// the two concrete actions. Their palette arms drive the complete `/pane dock …`
// text through the `pane` handler.
return BuiltInCommand::new(CommandSpec::new(
    'pane-dock-left',
    Lang::t('cmd.pane-dock-left.description'),
    Lang::t('cmd.category.layout'),
    paletteAction: PaletteAction::DockPaneLeft,
    paletteLabel: Lang::t('cmd.pane-dock-left.label'),
    slashVisible: false,
));
