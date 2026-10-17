<?php

declare(strict_types=1);

use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Commands\Specs\BuiltInCommand;
use SugarCraft\Code\Lang;

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
