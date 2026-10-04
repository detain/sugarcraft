<?php

declare(strict_types=1);

use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Commands\Specs\BuiltInCommand;
use SugarCraft\Crush\Palette\PaletteAction;

// X-35a: a local export, `~/.sugar-crush/exports/` by default or a path inside
// the project. See ShareCommand.
return BuiltInCommand::new(CommandSpec::new(
    'share',
    'Export the session to a file',
    'Session',
    paletteAction: PaletteAction::ShareSession,
    paletteLabel: 'Share session',
    argumentHint: '[md|html|json] [path]',
))->withHandler('handleShareCommand')
    ->withHostCommand(\SugarCraft\Crush\Host\Commands\ShareHostCommand::class);
