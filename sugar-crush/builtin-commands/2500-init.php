<?php

declare(strict_types=1);

use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Commands\Specs\BuiltInCommand;

// Roadmap 5.14e. A canned prompt rather than a handler: the agent studies the
// checkout and writes the file, gated like any other Write. Filed under Memory
// because AGENTS.md is the instruction-file half of what docs/MEMORY.md
// documents. The one command that STARTS A TURN — see Chat::handleInitCommand().
return BuiltInCommand::new(CommandSpec::new(
    'init',
    'Study this project and write or improve its AGENTS.md',
    'Memory',
    argumentHint: '[focus]',
))->withHandler('handleInitCommand');
