<?php

declare(strict_types=1);

use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Commands\Specs\BuiltInCommand;
use SugarCraft\Crush\Lang;

// Roadmap 3.B-2 (DCP manual mode). The choice is the session's, kept on its
// context ledger; bare `/pruning` reports the mode in force and its source.
return BuiltInCommand::new(CommandSpec::new(
    'pruning',
    Lang::t('cmd.pruning.description'),
    Lang::t('cmd.category.app'),
    argumentHint: Lang::t('cmd.pruning.hint'),
))->withHandler('handlePruningCommand')
    ->withHostCommand(\SugarCraft\Crush\Host\Commands\PruningHostCommand::class);
