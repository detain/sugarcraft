<?php

declare(strict_types=1);

use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Commands\Specs\BuiltInCommand;

// Roadmap 5.14b. The one command that also runs while a turn is in flight
// (Host\TurnController::midTurnRoute()): it only appends UI-only rows, so the
// agent never reads the question or the answer.
return BuiltInCommand::new(CommandSpec::new(
    'btw',
    'Ask the title model a side question about this conversation, kept out of it',
    'Session',
    argumentHint: '<question>',
))->withHandler('handleBtwCommand')
    ->withHostCommand(\SugarCraft\Crush\Host\Commands\BtwHostCommand::class);
