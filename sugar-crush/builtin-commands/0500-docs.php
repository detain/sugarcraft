<?php

declare(strict_types=1);

use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Commands\Specs\BuiltInCommand;
use SugarCraft\Crush\Palette\PaletteAction;

// Palette-only, like `new`.
return BuiltInCommand::new(CommandSpec::new(
    'docs',
    'Open the documentation',
    'App',
    paletteAction: PaletteAction::OpenDocs,
    paletteLabel: 'Open docs',
    slashVisible: false,
));
