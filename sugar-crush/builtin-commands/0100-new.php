<?php

declare(strict_types=1);

use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Commands\Specs\BuiltInCommand;
use SugarCraft\Crush\Palette\PaletteAction;

// Palette-only: no slash spelling dispatches it (typed, `/new` is a prompt).
return BuiltInCommand::new(CommandSpec::new(
    'new',
    'Start a fresh session',
    'Session',
    paletteAction: PaletteAction::NewSession,
    paletteLabel: 'New session',
    slashVisible: false,
));
