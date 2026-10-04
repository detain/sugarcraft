<?php

declare(strict_types=1);

use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Commands\Specs\BuiltInCommand;
use SugarCraft\Crush\Palette\PaletteAction;

return BuiltInCommand::new(CommandSpec::new(
    'branch',
    'Fork the current session into a new branch',
    'Session',
    paletteAction: PaletteAction::BranchSession,
    paletteLabel: 'Branch session',
))->withHandler('handleBranchCommand')
    ->withHostCommand(\SugarCraft\Crush\Host\Commands\BranchCommand::class);
