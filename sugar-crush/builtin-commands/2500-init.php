<?php

declare(strict_types=1);

use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Commands\Specs\BuiltInCommand;
use SugarCraft\Crush\Lang;

// Roadmap 5.14e. A canned prompt rather than a handler: the agent studies the
// checkout and writes the file, gated like any other Write. Filed under Memory
// because AGENTS.md is the instruction-file half of what docs/MEMORY.md
// documents. A command that STARTS A TURN (as `/compress`, `/goal` and `/grind`
// do) — see Chat::handleInitCommand().
return BuiltInCommand::new(CommandSpec::new(
    'init',
    Lang::t('cmd.init.description'),
    Lang::t('cmd.category.memory'),
    argumentHint: Lang::t('cmd.init.hint'),
))->withHandler('handleInitCommand');
