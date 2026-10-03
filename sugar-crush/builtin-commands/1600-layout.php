<?php

declare(strict_types=1);

use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Commands\Specs\BuiltInCommand;
use SugarCraft\Crush\Palette\PaletteAction;

return BuiltInCommand::new(CommandSpec::new(
    'layout',
    'Reset the pane layout to the launch default',
    'Layout',
    paletteAction: PaletteAction::LayoutReset,
    paletteLabel: 'Reset layout',
    argumentHint: 'reset',
))->withHandler('handleLayoutCommand');
