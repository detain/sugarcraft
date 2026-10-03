<?php

declare(strict_types=1);

use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Commands\Specs\BuiltInCommand;

// Category 'App', not 'Session': the cap and the tracker behind it are
// per-LAUNCH, carried by object identity through Chat::mutate() and untouched by
// /new, /clear or a session switch. Filing it under Session would advertise a
// scope it does not have.
return BuiltInCommand::new(CommandSpec::new(
    'budget',
    'Show this session\'s reported spend, or cap it',
    'App',
    argumentHint: '[amount|off]',
))->withHandler('handleBudgetCommand');
