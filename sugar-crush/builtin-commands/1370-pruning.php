<?php

declare(strict_types=1);

use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Commands\Specs\BuiltInCommand;

// Roadmap 3.B-2 (DCP manual mode). The choice is the session's, kept on its
// context ledger; bare `/pruning` reports the mode in force and its source.
return BuiltInCommand::new(CommandSpec::new(
    'pruning',
    'Show or set how this session prunes its context: auto, manual or off',
    'App',
    argumentHint: '[auto|manual|off|default]',
))->withHandler('handlePruningCommand');
