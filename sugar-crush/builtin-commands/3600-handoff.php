<?php

declare(strict_types=1);

use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Commands\Specs\BuiltInCommand;

// Roadmap 5.14c. A fresh session that starts from a state summary of this one
// (the W5 state block), forked so it is linked as a branch, and moved onto when
// the summary lands — see Chat::handleHandoffCommand(). The optional focus
// steers what the summary model dwells on.
return BuiltInCommand::new(CommandSpec::new(
    'handoff',
    'Continue in a new session that starts from a state summary of this one',
    'Session',
    argumentHint: '[focus]',
))->withHandler('handleHandoffCommand')
    ->withHostCommand(\SugarCraft\Crush\Host\Commands\HandoffHostCommand::class);
